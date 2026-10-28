<?php
/**
 * Nebenleitstellen-Editor – memory-safe list query
 * v2025-05-05
 */

if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
    wp_die( 'Keine Berechtigung.' );
}

add_action('wp_ajax_lsttraining_get_next_neben_id', function() {
    global $pdo;
    $next = (int) $pdo->query('SELECT MAX(id)+1 FROM nebenleitstellen')->fetchColumn() ?: 1;
    wp_send_json_success($next);
});


require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/regions.php';
require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

$pdo          = lsttraining_get_connection();
$nebenstellen = [];
$suchbegriff  = isset($_GET['suchbegriff']) ? sanitize_text_field(wp_unslash((string) $_GET['suchbegriff'])) : '';
$orderby      = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'id';
$order        = isset($_GET['order']) ? strtolower((string) $_GET['order']) : 'asc';
[ $selected_land, $selected_bundesland ] = lsttraining_region_filter_values( $_GET );
$region_map = lsttraining_region_map();
$is_direct_create = $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ( $_POST['neben_create'] ?? '' ) === '1';

if ( ! function_exists( 'lsttraining_nebenstellen_sort_link' ) ) {
    function lsttraining_nebenstellen_sort_link( string $label, string $key, string $current_key, string $current_order ): string {
        $next_order = ( $current_key === $key && $current_order === 'asc' ) ? 'desc' : 'asc';
        $qs = $_GET;
        $qs['orderby'] = $key;
        $qs['order'] = $next_order;
        $url = add_query_arg( $qs, admin_url( 'admin.php?page=lsttraining_nebenstellen' ) );
        $arrow = $current_key === $key ? ( $current_order === 'asc' ? ' ▲' : ' ▼' ) : '';

        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label . $arrow ) . '</a>';
    }
}


$nextId = (int) $pdo->query('SELECT MAX(id)+1 FROM nebenleitstellen')->fetchColumn() ?: 1;

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! $is_direct_create && ! empty( $_POST['neben_update_id'] ) && $pdo ) {
    $update_id = absint( $_POST['neben_update_id'] );
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lsttraining_nonce'] ?? '' ) ), 'lsttraining_nebenstelle_form' ) ) {
        wp_die( 'Ungültiger Sicherheits-Token.', 403 );
    }
    if ( $update_id > 0 && ! lsttraining_user_can_object( $pdo, 'nebenstellen', 'nebenstelle', $update_id ) ) {
        wp_die( 'Keine Berechtigung.', 403 );
    }
    $region = lsttraining_region_validate_selection(
        sanitize_text_field( wp_unslash( (string) ( $_POST['neben_update_land'] ?? '' ) ) ),
        sanitize_text_field( wp_unslash( (string) ( $_POST['neben_update_bundesland'] ?? '' ) ) )
    );
    if ( ! $region['valid'] ) {
        wp_die( esc_html( $region['message'] ), 400 );
    }
    $pdo->prepare(
        'UPDATE nebenleitstellen
            SET name = ?, land = ?, bundesland = ?, zustandigkeit = ?, einwohner = ?, flaeche_km2 = ?,
                gps = ?, nachbarleitstelle = ?, geojson = ?
          WHERE id = ?'
    )->execute( [
        sanitize_text_field( $_POST['neben_update_name'] ),
        $region['land'] !== '' ? $region['land'] : null,
        $region['bundesland'] !== '' ? $region['bundesland'] : null,
        sanitize_text_field( $_POST['neben_update_zustandigkeit'] ),
        intval   ( $_POST['neben_update_einwohner'] ),
        floatval ( $_POST['neben_update_flaeche'] ),
        sanitize_text_field( $_POST['neben_update_gps'] ),
        intval   ( $_POST['neben_update_nachbar'] ),
        stripslashes( $_POST['geojson_edit'] ?? '' ),
        $update_id
    ] );
    if ( function_exists( 'lsttraining_log_activity' ) ) {
        lsttraining_log_activity( [
            'entity_type' => 'nebenstelle',
            'action'      => 'update',
            'entity_id'   => $update_id,
            'meta'        => [ 'page' => 'nebenstellen_editor.php', 'land' => $region['land'], 'bundesland' => $region['bundesland'] ],
        ] );
    }
}

/* -------------------------------------------------------------------------
 * LIST – fetch only small cols + Boolean flag (no big JSON)
 * ---------------------------------------------------------------------- */
if ($pdo) {
    $scope_join = '';
    $where_parts = [];
    $args = [];
    if (!current_user_can('manage_options')) {
        $allowed_ids = lsttraining_user_leitstellen_ids();
        $scope_join = ' INNER JOIN leitstelle_nebenleitstellen AS ln ON ln.nebenleitstelle_id = n.id ';
        if ($allowed_ids) {
            $where_parts[] = 'ln.leitstelle_id IN (' . implode(',', array_fill(0, count($allowed_ids), '?')) . ')';
            $args = array_merge($args, $allowed_ids);
        } else {
            $where_parts[] = '1 = 0';
        }
    }
    $sql = '
        SELECT DISTINCT
            n.id,
            n.name,
            n.land,
            n.bundesland,
            n.zustandigkeit,
            n.einwohner,
            n.flaeche_km2,
            n.gps,
            (CHAR_LENGTH(TRIM(COALESCE(n.geojson, ""))) > 2) AS has_geojson,
            COALESCE(cnt.cnt, 0) AS wachen_cnt
        FROM nebenleitstellen AS n ' . $scope_join . '
        LEFT JOIN (
            SELECT nebenleitstelle_id, COUNT(*) AS cnt
            FROM wache_nebenleitstellen
            GROUP BY nebenleitstelle_id
        ) AS cnt
          ON cnt.nebenleitstelle_id = n.id';

    if ($suchbegriff !== '') {
        $where_parts[] = '(n.name LIKE ? OR n.id = ?)';
        $args[] = "%$suchbegriff%";
        $args[] = $suchbegriff;
    }
    if ($selected_land !== '') {
        $where_parts[] = 'n.land = ?';
        $args[] = $selected_land;
        if ($selected_bundesland === '__none__') {
            $where_parts[] = "(n.bundesland IS NULL OR TRIM(n.bundesland) = '')";
        } elseif ($selected_bundesland !== '') {
            $where_parts[] = 'n.bundesland = ?';
            $args[] = $selected_bundesland;
        }
    }
    if ($where_parts) {
        $sql .= ' WHERE ' . implode(' AND ', $where_parts);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $nebenstellen = $stmt->fetchAll(PDO::FETCH_OBJ);

    $order_factor = ($order === 'desc') ? -1 : 1;
    $sort_key = in_array($orderby, ['id', 'name', 'land', 'bundesland', 'wachen'], true) ? $orderby : 'id';
    usort($nebenstellen, static function ($a, $b) use ($sort_key, $order_factor) {
        if ($sort_key === 'id') {
            $cmp = (int) $a->id <=> (int) $b->id;
        } elseif ($sort_key === 'wachen') {
            $cmp = (int) $a->wachen_cnt <=> (int) $b->wachen_cnt;
        } else {
            $field = in_array($sort_key, ['land', 'bundesland'], true) ? $sort_key : 'name';
            $cmp = strnatcasecmp((string) ($a->{$field} ?? ''), (string) ($b->{$field} ?? ''));
        }

        return $cmp * $order_factor;
    });
}

if ( $is_direct_create && $pdo ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lsttraining_nonce'] ?? '' ) ), 'lsttraining_nebenstelle_form' ) ) {
        wp_die( 'Ungültiger Sicherheits-Token.', 403 );
    }
    $region = lsttraining_region_validate_selection(
        sanitize_text_field( wp_unslash( (string) ( $_POST['neben_update_land'] ?? '' ) ) ),
        sanitize_text_field( wp_unslash( (string) ( $_POST['neben_update_bundesland'] ?? '' ) ) )
    );
    if ( ! $region['valid'] ) {
        wp_die( esc_html( $region['message'] ), 400 );
    }
    $stmt = $pdo->prepare(
        'INSERT INTO nebenleitstellen
         (name,land,bundesland,zustandigkeit,einwohner,flaeche_km2,gps,nachbarleitstelle,geojson)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        sanitize_text_field( $_POST['neben_update_name'] ),
        $region['land'] !== '' ? $region['land'] : null,
        $region['bundesland'] !== '' ? $region['bundesland'] : null,
        sanitize_text_field( $_POST['neben_update_zustandigkeit'] ),
        intval( $_POST['neben_update_einwohner'] ),
        floatval( $_POST['neben_update_flaeche'] ),
        sanitize_text_field( $_POST['neben_update_gps'] ),
        intval( $_POST['neben_update_nachbar'] ?? 0 ),
        stripslashes( $_POST['geojson_edit'] ?? '[]' ),
    ]);
    $new_id = (int) $pdo->lastInsertId();
    if ( function_exists( 'lsttraining_log_activity' ) ) {
        lsttraining_log_activity( [
            'entity_type' => 'nebenstelle',
            'action'      => 'create',
            'entity_id'   => $new_id,
            'meta'        => [ 'page' => 'nebenstellen_editor.php', 'land' => $region['land'], 'bundesland' => $region['bundesland'] ],
        ] );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=lsttraining_nebenstellen' ) );
    exit;
}


?>
<div class="wrap">
    <h1>Nebenleitstellen verwalten</h1>

    <form method="get" data-lst-region-filter-form style="margin-bottom:20px;">
        <input type="hidden" name="page" value="lsttraining_nebenstellen">
        <input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
        <input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
        <input id="nebenstellen-search" name="suchbegriff" data-target="#nebenstellen-table" type="text" placeholder="Suchen nach Name oder ID …" value="<?php echo esc_attr($suchbegriff); ?>" style="width:300px;">
        <button class="button">Suchen</button>
        <?php lsttraining_render_region_filter( $selected_land, $selected_bundesland ); ?>
    </form>
    <button type="button" id="create-nebenstelle" class="button" data-next-id="<?php echo esc_attr( $nextId ); ?>" style="margin-bottom:20px;">+ Nebenstelle erstellen</button>

    <table class="widefat" id="nebenstellen-table">
        <thead>
            <tr><th><?php echo lsttraining_nebenstellen_sort_link('ID', 'id', $orderby, $order); ?></th><th><?php echo lsttraining_nebenstellen_sort_link('Name', 'name', $orderby, $order); ?></th><th><?php echo lsttraining_nebenstellen_sort_link('Land', 'land', $orderby, $order); ?></th><th><?php echo lsttraining_nebenstellen_sort_link('Bundesland', 'bundesland', $orderby, $order); ?></th><th>Zuständigkeit</th><th>Einwohner</th>
                <th>Fläche</th><th>Standort</th><th>Einsatzgebiet</th><th><?php echo lsttraining_nebenstellen_sort_link('Wachen', 'wachen', $orderby, $order); ?></th><th>Aktionen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($nebenstellen as $n) : ?>
  <?php
    $missingGps = empty(trim($n->gps)) || strtolower($n->gps) === 'none';
    $rowClass   = $missingGps && !$n->has_geojson ? 'missing-both'
               : ($missingGps ? 'missing-gps'
               : (!$n->has_geojson ? 'missing-geojson' : ''));

    $onclick = sprintf(
      "loadNebenstelleAndOpen(%d, %s, %s, %d, %f, %s, %d, %s, %s); return false;",
      $n->id,
      json_encode($n->name),
      json_encode($n->zustandigkeit),
      (int)$n->einwohner,
      (float)$n->flaeche_km2,
      json_encode($n->gps),
      0,
      json_encode($n->land ?? ''),
      json_encode($n->bundesland ?? '')
    );
  ?>
  <tr class="<?php echo esc_attr($rowClass); ?>">
    <td><?php echo esc_html($n->id); ?></td>
    <td><?php echo esc_html($n->name); ?></td>
    <td><?php echo esc_html($n->land ?: '–'); ?></td>
    <td><?php echo esc_html($n->bundesland ?: '–'); ?></td>
    <td><?php echo esc_html($n->zustandigkeit); ?></td>
    <td><?php echo esc_html($n->einwohner); ?></td>
    <td><?php echo esc_html($n->flaeche_km2); ?></td>
    <td><?php echo esc_html($n->gps); ?></td>
    <td><?php echo $n->has_geojson ? '✅' : '❌'; ?></td>
    <td class="num"><?php echo (int)$n->wachen_cnt; ?></td> <!-- NEU -->
    <td>
      <a href="#" class="button" onclick="<?php echo htmlspecialchars($onclick); ?>" style="margin-bottom:5px;">Bearbeiten</a>
      <button type="button" class="button button-link-delete js-delete-nebenstelle" data-id="<?php echo esc_attr($n->id); ?>" style="width:85px;">
        Löschen
      </button>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
    </table>
</div>


<!-- Overlay -->
<div id="popup-overlay"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:9998;"></div>

<!-- Edit-Popup -->
<div id="edit-nebenstelle-formular" class="lst-nebenstelle-modal" style="display:none;">
    <form method="post" class="lst-nebenstelle-modal__form">
        <?php wp_nonce_field( 'lsttraining_nebenstelle_form', 'lsttraining_nonce' ); ?>
        <div class="lst-nebenstelle-modal__header">
            <div>
                <h2>Nebenleitstelle bearbeiten</h2>
            </div>
            <button type="button" class="button lst-nebenstelle-modal__close" onclick="closeNebenstellePopup()">Abbrechen</button>
        </div>

        <div class="lst-nebenstelle-modal__body">
        <input type="hidden" name="neben_update_id" id="neben_update_id">
		<input type="hidden" name="neben_create" id="neben_create" value="0">
        <input type="hidden" name="neben_update_nachbar" id="neben_update_nachbar">

        <div class="lst-nebenstelle-grid">
            <label class="lst-nebenstelle-field">
                <span>Name</span>
                <input type="text" name="neben_update_name" id="neben_update_name" required>
            </label>

            <label class="lst-nebenstelle-field">
                <span>Zuständigkeit</span>
                <input type="text" name="neben_update_zustandigkeit" id="neben_update_zustandigkeit">
            </label>

            <label class="lst-nebenstelle-field">
                <span>Land</span>
                <select name="neben_update_land" id="neben_update_land">
                    <option value="">— Land wählen —</option>
                    <?php foreach ( array_keys( $region_map ) as $land ) : ?>
                        <option value="<?php echo esc_attr( $land ); ?>"><?php echo esc_html( $land ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="lst-nebenstelle-field">
                <span>Bundesland</span>
                <select name="neben_update_bundesland" id="neben_update_bundesland" data-map='<?php echo esc_attr( (string) wp_json_encode( $region_map, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT ) ); ?>' disabled>
                    <option value="">— Bundesland wählen —</option>
                </select>
            </label>

            <label class="lst-nebenstelle-field">
                <span>Einwohner</span>
                <input type="number" name="neben_update_einwohner" id="neben_update_einwohner">
            </label>

            <div class="lst-nebenstelle-field">
                <span>Fläche (km²)</span>
                <div class="lst-nebenstelle-inline-control">
                    <input type="number" step="0.01" name="neben_update_flaeche" id="neben_update_flaeche">
                    <button type="button" id="calc-flaeche" class="button">Berechnen</button>
                </div>
            </div>

            <label class="lst-nebenstelle-field lst-nebenstelle-field--wide">
                <span>Standort</span>
                <input type="text" name="neben_update_gps" id="neben_update_gps" placeholder="z.B. 48.12345, 9.12345">
            </label>
        </div>

        <div class="lst-nebenstelle-map-panel">
            <div class="lst-nebenstelle-section-head">
                <h3>Standortkarte</h3>
            </div>
            <div id="nebenstelle_map"></div>
        </div>

        <input type="hidden" name="geojson_edit" id="geojson_edit" value="[]">

        <div class="form-map lst-nebenstelle-actions-panel" id="einsatzgebiet_container">
            <div class="lst-nebenstellen-map-actions">
                <button type="button" class="button open-einsatzgebiet-editor"
                        data-map-id="einsatzgebiet_edit"
                        data-leitstelle-id="0"
                        data-center=""
                        data-context="neben">
                    Einsatzgebiet bearbeiten
                </button>

                <button type="button"
                        class="button"
                        id="btn-open-zuordnung-neben"
                        disabled
                        title="Bitte zuerst speichern">
                    Zuordnung der Wachen bearbeiten
                </button>
            </div>

            <div class="lst-nebenstellen-copy-action">
                <div>
                    <strong>Leitstelle als Vorlage übernehmen</strong>
                    <p>Übernimmt Standort, Einsatzgebiet und Wachen in eine spielbare Leitstelle.</p>
                </div>
                <button type="button" class="button button-secondary open-copy-leit-modal">
                    Leitstelle übernehmen
                </button>
            </div>
        </div>
        </div>

        <div class="lst-nebenstelle-modal__footer">
            <button class="button button-primary" id="nebenstelle-save-button">Speichern</button>
            <button type="button" class="button" onclick="closeNebenstellePopup()">Abbrechen</button>
        </div>
    </form>
</div>
<!-- Copy-Leitstelle Modal (initial ausgeblendet) -->
<div id="copy-leit-modal" class="hidden" style="position:fixed;top:0;left:0;
     width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;
     align-items:center;justify-content:center;z-index:1000;">
  <div style="background:#fff;padding:20px;border-radius:4px;
              width:320px;max-width:90%;">
    <h2 style="margin-top:0">Leitstelle übernehmen</h2>
    <p>
      <label for="copy_ls_search"><strong>Filter:</strong></label><br>
      <input type="text" id="copy_ls_search"
             placeholder="Leitstelle suchen…" style="width:100%;box-sizing:border-box;">
    </p>
    <p>
      <label for="copy_ls_select"><strong>Leitstelle wählen:</strong></label><br>
      <select id="copy_ls_select" style="width:100%;box-sizing:border-box;">
        <option value="0">– bitte wählen –</option>
      </select>
    </p>
    <p style="text-align:right;margin-top:1em;">
      <button type="button" id="cancel-copy-leit" class="button">Abbrechen</button>
      <button type="button" id="confirm-copy-leit" class="button button-primary" disabled>
        Diese Leitstelle übernehmen
      </button>
    </p>

  </div>
</div>
<script>
/* helper to close the popup */
function closeNebenstellePopup () {
  document.getElementById('popup-overlay').style.display        = 'none';
  document.getElementById('edit-nebenstelle-formular').style.display = 'none';
}
(function () {
  const input = document.getElementById('nebenstellen-search');
  const table = document.getElementById('nebenstellen-table');
  if (!input || !table) return;

  const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : table.querySelector('tbody');
  if (!tbody) return;

  // Mehrfaches Binden verhindern
  if (input._lstFilterBound) return;
  input._lstFilterBound = true;

  input.addEventListener('input', function () {
    const term = input.value.trim().toLowerCase();
    // Alle Zeilen durchgehen (ohne thead)
    tbody.querySelectorAll('tr').forEach(function (row) {
      // Falls du später data-search setzt, nimm row.dataset.search || …
      const hay = (row.innerText || '').toLowerCase();
      row.style.display = term === '' || hay.includes(term) ? '' : 'none';
    });
  });

  // Falls beim Laden schon Text im Input steht → sofort anwenden
  input.dispatchEvent(new Event('input'));
	
	
	
	
})();
</script>
