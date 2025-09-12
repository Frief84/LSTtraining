<?php
/**
 * verlauf.php – Admin-Seite: Aktivitäts-/Audit-Log für LST-Training
 *
 * Zeigt Änderungen (create/update/delete), Relations-Events und Berechtigungsänderungen
 * mit Filtern und Pagination. "Details" rendert Feld-Diffs menschenlesbar.
 */

if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die(__('Du hast keine ausreichenden Rechte, um diese Seite aufzurufen.', 'lsttraining'));
}

require_once plugin_dir_path(__FILE__) . 'db.php';

$pdo = lsttraining_get_connection();
if (!$pdo) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Datenbankverbindung fehlgeschlagen.', 'lsttraining') . '</p></div>';
    return;
}

/** Eingaben/Filter lesen */
$per_page = 25;
$paged    = max(1, (int)($_GET['paged'] ?? 1));
$offset   = ($paged - 1) * $per_page;

$filter_user   = isset($_GET['user_id'])     ? (int)$_GET['user_id'] : 0;
$filter_type   = isset($_GET['entity_type']) ? sanitize_text_field($_GET['entity_type']) : '';
$filter_action = isset($_GET['action_type']) ? sanitize_text_field($_GET['action_type']) : '';
$filter_from   = isset($_GET['from'])        ? sanitize_text_field($_GET['from']) : ''; // YYYY-MM-DD
$filter_to     = isset($_GET['to'])          ? sanitize_text_field($_GET['to'])   : '';

$where  = [];
$params = [];

/** Zeitraum */
if ($filter_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) {
    $where[]  = 'ts >= ?';
    $params[] = $filter_from . ' 00:00:00';
}
if ($filter_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to)) {
    $where[]  = 'ts <= ?';
    $params[] = $filter_to . ' 23:59:59';
}
/** User */
if ($filter_user > 0) {
    $where[]  = 'user_id = ?';
    $params[] = $filter_user;
}
/** entity_type */
if ($filter_type !== '') {
    $where[]  = 'entity_type = ?';
    $params[] = $filter_type;
}
/** action */
if ($filter_action !== '') {
    $where[]  = 'action = ?';
    $params[] = $filter_action;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/** Count */
$stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM lst_activity_log {$where_sql}");
$stmt_cnt->execute($params);
$total = (int)$stmt_cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

/** Daten holen */
$sql = "SELECT id, ts, user_id, entity_type, entity_id, action, user_agent, meta_json
        FROM lst_activity_log
        {$where_sql}
        ORDER BY ts DESC, id DESC
        LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Benutzeranzeige vorbereiten */
$user_map = [];
if (!empty($rows)) {
    $uids = array_values(array_unique(array_filter(array_map(function ($r) {
        return (int)($r['user_id'] ?? 0);
    }, $rows))));
    if ($uids) {
        $wp_users = get_users(['include' => $uids, 'fields' => ['ID', 'user_login', 'display_name']]);
        foreach ($wp_users as $u) {
            $user_map[(int)$u->ID] = [
                'login' => $u->user_login,
                'name'  => $u->display_name,
            ];
        }
    }
}

/** Helfer: Pagination-URL mit aktuellen Filtern */
if (!function_exists('lsttraining_build_url')) {
    function lsttraining_build_url(array $args_add, array $args_remove = []): string
    {
        $current = $_GET;
        foreach ($args_remove as $ar) {
            unset($current[$ar]);
        }
        $query = array_merge($current, $args_add);
        return esc_url(add_query_arg($query, admin_url('admin.php?page=lsttraining_verlauf')));
    }
}

/** Helfer: Werte kurz darstellen */
if (!function_exists('lsttraining_trim_val')) {
    function lsttraining_trim_val($v, int $max = 120): string
    {
        if (is_array($v) || is_object($v)) {
            $s = wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $s = (string)$v;
        }
        $s = trim($s);
        if (mb_strlen($s) > $max) {
            $s = mb_substr($s, 0, $max - 3) . '...';
        }
        return $s;
    }
}

/** Helfer: Zeit formatieren */
if (!function_exists('lsttraining_fmt_ts')) {
    function lsttraining_fmt_ts($ts): string
    {
        $t = strtotime((string)$ts);
        if ($t) {
            return esc_html(date_i18n('Y-m-d H:i:s', $t));
        }
        return esc_html((string)$ts);
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Verlauf / Aktivität', 'lsttraining'); ?></h1>

    <form method="get" action="">
        <input type="hidden" name="page" value="lsttraining_verlauf">
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <label for="from"><strong><?php esc_html_e('Von', 'lsttraining'); ?></strong></label><br>
                <input type="date" name="from" id="from" value="<?php echo esc_attr($filter_from); ?>">
            </div>
            <div>
                <label for="to"><strong><?php esc_html_e('Bis', 'lsttraining'); ?></strong></label><br>
                <input type="date" name="to" id="to" value="<?php echo esc_attr($filter_to); ?>">
            </div>
            <div>
                <label for="user_id"><strong><?php esc_html_e('Benutzer-ID', 'lsttraining'); ?></strong></label><br>
                <input type="number" name="user_id" id="user_id" min="1" step="1" value="<?php echo $filter_user ? (int)$filter_user : ''; ?>" placeholder="z. B. 7">
            </div>
            <div>
                <label for="entity_type"><strong><?php esc_html_e('Bereich (entity_type)', 'lsttraining'); ?></strong></label><br>
                <input type="text" name="entity_type" id="entity_type" value="<?php echo esc_attr($filter_type); ?>" placeholder="wache, leitstelle, ...">
            </div>
            <div>
                <label for="action_type"><strong><?php esc_html_e('Aktion', 'lsttraining'); ?></strong></label><br>
                <input type="text" name="action_type" id="action_type" value="<?php echo esc_attr($filter_action); ?>" placeholder="create, update, delete, ...">
            </div>
            <div>
                <button class="button button-primary" type="submit"><?php esc_html_e('Filtern', 'lsttraining'); ?></button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lsttraining_verlauf')); ?>"><?php esc_html_e('Zurücksetzen', 'lsttraining'); ?></a>
            </div>
        </div>
    </form>

    <p style="margin-top:12px;">
        <?php
        printf(
            esc_html__('%d Einträge, Seite %d von %d', 'lsttraining'),
            (int)$total,
            (int)$paged,
            (int)$pages
        );
        ?>
    </p>

    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:120px;"><?php esc_html_e('Zeit', 'lsttraining'); ?></th>
                <th style="width:80px; text-align:right;"><?php esc_html_e('User-ID', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Benutzer', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Bereich', 'lsttraining'); ?></th>
                <th style="width:100px; text-align:right;"><?php esc_html_e('Objekt-ID', 'lsttraining'); ?></th>
                <th style="width:120px;"><?php esc_html_e('Aktion', 'lsttraining'); ?></th>
                <th><?php esc_html_e('Details', 'lsttraining'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)) : ?>
            <tr><td colspan="7"><?php esc_html_e('Keine Einträge gefunden.', 'lsttraining'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($rows as $r):
                $uid = (int)($r['user_id'] ?? 0);
                $uname = $user_map[$uid]['name']  ?? '';
                $ulog  = $user_map[$uid]['login'] ?? '';

                // Details vorbereiten
                $details_html = '';
                $meta_raw = $r['meta_json'] ?? '';
                $meta_arr = null;
                if (is_string($meta_raw) && $meta_raw !== '') {
                    $tmp = json_decode($meta_raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                        $meta_arr = $tmp;
                    }
                }

                if (is_array($meta_arr)) {
                    // 1) Feld-Diffs
                    if (!empty($meta_arr['changes']) && is_array($meta_arr['changes'])) {
                        $details_html .= '<ul class="lst-activity-changes" style="margin:0; padding-left:18px;">';
                        foreach ($meta_arr['changes'] as $field => $chg) {
                            $old = $chg['old'] ?? null;
                            $new = $chg['new'] ?? null;
                            $details_html .= '<li><strong>' . esc_html((string)$field) . '</strong>: '
                                . esc_html(lsttraining_trim_val($old)) . ' &rarr; '
                                . esc_html(lsttraining_trim_val($new)) . '</li>';
                        }
                        $details_html .= '</ul>';
                    }
                    // 2) Created / Deleted kompakt
                    if ($details_html === '' && !empty($meta_arr['created']) && is_array($meta_arr['created'])) {
                        $pairs = [];
                        foreach ($meta_arr['created'] as $k => $v) {
                            $pairs[] = esc_html($k) . '=' . esc_html(lsttraining_trim_val($v));
                            if (count($pairs) >= 8) { $pairs[] = '...'; break; }
                        }
                        $details_html = '<span>' . implode(', ', $pairs) . '</span>';
                    }
                    if ($details_html === '' && !empty($meta_arr['deleted']) && is_array($meta_arr['deleted'])) {
                        $pairs = [];
                        foreach ($meta_arr['deleted'] as $k => $v) {
                            $pairs[] = esc_html($k) . '=' . esc_html(lsttraining_trim_val($v));
                            if (count($pairs) >= 8) { $pairs[] = '...'; break; }
                        }
                        $details_html = '<span>' . implode(', ', $pairs) . '</span>';
                    }
                    // 3) Relation-Events
                    if ($details_html === '' && !empty($meta_arr['relation'])) {
                        $rel = esc_html((string)$meta_arr['relation']);
                        $old = isset($meta_arr['old']) ? esc_html(lsttraining_trim_val($meta_arr['old'])) : '';
                        $new = isset($meta_arr['new']) ? esc_html(lsttraining_trim_val($meta_arr['new'])) : '';
                        if ($old !== '' || $new !== '') {
                            $details_html = '<span>Relation ' . $rel . ': ' . $old . ' &rarr; ' . $new . '</span>';
                        } else {
                            $details_html = '<span>Relation ' . $rel . '</span>';
                        }
                    }
                }

                // 4) Fallback: gekürztes JSON (ausklappbar)
                if ($details_html === '') {
                    $short = $meta_raw ? lsttraining_trim_val($meta_raw, 200) : '';
                    if ($short !== '') {
                        $details_html = '<code>' . esc_html($short) . '</code>';
                        if ($meta_raw && $short !== $meta_raw) {
                            $details_html .= ' <details><summary>mehr</summary><code>' . esc_html($meta_raw) . '</code></details>';
                        }
                    }
                }
                ?>
                <tr>
                    <td><?php echo lsttraining_fmt_ts($r['ts']); ?></td>
                    <td style="text-align:right;"><?php echo $uid ?: ''; ?></td>
                    <td><?php echo esc_html($uname ? ($uname . ' (' . $ulog . ')') : ($ulog ?: '')); ?></td>
                    <td><?php echo esc_html($r['entity_type']); ?></td>
                    <td style="text-align:right;"><?php echo !empty($r['entity_id']) ? (int)$r['entity_id'] : ''; ?></td>
                    <td><?php echo esc_html($r['action']); ?></td>
                    <td><?php echo $details_html ?: ''; ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $prev = $paged > 1 ? lsttraining_build_url(['paged' => $paged - 1]) : '';
                $next = $paged < $pages ? lsttraining_build_url(['paged' => $paged + 1]) : '';
                ?>
                <span class="displaying-num"><?php echo (int)$total; ?> <?php esc_html_e('Einträge', 'lsttraining'); ?></span>
                <span class="pagination-links">
                    <?php if ($prev): ?>
                        <a class="prev-page button" href="<?php echo $prev; ?>">&laquo;</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                    <?php endif; ?>
                    <span class="paging-input"><?php printf('%d / %d', (int)$paged, (int)$pages); ?></span>
                    <?php if ($next): ?>
                        <a class="next-page button" href="<?php echo $next; ?>">&raquo;</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>
