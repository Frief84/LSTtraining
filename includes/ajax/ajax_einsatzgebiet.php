<?php
// Einsatzgebiet / GeoJSON (Leitstelle)
/* -------------------------------------------------------------------------
 * 1. LEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Leitstelle laden
 * @action wp_ajax_lsttraining_get_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_get_einsatzgebiet', function () {
    $g = lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'ls_param' => 'leitstelle_id',
        'ls_required' => true,
    ]);
    $leitstelle_id = $g['ls_id'];

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
        'area' => 'leitstellen',
        'ls_param' => 'leitstelle_id',
        'ls_required' => true,
    ]);
    $leitstelle_id = $g['ls_id'];

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


// Einsatzgebiet / GeoJSON (Nebenleitstelle)
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

// Gemeinsamer Pop-up Editor
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

