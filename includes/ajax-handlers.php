<?php
/**
 * AJAX-Handler für das LST-Training-Plugin
 * – sämtliche Rechteprüfungen laufen über lsttraining_user_can()
 *   (siehe includes/permissions.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direktzugriff verhindern
}

require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/permissions.php';


/* -------------------------------------------------------------------------
 * 1. LEITSTELLEN (GeoJSON-Editor)
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
    if ( ! lsttraining_user_can( 'leitstellen', $leitstelle_id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
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

    $leitstelle_id = intval( $_POST['leitstelle_id'] ?? 0 );
    if ( ! lsttraining_user_can( 'leitstellen', $leitstelle_id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $geojson = stripslashes( $_POST['geojson'] ?? '' );
    if ( $leitstelle_id <= 0 || empty( $geojson ) ) {
        wp_send_json_error( 'Invalid data', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "UPDATE leitstellen SET geojson = ? WHERE id = ?" );
    $stmt->execute( [ $geojson, $leitstelle_id ] );

    wp_send_json_success();
});


/* -------------------------------------------------------------------------
 * 2. NEBENLEITSTELLEN (GeoJSON-Editor)
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
    if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
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

    if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
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
 * 3. POP-UP-EDITOR (gemeinsamer Render-Endpunkt)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {

    require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

    $mapId        = sanitize_text_field( $_GET['map_id']        ?? 'einsatzgebiet_edit' );
    $inputId      = sanitize_text_field( $_GET['input_id']      ?? 'geojson_edit' );
    $leitstelleId = intval( $_GET['leitstelle_id'] ?? 0 );
    $context      = sanitize_text_field( $_GET['context']       ?? 'leitstelle' );
    $center       = sanitize_text_field( $_GET['center']        ?? '' );

    $geojson = '';

    ob_start();
    lsttraining_einsatzgebiet_editor(
        $mapId,
        $inputId,
        $geojson,
        $leitstelleId,
        $context,
        $center
    );
    echo ob_get_clean();
    wp_die();
});


/* -------------------------------------------------------------------------
 * 4. WACHEN (Liste, Details, CRUD)
 * ---------------------------------------------------------------------- */

/**
 * Liste der Wachen für Karte/Tabelle
 * @action wp_ajax_lsttraining_get_wachen
 */
add_action( 'wp_ajax_lsttraining_get_wachen', function () {

    if ( ! lsttraining_user_can( 'wachen' ) ) {
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
                 FROM wachen WHERE 1=1';
    $params = [];

    if ( $ls  ) { $sql .= ' AND leitstelle_id      = ?'; $params[] = $ls;  }
    if ( $nls ) { $sql .= ' AND nebenleitstelle_id = ?'; $params[] = $nls; }

    $stmt = $pdo->prepare( $sql );
    $stmt->execute( $params );

    wp_send_json_success( $stmt->fetchAll( PDO::FETCH_ASSOC ) );
});


/**
 * Daten einer einzelnen Wache laden
 * @action wp_ajax_lsttraining_get_wache
 */
add_action( 'wp_ajax_lsttraining_get_wache', function () {

    if ( ! lsttraining_user_can( 'wachen' ) ) {
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
});


/**
 * Speichert Änderungen an einer Wache
 * @action wp_ajax_lsttraining_save_wache
 */
add_action( 'wp_ajax_lsttraining_save_wache', function () {

    if ( ! lsttraining_user_can( 'wachen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id        = intval( $_POST['id'] ?? 0 );
    $name      = sanitize_text_field( $_POST['name'] ?? '' );
    $typ       = sanitize_text_field( $_POST['typ']  ?? '' );
    $latitude  = floatval( $_POST['latitude']  ?? 0 );
    $longitude = floatval( $_POST['longitude'] ?? 0 );
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
});


/**
 * Löscht eine Wache
 * @action wp_ajax_lsttraining_delete_wache
 */
add_action( 'wp_ajax_lsttraining_delete_wache', function () {

    if ( ! lsttraining_user_can( 'wachen' ) ) {
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
});


/**
 * Legt eine neue Wache an
 * @action wp_ajax_lsttraining_create_wache
 */
add_action( 'wp_ajax_lsttraining_create_wache', function () {

    if ( ! lsttraining_user_can( 'wachen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $name       = sanitize_text_field( $_POST['name'] ?? '' );
    $typ        = sanitize_text_field( $_POST['typ']  ?? '' );
    $lat        = floatval( $_POST['latitude']  ?? 0 );
    $lon        = floatval( $_POST['longitude'] ?? 0 );
    $arrival    = sanitize_text_field( $_POST['arrival_pos']   ?? '' );
    $departure  = sanitize_text_field( $_POST['departure_pos'] ?? '' );
    $ls_id      = intval( $_POST['leitstelle_id'] ?? 0 );

    if ( $lat === 0 || $lon === 0 || $name === '' ) {
        wp_send_json_error( 'Pflichtfelder fehlen', 400 );
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'INSERT INTO wachen
             (leitstelle_id, name, typ, latitude, longitude, arrival_pos, departure_pos)
         VALUES (?,?,?,?,?,?,?)'
    );
    $ok = $stmt->execute( [
        $ls_id, $name, $typ, $lat, $lon, $arrival, $departure
    ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Anlegen fehlgeschlagen', 500 );
});


/* -------------------------------------------------------------------------
 * 5. KRANKENHÄUSER (Liste, Details, CRUD, Departments)
 * ---------------------------------------------------------------------- */

/**
 * Liste aller Krankenhäuser
 * @action wp_ajax_get_krankenhaeuser
 */
add_action( 'wp_ajax_get_krankenhaeuser', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'SELECT id, name, versorgungsstufe, trauma_level, latitude, longitude
           FROM krankenhaeuser
          ORDER BY name'
    );
    $stmt->execute();

    wp_send_json( $stmt->fetchAll( PDO::FETCH_ASSOC ) );
});


/**
 * Einzelnes Krankenhaus lesen (read-only)
 *  – nopriv-Hook bleibt erhalten
 */
add_action( 'wp_ajax_get_krankenhaus',        'lsttraining_ajax_get_krankenhaus' );
add_action( 'wp_ajax_nopriv_get_krankenhaus', 'lsttraining_ajax_get_krankenhaus' );
function lsttraining_ajax_get_krankenhaus() {

    header( 'Content-Type: application/json; charset=utf-8' );

    $id = intval( $_GET['id'] ?? 0 );
    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige Krankenhaus-ID', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'SELECT id, name, versorgungsstufe, trauma_level,
                latitude, longitude, departments, helipad
           FROM krankenhaeuser
          WHERE id = ?'
    );
    $stmt->execute( [ $id ] );
    $row = $stmt->fetch( PDO::FETCH_ASSOC );

    $row ? wp_send_json_success( $row )
         : wp_send_json_error   ( 'Krankenhaus nicht gefunden', 404 );

    wp_die();
}


/**
 * Ein Krankenhaus löschen
 * @action wp_ajax_delete_krankenhaus
 */
add_action( 'wp_ajax_delete_krankenhaus', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id = intval( $_POST['id'] ?? 0 );
    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige ID', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( 'DELETE FROM krankenhaeuser WHERE id = ?' );
    $ok   = $stmt->execute( [ $id ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Löschen fehlgeschlagen', 500 );
});


/**
 * Krankenhaus speichern
 * @action wp_ajax_save_krankenhaus
 */
add_action( 'wp_ajax_save_krankenhaus', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id               = intval( $_POST['id'] ?? 0 );
    $name             = sanitize_text_field( $_POST['name'] ?? '' );
    $versorgungsstufe = sanitize_text_field( $_POST['versorgungsstufe'] ?? '' );
    $trauma_level     = intval( $_POST['trauma_level'] ?? 0 );
    $latitude         = floatval( $_POST['latitude'] ?? 0 );
    $longitude        = floatval( $_POST['longitude'] ?? 0 );
    $departments      = stripslashes( $_POST['departments'] ?? '' );
    $helipad          = isset( $_POST['helipad'] ) ? 1 : 0;

    if ( $id <= 0 || $name === '' ) {
        wp_send_json_error( 'Ungültige Daten', 400 );
    }

    $pdo  = lsttraining_get_connection();
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

    $ok = $stmt->execute( [
        $name,
        $versorgungsstufe,
        $trauma_level,
        $latitude,
        $longitude,
        $departments,
        $helipad,
        $id
    ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
});


/**
 * Departments speichern
 * @action wp_ajax_lsttraining_save_departments
 */
add_action( 'wp_ajax_lsttraining_save_departments', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $hid = intval( $_POST['hospital_id'] ?? 0 );
    if ( ! $hid ) {
        wp_send_json_error( 'Keine Krankenhaus-ID', 400 );
    }

    $depts = $_POST['departments'] ?? [];
    $out   = [];

    foreach ( $depts as $code => $data ) {
        $out[] = [
            'code'      => sanitize_text_field( $code ),
            'enabled'   => isset( $data['enabled'] ),
            'latitude'  => floatval( $data['latitude'] ),
            'longitude' => floatval( $data['longitude'] )
        ];
    }
    $json = wp_json_encode( $out );

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'UPDATE krankenhaeuser
            SET departments = ?
          WHERE id = ?'
    );
    $ok = $stmt->execute( [ $json, $hid ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
});


/**
 * Departments abrufen
 * @action wp_ajax_get_departments
 */
add_action( 'wp_ajax_get_departments', function () {

    $hid = intval( $_REQUEST['hospital_id'] ?? 0 );
    if ( $hid <= 0 ) {
        wp_send_json_error( 'Ungültige Krankenhaus-ID.', 400 );
    }
    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    $pdo = lsttraining_get_connection();
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

    $existing = json_decode( $row['departments'], true );
    if ( ! is_array( $existing ) ) {
        $existing = [];
    }

    $json_path   = plugin_dir_path( __FILE__ ) . 'departments.json';
    $departments = json_decode( file_get_contents( $json_path ), true );

    $allowed = array_map(
        function ( $d ) { return $d['label']; },
        $departments
    );

    wp_send_json_success( [
        'existing'     => $existing,
        'allowed'      => $allowed,
        'hospital_lat' => (float) $row['latitude'],
        'hospital_lon' => (float) $row['longitude'],
    ] );
});


/* -------------------------------------------------------------------------
 * 6. LEITSTELLE ↔ HOSPITAL ZUORDNUNG
 * ---------------------------------------------------------------------- */

/**
 * Liefert Hospitals je Leitstelle
 * @action wp_ajax_get_leitstelle_hospitals
 */
add_action( 'wp_ajax_get_leitstelle_hospitals', function () {

    $id = intval( $_GET['leitstelle_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Ungültige Leitstelle' );
    }
    if ( ! lsttraining_user_can( 'leitstellen', $id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $pdo = lsttraining_get_connection();

    try {
        // Leitstelle
        $stmt = $pdo->prepare(
            'SELECT available_hospitals,
                    latitude   AS leitstelle_lat,
                    longitude  AS leitstelle_lon,
                    geojson
               FROM leitstellen
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute( [ ':id' => $id ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! $row ) {
            wp_send_json_error( 'Leitstelle nicht gefunden' );
        }

        $existing = json_decode( $row['available_hospitals'], true ) ?: [];

        // Fallback: alle KH im Polygon
        if ( empty( $existing ) ) {
            $stmt2 = $pdo->prepare(
                'SELECT id
                   FROM krankenhaeuser
                  WHERE ST_Contains(
                          ST_GeomFromText( ST_AsText( ST_GeomFromGeoJSON( :geojson ) ) ),
                          ST_GeomFromText( CONCAT( "POINT(", longitude, " ", latitude, ")" ) )
                        )'
            );
            $stmt2->execute( [ ':geojson' => $row['geojson'] ] );
            $existing = $stmt2->fetchAll( PDO::FETCH_COLUMN );
        }

        // alle KH für Liste
        $stmt3 = $pdo->query(
            'SELECT id, name, latitude, longitude
               FROM krankenhaeuser
              ORDER BY name'
        );
        $hospitals = $stmt3->fetchAll( PDO::FETCH_ASSOC );

        wp_send_json_success( [
            'leitstelle_id'  => $id,
            'existing'       => $existing,
            'hospitals'      => $hospitals,
            'leitstelle_lat' => (float) $row['leitstelle_lat'],
            'leitstelle_lon' => (float) $row['leitstelle_lon'],
            'geojson'        => $row['geojson'],
        ] );

    } catch ( PDOException $e ) {
        wp_send_json_error( 'Datenbankfehler: ' . $e->getMessage() );
    }
});


/**
 * Speichert Hospitals je Leitstelle
 * @action wp_ajax_save_leitstelle_hospitals
 */
add_action( 'wp_ajax_save_leitstelle_hospitals', function () {

    $id = intval( $_POST['leitstelle_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Ungültige Leitstelle' );
    }
    if ( ! lsttraining_user_can( 'leitstellen', $id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $items = isset( $_POST['hospitals'] )
        ? json_decode( wp_unslash( $_POST['hospitals'] ), true )
        : [];

    if ( ! is_array( $items ) ) {
        wp_send_json_error( 'Ungültige Daten' );
    }

    $json = wp_json_encode( array_map( 'intval', $items ) );

    $pdo = lsttraining_get_connection();
    try {
        $stmt = $pdo->prepare(
            'UPDATE leitstellen
                SET available_hospitals = :json
              WHERE id = :id'
        );
        $stmt->execute( [
            ':json' => $json,
            ':id'   => $id,
        ] );
        wp_send_json_success();
    } catch ( PDOException $e ) {
        wp_send_json_error( 'Speicherfehler: ' . $e->getMessage() );
    }
});


/* -------------------------------------------------------------------------
 * 7. BENUTZER-RECHTE (nur Admins – global)
 * ---------------------------------------------------------------------- */

/**
 * Alle Rechte abrufen
 * @action wp_ajax_lsttraining_get_user_permissions
 */
add_action( 'wp_ajax_lsttraining_get_user_permissions', function () {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) {
        wp_send_json_error( 'Datenbankverbindung fehlgeschlagen.', 500 );
    }

    $wp_users = get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] );
    $user_ids = wp_list_pluck( $wp_users, 'ID' );
    if ( empty( $user_ids ) ) {
        wp_send_json_success( [] );
    }

    $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '?' ) );
    $sql = "SELECT user_id,
                   can_edit_leitstellen,
                   can_edit_nebenstellen,
                   can_edit_hospitals,
                   can_edit_wachen,
                   can_edit_fahrzeuge,
                   leistellen_ids
              FROM user_permissions
             WHERE user_id IN ($placeholders)";

    $stmt = $pdo->prepare( $sql );
    $stmt->execute( $user_ids );
    $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

    $perms_by_user = [];
    foreach ( $rows as $r ) {
        $perms_by_user[ (int) $r['user_id'] ] = [
            'leitstellen'    => (int) $r['can_edit_leitstellen'],
            'nebenstellen'   => (int) $r['can_edit_nebenstellen'],
            'hospitals'      => (int) $r['can_edit_hospitals'],
            'wachen'         => (int) $r['can_edit_wachen'],
            'fahrzeuge'      => (int) $r['can_edit_fahrzeuge'],
            'leistellen_ids' => $r['leistellen_ids'],
        ];
    }

    $result = [];
    foreach ( $wp_users as $u ) {
        $uid = (int) $u->ID;
        $result[] = [
            'ID'           => $uid,
            'user_login'   => $u->user_login,
            'display_name' => $u->display_name ?: $u->user_login,
            'permissions'  => $perms_by_user[ $uid ] ?? [
                'leitstellen'    => 0,
                'nebenstellen'   => 0,
                'hospitals'      => 0,
                'wachen'         => 0,
                'fahrzeuge'      => 0,
                'leistellen_ids' => '',
            ],
        ];
    }

    wp_send_json_success( $result );
});


/**
 * Rechte speichern / updaten
 * @action wp_ajax_lsttraining_save_user_permissions
 */
add_action( 'wp_ajax_lsttraining_save_user_permissions', function () {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    $json = wp_unslash( $_POST['user_permissions'] ?? '' );
    if ( empty( $json ) ) {
        wp_send_json_error( 'Keine Daten übermittelt.', 400 );
    }

    $data = json_decode( $json, true );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( 'Ungültiges JSON-Format.', 400 );
    }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) {
        wp_send_json_error( 'Datenbankverbindung fehlgeschlagen.', 500 );
    }

    $stmtCheck  = $pdo->prepare( "SELECT user_id FROM user_permissions WHERE user_id = ?" );
    $stmtInsert = $pdo->prepare(
        'INSERT INTO user_permissions (
            user_id,
            can_edit_leitstellen,
            can_edit_nebenstellen,
            can_edit_hospitals,
            can_edit_wachen,
            can_edit_fahrzeuge,
            leistellen_ids
        ) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtUpdate = $pdo->prepare(
        'UPDATE user_permissions
            SET can_edit_leitstellen  = ?,
                can_edit_nebenstellen = ?,
                can_edit_hospitals    = ?,
                can_edit_wachen       = ?,
                can_edit_fahrzeuge    = ?,
                leistellen_ids        = ?

          WHERE user_id = ?'
    );

    try {
        $pdo->beginTransaction();

        foreach ( $data as $entry ) {
            $user_id          = intval( $entry['user_id'] );
            $can_leitstellen  = isset( $entry['can_edit_leitstellen'] ) ? 1 : 0;
            $can_nebenstellen = isset( $entry['can_edit_nebenstellen'] ) ? 1 : 0;
            $can_hospitals    = isset( $entry['can_edit_hospitals'] ) ? 1 : 0;
            $can_wachen       = isset( $entry['can_edit_wachen'] ) ? 1 : 0;
            $can_fahrzeuge    = isset( $entry['can_edit_fahrzeuge'] ) ? 1 : 0;

            $ids_raw = sanitize_text_field( $entry['leistellen_ids'] ?? '' );
            $ids_arr = array_filter(
                array_map( 'trim', explode( ',', $ids_raw ) ),
                static fn( $v ) => $v !== '' && ctype_digit( $v )
            );
            $leistellen_ids = implode( ',', $ids_arr );

            $stmtCheck->execute( [ $user_id ] );
            $exists = (bool) $stmtCheck->fetchColumn();

            if ( $exists ) {
                $stmtUpdate->execute( [
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leistellen_ids,
                    $user_id
                ] );
            } else {
                $stmtInsert->execute( [
                    $user_id,
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leistellen_ids
                ] );
            }
        }

        $pdo->commit();
        wp_send_json_success();
    } catch ( PDOException $e ) {
        $pdo->rollBack();
        wp_send_json_error( 'Datenbank-Fehler: ' . $e->getMessage(), 500 );
    }
});
