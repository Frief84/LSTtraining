<?php
/**
 * includes/fahrzeuge.php
 * Fahrzeuge-Liste mit Filtern (Suche, Bundesland, Leitstelle, Nebenleitstelle, Wache)
 * und Aktionen (Bearbeiten / Neu). Filter wirken serverseitig.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path( __FILE__ ) . 'db.php';
require_once plugin_dir_path( __FILE__ ) . 'permissions.php';

if ( ! lsttraining_user_can( 'fahrzeuge' ) ) {
    wp_die( 'Keine Berechtigung.' );
}

$pdo = lsttraining_get_connection();
if ( ! $pdo ) { wp_die( 'Keine Datenbankverbindung.' ); }
lsttraining_permissions_ensure_schema($pdo);

/* -----------------------------------------------------------
 * Helper: Tabellen-/Spalten-Existenz prüfen (robust)
 * ----------------------------------------------------------- */
if ( ! function_exists('lst_col_exists') ) {
    function lst_col_exists(PDO $pdo, $table, $column) {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?'
            );
            $st->execute([(string) $table, (string) $column]);
            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
}
if ( ! function_exists('lst_tbl_exists') ) {
    function lst_tbl_exists(PDO $pdo, $table) {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?'
            );
            $st->execute([(string) $table]);
            return (int) $st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
}

/* -----------------------------------------------------------
 * Request-Parameter
 * ----------------------------------------------------------- */
$s        = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['s']))) : '';
$orderby  = isset($_GET['orderby']) ? strtolower((string)$_GET['orderby']) : 'wache';
$order    = isset($_GET['order']) ? strtolower((string)$_GET['order']) : 'asc';
$paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 50;

$land          = isset($_GET['land']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['land']))) : '';
$bundesland    = isset($_GET['bundesland']) ? trim((string)$_GET['bundesland']) : '';
$leitstelle_id = (isset($_GET['leitstelle_id']) && $_GET['leitstelle_id'] !== '') ? max(0, intval($_GET['leitstelle_id'])) : 0;
$neben_id      = (isset($_GET['neben_id'])      && $_GET['neben_id'] !== '')      ? max(0, intval($_GET['neben_id']))      : 0;

// Wachen-Kontext (wichtig für "Fahrzeuge bearbeiten" aus Wachen-Modal)
$wache_id = (isset($_GET['wache_id']) && $_GET['wache_id'] !== '') ? max(0, intval($_GET['wache_id'])) : 0;

/* -----------------------------------------------------------
 * Spalten/Relationen erkennen
 * ----------------------------------------------------------- */
$has_wachen_bundesland = lst_col_exists($pdo, 'wachen', 'bundesland');
$has_wachen_land       = lst_col_exists($pdo, 'wachen', 'land');
$has_wachen_leitstelle = lst_col_exists($pdo, 'wachen', 'leitstelle_id');
$has_wachen_neben      = lst_col_exists($pdo, 'wachen', 'nebenleitstelle_id');

$has_leitstellen_tbl   = lst_tbl_exists($pdo, 'leitstellen');
$has_ls_bundesland     = $has_leitstellen_tbl ? lst_col_exists($pdo, 'leitstellen', 'bundesland') : false;
$has_ls_land           = $has_leitstellen_tbl ? lst_col_exists($pdo, 'leitstellen', 'land') : false;

$map_ls_tbl  = lst_tbl_exists($pdo, 'wache_leitstellen')   ? 'wache_leitstellen'
            : (lst_tbl_exists($pdo, 'wachen_leitstellen')  ? 'wachen_leitstellen'
            : (lst_tbl_exists($pdo, 'leitstellen_wachen')  ? 'leitstellen_wachen' : ''));
$map_neb_tbl = lst_tbl_exists($pdo, 'wache_nebenleitstellen') ? 'wache_nebenleitstellen'
            : (lst_tbl_exists($pdo, 'wachen_nebenstellen')    ? 'wachen_nebenstellen'
            : (lst_tbl_exists($pdo, 'nebenstellen_wachen')    ? 'nebenstellen_wachen' : ''));

$can_global_fahrzeuge = current_user_can('manage_options') || lsttraining_user_can_global_area('fahrzeuge');
$allowed_fahrzeuge_leitstellen = current_user_can('manage_options') || $can_global_fahrzeuge
    ? []
    : lsttraining_user_allowed_leitstellen('fahrzeuge');

if (!$can_global_fahrzeuge && $leitstelle_id > 0 && !in_array($leitstelle_id, $allowed_fahrzeuge_leitstellen, true)) {
    wp_die('Keine Berechtigung.');
}

if ($wache_id > 0 && !$can_global_fahrzeuge) {
    $st_perm = $pdo->prepare('SELECT leitstelle_id FROM wache_leitstellen WHERE wache_id = ?');
    $st_perm->execute([$wache_id]);
    $wache_ls = array_map('intval', $st_perm->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!array_intersect($wache_ls, $allowed_fahrzeuge_leitstellen)) {
        wp_die('Keine Berechtigung.');
    }
}

/* -----------------------------------------------------------
 * Optionen für Filter-Dropdowns laden
 * ----------------------------------------------------------- */
$plugin_base = trailingslashit( plugin_dir_path( dirname(__FILE__) ) ); // Plugin-Root

// Bundesländer (aus JSON)
$bundes_opts = [];
$bl_path = $plugin_base . 'data/bundeslaender.json';
if ( is_readable($bl_path) ) {
    $tmp = json_decode(file_get_contents($bl_path), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
        $bundes_opts = $tmp;
    }
}

// Leitstellen
$leitstellen_opts = [];
try {
    if ($has_leitstellen_tbl) {
        if (!$can_global_fahrzeuge) {
            if ($allowed_fahrzeuge_leitstellen) {
                $st = $pdo->prepare('SELECT id, name FROM leitstellen WHERE id IN (' . implode(',', array_fill(0, count($allowed_fahrzeuge_leitstellen), '?')) . ' ) ORDER BY name');
                $st->execute($allowed_fahrzeuge_leitstellen);
            } else {
                $st = false;
            }
        } else {
            $st = $pdo->query("SELECT id, name FROM leitstellen ORDER BY name");
        }
        if ($st) { $leitstellen_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
    }
    if (!$leitstellen_opts && $has_leitstellen_tbl && $map_ls_tbl) {
        $st = $pdo->query(
            "SELECT DISTINCT l.id, l.name
               FROM leitstellen l
               JOIN {$map_ls_tbl} wl ON wl.leitstelle_id = l.id
               JOIN fahrzeuge f ON f.wache_id = wl.wache_id
           ORDER BY l.name"
        );
        if ($st) { $leitstellen_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
    }
} catch (Throwable $e) {}

// Nebenleitstellen
$neben_opts = [];
try {
    if (lst_tbl_exists($pdo, 'nebenleitstellen')) {
        if (!$can_global_fahrzeuge) {
            if ($allowed_fahrzeuge_leitstellen && lst_tbl_exists($pdo, 'leitstelle_nebenleitstellen')) {
                $st = $pdo->prepare(
                    'SELECT DISTINCT n.id, n.name
                       FROM nebenleitstellen n
                       JOIN leitstelle_nebenleitstellen ln ON ln.nebenleitstelle_id = n.id
                      WHERE ln.leitstelle_id IN (' . implode(',', array_fill(0, count($allowed_fahrzeuge_leitstellen), '?')) . ')
                   ORDER BY n.name'
                );
                $st->execute($allowed_fahrzeuge_leitstellen);
                $neben_opts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            if ($allowed_fahrzeuge_leitstellen && $map_ls_tbl && $map_neb_tbl) {
                $st = $pdo->prepare(
                    "SELECT DISTINCT n.id, n.name
                       FROM nebenleitstellen n
                       JOIN {$map_neb_tbl} wnb ON wnb.nebenleitstelle_id = n.id
                       JOIN {$map_ls_tbl} wls ON wls.wache_id = wnb.wache_id
                      WHERE wls.leitstelle_id IN (" . implode(',', array_fill(0, count($allowed_fahrzeuge_leitstellen), '?')) . ")
                   ORDER BY n.name"
                );
                $st->execute($allowed_fahrzeuge_leitstellen);
                $neben_opts = array_merge($neben_opts, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }
            if (!$neben_opts) {
                $st = $pdo->query("SELECT id, name FROM nebenleitstellen ORDER BY name");
                if ($st) { $neben_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
            }
        } else {
            $st = $pdo->query("SELECT id, name FROM nebenleitstellen ORDER BY name");
            if ($st) { $neben_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
        }
        $seen_neben = [];
        $neben_opts = array_values(array_filter($neben_opts, static function ($row) use (&$seen_neben) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($seen_neben[$id])) { return false; }
            $seen_neben[$id] = true;
            return true;
        }));
        usort($neben_opts, static function ($a, $b) {
            return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    }
    if (!$neben_opts && $map_neb_tbl) {
        $st = $pdo->query(
            "SELECT DISTINCT n.id, n.name
               FROM nebenleitstellen n
               JOIN {$map_neb_tbl} wn ON wn.nebenleitstelle_id = n.id
               JOIN fahrzeuge f ON f.wache_id = wn.wache_id
           ORDER BY n.name"
        );
        if ($st) { $neben_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
    }
} catch (Throwable $e) {}

/* -----------------------------------------------------------
 * Wachenname laden (Kontext-Headline)
 * ----------------------------------------------------------- */
$wache_name = '';
if ($wache_id > 0) {
    if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'wache', $wache_id)) {
        wp_die('Keine Berechtigung für diese Wache.');
    }
    try {
        $st = $pdo->prepare("SELECT name FROM wachen WHERE id = :wid LIMIT 1");
        $st->execute([':wid' => $wache_id]);
        $wache_name = (string) $st->fetchColumn();
    } catch (Throwable $e) {
        $wache_name = '';
    }
}

/* -----------------------------------------------------------
 * Default Thumbnail (immer anzeigen)
 * Pfad: plugin-root/img/fahrzeug/default.png
 * ----------------------------------------------------------- */
$default_id = (int) get_option('lsttraining_default_fahrzeug_image_id', 0);
$default_thumb = $default_id ? wp_get_attachment_image_url($default_id, 'thumbnail') : '';

if (!$default_thumb) {
    $default_thumb = plugins_url('img/fahrzeug/default.png', dirname(__FILE__));
}

if ( ! function_exists('lsttraining_fahrzeug_thumb_url') ) {
    function lsttraining_fahrzeug_thumb_url($image) {
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
        $url = plugins_url(ltrim($image, '/'), dirname(__FILE__));
        return $target_scheme ? set_url_scheme($url, $target_scheme) : $url;
    }
}

/* -----------------------------------------------------------
 * Sortierung
 * ----------------------------------------------------------- */
$allowed_orderby = [
    'rufname' => 'f.rufname',
    'wache'   => 'w.name',
    'id'      => 'f.id',
];
$orderby_sql = $allowed_orderby[ $orderby ] ?? $allowed_orderby['wache'];
$order_sql   = ($order === 'desc') ? 'DESC' : 'ASC';

/* -----------------------------------------------------------
 * WHERE / JOIN dynamisch aufbauen
 * ----------------------------------------------------------- */
$where  = [];
$params = [];
$joins  = [];

$joined_wls = false;
$joined_wnb = false;
$joined_ls  = false;

// Kontextfilter Wache (muss ganz oben rein, weil er die Ergebnismenge begrenzt)
if ($wache_id > 0) {
    $where[] = 'f.wache_id = :wid';
    $params[':wid'] = $wache_id;

    if (!isset($_GET['orderby']) || $_GET['orderby'] === '') {
        $orderby_sql = 'f.rufname';
    }
}

// Suche
if ($s !== '') {
    // Der Standort einer Wache wird in diesem Schema über ihren Namen geführt.
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

// Leitstelle
if ($leitstelle_id > 0) {
    if (!lsttraining_user_can('fahrzeuge', $leitstelle_id)) {
        wp_die('Keine Berechtigung für diese Leitstelle.');
    }
    if ($has_wachen_leitstelle) {
        $where[] = 'w.leitstelle_id = :lsid';
        $params[':lsid'] = $leitstelle_id;
    } elseif ($map_ls_tbl) {
        if (!$joined_wls) {
            $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
            $joined_wls = true;
        }
        $where[] = 'wls.leitstelle_id = :lsid';
        $params[':lsid'] = $leitstelle_id;
    }
}

// Nebenleitstelle
if ($neben_id > 0) {
    if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'nebenstelle', $neben_id)) {
        wp_die('Keine Berechtigung für diese Nebenleitstelle.');
    }
    if ($has_wachen_neben) {
        $where[] = 'w.nebenleitstelle_id = :nbid';
        $params[':nbid'] = $neben_id;
    } elseif ($map_neb_tbl) {
        if (!$joined_wnb) {
            $joins[] = "JOIN {$map_neb_tbl} AS wnb ON wnb.wache_id = w.id";
            $joined_wnb = true;
        }
        $where[] = 'wnb.nebenleitstelle_id = :nbid';
        $params[':nbid'] = $neben_id;
    }
}

if (!current_user_can('manage_options') && !$can_global_fahrzeuge) {
    $scope_ids = $allowed_fahrzeuge_leitstellen;
    if (!$scope_ids) {
        wp_die('Für diesen Benutzer ist keine Leitstelle freigegeben.');
    }
    $scope_sql = implode(',', array_map('intval', $scope_ids));
    $where[] = "(EXISTS (SELECT 1 FROM wache_leitstellen swl WHERE swl.wache_id = w.id AND swl.leitstelle_id IN ($scope_sql)) OR EXISTS (SELECT 1 FROM wache_nebenleitstellen swn JOIN leitstelle_nebenleitstellen sln ON sln.nebenleitstelle_id = swn.nebenleitstelle_id WHERE swn.wache_id = w.id AND sln.leitstelle_id IN ($scope_sql)))";
}

// Bundesland
if ($land !== '') {
    if ($has_wachen_land) {
        $where[] = 'w.land = :land';
        $params[':land'] = $land;
    } elseif ($has_ls_land) {
        if ($has_wachen_leitstelle) {
            if (!$joined_ls) {
                $joins[] = "JOIN leitstellen AS ls ON ls.id = w.leitstelle_id";
                $joined_ls = true;
            }
            $where[] = 'ls.land = :land';
            $params[':land'] = $land;
        } elseif ($map_ls_tbl) {
            if (!$joined_wls) {
                $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
                $joined_wls = true;
            }
            if (!$joined_ls) {
                $joins[] = "JOIN leitstellen AS ls ON ls.id = wls.leitstelle_id";
                $joined_ls = true;
            }
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
        if ($has_wachen_leitstelle) {
            if (!$joined_ls) {
                $joins[] = "JOIN leitstellen AS ls ON ls.id = w.leitstelle_id";
                $joined_ls = true;
            }
            $where[] = 'ls.bundesland = :bl';
            $params[':bl'] = $bundesland;
        } elseif ($map_ls_tbl) {
            if (!$joined_wls) {
                $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
                $joined_wls = true;
            }
            if (!$joined_ls) {
                $joins[] = "JOIN leitstellen AS ls ON ls.id = wls.leitstelle_id";
                $joined_ls = true;
            }
            $where[] = 'ls.bundesland = :bl';
            $params[':bl'] = $bundesland;
        }
    }
}

if (!$can_global_fahrzeuge) {
    if ($allowed_fahrzeuge_leitstellen) {
        if (!$joined_wls && $map_ls_tbl) {
            $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id";
            $joined_wls = true;
        }
        if ($joined_wls) {
            $in_keys = [];
            foreach ($allowed_fahrzeuge_leitstellen as $idx => $allowed_id) {
                $key = ':allowed_lsid_' . $idx;
                $in_keys[] = $key;
                $params[$key] = (int) $allowed_id;
            }
            $where[] = 'wls.leitstelle_id IN (' . implode(',', $in_keys) . ')';
        } else {
            $where[] = '0 = 1';
        }
    } else {
        $where[] = '0 = 1';
    }
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$joins_sql = $joins ? (' ' . implode(' ', $joins) . ' ') : ' ';

/* -----------------------------------------------------------
 * Count
 * ----------------------------------------------------------- */
$sql_count = "SELECT COUNT(DISTINCT f.id)
              FROM fahrzeuge f
              JOIN wachen w ON w.id = f.wache_id
              {$joins_sql}
              {$where_sql}";
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$max_pages = max(1, (int)ceil($total / $per_page));
$paged = min($paged, $max_pages);
$offset = ($paged - 1) * $per_page;

/* -----------------------------------------------------------
 * Thumbnail-Spalte erkennen
 * Das aktuelle Editor-Feld bild_datei wird vom Fahrzeug-Modal direkt
 * gelesen und geschrieben und gehört zum Stammdatenschema. Ältere
 * Installationen können zusätzlich noch image_url oder image_id enthalten.
 * Unterstützt:
 * - fahrzeuge.bild_datei (URL oder plugin-relativer Pfad)
 * - fahrzeuge.image_url (direkte URL)
 * - fahrzeuge.image_id  (WP Attachment ID)
 * ----------------------------------------------------------- */
$has_fahrzeuge_image_url = lst_col_exists($pdo, 'fahrzeuge', 'image_url');
$has_fahrzeuge_image_id  = lst_col_exists($pdo, 'fahrzeuge', 'image_id');

/* -----------------------------------------------------------
 * Daten
 * ----------------------------------------------------------- */
$sql = "SELECT DISTINCT
            f.id,
            f.rufname,
            f.fahrzeugtyp,
            f.fms_status,
            f.bild_datei," .
            ($has_fahrzeuge_image_url ? " f.image_url," : "") .
            ($has_fahrzeuge_image_id  ? " f.image_id,"  : "") . "
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
$stmt->bindValue(':limit',  (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset,   PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------------------------
 * Rows: Thumb URL ableiten
 * - bild_datei -> gespeichertes aktuelles Fahrzeugbild
 * - image_id -> wp_get_attachment_image_url(..., 'thumbnail')
 * - image_url direkt nutzen
 * - sonst Default
 * ----------------------------------------------------------- */
foreach ($rows as &$r) {
    $thumb = lsttraining_fahrzeug_thumb_url($r['bild_datei'] ?? '');

    if ($has_fahrzeuge_image_id) {
        $img_id = isset($r['image_id']) ? (int)$r['image_id'] : 0;
        if ($thumb === '' && $img_id > 0) {
            $u = wp_get_attachment_image_url($img_id, 'thumbnail');
            if ($u) $thumb = $u;
        }
    }

    if ($thumb === '' && $has_fahrzeuge_image_url) {
        $u = isset($r['image_url']) ? trim((string)$r['image_url']) : '';
        if ($u !== '') $thumb = $u;
    }

    if ($thumb === '') $thumb = $default_thumb;

    $r['__thumb'] = $thumb;
}
unset($r);

/* -----------------------------------------------------------
 * Helper für Sort-Links (wache_id muss erhalten bleiben)
 * ----------------------------------------------------------- */
function lst_sort_link($label, $key, $current_key, $current_order) {
    $order = ($current_key === $key && $current_order === 'asc') ? 'desc' : 'asc';
    $qs = $_GET;
    $qs['orderby'] = $key;
    $qs['order'] = $order;

    $url = esc_url( add_query_arg($qs, admin_url('admin.php?page=lsttraining_fahrzeuge')) );
    $arrow = ($current_key === $key) ? ($current_order === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="'.$url.'" class="sort-link" data-key="'.esc_attr($key).'">'.esc_html($label.$arrow).'</a>';
}

?>
<div class="wrap">

  <h1>
    Fahrzeuge
    <?php if ($wache_id > 0): ?>
      <?php
        $back_url = admin_url('admin.php?page=lsttraining_leitstellen_wachen&open_wache_id=' . (int)$wache_id);
        $label = 'Wache #' . (int)$wache_id;
        if ($wache_name !== '') $label .= ' — ' . $wache_name;
      ?>
      <a href="<?php echo esc_url($back_url); ?>"
         style="font-size:13px; font-weight:400; opacity:.85; text-decoration:none; border-bottom:1px dotted currentColor;">
        — <?php echo esc_html($label); ?>
      </a>
    <?php endif; ?>
  </h1>

  <?php if ($wache_id > 0): ?>
    <p style="margin:6px 0 14px 0;">
      <a class="button" href="<?php echo esc_url( add_query_arg([
        'page' => 'lsttraining_leitstellen_wachen',
        'open_wache_id' => (int)$wache_id,
      ], admin_url('admin.php')) ); ?>">Zurück zu Wachen</a>
    </p> 
  <?php endif; ?>

  <form method="get" id="fahrzeuge-filter" class="lst-fahrzeuge-filter" onchange="if (window.lstFahrzeugeRefreshFilters) { window.lstFahrzeugeRefreshFilters(); } else { this.requestSubmit ? this.requestSubmit() : this.submit(); }">
    <input type="hidden" name="page" value="lsttraining_fahrzeuge" />
    <?php if ($wache_id > 0): ?>
      <input type="hidden" name="wache_id" value="<?php echo (int)$wache_id; ?>" />
    <?php endif; ?>

    <div class="lst-fahrzeuge-filter__field lst-fahrzeuge-filter__field--search">
      <label for="fahrzeuge-search">Suche</label>
      <input type="search" id="fahrzeuge-search" name="s" value="<?php echo esc_attr($s); ?>"
             placeholder="Rufname oder Wachenort suchen"
             aria-describedby="fahrzeuge-search-hint" />
      <span id="fahrzeuge-search-hint" class="description">Rufname oder Name der Wache.</span>
    </div>

    <label class="lst-fahrzeuge-filter__field">
      <span>Land</span>
      <select name="land">
        <option value="">– alle –</option>
        <?php foreach (array_keys($bundes_opts) as $landName): ?>
          <option value="<?php echo esc_attr($landName); ?>" <?php selected($land, $landName); ?>>
            <?php echo esc_html($landName); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="lst-fahrzeuge-filter__field">
      <span>Bundesland</span>
      <select name="bundesland">
        <option value="">– alle –</option>
        <?php
        foreach ($bundes_opts as $landName => $blList) {
            if (!is_array($blList) || empty($blList)) { continue; }
            echo '<optgroup label="'.esc_attr($landName).'">';
            foreach ($blList as $bl) {
                $sel = ($bundesland === $bl) ? ' selected' : '';
                echo '<option value="'.esc_attr($bl).'"'.$sel.'>'.esc_html($bl).'</option>';
            }
            echo '</optgroup>';
        }
        ?>
      </select>
    </label>

    <label class="lst-fahrzeuge-filter__field">
      <span>Leitstelle</span>
      <select name="leitstelle_id">
        <option value="">– alle –</option>
        <?php foreach ($leitstellen_opts as $ls): ?>
          <option value="<?php echo (int)$ls['id']; ?>" <?php selected($leitstelle_id, (int)$ls['id']); ?>>
            #<?php echo (int)$ls['id']; ?> — <?php echo esc_html($ls['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="lst-fahrzeuge-filter__field">
      <span>Nebenleitstelle</span>
      <select name="neben_id">
        <option value="">– alle –</option>
        <?php foreach ($neben_opts as $nb): ?>
          <option value="<?php echo (int)$nb['id']; ?>" <?php selected($neben_id, (int)$nb['id']); ?>>
            #<?php echo (int)$nb['id']; ?> — <?php echo esc_html($nb['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="lst-fahrzeuge-filter__field lst-fahrzeuge-filter__field--small">
      <span>Pro Seite</span>
      <input type="number" min="10" max="200" step="10" name="per_page"
             value="<?php echo esc_attr($per_page); ?>" />
    </label>

    <?php
      $hasFilter = ($s !== '' || $land !== '' || $bundesland !== '' || $leitstelle_id > 0 || $neben_id > 0 || (isset($_GET['per_page']) && (int) $_GET['per_page'] !== 50));
      $reset_qs = ['page' => 'lsttraining_fahrzeuge'];
      if ($wache_id > 0) { $reset_qs['wache_id'] = $wache_id; }
      $reset_url = add_query_arg($reset_qs, admin_url('admin.php'));
    ?>
    <div class="lst-fahrzeuge-filter__actions">
      <span class="spinner" id="fahrzeuge-filter-spinner" aria-hidden="true"></span>
      <a class="button" id="fahrzeuge-reset" href="<?php echo esc_url($reset_url); ?>"<?php echo $hasFilter ? '' : ' hidden'; ?>>Zurücksetzen</a>
    </div>
  </form>
<a href="#" class="button button-primary" id="fahrzeug-new">Neues Fahrzeug</a>

<div id="lst-fahrzeuge-results" class="lst-fahrzeuge-results" aria-live="polite">
  <p class="lst-fahrzeuge-summary">
    <strong><?php echo number_format_i18n($total); ?></strong> Fahrzeuge gefunden.
    <?php if ($s !== ''): ?>
      Suche nach <strong>„<?php echo esc_html($s); ?>“</strong> in Rufname oder Wachenort.
    <?php endif; ?>
    Seite <?php echo (int)$paged; ?> von <?php echo (int)$max_pages; ?>.
  </p>

  <table class="widefat fixed striped" id="fahrzeuge-table">
  <thead>
    <tr>
      <th style="width:110px;"><?php echo lst_sort_link('ID', 'id', $orderby, $order); ?></th>
      <th style="min-width:240px;"><?php echo lst_sort_link('Rufname (Funkname)', 'rufname', $orderby, $order); ?></th>
      <th style="min-width:220px;"><?php echo lst_sort_link('Wache', 'wache', $orderby, $order); ?></th>
      <th style="min-width:180px;">Fahrzeugtyp</th>
      <th style="min-width:120px;">FMS</th>
      <th style="width:90px;">Bild</th>
      <?php if ($has_wachen_bundesland): ?>
        <th style="min-width:180px;">Bundesland</th>
      <?php endif; ?>
      <th style="width:120px;">Aktion</th>
    </tr>
  </thead>

  <tbody>
    <?php
      // ID, Rufname, Wache, Typ, FMS, Bild, Aktion
      $colspan = 7;
      if ($has_wachen_bundesland) $colspan += 1;
    ?>

    <?php if (empty($rows)): ?>
      <tr><td colspan="<?php echo (int)$colspan; ?>">Keine Datensätze.</td></tr>
    <?php else: foreach ($rows as $r): ?>

      <?php
        $alt = 'Fahrzeug ' . (string)($r['rufname'] ?? '');
        if (!empty($r['fahrzeugtyp'])) $alt .= ', Typ ' . (string)$r['fahrzeugtyp'];
        if (!empty($r['wache_name']))  $alt .= ', Wache ' . (string)$r['wache_name'];
      ?>

      <tr data-id="<?php echo (int)$r['id']; ?>">
        <td>#<?php echo (int)$r['id']; ?></td>
        <td><?php echo esc_html($r['rufname']); ?></td>
        <td><?php echo esc_html($r['wache_name']); ?></td>
        <td><?php echo esc_html($r['fahrzeugtyp']); ?></td>
        <td><?php echo esc_html($r['fms_status']); ?></td>

        <td style="text-align:center;">
          <img
            src="<?php echo esc_url($r['__thumb']); ?>"
            alt="<?php echo esc_attr($alt); ?>"
            class="lst-fahrzeug-thumb"
            loading="lazy"
          />
        </td>

        <?php if ($has_wachen_bundesland): ?>
          <td><?php echo isset($r['bundesland']) ? esc_html($r['bundesland']) : ''; ?></td>
        <?php endif; ?>

        <td>
          <a href="#" class="button btn-edit-fahrzeug" data-id="<?php echo (int)$r['id']; ?>">Bearbeiten</a>
        </td>
      </tr>

    <?php endforeach; endif; ?>
  </tbody>
</table>

  <?php if ( $max_pages > 1 ) :
      $base_qs = $_GET;
      unset($base_qs['paged']);

      $base_url = add_query_arg($base_qs, admin_url('admin.php?page=lsttraining_fahrzeuge'));
      $prev_url = ($paged > 1) ? add_query_arg('paged', $paged-1, $base_url) : '';
      $next_url = ($paged < $max_pages) ? add_query_arg('paged', $paged+1, $base_url) : '';
  ?>
    <div class="tablenav">
      <div class="tablenav-pages">
        <span class="displaying-num"><?php echo number_format_i18n($total); ?> Einträge</span>
        <span class="pagination-links">
          <a class="button<?php echo $paged <= 1 ? ' disabled' : ''; ?>" href="<?php echo esc_url($prev_url); ?>">«</a>
          <span class="paging-input">
            Seite <input class="current-page" type="text" name="paged" value="<?php echo (int)$paged; ?>" size="2"> von <span class="total-pages"><?php echo (int)$max_pages; ?></span>
          </span>
          <a class="button<?php echo $paged >= $max_pages ? ' disabled' : ''; ?>" href="<?php echo esc_url($next_url); ?>">»</a>
        </span>
      </div>
    </div>
  <?php endif; ?>

  <p style="margin-top:14px">
  </p>
</div>
</div>
