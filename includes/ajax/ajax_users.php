<?php
// Benutzerrechte
/* -------------------------------------------------------------------------
 * 7. BENUTZER-RECHTE (nur Admins – global)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_lsttraining_get_user_permissions', function () {
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'capability' => 'manage_options',
        'nonce_action' => 'lsttraining_save_permissions',
        'method' => 'GET',
    ]);

$pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('Datenbankverbindung fehlgeschlagen.', 500);
    }

    $wp_users = get_users(['fields' => ['ID', 'user_login', 'display_name']]);
    $user_ids = wp_list_pluck($wp_users, 'ID');
    if (empty($user_ids)) {
        wp_send_json_success([]);
    }

    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $sql = "
        SELECT user_id,
               can_edit_leitstellen,
               can_edit_nebenstellen,
               can_edit_hospitals,
               can_edit_wachen,
               can_edit_fahrzeuge,
               leitstellen_ids
          FROM user_permissions
         WHERE user_id IN ($placeholders)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($user_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $perms_by_user = [];
    foreach ($rows as $r) {
        $perms_by_user[(int)$r['user_id']] = [
            'leitstellen'    => (int)$r['can_edit_leitstellen'],
            'nebenstellen'   => (int)$r['can_edit_nebenstellen'],
            'hospitals'      => (int)$r['can_edit_hospitals'],
            'wachen'         => (int)$r['can_edit_wachen'],
            'fahrzeuge'      => (int)$r['can_edit_fahrzeuge'],
            'leitstellen_ids' => (string)$r['leitstellen_ids'],
        ];
    }

    $result = [];
    foreach ($wp_users as $u) {
        $uid = (int)$u->ID;
        $result[] = [
            'ID'           => $uid,
            'user_login'   => $u->user_login,
            'display_name' => $u->display_name ?: $u->user_login,
            'permissions'  => $perms_by_user[$uid] ?? [
                'leitstellen'    => 0,
                'nebenstellen'   => 0,
                'hospitals'      => 0,
                'wachen'         => 0,
                'fahrzeuge'      => 0,
                'leitstellen_ids' => '',
            ],
        ];
    }

    wp_send_json_success($result);
});

add_action('wp_ajax_lsttraining_save_user_permissions', function () {
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'capability' => 'manage_options',
        'nonce_action' => 'lsttraining_save_permissions',
        'method' => 'POST',
    ]);

$json = wp_unslash($_POST['user_permissions'] ?? '');
    if ($json === '') {
        wp_send_json_error('Keine Daten übermittelt.', 400);
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        wp_send_json_error('Ungültiges JSON-Format.', 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        wp_send_json_error('Datenbankverbindung fehlgeschlagen.', 500);
    }

    $stmtCheck  = $pdo->prepare('SELECT user_id FROM user_permissions WHERE user_id = ?');
    $stmtInsert = $pdo->prepare('
        INSERT INTO user_permissions (
            user_id,
            can_edit_leitstellen,
            can_edit_nebenstellen,
            can_edit_hospitals,
            can_edit_wachen,
            can_edit_fahrzeuge,
            leitstellen_ids
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmtUpdate = $pdo->prepare('
        UPDATE user_permissions
           SET can_edit_leitstellen  = ?,
               can_edit_nebenstellen = ?,
               can_edit_hospitals    = ?,
               can_edit_wachen       = ?,
               can_edit_fahrzeuge    = ?,
               leitstellen_ids        = ?
         WHERE user_id = ?
    ');
    $valid_leitstellen_ids = array_map('intval', $pdo->query('SELECT id FROM leitstellen')->fetchAll(PDO::FETCH_COLUMN) ?: []);

    try {
        $pdo->beginTransaction();

        foreach ($data as $entry) {
            $user_id = (int)($entry['user_id'] ?? 0);
            if ($user_id <= 0) continue;

            $can_leitstellen  = !empty($entry['can_edit_leitstellen']) ? 1 : 0;
            $can_nebenstellen = !empty($entry['can_edit_nebenstellen']) ? 1 : 0;
            $can_hospitals    = !empty($entry['can_edit_hospitals']) ? 1 : 0;
            $can_wachen       = !empty($entry['can_edit_wachen']) ? 1 : 0;
            $can_fahrzeuge    = !empty($entry['can_edit_fahrzeuge']) ? 1 : 0;

            $ids_raw = sanitize_text_field($entry['leitstellen_ids'] ?? '');
            $ids_arr = array_values(array_unique(array_intersect(
                array_map('intval', explode(',', $ids_raw)),
                $valid_leitstellen_ids
            )));
            $leitstellen_ids = implode(',', $ids_arr);

            $stmtCheck->execute([$user_id]);
            $exists = (bool)$stmtCheck->fetchColumn();

            if ($exists) {
                $stmtUpdate->execute([
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leitstellen_ids,
                    $user_id,
                ]);
            } else {
                $stmtInsert->execute([
                    $user_id,
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leitstellen_ids,
                ]);
            }
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'user_permissions',
            'action'      => 'update',
            'entity_id'   => 0,
            'meta'        => ['page' => 'ajax:save_user_permissions'],
        ]);

        wp_send_json_success();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        wp_send_json_error('Datenbank-Fehler: ' . $e->getMessage(), 500);
    }
});
