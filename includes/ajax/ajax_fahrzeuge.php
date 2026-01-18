<?php
if (!defined('ABSPATH')) { exit; }

/* ===============================
 * Einzelnes Fahrzeug laden
 * =============================== */
add_action('wp_ajax_lsttraining_get_fahrzeug', function () {

    if (!current_user_can('read')) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
    }

    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Nonce ungültig.']]);
    }

    $id = 0;
    if (isset($_GET['fahrzeug_id'])) $id = intval($_GET['fahrzeug_id']);
    if ($id <= 0 && isset($_GET['id'])) $id = intval($_GET['id']);

    if ($id <= 0) {
        status_header(400);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Fahrzeug-ID fehlt.']]);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => 'DB-Verbindung fehlgeschlagen.']]);
    }

    try {
        $st = $pdo->prepare("
            SELECT id, wache_id, rufname, fahrzeugtyp, fms_status, is_first_responder
            FROM fahrzeuge
            WHERE id = ?
        ");
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


/* ===============================
 * Fahrzeuge einer Wache laden
 * =============================== */
add_action('wp_ajax_lsttraining_list_fahrzeuge_by_wache', function () {

    if (!current_user_can('read')) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
    }

    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Nonce ungültig.']]);
    }

    $wache_id = isset($_GET['wache_id']) ? intval($_GET['wache_id']) : 0;
    if ($wache_id <= 0) {
        status_header(400);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Wache-ID fehlt.']]);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => 'DB-Verbindung fehlgeschlagen.']]);
    }

    try {
        $st = $pdo->prepare("
            SELECT id, wache_id, rufname, fahrzeugtyp, fms_status, is_first_responder
            FROM fahrzeuge
            WHERE wache_id = ?
            ORDER BY rufname ASC
        ");
        $st->execute([$wache_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        wp_send_json([
            'success' => true,
            'data' => [
                'count' => count($rows),
                'fahrzeuge' => $rows
            ]
        ]);

    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});
