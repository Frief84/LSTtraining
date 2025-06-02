<?php
if (!current_user_can('manage_options'))
{
    wp_die('Keine Berechtigung.');
}

require_once plugin_dir_path(__FILE__) . '/db.php';
$pdo = lsttraining_get_connection();

?>

<!-- Underscore-Template für die Fachbereichs-Auswahl -->
<script type="text/html" id="tmpl-departments-editor">
    <div class="departments-edit-content">
    <form id="departments-edit-form">

      <!-- Hospital-ID verstecken -->
      <input type="hidden" name="hospital_id" value="{{ data.hospital_id }}">

      <!-- 1) Checkbox-Liste -->
<div id="departments-selector"
     style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;
            max-height:200px;overflow-y:auto;margin-bottom:1em;">

  <# _.each( data.departments, function( label, code ){ #>
    <label style="display:flex;align-items:center;">
      <input class="dept-toggle" type="checkbox" value="{{ code }}">
      <span style="margin-left:4px;">{{ label }}</span>
    </label>
  <# }); #>

</div>

      <!-- 2) Kurze Anleitung -->
      <p class="description">
        Wähle hier aus, welche Fachbereiche verfügbar sein sollen.
        In der Tabelle unten kannst du Priorität und Standort-Koordinaten anpassen.
        Klick auf eine Zeile aktiviert deren Marker zum Verschieben.
      </p>

      <!-- 3) Detail-Tabelle -->
      <table class="form-table" id="departments-details-table" style="width:100%;">
        <thead>
  <tr>
    <th><?php esc_html_e( 'Aktiv',       'lsttraining' ); ?></th>
    <th><?php esc_html_e( 'Koordinaten', 'lsttraining' ); ?></th>
  </tr>
</thead>
        <tbody>
          <!-- JS fügt hier die Zeilen ein -->
        </tbody>
      </table>

      <!-- 4) Speichern + Abbrechen -->
      <p class="submit" style="display:flex;justify-content:flex-end;gap:0.5em;">
        <button type="submit" class="button button-primary">
          <?php esc_html_e( 'Speichern', 'lsttraining' ); ?>
        </button>
        <button type="button" id="departments-edit-cancel" class="button">
          <?php esc_html_e( 'Abbrechen', 'lsttraining' ); ?>
        </button>
      </p>
    </form>

    <!-- 5) Karte -->
    <div id="dept-map"
         style="height:300px;
                width:100%;
                margin-top:20px;
                border:1px solid #ccc;">
    </div>
  </div>
</script>



<div class="wrap">
  <h1>Krankenhäuser verwalten</h1>

  <button id="btn-new-krankenhaus" class="button button-primary" style="margin-bottom: 20px;">
    + Neues Krankenhaus
  </button>

  <div id="krankenhaus-map" style="height: 400px; margin-bottom: 20px;"></div>

  <?php
$stmt = $pdo->query('SELECT id, name, versorgungsstufe, trauma_level, latitude, longitude FROM krankenhaeuser ORDER BY name');
$krankenhaeuser = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

	
  <table class="widefat fixed">
    <thead>
      <tr>
      <th data-field="id"               style="cursor:pointer;">ID</th>
      <th data-field="name"             style="cursor:pointer;">Name</th>
      <th data-field="versorgungsstufe" style="cursor:pointer;">Versorgungsstufe</th>
      <th data-field="trauma_level"     style="cursor:pointer;">Trauma-Level</th>
      <th>Koordinaten</th>
      <th>Aktionen</th>
    </tr>
    </thead>
   <tbody>
  <tr><td colspan="6">Lade Krankenhäuser…</td></tr>
</tbody>
  </table>

	
<!-- Template for the hospital edit form -->
<script type="text/html" id="tmpl-hospital-edit-form">
  <form id="hospital-edit-form">
    <input type="hidden" name="id" value="{{ data.id }}">

    <table class="form-table">
	   <tr>
        <th>ID</th>
        <td><strong>{{ data.id }}</strong></td>
      </tr>
	
      <!-- Name -->
      <tr>
        <th><label for="h-name">Name</label></th>
        <td>
          <input type="text"
                 id="h-name"
                 name="name"
                 value="{{ data.name }}"
                 class="regular-text"
                 required>
        </td>
      </tr>

      <!-- Versorgungsstufe -->
      <tr>
        <th><label for="h-versorgungsstufe">Versorgungsstufe</label></th>
        <td>
          <select id="h-versorgungsstufe" name="versorgungsstufe">
            <option value="Grundversorgung"   <# if ( data.versorgungsstufe === "Grundversorgung" ) { #>selected<# } #>>Grundversorgung</option>
            <option value="Schwerpunktversorger"<# if ( data.versorgungsstufe === "Schwerpunktversorger" ) { #>selected<# } #>>Schwerpunktversorger</option>
            <option value="Maximalversorger"  <# if ( data.versorgungsstufe === "Maximalversorger" ) { #>selected<# } #>>Maximalversorger</option>
          </select>
        </td>
      </tr>

      <!-- Trauma-Level -->
      <tr>
        <th><label for="h-trauma_level">Trauma-Level</label></th>
        <td>
          <input type="number"
                 id="h-trauma_level"
                 name="trauma_level"
                 min="0" max="3"
                 value="{{ data.trauma_level }}">
        </td>
      </tr>

      <!-- Koordinaten -->
      <tr>
        <th><label for="h-coords">Koordinaten (lat, lon)</label></th>
        <td>
          <input type="text"
                 id="h-coords"
                 name="coords"
                 value="{{ data.latitude }}, {{ data.longitude }}"
                 class="regular-text"
                 pattern="^\s*-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*$"
                 title="Format: Breitengrad, Längengrad">
          <input type="hidden" id="h-lat" name="latitude"  value="{{ data.latitude }}">
          <input type="hidden" id="h-lon" name="longitude" value="{{ data.longitude }}">
        </td>
      </tr>

 <!-- Fachbereichs-Editor -->
<tr>
        <th><label for="h-departments-button">Fachbereiche</label></th>
        <td>
          <button type="button"
                  id="h-departments-button"
                  class="button button-secondary"
                  data-id="{{ data.id }}">
            Fachbereiche bearbeiten
          </button>
        </td>
      </tr>
	<!-- Neu: Helipad Checkbox -->
	<tr>
	  <th><label for="h-helipad">Helipad</label></th>
	  <td>
		<label>
		  <input 
			  type="checkbox" 
			  id="h-helipad" 
			  name="helipad" 
			  <# if ( Number(data.helipad) ) { #>checked<# } #> 
			/>
		  Verfügbar
		</label>
	  </td>
	</tr>
      <!-- Karte -->
      <tr>
        <th>Karte<br>
<p class="hospital-map-hint">
  Bitte setzen Sie den Marker genau dort, wo die Rettungswagen (RTWs) halten bzw. am Haupteingang der Notaufnahme.
</p></th>
        <td>
          <div id="hospital-map-edit"
               style="height:300px; width:100%; border:1px solid #ccc;"></div>
          <p class="description">Marker per Drag & Drop verschieben</p>
        </td>
      </tr>
    </table>

    <p class="submit">
      <button type="submit" class="button button-primary">Speichern</button>
      <button type="button" id="hospital-edit-cancel" class="button">Abbrechen</button>
      <button type="button"
              id="hospital-delete-button"
              class="button button-link-delete"
              data-id="{{ data.id }}">
        Löschen
      </button>
    </p>
  </form>
</script>




<!-- Edit-Modal für Krankenhäuser -->
<div id="hospital-edit-modal" class="hospital-edit-modal hidden">
  <div class="hospital-edit-overlay"></div>
  <div class="hospital-edit-container">
    <h2>Krankenhaus bearbeiten</h2>
    <div class="hospital-edit-content">
      <!-- Formular wird hier per JS injiziert -->
    </div>

  </div>
</div>

<!-- Departments‐Modal -->
<div id="departments-edit-modal" class="edit-modal hidden">
  <div class="edit-overlay"></div>
  <div class="edit-container">
    <h2>Fachbereiche bearbeiten</h2>
    <div class="departments-edit-content"><!-- wird per JS befüllt --></div>
  </div>
</div>

 <!-- Departments‐Modal -->
<div id="departments-edit-modal" class="edit-modal hidden">
  <div class="edit-overlay"></div>
  <div class="edit-container">
    <h2>Fachbereiche bearbeiten</h2>
    <div class="departments-edit-content"><!-- wird per JS befüllt --></div>
    <button id="departments-edit-cancel" class="button">Abbrechen</button>
  </div>
</div>





	
</div> <!-- Ende .wrap -->