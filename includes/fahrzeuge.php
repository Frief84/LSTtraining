<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'read' ) ) { wp_die( 'Keine Berechtigung.' ); }

require_once plugin_dir_path( __FILE__ ) . 'db.php';
$pdo = lsttraining_get_connection();
if ( ! $pdo ) { wp_die( 'Keine Datenbankverbindung.' ); }

/* Request-Parameter */
$s         = isset($_GET['s']) ? trim((string)$_GET['s']) : '';
$orderby   = isset($_GET['orderby']) ? strtolower($_GET['orderby']) : 'wache';
$order     = isset($_GET['order']) ? strtolower($_GET['order']) : 'asc';
$paged     = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page  = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 50;

$allowed_orderby = [
    'rufname' => 'f.rufname',
    'wache'   => 'w.name',
    'id'      => 'f.id',
];
$orderby_sql = $allowed_orderby[ $orderby ] ?? $allowed_orderby['wache'];
$order_sql   = ($order === 'desc') ? 'DESC' : 'ASC';

$where = [];
$params = [];
if ($s !== '') {
    $where[] = '(f.rufname LIKE :q OR w.name LIKE :q)';
    $params[':q'] = '%' . $s . '%';
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Count */
$sql_count = "SELECT COUNT(*) FROM fahrzeuge f JOIN wachen w ON w.id = f.wache_id $where_sql";
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$max_pages = max(1, (int)ceil($total / $per_page));
$paged = min($paged, $max_pages);
$offset = ($paged - 1) * $per_page;

/* Daten */
$sql = "SELECT f.id, f.rufname, w.name AS wache_name
        FROM fahrzeuge f
        JOIN wachen w ON w.id = f.wache_id
        $where_sql
        ORDER BY $orderby_sql $order_sql, f.id ASC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
$stmt->bindValue(':limit',  (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset,   PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Helper für Sort-Links */
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
    <label style="margin-left:10px;">
      Pro Seite:
      <input type="number" min="10" max="200" step="10" name="per_page"
             value="<?php echo esc_attr($per_page); ?>" style="width:80px;" />
    </label>
    <button class="button">Filtern</button>
    <?php if ($s !== ''): ?>
      <a class="button" href="<?php echo esc_url( admin_url('admin.php?page=lsttraining_fahrzeuge') ); ?>">Zurücksetzen</a>
    <?php endif; ?>
  </form>

  <p><strong><?php echo number_format_i18n($total); ?></strong> Fahrzeuge gefunden. Seite <?php echo (int)$paged; ?> von <?php echo (int)$max_pages; ?>.</p>

  <table class="widefat fixed striped" id="fahrzeuge-table">
    <thead>
      <tr>
        <th style="width:110px;"><?php echo lst_sort_link('ID', 'id', $orderby, $order); ?></th>
        <th style="min-width:240px;"><?php echo lst_sort_link('Rufname (Funkname)', 'rufname', $orderby, $order); ?></th>
        <th style="min-width:280px;"><?php echo lst_sort_link('Wache', 'wache', $orderby, $order); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="3">Keine Datensätze.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>#<?php echo (int)$r['id']; ?></td>
          <td><?php echo esc_html($r['rufname']); ?></td>
          <td><?php echo esc_html($r['wache_name']); ?></td>
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
</div>

