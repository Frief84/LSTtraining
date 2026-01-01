<?php
/**
 * includes/fahrzeuge.php
 * Fahrzeuge-Liste mit Filtern (Suche, Bundesland, Leitstelle, Nebenleitstelle)
 * und Aktionen (Bearbeiten / Neu). Filter wirken serverseitig.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'read' ) ) { wp_die( 'Keine Berechtigung.' ); }

require_once plugin_dir_path( __FILE__ ) . 'db.php';

$pdo = lsttraining_get_connection();
if ( ! $pdo ) { wp_die( 'Keine Datenbankverbindung.' ); }

/* -----------------------------------------------------------
 * Helper: Tabellen-/Spalten-Existenz prüfen (robust)
 * – nur definieren, wenn nicht schon woanders vorhanden
 * ----------------------------------------------------------- */
if ( ! function_exists('lst_col_exists') ) {
    function lst_col_exists(PDO $pdo, $table, $column) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $st->execute([$column]);
            return ($st && $st->rowCount() > 0);
        } catch (Throwable $e) { return false; }
    }
}
if ( ! function_exists('lst_tbl_exists') ) {
    function lst_tbl_exists(PDO $pdo, $table) {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE ?");
            $st->execute([$table]);
            return ($st && $st->rowCount() > 0);
        } catch (Throwable $e) { return false; }
    }
}

/* -----------------------------------------------------------
 * Request-Parameter
 * ----------------------------------------------------------- */
$s        = isset($_GET['s']) ? trim((string)$_GET['s']) : '';
$orderby  = isset($_GET['orderby']) ? strtolower($_GET['orderby']) : 'wache';
$order    = isset($_GET['order']) ? strtolower($_GET['order']) : 'asc';
$paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 50;

$bundesland    = isset($_GET['bundesland']) ? trim((string)$_GET['bundesland']) : '';
$leitstelle_id = (isset($_GET['leitstelle_id']) && $_GET['leitstelle_id'] !== '') ? max(0, intval($_GET['leitstelle_id'])) : 0;
$neben_id      = (isset($_GET['neben_id'])      && $_GET['neben_id'] !== '')      ? max(0, intval($_GET['neben_id']))      : 0;

/* -----------------------------------------------------------
 * Spalten/Relationen erkennen
 * ----------------------------------------------------------- */
$has_wachen_bundesland = lst_col_exists($pdo, 'wachen', 'bundesland');
$has_wachen_leitstelle = lst_col_exists($pdo, 'wachen', 'leitstelle_id');
$has_wachen_neben      = lst_col_exists($pdo, 'wachen', 'nebenleitstelle_id');

$has_leitstellen_tbl   = lst_tbl_exists($pdo, 'leitstellen');
$has_ls_bundesland     = $has_leitstellen_tbl ? lst_col_exists($pdo, 'leitstellen', 'bundesland') : false;

$map_ls_tbl  = lst_tbl_exists($pdo, 'wachen_leitstellen')  ? 'wachen_leitstellen'
            : (lst_tbl_exists($pdo, 'leitstellen_wachen')  ? 'leitstellen_wachen' : '');
$map_neb_tbl = lst_tbl_exists($pdo, 'wachen_nebenstellen') ? 'wachen_nebenstellen'
            : (lst_tbl_exists($pdo, 'nebenstellen_wachen') ? 'nebenstellen_wachen' : '');

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
        $bundes_opts = $tmp; // [ "Deutschland" => [...], "Österreich" => [...] ]
    }
}

// Leitstellen
$leitstellen_opts = [];
try {
    if ($has_leitstellen_tbl) {
        $st = $pdo->query("SELECT id, name FROM leitstellen ORDER BY name");
        if ($st) { $leitstellen_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
    }
} catch (Throwable $e) {
    // ignore
}

// Nebenleitstellen
$neben_opts = [];
try {
    if (lst_tbl_exists($pdo, 'nebenleitstellen')) {
        $st = $pdo->query("SELECT id, name FROM nebenleitstellen ORDER BY name");
        if ($st) { $neben_opts = $st->fetchAll(PDO::FETCH_ASSOC); }
    }
} catch (Throwable $e) {
    // ignore
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

$joined_wls = false; // Mapping wache↔leitstelle
$joined_ls  = false; // Tabelle leitstellen

// Suche
if ($s !== '') {
    $where[] = '(f.rufname LIKE :q OR w.name LIKE :q)';
    $params[':q'] = '%' . $s . '%';
}

// Leitstelle (immer filtern, wenn ausgewählt und Weg vorhanden)
if ($leitstelle_id > 0) {
    if ($has_wachen_leitstelle) {
        $where[] = 'w.leitstelle_id = :lsid';
        $params[':lsid'] = $leitstelle_id;
    } elseif ($map_ls_tbl) {
        if (!$joined_wls) { $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id"; $joined_wls = true; }
        $where[] = 'wls.leitstelle_id = :lsid';
        $params[':lsid'] = $leitstelle_id;
    }
}

// Nebenleitstelle
if ($neben_id > 0) {
    if ($has_wachen_neben) {
        $where[] = 'w.nebenleitstelle_id = :nbid';
        $params[':nbid'] = $neben_id;
    } elseif ($map_neb_tbl) {
        $joins[] = "JOIN {$map_neb_tbl} AS wnb ON wnb.wache_id = w.id";
        $where[] = 'wnb.nebenleitstelle_id = :nbid';
        $params[':nbid'] = $neben_id;
    }
}

// Bundesland – bevorzugt wachen.bundesland, sonst via leitstellen.bundesland
if ($bundesland !== '') {
    if ($has_wachen_bundesland) {
        $where[] = 'w.bundesland = :bl';
        $params[':bl'] = $bundesland;
    } elseif ($has_ls_bundesland) {
        if ($has_wachen_leitstelle) {
            if (!$joined_ls) { $joins[] = "JOIN leitstellen AS ls ON ls.id = w.leitstelle_id"; $joined_ls = true; }
            $where[] = 'ls.bundesland = :bl';
            $params[':bl'] = $bundesland;
        } elseif ($map_ls_tbl) {
            if (!$joined_wls) { $joins[] = "JOIN {$map_ls_tbl} AS wls ON wls.wache_id = w.id"; $joined_wls = true; }
            if (!$joined_ls)  { $joins[] = "JOIN leitstellen AS ls ON ls.id = wls.leitstelle_id"; $joined_ls = true; }
            $where[] = 'ls.bundesland = :bl';
            $params[':bl'] = $bundesland;
        }
        // sonst: keine zuverlässige Verbindung → kein Filter
    }
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$joins_sql = $joins ? (' ' . implode(' ', $joins) . ' ') : ' ';

/* -----------------------------------------------------------
 * Count
 * ----------------------------------------------------------- */
$sql_count = "SELECT COUNT(*)
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
 * Daten
 * ----------------------------------------------------------- */
$sql = "SELECT
            f.id,
            f.rufname,
            f.fahrzeugtyp,
            f.fms_status,
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
 * Helper für Sort-Links
 * ----------------------------------------------------------- */
function lst_sort_link($label, $key, $current_key, $current_order) {
    $order = ($current_key === $key && $current_order === 'asc') ? 'desc' : 'asc';
    $qs = $_GET; $qs['orderby'] = $key; $qs['order'] = $order;
    $url = esc_url( add_query_arg($qs, admin_url('admin.php?page=lsttraining_fahrzeuge')) );
    $arrow = ($current_key === $key) ? ($current_order === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="'.$url.'" class="sort-link" data-key="'.esc_attr($key).'">'.esc_html($label.$arrow).'</a>';
}

?>
<div class="wrap">
  <h1>Fahrzeuge</h1>

  <form method="get" id="fahrzeuge-filter" style="margin-bottom:16px;">
    <input type="hidden" name="page" value="lsttraining_fahrzeuge" />

    <input type="search" name="s" value="<?php echo esc_attr($s); ?>"
           placeholder="Suche in Rufname / Wache" style="min-width:320px;" />

    <!-- Bundesland -->
    <label style="margin-left:10px;">
      Bundesland:
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

    <!-- Leitstelle -->
    <label style="margin-left:10px;">
      Leitstelle:
      <select name="leitstelle_id" style="min-width:220px;">
        <option value="">– alle –</option>
        <?php foreach ($leitstellen_opts as $ls): ?>
          <option value="<?php echo (int)$ls['id']; ?>" <?php selected($leitstelle_id, (int)$ls['id']); ?>>
            #<?php echo (int)$ls['id']; ?> — <?php echo esc_html($ls['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <!-- Nebenleitstelle -->
    <label style="margin-left:10px;">
      Nebenleitstelle:
      <select name="neben_id" style="min-width:220px;">
        <option value="">– alle –</option>
        <?php foreach ($neben_opts as $nb): ?>
          <option value="<?php echo (int)$nb['id']; ?>" <?php selected($neben_id, (int)$nb['id']); ?>>
            #<?php echo (int)$nb['id']; ?> — <?php echo esc_html($nb['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label style="margin-left:10px;">
      Pro Seite:
      <input type="number" min="10" max="200" step="10" name="per_page"
             value="<?php echo esc_attr($per_page); ?>" style="width:80px;" />
    </label>

    <button class="button" style="margin-left:8px;">Filtern</button>
    <?php
      $hasFilter = ($s !== '' || $bundesland !== '' || $leitstelle_id > 0 || $neben_id > 0 || isset($_GET['per_page']));
      if ($hasFilter): ?>
      <a class="button" href="<?php echo esc_url( admin_url('admin.php?page=lsttraining_fahrzeuge') ); ?>" style="margin-left:6px;">Zurücksetzen</a>
    <?php endif; ?>
  </form>

  <p>
    <strong><?php echo number_format_i18n($total); ?></strong> Fahrzeuge gefunden.
    Seite <?php echo (int)$paged; ?> von <?php echo (int)$max_pages; ?>.
    <?php
    if ($bundesland !== '' && !$has_wachen_bundesland && !$has_ls_bundesland) {
        echo '<br><em>Hinweis: Bundesland-Filter ohne Wirkung (keine geeignete Spalte gefunden).</em>';
    }
    if ($leitstelle_id > 0 && !$has_wachen_leitstelle && !$map_ls_tbl) {
        echo '<br><em>Hinweis: Leitstellen-Filter ohne Wirkung (weder <code>wachen.leitstelle_id</code> noch Mapping-Tabelle vorhanden).</em>';
    }
    if ($neben_id > 0 && !$has_wachen_neben && !$map_neb_tbl) {
        echo '<br><em>Hinweis: Nebenleitstellen-Filter ohne Wirkung (weder <code>wachen.nebenleitstelle_id</code> noch Mapping-Tabelle vorhanden).</em>';
    }
    ?>
  </p>

  <table class="widefat fixed striped" id="fahrzeuge-table">
    <thead>
      <tr>
        <th style="width:110px;"><?php echo lst_sort_link('ID', 'id', $orderby, $order); ?></th>
        <th style="min-width:240px;"><?php echo lst_sort_link('Rufname (Funkname)', 'rufname', $orderby, $order); ?></th>
        <th style="min-width:220px;"><?php echo lst_sort_link('Wache', 'wache', $orderby, $order); ?></th>
        <th style="min-width:180px;">Fahrzeugtyp</th>
        <th style="min-width:120px;">FMS</th>
        <?php if ($has_wachen_bundesland): ?>
        <th style="min-width:180px;">Bundesland</th>
        <?php endif; ?>
        <th style="width:120px;">Aktion</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?php echo $has_wachen_bundesland ? '7' : '6'; ?>">Keine Datensätze.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr data-id="<?php echo (int)$r['id']; ?>">
          <td>#<?php echo (int)$r['id']; ?></td>
          <td><?php echo esc_html($r['rufname']); ?></td>
          <td><?php echo esc_html($r['wache_name']); ?></td>
          <td><?php echo esc_html($r['fahrzeugtyp']); ?></td>
          <td><?php echo esc_html($r['fms_status']); ?></td>
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
      $base_qs = $_GET; unset($base_qs['paged']);
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
    <a href="#" class="button button-primary" id="fahrzeug-new">Neues Fahrzeug</a>
  </p>
</div>
