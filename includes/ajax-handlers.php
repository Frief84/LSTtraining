<?php
/**
 * AJAX Handlers for LSTtraining Plugin
 * Handles saving, loading and rendering of operational areas (GeoJSON polygons)
 */

require_once plugin_dir_path(__FILE__) . '/db.php';

// === LEITSTELLEN ===

/**
 * Load the GeoJSON polygon for a given Leitstelle from the database.
 *
 * @action wp_ajax_lsttraining_get_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_get_einsatzgebiet', function () {
    $leitstelle_id = intval($_GET['leitstelle_id'] ?? 0);
    if (!$leitstelle_id) {
        wp_send_json_error('Leitstelle-ID fehlt');
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare("SELECT polygon FROM einsatzgebiete WHERE leitstelle_id = ? LIMIT 1");
    $stmt->execute([$leitstelle_id]);
    $result = $stmt->fetchColumn();

    if ($result) {
        wp_send_json_success(json_decode($result));
    } else {
        wp_send_json_success(null);
    }
});

/**
 * Save or update the GeoJSON polygon for a Leitstelle.
 *
 * @action wp_ajax_lsttraining_save_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_save_einsatzgebiet', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $leitstelle_id = intval($_POST['leitstelle_id'] ?? 0);
    $geojson = $_POST['geojson'] ?? '';

    if ($leitstelle_id <= 0 || empty($geojson)) {
        wp_send_json_error('Invalid data', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare("SELECT id FROM einsatzgebiete WHERE leitstelle_id = ?");
    $stmt->execute([$leitstelle_id]);

    if ($stmt->fetchColumn()) {
        $stmt = $pdo->prepare("UPDATE einsatzgebiete SET polygon = ? WHERE leitstelle_id = ?");
        $stmt->execute([$geojson, $leitstelle_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO einsatzgebiete (leitstelle_id, bezeichnung, polygon) VALUES (?, 'Standard', ?)");
        $stmt->execute([$leitstelle_id, $geojson]);
    }

    wp_send_json_success();
});


// === NEBENLEITSTELLEN ===

/**
 * Load the GeoJSON string from a Nebenleitstelle (JSON column).
 *
 * @action wp_ajax_lsttraining_get_neben_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_get_neben_einsatzgebiet', function () {
    $neben_id = intval($_GET['neben_id'] ?? 0);
    if (!$neben_id) {
        wp_send_json_error('Nebenleitstelle-ID fehlt');
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare("SELECT geojson FROM nebenleistellen WHERE id = ? LIMIT 1");
    $stmt->execute([$neben_id]);
    $result = $stmt->fetchColumn();

    if ($result) {
        wp_send_json_success(json_decode($result));
    } else {
        wp_send_json_success(null);
    }
});

/**
 * Save or update the GeoJSON polygon for a Nebenleitstelle.
 *
 * @action wp_ajax_lsttraining_save_neben_einsatzgebiet
 */
add_action('wp_ajax_lsttraining_save_neben_einsatzgebiet', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $neben_id = intval($_POST['neben_id'] ?? 0);
    $geojson = stripslashes($_POST['geojson'] ?? '');

    if ($neben_id <= 0 || empty($geojson)) {
        wp_send_json_error('Invalid data', 400);
    }

    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare("SELECT id FROM nebenleistellen WHERE id = ?");
    $stmt->execute([$neben_id]);

    if ($stmt->fetchColumn()) {
        $stmt = $pdo->prepare("UPDATE nebenleistellen SET geojson = ? WHERE id = ?");
        $stmt->execute([$geojson, $neben_id]);
    } else {
        wp_send_json_error('Nebenleitstelle not found');
    }

    wp_send_json_success();
});


// === SHARED POPUP ===

/**
 * Render the Einsatzgebiet editor popup HTML dynamically via AJAX.
 * This does not include the GeoJSON data itself, which is loaded separately.
 *
 * @action wp_ajax_lsttraining_render_einsatzgebiet_editor
 */
add_action('wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {
    require_once plugin_dir_path(__FILE__) . '/einsatzgebiet-editor.php';

    $mapId = sanitize_text_field($_GET['map_id'] ?? 'einsatzgebiet_edit');
    $inputId = sanitize_text_field($_GET['input_id'] ?? 'geojson_edit');
    $leitstelleId = intval($_GET['leitstelle_id'] ?? 0);
    $context = sanitize_text_field($_GET['context'] ?? 'leitstelle');
    $center = sanitize_text_field($_GET['center'] ?? '');

    $geojson = ''; // GeoJSON wird clientseitig separat nachgeladen

    ob_start();
    lsttraining_einsatzgebiet_editor($mapId, $inputId, $geojson, $leitstelleId, $context, $center);
    echo ob_get_clean();
    wp_die();
});
