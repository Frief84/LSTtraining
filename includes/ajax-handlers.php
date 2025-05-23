<?php
require_once plugin_dir_path(__FILE__) . '/db.php';

/* -------------------------------------------------------------------------
 * LEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Leitstelle laden
 * @action wp_ajax_lsttraining_get_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_get_einsatzgebiet', function () {
    $leitstelle_id = intval( $_GET['leitstelle_id'] ?? 0 );
    if ( ! $leitstelle_id ) {
        wp_send_json_error( 'Leitstellen-ID fehlt' );
    }
    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "SELECT geojson FROM leitstellen WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $leitstelle_id ] );
    $geojson = $stmt->fetchColumn();
    wp_send_json_success( $geojson ? json_decode( $geojson, true ) : null );
});

/**
 * GeoJSON einer Leitstelle speichern
 * @action wp_ajax_lsttraining_save_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_save_einsatzgebiet', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    $leitstelle_id = intval( $_POST['leitstelle_id'] ?? 0 );
    $geojson       = stripslashes( $_POST['geojson'] ?? '' );
    if ( $leitstelle_id <= 0 || empty( $geojson ) ) {
        wp_send_json_error( 'Invalid data', 400 );
    }
    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "UPDATE leitstellen SET geojson = ? WHERE id = ?" );
    $stmt->execute( [ $geojson, $leitstelle_id ] );
    wp_send_json_success();
});

/* -------------------------------------------------------------------------
 * NEBENLEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Nebenleitstelle laden
 * @action wp_ajax_lsttraining_get_neben_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_get_neben_einsatzgebiet', function () {
    $neben_id = intval( $_GET['neben_id'] ?? 0 );
    if ( ! $neben_id ) {
        wp_send_json_error( 'Nebenleitstellen-ID fehlt' );
    }
    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "SELECT geojson FROM nebenleitstellen WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $neben_id ] );
    $geojson = $stmt->fetchColumn();
    wp_send_json_success( $geojson ? json_decode( $geojson, true ) : null );
});

/**
 * GeoJSON einer Nebenleitstelle speichern
 * @action wp_ajax_lsttraining_save_neben_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_save_neben_einsatzgebiet', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    $neben_id = intval( $_POST['neben_id'] ?? 0 );
    $geojson  = stripslashes( $_POST['geojson'] ?? '' );
    if ( $neben_id <= 0 || empty( $geojson ) ) {
        wp_send_json_error( 'Invalid data', 400 );
    }
    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "UPDATE nebenleitstellen SET geojson = ? WHERE id = ?" );
    $stmt->execute( [ $geojson, $neben_id ] );
    wp_send_json_success();
});

/* -------------------------------------------------------------------------
 * POP-UP-EDITOR (gemeinsam)
 * ---------------------------------------------------------------------- */

/**
 * Rendert das HTML für den Einsatzgebiet-Editor
 * @action wp_ajax_lsttraining_render_einsatzgebiet_editor
 */
add_action( 'wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {
    require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';
    $mapId        = sanitize_text_field( $_GET['map_id']        ?? 'einsatzgebiet_edit' );
    $inputId      = sanitize_text_field( $_GET['input_id']      ?? 'geojson_edit' );
    $leitstelleId = intval( $_GET['leitstelle_id'] ?? 0 );
    $context      = sanitize_text_field( $_GET['context']       ?? 'leitstelle' );
    $center       = sanitize_text_field( $_GET['center']        ?? '' );
    $geojson = '';
    ob_start();
    lsttraining_einsatzgebiet_editor( $mapId, $inputId, $geojson, $leitstelleId, $context, $center );
    echo ob_get_clean();
    wp_die();
});

/* -------------------------------------------------------------------------
 * WACHEN (Liste, Einzeldaten, Speichern, Löschen)
 * ---------------------------------------------------------------------- */

/**
 * Liste der Wachen für Karte/Tabelle
 * @action wp_ajax_lsttraining_get_wachen
 */
add_action( 'wp_ajax_lsttraining_get_wachen', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    $ls  = intval( $_GET['ls_id']  ?? 0 );
    $nls = intval( $_GET['nls_id'] ?? 0 );
    if ( ! $ls && ! $nls ) {
        wp_send_json_error( 'Kein Filter angegeben.', 400 );
    }

    $pdo    = lsttraining_get_connection();
    $sql    = 'SELECT id, name, typ, latitude, longitude,
                      arrival_pos, departure_pos
                 FROM wachen WHERE 1 = 1';
    $params = [];
    if ( $ls  ) { $sql .= ' AND leitstelle_id      = ?'; $params[] = $ls;  }
    if ( $nls ) { $sql .= ' AND nebenleitstelle_id = ?'; $params[] = $nls; }

    $stmt = $pdo->prepare( $sql );
    $stmt->execute( $params );

    wp_send_json_success( $stmt->fetchAll( PDO::FETCH_ASSOC ) );
} );

/**
 * Daten einer einzelnen Wache laden
 * @action wp_ajax_lsttraining_get_wache
 */
add_action( 'wp_ajax_lsttraining_get_wache', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id = intval( $_GET['wache_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Wache-ID fehlt', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'SELECT id, name, typ, latitude, longitude,
                arrival_pos, departure_pos
           FROM wachen
          WHERE id = ?'
    );
    $stmt->execute( [ $id ] );
    $row = $stmt->fetch( PDO::FETCH_ASSOC );

    $row ? wp_send_json_success( $row )
         : wp_send_json_error   ( 'Nicht gefunden', 404 );
} );

/**
 * Speichert Änderungen an einer Wache
 * @action wp_ajax_lsttraining_save_wache
 */
add_action( 'wp_ajax_lsttraining_save_wache', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id        = intval( $_POST['id']        ?? 0 );
    $name      = sanitize_text_field( $_POST['name'] ?? '' );
    $typ       = sanitize_text_field( $_POST['typ']  ?? '' );
    $latitude  = floatval( $_POST['latitude']  ?? 0 );
    $longitude = floatval( $_POST['longitude'] ?? 0 );

    /* neue optionale Felder */
    $arrival   = sanitize_text_field( $_POST['arrival_pos']   ?? '' );
    $departure = sanitize_text_field( $_POST['departure_pos'] ?? '' );

    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige Wache-ID', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'UPDATE wachen
            SET name          = ?,
                typ           = ?,
                latitude      = ?,
                longitude     = ?,
                arrival_pos   = ?,
                departure_pos = ?
          WHERE id = ?'
    );

    $ok = $stmt->execute( [
        $name,
        $typ,
        $latitude,
        $longitude,
        $arrival,
        $departure,
        $id
    ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
} );

/**
 * Löscht eine Wache
 * @action wp_ajax_lsttraining_delete_wache
 */
add_action( 'wp_ajax_lsttraining_delete_wache', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id = intval( $_POST['wache_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Ungültige Wache-ID', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( 'DELETE FROM wachen WHERE id = ?' );
    $ok   = $stmt->execute( [ $id ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Löschen fehlgeschlagen', 500 );
} );

/* --------- NEU: Wache anlegen ----------------------------------- */
add_action( 'wp_ajax_lsttraining_create_wache', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $name       = sanitize_text_field( $_POST['name'] ?? '' );
    $typ        = sanitize_text_field( $_POST['typ']  ?? '' );
    $lat        = floatval( $_POST['latitude']  ?? 0 );
    $lon        = floatval( $_POST['longitude'] ?? 0 );
    $arrival    = sanitize_text_field( $_POST['arrival_pos']   ?? '' );
    $departure  = sanitize_text_field( $_POST['departure_pos'] ?? '' );
    $ls_id      = intval( $_POST['leitstelle_id'] ?? 0 );        // falls nötig

    if ( $lat === 0 || $lon === 0 || $name === '' ) {
        wp_send_json_error( 'Pflichtfelder fehlen', 400 );
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'INSERT INTO wachen
             (leitstelle_id, name, typ, latitude, longitude, arrival_pos, departure_pos)
         VALUES (?,?,?,?,?,?,?)'
    );
    $ok = $stmt->execute([
        $ls_id, $name, $typ, $lat, $lon, $arrival, $departure
    ]);

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Anlegen fehlgeschlagen', 500 );
});

add_action('wp_ajax_get_krankenhaeuser', 'lsttraining_ajax_get_krankenhaeuser');

function lsttraining_ajax_get_krankenhaeuser() {
    $pdo = lsttraining_get_connection();

    $stmt = $pdo->prepare("SELECT id, name, versorgungsstufe, trauma_level, latitude, longitude FROM krankenhaeuser ORDER BY name");
    $stmt->execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    wp_send_json($results);
}

// Einzelnes Krankenhaus laden (inkl. departments)
add_action( 'wp_ajax_get_krankenhaus', 'lsttraining_ajax_get_krankenhaus' );
add_action( 'wp_ajax_nopriv_get_krankenhaus', 'lsttraining_ajax_get_krankenhaus' );
function lsttraining_ajax_get_krankenhaus() {
    header( 'Content-Type: application/json; charset=utf-8' );

    $id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige Krankenhaus-ID', 400 );
        wp_die();
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'SELECT 
            id, 
            name, 
            versorgungsstufe, 
            trauma_level, 
            latitude, 
            longitude,
            departments,
            helipad
         FROM krankenhaeuser
         WHERE id = ?'
    );
    $stmt->execute( [ $id ] );
    $krankenhaus = $stmt->fetch( PDO::FETCH_ASSOC );

    if ( ! $krankenhaus ) {
        wp_send_json_error( 'Krankenhaus nicht gefunden', 404 );
        wp_die();
    }

    wp_send_json_success( $krankenhaus );
    wp_die();
}

add_action('wp_ajax_delete_krankenhaus', 'lsttraining_ajax_delete_krankenhaus');
function lsttraining_ajax_delete_krankenhaus() {
    if (! current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }
    // Hier muss 'id' stehen, wenn JS 'id' sendet:
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error('Ungültige ID', 400);
    }
    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('DELETE FROM krankenhaeuser WHERE id = ?');
    $ok = $stmt->execute([$id]);
    $ok
      ? wp_send_json_success()
      : wp_send_json_error('Löschen fehlgeschlagen', 500);
}

/**
 * Speichert Änderungen an einem Krankenhaus
 * @action wp_ajax_save_krankenhaus
 */
add_action('wp_ajax_save_krankenhaus', 'lsttraining_ajax_save_krankenhaus');
function lsttraining_ajax_save_krankenhaus() {
    // Nur Admins dürfen speichern
    if (! current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    // Pflichtfelder einlesen und validieren
    $id               = intval( $_POST['id'] ?? 0 );
    $name             = sanitize_text_field( $_POST['name'] ?? '' );
    $versorgungsstufe = sanitize_text_field( $_POST['versorgungsstufe'] ?? '' );
    $trauma_level     = intval( $_POST['trauma_level'] ?? 0 );
    $latitude         = floatval( $_POST['latitude'] ?? 0 );
    $longitude        = floatval( $_POST['longitude'] ?? 0 );
    $departments      = stripslashes( $_POST['departments'] ?? '' ); // JSON-String
    $helipad          = isset( $_POST['helipad'] ) ? 1 : 0;

    if ( $id <= 0 || $name === '' ) {
        wp_send_json_error( 'Ungültige Daten', 400 );
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'UPDATE krankenhaeuser
            SET name             = ?,
                versorgungsstufe = ?,
                trauma_level     = ?,
                latitude         = ?,
                longitude        = ?,
                departments      = ?,
                helipad          = ?
          WHERE id = ?'
    );

    $ok = $stmt->execute([
        $name,
        $versorgungsstufe,
        $trauma_level,
        $latitude,
        $longitude,
        $departments,
        $helipad,
        $id
    ]);

    if ( $ok ) {
        wp_send_json_success();
    } else {
        wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
    }
}

/**
 * Speichert Departments
 * @action wp_ajax_lsttraining_save_departments
 */
add_action('wp_ajax_lsttraining_save_departments', function(){
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Unauthorized',403);
    }
    $hid = intval($_POST['hospital_id'] ?? 0);
    if ( ! $hid ) {
        wp_send_json_error('Keine Krankenhaus-ID',400);
    }

    $depts = $_POST['departments'] ?? [];
    // Sanitize & build JSON
    $out = [];
    foreach ($depts as $code => $data) {
        $out[] = [
            'code'      => sanitize_text_field($code),
            'enabled'   => isset($data['enabled']),
            'latitude'  => floatval($data['latitude']),
            'longitude' => floatval($data['longitude'])
        ];
    }
    $json = wp_json_encode($out);

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare("UPDATE krankenhaeuser SET departments = ? WHERE id = ?");
    $ok = $stmt->execute([$json, $hid]);
    $ok ? wp_send_json_success() : wp_send_json_error('Speichern fehlgeschlagen',500);
});



add_action( 'wp_ajax_get_departments', 'lsttraining_get_departments' );
function lsttraining_get_departments() {
    // 1) Nur für angemeldete Admins
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    // 2) Krankenhaus-ID aus dem Request
    $hid = intval( $_REQUEST['hospital_id'] ?? 0 );
    if ( $hid <= 0 ) {
        wp_send_json_error( 'Ungültige Krankenhaus-ID.', 400 );
    }

    // 3) PDO-Verbindung (wie in hospitals.php)
    require_once plugin_dir_path( __FILE__ ) . 'db.php';
    $pdo = lsttraining_get_connection();

    // 4) Nur die Spalte `departments` plus `latitude`/`longitude` abfragen
    $stmt = $pdo->prepare(
        'SELECT departments, latitude, longitude
         FROM krankenhaeuser
         WHERE id = :hid'
    );
    if ( ! $stmt->execute( [ ':hid' => $hid ] ) ) {
        wp_send_json_error( 'Fehler bei der Abfrage.', 500 );
    }
    $row = $stmt->fetch( PDO::FETCH_ASSOC );
    if ( ! $row ) {
        wp_send_json_error( 'Krankenhaus nicht gefunden.', 404 );
    }

    // 5) `departments` JSON dekodieren
    $existing = json_decode( $row['departments'], true );
    if ( ! is_array( $existing ) ) {
        $existing = [];
    }

    // 6) Erlaubte Fachbereiche (Codes → Labels) definieren
    //    Passe dieses Array an deine tatsächliche Konfiguration an
//JSON einlesen
$json_path    = plugin_dir_path( __FILE__ ) . 'departments.json';
$departments  = json_decode( file_get_contents( $json_path ), true );

// Labels-Array für die alte „allowed“-Semantik
$allowed = array_map(
    function( $info ) { return $info['label']; },
    $departments
);

    // 7) Alles als Objekt zurückgeben
    wp_send_json_success([
        'existing'     => $existing,                  // gespeicherte Departments mit lat/lon/enabled
        'allowed'      => $allowed,                   // Code→Label Liste
        'hospital_lat' => (float) $row['latitude'],   // für Karten-Center
        'hospital_lon' => (float) $row['longitude'],
    ]);
}
