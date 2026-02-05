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
  SELECT
    f.id,
    f.wache_id,
    TRIM(f.rufname) AS rufname,
    f.fahrzeugtyp,
    f.source_note,
    f.fms_status,
    f.dienstzeiten,
    f.bild_datei,
    f.is_first_responder,

    w.name AS wache_name,
    w.land AS wache_land,
    w.bundesland AS wache_bundesland
  FROM fahrzeuge f
  JOIN wachen w ON w.id = f.wache_id
  WHERE f.id = ?
  LIMIT 1
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
           SELECT id, wache_id, TRIM(rufname) AS rufname, fahrzeugtyp, fms_status, is_first_responder
FROM fahrzeuge
WHERE wache_id = ?
ORDER BY TRIM(rufname) ASC
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

/* ===============================
 * Fahrzeug speichern (Insert/Update)
 * =============================== */
add_action('wp_ajax_lsttraining_save_fahrzeug', function () {

    if (!current_user_can('read')) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
    }

    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Nonce ungültig.']]);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => 'DB-Verbindung fehlgeschlagen.']]);
    }

    $id        = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $wache_id  = isset($_POST['wache_id']) ? (int)$_POST['wache_id'] : 0;
    $rufname   = isset($_POST['rufname']) ? trim((string)$_POST['rufname']) : '';
    $typ       = isset($_POST['fahrzeugtyp']) ? trim((string)$_POST['fahrzeugtyp']) : '';
    $source    = isset($_POST['source_note']) ? trim((string)$_POST['source_note']) : null;
    $fms       = isset($_POST['fms_status']) ? (string)$_POST['fms_status'] : '2';
    $dienst    = isset($_POST['dienstzeiten']) ? trim((string)$_POST['dienstzeiten']) : null;
    $bild      = isset($_POST['bild_datei']) ? trim((string)$_POST['bild_datei']) : null;
    $is_fr     = !empty($_POST['is_first_responder']) ? 1 : 0;

    if ($wache_id <= 0 || $rufname === '' || $typ === '') {
        status_header(400);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Wache, Rufname und Fahrzeugtyp sind Pflichtfelder.']]);
    }

    try {
        // Duplicate-Check: beim Update eigenes Fahrzeug ausnehmen
        if ($id > 0) {
            $st = $pdo->prepare("
                SELECT id
                FROM fahrzeuge
                WHERE wache_id = ?
                  AND TRIM(rufname) = ?
                  AND id <> ?
                LIMIT 1
            ");
            $st->execute([$wache_id, $rufname, $id]);
        } else {
            $st = $pdo->prepare("
                SELECT id
                FROM fahrzeuge
                WHERE wache_id = ?
                  AND TRIM(rufname) = ?
                LIMIT 1
            ");
            $st->execute([$wache_id, $rufname]);
        }

        if ($st->fetch(PDO::FETCH_ASSOC)) {
            status_header(409);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Rufname ist in dieser Wache bereits vergeben.']]);
        }

        if ($id > 0) {
            $st = $pdo->prepare("
                UPDATE fahrzeuge
                SET wache_id = ?,
                    rufname = ?,
                    fahrzeugtyp = ?,
                    source_note = ?,
                    fms_status = ?,
                    dienstzeiten = ?,
                    bild_datei = ?,
                    is_first_responder = ?
                WHERE id = ?
            ");
            $st->execute([$wache_id, $rufname, $typ, $source, $fms, $dienst, $bild, $is_fr, $id]);
        } else {
            $st = $pdo->prepare("
                INSERT INTO fahrzeuge
                    (wache_id, rufname, fahrzeugtyp, source_note, fms_status, dienstzeiten, bild_datei, is_first_responder)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([$wache_id, $rufname, $typ, $source, $fms, $dienst, $bild, $is_fr]);
            $id = (int)$pdo->lastInsertId();
        }

        wp_send_json(['success' => true, 'data' => ['id' => $id]]);

    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});
add_action('wp_ajax_lsttraining_delete_fahrzeug', function () {

    if (!current_user_can('read')) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
    }

    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Nonce ungültig.']]);
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
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
        $st = $pdo->prepare("DELETE FROM fahrzeuge WHERE id = ?");
        $st->execute([$id]);
        wp_send_json(['success' => true]);
    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});

