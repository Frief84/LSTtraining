<?php
// Wachen (Liste/Details/CRUD)
/* -------------------------------------------------------------------------
 * 4. WACHEN (Liste, Details, CRUD)
 * ---------------------------------------------------------------------- */

/**
 * (Optional) Hilfsfunktion: Spaltenexistenz prüfen
 */
function lst_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Liste der Wachen für Karte/Tabelle
 * @action wp_ajax_lsttraining_get_wachen
 */
add_action('wp_ajax_lsttraining_get_wachen', 'lsttraining_get_wachen');
function lsttraining_get_wachen() {
    while (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_end_clean();
    }

    try {
        $pdo = lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            wp_send_json_error(['msg' => 'DB-Verbindung fehlgeschlagen'], 500);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    // Filter (BL > LS > NLS)
    $ls  = isset($_REQUEST['ls_id']) ? (int)$_REQUEST['ls_id'] : 0;
    $nls = isset($_REQUEST['nls_id']) ? (int)$_REQUEST['nls_id'] : 0;
    $bl  = isset($_REQUEST['bundesland']) ? sanitize_text_field($_REQUEST['bundesland']) : '';
    $land = isset($_REQUEST['land']) ? sanitize_text_field($_REQUEST['land']) : '';

    if ($bl !== '') {
        $ls = 0; $nls = 0;
    } elseif ($ls) {
        $nls = 0; $bl = '';
    } elseif ($nls) {
        $ls = 0; $bl = '';
    }

    if (!$ls && !$nls && $bl === '') {
        wp_send_json_error(['msg' => 'Kein Filter angegeben.'], 400);
    }

    $sql = "
        SELECT
            w.id,
            w.name,
            w.typ,
            w.latitude,
            w.longitude,
            w.land,
            w.bundesland,
            COALESCE(v.cnt, 0) AS fahrzeuge_count
        FROM wachen AS w
    ";

    // Aggregat über fahrzeuge – immer dabei
    $joins = "
        LEFT JOIN (
            SELECT wache_id, COUNT(*) AS cnt
            FROM fahrzeuge
            GROUP BY wache_id
        ) v ON v.wache_id = w.id
    ";

    $where  = [];
    $params = [];

    if ($bl !== '') {
        if ($land !== '') {
            $where[] = 'w.land = :land';
            $params[':land'] = $land;
        }

        if ($bl === '__none__') {
            $where[] = "(w.bundesland IS NULL OR w.bundesland = '')";
        } else {
            $where[] = 'w.bundesland = :bl';
            $params[':bl'] = $bl;
        }
    } elseif ($ls) {
        $joins .= ' INNER JOIN wache_leitstellen AS wl ON w.id = wl.wache_id ';
        $where[] = 'wl.leitstelle_id = :ls';
        $params[':ls'] = $ls;
    } elseif ($nls) {
        $joins .= ' INNER JOIN wache_nebenleitstellen AS wn ON w.id = wn.wache_id ';
        $where[] = 'wn.nebenleitstelle_id = :nls';
        $params[':nls'] = $nls;
    }

    $sql .= $joins;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY w.name ASC LIMIT 2000';

    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        while (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_end_clean();
        }

        wp_send_json_success(['count' => count($rows), 'wachen' => $rows], 200);
    } catch (Throwable $e) {
        error_log('lsttraining_get_wachen SQL ERROR: ' . $e->getMessage());
        wp_send_json_error(['msg' => 'DB-Fehler'], 500);
    }
}

/**
 * Daten einer einzelnen Wache laden
 * @action wp_ajax_lsttraining_get_wache
 */
add_action('wp_ajax_lsttraining_get_wache', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'wachen',
    ]);

$id = (int)($_GET['wache_id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Wache-ID fehlt', 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('Datenbankfehler', 500);
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id, name, typ, latitude, longitude,
                   arrival_pos, departure_pos,
                   land, bundesland
              FROM wachen
             WHERE id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            wp_send_json_error('Nicht gefunden', 404);
        }

        $stmt2 = $pdo->prepare('SELECT leitstelle_id FROM wache_leitstellen WHERE wache_id = ?');
        $stmt2->execute([$id]);
        $row['leitstellen'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);

        $stmt3 = $pdo->prepare('SELECT nebenleitstelle_id FROM wache_nebenleitstellen WHERE wache_id = ?');
        $stmt3->execute([$id]);
        $row['nebenleitstellen'] = $stmt3->fetchAll(PDO::FETCH_COLUMN);

        wp_send_json_success($row);
    } catch (Throwable $e) {
        error_log('lsttraining_get_wache ERROR: ' . $e->getMessage());
        wp_send_json_error('Datenbankfehler', 500);
    }
});

/**
 * Speichert eine bestehende Wache (Basis-Daten + Zuordnungen)
 * @action wp_ajax_lsttraining_save_wache
 */
add_action('wp_ajax_lsttraining_save_wache', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'wachen',
    ]);

$id         = (int)($_POST['id'] ?? 0);
    $name       = sanitize_text_field($_POST['name'] ?? '');
    $typ        = sanitize_text_field($_POST['typ'] ?? '');
    $latitude   = (float)($_POST['latitude'] ?? 0);
    $longitude  = (float)($_POST['longitude'] ?? 0);
    $arrival    = sanitize_text_field($_POST['arrival_pos'] ?? '');
    $departure  = sanitize_text_field($_POST['departure_pos'] ?? '');
    $land       = sanitize_text_field($_POST['land'] ?? 'Deutschland');
    $bundesland = sanitize_text_field($_POST['bundesland'] ?? '');
    $bundesland = ($bundesland === '') ? null : $bundesland;

    $leit_ids  = array_map('intval', (array)($_POST['leitstellen'] ?? []));
    $neben_ids = array_map('intval', (array)($_POST['nebenleitstellen'] ?? []));

    if ($id <= 0) {
        wp_send_json_error('Ungültige Wache-ID', 400);
    }

    $pdo = lsttraining_get_connection();

    try {
        $pdo->beginTransaction();

        $updated_by_user_id = get_current_user_id();
        $now = current_time('mysql');

        $stmt = $pdo->prepare('
            UPDATE wachen
               SET name               = :name,
                   typ                = :typ,
                   latitude           = :lat,
                   longitude          = :lon,
                   arrival_pos        = :arr,
                   departure_pos      = :dep,
                   land               = :land,
                   bundesland         = :bundesland,
                   updated_by_user_id = :uby,
                   updated_at         = :uat
             WHERE id = :id
        ');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':typ', $typ);
        $stmt->bindValue(':lat', $latitude);
        $stmt->bindValue(':lon', $longitude);
        $stmt->bindValue(':arr', $arrival);
        $stmt->bindValue(':dep', $departure);
        $stmt->bindValue(':land', $land);
        if ($bundesland === null) {
            $stmt->bindValue(':bundesland', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':bundesland', $bundesland, PDO::PARAM_STR);
        }
        $stmt->bindValue(':uby', $updated_by_user_id, PDO::PARAM_INT);
        $stmt->bindValue(':uat', $now);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception('Basis-Update fehlgeschlagen: ' . implode(', ', $stmt->errorInfo()));
        }

        // Pivot: wache_leitstellen nur wenn Feld übermittelt
        if (array_key_exists('leitstellen', $_POST)) {
            $pdo->prepare('DELETE FROM wache_leitstellen WHERE wache_id = ?')->execute([$id]);
            if (!empty($leit_ids)) {
                $ins = $pdo->prepare('INSERT INTO wache_leitstellen (wache_id, leitstelle_id) VALUES (?, ?)');
                foreach (array_unique($leit_ids) as $lid) {
                    if ($lid > 0) $ins->execute([$id, (int)$lid]);
                }
            }
        }

        // Pivot: wache_nebenleitstellen nur wenn Feld übermittelt
        if (array_key_exists('nebenleitstellen', $_POST)) {
            $pdo->prepare('DELETE FROM wache_nebenleitstellen WHERE wache_id = ?')->execute([$id]);
            if (!empty($neben_ids)) {
                $ins2 = $pdo->prepare('INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id) VALUES (?, ?)');
                foreach (array_unique($neben_ids) as $nlid) {
                    if ($nlid > 0) $ins2->execute([$id, (int)$nlid]);
                }
            }
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'wache',
            'action'      => 'update',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:save_wache'],
        ]);

        wp_send_json_success();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('lsttraining_save_wache ERROR: ' . $e->getMessage());
        wp_send_json_error('Speichern fehlgeschlagen', 500);
    }
});

/**
 * Löscht eine Wache inklusive aller Pivot-Beziehungen
 * @action wp_ajax_lsttraining_delete_wache
 */
add_action('wp_ajax_lsttraining_delete_wache', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'wachen',
    ]);

$id = (int)($_POST['wache_id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Ungültige Wache-ID', 400);
    }

    $pdo = lsttraining_get_connection();

    try {
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM wache_leitstellen WHERE wache_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM wache_nebenleitstellen WHERE wache_id = ?')->execute([$id]);

        $stmt = $pdo->prepare('DELETE FROM wachen WHERE id = ?');
        if (!$stmt->execute([$id])) {
            throw new Exception('Löschen fehlgeschlagen: ' . implode(', ', $stmt->errorInfo()));
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'wache',
            'action'      => 'delete',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:delete_wache'],
        ]);

        wp_send_json_success();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('lsttraining_delete_wache ERROR: ' . $e->getMessage());
        wp_send_json_error('Löschen fehlgeschlagen', 500);
    }
});

/**
 * Legt eine neue Wache an und setzt erste Zuordnungen
 * @action wp_ajax_lsttraining_create_wache
 */
add_action('wp_ajax_lsttraining_create_wache', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'wachen',
    ]);

$name       = sanitize_text_field($_POST['name'] ?? '');
    $typ        = sanitize_text_field($_POST['typ'] ?? '');
    $latitude   = (float)($_POST['latitude'] ?? 0);
    $longitude  = (float)($_POST['longitude'] ?? 0);
    $arrival    = sanitize_text_field($_POST['arrival_pos'] ?? '');
    $departure  = sanitize_text_field($_POST['departure_pos'] ?? '');
    $land       = sanitize_text_field($_POST['land'] ?? 'Deutschland');
    $bundesland = sanitize_text_field($_POST['bundesland'] ?? '');
    $bundesland = ($bundesland === '') ? null : $bundesland;

    $ls_ids  = array_map('intval', (array)($_POST['leitstellen'] ?? []));
    $nls_ids = array_map('intval', (array)($_POST['nebenleitstellen'] ?? []));

    if ($name === '' || $latitude == 0.0 || $longitude == 0.0) {
        wp_send_json_error('Pflichtfelder fehlen', 400);
    }

    $pdo = lsttraining_get_connection();

    try {
        $pdo->beginTransaction();

        $placed_by_user_id = get_current_user_id();
        $now = current_time('mysql');

        $stmt = $pdo->prepare('
            INSERT INTO wachen
                (name, typ, latitude, longitude, arrival_pos, departure_pos, land, bundesland,
                 placed_by_user_id, updated_by_user_id, updated_at)
            VALUES
                (:name, :typ, :lat, :lon, :arr, :dep, :land, :bundesland, :pby, :uby, :uat)
        ');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':typ', $typ);
        $stmt->bindValue(':lat', $latitude);
        $stmt->bindValue(':lon', $longitude);
        $stmt->bindValue(':arr', $arrival);
        $stmt->bindValue(':dep', $departure);
        $stmt->bindValue(':land', $land);
        if ($bundesland === null) {
            $stmt->bindValue(':bundesland', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':bundesland', $bundesland, PDO::PARAM_STR);
        }
        $stmt->bindValue(':pby', $placed_by_user_id, PDO::PARAM_INT);
        $stmt->bindValue(':uby', $placed_by_user_id, PDO::PARAM_INT);
        $stmt->bindValue(':uat', $now);

        if (!$stmt->execute()) {
            throw new Exception('Anlegen fehlgeschlagen: ' . implode(', ', $stmt->errorInfo()));
        }

        $new_id = (int)$pdo->lastInsertId();

        if (!empty($ls_ids)) {
            $ins = $pdo->prepare('INSERT INTO wache_leitstellen (wache_id, leitstelle_id) VALUES (?, ?)');
            foreach ($ls_ids as $lid) {
                if ((int)$lid > 0) $ins->execute([$new_id, (int)$lid]);
            }
        }

        if (!empty($nls_ids)) {
            $ins2 = $pdo->prepare('INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id) VALUES (?, ?)');
            foreach ($nls_ids as $nlid) {
                if ((int)$nlid > 0) $ins2->execute([$new_id, (int)$nlid]);
            }
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'wache',
            'action'      => 'create',
            'entity_id'   => (int)$new_id,
            'meta'        => ['page' => 'ajax:create_wache'],
        ]);

        wp_send_json_success(['id' => $new_id]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('lsttraining_create_wache ERROR: ' . $e->getMessage());
        wp_send_json_error('Anlegen fehlgeschlagen', 500);
    }
});


// Zuordnung: Wachen im Polygon + Zuordnungs-Endpunkte
/* -------------------------------------------------------------------------
 * 9. ZUORDNUNG: WACHEN im Polygon (Helper + Endpunkte)
 * ---------------------------------------------------------------------- */

function lst_z_check($cap = 'manage_options') {
    if (!current_user_can($cap)) {
        wp_send_json_error(['msg' => 'Keine Berechtigung'], 403);
    }
    $nonce = $_POST['_wpnonce'] ?? ($_POST['wpnonce'] ?? ($_POST['nonce'] ?? ''));
    if (!wp_verify_nonce($nonce, 'lst_zuordnung')) {
        wp_send_json_error(['msg' => 'Ungültiger Nonce'], 403);
    }
}

function lst_find_ids_via_geo_override(string $type, int $id): array {
    $geojson = trim((string)wp_unslash($_POST['geojson'] ?? ''));
    $pdo = lsttraining_get_connection();

    if ($geojson === '') {
        $table = ($type === 'leitstelle') ? 'leitstellen' : 'nebenleitstellen';
        $st = $pdo->prepare("SELECT geojson FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $geojson = (string)$st->fetchColumn();
    }
    if ($geojson === '') return [];

    $obj = json_decode($geojson, true);
    if (!is_array($obj)) return [];

    $mp = lst_geo_to_multipolygon($obj);
    if (!$mp) return [];

    [$minLon, $minLat, $maxLon, $maxLat] = lst_mpoly_bbox($mp);

    $st = $pdo->prepare('
        SELECT id, latitude, longitude
          FROM wachen
         WHERE longitude BETWEEN ? AND ?
           AND latitude  BETWEEN ? AND ?
    ');
    $st->execute([$minLon, $maxLon, $minLat, $maxLat]);
    $cands = $st->fetchAll(PDO::FETCH_ASSOC);

    $ids = [];
    foreach ($cands as $w) {
        $pt = [(float)$w['longitude'], (float)$w['latitude']];
        if (lst_point_in_mpoly($pt, $mp)) {
            $ids[] = (int)$w['id'];
        }
    }
    return $ids;
}

/**
 * Wachen im Polygon finden + Zuordnungsstatus (assigned)
 */
add_action('wp_ajax_lsttraining_find_wachen_in_polygon', function () {
    lst_z_check();

    $type = sanitize_key($_POST['entity_type'] ?? '');
    $id   = (int)($_POST['entity_id'] ?? 0);

    if (!in_array($type, ['leitstelle', 'nebenleitstelle'], true) || $id <= 0) {
        wp_send_json_error(['msg' => 'Ungültige Parameter'], 400);
    }

    $geojson = trim((string)wp_unslash($_POST['geojson'] ?? ''));

    try {
        if ($geojson === '') {
            $pdo = lsttraining_get_connection();
            $table = ($type === 'leitstelle') ? 'leitstellen' : 'nebenleitstellen';
            $st = $pdo->prepare("SELECT geojson FROM {$table} WHERE id = ?");
            $st->execute([$id]);
            $geojson = (string)$st->fetchColumn();
        }
        if ($geojson === '') {
            wp_send_json_success(['wachen' => []]);
        }
        $obj = json_decode($geojson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        wp_send_json_error(['msg' => 'Defektes GeoJSON'], 400);
    }

    $mp = lst_geo_to_multipolygon($obj);
    if (!$mp) {
        wp_send_json_success(['wachen' => []]);
    }

    [$minLon, $minLat, $maxLon, $maxLat] = lst_mpoly_bbox($mp);

    $pdo = lsttraining_get_connection();
    $st = $pdo->prepare('
        SELECT id, name, typ, latitude, longitude
          FROM wachen
         WHERE longitude BETWEEN ? AND ?
           AND latitude  BETWEEN ? AND ?
    ');
    $st->execute([$minLon, $maxLon, $minLat, $maxLat]);
    $cands = $st->fetchAll(PDO::FETCH_ASSOC);

    $relTable = ($type === 'leitstelle') ? 'wache_leitstellen' : 'wache_nebenleitstellen';
    $ownerCol = ($type === 'leitstelle') ? 'leitstelle_id' : 'nebenleitstelle_id';

    $assigned = [];
    if ($cands) {
        $ids = array_map('intval', array_column($cands, 'id'));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $pdo->prepare("SELECT wache_id FROM {$relTable} WHERE {$ownerCol} = ? AND wache_id IN ($in)");
        $st2->execute(array_merge([$id], $ids));
        foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $wid) {
            $assigned[(int)$wid] = true;
        }
    }

    $inside = [];
    foreach ($cands as $w) {
        $pt = [(float)$w['longitude'], (float)$w['latitude']];
        if (lst_point_in_mpoly($pt, $mp)) {
            $w['assigned'] = !empty($assigned[(int)$w['id']]);
            $inside[] = $w;
        }
    }

    wp_send_json_success(['wachen' => $inside]);
});

/**
 * Einsatzgebiet laden (mit optionalem GeoJSON-Override)
 */
add_action('wp_ajax_lsttraining_get_entity_polygon', function () {
    lst_z_check();

    $type = sanitize_key($_POST['entity_type'] ?? '');
    $id   = (int)($_POST['entity_id'] ?? 0);

    if (!in_array($type, ['leitstelle', 'nebenleitstelle'], true) || $id <= 0) {
        wp_send_json_error(['msg' => 'Ungültige Parameter'], 400);
    }

    if (isset($_POST['geojson']) && $_POST['geojson'] !== '') {
        $geojson = wp_unslash($_POST['geojson']);
        wp_send_json_success(['geojson' => $geojson]);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    if ($type === 'leitstelle') {
        if (!lsttraining_user_can('leitstellen', $id)) {
            wp_send_json_error(['msg' => 'Keine Berechtigung'], 403);
        }
        $stmt = $pdo->prepare('SELECT geojson FROM leitstellen WHERE id = ?');
    } else {
        if (!lsttraining_user_can('nebenstellen')) {
            wp_send_json_error(['msg' => 'Keine Berechtigung'], 403);
        }
        $stmt = $pdo->prepare('SELECT geojson FROM nebenleitstellen WHERE id = ?');
    }

    $stmt->execute([$id]);
    $geojson = $stmt->fetchColumn();

    if (!$geojson) {
        wp_send_json_error(['msg' => 'Kein Einsatzgebiet hinterlegt'], 404);
    }

    wp_send_json_success(['geojson' => $geojson]);
});

/**
 * Alle Wachen im Einsatzgebiet zuordnen (exklusiv je Dimension)
 */
add_action('wp_ajax_lsttraining_assign_wachen_in_polygon', function () {
    lst_z_check();

    $type = sanitize_key($_POST['entity_type'] ?? '');
    $id   = (int)($_POST['entity_id'] ?? 0);

    if (!in_array($type, ['leitstelle', 'nebenleitstelle'], true) || $id <= 0) {
        wp_send_json_error(['msg' => 'Ungültige Parameter'], 400);
    }

    $ids = lst_find_ids_via_geo_override($type, $id);
    if (!$ids) {
        wp_send_json_success(['assigned' => 0]);
    }

    $pdo = lsttraining_get_connection();
    $rel = ($type === 'leitstelle') ? 'wache_leitstellen' : 'wache_nebenleitstellen';
    $col = ($type === 'leitstelle') ? 'leitstelle_id' : 'nebenleitstelle_id';

    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM {$rel} WHERE wache_id IN ($in)")->execute($ids);

    $vals = [];
    foreach ($ids as $wid) {
        $vals[] = '(' . (int)$wid . ', ' . (int)$id . ')';
    }
    $sql = "INSERT INTO {$rel} (wache_id, {$col}) VALUES " . implode(',', $vals);
    $pdo->exec($sql);

    lsttraining_log_activity([
        'entity_type' => ($type === 'leitstelle') ? 'leitstelle' : 'nebenstelle',
        'action'      => 'update',
        'entity_id'   => (int)$id,
        'meta'        => ['page' => 'ajax:assign_wachen_in_polygon', 'assigned_count' => count($ids)],
    ]);

    wp_send_json_success(['assigned' => count($ids)]);
});

/**
 * Alle Wachen im Einsatzgebiet von DIESER Entity abmelden
 */
add_action('wp_ajax_lsttraining_unassign_wachen_in_polygon', function () {
    lst_z_check();

    $type = sanitize_key($_POST['entity_type'] ?? '');
    $id   = (int)($_POST['entity_id'] ?? 0);

    if (!in_array($type, ['leitstelle', 'nebenleitstelle'], true) || $id <= 0) {
        wp_send_json_error(['msg' => 'Ungültige Parameter'], 400);
    }

    $ids = lst_find_ids_via_geo_override($type, $id);
    if (!$ids) {
        wp_send_json_success(['removed' => 0]);
    }

    $pdo = lsttraining_get_connection();
    $rel = ($type === 'leitstelle') ? 'wache_leitstellen' : 'wache_nebenleitstellen';
    $col = ($type === 'leitstelle') ? 'leitstelle_id' : 'nebenleitstelle_id';

    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM {$rel} WHERE {$col} = ? AND wache_id IN ($in)");
    $st->execute(array_merge([$id], $ids));

    lsttraining_log_activity([
        'entity_type' => ($type === 'leitstelle') ? 'leitstelle' : 'nebenstelle',
        'action'      => 'update',
        'entity_id'   => (int)$id,
        'meta'        => ['page' => 'ajax:unassign_wachen_in_polygon', 'removed_count' => count($ids)],
    ]);

    wp_send_json_success(['removed' => count($ids)]);
});

/**
 * Wachen im sichtbaren Kartenausschnitt laden (ohne zuzuordnen)
 */
add_action('wp_ajax_lsttraining_get_wachen_bbox', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'wachen',
    ]);

$type = sanitize_key($_POST['entity_type'] ?? '');
    $eid  = (int)($_POST['entity_id'] ?? 0);
    if (!in_array($type, ['leitstelle', 'nebenleitstelle'], true) || $eid <= 0) {
        wp_send_json_error('Ungültige Parameter', 400);
    }

    $minLon = (float)($_POST['min_lon'] ?? 0);
    $minLat = (float)($_POST['min_lat'] ?? 0);
    $maxLon = (float)($_POST['max_lon'] ?? 0);
    $maxLat = (float)($_POST['max_lat'] ?? 0);

    $limit = (int)($_POST['limit'] ?? 800);
    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('DB-Verbindung fehlgeschlagen', 500);
    }

    $pivotTbl = ($type === 'leitstelle') ? 'wache_leitstellen' : 'wache_nebenleitstellen';
    $ownerCol = ($type === 'leitstelle') ? 'leitstelle_id' : 'nebenleitstelle_id';

    $sql = "
        SELECT
            w.id, w.name, w.typ, w.latitude, w.longitude,
            CASE WHEN wn.wache_id IS NULL THEN 0 ELSE 1 END AS assigned
        FROM wachen w
        LEFT JOIN {$pivotTbl} wn
          ON wn.wache_id = w.id AND wn.{$ownerCol} = :eid
        WHERE w.longitude BETWEEN :minLon AND :maxLon
          AND w.latitude  BETWEEN :minLat AND :maxLat
        ORDER BY w.id
        LIMIT {$limit}
    ";

    $st = $pdo->prepare($sql);
    $st->bindValue(':eid', $eid, PDO::PARAM_INT);
    $st->bindValue(':minLon', $minLon);
    $st->bindValue(':maxLon', $maxLon);
    $st->bindValue(':minLat', $minLat);
    $st->bindValue(':maxLat', $maxLat);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    while (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_end_clean();
    }
    wp_send_json_success(['wachen' => $rows]);
});

/**
 * Einzelne Wache zuordnen/entfernen
 */
add_action('wp_ajax_lsttraining_toggle_wache_assignment', function () {
    lst_z_check();

    $type    = sanitize_key($_POST['entity_type'] ?? '');
    $ownerId = (int)($_POST['entity_id'] ?? 0);
    $wacheId = (int)($_POST['wache_id'] ?? 0);
    $assign  = isset($_POST['assign']) ? (int)$_POST['assign'] : 1;

    if (!in_array($type, ['leitstelle', 'nebenleitstelle'], true) || $ownerId <= 0 || $wacheId <= 0) {
        wp_send_json_error('Ungültige Parameter', 400);
    }

    if ($type === 'leitstelle' && !lsttraining_user_can('leitstellen', $ownerId)) {
        wp_send_json_error('Keine Berechtigung', 403);
    }
    if ($type === 'nebenleitstelle' && !lsttraining_user_can('nebenstellen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('DB-Verbindung fehlgeschlagen', 500);
    }

    $pivot_table = ($type === 'leitstelle') ? 'wache_leitstellen' : 'wache_nebenleitstellen';
    $owner_col   = ($type === 'leitstelle') ? 'leitstelle_id' : 'nebenleitstelle_id';

    try {
        if ($assign) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$pivot_table} WHERE wache_id = ? AND {$owner_col} = ? LIMIT 1");
            $stmt->execute([$wacheId, $ownerId]);
            if (!$stmt->fetchColumn()) {
                $ins = $pdo->prepare("INSERT INTO {$pivot_table} (wache_id, {$owner_col}) VALUES (?, ?)");
                $ins->execute([$wacheId, $ownerId]);
            }
        } else {
            $del = $pdo->prepare("DELETE FROM {$pivot_table} WHERE wache_id = ? AND {$owner_col} = ?");
            $del->execute([$wacheId, $ownerId]);
        }

        lsttraining_log_activity([
            'entity_type' => 'wache',
            'action'      => 'update',
            'entity_id'   => (int)$wacheId,
            'meta'        => [
                'page'      => 'ajax:toggle_wache_assignment',
                'relation'  => ($type === 'leitstelle' ? 'wache_leitstelle' : 'wache_nebenleitstelle'),
                'owner_id'  => (int)$ownerId,
                'assigned'  => (bool)$assign,
            ],
        ]);

        wp_send_json_success(['wache_id' => $wacheId, 'assigned' => (bool)$assign]);
    } catch (Throwable $e) {
        error_log('lsttraining_toggle_wache_assignment: ' . $e->getMessage());
        wp_send_json_error('DB-Fehler', 500);
    }
});

/**
 * Wache aktualisieren (reiner Update-Endpunkt, ohne Pivot)
 * @action wp_ajax_lsttraining_update_wache
 */
add_action('wp_ajax_lsttraining_update_wache', function () {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet'], 401);
    }
    if (!function_exists('lsttraining_user_can') || !lsttraining_user_can('wachen')) {
        wp_send_json_error(['message' => 'Keine Berechtigung'], 403);
    }

    if (!function_exists('lsttraining_get_connection')) {
        require_once plugin_dir_path(__FILE__) . 'db.php';
    }
    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id         = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name       = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $typ        = isset($_POST['typ']) ? sanitize_text_field($_POST['typ']) : '';
    $latitude   = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0.0;
    $longitude  = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0.0;
    $arrival    = array_key_exists('arrival_pos', $_POST) ? sanitize_text_field($_POST['arrival_pos']) : null;
    $departure  = array_key_exists('departure_pos', $_POST) ? sanitize_text_field($_POST['departure_pos']) : null;
    $land       = isset($_POST['land']) ? sanitize_text_field($_POST['land']) : '';
    $bundesland = (isset($_POST['bundesland']) && $_POST['bundesland'] !== '')
        ? sanitize_text_field($_POST['bundesland'])
        : null;

    if ($id <= 0) {
        wp_send_json_error(['message' => 'Ungültige ID'], 400);
    }

    $updated_by_user_id = get_current_user_id();
    $now = current_time('mysql'); // WP-Zeitzone

    // Altstand für Diff (optional, aber fürs Logging sinnvoll)
    $old = [];
    try {
        $stOld = $pdo->prepare("
            SELECT name, typ, latitude, longitude, arrival_pos, departure_pos, land, bundesland
              FROM wachen
             WHERE id = ?
             LIMIT 1
        ");
        $stOld->execute([$id]);
        $old = $stOld->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $old = [];
    }

    try {
        $stmt = $pdo->prepare('UPDATE wachen
            SET name = :name,
                typ = :typ,
                latitude = :lat,
                longitude = :lon,
                arrival_pos = :arr,
                departure_pos = :dep,
                land = :land,
                bundesland = :bundesland,
                updated_by_user_id = :uby,
                updated_at = :uat
          WHERE id = :id');

        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':typ', $typ);
        $stmt->bindValue(':lat', $latitude);
        $stmt->bindValue(':lon', $longitude);
        $stmt->bindValue(':arr', $arrival);
        $stmt->bindValue(':dep', $departure);
        $stmt->bindValue(':land', $land);

        if (is_null($bundesland)) {
            $stmt->bindValue(':bundesland', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':bundesland', $bundesland, PDO::PARAM_STR);
        }

        $stmt->bindValue(':uby', $updated_by_user_id, PDO::PARAM_INT);
        $stmt->bindValue(':uat', $now);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $ok = $stmt->execute();
        if (!$ok) {
            throw new Exception('Update fehlgeschlagen: ' . implode(', ', $stmt->errorInfo()));
        }

        // Diff berechnen
        $new = [
            'name'          => $name,
            'typ'           => $typ,
            'latitude'      => $latitude,
            'longitude'     => $longitude,
            'arrival_pos'   => $arrival,
            'departure_pos' => $departure,
            'land'          => $land,
            'bundesland'    => $bundesland,
        ];
        $norm = function ($v) {
            if ($v === null) return null;
            if (is_bool($v)) return $v ? '1' : '0';
            if (is_numeric($v)) return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
            return trim((string)$v);
        };
        $changes = [];
        foreach ($new as $k => $nv) {
            $ov = array_key_exists($k, $old) ? $old[$k] : null;
            if ($norm($ov) !== $norm($nv)) {
                $changes[$k] = ['old' => $ov, 'new' => $nv];
            }
        }

        if (!empty($changes) && function_exists('lsttraining_log_activity')) {
            lsttraining_log_activity([
                'entity_type' => 'wache',
                'action'      => 'update',
                'entity_id'   => (int)$id,
                'meta'        => [
                    'changes' => $changes,
                    'page'    => 'ajax:update_wache',
                ],
            ]);
        }

        wp_send_json_success(['id' => $id]);

    } catch (Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
});
