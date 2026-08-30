<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('lsttraining_fahrzeuge_column_exists')) {
    function lsttraining_fahrzeuge_column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?'
            );
            $st->execute([$table, $column]);
            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('lsttraining_fahrzeuge_table_exists')) {
    function lsttraining_fahrzeuge_table_exists(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?'
            );
            $st->execute([$table]);
            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('lsttraining_fahrzeuge_ensure_signal_lights_column')) {
    function lsttraining_fahrzeuge_ensure_signal_lights_column(PDO $pdo): bool {
        if (lsttraining_fahrzeuge_column_exists($pdo, 'fahrzeuge', 'signal_lights_json')) {
            return true;
        }
        try {
            $pdo->exec('ALTER TABLE fahrzeuge ADD COLUMN signal_lights_json LONGTEXT NULL AFTER bild_datei');
            return true;
        } catch (Throwable $e) {
            error_log('[LSTtraining][fahrzeuge_signal_lights_column] ' . $e->getMessage());
            return false;
        }
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

if (!function_exists('lsttraining_user_can_manage_fahrzeug_wache')) {
    function lsttraining_user_can_manage_fahrzeug_wache(PDO $pdo, int $wache_id): bool {
        if ($wache_id <= 0) {
            return false;
        }
        if (lsttraining_user_can_global_area('fahrzeuge')) {
            return true;
        }

        $stmt = $pdo->prepare('SELECT leitstelle_id FROM wache_leitstellen WHERE wache_id = ?');
        $stmt->execute([$wache_id]);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $leitstelle_id) {
            if (lsttraining_user_can('fahrzeuge', (int) $leitstelle_id)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('lsttraining_user_can_manage_fahrzeug_id')) {
    function lsttraining_user_can_manage_fahrzeug_id(PDO $pdo, int $fahrzeug_id): bool {
        if ($fahrzeug_id <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT wache_id FROM fahrzeuge WHERE id = ? LIMIT 1');
        $stmt->execute([$fahrzeug_id]);
        return lsttraining_user_can_manage_fahrzeug_wache($pdo, (int) $stmt->fetchColumn());
    }
}

if (!function_exists('lsttraining_fahrzeuge_thumb_url')) {
    function lsttraining_fahrzeuge_thumb_url($image): string {
        $image = trim((string) $image);
        if ($image === '') {
            return '';
        }
        $site_scheme = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME);
        $target_scheme = (is_ssl() || $site_scheme === 'https') ? 'https' : null;
        if (preg_match('#^https?://#i', $image)) {
            return $target_scheme ? set_url_scheme($image, $target_scheme) : $image;
        }
        if (strpos($image, '//') === 0) {
            return is_ssl() ? 'https:' . $image : 'http:' . $image;
        }
        if ($image[0] === '/') {
            $url = site_url($image);
            return $target_scheme ? set_url_scheme($url, $target_scheme) : $url;
        }
        $url = plugins_url(ltrim($image, '/'), dirname(dirname(__FILE__)));
        return $target_scheme ? set_url_scheme($url, $target_scheme) : $url;
    }
}

if (!function_exists('lsttraining_fahrzeuge_sort_link_ajax_html')) {
    function lsttraining_fahrzeuge_sort_link_ajax_html(string $label, string $key, array $state): string {
        $order = ($state['orderby'] === $key && $state['order'] === 'asc') ? 'desc' : 'asc';
        $qs = $state['query'];
        $qs['orderby'] = $key;
        $qs['order'] = $order;
        $url = add_query_arg($qs, admin_url('admin.php'));
        $arrow = ($state['orderby'] === $key) ? ($state['order'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . esc_url($url) . '" class="sort-link" data-key="' . esc_attr($key) . '">' . esc_html($label . $arrow) . '</a>';
    }
}

if (!function_exists('lsttraining_fahrzeuge_filter_state')) {
    function lsttraining_fahrzeuge_filter_state(PDO $pdo, array $request): array {
        $s = isset($request['s']) ? trim(sanitize_text_field(wp_unslash((string) $request['s']))) : '';
        $orderby = isset($request['orderby']) ? strtolower(sanitize_key((string) $request['orderby'])) : 'wache';
        $order = isset($request['order']) ? strtolower(sanitize_key((string) $request['order'])) : 'asc';
        $paged = isset($request['paged']) ? max(1, intval($request['paged'])) : 1;
        $per_page = isset($request['per_page']) ? max(10, min(200, intval($request['per_page']))) : 50;
        $land = isset($request['land']) ? trim(sanitize_text_field(wp_unslash((string) $request['land']))) : '';
        $bundesland = isset($request['bundesland']) ? trim(sanitize_text_field(wp_unslash((string) $request['bundesland']))) : '';
        $leitstelle_id = (isset($request['leitstelle_id']) && $request['leitstelle_id'] !== '') ? max(0, intval($request['leitstelle_id'])) : 0;
        $neben_id = (isset($request['neben_id']) && $request['neben_id'] !== '') ? max(0, intval($request['neben_id'])) : 0;
        $wache_id = (isset($request['wache_id']) && $request['wache_id'] !== '') ? max(0, intval($request['wache_id'])) : 0;

        $has_wachen_bundesland = lsttraining_fahrzeuge_column_exists($pdo, 'wachen', 'bundesland');
        $has_wachen_land = lsttraining_fahrzeuge_column_exists($pdo, 'wachen', 'land');
        $has_wachen_leitstelle = lsttraining_fahrzeuge_column_exists($pdo, 'wachen', 'leitstelle_id');
        $has_wachen_neben = lsttraining_fahrzeuge_column_exists($pdo, 'wachen', 'nebenleitstelle_id');
        $has_leitstellen_tbl = lsttraining_fahrzeuge_table_exists($pdo, 'leitstellen');
        $has_ls_bundesland = $has_leitstellen_tbl ? lsttraining_fahrzeuge_column_exists($pdo, 'leitstellen', 'bundesland') : false;
        $has_ls_land = $has_leitstellen_tbl ? lsttraining_fahrzeuge_column_exists($pdo, 'leitstellen', 'land') : false;
        $map_ls_tbl = lsttraining_fahrzeuge_table_exists($pdo, 'wache_leitstellen') ? 'wache_leitstellen'
            : (lsttraining_fahrzeuge_table_exists($pdo, 'wachen_leitstellen') ? 'wachen_leitstellen'
            : (lsttraining_fahrzeuge_table_exists($pdo, 'leitstellen_wachen') ? 'leitstellen_wachen' : ''));
        $map_neb_tbl = lsttraining_fahrzeuge_table_exists($pdo, 'wache_nebenleitstellen') ? 'wache_nebenleitstellen'
            : (lsttraining_fahrzeuge_table_exists($pdo, 'wachen_nebenstellen') ? 'wachen_nebenstellen'
            : (lsttraining_fahrzeuge_table_exists($pdo, 'nebenstellen_wachen') ? 'nebenstellen_wachen' : ''));

        $can_global = current_user_can('manage_options') || lsttraining_user_can_global_area('fahrzeuge');
        $allowed = $can_global ? [] : lsttraining_user_allowed_leitstellen('fahrzeuge');
        if (!$can_global && $leitstelle_id > 0 && !in_array($leitstelle_id, $allowed, true)) {
            throw new RuntimeException('Keine Berechtigung.');
        }

        $allowed_orderby = [
            'rufname' => 'f.rufname',
            'wache' => 'w.name',
            'id' => 'f.id',
        ];
        $orderby_sql = $allowed_orderby[$orderby] ?? $allowed_orderby['wache'];
        $order_sql = ($order === 'desc') ? 'DESC' : 'ASC';
        if (!isset($allowed_orderby[$orderby])) {
            $orderby = 'wache';
        }

        $where = [];
        $params = [];
        $joins = [];
        $joined_wls = false;
        $joined_wnb = false;
        $joined_ls = false;

        if ($wache_id > 0) {
            $where[] = 'f.wache_id = :wid';
            $params[':wid'] = $wache_id;
            if (!isset($request['orderby']) || $request['orderby'] === '') {
                $orderby_sql = 'f.rufname';
            }
            if (!$can_global) {
                $st_perm = $pdo->prepare('SELECT leitstelle_id FROM wache_leitstellen WHERE wache_id = ?');
                $st_perm->execute([$wache_id]);
                $wache_ls = array_map('intval', $st_perm->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if (!array_intersect($wache_ls, $allowed)) {
                    throw new RuntimeException('Keine Berechtigung.');
                }
            }
        }

        if ($s !== '') {
            $search_parts = ['f.rufname LIKE :q_rufname', 'w.name LIKE :q_wache'];
            if ($has_wachen_bundesland) {
                $search_parts[] = 'w.bundesland LIKE :q_bundesland';
            }
            if ($has_wachen_land) {
                $search_parts[] = 'w.land LIKE :q_land';
            }
            $where[] = '(' . implode(' OR ', $search_parts) . ')';
            $params[':q_rufname'] = '%' . $s . '%';
            $params[':q_wache'] = '%' . $s . '%';
            if ($has_wachen_bundesland) {
                $params[':q_bundesland'] = '%' . $s . '%';
            }
            if ($has_wachen_land) {
                $params[':q_land'] = '%' . $s . '%';
            }
        }
        if ($leitstelle_id > 0) {
            if ($has_wachen_leitstelle) {
                $where[] = 'w.leitstelle_id = :lsid';
            } elseif ($map_ls_tbl) {
                $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
                $joined_wls = true;
                $where[] = 'wls.leitstelle_id = :lsid';
            }
            $params[':lsid'] = $leitstelle_id;
        }
        if ($neben_id > 0) {
            if ($has_wachen_neben) {
                $where[] = 'w.nebenleitstelle_id = :nbid';
            } elseif ($map_neb_tbl) {
                $joins[] = "JOIN {$map_neb_tbl} AS wnb ON wnb.wache_id = w.id";
                $joined_wnb = true;
                $where[] = 'wnb.nebenleitstelle_id = :nbid';
            }
            $params[':nbid'] = $neben_id;
        }
        if ($land !== '') {
            if ($has_wachen_land) {
                $where[] = 'w.land = :land';
                $params[':land'] = $land;
            } elseif ($has_ls_land) {
                $can_join_ls_for_land = false;
                if ($has_wachen_leitstelle) {
                    if (!$joined_ls) {
                        $joins[] = "JOIN leitstellen AS ls ON ls.id = w.leitstelle_id";
                        $joined_ls = true;
                    }
                    $can_join_ls_for_land = true;
                } elseif ($map_ls_tbl) {
                    if (!$joined_wls) {
                        $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
                        $joined_wls = true;
                    }
                    if (!$joined_ls) {
                        $joins[] = "JOIN leitstellen AS ls ON ls.id = wls.leitstelle_id";
                        $joined_ls = true;
                    }
                    $can_join_ls_for_land = true;
                }
                if ($can_join_ls_for_land) {
                    $where[] = 'ls.land = :land';
                    $params[':land'] = $land;
                }
            }
        }
        if ($bundesland !== '') {
            if ($has_wachen_bundesland) {
                $where[] = 'w.bundesland = :bl';
                $params[':bl'] = $bundesland;
            } elseif ($has_ls_bundesland) {
                $can_join_ls_for_bundesland = false;
                if ($has_wachen_leitstelle) {
                    $joins[] = "JOIN leitstellen AS ls ON ls.id = w.leitstelle_id";
                    $joined_ls = true;
                    $can_join_ls_for_bundesland = true;
                } elseif ($map_ls_tbl) {
                    if (!$joined_wls) {
                        $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
                        $joined_wls = true;
                    }
                    if (!$joined_ls) {
                        $joins[] = "JOIN leitstellen AS ls ON ls.id = wls.leitstelle_id";
                    }
                    $can_join_ls_for_bundesland = true;
                }
                if ($can_join_ls_for_bundesland) {
                    $where[] = 'ls.bundesland = :bl';
                    $params[':bl'] = $bundesland;
                }
            }
        }
        if (!$can_global) {
            if ($allowed && $map_ls_tbl) {
                if (!$joined_wls) {
                    $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
                    $joined_wls = true;
                }
                $in_keys = [];
                foreach ($allowed as $idx => $allowed_id) {
                    $key = ':allowed_lsid_' . $idx;
                    $in_keys[] = $key;
                    $params[$key] = (int) $allowed_id;
                }
                $where[] = 'wls.leitstelle_id IN (' . implode(',', $in_keys) . ')';
            } else {
                $where[] = '0 = 1';
            }
        }

        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $joins_sql = $joins ? (' ' . implode(' ', $joins) . ' ') : ' ';

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.id) FROM fahrzeuge f JOIN wachen w ON w.id = f.wache_id {$joins_sql} {$where_sql}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $max_pages = max(1, (int) ceil($total / $per_page));
        $paged = min($paged, $max_pages);
        $offset = ($paged - 1) * $per_page;

        $has_image_url = lsttraining_fahrzeuge_column_exists($pdo, 'fahrzeuge', 'image_url');
        $has_image_id = lsttraining_fahrzeuge_column_exists($pdo, 'fahrzeuge', 'image_id');
        $default_id = (int) get_option('lsttraining_default_fahrzeug_image_id', 0);
        $default_thumb = $default_id ? wp_get_attachment_image_url($default_id, 'thumbnail') : '';
        if (!$default_thumb) {
            $default_thumb = plugins_url('img/fahrzeug/default.png', dirname(dirname(__FILE__)));
        }

        $sql = "SELECT DISTINCT f.id, f.rufname, f.fahrzeugtyp, f.fms_status, f.bild_datei," .
            ($has_image_url ? " f.image_url," : "") .
            ($has_image_id ? " f.image_id," : "") . "
            w.name AS wache_name" . ($has_wachen_bundesland ? ", w.bundesland" : "") . "
            FROM fahrzeuge f
            JOIN wachen w ON w.id = f.wache_id
            {$joins_sql}
            {$where_sql}
            ORDER BY {$orderby_sql} {$order_sql}, f.id ASC
            LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $thumb = lsttraining_fahrzeuge_thumb_url($row['bild_datei'] ?? '');
            if ($thumb === '' && $has_image_id && !empty($row['image_id'])) {
                $thumb = wp_get_attachment_image_url((int) $row['image_id'], 'thumbnail') ?: '';
            }
            if ($thumb === '' && $has_image_url && !empty($row['image_url'])) {
                $thumb = trim((string) $row['image_url']);
            }
            $row['__thumb'] = $thumb ?: $default_thumb;
        }
        unset($row);

        $query = [
            'page' => 'lsttraining_fahrzeuge',
            's' => $s,
            'land' => $land,
            'bundesland' => $bundesland,
            'leitstelle_id' => $leitstelle_id ?: '',
            'neben_id' => $neben_id ?: '',
            'per_page' => $per_page,
            'orderby' => $orderby,
            'order' => $order,
        ];
        if ($wache_id > 0) {
            $query['wache_id'] = $wache_id;
        }
        $query = array_filter($query, static function ($value) {
            return $value !== '' && $value !== null;
        });

        return compact('s', 'land', 'bundesland', 'leitstelle_id', 'neben_id', 'wache_id', 'orderby', 'order', 'paged', 'per_page', 'total', 'max_pages', 'rows', 'has_wachen_bundesland', 'query');
    }
}

if (!function_exists('lsttraining_fahrzeuge_results_html')) {
    function lsttraining_fahrzeuge_results_html(array $state): string {
        ob_start();
        ?>
        <p class="lst-fahrzeuge-summary">
            <strong><?php echo number_format_i18n((int) $state['total']); ?></strong> Fahrzeuge gefunden.
            <?php if ($state['s'] !== ''): ?>
                Suche nach <strong>„<?php echo esc_html($state['s']); ?>“</strong> in Rufname oder Wachenort.
            <?php endif; ?>
            Seite <?php echo (int) $state['paged']; ?> von <?php echo (int) $state['max_pages']; ?>.
        </p>
        <table class="widefat fixed striped" id="fahrzeuge-table">
            <thead>
            <tr>
                <th style="width:110px;"><?php echo lsttraining_fahrzeuge_sort_link_ajax_html('ID', 'id', $state); ?></th>
                <th style="min-width:240px;"><?php echo lsttraining_fahrzeuge_sort_link_ajax_html('Rufname (Funkname)', 'rufname', $state); ?></th>
                <th style="min-width:220px;"><?php echo lsttraining_fahrzeuge_sort_link_ajax_html('Wache', 'wache', $state); ?></th>
                <th style="min-width:180px;">Fahrzeugtyp</th>
                <th style="min-width:120px;">FMS</th>
                <th style="width:90px;">Bild</th>
                <?php if ($state['has_wachen_bundesland']): ?><th style="min-width:180px;">Bundesland</th><?php endif; ?>
                <th style="width:120px;">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php $colspan = $state['has_wachen_bundesland'] ? 8 : 7; ?>
            <?php if (empty($state['rows'])): ?>
                <tr><td colspan="<?php echo (int) $colspan; ?>">Keine Datensätze.</td></tr>
            <?php else: foreach ($state['rows'] as $row): ?>
                <?php
                $alt = 'Fahrzeug ' . (string) ($row['rufname'] ?? '');
                if (!empty($row['fahrzeugtyp'])) { $alt .= ', Typ ' . (string) $row['fahrzeugtyp']; }
                if (!empty($row['wache_name'])) { $alt .= ', Wache ' . (string) $row['wache_name']; }
                ?>
                <tr data-id="<?php echo (int) $row['id']; ?>">
                    <td>#<?php echo (int) $row['id']; ?></td>
                    <td><?php echo esc_html($row['rufname']); ?></td>
                    <td><?php echo esc_html($row['wache_name']); ?></td>
                    <td><?php echo esc_html($row['fahrzeugtyp']); ?></td>
                    <td><?php echo esc_html($row['fms_status']); ?></td>
                    <td style="text-align:center;"><img src="<?php echo esc_url($row['__thumb']); ?>" alt="<?php echo esc_attr($alt); ?>" class="lst-fahrzeug-thumb" loading="lazy" /></td>
                    <?php if ($state['has_wachen_bundesland']): ?><td><?php echo isset($row['bundesland']) ? esc_html($row['bundesland']) : ''; ?></td><?php endif; ?>
                    <td><a href="#" class="button btn-edit-fahrzeug" data-id="<?php echo (int) $row['id']; ?>">Bearbeiten</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php if ((int) $state['max_pages'] > 1): ?>
            <?php
            $base_qs = $state['query'];
            unset($base_qs['paged']);
            $base_url = add_query_arg($base_qs, admin_url('admin.php'));
            $prev_url = ((int) $state['paged'] > 1) ? add_query_arg('paged', (int) $state['paged'] - 1, $base_url) : '';
            $next_url = ((int) $state['paged'] < (int) $state['max_pages']) ? add_query_arg('paged', (int) $state['paged'] + 1, $base_url) : '';
            ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format_i18n((int) $state['total']); ?> Einträge</span>
                    <span class="pagination-links">
                        <a class="button<?php echo (int) $state['paged'] <= 1 ? ' disabled' : ''; ?>" href="<?php echo esc_url($prev_url); ?>">«</a>
                        <span class="paging-input">Seite <input class="current-page" type="text" name="paged" value="<?php echo (int) $state['paged']; ?>" size="2"> von <span class="total-pages"><?php echo (int) $state['max_pages']; ?></span></span>
                        <a class="button<?php echo (int) $state['paged'] >= (int) $state['max_pages'] ? ' disabled' : ''; ?>" href="<?php echo esc_url($next_url); ?>">»</a>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        <p style="margin-top:14px"></p>
        <?php
        return trim(ob_get_clean());
    }
}

add_action('wp_ajax_lsttraining_filter_fahrzeuge', function () {
    if (!current_user_can('read') || !lsttraining_user_can('fahrzeuge')) {
        status_header(403);
        wp_send_json_error(['msg' => 'Keine Berechtigung.']);
    }
    if (!check_ajax_referer('lst_fahrzeuge_nonce', 'nonce', false)) {
        status_header(403);
        wp_send_json_error(['msg' => 'Nonce ungültig.']);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        status_header(500);
        wp_send_json_error(['msg' => 'DB-Verbindung fehlgeschlagen.']);
    }
    if (function_exists('lsttraining_permissions_ensure_schema')) {
        lsttraining_permissions_ensure_schema($pdo);
    }

    try {
        $state = lsttraining_fahrzeuge_filter_state($pdo, $_GET);
        $url_qs = $state['query'];
        if ((int) $state['paged'] > 1) {
            $url_qs['paged'] = (int) $state['paged'];
        }
        $has_filter = (
            $state['s'] !== ''
            || $state['land'] !== ''
            || $state['bundesland'] !== ''
            || (int) $state['leitstelle_id'] > 0
            || (int) $state['neben_id'] > 0
            || (isset($_GET['per_page']) && (int) $_GET['per_page'] !== 50)
        );
        $reset_qs = ['page' => 'lsttraining_fahrzeuge'];
        if ((int) $state['wache_id'] > 0) {
            $reset_qs['wache_id'] = (int) $state['wache_id'];
        }

        wp_send_json_success([
            'html' => lsttraining_fahrzeuge_results_html($state),
            'url' => add_query_arg($url_qs, admin_url('admin.php')),
            'reset_url' => add_query_arg($reset_qs, admin_url('admin.php')),
            'has_filter' => $has_filter,
            'total' => (int) $state['total'],
            'paged' => (int) $state['paged'],
            'max_pages' => (int) $state['max_pages'],
        ]);
    } catch (Throwable $e) {
        status_header($e->getMessage() === 'Keine Berechtigung.' ? 403 : 500);
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
});

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
        if (!lsttraining_user_can_manage_fahrzeug_id($pdo, $id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
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
        if (!lsttraining_user_can_manage_fahrzeug_wache($pdo, $wache_id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
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
    $has_signal_lights = lsttraining_fahrzeuge_ensure_signal_lights_column($pdo);
    $signal_lights = $has_signal_lights
        ? lsttraining_fahrzeuge_normalize_signal_lights_json($_POST['signal_lights_json'] ?? '')
        : '';

    if ($wache_id <= 0 || $rufname === '' || $typ === '') {
        status_header(400);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Wache, Rufname und Fahrzeugtyp sind Pflichtfelder.']]);
    }

    if (!lsttraining_user_can_manage_fahrzeug_wache($pdo, $wache_id)) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
    }
    if ($id > 0 && !lsttraining_user_can_manage_fahrzeug_id($pdo, $id)) {
        status_header(403);
        wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
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
        if (!lsttraining_user_can_manage_fahrzeug_id($pdo, $id)) {
            status_header(403);
            wp_send_json(['success' => false, 'data' => ['msg' => 'Keine Berechtigung.']]);
        }

        $st = $pdo->prepare("DELETE FROM fahrzeuge WHERE id = ?");
        $st->execute([$id]);
        wp_send_json(['success' => true]);
    } catch (Throwable $e) {
        status_header(500);
        wp_send_json(['success' => false, 'data' => ['msg' => $e->getMessage()]]);
    }
});

