<?php
/**
 * Admin-Seite: Spielinstanzen verwalten.
 */

if (!defined('ABSPATH')) { exit; }

require_once plugin_dir_path(__FILE__) . 'db.php';
require_once plugin_dir_path(__FILE__) . 'permissions.php';
require_once plugin_dir_path(__FILE__) . 'instance-lifecycle.php';
require_once plugin_dir_path(__FILE__) . 'activity.php';

if (!lsttraining_user_can('spielinstanzen')) {
    wp_die(__('Du hast keine ausreichenden Rechte, um diese Seite aufzurufen.', 'lsttraining'));
}

$pdo = lsttraining_get_connection();
if (!$pdo instanceof PDO) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Datenbankverbindung fehlgeschlagen.', 'lsttraining') . '</p></div>';
    return;
}

lsttraining_instance_lifecycle_ensure_schema($pdo);

if (!function_exists('lsttraining_spielinstanzen_mode_label')) {
    function lsttraining_spielinstanzen_mode_label(?string $mode): string {
        $labels = [
            'singleplayer' => 'Einzelspieler',
            'multiplayer' => 'Multiplayer',
            'einsatzleiter' => 'Einsatzleiter',
        ];
        return $labels[$mode ?? ''] ?? (string) $mode;
    }
}

if (!function_exists('lsttraining_spielinstanzen_datetime_input')) {
    function lsttraining_spielinstanzen_datetime_input(?string $value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts ? wp_date('Y-m-d\TH:i', $ts) : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lst_spielinstanzen_nonce'])) {
    if (!wp_verify_nonce((string) $_POST['lst_spielinstanzen_nonce'], 'lsttraining_spielinstanzen')) {
        wp_die(__('Nonce-Check fehlgeschlagen.', 'lsttraining'));
    }

    $action = sanitize_key((string) ($_POST['lst_instance_action'] ?? ''));
    $instanz_id = absint($_POST['instanz_id'] ?? 0);

    if ($instanz_id <= 0) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Simulation fehlt.', 'lsttraining') . '</p></div>';
    } elseif ($action === 'update_retention') {
        $raw = sanitize_text_field(wp_unslash($_POST['retention_delete_at'] ?? ''));
        $delete_at = null;
        if ($raw !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, wp_timezone());
            if ($dt instanceof DateTimeImmutable) {
                $delete_at = $dt->format('Y-m-d H:i:s');
            }
        }

        try {
            $stmt = $pdo->prepare('
                UPDATE spielinstanzen
                SET retention_delete_at = ?,
                    retention_notice_sent_at = NULL
                WHERE id = ?
            ');
            $stmt->execute([$delete_at, $instanz_id]);

            lsttraining_instance_lifecycle_log($instanz_id, (int) get_current_user_id(), 'retention_update', [
                'retention_delete_at' => $delete_at,
                'page' => 'spielinstanzen.php',
            ]);

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Löschfrist wurde gespeichert.', 'lsttraining') . '</p></div>';
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Löschfrist konnte nicht gespeichert werden: ', 'lsttraining') . esc_html($e->getMessage()) . '</p></div>';
        }
    } elseif ($action === 'delete') {
        try {
            if (lsttraining_instance_lifecycle_delete($pdo, $instanz_id, (int) get_current_user_id(), 'delete')) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Spielinstanz wurde gelöscht.', 'lsttraining') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Spielinstanz wurde nicht gefunden.', 'lsttraining') . '</p></div>';
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Spielinstanz konnte nicht gelöscht werden: ', 'lsttraining') . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

$per_page = 50;
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$offset = ($paged - 1) * $per_page;

$filter_status = sanitize_key((string) ($_GET['sim_state'] ?? ''));
$filter_leitstelle = absint($_GET['leitstelle_id'] ?? 0);
$filter_owner = absint($_GET['owner_user_id'] ?? 0);
$filter_from = sanitize_text_field((string) ($_GET['from'] ?? ''));
$filter_to = sanitize_text_field((string) ($_GET['to'] ?? ''));

$where = [];
$params = [];

if (in_array($filter_status, ['created', 'running', 'paused', 'ended'], true)) {
    $where[] = 'si.sim_state = ?';
    $params[] = $filter_status;
}
if ($filter_leitstelle > 0) {
    $where[] = 'si.leitstelle_id = ?';
    $params[] = $filter_leitstelle;
}
if ($filter_owner > 0) {
    $where[] = 'si.owner_user_id = ?';
    $params[] = $filter_owner;
}
if ($filter_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) {
    $where[] = 'si.erstellt_am >= ?';
    $params[] = $filter_from . ' 00:00:00';
}
if ($filter_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to)) {
    $where[] = 'si.erstellt_am <= ?';
    $params[] = $filter_to . ' 23:59:59';
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM spielinstanzen si {$where_sql}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $per_page));

$sql = "
    SELECT
        si.id,
        si.name,
        si.leitstelle_id,
        si.erstellt_am,
        si.ist_aktiv,
        si.settings_json,
        si.started_at,
        si.sim_state,
        si.owner_user_id,
        si.last_activity_at,
        si.retention_notice_sent_at,
        si.retention_delete_at,
        l.name AS leitstelle_name,
        (SELECT COUNT(*) FROM instanz_user iu WHERE iu.instanz_id = si.id AND COALESCE(iu.connected, 1) = 1) AS participants_count,
        (SELECT COUNT(*) FROM fahrzeug_status fs WHERE fs.instanz_id = si.id) AS fahrzeuge_count
    FROM spielinstanzen si
    LEFT JOIN leitstellen l ON l.id = si.leitstelle_id
    {$where_sql}
    ORDER BY si.erstellt_am DESC, si.id DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$leitstellen = [];
try {
    $ls_stmt = $pdo->query('SELECT id, name FROM leitstellen ORDER BY name ASC, id ASC');
    $leitstellen = $ls_stmt ? ($ls_stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $leitstellen = [];
}

$owner_ids = array_values(array_unique(array_filter(array_map(static function (array $row): int {
    return (int) ($row['owner_user_id'] ?? 0);
}, $rows))));
$owner_map = [];
if ($owner_ids) {
    foreach (get_users(['include' => $owner_ids, 'fields' => ['ID', 'user_login', 'display_name']]) as $owner) {
        $owner_map[(int) $owner->ID] = $owner->display_name ?: $owner->user_login;
    }
}

?>
<div class="wrap">
    <h1><?php esc_html_e('Spielinstanzen', 'lsttraining'); ?></h1>

    <form method="get" action="" style="margin: 16px 0;">
        <input type="hidden" name="page" value="lsttraining_spielinstanzen">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <label>
                <strong><?php esc_html_e('Status', 'lsttraining'); ?></strong><br>
                <select name="sim_state">
                    <option value=""><?php esc_html_e('Alle', 'lsttraining'); ?></option>
                    <?php foreach (['created', 'running', 'paused', 'ended'] as $state): ?>
                        <option value="<?php echo esc_attr($state); ?>" <?php selected($filter_status, $state); ?>><?php echo esc_html($state); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <strong><?php esc_html_e('Leitstelle', 'lsttraining'); ?></strong><br>
                <select name="leitstelle_id">
                    <option value="0"><?php esc_html_e('Alle', 'lsttraining'); ?></option>
                    <?php foreach ($leitstellen as $leitstelle): ?>
                        <option value="<?php echo esc_attr((string) (int) $leitstelle['id']); ?>" <?php selected($filter_leitstelle, (int) $leitstelle['id']); ?>>
                            <?php echo esc_html('#' . (int) $leitstelle['id'] . ' ' . (string) $leitstelle['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <strong><?php esc_html_e('Besitzer-ID', 'lsttraining'); ?></strong><br>
                <input type="number" name="owner_user_id" min="1" value="<?php echo $filter_owner ? esc_attr((string) $filter_owner) : ''; ?>">
            </label>
            <label>
                <strong><?php esc_html_e('Von', 'lsttraining'); ?></strong><br>
                <input type="date" name="from" value="<?php echo esc_attr($filter_from); ?>">
            </label>
            <label>
                <strong><?php esc_html_e('Bis', 'lsttraining'); ?></strong><br>
                <input type="date" name="to" value="<?php echo esc_attr($filter_to); ?>">
            </label>
            <button class="button button-primary" type="submit"><?php esc_html_e('Filtern', 'lsttraining'); ?></button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lsttraining_spielinstanzen')); ?>"><?php esc_html_e('Zurücksetzen', 'lsttraining'); ?></a>
        </div>
    </form>

    <p>
        <?php printf(esc_html__('%d Instanzen, Seite %d von %d', 'lsttraining'), (int) $total, (int) $paged, (int) $pages); ?>
    </p>

    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:70px;"><?php esc_html_e('ID', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Name', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Leitstelle', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Besitzer', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Modus / Status', 'lsttraining'); ?></th>
                <th style="width:90px;"><?php esc_html_e('Teiln.', 'lsttraining'); ?></th>
                <th style="width:90px;"><?php esc_html_e('Fahrz.', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Aktivität', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Löschfrist', 'lsttraining'); ?></th>
                <th style="width:220px;"><?php esc_html_e('Aktionen', 'lsttraining'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="10"><?php esc_html_e('Keine Spielinstanzen gefunden.', 'lsttraining'); ?></td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row):
                $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
                $settings = is_array($settings) ? $settings : [];
                $mode = (string) ($settings['mode'] ?? '');
                $owner_id = (int) ($row['owner_user_id'] ?? 0);
                $owner_label = $owner_id > 0 ? (($owner_map[$owner_id] ?? ('User #' . $owner_id)) . ' (#' . $owner_id . ')') : '';
                $open_url = lsttraining_instance_lifecycle_continue_url((int) $row['id']);
            ?>
                <tr>
                    <td>#<?php echo esc_html((string) (int) $row['id']); ?></td>
                    <td>
                        <strong><?php echo esc_html((string) ($row['name'] ?? '')); ?></strong><br>
                        <span class="description"><?php echo esc_html((string) ($row['erstellt_am'] ?? '')); ?></span>
                    </td>
                    <td><?php echo esc_html(trim('#' . (int) ($row['leitstelle_id'] ?? 0) . ' ' . (string) ($row['leitstelle_name'] ?? ''))); ?></td>
                    <td><?php echo esc_html($owner_label); ?></td>
                    <td>
                        <?php echo esc_html(lsttraining_spielinstanzen_mode_label($mode)); ?><br>
                        <code><?php echo esc_html((string) ($row['sim_state'] ?? 'created')); ?></code>
                    </td>
                    <td><?php echo esc_html((string) (int) ($row['participants_count'] ?? 0)); ?></td>
                    <td><?php echo esc_html((string) (int) ($row['fahrzeuge_count'] ?? 0)); ?></td>
                    <td>
                        <?php echo esc_html((string) ($row['last_activity_at'] ?: $row['started_at'] ?: '')); ?>
                        <?php if (!empty($row['retention_notice_sent_at'])): ?>
                            <br><span class="description"><?php esc_html_e('Erinnerung gesendet', 'lsttraining'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="">
                            <?php wp_nonce_field('lsttraining_spielinstanzen', 'lst_spielinstanzen_nonce'); ?>
                            <input type="hidden" name="lst_instance_action" value="update_retention">
                            <input type="hidden" name="instanz_id" value="<?php echo esc_attr((string) (int) $row['id']); ?>">
                            <input type="datetime-local" name="retention_delete_at" value="<?php echo esc_attr(lsttraining_spielinstanzen_datetime_input($row['retention_delete_at'] ?? '')); ?>" style="max-width:180px;">
                            <button type="submit" class="button button-small"><?php esc_html_e('Speichern', 'lsttraining'); ?></button>
                        </form>
                    </td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url($open_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Öffnen', 'lsttraining'); ?></a>
                        <form method="post" action="" style="display:inline;" onsubmit="return confirm('Spielinstanz wirklich löschen?');">
                            <?php wp_nonce_field('lsttraining_spielinstanzen', 'lst_spielinstanzen_nonce'); ?>
                            <input type="hidden" name="lst_instance_action" value="delete">
                            <input type="hidden" name="instanz_id" value="<?php echo esc_attr((string) (int) $row['id']); ?>">
                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Löschen', 'lsttraining'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <p>
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php
                $url = add_query_arg(array_merge($_GET, ['paged' => $i]), admin_url('admin.php'));
                ?>
                <a class="button<?php echo $i === $paged ? ' button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $i); ?></a>
            <?php endfor; ?>
        </p>
    <?php endif; ?>
</div>
