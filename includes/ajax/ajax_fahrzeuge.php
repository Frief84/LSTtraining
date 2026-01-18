<?php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_lsttraining_get_fahrzeug', function () {

    if (!current_user_can('read')) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
    }

    $ok = check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false);
    if (!$ok) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Nonce ungültig.']]);
    }

    // akzeptiere id UND fahrzeug_id
    $id = 0;
    if (isset($_GET['fahrzeug_id'])) $id = intval($_GET['fahrzeug_id']);
    if ($id <= 0 && isset($_GET['id'])) $id = intval($_GET['id']);

    if ($id <= 0) {
        status_header(400);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Fahrzeug-ID fehlt.']]);
    }

    if (!function_exists('lsttraining_get_connection')) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => 'DB-Funktion fehlt.']]);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => 'DB-Verbindung fehlgeschlagen.']]);
    }

    try {
        $st = $pdo->prepare("SELECT id, wache_id, rufname, fahrzeugtyp, fms_status, is_first_responder
                             FROM fahrzeuge
                             WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            status_header(404);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Fahrzeug nicht gefunden.']]);
        }

        wp_send_json(['success' => true, 'data' => $row]);

    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});