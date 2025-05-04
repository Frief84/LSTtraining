<?php
/**
 * Nebenleitstellen-Editor – memory-safe list query
 * v2025-05-05
 */

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Keine Berechtigung.' );
}

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

?>
<div class="wrap">
    <h1>Nebenleitstellen verwalten</h1>

    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="lsttraining_nebenleitstellen">
        <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID …"
               value="<?php echo esc_attr( $suchbegriff ); ?>" style="width:300px;">
        <button class="button">Suchen</button>
    </form>

    <table class="widefat">
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
                    "editNebenstelle(%d, %s, %s, %d, %f, %s, %d, '') ; return false;",
                    $n->id,
                    json_encode( $n->name ),
                    json_encode( $n->zustandigkeit ),
                    $n->einwohner,
                    $n->flaeche_km2,
                    json_encode( $n->gps ),
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
                    <a href="#" class="button" onclick="<?php echo htmlspecialchars( $onclick ); ?>">Bearbeiten</a>
                    <a href="<?php echo admin_url(
                        'admin.php?page=lsttraining_nebenleitstellen&delete_id=' . $n->id ); ?>"
                       class="button button-link-delete"
                       onclick="return confirm('Wirklich löschen?');">Löschen</a>
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
        <table class="form-table">
            <tr><td>Name</td>          <td><input type="text"  name="neben_update_name"          id="neben_update_name" required></td></tr>
            <tr><td>Zuständigkeit</td> <td><input type="text"  name="neben_update_zustandigkeit" id="neben_update_zustandigkeit"></td></tr>
            <tr><td>Einwohner</td>     <td><input type="number"name="neben_update_einwohner"     id="neben_update_einwohner"></td></tr>
            <tr><td>Fläche (km²)</td>  <td><input type="number" step="0.01" name="neben_update_flaeche" id="neben_update_flaeche"></td></tr>
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
        </div>

        <p style="margin-top:1rem;">
            <button class="button button-primary">Speichern</button>
            <button type="button" class="button" onclick="closeNebenstellePopup()">Abbrechen</button>
        </p>
    </form>
</div>

<script>
/* helper to close the popup */
function closeNebenstellePopup () {
  document.getElementById('popup-overlay').style.display        = 'none';
  document.getElementById('edit-nebenstelle-formular').style.display = 'none';
}
</script>
