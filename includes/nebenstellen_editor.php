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


$base = plugin_dir_url( __FILE__ ) . '..';
require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

wp_enqueue_script(
    'lsttraining-einsatzgebiet-editor',
    $base . '/js/einsatzgebiet-editor.js',
    [ 'jquery' ],
    '1.0',
    true
);

$pdo          = lsttraining_get_connection();
$nebenstellen = [];
$suchbegriff  = $_GET['suchbegriff'] ?? '';


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
if ( $pdo ) {
    $sql  = 'SELECT id,name,zustandigkeit,einwohner,flaeche_km2,gps,
                    (CHAR_LENGTH(TRIM(COALESCE(geojson,""))) > 2) AS has_geojson
               FROM nebenleitstellen';
    $args = [];

    if ( $suchbegriff !== "" ) {
        $sql .= ' WHERE name LIKE ? OR id = ?';
        $args = [ "%$suchbegriff%", $suchbegriff ];
    }
    /* intentionally no ORDER BY → avoids large sort buffer */

    $stmt = $pdo->prepare( $sql );
    $stmt->execute( $args );
    $nebenstellen = $stmt->fetchAll( PDO::FETCH_OBJ );
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
    wp_safe_redirect( admin_url( 'admin.php?page=lsttraining_nebenleitstellen' ) );
    exit;
}


?>
<div class="wrap">
    <h1>Nebenleitstellen verwalten</h1>

    <div style="margin-bottom:20px;">
  		<input id="nebenstellen-search" data-target="#nebenstellen-table" type="text" placeholder="Suchen nach Name oder ID …" style="width:300px;">
		<button    id="create-nebenstelle"    class="button"     data-next-id="<?php echo esc_attr( $nextId ); ?>"    style="margin-left:10px;"
>+ Nebenstelle erstellen</button>
	</div>

    <table class="widefat" id="nebenstellen-table">
        <thead>
            <tr><th>ID</th><th>Name</th><th>Zuständigkeit</th><th>Einwohner</th>
                <th>Fläche</th><th>Standort</th><th>Einsatzgebiet</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
        <?php foreach ( $nebenstellen as $n ) : ?>
            <?php
                /* row colouring */
                $missingGps = empty( trim( $n->gps ) ) || strtolower( $n->gps ) === 'none';
                $rowClass   = $missingGps && ! $n->has_geojson ? 'missing-both'
                            : ( $missingGps ? 'missing-gps'
                            : ( ! $n->has_geojson ? 'missing-geojson' : '' ) );

                /* onclick (GeoJSON loaded via Ajax in editor) */
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
            <tr class="<?php echo esc_attr( $rowClass ); ?>">
                <td><?php echo esc_html( $n->id ); ?></td>
                <td><?php echo esc_html( $n->name ); ?></td>
                <td><?php echo esc_html( $n->zustandigkeit ); ?></td>
                <td><?php echo esc_html( $n->einwohner ); ?></td>
                <td><?php echo esc_html( $n->flaeche_km2 ); ?></td>
                <td><?php echo esc_html( $n->gps ); ?></td>
                <td><?php echo $n->has_geojson ? '✅' : '❌'; ?></td>
                <td>
                    <a href="#" class="button"  onclick="<?php echo htmlspecialchars( $onclick ); ?>" style="    margin-bottom: 5px;">Bearbeiten</a>
                    <button type="button" class="button button-link-delete js-delete-nebenstelle" data-id="<?php echo esc_attr( $n->id ); ?>" style="width: 85px;">
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
<div id="edit-nebenstelle-formular"
     style="display:none; position:fixed; top:5%; left:50%; transform:translateX(-50%);
            background:#fff; padding:20px; border:1px solid #ccc; z-index:9999;
            max-width:750px; width:95%; box-shadow:0 0 12px rgba(0,0,0,.3)">

    <h2>Nebenleitstelle bearbeiten</h2>

    <form method="post">
        <input type="hidden" name="neben_update_id" id="neben_update_id">
		<input type="hidden" name="neben_create" id="neben_create" value="0">
        <table class="form-table">
            <tr><td>Name</td>          <td><input type="text"  name="neben_update_name"          id="neben_update_name" required></td></tr>
            <tr><td>Zuständigkeit</td> <td><input type="text"  name="neben_update_zustandigkeit" id="neben_update_zustandigkeit"></td></tr>
            <tr><td>Einwohner</td>     <td><input type="number"name="neben_update_einwohner"     id="neben_update_einwohner"></td></tr>
            <tr><td>Fläche (km²)</td>  <td><input type="number" step="0.01" name="neben_update_flaeche" id="neben_update_flaeche"> <button type="button" id="calc-flaeche" class="button">Berechnen</button></td></tr>
            <tr><td>Standort</td>      <td><input type="text"  name="neben_update_gps"           id="neben_update_gps" placeholder="z.B. 48.12345, 9.12345"></td></tr>
            <tr><td colspan="2"><div id="nebenstelle_map" style="height:250px;"></div></td></tr>
            <tr style="display:none"><td>Nachbarleitstelle</td><td><input type="number" name="neben_update_nachbar" id="neben_update_nachbar"></td></tr>
        </table>

        <input type="hidden" name="geojson_edit" id="geojson_edit" value="[]">

        <div class="form-map" id="einsatzgebiet_container">
            <button type="button" class="button open-einsatzgebiet-editor"
                    data-map-id="einsatzgebiet_edit"
                    data-leitstelle-id="0"
                    data-center=""
                    data-context="neben">
                Einsatzgebiet bearbeiten
            </button>
					  <!-- Button öffnet Modal -->
  <button
    type="button"
    class="button button-secondary open-copy-leit-modal"
    style="margin-left:10px"
  >
    Leitstelle übernehmen
  </button>

<button type="button"
        class="button"
        id="btn-open-zuordnung-neben"
        style="margin-left:10px;"
        disabled
        title="Bitte zuerst speichern">
  Zuordnung der Wachen bearbeiten
</button>




        </div>

        <p style="margin-top:1rem;">
            <button class="button button-primary" id="nebenstelle-save-button" >Speichern</button>
            <button type="button" class="button" onclick="closeNebenstellePopup()">Abbrechen</button>
        </p>
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
