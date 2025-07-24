<?php
/**
 * Editor for playable dispatch centres (“Leitstellen”)
 * – GeoJSON is loaded via JavaScript and saved in leitstellen.geojson
 *   v2025-05-04  • admin notice instead of late redirect (no header warnings)
 */


$leitstelle_id = isset( $_GET['ls_id'] )
    ? intval( $_GET['ls_id'] )
    : 0;

require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

$pdo         = lsttraining_get_connection();
$leitstellen = [];
$suchbegriff = isset( $_GET['suchbegriff'] ) ? $_GET['suchbegriff'] : '';


if ( ! lsttraining_user_can( 'leitstellen', $leitstelle_id ) ) {
    wp_die( 'Keine Berechtigung.' );
}


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
 * CREATE
 * ---------------------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST'
     && isset( $_POST['lst_form_mode'] )
     && $_POST['lst_form_mode'] === 'create'
     && $pdo ) {

    // Neue Leitstelle schreiben
    $stmt = $pdo->prepare(
        'INSERT INTO leitstellen
             (name, ort, bundesland, land, latitude, longitude, geojson)
         VALUES (?,?,?,?,?,?,?)'
    );

    $stmt->execute( [
        sanitize_text_field( $_POST['lst_update_name'] ),
        sanitize_text_field( $_POST['lst_update_ort'] ),
        sanitize_text_field( $_POST['lst_update_bl'] ),
        sanitize_text_field( $_POST['lst_update_land'] ),
        floatval( $_POST['lst_update_lat'] ),
        floatval( $_POST['lst_update_lon'] ),
        wp_unslash( $_POST['geojson_edit'] ?? '' ),
    ] );

    add_settings_error(
        'lsttraining_msg',
        'lst_ok',
        'Leitstelle angelegt.',
        'updated'
    );
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
	
	<button id="btn-new-leitstelle" class="button button-primary">
    + Neue Leitstelle
</button>

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
        onclick="window.location.href='<?php 
          echo admin_url( 'admin.php?page=lsttraining_leitstellen_wachen' );
        ?>&ls_id='+document.getElementById('lst_update_id').value;">
    Wachen bearbeiten
</button>
<input type="hidden" id="lst_update_id" name="ls_id" 
       value="<?php echo esc_attr( $leitstelle_id ); ?>">

<button
   type="button"
   class="button open-leitstelle-hospitals-editor"
   style="margin-left:10px;"
>
   Krankenhäuser bearbeiten
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


<script type="text/html" id="tmpl-leitstellen-hospitals-editor">
  <div class="leitstellen-hospitals-content">
    <form id="leitstellen-hospitals-form">
      <input type="hidden" name="leitstelle_id" value="{{ data.leitstelle_id }}">

      <!-- 0) Karte mit Einsatzgebiet & Krankenhäusern -->
      <div id="leitstellen-hospitals-map" style="height:300px; margin-bottom:1em; border:1px solid #ccc;"></div>
	  <input
  type="text"
  id="leitstellen-hospitals-filter"
  placeholder="Nach ID oder Name filtern…"
  style="width:100%; margin-bottom:8px; padding:4px; box-sizing:border-box;"
>
      <!-- 1) Checkbox-Liste -->
      <div id="leitstellen-hospitals-selector" style="max-height:200px; overflow-y:auto; margin-bottom:1em;">
        <# _.each( data.hospitals, function( h ){ #>
          <label style="display:block; padding:4px;">
            <input class="hos-toggle" type="checkbox" value="{{ h.id }}">
            {{ h.name }}
          </label>
        <# }); #>
      </div>

      <!-- 2) Beschreibung und Buttons -->
      <p class="description">
        Wähle hier aus, welche Krankenhäuser für diese Leitstelle verfügbar sein sollen.
      </p>

      <p class="submit" style="display:flex; gap:0.5em;">
        <button type="submit" class="button button-primary">Speichern</button>
        <button type="button" id="leitstellen-hospitals-cancel" class="button">Abbrechen</button>
      </p>
    </form>
  </div>
</script>


<!-- Modal-Container -->
<div id="leitstellen-hospitals-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2><?php esc_html_e('Krankenhäuser für Leitstelle bearbeiten','lsttraining'); ?></h2>
      <button class="modal-close">×</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

