<?php
if (!defined('ABSPATH')) { exit; }

/** Dedicated, globally scoped, serialized writes for individually approved agent deltas. */
add_action('rest_api_init', static function (): void {
    $permission = static function () { return current_user_can('manage_options'); };
    register_rest_route('lst/v1', '/abgleich/status', [
        'methods' => 'GET', 'permission_callback' => $permission,
        'callback' => 'lst_abgleich_status',
    ]);
    register_rest_route('lst/v1', '/abgleich/aktion', [
        'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'lst_abgleich_write',
    ]);
});

function lst_abgleich_status() {
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) { return lst_manage_error('db_connection_failed', 'Keine Datenbankverbindung.', 503); }
    try {
        // A route alone does not prove that the required schema migration ran.
        $pdo->query('SELECT adresse FROM wachen LIMIT 0');
        $pdo->query('SELECT id, wache_id, rufname FROM fahrzeuge LIMIT 0');
    } catch (Throwable $e) {
        error_log('[LSTtraining][abgleich status] ' . $e->getMessage());
        return lst_manage_error('schema_incomplete', 'Abgleich-Datenbankschema nicht bereit.', 503);
    }
    return lst_manage_success(['global_read' => true, 'guarded_writes' => true, 'version' => 1]);
}

function lst_abgleich_key(string $value): string {
    $value = str_replace(['ß', 'ẞ'], 'ss', $value);
    $value = remove_accents($value, 'en_US');
    return preg_replace('/[^a-z0-9]/', '', strtolower($value));
}

function lst_abgleich_assert_before(array $current, array $before, array $fields): void {
    foreach ($fields as $field) {
        if (!array_key_exists($field, $before) || (string) ($current[$field] ?? '') !== (string) $before[$field]) {
            throw new RuntimeException('Bestand seit Freigabe verändert: ' . $field);
        }
    }
}

function lst_abgleich_write(WP_REST_Request $request) {
    $payload = $request->get_json_params();
    $action = is_array($payload) ? ($payload['action'] ?? '') : '';
    if (!in_array($action, ['ADD', 'UPDATE', 'SET_AD', 'ADD_WACHE', 'UPDATE_WACHE'], true)
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($payload['freigabe_id'] ?? ''))
        || empty($payload['beleg']) || !filter_var($payload['quelle'] ?? '', FILTER_VALIDATE_URL)) {
        return lst_manage_error('invalid_approval', 'Aktion, Freigabe-ID und Beleg erforderlich.', 400);
    }
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) { return lst_manage_error('db_connection_failed', 'Keine Datenbankverbindung.', 500); }
    $lock = 'lst-abgleich-' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $held = false;
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) { throw new RuntimeException('Abgleich-Schreibsperre belegt.'); }
        $held = true;
        $pdo->beginTransaction();
        $station_id = (int) ($payload['wache_id'] ?? 0);
        $after = (array) ($payload['after'] ?? []);
        $before = (array) ($payload['before'] ?? []);
        $resource = in_array($action, ['ADD_WACHE', 'UPDATE_WACHE'], true) ? 'wachen' : 'fahrzeuge';
        $config = lst_manage_get_config($resource);
        $creating = in_array($action, ['ADD', 'ADD_WACHE'], true);
        if ($action !== 'ADD_WACHE') {
            $station = lst_manage_fetch_one($pdo, 'wachen', $station_id);
            if (!$station) { throw new RuntimeException('Zielwache fehlt.'); }
            if (!empty($payload['station_before'])) { lst_abgleich_assert_before($station, $payload['station_before'], ['name', 'adresse', 'latitude', 'longitude']); }
        }
        if ($action === 'UPDATE_WACHE') {
            if (!preg_match('~^(way|node|relation)/\d+$~i', (string) $station['name'])) { throw new RuntimeException('Wache hat keinen technischen Namen mehr.'); }
            lst_abgleich_assert_before($station, $before, ['name', 'latitude', 'longitude']);
            if ((string) ($station['adresse'] ?? '') !== (string) ($before['adresse'] ?? '')) { throw new RuntimeException('Adresse verändert.'); }
            $data = lst_manage_normalize_payload($config, ['name' => $after['name'] ?? '', 'adresse' => $after['adresse'] ?? null], false);
            if (!$data['name'] || preg_match('~^(way|node|relation)/\d+$~i', $data['name'])) { throw new InvalidArgumentException('Aufgelöster Name fehlt.'); }
            $id = $station_id;
        } elseif ($action === 'ADD_WACHE') {
            $data = lst_manage_normalize_payload($config, $after, true);
            foreach ($pdo->query('SELECT id, name, land, bundesland, latitude, longitude, adresse FROM wachen')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (lst_abgleich_key((string) $row['land']) !== lst_abgleich_key((string) $data['land'])) { continue; }
                $close = abs((float)$row['latitude'] - (float)$data['latitude']) < 0.002 && abs((float)$row['longitude'] - (float)$data['longitude']) < 0.003;
                $same_name = lst_abgleich_key($row['name']) === lst_abgleich_key($data['name']) && lst_abgleich_key((string)$row['bundesland']) === lst_abgleich_key((string)$data['bundesland']);
                if ($close || $same_name) { throw new RuntimeException('Mögliche bestehende Wache; keine Neuanlage.'); }
            }
            if (empty($after['leitstellen']) && empty($after['nebenleitstellen'])) { throw new InvalidArgumentException('Leitstellenzuordnung fehlt.'); }
            $data['placed_by_user_id'] = (int) get_current_user_id();
        } else {
            $callsign = trim((string) ($after['callsign'] ?? ''));
            if ($callsign === '') { throw new InvalidArgumentException('Rufname fehlt.'); }
            $id = (int) ($payload['vehicle_id'] ?? 0);
            $all = $pdo->query('SELECT id, wache_id, rufname FROM fahrzeuge')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all as $row) {
                if (lst_abgleich_key($row['rufname']) === lst_abgleich_key($callsign) && ($creating || (int)$row['id'] !== $id)) {
                    throw new RuntimeException('Rufname bereits vorhanden; Wache ' . $row['wache_id']);
                }
            }
            if (!$creating) {
                $current = lst_manage_fetch_one($pdo, 'fahrzeuge', $id);
                if (!$current || (int)$current['wache_id'] !== $station_id) { throw new RuntimeException('Fahrzeug fehlt oder Heimatwache geändert.'); }
                lst_abgleich_assert_before($current, $before, ['wache_id', 'rufname', 'fahrzeugtyp', 'status', 'fms_status']);
                if (lst_abgleich_key($current['rufname']) !== lst_abgleich_key($callsign)) { throw new RuntimeException('Rufname verändert.'); }
            }
            if ($action === 'SET_AD') {
                if (!preg_match('/a\s*\.\s*d\s*\.|au(?:ß|ss)er\s+Dienst|ehem\.|ehemalig|historisch|Museum|Oldtimer|inaktiv|verkauft/iu', $payload['beleg'])) { throw new InvalidArgumentException('Außer-Dienst-Beleg fehlt.'); }
                $values = ['status' => 'nicht einsatzbereit', 'fms_status' => '6', 'source_note' => 'Außer Dienst; bestätigt durch freigegebenen Abgleich'];
            } else {
                $types = json_decode(file_get_contents(dirname(__DIR__, 2) . '/data/fahrzeugtypen.json'), true);
                if (!in_array($after['type'] ?? '', $types ?: [], true)) { throw new InvalidArgumentException('Unbekannter Fahrzeugtyp.'); }
                $values = $creating ? ['wache_id' => $station_id, 'rufname' => $callsign, 'fahrzeugtyp' => $after['type']] : ['fahrzeugtyp' => $after['type']];
            }
            $data = lst_manage_normalize_payload($config, $values, $creating);
        }
        $data['source_url'] = substr($payload['quelle'], 0, 500);
        $data['source_checked_at'] = current_time('mysql', true);
        if ($resource === 'wachen') { $data['updated_by_user_id'] = (int)get_current_user_id(); $data['updated_at'] = current_time('mysql'); }
        if ($creating) {
            $columns = array_keys($data);
            $stmt = $pdo->prepare('INSERT INTO ' . $config['table'] . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
            $stmt->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
            if ($action === 'ADD_WACHE') { lst_manage_sync_relations($pdo, $config, $id, $after); }
        } else {
            $stmt = $pdo->prepare('UPDATE ' . $config['table'] . ' SET ' . implode(', ', array_map(static fn($field) => $field . ' = ?', array_keys($data))) . ' WHERE id = ?');
            $stmt->execute(array_merge(array_values($data), [$id]));
        }
        $pdo->commit();
        if (function_exists('lsttraining_log_activity')) { lsttraining_log_activity(['entity_type' => $config['object_type'], 'action' => $creating ? 'create' : 'update', 'entity_id' => $id, 'meta' => ['source' => 'abgleich', 'approval' => $payload['freigabe_id'], 'action' => $action]]); }
        return lst_manage_success(['action' => $action, 'item' => lst_manage_fetch_one($pdo, $resource, $id)], $creating ? 201 : 200);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return lst_manage_error('invalid_evidence', $e->getMessage(), 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return lst_manage_error('abgleich_conflict', $e->getMessage(), 409);
    } finally {
        if ($held) { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]); }
    }
}
