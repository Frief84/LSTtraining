<?php
// Fahrzeuge
/* -------------------------------------------------------------------------
 * 10. FAHRZEUGE (GET/SAVE/DELETE/UPLOAD/SUCHE)
 * ---------------------------------------------------------------------- */

// --- GET: ein Fahrzeug laden ----------------------------------------------
add_action('wp_ajax_lsttraining_get_fahrzeug', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'fahrzeuge',
        'nonce_action' => 'lst_fahrzeuge_nonce',
        'nonce_field' => 'nonce',
    ]);

$pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error(['code' => 'bad_request', 'msg' => 'ID fehlt'], 400);
    }

    $has_bl = false;
    try {
        $stc = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'bundesland'");
        $has_bl = $stc && $stc->rowCount() > 0;
    } catch (Throwable $e) {}

    try {
        $sql = "SELECT
                    f.id, f.wache_id, f.rufname, f.fahrzeugtyp, f.source_note, f.is_first_responder,
                    f.status, f.fms_status, f.dienstzeiten, f.bild_datei,
                    w.name AS wache_name" . ($has_bl ? ", w.bundesland AS wache_bundesland" : "") . "
                FROM fahrzeuge f
                JOIN wachen w ON w.id = f.wache_id
                WHERE f.id = ?";
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            wp_send_json_error(['code' => 'not_found', 'msg' => 'Nicht gefunden'], 404);
        }
        $row['is_first_responder'] = (int)!empty($row['is_first_responder']);
        wp_send_json_success(['fahrzeug' => $row]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Lesefehler: ' . $e->getMessage()], 500);
    }
});

// --- SAVE: Fahrzeug neu anlegen / aktualisieren ----------------------------
add_action('wp_ajax_lsttraining_save_fahrzeug', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'fahrzeuge',
        'nonce_action' => 'lst_fahrzeuge_nonce',
        'nonce_field' => 'nonce',
    ]);

$pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id           = (int)($_POST['id'] ?? 0);
    $wache_id     = (int)($_POST['wache_id'] ?? 0);
    $rufname      = sanitize_text_field($_POST['rufname'] ?? '');
    $fahrzeugtyp  = sanitize_text_field($_POST['fahrzeugtyp'] ?? '');
    $source_note  = sanitize_text_field($_POST['source_note'] ?? '');
    $is_fr        = isset($_POST['is_first_responder']) ? (int)!!$_POST['is_first_responder'] : 0;
    $fms_status   = sanitize_text_field($_POST['fms_status'] ?? '2'); // nur '2' oder '6'
    $dienstzeiten = sanitize_text_field($_POST['dienstzeiten'] ?? '');
    $bild_datei   = sanitize_text_field($_POST['bild_datei'] ?? '');

    if ($wache_id <= 0 || $rufname === '' || $fahrzeugtyp === '') {
        wp_send_json_error(['code' => 'validation', 'msg' => 'Wache, Rufname und Fahrzeugtyp sind Pflichtfelder'], 400);
    }
    if (!in_array($fms_status, ['2', '6'], true)) {
        wp_send_json_error(['code' => 'validation', 'msg' => 'Ungültiger FMS-Status (nur 2 oder 6 erlaubt)'], 400);
    }

    $status = ($fms_status === '6') ? 'nicht einsatzbereit' : 'einsatzbereit';

    // Eindeutigkeitsprüfung (wache_id, rufname)
    try {
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM fahrzeuge WHERE wache_id = ? AND rufname = ? AND id <> ?');
            $chk->execute([$wache_id, $rufname, $id]);
        } else {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM fahrzeuge WHERE wache_id = ? AND rufname = ?');
            $chk->execute([$wache_id, $rufname]);
        }
        if ((int)$chk->fetchColumn() > 0) {
            wp_send_json_error(['code' => 'conflict', 'msg' => 'Rufname in dieser Wache bereits vergeben'], 409);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Eindeutigkeitsprüfung fehlgeschlagen: ' . $e->getMessage()], 500);
    }

    try {
        if ($id > 0) {
            $pdo->beginTransaction();

            $stOld = $pdo->prepare('
                SELECT wache_id, rufname, fahrzeugtyp, source_note, is_first_responder,
                       status, fms_status, dienstzeiten, bild_datei
                  FROM fahrzeuge
                 WHERE id = ?
            ');
            $stOld->execute([$id]);
            $old = $stOld->fetch(PDO::FETCH_ASSOC) ?: [];

            $u = $pdo->prepare('
                UPDATE fahrzeuge
                   SET wache_id = ?, rufname = ?, fahrzeugtyp = ?, source_note = ?, is_first_responder = ?,
                       status = ?, fms_status = ?, dienstzeiten = ?, bild_datei = ?
                 WHERE id = ?
            ');
            $u->execute([
                $wache_id, $rufname, $fahrzeugtyp, $source_note, $is_fr,
                $status, $fms_status, $dienstzeiten, $bild_datei,
                $id,
            ]);

            $pdo->commit();

            $new = [
                'wache_id'           => $wache_id,
                'rufname'            => $rufname,
                'fahrzeugtyp'        => $fahrzeugtyp,
                'source_note'        => $source_note,
                'is_first_responder' => $is_fr,
                'status'             => $status,
                'fms_status'         => $fms_status,
                'dienstzeiten'       => $dienstzeiten,
                'bild_datei'         => $bild_datei,
            ];
            $norm = static function ($v) {
                if ($v === null) return null;
                if (is_bool($v)) return $v ? 1 : 0;
                if (is_numeric($v)) return (string)+$v;
                return trim((string)$v);
            };
            $changes = [];
            foreach ($new as $k => $nv) {
                $ov = array_key_exists($k, $old) ? $old[$k] : null;
                if ($norm($ov) !== $norm($nv)) {
                    $changes[$k] = ['old' => $ov, 'new' => $nv];
                }
            }

            if ($changes) {
                lsttraining_log_activity([
                    'entity_type' => 'fahrzeug',
                    'action'      => 'update',
                    'entity_id'   => (int)$id,
                    'meta'        => ['changes' => $changes],
                ]);
            }

            wp_send_json_success(['id' => $id]);
        }

        // INSERT
        $pdo->beginTransaction();

        $i = $pdo->prepare('
            INSERT INTO fahrzeuge
                (wache_id, rufname, fahrzeugtyp, source_note, is_first_responder,
                 status, fms_status, dienstzeiten, bild_datei)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        $i->execute([
            $wache_id, $rufname, $fahrzeugtyp, $source_note, $is_fr,
            $status, $fms_status, $dienstzeiten, $bild_datei,
        ]);
        $new_id = (int)$pdo->lastInsertId();

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'fahrzeug',
            'action'      => 'create',
            'entity_id'   => $new_id,
            'meta'        => [
                'created' => [
                    'wache_id'           => $wache_id,
                    'rufname'            => $rufname,
                    'fahrzeugtyp'        => $fahrzeugtyp,
                    'source_note'        => $source_note,
                    'is_first_responder' => $is_fr,
                    'status'             => $status,
                    'fms_status'         => $fms_status,
                    'dienstzeiten'       => $dienstzeiten,
                    'bild_datei'         => $bild_datei,
                ],
            ],
        ]);

        wp_send_json_success(['id' => $new_id]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {

            $pdo->rollBack();
        }
        wp_send_json_error(['code' => 'db', 'msg' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

// --- DELETE: Fahrzeug löschen ---------------------------------------------
add_action('wp_ajax_lsttraining_delete_fahrzeug', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'fahrzeuge',
        'nonce_action' => 'lst_fahrzeuge_nonce',
        'nonce_field' => 'nonce',
    ]);

$pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        wp_send_json_error(['code' => 'bad_request', 'msg' => 'ID fehlt'], 400);
    }

    try {
        $st = $pdo->prepare('
            SELECT wache_id, rufname, fahrzeugtyp, source_note, is_first_responder,
                   status, fms_status, dienstzeiten, bild_datei
              FROM fahrzeuge
             WHERE id = ?
        ');
        $st->execute([$id]);
        $old = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $d = $pdo->prepare('DELETE FROM fahrzeuge WHERE id = ?');
        $d->execute([$id]);

        lsttraining_log_activity([
            'entity_type' => 'fahrzeug',
            'action'      => 'delete',
            'entity_id'   => (int)$id,
            'meta'        => ['deleted' => $old],
        ]);

        wp_send_json_success(['ok' => true]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Löschen fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

// --- UPLOAD: Bild-Datei für Fahrzeug --------------------------------------
add_action('wp_ajax_lsttraining_upload_fahrzeug_bild', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'fahrzeuge',
        'nonce_action' => 'lst_fahrzeuge_nonce',
        'nonce_field' => 'nonce',
    ]);

$fileArray = null;
    if (!empty($_FILES['file'])) {
        $fileArray = $_FILES['file'];
    } elseif (!empty($_FILES['fz_file'])) {
        $fileArray = $_FILES['fz_file'];
    }

    if (!$fileArray || empty($fileArray['name']) || empty($fileArray['tmp_name'])) {
        wp_send_json_error(['code' => 'no_file', 'msg' => 'Keine Datei empfangen. Bitte erneut auswählen.'], 200);
    }

    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $overrides = [
        'test_form' => false,
        'mimes'     => [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ],
    ];

    $postMax   = ini_get('post_max_size');
    $uploadMax = ini_get('upload_max_filesize');

    $movefile = wp_handle_upload($fileArray, $overrides);

    if ($movefile && !isset($movefile['error'])) {
        wp_send_json_success([
            'url'        => $movefile['url'],
            'type'       => $movefile['type'],
            'post_max'   => $postMax,
            'upload_max' => $uploadMax,
        ]);
    }

    $msg = (is_array($movefile) && isset($movefile['error'])) ? $movefile['error'] : 'Upload fehlgeschlagen';
    wp_send_json_error([
        'code' => 'upload',
        'msg'  => $msg . ' (max: post_max_size=' . $postMax . ', upload_max_filesize=' . $uploadMax . ')',
    ], 200);
});

// --- SEARCH: Wachen für Fahrzeug-Form -------------------------------------
add_action('wp_ajax_lsttraining_search_wachen', function () {
        // Guard
    lsttraining_ajax_guard([
        'area' => 'fahrzeuge',
        'nonce_action' => 'lst_fahrzeuge_nonce',
        'nonce_field' => 'nonce',
    ]);

$pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    $q       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $land    = isset($_GET['land']) ? trim((string)$_GET['land']) : '';
    $bl_name = isset($_GET['bundesland_name']) ? trim((string)$_GET['bundesland_name']) : '';
    $limit   = 50;

    $has_bl = false; $has_bl_code = false; $has_land = false;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'bundesland'");
        $has_bl = $st && $st->rowCount() > 0;
        $st2 = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'bundesland_code'");
        $has_bl_code = $st2 && $st2->rowCount() > 0;
        $st3 = $pdo->query("SHOW COLUMNS FROM wachen LIKE 'land'");
        $has_land = $st3 && $st3->rowCount() > 0;
    } catch (Throwable $e) {}

    $where = [];
    $args  = [];

    if ($land !== '' && $has_land) {
        $where[] = 'land = ?';
        $args[]  = $land;
    }

    if ($bl_name !== '' && ($has_bl || $has_bl_code)) {
        $cond = [];
        if ($has_bl)      { $cond[] = 'bundesland = ?';      $args[] = $bl_name; }
        if ($has_bl_code) { $cond[] = 'bundesland_code = ?'; $args[] = $bl_name; }
        if ($cond) $where[] = '(' . implode(' OR ', $cond) . ')';
    }

    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = '(id = ? OR name LIKE ?)';
            $args[]  = (int)$q;
            $args[]  = '%' . $q . '%';
        } else {
            $where[] = '(name LIKE ?)';
            $args[]  = '%' . $q . '%';
        }
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "
        SELECT id, name
          FROM wachen
          $where_sql
         ORDER BY name ASC
         LIMIT $limit
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);

        $items = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id'   => (int)$r['id'],
                'text' => $r['name'] . ' (#' . (int)$r['id'] . ')',
            ];
        }
        wp_send_json_success(['items' => $items]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Suche fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});
