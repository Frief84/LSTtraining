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
	    // Log: Leitstelle gelöscht
    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'delete',
        'entity_id'   => (int)$_GET['delete_id'],
        'meta'        => ['page' => 'leitstellen_editor.php']
    ]);
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
	
   $new_id = (int)$pdo->lastInsertId();
    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'create',
        'entity_id'   => $new_id,
        'meta'        => ['page' => 'leitstellen_editor.php']
    ]);

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
	
   lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'update',
        'entity_id'   => (int)$leitstelle_id,
        'meta'        => ['page' => 'leitstellen_editor.php']
    ]);

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
<input type="hidden" id="current_leitstelle_id" name="ls_id" 
       value="<?php echo esc_attr( $leitstelle_id ); ?>">

<button
   type="button"
   class="button open-leitstelle-hospitals-editor"
   style="margin-left:10px;"
>
   Krankenhäuser bearbeiten
</button>

<button
   type="button"
   class="button open-leitstelle-pois-editor"
   style="margin-left:10px;"
>
   POIs bearbeiten
</button>

<button
   type="button"
   class="button"
   id="btn-osm-refresh"
   style="margin-left:10px;"
   title="Geänderte OSM-Tiles für diese Leitstelle anwenden"
>OSM Tiles sync</button>

<span id="lst-osm-refresh-spinner" class="spinner" style="float:none; margin-left:6px; visibility:hidden;"></span>

<div id="lst-osm-refresh-status" class="notice inline" style="display:none; margin-top:10px; padding:8px;"></div>
<?php
$zuo_url = admin_url( 'admin.php?page=lsttraining_zuordnung_modal'
    . '&entity_type=leitstelle&entity_id=' . intval($leitstelle_id)
    . '&TB_iframe=true&width=1100&height=760' );
?>
<button type="button"
        class="button"
        id="w_zuord_button_l"
        style="margin-left:10px;"
        disabled
        title="Bitte zuerst speichern">
  Zuordnung der Wachen bearbeiten
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
<!-- Krankenhäuser-Modal -->
<div id="leitstellen-hospitals-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2>Krankenhäuser bearbeiten</h2>
      <button type="button" class="modal-close" aria-label="Schließen">&times;</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

<script type="text/html" id="tmpl-leitstellen-hospitals-editor">
  <form id="leitstellen-hospitals-form">
    <p class="description">
      Hier können Krankenhäuser dieser Leitstelle zugeordnet werden.
    </p>

    <p>
      <label for="leitstellen-hospitals-filter"><strong>Krankenhäuser filtern</strong></label><br>
      <input
        type="text"
        id="leitstellen-hospitals-filter"
        placeholder="Nach Name oder ID filtern …"
        style="width:100%;"
        autocomplete="off"
      >
    </p>

    <div id="leitstellen-hospitals-map" style="width:100%; height:400px; margin-bottom:15px;"></div>

    <div
      id="leitstellen-hospitals-selector"
      style="max-height:300px; overflow:auto; border:1px solid #ddd; padding:10px; margin-bottom:15px;"
    >
      <# _.each(data.hospitals || [], function(h){ #>
        <label
          style="display:block; margin:0 0 8px 0;"
          class="hospital-row"
          data-id="{{ h.id }}"
          data-name="{{ (h.name || '').toLowerCase() }}"
        >
          <input
            type="checkbox"
            class="hos-toggle"
            value="{{ h.id }}"
            <# if (_.contains(data.selected_ids || [], String(h.id)) || _.contains(data.selected_ids || [], h.id)) { #>checked<# } #>
          >
          <strong>{{ h.name || ('Krankenhaus #' + h.id) }}</strong>
          <span class="description">
            (#{{ h.id }})
            <# if (h.versorgungsstufe) { #> · {{ h.versorgungsstufe }}<# } #>
            <# if (h.trauma_level) { #> · Trauma {{ h.trauma_level }}<# } #>
          </span>
        </label>
      <# }); #>
    </div>

    <p>
      <button type="submit" class="button button-primary">Speichern</button>
      <button type="button" class="button" id="leitstellen-hospitals-cancel">Abbrechen</button>
    </p>
  </form>
</script>
<!-- POI-Modal -->
<div id="leitstellen-pois-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2>POIs bearbeiten</h2>
      <button type="button" class="modal-close" aria-label="Schließen">&times;</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

<script type="text/html" id="tmpl-leitstellen-pois-editor">
  <div class="leitstellen-pois-editor-wrap">
    <p class="description">
      POIs dieser Leitstelle verwalten. Klick in die Karte setzt Koordinaten für den Editor.
    </p>

    <p>
      <label for="leitstellen-pois-filter"><strong>POIs filtern</strong></label><br>
      <input
        type="text"
        id="leitstellen-pois-filter"
        placeholder="Nach Name, Typ oder ID filtern …"
        style="width:100%;"
        autocomplete="off"
      >
    </p>

    <div id="leitstellen-pois-map" style="width:100%; height:400px; margin-bottom:15px;"></div>

    <div
      id="lst-poi-list"
      style="max-height:260px; overflow:auto; border:1px solid #ddd; padding:10px; margin-bottom:15px;"
    >
      <div class="lst-poi-list-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <strong>Vorhandene POIs</strong>
        <button type="button" class="button" id="lst-poi-close-list">Schließen</button>
      </div>

      <div class="lst-poi-list-body">
        <table class="widefat striped" id="leitstellen-pois-table">
          <thead>
            <tr>
              <th style="width:70px;">ID</th>
              <th style="width:140px;">Typ</th>
              <th>Name</th>
              <th style="width:100px;">Genus</th>
              <th style="width:170px;">Koordinaten</th>
            </tr>
          </thead>
          <tbody>
            <# _.each(data.pois || [], function(p){ #>
              <tr
                class="poi-row"
                data-id="{{ p.id }}"
                data-name="{{ (p.name || '').toLowerCase() }}"
                data-type="{{ (p.poi_type || '').toLowerCase() }}"
              >
                <td>{{ p.id }}</td>
                <td>{{ p.poi_type || '' }}</td>
                <td>{{ p.name || '' }}</td>
                <td>{{ p.genus || 'der' }}</td>
                <td>{{ p.latitude || '' }}, {{ p.longitude || '' }}</td>
              </tr>
            <# }); #>
          </tbody>
        </table>
      </div>
    </div>

    <div id="lst-poi-editor" style="border:1px solid #ddd; padding:15px; margin-bottom:15px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <div>
          <strong>POI bearbeiten</strong><br>
          <span class="description">Neu anlegen oder bestehenden POI ändern.</span>
        </div>
        <button type="button" class="button" id="lst-poi-editor-close">Schließen</button>
      </div>

      <form id="leitstellen-pois-form">
        <input type="hidden" id="poi_id" value="">

        <p>
          <label for="poi_type"><strong>Typ</strong></label><br>
          <select id="poi_type" style="width:100%;" required>
            <option value="">Bitte wählen …</option>
            <# _.each(data.poi_types || [], function(t){ #>
              <option value="{{ t.tag || t.value || t.slug || t.id }}">
                {{ t.label || t.name || t.tag || t.value || t.slug || t.id }}
              </option>
            <# }); #>
          </select>
        </p>

        <p id="poi_type_desc" class="description"></p>

        <p>
          <label for="poi_name"><strong>Name</strong></label><br>
          <input type="text" id="poi_name" style="width:100%;" value="">
        </p>

        <p>
          <label for="poi_comment"><strong>Kommentar</strong></label><br>
          <textarea id="poi_comment" rows="4" style="width:100%;"></textarea>
        </p>

        <p>
          <label for="poi_genus"><strong>Genus</strong></label><br>
          <select id="poi_genus" style="width:100%;">
            <option value="der">der</option>
            <option value="die">die</option>
            <option value="das">das</option>
          </select>
        </p>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <p style="flex:1 1 200px;">
            <label for="poi_lat"><strong>Breitengrad</strong></label><br>
            <input type="number" step="0.000001" id="poi_lat" style="width:100%;" required>
          </p>

          <p style="flex:1 1 200px;">
            <label for="poi_lon"><strong>Längengrad</strong></label><br>
            <input type="number" step="0.000001" id="poi_lon" style="width:100%;" required>
          </p>
        </div>

        <p>
          <button type="submit" class="button button-primary">Speichern</button>
          <button type="button" class="button" id="leitstellen-pois-delete" disabled>Löschen</button>
          <button type="button" class="button" id="leitstellen-pois-cancel">Abbrechen</button>
        </p>
      </form>
    </div>

    <div id="lst-poi-import-panel" class="hidden" style="border:1px solid #ddd; padding:15px; margin-bottom:15px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <strong>POIs importieren</strong>
        <button type="button" class="button" id="lst-poi-import-close">Schließen</button>
      </div>

      <p class="description">
        Mehrere POIs zeilenweise einfügen und vor dem Import prüfen.
      </p>

      <textarea id="lst-poi-import-text" rows="10" style="width:100%;"></textarea>

      <p style="margin-top:10px;">
        <button type="button" class="button" id="lst-poi-import-parse">Vorschau erzeugen</button>
        <button type="button" class="button button-primary" id="lst-poi-import-run" disabled>Importieren</button>
      </p>

      <div id="lst-poi-import-preview" style="margin-top:12px;"></div>
    </div>

    <p style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button type="button" class="button" id="lst-poi-toggle-list">Liste</button>
      <button type="button" class="button" id="lst-poi-open-editor">Editor</button>
      <button type="button" class="button" id="leitstellen-pois-new">Neu</button>
      <button type="button" class="button" id="lst-poi-import">Import</button>
    </p>
  </div>
</script>
