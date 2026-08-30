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
require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

$pdo          = lsttraining_get_connection();
$nebenstellen = [];
$suchbegriff  = isset($_GET['suchbegriff']) ? sanitize_text_field(wp_unslash((string) $_GET['suchbegriff'])) : '';
$orderby      = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'id';
$order        = isset($_GET['order']) ? strtolower((string) $_GET['order']) : 'asc';

if ( ! function_exists( 'lsttraining_nebenstellen_detect_land' ) ) {
    function lsttraining_nebenstellen_detect_land( string $name, string $zustandigkeit ): string {
        $haystack = str_replace(
            [ 'Ä', 'Ö', 'Ü' ],
            [ 'ä', 'ö', 'ü' ],
            strtolower( $name . ' ' . $zustandigkeit )
        );
        $countries = [
            'Österreich' => [
                'österreich', 'austria', 'burgenland', 'kärnten', 'kaernten', 'niederösterreich',
                'niederoesterreich', 'oberösterreich', 'oberoesterreich', 'salzburg', 'steiermark',
                'tirol', 'vorarlberg', 'wien',
            ],
            'Deutschland' => [
                'deutschland', 'germany', 'baden-württemberg', 'baden-wuerttemberg', 'bayern',
                'berlin', 'brandenburg', 'bremen', 'hamburg', 'hessen', 'mecklenburg-vorpommern',
                'niedersachsen', 'nordrhein-westfalen', 'rheinland-pfalz', 'saarland', 'sachsen',
                'sachsen-anhalt', 'schleswig-holstein', 'thüringen', 'thueringen',
            ],
        ];

        foreach ( $countries as $country => $needles ) {
            foreach ( $needles as $needle ) {
                if ( $needle !== '' && strpos( $haystack, $needle ) !== false ) {
                    return $country;
                }
            }
        }

        return '';
    }
}

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

/* --- delete & update  (identisch zu deiner Version) -------------------- */
if ( isset( $_GET['delete_id'] ) && $pdo ) {
    $pdo->prepare( 'DELETE FROM nebenleitstellen WHERE id = ?' )
        ->execute( [ intval( $_GET['delete_id'] ) ] );
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['neben_update_id'] ) && $pdo ) {
    $pdo->prepare(
        'UPDATE nebenleitstellen
            SET name = ?, zustandigkeit = ?, einwohner = ?, flaeche_km2 = ?,
                gps = ?, nachbarleitstelle = ?, geojson = ?
          WHERE id = ?'
    )->execute( [
        sanitize_text_field( $_POST['neben_update_name'] ),
        sanitize_text_field( $_POST['neben_update_zustandigkeit'] ),
        intval   ( $_POST['neben_update_einwohner'] ),
        floatval ( $_POST['neben_update_flaeche'] ),
        sanitize_text_field( $_POST['neben_update_gps'] ),
        intval   ( $_POST['neben_update_nachbar'] ),
        stripslashes( $_POST['geojson_edit'] ?? '' ),
        intval   ( $_POST['neben_update_id'] )
    ] );
}

/* -------------------------------------------------------------------------
 * LIST – fetch only small cols + Boolean flag (no big JSON)
 * ---------------------------------------------------------------------- */
if ($pdo) {
    $sql = '
        SELECT
            n.id,
            n.name,
            n.zustandigkeit,
            n.einwohner,
            n.flaeche_km2,
            n.gps,
            (CHAR_LENGTH(TRIM(COALESCE(n.geojson, ""))) > 2) AS has_geojson,
            COALESCE(cnt.cnt, 0) AS wachen_cnt
        FROM nebenleitstellen AS n
        LEFT JOIN (
            SELECT nebenleitstelle_id, COUNT(*) AS cnt
            FROM wache_nebenleitstellen
            GROUP BY nebenleitstelle_id
        ) AS cnt
          ON cnt.nebenleitstelle_id = n.id';

    $args = [];

    if ($suchbegriff !== '') {
        $sql  .= ' WHERE n.name LIKE ? OR n.id = ?';
        $args  = [ "%$suchbegriff%", $suchbegriff ];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $nebenstellen = $stmt->fetchAll(PDO::FETCH_OBJ);

    foreach ($nebenstellen as $n) {
        $n->land = lsttraining_nebenstellen_detect_land(
            (string) ($n->name ?? ''),
            (string) ($n->zustandigkeit ?? '')
        );
    }

    $order_factor = ($order === 'desc') ? -1 : 1;
    $sort_key = in_array($orderby, ['id', 'name', 'land', 'wachen'], true) ? $orderby : 'id';
    usort($nebenstellen, static function ($a, $b) use ($sort_key, $order_factor) {
        if ($sort_key === 'id') {
            $cmp = (int) $a->id <=> (int) $b->id;
        } elseif ($sort_key === 'wachen') {
            $cmp = (int) $a->wachen_cnt <=> (int) $b->wachen_cnt;
        } else {
            $field = $sort_key === 'land' ? 'land' : 'name';
            $cmp = strnatcasecmp((string) ($a->{$field} ?? ''), (string) ($b->{$field} ?? ''));
        }

        return $cmp * $order_factor;
    });
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['neben_create'] ) && $pdo ) {
    $stmt = $pdo->prepare(
        'INSERT INTO nebenleitstellen
         (name,zustandigkeit,einwohner,flaeche_km2,gps,nachbarleitstelle,geojson)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        sanitize_text_field( $_POST['neben_update_name'] ),
        sanitize_text_field( $_POST['neben_update_zustandigkeit'] ),
        intval( $_POST['neben_update_einwohner'] ),
        floatval( $_POST['neben_update_flaeche'] ),
        sanitize_text_field( $_POST['neben_update_gps'] ),
        intval( $_POST['neben_update_nachbar'] ?? 0 ),
        stripslashes( $_POST['geojson_edit'] ?? '[]' ),
    ]);
    wp_safe_redirect( admin_url( 'admin.php?page=lsttraining_nebenstellen' ) );
    exit;
}


?>
<div class="wrap">
    <h1>Nebenleitstellen verwalten</h1>

    <div style="margin-bottom:20px;">
        <input id="nebenstellen-search" data-target="#nebenstellen-table" type="text" placeholder="Suchen nach Name oder ID …" value="<?php echo esc_attr($suchbegriff); ?>" style="width:300px;">
		<button    id="create-nebenstelle"    class="button"     data-next-id="<?php echo esc_attr( $nextId ); ?>"    style="margin-left:10px;"
>+ Nebenstelle erstellen</button>
	</div>

    <table class="widefat" id="nebenstellen-table">
        <thead>
            <tr><th><?php echo lsttraining_nebenstellen_sort_link('ID', 'id', $orderby, $order); ?></th><th><?php echo lsttraining_nebenstellen_sort_link('Name', 'name', $orderby, $order); ?></th><th><?php echo lsttraining_nebenstellen_sort_link('Land', 'land', $orderby, $order); ?></th><th>Zuständigkeit</th><th>Einwohner</th>
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
      "loadNebenstelleAndOpen(%d, %s, %s, %d, %f, %s, %d); return false;",
      $n->id,
      json_encode($n->name),
      json_encode($n->zustandigkeit),
      (int)$n->einwohner,
      (float)$n->flaeche_km2,
      json_encode($n->gps),
      0
    );
  ?>
  <tr class="<?php echo esc_attr($rowClass); ?>">
    <td><?php echo esc_html($n->id); ?></td>
    <td><?php echo esc_html($n->name); ?></td>
    <td><?php echo esc_html($n->land ?: '–'); ?></td>
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
