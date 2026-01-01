<?php
/**
 * AJAX-Handler für das LST-Training-Plugin
 * – sämtliche Rechteprüfungen laufen über lsttraining_user_can()
 *   (siehe includes/permissions.php).
 */

if (!defined('ABSPATH')) {
    exit(); // Direktzugriff verhindern
}

require_once plugin_dir_path(__FILE__) . '/db.php';
require_once plugin_dir_path(__FILE__) . '/permissions.php';
require_once plugin_dir_path(__FILE__) . '/geo.php';
require_once plugin_dir_path(__FILE__) . '/activity.php';

/* -------------------------------------------------------------------------
 * 1. LEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Leitstelle laden
 * @action wp_ajax_lsttraining_get_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_get_einsatzgebiet', function () {
    $leitstelle_id = (int)($_GET['leitstelle_id'] ?? 0);
    if ($leitstelle_id <= 0) {
        wp_send_json_error('Leitstellen-ID fehlt', 400);
    }
    if (!lsttraining_user_can('leitstellen', $leitstelle_id)) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('SELECT geojson FROM leitstellen WHERE id = ? LIMIT 1');
    $stmt->execute([$leitstelle_id]);
    $geojson = $stmt->fetchColumn();

    wp_send_json_success($geojson ? json_decode($geojson, true) : null);
});

/**
 * GeoJSON einer Leitstelle speichern
 * @action wp_ajax_lsttraining_save_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_save_einsatzgebiet', function () {
    $leitstelle_id = (int)($_POST['leitstelle_id'] ?? 0);
    if ($leitstelle_id <= 0) {
        wp_send_json_error('Leitstellen-ID fehlt', 400);
    }
    if (!lsttraining_user_can('leitstellen', $leitstelle_id)) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $geojson = wp_unslash($_POST['geojson'] ?? '');
    if ($geojson === '') {
        wp_send_json_error('Invalid data', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('UPDATE leitstellen SET geojson = ? WHERE id = ?');
    $stmt->execute([$geojson, $leitstelle_id]);

    // Log: GeoJSON der Leitstelle aktualisiert
    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'update',
        'entity_id'   => (int)$leitstelle_id,
        'meta'        => ['field' => 'geojson', 'page' => 'ajax:save_einsatzgebiet'],
    ]);

    wp_send_json_success();
});

/* -------------------------------------------------------------------------
 * 2. NEBENLEITSTELLEN (GeoJSON-Editor + CRUD)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Nebenleitstelle laden
 * @action wp_ajax_lsttraining_get_neben_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_get_neben_einsatzgebiet', function () {
    $neben_id = (int)($_GET['neben_id'] ?? 0);
    if ($neben_id <= 0) {
        wp_send_json_error('Nebenleitstellen-ID fehlt', 400);
    }
    if (!lsttraining_user_can('nebenstellen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('SELECT geojson FROM nebenleitstellen WHERE id = ? LIMIT 1');
    $stmt->execute([$neben_id]);
    $geojson = $stmt->fetchColumn();

    wp_send_json_success($geojson ? json_decode($geojson, true) : null);
});

/**
 * GeoJSON einer Nebenleitstelle speichern
 * @action wp_ajax_lsttraining_save_neben_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_save_neben_einsatzgebiet', function () {
    if (!lsttraining_user_can('nebenstellen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $neben_id = (int)($_POST['neben_id'] ?? 0);
    $geojson  = wp_unslash($_POST['geojson'] ?? '');

    if ($neben_id <= 0 || $geojson === '') {
        wp_send_json_error('Invalid data', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('UPDATE nebenleitstellen SET geojson = ? WHERE id = ?');
    $stmt->execute([$geojson, $neben_id]);

    lsttraining_log_activity([
        'entity_type' => 'nebenstelle',
        'action'      => 'update',
        'entity_id'   => (int)$neben_id,
        'meta'        => ['field' => 'geojson', 'page' => 'ajax:save_neben_einsatzgebiet'],
    ]);

    wp_send_json_success();
});

/**
 * Speichern einer Nebenleitstelle (Insert oder Update)
 * @action wp_ajax_lsttraining_save_nebenleitstelle
 */
add_action('wp_ajax_lsttraining_save_nebenleitstelle', function () {
    // 1) Nonce + Rechte prüfen (ohne wp_die)
    if (!check_ajax_referer('lst_nebenstellen_nonce', '_ajax_nonce', false)) {
        wp_send_json_error(['code' => 'bad_nonce', 'msg' => 'Nonce ungültig'], 403);
    }
    if (function_exists('lsttraining_user_can') && !lsttraining_user_can('nebenstellen')) {
        wp_send_json_error(['code' => 'forbidden', 'msg' => 'Keine Berechtigung'], 403);
    }

    // 2) DB verbinden
    try {
        $pdo = lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung: ' . $e->getMessage()], 500);
    }

    // 3) Eingaben
    $id          = (int)($_POST['id'] ?? 0);               // 0/leer => INSERT
    $desired_id  = (int)($_POST['desired_id'] ?? 0);       // optional bei INSERT
    $name        = sanitize_text_field($_POST['name'] ?? '');
    $zust        = sanitize_text_field($_POST['zustandigkeit'] ?? '');
    $einwohner   = (int)($_POST['einwohner'] ?? 0);
    $flaeche_km2 = (float)($_POST['flaeche'] ?? 0);
    $gps         = sanitize_text_field($_POST['gps'] ?? '');

    if ($name === '') {
        wp_send_json_error(['code' => 'validation', 'msg' => 'Name darf nicht leer sein'], 400);
    }

    // Normalisierung für Diffs
    $norm = static function ($v) {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        }
        return trim((string)$v);
    };

    // 4) Eindeutigkeit Name prüfen
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM nebenleitstellen WHERE name = :name AND id <> :id');
            $stmt->execute([':name' => $name, ':id' => $id]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM nebenleitstellen WHERE name = :name');
            $stmt->execute([':name' => $name]);
        }
        if ((int)$stmt->fetchColumn() > 0) {
            wp_send_json_error(['code' => 'name_conflict', 'msg' => 'Name bereits vorhanden'], 409);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Prüfung fehlgeschlagen: ' . $e->getMessage()], 500);
    }

    // 5) INSERT oder UPDATE
    try {
        if ($id > 0) {
            // Altstand für Diff laden
            $old = [];
            try {
                $sel = $pdo->prepare('
                    SELECT name, zustandigkeit, einwohner, flaeche_km2, gps
                    FROM nebenleitstellen WHERE id = :id
                ');
                $sel->execute([':id' => $id]);
                $old = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $old = [];
            }

            $stmt = $pdo->prepare('
                UPDATE nebenleitstellen
                   SET name = :name,
                       zustandigkeit = :zust,
                       einwohner = :einwohner,
                       flaeche_km2 = :flaeche_km2,
                       gps = :gps
                 WHERE id = :id
            ');
            $stmt->execute([
                ':name'        => $name,
                ':zust'        => $zust,
                ':einwohner'   => $einwohner,
                ':flaeche_km2' => $flaeche_km2,
                ':gps'         => $gps,
                ':id'          => $id,
            ]);

            $new = [
                'name'         => $name,
                'zustandigkeit'=> $zust,
                'einwohner'    => $einwohner,
                'flaeche_km2'  => $flaeche_km2,
                'gps'          => $gps,
            ];

            $changes = [];
            foreach ($new as $k => $nv) {
                $ov = array_key_exists($k, $old) ? $old[$k] : null;
                if ($norm($ov) !== $norm($nv)) {
                    $changes[$k] = ['old' => $ov, 'new' => $nv];
                }
            }

            if (!empty($changes) && function_exists('lsttraining_log_activity')) {
                lsttraining_log_activity([
                    'entity_type' => 'nebenstelle',
                    'action'      => 'update',
                    'entity_id'   => (int)$id,
                    'meta'        => ['changes' => $changes],
                ]);
            }

            wp_send_json_success(['id' => $id]);
        }

        // INSERT
        if ($desired_id > 0) {
            $chk = $pdo->prepare('SELECT 1 FROM nebenleitstellen WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $desired_id]);
            if ($chk->fetchColumn()) {
                wp_send_json_error(['code' => 'id_conflict', 'msg' => 'Gewünschte ID bereits vergeben'], 409);
            }

            $stmt = $pdo->prepare('
                INSERT INTO nebenleitstellen (id, name, zustandigkeit, einwohner, flaeche_km2, gps)
                VALUES (:id, :name, :zust, :einwohner, :flaeche_km2, :gps)
            ');
            $stmt->execute([
                ':id'          => $desired_id,
                ':name'        => $name,
                ':zust'        => $zust,
                ':einwohner'   => $einwohner,
                ':flaeche_km2' => $flaeche_km2,
                ':gps'         => $gps,
            ]);

            if (function_exists('lsttraining_log_activity')) {
                lsttraining_log_activity([
                    'entity_type' => 'nebenstelle',
                    'action'      => 'create',
                    'entity_id'   => (int)$desired_id,
                    'meta'        => [
                        'created' => [
                            'name'          => $name,
                            'zustandigkeit' => $zust,
                            'einwohner'     => $einwohner,
                            'flaeche_km2'   => $flaeche_km2,
                            'gps'           => $gps,
                        ],
                    ],
                ]);
            }

            wp_send_json_success(['id' => $desired_id]);
        }

        $stmt = $pdo->prepare('
            INSERT INTO nebenleitstellen (name, zustandigkeit, einwohner, flaeche_km2, gps)
            VALUES (:name, :zust, :einwohner, :flaeche_km2, :gps)
        ');
        $stmt->execute([
            ':name'        => $name,
            ':zust'        => $zust,
            ':einwohner'   => $einwohner,
            ':flaeche_km2' => $flaeche_km2,
            ':gps'         => $gps,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if (function_exists('lsttraining_log_activity')) {
            lsttraining_log_activity([
                'entity_type' => 'nebenstelle',
                'action'      => 'create',
                'entity_id'   => $newId,
                'meta'        => [
                    'created' => [
                        'name'          => $name,
                        'zustandigkeit' => $zust,
                        'einwohner'     => $einwohner,
                        'flaeche_km2'   => $flaeche_km2,
                        'gps'           => $gps,
                    ],
                ],
            ]);
        }

        wp_send_json_success(['id' => $newId]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

/**
 * Löscht eine Nebenstelle via AJAX
 * @action wp_ajax_lsttraining_delete_nebenstelle
 */
add_action('wp_ajax_lsttraining_delete_nebenstelle', function () {
    if (!lsttraining_user_can('nebenstellen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    check_ajax_referer('lsttraining_delete_nebenstelle', '_wpnonce');

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        wp_send_json_error('Ungültige ID', 400);
    }

    $pdo = lsttraining_get_connection();

    $stmt = $pdo->prepare('DELETE FROM nebenleitstellen WHERE id = ?');
    $ok = $stmt->execute([$id]);

    // Pivot-Beziehungen löschen
    $pdo->prepare('DELETE FROM wache_nebenleitstellen WHERE nebenleitstelle_id = ?')->execute([$id]);

    if ($ok) {
        lsttraining_log_activity([
            'entity_type' => 'nebenstelle',
            'action'      => 'delete',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:delete_nebenstelle'],
        ]);
        wp_send_json_success();
    }
    wp_send_json_error('Löschen fehlgeschlagen', 500);
});

/* -------------------------------------------------------------------------
 * 3. POP-UP-EDITOR (gemeinsamer Render-Endpunkt)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {
    require_once plugin_dir_path(__FILE__) . '/einsatzgebiet-editor.php';

    $mapId        = sanitize_text_field($_GET['map_id'] ?? 'einsatzgebiet_edit');
    $inputId      = sanitize_text_field($_GET['input_id'] ?? 'geojson_edit');
    $leitstelleId = (int)($_GET['leitstelle_id'] ?? 0);
    $context      = sanitize_text_field($_GET['context'] ?? 'leitstelle');
    $center       = sanitize_text_field($_GET['center'] ?? '');
    $geojson      = '';

    ob_start();
    lsttraining_einsatzgebiet_editor($mapId, $inputId, $geojson, $leitstelleId, $context, $center);
    echo ob_get_clean();
    wp_die();
});

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
    if (!lsttraining_user_can('wachen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

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
    if (!lsttraining_user_can('wachen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

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
    if (!lsttraining_user_can('wachen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

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
    if (!lsttraining_user_can('wachen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

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

/* -------------------------------------------------------------------------
 * 5. KRANKENHÄUSER (Liste, Details, CRUD, Departments)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_get_krankenhaeuser', function () {
    if (!lsttraining_user_can('hospitals')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }
    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('
        SELECT id, name, versorgungsstufe, trauma_level, latitude, longitude
          FROM krankenhaeuser
         ORDER BY name
    ');
    $stmt->execute();
    wp_send_json($stmt->fetchAll(PDO::FETCH_ASSOC));
});

/**
 * Einzelnes Krankenhaus lesen (read-only) – nopriv bleibt erhalten
 */
add_action('wp_ajax_get_krankenhaus', 'lsttraining_ajax_get_krankenhaus');
add_action('wp_ajax_nopriv_get_krankenhaus', 'lsttraining_ajax_get_krankenhaus');
function lsttraining_ajax_get_krankenhaus() {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Ungültige Krankenhaus-ID', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('
        SELECT id, name, versorgungsstufe, trauma_level,
               latitude, longitude, departments, helipad
          FROM krankenhaeuser
         WHERE id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $row ? wp_send_json_success($row) : wp_send_json_error('Krankenhaus nicht gefunden', 404);
    wp_die();
}

add_action('wp_ajax_delete_krankenhaus', function () {
    if (!lsttraining_user_can('hospitals')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Ungültige ID', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('DELETE FROM krankenhaeuser WHERE id = ?');
    $ok = $stmt->execute([$id]);

    if ($ok) {
        lsttraining_log_activity([
            'entity_type' => 'krankenhaus',
            'action'      => 'delete',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:delete_krankenhaus'],
        ]);
        wp_send_json_success();
    }
    wp_send_json_error('Löschen fehlgeschlagen', 500);
});

add_action('wp_ajax_save_krankenhaus', function () {
    if (!lsttraining_user_can('hospitals')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $id             = (int)($_POST['id'] ?? 0);
    $name           = sanitize_text_field($_POST['name'] ?? '');
    $versorgungsstufe = sanitize_text_field($_POST['versorgungsstufe'] ?? '');
    $trauma_level   = (int)($_POST['trauma_level'] ?? 0);
    $latitude       = (float)($_POST['latitude'] ?? 0);
    $longitude      = (float)($_POST['longitude'] ?? 0);
    $helipad        = isset($_POST['helipad']) ? 1 : 0;

    // optional: departments nur setzen, wenn übermittelt
    $departments_in = array_key_exists('departments', $_POST) ? wp_unslash($_POST['departments']) : null;

    $editor_id = get_current_user_id();
    $now_mysql = current_time('mysql', 1); // UTC

    if ($id <= 0 || $name === '') {
        wp_send_json_error('Ungültige Daten', 400);
    }

    $set = '
        name             = ?,
        versorgungsstufe = ?,
        trauma_level     = ?,
        latitude         = ?,

        longitude        = ?,
        helipad          = ?,
        last_update      = ?,
        last_editor      = ?';

    $params = [
        $name,
        $versorgungsstufe,
        $trauma_level,
        $latitude,
        $longitude,
        $helipad,
        $now_mysql,
        $editor_id,
    ];

    if ($departments_in !== null) {
        $set .= ', departments = ?';
        $params[] = $departments_in;
    }
    $params[] = $id;

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare("UPDATE krankenhaeuser SET $set WHERE id = ?");

    if ($stmt->execute($params)) {
        lsttraining_log_activity([
            'entity_type' => 'krankenhaus',
            'action'      => 'update',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:save_krankenhaus', 'departments_written' => ($departments_in !== null)],
        ]);
        wp_send_json_success();
    }
    wp_send_json_error('Speichern fehlgeschlagen', 500);
});

add_action('wp_ajax_lsttraining_create_krankenhaus', function () {
    if (!lsttraining_user_can('hospitals')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $name = sanitize_text_field($_POST['name'] ?? '');
    if ($name === '') {
        wp_send_json_error('Name fehlt', 400);
    }

    $versorgungsstufe = sanitize_text_field($_POST['versorgungsstufe'] ?? '');
    $trauma_level     = (int)($_POST['trauma_level'] ?? 0);

    $lat = (float)($_POST['latitude'] ?? 0);
    $lon = (float)($_POST['longitude'] ?? 0);
    if ($lat == 0.0 && $lon == 0.0 && !empty($_POST['coords'])) {
        $parts = explode(',', (string)$_POST['coords']);
        if (count($parts) === 2) {
            $lat = (float)trim($parts[0]);
            $lon = (float)trim($parts[1]);
        }
    }

    $departments = wp_unslash($_POST['departments'] ?? '');
    $departments = ($departments === '') ? '[]' : $departments;

    $helipad = isset($_POST['helipad']) ? 1 : 0;

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('
        INSERT INTO krankenhaeuser
            (name, versorgungsstufe, trauma_level, latitude, longitude, departments, helipad)
        VALUES (?,?,?,?,?,?,?)
    ');
    $ok = $stmt->execute([$name, $versorgungsstufe, $trauma_level, $lat, $lon, $departments, $helipad]);

    if ($ok) {
        $newId = (int)$pdo->lastInsertId();
        lsttraining_log_activity([
            'entity_type' => 'krankenhaus',
            'action'      => 'create',
            'entity_id'   => $newId,
            'meta'        => ['page' => 'ajax:create_krankenhaus'],
        ]);
        wp_send_json_success(['new_id' => $newId]);
    }
    wp_send_json_error('Anlegen fehlgeschlagen', 500);
});

/**
 * Departments-Liste speichern
 */
add_action('wp_ajax_lsttraining_save_departments', 'lsttraining_save_departments');
function lsttraining_save_departments() {
    if (!lsttraining_user_can('hospitals')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $hid = (int)($_POST['hospital_id'] ?? 0);
    if ($hid <= 0) {
        wp_send_json_error('Krankenhaus-ID fehlt', 400);
    }

    $raw = $_POST['departments'] ?? [];
    if (is_string($raw) && $raw !== '') {
        $raw = json_decode(wp_unslash($raw), true);
        if (!is_array($raw)) {
            wp_send_json_error('Ungültiges JSON', 400);
        }
    }
    if (empty($raw)) {
        wp_send_json_error('Keine Departments übermittelt', 400);
    }

    $defLat = isset($_POST['hospital_lat']) ? (float)$_POST['hospital_lat'] : 0.0;
    $defLon = isset($_POST['hospital_lon']) ? (float)$_POST['hospital_lon'] : 0.0;

    $map = []; // code => [Lat,Long]
    foreach ($raw as $key => $val) {
        // A) Checkbox-Array
        if (is_int($key) || ctype_digit((string)$key)) {
            $code = strtoupper(sanitize_key($val));
            if ($code !== '') {
                $map[$code] = ['Lat' => $defLat, 'Long' => $defLon];
            }
            continue;
        }

        // B) Neues JSON: CODE => {Lat,Long}
        if (is_array($val) && isset($val['Lat'], $val['Long'])) {
            $code = strtoupper(sanitize_key($key));
            if ($code !== '') {
                $map[$code] = ['Lat' => (float)$val['Lat'], 'Long' => (float)$val['Long']];
            }
            continue;
        }

        // C) Altes Einzel-Objekt
        if (is_array($val) && isset($val['code'])) {
            $code = strtoupper(sanitize_key($val['code']));
            if ($code !== '') {
                $map[$code] = [
                    'Lat'  => (float)($val['latitude'] ?? $defLat),
                    'Long' => (float)($val['longitude'] ?? $defLon),
                ];
            }
        }
    }

    if (empty($map)) {
        wp_send_json_error('Keine gültigen Codes gefunden', 400);
    }

    $out = [];
    foreach ($map as $code => $latlon) {
        $out[] = [$code => $latlon];
    }

    $json = wp_json_encode($out);

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('UPDATE krankenhaeuser SET departments = ? WHERE id = ?');
    $ok = $stmt->execute([$json, $hid]);

    if ($ok) {
        lsttraining_log_activity([
            'entity_type' => 'krankenhaus',
            'action'      => 'update',
            'entity_id'   => (int)$hid,
            'meta'        => ['page' => 'ajax:save_departments'],
        ]);
        wp_send_json_success();
    }
    wp_send_json_error('Speichern fehlgeschlagen', 500);
}

/**
 * Liefert Fachbereiche für ein Krankenhaus
 */
add_action('wp_ajax_get_departments', 'lsttraining_get_departments');
function lsttraining_get_departments() {
    if (!lsttraining_user_can('hospitals')) {
        wp_send_json_error('Keine Berechtigung.', 403);
    }

    $hid = (int)($_REQUEST['hospital_id'] ?? 0);
    if ($hid <= 0) {
        wp_send_json_error('Ungültige Krankenhaus-ID.', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('SELECT departments, latitude, longitude FROM krankenhaeuser WHERE id = :hid');
    if (!$stmt->execute([':hid' => $hid])) {
        wp_send_json_error('Datenbankfehler.', 500);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        wp_send_json_error('Krankenhaus nicht gefunden.', 404);
    }

    $existing_raw = json_decode((string)$row['departments'], true) ?: [];
    $existing = [];

    foreach ($existing_raw as $item) {
        if (is_array($item) && count($item) === 1) {
            $code = array_key_first($item);
            if ($code === null) continue;

            $lat = $item[$code]['Lat'] ?? $item[$code]['latitude'] ?? $row['latitude'];
            $lon = $item[$code]['Long'] ?? $item[$code]['longitude'] ?? $row['longitude'];

            $existing[] = ['code' => strtoupper($code), 'latitude' => (float)$lat, 'longitude' => (float)$lon];
            continue;
        }

        if (is_array($item) && isset($item['code'])) {
            $existing[] = [
                'code'      => strtoupper((string)$item['code']),
                'latitude'  => (float)($item['latitude'] ?? $row['latitude']),
                'longitude' => (float)($item['longitude'] ?? $row['longitude']),
            ];
            continue;
        }

        if (is_string($item) && $item !== '') {
            $existing[] = [
                'code'      => strtoupper($item),
                'latitude'  => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
            ];
        }
    }

    // departments.json robust laden (code + label)
    $deps_path = plugin_dir_path(__FILE__) . 'departments.json';
    $allowed_pairs = []; // [{code,label}]
    if (is_readable($deps_path)) {
        try {
            $parsed = json_decode((string)file_get_contents($deps_path), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                foreach ($parsed as $it) {
                    if (is_array($it) && isset($it['code'], $it['label'])) {

                        $allowed_pairs[] = ['code' => strtoupper((string)$it['code']), 'label' => (string)$it['label']];
                    } elseif (is_string($it) && $it !== '') {
                        $allowed_pairs[] = ['code' => strtoupper($it), 'label' => $it];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('get_departments: JSON-Fehler in departments.json – ' . $e->getMessage());
        }
    } else {
        error_log('get_departments: departments.json nicht lesbar: ' . $deps_path);
    }

    $label_by_code = [];
    foreach ($allowed_pairs as $p) {
        $label_by_code[$p['code']] = $p['label'];
    }

    $existing_codes  = [];
    $existing_labels = [];
    foreach ($existing as $ex) {
        $code = $ex['code'];
        $existing_codes[]  = $code;
        $existing_labels[] = $label_by_code[$code] ?? $code;

        if (!isset($label_by_code[$code])) {
            $allowed_pairs[] = ['code' => $code, 'label' => $code];
            $label_by_code[$code] = $code;
        }
    }

    $allowed_labels = array_values(array_unique(array_map(static function ($p) {
        return $p['label'];
    }, $allowed_pairs)));

    wp_send_json_success([
        'hospital_id'     => $hid,
        'existing'        => $existing,
        'existing_codes'  => $existing_codes,
        'existing_labels' => $existing_labels,
        'allowed'         => $allowed_labels,
        'allowed_pairs'   => $allowed_pairs,
        'label_by_code'   => $label_by_code,
        'hospital_lat'    => (float)$row['latitude'],
        'hospital_lon'    => (float)$row['longitude'],
    ]);
}

/* -------------------------------------------------------------------------
 * 6. LEITSTELLE ↔ HOSPITAL ZUORDNUNG
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_get_leitstelle_hospitals', function () {
    $id = (int)($_GET['leitstelle_id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Ungültige Leitstelle', 400);
    }
    if (!lsttraining_user_can('leitstellen', $id)) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $pdo = lsttraining_get_connection();

    try {
        $stmt = $pdo->prepare('
            SELECT available_hospitals,
                   latitude  AS leitstelle_lat,
                   longitude AS leitstelle_lon,
                   geojson
              FROM leitstellen
             WHERE id = :id
             LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            wp_send_json_error('Leitstelle nicht gefunden', 404);
        }

        $existing = json_decode((string)$row['available_hospitals'], true) ?: [];

        // Fallback: alle KH im Polygon
        if (empty($existing) && !empty($row['geojson'])) {
            $stmt2 = $pdo->prepare('
                SELECT id
                  FROM krankenhaeuser
                 WHERE ST_Contains(
                         ST_GeomFromText(ST_AsText(ST_GeomFromGeoJSON(:geojson))),
                         ST_GeomFromText(CONCAT("POINT(", longitude, " ", latitude, ")"))
                       )
            ');
            $stmt2->execute([':geojson' => $row['geojson']]);
            $existing = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        }

        $stmt3 = $pdo->query('SELECT id, name, latitude, longitude FROM krankenhaeuser ORDER BY name');
        $hospitals = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        wp_send_json_success([
            'leitstelle_id' => $id,
            'existing'      => $existing,
            'hospitals'     => $hospitals,
            'leitstelle_lat'=> (float)$row['leitstelle_lat'],
            'leitstelle_lon'=> (float)$row['leitstelle_lon'],
            'geojson'       => $row['geojson'],
        ]);
    } catch (Throwable $e) {
        wp_send_json_error('Datenbankfehler: ' . $e->getMessage(), 500);
    }
});

add_action('wp_ajax_save_leitstelle_hospitals', function () {
    $id = (int)($_POST['leitstelle_id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Ungültige Leitstelle', 400);
    }
    if (!lsttraining_user_can('leitstellen', $id)) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $items = isset($_POST['hospitals']) ? json_decode(wp_unslash($_POST['hospitals']), true) : [];
    if (!is_array($items)) {
        wp_send_json_error('Ungültige Daten', 400);
    }

    $json = wp_json_encode(array_map('intval', $items));

    $pdo = lsttraining_get_connection();
    try {
        $stmt = $pdo->prepare('
            UPDATE leitstellen
               SET available_hospitals = :json
             WHERE id = :id
        ');
        $stmt->execute([':json' => $json, ':id' => $id]);

        lsttraining_log_activity([
            'entity_type' => 'leitstelle',
            'action'      => 'update',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:save_leitstelle_hospitals'],
        ]);

        wp_send_json_success();
    } catch (Throwable $e) {
        wp_send_json_error('Speicherfehler: ' . $e->getMessage(), 500);
    }
});

/* -------------------------------------------------------------------------
 * 7. BENUTZER-RECHTE (nur Admins – global)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_lsttraining_get_user_permissions', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.', 403);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('Datenbankverbindung fehlgeschlagen.', 500);
    }

    $wp_users = get_users(['fields' => ['ID', 'user_login', 'display_name']]);
    $user_ids = wp_list_pluck($wp_users, 'ID');
    if (empty($user_ids)) {
        wp_send_json_success([]);
    }

    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $sql = "
        SELECT user_id,
               can_edit_leitstellen,
               can_edit_nebenstellen,
               can_edit_hospitals,
               can_edit_wachen,
               can_edit_fahrzeuge,
               leitstellen_ids
          FROM user_permissions
         WHERE user_id IN ($placeholders)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($user_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $perms_by_user = [];
    foreach ($rows as $r) {
        $perms_by_user[(int)$r['user_id']] = [
            'leitstellen'    => (int)$r['can_edit_leitstellen'],
            'nebenstellen'   => (int)$r['can_edit_nebenstellen'],
            'hospitals'      => (int)$r['can_edit_hospitals'],
            'wachen'         => (int)$r['can_edit_wachen'],
            'fahrzeuge'      => (int)$r['can_edit_fahrzeuge'],
            'leitstellen_ids' => (string)$r['leitstellen_ids'],
        ];
    }

    $result = [];
    foreach ($wp_users as $u) {
        $uid = (int)$u->ID;
        $result[] = [
            'ID'           => $uid,
            'user_login'   => $u->user_login,
            'display_name' => $u->display_name ?: $u->user_login,
            'permissions'  => $perms_by_user[$uid] ?? [
                'leitstellen'    => 0,
                'nebenstellen'   => 0,
                'hospitals'      => 0,
                'wachen'         => 0,
                'fahrzeuge'      => 0,
                'leitstellen_ids' => '',
            ],
        ];
    }

    wp_send_json_success($result);
});

add_action('wp_ajax_lsttraining_save_user_permissions', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung.', 403);
    }

    $json = wp_unslash($_POST['user_permissions'] ?? '');
    if ($json === '') {
        wp_send_json_error('Keine Daten übermittelt.', 400);
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        wp_send_json_error('Ungültiges JSON-Format.', 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('Datenbankverbindung fehlgeschlagen.', 500);
    }

    $stmtCheck  = $pdo->prepare('SELECT user_id FROM user_permissions WHERE user_id = ?');
    $stmtInsert = $pdo->prepare('
        INSERT INTO user_permissions (
            user_id,
            can_edit_leitstellen,
            can_edit_nebenstellen,
            can_edit_hospitals,
            can_edit_wachen,
            can_edit_fahrzeuge,
            leitstellen_ids
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmtUpdate = $pdo->prepare('
        UPDATE user_permissions
           SET can_edit_leitstellen  = ?,
               can_edit_nebenstellen = ?,
               can_edit_hospitals    = ?,
               can_edit_wachen       = ?,
               can_edit_fahrzeuge    = ?,
               leitstellen_ids        = ?
         WHERE user_id = ?
    ');

    try {
        $pdo->beginTransaction();

        foreach ($data as $entry) {
            $user_id = (int)($entry['user_id'] ?? 0);
            if ($user_id <= 0) continue;

            $can_leitstellen  = !empty($entry['can_edit_leitstellen']) ? 1 : 0;
            $can_nebenstellen = !empty($entry['can_edit_nebenstellen']) ? 1 : 0;
            $can_hospitals    = !empty($entry['can_edit_hospitals']) ? 1 : 0;
            $can_wachen       = !empty($entry['can_edit_wachen']) ? 1 : 0;
            $can_fahrzeuge    = !empty($entry['can_edit_fahrzeuge']) ? 1 : 0;

            $ids_raw = sanitize_text_field($entry['leitstellen_ids'] ?? '');
            $ids_arr = array_filter(array_map('trim', explode(',', $ids_raw)), static function ($v) {
                return $v !== '' && ctype_digit($v);
            });
            $leitstellen_ids = implode(',', $ids_arr);

            $stmtCheck->execute([$user_id]);
            $exists = (bool)$stmtCheck->fetchColumn();

            if ($exists) {
                $stmtUpdate->execute([
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leitstellen_ids,
                    $user_id,
                ]);
            } else {
                $stmtInsert->execute([
                    $user_id,
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leitstellen_ids,
                ]);
            }
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'user_permissions',
            'action'      => 'update',
            'entity_id'   => 0,
            'meta'        => ['page' => 'ajax:save_user_permissions'],
        ]);

        wp_send_json_success();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        wp_send_json_error('Datenbank-Fehler: ' . $e->getMessage(), 500);
    }
});

/* -------------------------------------------------------------------------
 * 8. COPY LEITSTELLE -> NEBENSTELLE (Pivot + Geo)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_lsttraining_copy_leitstelle', 'lsttraining_ajax_copy_leitstelle');
function lsttraining_ajax_copy_leitstelle() {
    if (!lsttraining_user_can('nebenstellen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }
    check_ajax_referer('lsttraining_copy_leitstelle', '_wpnonce');

    $neben_id = filter_input(INPUT_POST, 'neben_id', FILTER_VALIDATE_INT);
    $leit_id  = filter_input(INPUT_POST, 'leit_id', FILTER_VALIDATE_INT);
    if (!$neben_id || !$leit_id) {
        wp_send_json_error('Ungültige IDs', 400);
    }

    try {
        $pdo = lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            throw new Exception('DB-Verbindung fehlgeschlagen');
        }

        $insert = $pdo->prepare('
            INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id)
            SELECT wl.wache_id, :nid
              FROM wache_leitstellen AS wl
             WHERE wl.leitstelle_id = :lid
               AND wl.wache_id NOT IN (
                   SELECT wache_id
                     FROM wache_nebenleitstellen
                    WHERE nebenleitstelle_id = :nid
               )
        ');
        $insert->execute([':nid' => (int)$neben_id, ':lid' => (int)$leit_id]);

        $stmt = $pdo->prepare('
            SELECT latitude, longitude, geojson
              FROM leitstellen
             WHERE id = :lid
             LIMIT 1
        ');
        $stmt->execute([':lid' => (int)$leit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Leitstelle nicht gefunden (ID ' . $leit_id . ')');
        }

        $stmtChk = $pdo->prepare('SELECT 1 FROM nebenleitstellen WHERE id = :nid LIMIT 1');
        $stmtChk->execute([':nid' => (int)$neben_id]);
        if (!$stmtChk->fetchColumn()) {
            throw new Exception('Nebenleitstelle nicht gefunden (ID ' . $neben_id . ')');
        }

        $gps = $row['latitude'] . ', ' . $row['longitude'];
        $upd = $pdo->prepare('
            UPDATE nebenleitstellen
               SET gps     = :gps,
                   geojson = :geo
             WHERE id      = :nid
        ');
        $upd->execute([':gps' => $gps, ':geo' => $row['geojson'], ':nid' => (int)$neben_id]);

        lsttraining_log_activity([
            'entity_type' => 'nebenstelle',
            'action'      => 'update',
            'entity_id'   => (int)$neben_id,
            'meta'        => ['page' => 'ajax:copy_leitstelle', 'from_leitstelle_id' => (int)$leit_id],
        ]);

        wp_send_json_success('Nebenstelle erfolgreich kopiert');
    } catch (Throwable $e) {
        error_log('lsttraining_copy_leitstelle ERROR: ' . $e->getMessage());
        wp_send_json_error('Server-Fehler beim Übernehmen', 500);
    }
}

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
    if (!function_exists('lsttraining_user_can') || !lsttraining_user_can('wachen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

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

/* -------------------------------------------------------------------------
 * 10. FAHRZEUGE (GET/SAVE/DELETE/UPLOAD/SUCHE)
 * ---------------------------------------------------------------------- */

// --- GET: ein Fahrzeug laden ----------------------------------------------
add_action('wp_ajax_lsttraining_get_fahrzeug', function () {
    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        wp_send_json_error(['code' => 'bad_nonce', 'msg' => 'Nonce ungültig'], 403);
    }
    if (function_exists('lsttraining_user_can') && !lsttraining_user_can('fahrzeuge')) {
        wp_send_json_error(['code' => 'forbidden', 'msg' => 'Keine Berechtigung'], 403);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error(['code' => 'bad_request', 'msg' => 'ID fehlt'], 400);
    }

    $has_bl = false;
    try {
        $stc = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'bundesland'");
        $has_bl = $stc && $stc->rowCount() > 0;
    } catch (Throwable $e) {}

    try {
        $sql = "SELECT
                    f.id, f.wache_id, f.rufname, f.fahrzeugtyp, f.source_note, f.is_first_responder,
                    f.status, f.fms_status, f.dienstzeiten, f.bild_datei,
                    w.name AS wache_name" . ($has_bl ? ", w.bundesland AS wache_bundesland" : "") . "
                FROM fahrzeuge f
                JOIN wachen w ON w.id = f.wache_id
                WHERE f.id = ?";
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            wp_send_json_error(['code' => 'not_found', 'msg' => 'Nicht gefunden'], 404);
        }
        $row['is_first_responder'] = (int)!empty($row['is_first_responder']);
        wp_send_json_success(['fahrzeug' => $row]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Lesefehler: ' . $e->getMessage()], 500);
    }
});

// --- SAVE: Fahrzeug neu anlegen / aktualisieren ----------------------------
add_action('wp_ajax_lsttraining_save_fahrzeug', function () {
    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        wp_send_json_error(['code' => 'bad_nonce', 'msg' => 'Nonce ungültig'], 403);
    }
    if (function_exists('lsttraining_user_can') && !lsttraining_user_can('fahrzeuge')) {
        wp_send_json_error(['code' => 'forbidden', 'msg' => 'Keine Berechtigung'], 403);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id           = (int)($_POST['id'] ?? 0);
    $wache_id     = (int)($_POST['wache_id'] ?? 0);
    $rufname      = sanitize_text_field($_POST['rufname'] ?? '');
    $fahrzeugtyp  = sanitize_text_field($_POST['fahrzeugtyp'] ?? '');
    $source_note  = sanitize_text_field($_POST['source_note'] ?? '');
    $is_fr        = isset($_POST['is_first_responder']) ? (int)!!$_POST['is_first_responder'] : 0;
    $fms_status   = sanitize_text_field($_POST['fms_status'] ?? '2'); // nur '2' oder '6'
    $dienstzeiten = sanitize_text_field($_POST['dienstzeiten'] ?? '');
    $bild_datei   = sanitize_text_field($_POST['bild_datei'] ?? '');

    if ($wache_id <= 0 || $rufname === '' || $fahrzeugtyp === '') {
        wp_send_json_error(['code' => 'validation', 'msg' => 'Wache, Rufname und Fahrzeugtyp sind Pflichtfelder'], 400);
    }
    if (!in_array($fms_status, ['2', '6'], true)) {
        wp_send_json_error(['code' => 'validation', 'msg' => 'Ungültiger FMS-Status (nur 2 oder 6 erlaubt)'], 400);
    }

    $status = ($fms_status === '6') ? 'nicht einsatzbereit' : 'einsatzbereit';

    // Eindeutigkeitsprüfung (wache_id, rufname)
    try {
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM fahrzeuge WHERE wache_id = ? AND rufname = ? AND id <> ?');
            $chk->execute([$wache_id, $rufname, $id]);
        } else {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM fahrzeuge WHERE wache_id = ? AND rufname = ?');
            $chk->execute([$wache_id, $rufname]);
        }
        if ((int)$chk->fetchColumn() > 0) {
            wp_send_json_error(['code' => 'conflict', 'msg' => 'Rufname in dieser Wache bereits vergeben'], 409);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Eindeutigkeitsprüfung fehlgeschlagen: ' . $e->getMessage()], 500);
    }

    try {
        if ($id > 0) {
            $pdo->beginTransaction();

            $stOld = $pdo->prepare('
                SELECT wache_id, rufname, fahrzeugtyp, source_note, is_first_responder,
                       status, fms_status, dienstzeiten, bild_datei
                  FROM fahrzeuge
                 WHERE id = ?
            ');
            $stOld->execute([$id]);
            $old = $stOld->fetch(PDO::FETCH_ASSOC) ?: [];

            $u = $pdo->prepare('
                UPDATE fahrzeuge
                   SET wache_id = ?, rufname = ?, fahrzeugtyp = ?, source_note = ?, is_first_responder = ?,
                       status = ?, fms_status = ?, dienstzeiten = ?, bild_datei = ?
                 WHERE id = ?
            ');
            $u->execute([
                $wache_id, $rufname, $fahrzeugtyp, $source_note, $is_fr,
                $status, $fms_status, $dienstzeiten, $bild_datei,
                $id,
            ]);

            $pdo->commit();

            $new = [
                'wache_id'           => $wache_id,
                'rufname'            => $rufname,
                'fahrzeugtyp'        => $fahrzeugtyp,
                'source_note'        => $source_note,
                'is_first_responder' => $is_fr,
                'status'             => $status,
                'fms_status'         => $fms_status,
                'dienstzeiten'       => $dienstzeiten,
                'bild_datei'         => $bild_datei,
            ];
            $norm = static function ($v) {
                if ($v === null) return null;
                if (is_bool($v)) return $v ? 1 : 0;
                if (is_numeric($v)) return (string)+$v;
                return trim((string)$v);
            };
            $changes = [];
            foreach ($new as $k => $nv) {
                $ov = array_key_exists($k, $old) ? $old[$k] : null;
                if ($norm($ov) !== $norm($nv)) {
                    $changes[$k] = ['old' => $ov, 'new' => $nv];
                }
            }

            if ($changes) {
                lsttraining_log_activity([
                    'entity_type' => 'fahrzeug',
                    'action'      => 'update',
                    'entity_id'   => (int)$id,
                    'meta'        => ['changes' => $changes],
                ]);
            }

            wp_send_json_success(['id' => $id]);
        }

        // INSERT
        $pdo->beginTransaction();

        $i = $pdo->prepare('
            INSERT INTO fahrzeuge
                (wache_id, rufname, fahrzeugtyp, source_note, is_first_responder,
                 status, fms_status, dienstzeiten, bild_datei)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        $i->execute([
            $wache_id, $rufname, $fahrzeugtyp, $source_note, $is_fr,
            $status, $fms_status, $dienstzeiten, $bild_datei,
        ]);
        $new_id = (int)$pdo->lastInsertId();

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'fahrzeug',
            'action'      => 'create',
            'entity_id'   => $new_id,
            'meta'        => [
                'created' => [
                    'wache_id'           => $wache_id,
                    'rufname'            => $rufname,
                    'fahrzeugtyp'        => $fahrzeugtyp,
                    'source_note'        => $source_note,
                    'is_first_responder' => $is_fr,
                    'status'             => $status,
                    'fms_status'         => $fms_status,
                    'dienstzeiten'       => $dienstzeiten,
                    'bild_datei'         => $bild_datei,
                ],
            ],
        ]);

        wp_send_json_success(['id' => $new_id]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {

            $pdo->rollBack();
        }
        wp_send_json_error(['code' => 'db', 'msg' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

// --- DELETE: Fahrzeug löschen ---------------------------------------------
add_action('wp_ajax_lsttraining_delete_fahrzeug', function () {
    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        wp_send_json_error(['code' => 'bad_nonce', 'msg' => 'Nonce ungültig'], 403);
    }
    if (function_exists('lsttraining_user_can') && !lsttraining_user_can('fahrzeuge')) {
        wp_send_json_error(['code' => 'forbidden', 'msg' => 'Keine Berechtigung'], 403);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error(['code' => 'bad_request', 'msg' => 'ID fehlt'], 400);
    }

    try {
        $st = $pdo->prepare('
            SELECT wache_id, rufname, fahrzeugtyp, source_note, is_first_responder,
                   status, fms_status, dienstzeiten, bild_datei
              FROM fahrzeuge
             WHERE id = ?
        ');
        $st->execute([$id]);
        $old = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $d = $pdo->prepare('DELETE FROM fahrzeuge WHERE id = ?');
        $d->execute([$id]);

        lsttraining_log_activity([
            'entity_type' => 'fahrzeug',
            'action'      => 'delete',
            'entity_id'   => (int)$id,
            'meta'        => ['deleted' => $old],
        ]);

        wp_send_json_success(['ok' => true]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Löschen fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

// --- UPLOAD: Bild-Datei für Fahrzeug --------------------------------------
add_action('wp_ajax_lsttraining_upload_fahrzeug_bild', function () {
    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        wp_send_json_error(['code' => 'bad_nonce', 'msg' => 'Nonce ungültig'], 403);
    }
    if (function_exists('lsttraining_user_can') && !lsttraining_user_can('fahrzeuge')) {
        wp_send_json_error(['code' => 'forbidden', 'msg' => 'Keine Berechtigung'], 403);
    }

    $fileArray = null;
    if (!empty($_FILES['file'])) {
        $fileArray = $_FILES['file'];
    } elseif (!empty($_FILES['fz_file'])) {
        $fileArray = $_FILES['fz_file'];
    }

    if (!$fileArray || empty($fileArray['name']) || empty($fileArray['tmp_name'])) {
        wp_send_json_error(['code' => 'no_file', 'msg' => 'Keine Datei empfangen. Bitte erneut auswählen.'], 200);
    }

    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $overrides = [
        'test_form' => false,
        'mimes'     => [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ],
    ];

    $postMax   = ini_get('post_max_size');
    $uploadMax = ini_get('upload_max_filesize');

    $movefile = wp_handle_upload($fileArray, $overrides);

    if ($movefile && !isset($movefile['error'])) {
        wp_send_json_success([
            'url'        => $movefile['url'],
            'type'       => $movefile['type'],
            'post_max'   => $postMax,
            'upload_max' => $uploadMax,
        ]);
    }

    $msg = (is_array($movefile) && isset($movefile['error'])) ? $movefile['error'] : 'Upload fehlgeschlagen';
    wp_send_json_error([
        'code' => 'upload',
        'msg'  => $msg . ' (max: post_max_size=' . $postMax . ', upload_max_filesize=' . $uploadMax . ')',
    ], 200);
});

// --- SEARCH: Wachen für Fahrzeug-Form -------------------------------------
add_action('wp_ajax_lsttraining_search_wachen', function () {
    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        wp_send_json_error(['code' => 'bad_nonce', 'msg' => 'Nonce ungültig'], 403);
    }
    if (function_exists('lsttraining_user_can') && !lsttraining_user_can('fahrzeuge')) {
        wp_send_json_error(['code' => 'forbidden', 'msg' => 'Keine Berechtigung'], 403);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $q       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $land    = isset($_GET['land']) ? trim((string)$_GET['land']) : '';
    $bl_name = isset($_GET['bundesland_name']) ? trim((string)$_GET['bundesland_name']) : '';
    $limit   = 50;

    $has_bl = false; $has_bl_code = false; $has_land = false;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'bundesland'");
        $has_bl = $st && $st->rowCount() > 0;
        $st2 = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'bundesland_code'");
        $has_bl_code = $st2 && $st2->rowCount() > 0;
        $st3 = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'land'");
        $has_land = $st3 && $st3->rowCount() > 0;
    } catch (Throwable $e) {}

    $where = [];
    $args  = [];

    if ($land !== '' && $has_land) {
        $where[] = 'land = ?';
        $args[]  = $land;
    }

    if ($bl_name !== '' && ($has_bl || $has_bl_code)) {
        $cond = [];
        if ($has_bl)      { $cond[] = 'bundesland = ?';      $args[] = $bl_name; }
        if ($has_bl_code) { $cond[] = 'bundesland_code = ?'; $args[] = $bl_name; }
        if ($cond) $where[] = '(' . implode(' OR ', $cond) . ')';
    }

    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = '(id = ? OR name LIKE ?)';
            $args[]  = (int)$q;
            $args[]  = '%' . $q . '%';
        } else {
            $where[] = '(name LIKE ?)';
            $args[]  = '%' . $q . '%';
        }
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "
        SELECT id, name
          FROM wachen
          $where_sql
         ORDER BY name ASC
         LIMIT $limit
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);

        $items = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id'   => (int)$r['id'],
                'text' => $r['name'] . ' (#' . (int)$r['id'] . ')',
            ];
        }
        wp_send_json_success(['items' => $items]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Suche fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});
