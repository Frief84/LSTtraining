<?php
if (!defined('ABSPATH')) { exit; }

/** REST CRUD for global Einsatzvorlagen (not spawned instance incidents). */
add_action('rest_api_init', static function (): void {
    register_rest_route('lst/v1', '/verwaltung/einsaetze', [
        ['methods' => WP_REST_Server::READABLE, 'callback' => 'lst_rest_einsaetze_list', 'permission_callback' => 'lst_rest_einsaetze_permission'],
        ['methods' => WP_REST_Server::CREATABLE, 'callback' => 'lst_rest_einsaetze_write', 'permission_callback' => 'lst_rest_einsaetze_permission'],
    ]);
    register_rest_route('lst/v1', '/verwaltung/einsaetze/(?P<id>\d+)', [
        ['methods' => WP_REST_Server::READABLE, 'callback' => 'lst_rest_einsaetze_get', 'permission_callback' => 'lst_rest_einsaetze_permission'],
        ['methods' => WP_REST_Server::EDITABLE, 'callback' => 'lst_rest_einsaetze_write', 'permission_callback' => 'lst_rest_einsaetze_permission'],
        ['methods' => WP_REST_Server::DELETABLE, 'callback' => 'lst_rest_einsaetze_delete', 'permission_callback' => 'lst_rest_einsaetze_permission'],
    ]);
});

function lst_rest_einsaetze_permission() {
    if (!is_user_logged_in()) {
        return new WP_Error('lst_manage_not_logged_in', 'Anmeldung erforderlich.', ['status' => 401]);
    }
    return current_user_can('manage_options') || lsttraining_user_can('leitstellen')
        ? true : new WP_Error('lst_manage_forbidden', 'Keine Berechtigung fuer Einsatzvorlagen.', ['status' => 403]);
}

function lst_rest_einsaetze_fields(): array {
    return [
        'title' => ['type' => 'string', 'required' => true, 'max' => 255],
        'description' => ['type' => 'text', 'nullable' => true, 'max' => 10000],
        'einsatzart' => ['type' => 'enum', 'required' => true, 'allowed' => ['RD', 'FW']],
        'einsatztyp' => ['type' => 'string', 'required' => true, 'max' => 100],
        'enabled' => ['type' => 'bool', 'default' => true],
        'scope_type' => ['type' => 'enum', 'default' => 'anywhere', 'allowed' => ['anywhere', 'landscape', 'poi_type', 'fixed_point']],
        'landscape_tags_json' => ['type' => 'json_list', 'nullable' => true],
        'poi_type' => ['type' => 'string', 'nullable' => true, 'max' => 50],
        'fixed_latitude' => ['type' => 'latitude', 'nullable' => true],
        'fixed_longitude' => ['type' => 'longitude', 'nullable' => true],
        'fixed_radius_m' => ['type' => 'int', 'nullable' => true, 'min' => 0],
        'lagemeldung' => ['type' => 'text', 'required' => true, 'max' => 10000],
        'patientenzahl' => ['type' => 'int', 'nullable' => true, 'min' => 0, 'default' => 0],
        'patient_anforderung' => ['type' => 'string', 'nullable' => true, 'max' => 255],
        'notarzt_benoetigt' => ['type' => 'bool', 'default' => false],
        'feuerwehr_benoetigt' => ['type' => 'bool', 'default' => false],
        'poi_tag' => ['type' => 'string', 'nullable' => true, 'max' => 50],
        'tags_json' => ['type' => 'json_list', 'nullable' => true],
        'base_required_resources_json' => ['type' => 'json_list', 'nullable' => true],
        'patient_profile_json' => ['type' => 'json_list', 'nullable' => true],
    ];
}

function lst_rest_einsaetze_safe_json(string $name, $value, int $depth = 0): void {
    if ($depth > 12) { throw new InvalidArgumentException($name . ' ist zu tief verschachtelt.'); }
    if (is_string($value)) { lsttraining_rest_assert_safe_string($name, $value, 10000, true); return; }
    if (is_int($value) || is_bool($value) || $value === null) { return; }
    if (is_float($value) && is_finite($value)) { return; }
    if (!is_array($value)) { throw new InvalidArgumentException($name . ' enthaelt einen ungueltigen Wert.'); }
    foreach ($value as $key => $item) {
        if (is_string($key)) { lsttraining_rest_assert_safe_string($name, $key, 100); }
        lst_rest_einsaetze_safe_json($name, $item, $depth + 1);
    }
}

function lst_rest_einsaetze_normalize_json(string $name, $value): string {
    $decoded = lsttraining_rest_json_input($name, $value);
    if (!lsttraining_rest_is_list($decoded)) { throw new InvalidArgumentException($name . ' muss eine JSON-Liste sein.'); }
    lst_rest_einsaetze_safe_json($name, $decoded);
    $encoded = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || strlen($encoded) > 65535) { throw new InvalidArgumentException($name . ' ist zu gross.'); }
    return $encoded;
}

function lst_rest_einsaetze_normalize_any_json(string $name, $value): string {
    $decoded = lsttraining_rest_json_input($name, $value);
    lst_rest_einsaetze_safe_json($name, $decoded);
    $encoded = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || strlen($encoded) > 65535) { throw new InvalidArgumentException($name . ' ist zu gross.'); }
    return $encoded;
}

function lst_rest_einsaetze_normalize(WP_REST_Request $request, bool $creating): array {
    $fields = lst_rest_einsaetze_fields();
    $children = ['time_windows', 'seasons', 'weather_conditions', 'caller_parts', 'caller_profiles', 'followups'];
    $payload = lsttraining_rest_json_object($request, array_merge(array_keys($fields), $children), 16777216);
    $data = [];
    foreach ($fields as $name => $definition) {
        if (array_key_exists($name, $payload)) {
            $data[$name] = $definition['type'] === 'json_list' && $payload[$name] !== null
                ? lst_rest_einsaetze_normalize_json($name, $payload[$name])
                : lst_manage_normalize_field($name, $payload[$name], $definition);
        } elseif ($creating && array_key_exists('default', $definition)) {
            $data[$name] = lst_manage_normalize_field($name, $definition['default'], $definition);
        } elseif ($creating && !empty($definition['required'])) {
            throw new InvalidArgumentException($name . ' ist erforderlich.');
        }
        if (!empty($definition['required']) && array_key_exists($name, $data) && $data[$name] === '') {
            throw new InvalidArgumentException($name . ' darf nicht leer sein.');
        }
    }
    $normalized = [];
    if (array_key_exists('time_windows', $payload)) {
        $normalized['time_windows'] = lsttraining_einsatzeditor_normalize_time_windows($payload['time_windows']);
    }
    if (array_key_exists('seasons', $payload)) {
        $normalized['seasons'] = lst_rest_einsaetze_enum_list('seasons', $payload['seasons'], ['spring', 'summer', 'autumn', 'winter']);
    }
    if (array_key_exists('weather_conditions', $payload)) {
        $normalized['weather_conditions'] = lst_rest_einsaetze_enum_list('weather_conditions', $payload['weather_conditions'], ['clear', 'cloudy', 'rain', 'snow', 'storm', 'windy', 'fog', 'cold', 'hot']);
    }
    if (array_key_exists('caller_parts', $payload)) {
        $parts = $payload['caller_parts'];
        if (!is_array($parts) || lsttraining_rest_is_list($parts)) { throw new InvalidArgumentException('caller_parts muss ein Objekt sein.'); }
        if (array_diff(array_keys($parts), ['problem', 'observation', 'extra'])) { throw new InvalidArgumentException('caller_parts enthaelt unbekannte Gruppen.'); }
        $normalized['caller_parts'] = [];
        foreach ($parts as $key => $rows) {
            $normalized['caller_parts'][$key] = lst_rest_einsaetze_rows($key, $rows, [
                'text' => ['type' => 'text', 'required' => true, 'max' => 10000],
                'sort_order' => ['type' => 'int', 'default' => 0],
                'enabled' => ['type' => 'bool', 'default' => true],
            ]);
        }
    }
    if (array_key_exists('caller_profiles', $payload)) {
        $normalized['caller_profiles'] = lst_rest_einsaetze_rows('caller_profiles', $payload['caller_profiles'], [
            'profile_id' => ['type' => 'id', 'required' => true],
            'weight' => ['type' => 'int', 'default' => 100, 'min' => 1, 'max' => 1000],
        ]);
        $ids = array_column($normalized['caller_profiles'], 'profile_id');
        if (count($ids) !== count(array_unique($ids))) { throw new InvalidArgumentException('caller_profiles enthaelt doppelte Profil-IDs.'); }
    }
    if (array_key_exists('followups', $payload)) {
        $normalized['followups'] = lst_rest_einsaetze_rows('followups', $payload['followups'], [
            'step_no' => ['type' => 'int', 'required' => true, 'min' => 1],
            'label' => ['type' => 'string', 'nullable' => true, 'max' => 255],
            'kind' => ['type' => 'enum', 'default' => 'update', 'allowed' => ['dispatcher_question', 'caller_answer', 'update', 'unit_report']],
            'text' => ['type' => 'text', 'required' => true, 'max' => 10000],
            'min_after_sec' => ['type' => 'int', 'nullable' => true, 'min' => 0],
            'max_after_sec' => ['type' => 'int', 'nullable' => true, 'min' => 0],
            'probability_percent' => ['type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100],
            'speaker_type' => ['type' => 'enum', 'default' => 'system', 'allowed' => ['caller', 'fire_unit', 'ems_unit', 'police', 'dispatch', 'system']],
            'trigger_mode' => ['type' => 'enum', 'default' => 'random', 'allowed' => ['random', 'on_unit_arrival', 'on_missing_resources', 'on_dispatcher_question', 'manual']],
        ], ['condition_json', 'required_resources_json', 'effect_json']);
        $steps = array_column($normalized['followups'], 'step_no');
        if (count($steps) !== count(array_unique($steps))) { throw new InvalidArgumentException('followups enthaelt doppelte step_no.'); }
        foreach ($normalized['followups'] as $row) {
            if (isset($row['min_after_sec'], $row['max_after_sec']) && $row['min_after_sec'] > $row['max_after_sec']) {
                throw new InvalidArgumentException('followups: min_after_sec darf max_after_sec nicht uebersteigen.');
            }
        }
    }
    return [$data, $normalized];
}

function lst_rest_einsaetze_enum_list(string $name, $value, array $allowed): array {
    if (!is_array($value) || !lsttraining_rest_is_list($value) || count($value) > 100) { throw new InvalidArgumentException($name . ' muss eine JSON-Liste sein.'); }
    foreach ($value as $item) {
        if (!is_string($item) || !in_array($item, $allowed, true)) { throw new InvalidArgumentException($name . ' enthaelt einen ungueltigen Wert.'); }
    }
    if (count($value) !== count(array_unique($value))) { throw new InvalidArgumentException($name . ' enthaelt doppelte Werte.'); }
    return $value;
}

function lst_rest_einsaetze_rows(string $name, $rows, array $fields, array $json_fields = []): array {
    if (!is_array($rows) || !lsttraining_rest_is_list($rows) || count($rows) > 500) { throw new InvalidArgumentException($name . ' muss eine JSON-Liste mit hoechstens 500 Eintraegen sein.'); }
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row) || lsttraining_rest_is_list($row) || array_diff(array_keys($row), array_merge(array_keys($fields), $json_fields))) {
            throw new InvalidArgumentException($name . ' enthaelt eine ungueltige Zeile.');
        }
        $item = lst_manage_normalize_payload(['fields' => $fields, 'relations' => []], array_diff_key($row, array_flip($json_fields)), true);
        foreach ($json_fields as $field) {
            if (array_key_exists($field, $row)) {
                $item[$field] = $row[$field] === null ? null : lst_rest_einsaetze_normalize_any_json($field, $row[$field]);
            }
        }
        $result[] = $item;
    }
    return $result;
}

function lst_rest_einsaetze_validate(PDO $pdo, array $data, array $children, ?array $current, bool $creating): void {
    $merged = array_replace($current ?: [], $data);
    $scope = $merged['scope_type'] ?? 'anywhere';
    if ($scope === 'poi_type' && empty($merged['poi_type'])) { throw new InvalidArgumentException('Fuer poi_type ist poi_type erforderlich.'); }
    if ($scope === 'fixed_point' && (!isset($merged['fixed_latitude']) || !isset($merged['fixed_longitude']))) { throw new InvalidArgumentException('Fuer fixed_point sind Koordinaten erforderlich.'); }
    if ($scope === 'landscape') {
        $tags = $merged['landscape_tags_json'] ?? null;
        if (is_string($tags)) { $tags = json_decode($tags, true); }
        if (empty($tags)) { throw new InvalidArgumentException('Fuer landscape ist mindestens ein Tag erforderlich.'); }
    }
    if (array_key_exists('caller_parts', $children) || $creating) {
        $parts = $children['caller_parts']['problem'] ?? [];
        if (!$parts && !$creating && $current) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM einsatz_caller_parts WHERE einsatz_id = ? AND part_key = 'problem'");
            $stmt->execute([(int) $current['id']]);
            if (!array_key_exists('problem', $children['caller_parts'])) { $parts = (int) $stmt->fetchColumn() > 0 ? [['text' => 'existing']] : []; }
        }
        if (!$parts) { throw new InvalidArgumentException('Mindestens ein problem-Baustein ist erforderlich.'); }
    }
    if (isset($children['caller_profiles'])) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM anrufer_profile WHERE id = ?');
        foreach ($children['caller_profiles'] as $profile) {
            $check->execute([$profile['profile_id']]);
            if (!(int) $check->fetchColumn()) { throw new InvalidArgumentException('Anruferprofil #' . $profile['profile_id'] . ' existiert nicht.'); }
        }
    }
}

function lst_rest_einsaetze_fetch(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM einsaetze WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) { return null; }
    foreach (['tags_json', 'landscape_tags_json', 'base_required_resources_json', 'patient_profile_json'] as $field) {
        if (isset($item[$field])) { $item[$field] = json_decode($item[$field], true); }
    }
    $relations = [
        'time_windows' => ['einsatz_time_windows', 'day_type, start_time, end_time', 'day_type ASC, start_time ASC, id ASC'],
        'seasons' => ['einsatz_seasons', 'season', 'season ASC'],
        'weather_conditions' => ['einsatz_weather_conditions', 'weather_type', 'weather_type ASC'],
        'caller_parts' => ['einsatz_caller_parts', 'part_key, text, sort_order, enabled', 'part_key ASC, sort_order ASC, id ASC'],
        'caller_profiles' => ['einsatz_anrufer_profiles', 'profile_id, weight', 'profile_id ASC'],
        'followups' => ['einsatz_followups', 'step_no, label, kind, text, min_after_sec, max_after_sec, condition_json, probability_percent, speaker_type, trigger_mode, required_resources_json, effect_json', 'step_no ASC'],
    ];
    foreach ($relations as $name => [$table, $columns, $order]) {
        if ($name === 'caller_profiles' && !lsttraining_einsatzeditor_table_exists($pdo, $table)) { $item[$name] = []; continue; }
        $query = $pdo->prepare("SELECT {$columns} FROM {$table} WHERE einsatz_id = ? ORDER BY {$order}");
        $query->execute([$id]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($name === 'seasons' || $name === 'weather_conditions') { $rows = array_column($rows, $columns); }
        if ($name === 'caller_parts') {
            $grouped = ['problem' => [], 'observation' => [], 'extra' => []];
            foreach ($rows as $row) { $grouped[$row['part_key']][] = ['text' => $row['text'], 'sort_order' => (int) $row['sort_order'], 'enabled' => (bool) $row['enabled']]; }
            $rows = $grouped;
        }
        if ($name === 'followups') {
            foreach ($rows as &$row) {
                foreach (['condition_json', 'required_resources_json', 'effect_json'] as $field) { if (isset($row[$field])) { $row[$field] = json_decode($row[$field], true); } }
            }
            unset($row);
        }
        $item[$name] = $rows;
    }
    return $item;
}

function lst_rest_einsaetze_sync(PDO $pdo, int $id, array $children): void {
    if (array_key_exists('time_windows', $children)) {
        $pdo->prepare('DELETE FROM einsatz_time_windows WHERE einsatz_id = ?')->execute([$id]);
        $insert = $pdo->prepare('INSERT INTO einsatz_time_windows (einsatz_id, day_type, start_time, end_time) VALUES (?, ?, ?, ?)');
        foreach ($children['time_windows'] as $window) {
            $insert->execute([$id, $window['day_type'], $window['start_time'], $window['end_time']]);
        }
    }
    foreach (['seasons' => ['einsatz_seasons', 'season'], 'weather_conditions' => ['einsatz_weather_conditions', 'weather_type']] as $name => [$table, $column]) {
        if (!array_key_exists($name, $children)) { continue; }
        $pdo->prepare("DELETE FROM {$table} WHERE einsatz_id = ?")->execute([$id]);
        $insert = $pdo->prepare("INSERT INTO {$table} (einsatz_id, {$column}) VALUES (?, ?)");
        foreach ($children[$name] as $value) { $insert->execute([$id, $value]); }
    }
    if (array_key_exists('caller_parts', $children)) {
        $keys = array_keys($children['caller_parts']);
        $delete = $pdo->prepare('DELETE FROM einsatz_caller_parts WHERE einsatz_id = ? AND part_key = ?');
        $insert = $pdo->prepare('INSERT INTO einsatz_caller_parts (einsatz_id, part_key, text, sort_order, enabled) VALUES (?, ?, ?, ?, ?)');
        foreach ($keys as $key) {
            $delete->execute([$id, $key]);
            foreach ($children['caller_parts'][$key] as $row) { $insert->execute([$id, $key, $row['text'], $row['sort_order'], (int) $row['enabled']]); }
        }
    }
    if (array_key_exists('caller_profiles', $children)) {
        if (!lsttraining_einsatzeditor_table_exists($pdo, 'einsatz_anrufer_profiles')) { throw new RuntimeException('Zuordnungstabelle fuer Anruferprofile fehlt.'); }
        $pdo->prepare('DELETE FROM einsatz_anrufer_profiles WHERE einsatz_id = ?')->execute([$id]);
        $insert = $pdo->prepare('INSERT INTO einsatz_anrufer_profiles (einsatz_id, profile_id, weight) VALUES (?, ?, ?)');
        foreach ($children['caller_profiles'] as $row) { $insert->execute([$id, $row['profile_id'], $row['weight']]); }
    }
    if (array_key_exists('followups', $children)) {
        $pdo->prepare('DELETE FROM einsatz_followups WHERE einsatz_id = ?')->execute([$id]);
        $columns = ['step_no', 'label', 'kind', 'text', 'min_after_sec', 'max_after_sec', 'condition_json', 'probability_percent', 'speaker_type', 'trigger_mode', 'required_resources_json', 'effect_json'];
        $insert = $pdo->prepare('INSERT INTO einsatz_followups (einsatz_id, ' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns) + 1, '?')) . ')');
        foreach ($children['followups'] as $row) {
            $values = [];
            foreach ($columns as $column) { $values[] = $row[$column] ?? null; }
            $insert->execute(array_merge([$id], $values));
        }
    }
}

function lst_rest_einsaetze_list(WP_REST_Request $request) {
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) { return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500); }
    $page = max(1, absint($request->get_param('page')) ?: 1);
    $per_page = max(1, min(200, absint($request->get_param('per_page')) ?: 50));
    try { $search = lsttraining_rest_assert_safe_string('search', (string) ($request->get_param('search') ?? ''), 255); }
    catch (InvalidArgumentException $e) { return lst_manage_error('validation_failed', $e->getMessage(), 400); }
    try {
        $where = $search === '' ? '' : ' WHERE title LIKE ? OR einsatztyp LIKE ?';
        $params = $search === '' ? [] : ['%' . $search . '%', '%' . $search . '%'];
        $count = $pdo->prepare('SELECT COUNT(*) FROM einsaetze' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, title, description, einsatzart, einsatztyp, enabled, scope_type FROM einsaetze' . $where . ' ORDER BY id DESC LIMIT ' . $per_page . ' OFFSET ' . (($page - 1) * $per_page));
        $stmt->execute($params);
        return lst_manage_success(['resource' => 'einsaetze', 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'pagination' => ['page' => $page, 'per_page' => $per_page, 'total' => $total, 'pages' => (int) ceil($total / $per_page)]]);
    } catch (Throwable $e) { error_log('[LSTtraining][REST einsaetze list] ' . $e->getMessage()); return lst_manage_error('db_query_failed', 'Einsatzvorlagen konnten nicht geladen werden.', 500); }
}

function lst_rest_einsaetze_get(WP_REST_Request $request) {
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) { return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500); }
    try {
        $item = lst_rest_einsaetze_fetch($pdo, absint($request->get_param('id')));
        return $item ? lst_manage_success($item) : lst_manage_error('not_found', 'Einsatzvorlage nicht gefunden.', 404);
    } catch (Throwable $e) { error_log('[LSTtraining][REST einsaetze get] ' . $e->getMessage()); return lst_manage_error('db_query_failed', 'Einsatzvorlage konnte nicht geladen werden.', 500); }
}

function lst_rest_einsaetze_write(WP_REST_Request $request) {
    $id = absint($request->get_param('id'));
    $creating = $id === 0;
    try { [$data, $children] = lst_rest_einsaetze_normalize($request, $creating); }
    catch (InvalidArgumentException $e) { return lst_manage_error('validation_failed', $e->getMessage(), 400); }
    if (!$data && !$children) { return lst_manage_error('no_changes', 'Keine aenderbaren Felder uebermittelt.', 400); }
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) { return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500); }
    try {
        $current = $creating ? null : lst_rest_einsaetze_fetch($pdo, $id);
        if (!$creating && !$current) { return lst_manage_error('not_found', 'Einsatzvorlage nicht gefunden.', 404); }
        lst_rest_einsaetze_validate($pdo, $data, $children, $current, $creating);
        foreach (['base_required_resources_json', 'patient_profile_json'] as $column) {
            if (array_key_exists($column, $data) && !lsttraining_einsatzeditor_column_exists($pdo, 'einsaetze', $column)) {
                throw new InvalidArgumentException($column . ' ist in dieser Datenbank nicht verfuegbar.');
            }
        }
        if ($creating) {
            $data += ['caller_who' => '-', 'caller_where' => '-', 'caller_what' => '-', 'anrufertext' => '', 'caller_template_text' => '', 'weight_base' => 100];
        }
        if (isset($children['caller_parts'])) {
            foreach (['problem' => 'caller_what'] as $key => $field) {
                if (isset($children['caller_parts'][$key][0]['text'])) { $data[$field] = $children['caller_parts'][$key][0]['text']; }
            }
        }
        $pdo->beginTransaction();
        if ($creating) {
            $columns = array_keys($data);
            $stmt = $pdo->prepare('INSERT INTO einsaetze (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
            $stmt->execute(array_values($data));
            $id = (int) $pdo->lastInsertId();
        } elseif ($data) {
            $set = implode(', ', array_map(static fn($field) => $field . ' = ?', array_keys($data)));
            $stmt = $pdo->prepare('UPDATE einsaetze SET ' . $set . ' WHERE id = ?');
            $stmt->execute(array_merge(array_values($data), [$id]));
        }
        lst_rest_einsaetze_sync($pdo, $id, $children);
        $result = lst_rest_einsaetze_fetch($pdo, $id);
        $pdo->commit();
        if (function_exists('lsttraining_log_activity')) { lsttraining_log_activity(['entity_type' => 'einsatz', 'entity_id' => $id, 'action' => $creating ? 'create' : 'update', 'meta' => ['source' => 'rest-management']]); }
        return lst_manage_success($result, $creating ? 201 : 200);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return lst_manage_error('validation_failed', $e->getMessage(), 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[LSTtraining][REST einsaetze write] ' . $e->getMessage());
        return lst_manage_error('db_write_failed', 'Einsatzvorlage konnte nicht gespeichert werden.', 500);
    }
}

function lst_rest_einsaetze_delete(WP_REST_Request $request) {
    if (!in_array($request->get_param('confirm'), [true, 1, '1', 'true'], true)) { return lst_manage_error('confirmation_required', 'confirm=true ist erforderlich.', 400); }
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) { return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500); }
    $id = absint($request->get_param('id'));
    try {
        if (!lst_rest_einsaetze_fetch($pdo, $id)) { return lst_manage_error('not_found', 'Einsatzvorlage nicht gefunden.', 404); }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM einsaetze WHERE id = ?')->execute([$id]);
        $pdo->commit();
        if (function_exists('lsttraining_log_activity')) { lsttraining_log_activity(['entity_type' => 'einsatz', 'entity_id' => $id, 'action' => 'delete', 'meta' => ['source' => 'rest-management']]); }
        return lst_manage_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[LSTtraining][REST einsaetze delete] ' . $e->getMessage());
        return lst_manage_error('db_write_failed', 'Einsatzvorlage konnte nicht geloescht werden.', 500);
    }
}

