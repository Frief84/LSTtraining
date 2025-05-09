<?php
/**
 * Editor for playable dispatch centres (“Leitstellen”)
 * – GeoJSON is loaded via JavaScript and saved in leitstellen.geojson
 *   v2025-05-04  • admin notice instead of late redirect (no header warnings)
 */

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Keine Berechtigung.' );
}

require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

$pdo         = lsttraining_get_connection();
$leitstellen = [];
$suchbegriff = isset( $_GET['suchbegriff'] ) ? $_GET['suchbegriff'] : '';

/* -------------------------------------------------------------------------
 * DELETE
 * ---------------------------------------------------------------------- */
if ( isset( $_GET['delete_id'] ) && $pdo ) {
    $pdo->prepare( 'DELETE FROM leitstellen WHERE id = ?' )
        ->execute( [ intval( $_GET['delete_id'] ) ] );
    add_settings_error( 'lsttraining_msg', 'deleted',
        'Leitstelle gelöscht.', 'updated' );
}

/* -------------------------------------------------------------------------
 * UPDATE
 * ---------------------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['lst_update_id'] ) && $pdo ) {

    /* basic data */
    $pdo->prepare(
        'UPDATE leitstellen
            SET name = ?,
                ort = ?,
                bundesland = ?,
                land = ?,
                latitude = ?,
                longitude = ?
          WHERE id = ?'
    )->execute( [
        sanitize_text_field( $_POST['lst_update_name'] ),
        sanitize_text_field( $_POST['lst_update_ort'] ),
        sanitize_text_field( $_POST['lst_update_bl'] ),
        sanitize_text_field( $_POST['lst_update_land'] ),
        floatval( $_POST['lst_update_lat'] ),
        floatval( $_POST['lst_update_lon'] ),
        intval( $_POST['lst_update_id'] )
    ] );

    /* GeoJSON (accept both field names) */
    $geojson = '';
    if ( isset( $_POST['geojson_edit'] ) ) {
        $geojson = stripslashes( $_POST['geojson_edit'] );
    } elseif ( isset( $_POST['geojson_einsatzgebiet_edit'] ) ) {
        $geojson = stripslashes( $_POST['geojson_einsatzgebiet_edit'] );
    }
    if ( $geojson !== '' ) {
        $pdo->prepare(
            'UPDATE leitstellen SET geojson = ? WHERE id = ?'
        )->execute( [ $geojson, intval( $_POST['lst_update_id'] ) ] );
    }

    /* success notice */
    add_settings_error( 'lsttraining_msg', 'saved',
        'Leitstelle gespeichert.', 'updated' );
}

/* -------------------------------------------------------------------------
 * LIST
 * ---------------------------------------------------------------------- */
if ( $pdo ) {
    if ( $suchbegriff !== '' ) {
        $stmt = $pdo->prepare(
            'SELECT id,name,ort,bundesland,land,latitude,longitude
               FROM leitstellen
              WHERE name LIKE ?
                 OR id = ?
           ORDER BY name ASC'
        );
        $stmt->execute( [ '%' . $suchbegriff . '%', $suchbegriff ] );
    } else {
        $stmt = $pdo->query(
            'SELECT id,name,ort,bundesland,land,latitude,longitude
               FROM leitstellen
           ORDER BY name ASC'
        );
    }
    $leitstellen = $stmt->fetchAll( PDO::FETCH_OBJ );
}
?>

<div class="wrap">
    <h1>Leitstellen verwalten</h1>

    <?php settings_errors( 'lsttraining_msg' ); ?>

    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="lsttraining_leitstellen">
        <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID …"
               value="<?php echo esc_attr( $suchbegriff ); ?>" style="width:300px;">
        <button class="button">Suchen</button>
    </form>

    <table class="widefat">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Ort</th>
                <th>Bundesland</th><th>Land</th><th>Koordinaten</th><th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $leitstellen as $l ) : ?>
            <tr>
                <td><?php echo esc_html( $l->id ); ?></td>
                <td><?php echo esc_html( $l->name ); ?></td>
                <td><?php echo esc_html( $l->ort ); ?></td>
                <td><?php echo esc_html( $l->bundesland ); ?></td>
                <td><?php echo esc_html( $l->land ); ?></td>
                <td><?php echo esc_html( $l->latitude ); ?>,&nbsp;<?php echo esc_html( $l->longitude ); ?></td>
                <td>
                    <a href="#" class="button edit-leitstelle"
                       data-id="<?php echo esc_attr( $l->id ); ?>"
                       data-name="<?php echo esc_attr( $l->name ); ?>"
                       data-ort="<?php echo esc_attr( $l->ort ); ?>"
                       data-bl="<?php echo esc_attr( $l->bundesland ); ?>"
                       data-land="<?php echo esc_attr( $l->land ); ?>"
                       data-lat="<?php echo esc_attr( $l->latitude ); ?>"
                       data-lon="<?php echo esc_attr( $l->longitude ); ?>"
                    >Bearbeiten</a>
                    <a href="<?php echo admin_url(
                        'admin.php?page=lsttraining_leitstellen&delete_id=' . $l->id ); ?>"
                       class="button button-link-delete"
                       onclick="return confirm('Wirklich löschen?');"
                    >Löschen</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Popup overlay -->
<div id="popup-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:9998;"></div>

<!-- Edit popup -->
<div id="edit-leitstelle-formular" style="display:none; position:fixed; top:5%; left:50%; transform:translateX(-50%);
        background:#fff; padding:20px; max-width:800px; width:90%;
        border:1px solid #ccc; z-index:9999; box-shadow:0 0 15px rgba(0,0,0,.3);">

    <h2>Leitstelle bearbeiten</h2>

    <form method="post" style="display:flex; flex-wrap:wrap; gap:20px;">
        <div style="flex:1 1 48%;">
            <input type="hidden" name="lst_update_id" id="lst_update_id">
            <table class="form-table">
                <tr><td>Name</td><td><input type="text" name="lst_update_name" id="lst_update_name" required></td></tr>
                <tr><td>Ort</td><td><input type="text" name="lst_update_ort" id="lst_update_ort"></td></tr>
                <tr><td>Bundesland</td><td><input type="text" name="lst_update_bl" id="lst_update_bl"></td></tr>
                <tr><td>Land</td><td><input type="text" name="lst_update_land" id="lst_update_land"></td></tr>
                <tr>
                    <td>Koordinaten</td>
                    <td>
                        <input type="number" step="0.000001" name="lst_update_lat" id="lst_update_lat">
                        <input type="number" step="0.000001" name="lst_update_lon" id="lst_update_lon">
                    </td>
                </tr>
            </table>
        </div>

        <div style="flex:1 1 48%;"><div id="map_edit" style="height:300px;"></div></div>

        <div style="width:100%;">
<?php
/* hidden polygon field + invisible map container (filled via JS) */
lsttraining_einsatzgebiet_editor(
    'einsatzgebiet_edit',   // placeholder map ID – JS will overwrite
    'geojson_edit',         // fixed hidden field
    '', 0, 'leitstelle', ''
);
?>
<button type="button" class="button open-einsatzgebiet-editor"
        data-map-id="einsatzgebiet_edit"
        data-leitstelle-id="0"
        data-center=""
        data-context="leitstelle">
    Einsatzgebiet bearbeiten
</button>
<button type="button" class="button open-wachen-editor" style="margin-left:10px;"
        onclick="window.location.href='<?php echo admin_url('admin.php?page=lsttraining_leitstellen_wachen'); ?>&leitstelle_id='+document.getElementById('lst_update_id').value;">
    Wachen bearbeiten
</button>

        <p>
            <button class="button button-primary">Speichern</button>
            <button type="button" class="button"
                    onclick="document.getElementById('popup-overlay').style.display='none';
                             document.getElementById('edit-leitstelle-formular').style.display='none';">
                Abbrechen
            </button>
        </p>
        </div>
    </form>
</div>
