<?php
/**
 * AJAX-Handler für LSTtraining
 * – speichert, lädt und rendert Einsatzgebiete (GeoJSON-Polygone)
 */

require_once plugin_dir_path( __FILE__ ) . '/db.php';

/* -------------------------------------------------------------------------
 * LEITSTELLEN
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

    $pdo   = lsttraining_get_connection();
    $stmt  = $pdo->prepare( "SELECT geojson FROM leitstellen WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $leitstelle_id ] );
    $geojson = $stmt->fetchColumn();

    wp_send_json_success( $geojson ? json_decode( $geojson, true ) : null );
} );


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
} );


/* -------------------------------------------------------------------------
 * NEBENLEITSTELLEN
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

    $pdo   = lsttraining_get_connection();
    $stmt  = $pdo->prepare( "SELECT geojson FROM nebenleitstellen WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $neben_id ] );
    $geojson = $stmt->fetchColumn();

    wp_send_json_success( $geojson ? json_decode( $geojson, true ) : null );
} );


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

    $pdo   = lsttraining_get_connection();
    $stmt  = $pdo->prepare( "UPDATE nebenleitstellen SET geojson = ? WHERE id = ?" );
    $stmt->execute( [ $geojson, $neben_id ] );

    wp_send_json_success();
} );


/* -------------------------------------------------------------------------
 * POP-UP-EDITOR (gemeinsam)
 * ---------------------------------------------------------------------- */

/**
 * Rendert das HTML-Gerüst für den Einsatzgebiet-Editor
 * @action wp_ajax_lsttraining_render_einsatzgebiet_editor
 */
add_action( 'wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {

    require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

    $mapId        = sanitize_text_field( $_GET['map_id']        ?? 'einsatzgebiet_edit' );
    $inputId      = sanitize_text_field( $_GET['input_id']      ?? 'geojson_edit' );
    $leitstelleId = intval( $_GET['leitstelle_id'] ?? 0 );
    $context      = sanitize_text_field( $_GET['context']       ?? 'leitstelle' );
    $center       = sanitize_text_field( $_GET['center']        ?? '' );

    $geojson = ''; // GeoJSON wird clientseitig via JS nachgeladen

    ob_start();
    lsttraining_einsatzgebiet_editor( $mapId, $inputId, $geojson, $leitstelleId, $context, $center );
    echo ob_get_clean();
    wp_die();
} );
?>
