<?php
/**
 * ajax_fahrzeuge.php
 *
 * AJAX: Fahrzeuge einer Wache listen.
 *
 * JS ruft:
 *  action=lsttraining_list_fahrzeuge_by_wache
 *  wache_id=<int>
 *  nonce=<nonce>  (Action: 'lst_fahrzeuge_nonce')
 */
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_lsttraining_list_fahrzeuge_by_wache', function () {

    if (!current_user_can('read')) {
        status_header(403);
        wp_send_json_error(['msg' => 'Keine Berechtigung.']);
    }

    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        status_header(403);
        wp_send_json_error(['msg' => 'Nonce ungültig.']);
    }

    $wache_id = isset($_GET['wache_id']) ? (int) $_GET['wache_id'] : 0;
    if ($wache_id <= 0) {
        status_header(400);
        wp_send_json_error(['msg' => 'wache_id fehlt/ungültig.']);
    }

    $pdo = function_exists('lsttraining_get_connection') ? lsttraining_get_connection() : null;

    if (!$pdo) {
        status_header(500);
        $msg = 'DB-Verbindung fehlgeschlagen.';
        if (current_user_can('manage_options') && !empty($GLOBALS['lsttraining_db_last_error'])) {
            $msg .= ' ' . $GLOBALS['lsttraining_db_last_error'];
        }
        wp_send_json_error(['msg' => $msg]);
    }

    try {
        $sql = "SELECT id, rufname, fahrzeugtyp, fms_status, is_first_responder
                FROM fahrzeuge
                WHERE wache_id = :wid
                ORDER BY rufname, id";

        $st = $pdo->prepare($sql);
        $st->execute([':wid' => $wache_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        wp_send_json_success([
            'count'     => is_array($rows) ? count($rows) : 0,
            'fahrzeuge' => $rows ?: [],
        ]);

    } catch (Throwable $e) {
        status_header(500);
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
});
