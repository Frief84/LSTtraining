<?php
// ajax-handlers.php – zentrale Sammlung von AJAX-Actions für LSTtraining

add_action('wp_ajax_lsttraining_get_einsatzgebiet', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $leitstelle_id = intval($_GET['leitstelle_id'] ?? 0);
    if ($leitstelle_id <= 0) {
        wp_send_json_error('Invalid ID', 400);
    }

    require_once plugin_dir_path(__FILE__) . '/db.php';
    $pdo = lsttraining_get_connection();

    $stmt = $pdo->prepare("SELECT polygon FROM einsatzgebiete WHERE leitstelle_id = ?");
    $stmt->execute([$leitstelle_id]);

    $result = $stmt->fetchColumn();

    if ($result) {
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            wp_send_json_success($decoded);
        } else {
            wp_send_json_error('Ungültiges GeoJSON in DB', 400);
        }
    } else {
        wp_send_json_success([]); // leeres Polygon
    }
});

add_action('wp_ajax_lsttraining_save_einsatzgebiet', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $leitstelle_id = intval($_POST['leitstelle_id'] ?? 0);
    $geojson = stripslashes($_POST['geojson'] ?? '');

    if ($leitstelle_id <= 0 || empty($geojson)) {
        wp_send_json_error('Ungültige Daten', 400);
    }

    require_once plugin_dir_path(__FILE__) . '/db.php';
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
