<?php
/**
 * Admin-Seite: Benutzer-Rechte verwalten.
 */

if (!defined('ABSPATH')) { exit; }
if (!current_user_can('manage_options')) {
    wp_die(__('Du hast keine ausreichenden Rechte, um diese Seite aufzurufen.', 'lsttraining'));
}

require_once plugin_dir_path(__FILE__) . 'db.php';
require_once plugin_dir_path(__FILE__) . 'permissions.php';
require_once plugin_dir_path(__FILE__) . 'activity.php';

$pdo = lsttraining_get_connection();
if (!$pdo instanceof PDO) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Datenbankverbindung fehlgeschlagen.', 'lsttraining') . '</p></div>';
    return;
}
lsttraining_permissions_ensure_schema($pdo);

$table_name = 'user_permissions';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lsttraining_nonce'])) {
    if (!wp_verify_nonce($_POST['lsttraining_nonce'], 'lsttraining_save_permissions')) {
        wp_die(__('Nonce-Check fehlgeschlagen.', 'lsttraining'));
    }

    $all_user_ids = array_map('intval', (array) ($_POST['user_ids'] ?? []));
    $matrix = (array) ($_POST['ulp'] ?? []);
    $grantor_id = (int) get_current_user_id();

    try {
        $pdo->beginTransaction();

        $stmtCheck = $pdo->prepare("SELECT user_id FROM {$table_name} WHERE user_id = ?");
        $stmtInsert = $pdo->prepare("
            INSERT INTO {$table_name} (
                user_id,
                can_edit_leitstellen,
                can_edit_nebenstellen,
                can_edit_hospitals,
                can_edit_wachen,
                can_edit_fahrzeuge,
                can_manage_spielinstanzen,
                leitstellen_ids
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtUpdate = $pdo->prepare("
            UPDATE {$table_name}
               SET can_edit_leitstellen = ?,
                   can_edit_nebenstellen = ?,
                   can_edit_hospitals = ?,
                   can_edit_wachen = ?,
                   can_edit_fahrzeuge = ?,
                   can_manage_spielinstanzen = ?,
                   leitstellen_ids = ?
             WHERE user_id = ?
        ");
        $deleteMatrix = $pdo->prepare('DELETE FROM user_leitstelle_permissions WHERE user_id = ?');
        $insertMatrix = $pdo->prepare('
            INSERT INTO user_leitstelle_permissions
                (user_id, leitstelle_id, can_edit_leitstelle, can_edit_hospitals, can_edit_wachen, can_edit_fahrzeuge, granted_by_user_id, granted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ');

        foreach ($all_user_ids as $user_id) {
            $can_leitstelle = isset($_POST["leitstellen_{$user_id}"]) ? 1 : 0;
            $can_nebenstelle = isset($_POST["nebenstellen_{$user_id}"]) ? 1 : 0;
            $can_hospital = isset($_POST["hospitals_{$user_id}"]) ? 1 : 0;
            $can_wache = isset($_POST["wachen_{$user_id}"]) ? 1 : 0;
            $can_fahrzeug = isset($_POST["fahrzeuge_{$user_id}"]) ? 1 : 0;
            $can_spielinstanzen = isset($_POST["spielinstanzen_{$user_id}"]) ? 1 : 0;

            $legacy_ids = [];
            $deleteMatrix->execute([$user_id]);

            foreach ((array) ($matrix[$user_id] ?? []) as $leitstelle_id => $row) {
                $leitstelle_id = (int) $leitstelle_id;
                if ($leitstelle_id <= 0 || !is_array($row)) {
                    continue;
                }
                $row_leitstelle = !empty($row['leitstelle']) ? 1 : 0;
                $row_hospitals = !empty($row['hospitals']) ? 1 : 0;
                $row_wachen = !empty($row['wachen']) ? 1 : 0;
                $row_fahrzeuge = !empty($row['fahrzeuge']) ? 1 : 0;
                if (!$row_leitstelle && !$row_hospitals && !$row_wachen && !$row_fahrzeuge) {
                    continue;
                }
                if ($row_leitstelle) {
                    $legacy_ids[] = $leitstelle_id;
                }
                $insertMatrix->execute([
                    $user_id,
                    $leitstelle_id,
                    $row_leitstelle,
                    $row_hospitals,
                    $row_wachen,
                    $row_fahrzeuge,
                    $grantor_id,
                ]);
            }

            $leitstellen_ids = implode(',', array_values(array_unique($legacy_ids)));

            $stmtCheck->execute([$user_id]);
            if ($stmtCheck->fetchColumn()) {
                $stmtUpdate->execute([
                    $can_leitstelle,
                    $can_nebenstelle,
                    $can_hospital,
                    $can_wache,
                    $can_fahrzeug,
                    $can_spielinstanzen,
                    $leitstellen_ids,
                    $user_id,
                ]);
            } else {
                $stmtInsert->execute([
                    $user_id,
                    $can_leitstelle,
                    $can_nebenstelle,
                    $can_hospital,
                    $can_wache,
                    $can_fahrzeug,
                    $can_spielinstanzen,
                    $leitstellen_ids,
                ]);
            }
        }

        $pdo->commit();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Zugriffsrechte wurden gespeichert.', 'lsttraining') . '</p></div>';

        lsttraining_log_activity([
            'entity_type' => 'permission',
            'action' => 'permission_change',
            'entity_id' => null,
            'meta' => [
                'count_users' => count($all_user_ids),
                'page' => 'benutzer.php',
                'mode' => 'leitstellen_matrix',
            ],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo '<div class="notice notice-error"><p>' . esc_html__('Datenbank-Fehler: ', 'lsttraining') . esc_html($e->getMessage()) . '</p></div>';
    }
}

$users = get_users(['fields' => ['ID', 'user_login', 'display_name']]);
$leitstellen = [];
try {
    $stmt = $pdo->query('SELECT id, name, ort, bundesland, created_by_user_id FROM leitstellen ORDER BY name ASC, id ASC');
    $leitstellen = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $leitstellen = [];
}

$permissions = [];
$matrix_permissions = [];
if ($users) {
    $user_ids = array_map('intval', wp_list_pluck($users, 'ID'));
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));

    $stmt = $pdo->prepare("SELECT * FROM {$table_name} WHERE user_id IN ({$placeholders})");
    $stmt->execute($user_ids);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $permissions[(int) $row['user_id']] = $row;
    }

    $stmt = $pdo->prepare("SELECT * FROM user_leitstelle_permissions WHERE user_id IN ({$placeholders})");
    $stmt->execute($user_ids);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $uid = (int) $row['user_id'];
        $lid = (int) $row['leitstelle_id'];
        $matrix_permissions[$uid][$lid] = $row;
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Benutzer-Rechte verwalten', 'lsttraining'); ?></h1>
    <p class="description">
        <?php esc_html_e('Globale Rechte steuern den Zugriff auf Bereiche und das Anlegen neuer Leitstellen. Die Matrix darunter schaltet fremde oder bestehende Leitstellen pro Benutzer und Bereich frei. Selbst erstellte Leitstellen sind automatisch vollständig bearbeitbar.', 'lsttraining'); ?>
    </p>

    <form method="post" action="">
        <?php wp_nonce_field('lsttraining_save_permissions', 'lsttraining_nonce'); ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:70px; text-align:right;"><?php esc_html_e('ID', 'lsttraining'); ?></th>
                    <th><?php esc_html_e('Benutzer', 'lsttraining'); ?></th>
                    <th style="text-align:center;"><?php esc_html_e('Leitstellen', 'lsttraining'); ?></th>
                    <th style="text-align:center;"><?php esc_html_e('Nebenstellen', 'lsttraining'); ?></th>
                    <th style="text-align:center;"><?php esc_html_e('Krankenhäuser', 'lsttraining'); ?></th>
                    <th style="text-align:center;"><?php esc_html_e('Wachen', 'lsttraining'); ?></th>
                    <th style="text-align:center;"><?php esc_html_e('Fahrzeuge', 'lsttraining'); ?></th>
                    <th style="text-align:center;"><?php esc_html_e('Spielinstanzen', 'lsttraining'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user):
                $uid = (int) $user->ID;
                $perm = $permissions[$uid] ?? [];
            ?>
                <tr>
                    <td style="text-align:right;">
                        <?php echo esc_html((string) $uid); ?>
                        <input type="hidden" name="user_ids[]" value="<?php echo esc_attr((string) $uid); ?>">
                    </td>
                    <td>
                        <strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong><br>
                        <span class="description"><?php echo esc_html($user->user_login); ?></span>
                    </td>
                    <td style="text-align:center;"><input type="checkbox" name="leitstellen_<?php echo esc_attr((string) $uid); ?>" value="1" <?php checked((int) ($perm['can_edit_leitstellen'] ?? 0), 1); ?>></td>
                    <td style="text-align:center;"><input type="checkbox" name="nebenstellen_<?php echo esc_attr((string) $uid); ?>" value="1" <?php checked((int) ($perm['can_edit_nebenstellen'] ?? 0), 1); ?>></td>
                    <td style="text-align:center;"><input type="checkbox" name="hospitals_<?php echo esc_attr((string) $uid); ?>" value="1" <?php checked((int) ($perm['can_edit_hospitals'] ?? 0), 1); ?>></td>
                    <td style="text-align:center;"><input type="checkbox" name="wachen_<?php echo esc_attr((string) $uid); ?>" value="1" <?php checked((int) ($perm['can_edit_wachen'] ?? 0), 1); ?>></td>
                    <td style="text-align:center;"><input type="checkbox" name="fahrzeuge_<?php echo esc_attr((string) $uid); ?>" value="1" <?php checked((int) ($perm['can_edit_fahrzeuge'] ?? 0), 1); ?>></td>
                    <td style="text-align:center;"><input type="checkbox" name="spielinstanzen_<?php echo esc_attr((string) $uid); ?>" value="1" <?php checked((int) ($perm['can_manage_spielinstanzen'] ?? 0), 1); ?>></td>
                </tr>
                <tr>
                    <td></td>
                    <td colspan="7">
                        <?php if (!$leitstellen): ?>
                            <em><?php esc_html_e('Keine Leitstellen vorhanden.', 'lsttraining'); ?></em>
                        <?php else: ?>
                            <details>
                                <summary><?php esc_html_e('Leitstellen-Freigaben bearbeiten', 'lsttraining'); ?></summary>
                                <table class="widefat striped" style="margin-top:10px;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Leitstelle', 'lsttraining'); ?></th>
                                            <th style="text-align:center;"><?php esc_html_e('Leitstelle', 'lsttraining'); ?></th>
                                            <th style="text-align:center;"><?php esc_html_e('Krankenhäuser', 'lsttraining'); ?></th>
                                            <th style="text-align:center;"><?php esc_html_e('Wachen', 'lsttraining'); ?></th>
                                            <th style="text-align:center;"><?php esc_html_e('Fahrzeuge', 'lsttraining'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($leitstellen as $leitstelle):
                                        $lid = (int) $leitstelle['id'];
                                        $row = $matrix_permissions[$uid][$lid] ?? [];
                                        $owned = (int) ($leitstelle['created_by_user_id'] ?? 0) === $uid;
                                    ?>
                                        <tr>
                                            <td>
                                                <strong>#<?php echo esc_html((string) $lid); ?> <?php echo esc_html((string) $leitstelle['name']); ?></strong>
                                                <?php if ($owned): ?>
                                                    <span class="description"><?php esc_html_e('selbst erstellt, automatisch erlaubt', 'lsttraining'); ?></span>
                                                <?php elseif (!empty($leitstelle['ort']) || !empty($leitstelle['bundesland'])): ?>
                                                    <br><span class="description"><?php echo esc_html(trim((string) ($leitstelle['ort'] ?? '') . ' ' . (string) ($leitstelle['bundesland'] ?? ''))); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;"><input type="checkbox" name="ulp[<?php echo esc_attr((string) $uid); ?>][<?php echo esc_attr((string) $lid); ?>][leitstelle]" value="1" <?php checked($owned || (int) ($row['can_edit_leitstelle'] ?? 0), 1); ?> <?php disabled($owned); ?>></td>
                                            <td style="text-align:center;"><input type="checkbox" name="ulp[<?php echo esc_attr((string) $uid); ?>][<?php echo esc_attr((string) $lid); ?>][hospitals]" value="1" <?php checked($owned || (int) ($row['can_edit_hospitals'] ?? 0), 1); ?> <?php disabled($owned); ?>></td>
                                            <td style="text-align:center;"><input type="checkbox" name="ulp[<?php echo esc_attr((string) $uid); ?>][<?php echo esc_attr((string) $lid); ?>][wachen]" value="1" <?php checked($owned || (int) ($row['can_edit_wachen'] ?? 0), 1); ?> <?php disabled($owned); ?>></td>
                                            <td style="text-align:center;"><input type="checkbox" name="ulp[<?php echo esc_attr((string) $uid); ?>][<?php echo esc_attr((string) $lid); ?>][fahrzeuge]" value="1" <?php checked($owned || (int) ($row['can_edit_fahrzeuge'] ?? 0), 1); ?> <?php disabled($owned); ?>></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button(__('Rechte speichern', 'lsttraining')); ?>
    </form>
</div>
