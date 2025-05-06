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
 * WACHEN (Liste, Einzeldaten, Speichern)
 * ---------------------------------------------------------------------- */

/**
 * Liste der Wachen für Karte/Tabelle
 * @action wp_ajax_lsttraining_get_wachen
 */
add_action( 'wp_ajax_lsttraining_get_wachen', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }
    $ls  = intval( $_GET['ls_id']  ?? 0 );
    $nls = intval( $_GET['nls_id'] ?? 0 );
    if ( ! $ls && ! $nls ) {
        wp_send_json_error( 'Kein Filter angegeben.', 400 );
    }
    $pdo    = lsttraining_get_connection();
    $sql    = 'SELECT id, name, typ, latitude, longitude FROM wachen WHERE 1=1';
    $params = [];
    if ( $ls )  { $sql .= ' AND leitstelle_id = ?';      $params[] = $ls;  }
    if ( $nls ) { $sql .= ' AND nebenleitstelle_id = ?'; $params[] = $nls; }
    $stmt = $pdo->prepare( $sql );
    $stmt->execute( $params );
    wp_send_json_success( $stmt->fetchAll( PDO::FETCH_ASSOC ) );
});

/**
 * Daten einer einzelnen Wache laden
 * @action wp_ajax_lsttraining_get_wache
 */
add_action( 'wp_ajax_lsttraining_get_wache', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }
    $id = intval( $_GET['wache_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Wache-ID fehlt', 400 );
    }
    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( 'SELECT id, name, typ, latitude, longitude FROM wachen WHERE id = ?' );
    $stmt->execute( [ $id ] );
    $row = $stmt->fetch( PDO::FETCH_ASSOC );
    $row ? wp_send_json_success( $row )
         : wp_send_json_error( 'Nicht gefunden', 404 );
});

/**
 * Speichert Änderungen an einer Wache
 * @action wp_ajax_lsttraining_save_wache
 */
add_action('wp_ajax_lsttraining_save_wache', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    $id        = intval($_POST['id']       ?? 0);
    $name      = sanitize_text_field($_POST['name'] ?? '');
    $typ       = sanitize_text_field($_POST['typ']  ?? '');         // <- holen wir hier
    $latitude  = floatval($_POST['latitude']  ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);

    if ( $id <= 0 ) {
        wp_send_json_error('Ungültige Wache-ID', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        "UPDATE wachen
           SET name      = ?,
               typ       = ?,
               latitude  = ?,
               longitude = ?
         WHERE id = ?"
    );

    $ok = $stmt->execute([
        $name,
        $typ,            // <- typ hier
        $latitude,
        $longitude,
        $id
    ]);

    if ( ! $ok ) {
        wp_send_json_error('Speichern fehlgeschlagen', 500);
    }

    wp_send_json_success();
});


