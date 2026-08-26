<?php
if (!defined('ABSPATH')) { exit; }

function lsttraining_fahrzeuge_require_method(string $method): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        wp_send_json_error(['msg' => 'Ungültige HTTP-Methode.'], 405);
    }
}

if (!function_exists('lsttraining_fahrzeuge_column_exists')) {
    function lsttraining_fahrzeuge_column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $st->execute([$column]);
            return ($st && $st->rowCount() > 0);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('lsttraining_fahrzeuge_ensure_signal_lights_column')) {
function lsttraining_fahrzeuge_ensure_signal_lights_column(PDO $pdo): bool {
        return lsttraining_fahrzeuge_column_exists($pdo, 'fahrzeuge', 'signal_lights_json');
    }
}

if (!function_exists('lsttraining_fahrzeuge_normalize_signal_lights_json')) {
    function lsttraining_fahrzeuge_normalize_signal_lights_json($raw): string {
        $decoded = is_array($raw) ? $raw : json_decode((string) wp_unslash($raw), true);
        if (!is_array($decoded)) {
            return '';
        }
        $lights = is_array($decoded['lights'] ?? null) ? $decoded['lights'] : (array_values($decoded) === $decoded ? $decoded : []);
        $normalized = [];
        foreach ($lights as $light) {
            if (!is_array($light)) {
                continue;
            }
            $x = isset($light['x']) ? (float) $light['x'] : null;
            $y = isset($light['y']) ? (float) $light['y'] : null;
            if ($x === null || $y === null || !is_finite($x) || !is_finite($y)) {
                continue;
            }
            $type = sanitize_key((string) ($light['type'] ?? 'beacon'));
            if (!in_array($type, ['beacon', 'strobe', 'bar', 'glow'], true)) {
                $type = 'beacon';
            }
            $normalized[] = [
                'x' => max(0.0, min(1.0, $x)),
                'y' => max(0.0, min(1.0, $y)),
                'type' => $type,
                'interval' => max(120, min(2000, (int) ($light['interval'] ?? 420))),
                'phase' => max(0, min(5000, (int) ($light['phase'] ?? 0))),
                'size' => max(0.4, min(2.5, (float) ($light['size'] ?? 1))),
            ];
        }
        if (!$normalized) {
            return '';
        }
        return (string) wp_json_encode(['version' => 1, 'lights' => $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

/* ===============================
 * Einzelnes Fahrzeug laden
 * =============================== */
add_action('wp_ajax_lsttraining_get_fahrzeug', function () {

    lsttraining_fahrzeuge_require_method('GET');

    if (!is_user_logged_in()) {
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
        if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'fahrzeug', $id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung für dieses Fahrzeug.']]);
        }
        $has_signal_lights = lsttraining_fahrzeuge_ensure_signal_lights_column($pdo);
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
    " . ($has_signal_lights ? "f.signal_lights_json," : "NULL AS signal_lights_json,") . "
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

    lsttraining_fahrzeuge_require_method('GET');

    if (!is_user_logged_in()) {
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
        if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'wache', $wache_id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung für diese Wache.']]);
        }
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

    lsttraining_fahrzeuge_require_method('POST');

    if (!is_user_logged_in()) {
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
    $has_signal_lights = lsttraining_fahrzeuge_ensure_signal_lights_column($pdo);
    $signal_lights = $has_signal_lights
        ? lsttraining_fahrzeuge_normalize_signal_lights_json($_POST['signal_lights_json'] ?? '')
        : '';

    if ($wache_id <= 0 || $rufname === '' || $typ === '') {
        status_header(400);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Wache, Rufname und Fahrzeugtyp sind Pflichtfelder.']]);
    }

    try {
        if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'wache', $wache_id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung für die Zielwache.']]);
        }
        if ($id > 0 && !lsttraining_user_can_object($pdo, 'fahrzeuge', 'fahrzeug', $id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung für das bestehende Fahrzeug.']]);
        }
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
            $sql = "
                UPDATE fahrzeuge
                SET wache_id = ?,
                    rufname = ?,
                    fahrzeugtyp = ?,
                    source_note = ?,
                    fms_status = ?,
                    dienstzeiten = ?,
                    bild_datei = ?";
            $params = [$wache_id, $rufname, $typ, $source, $fms, $dienst, $bild];
            if ($has_signal_lights) {
                $sql .= ', signal_lights_json = ?';
                $params[] = $signal_lights;
            }
            $sql .= ', is_first_responder = ? WHERE id = ?';
            $params[] = $is_fr;
            $params[] = $id;
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } else {
            $columns = 'wache_id, rufname, fahrzeugtyp, source_note, fms_status, dienstzeiten, bild_datei';
            $values = '?, ?, ?, ?, ?, ?, ?';
            $params = [$wache_id, $rufname, $typ, $source, $fms, $dienst, $bild];
            if ($has_signal_lights) {
                $columns .= ', signal_lights_json';
                $values .= ', ?';
                $params[] = $signal_lights;
            }
            $columns .= ', is_first_responder';
            $values .= ', ?';
            $params[] = $is_fr;
            $st = $pdo->prepare("INSERT INTO fahrzeuge ($columns) VALUES ($values)");
            $st->execute($params);
            $id = (int)$pdo->lastInsertId();
        }

        wp_send_json(['success' => true, 'data' => ['id' => $id]]);

    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});
add_action('wp_ajax_lsttraining_delete_fahrzeug', function () {

    lsttraining_fahrzeuge_require_method('POST');

    if (!is_user_logged_in()) {
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
        if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'fahrzeug', $id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung für dieses Fahrzeug.']]);
        }
        $st = $pdo->prepare("DELETE FROM fahrzeuge WHERE id = ?");
        $st->execute([$id]);
        wp_send_json(['success' => true]);
    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});
