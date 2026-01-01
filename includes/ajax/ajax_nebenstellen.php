<?php
// Nebenleitstellen CRUD
/**
 * Speichern einer Nebenleitstelle (Insert oder Update)
 * @action wp_ajax_lsttraining_save_nebenleitstelle
 */
add_action('wp_ajax_lsttraining_save_nebenleitstelle', function () {
    // Guard
    lsttraining_ajax_guard([
        'area' => 'nebenstellen',
        'nonce_action' => 'lst_nebenstellen_nonce',
        'nonce_field' => '_ajax_nonce',
    ]);

    // 2) DB verbinden
    try {
        $pdo = lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung fehlgeschlagen'], 500);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'DB-Verbindung: ' . $e->getMessage()], 500);
    }

    // 3) Eingaben
    $id          = (int)($_POST['id'] ?? 0);               // 0/leer => INSERT
    $desired_id  = (int)($_POST['desired_id'] ?? 0);       // optional bei INSERT
    $name        = sanitize_text_field($_POST['name'] ?? '');
    $zust        = sanitize_text_field($_POST['zustandigkeit'] ?? '');
    $einwohner   = (int)($_POST['einwohner'] ?? 0);
    $flaeche_km2 = (float)($_POST['flaeche'] ?? 0);
    $gps         = sanitize_text_field($_POST['gps'] ?? '');

    if ($name === '') {
        wp_send_json_error(['code' => 'validation', 'msg' => 'Name darf nicht leer sein'], 400);
    }

    // Normalisierung für Diffs
    $norm = static function ($v) {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        }
        return trim((string)$v);
    };

    // 4) Eindeutigkeit Name prüfen
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM nebenleitstellen WHERE name = :name AND id <> :id');
            $stmt->execute([':name' => $name, ':id' => $id]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM nebenleitstellen WHERE name = :name');
            $stmt->execute([':name' => $name]);
        }
        if ((int)$stmt->fetchColumn() > 0) {
            wp_send_json_error(['code' => 'name_conflict', 'msg' => 'Name bereits vorhanden'], 409);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Prüfung fehlgeschlagen: ' . $e->getMessage()], 500);
    }

    // 5) INSERT oder UPDATE
    try {
        if ($id > 0) {
            // Altstand für Diff laden
            $old = [];
            try {
                $sel = $pdo->prepare('
                    SELECT name, zustandigkeit, einwohner, flaeche_km2, gps
                    FROM nebenleitstellen WHERE id = :id
                ');
                $sel->execute([':id' => $id]);
                $old = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $old = [];
            }

            $stmt = $pdo->prepare('
                UPDATE nebenleitstellen
                   SET name = :name,
                       zustandigkeit = :zust,
                       einwohner = :einwohner,
                       flaeche_km2 = :flaeche_km2,
                       gps = :gps
                 WHERE id = :id
            ');
            $stmt->execute([
                ':name'        => $name,
                ':zust'        => $zust,
                ':einwohner'   => $einwohner,
                ':flaeche_km2' => $flaeche_km2,
                ':gps'         => $gps,
                ':id'          => $id,
            ]);

            $new = [
                'name'         => $name,
                'zustandigkeit'=> $zust,
                'einwohner'    => $einwohner,
                'flaeche_km2'  => $flaeche_km2,
                'gps'          => $gps,
            ];

            $changes = [];
            foreach ($new as $k => $nv) {
                $ov = array_key_exists($k, $old) ? $old[$k] : null;
                if ($norm($ov) !== $norm($nv)) {
                    $changes[$k] = ['old' => $ov, 'new' => $nv];
                }
            }

            if (!empty($changes) && function_exists('lsttraining_log_activity')) {
                lsttraining_log_activity([
                    'entity_type' => 'nebenstelle',
                    'action'      => 'update',
                    'entity_id'   => (int)$id,
                    'meta'        => ['changes' => $changes],
                ]);
            }

            wp_send_json_success(['id' => $id]);
        }

        // INSERT
        if ($desired_id > 0) {
            $chk = $pdo->prepare('SELECT 1 FROM nebenleitstellen WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $desired_id]);
            if ($chk->fetchColumn()) {
                wp_send_json_error(['code' => 'id_conflict', 'msg' => 'Gewünschte ID bereits vergeben'], 409);
            }

            $stmt = $pdo->prepare('
                INSERT INTO nebenleitstellen (id, name, zustandigkeit, einwohner, flaeche_km2, gps)
                VALUES (:id, :name, :zust, :einwohner, :flaeche_km2, :gps)
            ');
            $stmt->execute([
                ':id'          => $desired_id,
                ':name'        => $name,
                ':zust'        => $zust,
                ':einwohner'   => $einwohner,
                ':flaeche_km2' => $flaeche_km2,
                ':gps'         => $gps,
            ]);

            if (function_exists('lsttraining_log_activity')) {
                lsttraining_log_activity([
                    'entity_type' => 'nebenstelle',
                    'action'      => 'create',
                    'entity_id'   => (int)$desired_id,
                    'meta'        => [
                        'created' => [
                            'name'          => $name,
                            'zustandigkeit' => $zust,
                            'einwohner'     => $einwohner,
                            'flaeche_km2'   => $flaeche_km2,
                            'gps'           => $gps,
                        ],
                    ],
                ]);
            }

            wp_send_json_success(['id' => $desired_id]);
        }

        $stmt = $pdo->prepare('
            INSERT INTO nebenleitstellen (name, zustandigkeit, einwohner, flaeche_km2, gps)
            VALUES (:name, :zust, :einwohner, :flaeche_km2, :gps)
        ');
        $stmt->execute([
            ':name'        => $name,
            ':zust'        => $zust,
            ':einwohner'   => $einwohner,
            ':flaeche_km2' => $flaeche_km2,
            ':gps'         => $gps,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if (function_exists('lsttraining_log_activity')) {
            lsttraining_log_activity([
                'entity_type' => 'nebenstelle',
                'action'      => 'create',
                'entity_id'   => $newId,
                'meta'        => [
                    'created' => [
                        'name'          => $name,
                        'zustandigkeit' => $zust,
                        'einwohner'     => $einwohner,
                        'flaeche_km2'   => $flaeche_km2,
                        'gps'           => $gps,
                    ],
                ],
            ]);
        }

        wp_send_json_success(['id' => $newId]);
    } catch (Throwable $e) {
        wp_send_json_error(['code' => 'db', 'msg' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

/**
 * Löscht eine Nebenstelle via AJAX
 * @action wp_ajax_lsttraining_delete_nebenstelle
 */
add_action('wp_ajax_lsttraining_delete_nebenstelle', function () {
    if (!lsttraining_user_can('nebenstellen')) {
        wp_send_json_error('Keine Berechtigung', 403);
    }

    check_ajax_referer('lsttraining_delete_nebenstelle', '_wpnonce');

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        wp_send_json_error('Ungültige ID', 400);
    }

    $pdo = lsttraining_get_connection();

    $stmt = $pdo->prepare('DELETE FROM nebenleitstellen WHERE id = ?');
    $ok = $stmt->execute([$id]);

    // Pivot-Beziehungen löschen
    $pdo->prepare('DELETE FROM wache_nebenleitstellen WHERE nebenleitstelle_id = ?')->execute([$id]);

    if ($ok) {
        lsttraining_log_activity([
            'entity_type' => 'nebenstelle',
            'action'      => 'delete',
            'entity_id'   => (int)$id,
            'meta'        => ['page' => 'ajax:delete_nebenstelle'],
        ]);
        wp_send_json_success();
    }
    wp_send_json_error('Löschen fehlgeschlagen', 500);
});

