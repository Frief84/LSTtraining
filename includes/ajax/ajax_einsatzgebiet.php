<?php
/**
 * ajax_einsatzgebiet.php
 *
 * AJAX-Endpunkte für Einsatzgebiet/GeoJSON.
 */

if (!defined('ABSPATH')) { exit; }


/* -------------------------------------------------------------------------
 * 1) LEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Leitstelle laden
 * @action wp_ajax_lsttraining_get_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_get_einsatzgebiet', function () {

    $g = lsttraining_ajax_guard([
        'area'        => 'leitstellen',
        'ls_param'    => 'leitstelle_id',
        'ls_required' => true,
    ]);
    $leitstelle_id = (int)$g['ls_id'];

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

    $g = lsttraining_ajax_guard([
        'area'        => 'leitstellen',
        'ls_param'    => 'leitstelle_id',
        'ls_required' => true,
    ]);
    $leitstelle_id = (int)$g['ls_id'];

    $geojson = wp_unslash($_POST['geojson'] ?? '');
    if ($geojson === '') {
        wp_send_json_error('Invalid data', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare('UPDATE leitstellen SET geojson = ? WHERE id = ?');
    $stmt->execute([$geojson, $leitstelle_id]);

    // Log: GeoJSON der Leitstelle aktualisiert
    if (function_exists('lsttraining_log_activity')) {
        lsttraining_log_activity([
            'entity_type' => 'leitstelle',
            'action'      => 'update',
            'entity_id'   => (int)$leitstelle_id,
            'meta'        => ['field' => 'geojson', 'page' => 'ajax:save_einsatzgebiet'],
        ]);
    }

    wp_send_json_success();
});



/* -------------------------------------------------------------------------
 * 2) NEBENLEITSTELLEN (GeoJSON-Endpunkte)
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

    if (function_exists('lsttraining_log_activity')) {
        lsttraining_log_activity([
            'entity_type' => 'nebenstelle',
            'action'      => 'update',
            'entity_id'   => (int)$neben_id,
            'meta'        => ['field' => 'geojson', 'page' => 'ajax:save_neben_einsatzgebiet'],
        ]);
    }

    wp_send_json_success();
});



/* -------------------------------------------------------------------------
 * 3) POP-UP-EDITOR RENDER
 * ---------------------------------------------------------------------- */

/**
 * Pop-up Editor HTML rendern
 * @action wp_ajax_lsttraining_render_einsatzgebiet_editor
 *
 * Der Kontext bestimmt die Berechtigung und den Speicher-Endpunkt im Client.
 */
add_action('wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {

    $context = sanitize_text_field($_GET['context'] ?? 'leitstelle');
    if (!in_array($context, ['leitstelle', 'neben'], true)) {
        wp_send_json_error('Ungültiger Editor-Kontext', 400);
    }

    $area = ($context === 'neben') ? 'nebenstellen' : 'leitstellen';
    if (!lsttraining_user_can($area)) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    // Datei liegt in /includes/einsatzgebiet-editor.php
    require_once plugin_dir_path(__FILE__) . '/../einsatzgebiet-editor.php';

    $mapId        = sanitize_text_field($_GET['map_id'] ?? 'einsatzgebiet_edit');
    $inputId      = sanitize_text_field($_GET['input_id'] ?? 'geojson_edit');
    $entityId      = (int)($_GET['leitstelle_id'] ?? 0);
    $center       = sanitize_text_field($_GET['center'] ?? '');
    $geojson      = '';

    ob_start();
    lsttraining_einsatzgebiet_editor($mapId, $inputId, $geojson, $entityId, $context, $center);
    echo ob_get_clean();
    wp_die();
});
