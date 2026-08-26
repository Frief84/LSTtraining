<?php
// Krankenhäuser + Zuordnung Leitstelle↔Hospital
/* -------------------------------------------------------------------------
 * 5. KRANKENHÄUSER (Liste, Details, CRUD, Departments)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_get_krankenhaeuser', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'hospitals',
        'nonce_action' => 'lsttraining_hospitals',
        'method' => 'GET',
    ]);

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
        // Guard
    lsttraining_ajax_guard([
        'area' => 'hospitals',
        'nonce_action' => 'lsttraining_hospitals',
        'method' => 'POST',
    ]);

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
        // Guard
    lsttraining_ajax_guard([
        'area' => 'hospitals',
        'nonce_action' => 'lsttraining_hospitals',
        'method' => 'POST',
    ]);

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
        // Guard
    lsttraining_ajax_guard([
        'area' => 'hospitals',
        'nonce_action' => 'lsttraining_hospitals',
        'method' => 'POST',
    ]);

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
    $poi_id = 'manual-' . wp_generate_uuid4();

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('
        INSERT INTO krankenhaeuser
            (poi_id, name, versorgungsstufe, trauma_level, latitude, longitude, departments, helipad)
        VALUES (?,?,?,?,?,?,?,?)
    ');
    $ok = $stmt->execute([$poi_id, $name, $versorgungsstufe, $trauma_level, $lat, $lon, $departments, $helipad]);

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
        // Guard
    lsttraining_ajax_guard([
        'area' => 'hospitals',
        'nonce_action' => 'lsttraining_hospitals',
        'method' => 'POST',
    ]);

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
    lsttraining_ajax_guard([
        'area' => 'hospitals',
        'nonce_action' => 'lsttraining_hospitals',
        'method' => 'GET',
    ]);

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
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'method' => 'GET',
    ]);
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
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'method' => 'POST',
    ]);
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
