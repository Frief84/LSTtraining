<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Keine Berechtigung.' );
}



require_once plugin_dir_path( __FILE__ ) . '/db.php';
$pdo = lsttraining_get_connection();

// Filter aus Request
$filter_leitstelle      = isset( $_GET['ls_id'] )  ? intval( $_GET['ls_id'] )  : 0;
$filter_nebenleitstelle = isset( $_GET['nls_id'] ) ? intval( $_GET['nls_id'] ) : 0;

// 1) Alle Leitstellen laden (gleich für Nebenleitstellen)
$all_ls  = $pdo->query( 'SELECT id, name FROM leitstellen ORDER BY name' )->fetchAll( PDO::FETCH_ASSOC );
$all_nls = $all_ls;
?>
<div class="wrap">
  <h1>Wachen verwalten</h1>

 <form method="get" style="display: flex; gap: 20px; margin-bottom: 20px;">
  <input type="hidden" name="page" value="lsttraining_leitstellen_wachen">

  <!-- Leitstellen-Box -->
  <div class="filter-box" style="flex:1; border:1px solid #ddd; padding:10px; border-radius:4px;">
    <h2 style="margin-top:0;">Leitstelle</h2>
    <p>
      <label for="ls_search">Suche Leitstelle:</label><br>
      <input type="text" id="ls_search" placeholder="Filter..." style="width:100%; box-sizing:border-box;">
    </p>
    <p>
      <label for="ls_id">Leitstelle auswählen:</label><br>
      <select id="ls_id" name="ls_id" style="width:100%; box-sizing:border-box;">
        <option value="0">– keine –</option>
        <?php foreach ( $all_ls as $ls ) : ?>
          <option value="<?php echo esc_attr( $ls['id'] ) ?>"
            <?php selected( $filter_leitstelle, $ls['id'] ) ?>>
            <?php echo esc_html( $ls['name'] ) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </p>
  </div>

  <!-- Nebenleitstellen-Box -->
  <div class="filter-box" style="flex:1; border:1px solid #ddd; padding:10px; border-radius:4px;">
    <h2 style="margin-top:0;">Nebenleitstelle</h2>
    <p>
      <label for="nls_search">Suche Nebenleitstelle:</label><br>
      <input type="text" id="nls_search" placeholder="Filter..." style="width:100%; box-sizing:border-box;">
    </p>
    <p>
      <label for="nls_id">Nebenleitstelle auswählen:</label><br>
      <select id="nls_id" name="nls_id" style="width:100%; box-sizing:border-box;">
        <option value="0">– keine –</option>
        <?php foreach ( $all_nls as $nls ) : ?>
          <option value="<?php echo esc_attr( $nls['id'] ) ?>"
            <?php selected( $filter_nebenleitstelle, $nls['id'] ) ?>>
            <?php echo esc_html( $nls['name'] ) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </p>
  </div>
</form>

  <!-- Karte -->
  <div id="wachen-map" style="height: 400px; margin-bottom: 20px;"></div>

  <!-- Tabelle -->
  <?php
  $sql    = 'SELECT id, name, typ, latitude, longitude FROM wachen WHERE 1=1';
  $params = [];

  if ( $filter_leitstelle ) {
      // nur nach Leitstelle filtern
      $sql      .= ' AND leitstelle_id = ?';
      $params[] = $filter_leitstelle;
  } elseif ( $filter_nebenleitstelle ) {
      // nur nach Nebenleitstelle filtern
      $sql      .= ' AND nebenleitstelle_id = ?';
      $params[] = $filter_nebenleitstelle;
  }

  $stmt = $pdo->prepare( $sql );
  $stmt->execute( $params );
  $wachen = $stmt->fetchAll( PDO::FETCH_ASSOC );
  ?>
  <table class="widefat fixed">
    <thead>
      <tr>
        <th width="50">ID</th>
        <th>Name</th>
        <th>Typ</th>
        <th>Koordinaten</th>
        <th width="120">Aktionen</th>
      </tr>
    </thead>
    <tbody>
      <?php if ( empty( $wachen ) ) : ?>
        <tr><td colspan="5">Keine Wachen gefunden.</td></tr>
      <?php else : ?>
        <?php foreach ( $wachen as $w ) : ?>
          <tr>
            <td><?php echo esc_html( $w['id'] ); ?></td>
            <td><?php echo esc_html( $w['name'] ); ?></td>
            <td><?php echo esc_html( $w['typ'] ); ?></td>
            <td><?php echo esc_html( $w['latitude'] . ', ' . $w['longitude'] ); ?></td>
            <td>
              <button class="button edit-wache" data-id="<?php echo esc_attr( $w['id'] ); ?>">
                Bearbeiten
              </button>
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=lsttraining_leitstellen_wachen&delete_id=' . $w['id'] ) );?>"
                 class="button button-link-delete"
                 onclick="return confirm('Wache wirklich löschen?');">
                 Löschen
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- -------------- -->
<!-- Edit-Modal HTML -->
<!-- -------------- -->
<div id="wache-edit-modal" class="wache-edit-modal hidden">
  <div class="wache-edit-overlay"></div>
  <div class="wache-edit-container">
    <h2>Wache bearbeiten</h2>
    <div class="wache-edit-content">
      <!-- form will be injected via JS -->
    </div>
  </div>
</div>

<!-- Template for the form (loaded by JS via wp.template / _.template) -->
<script type="text/html" id="tmpl-wache-edit-form">
  <form id="wache-edit-form">
    <input type="hidden" name="id" value="{{id}}">

    <table class="form-table">
      <tr>
        <th>ID</th>
        <td><strong>{{id}}</strong></td>
      </tr>

      <tr>
        <th><label for="w-name">Name</label></th>
        <td>
          <input type="text" id="w-name" name="name" value="{{name}}" class="regular-text" required>
        </td>
      </tr>

      <tr>
        <th><label for="w-typ">Typ</label></th>
        <td>
          <select id="w-typ" name="typ">
            <option value="">– wählen –</option>
            <option value="FW"   {{typ==="FW"  ?"selected":""}}>Feuerwache</option>
            <option value="FFW"  {{typ==="FFW" ?"selected":""}}>Freiwillige Feuerwehr</option>
            <option value="SEG"  {{typ==="SEG" ?"selected":""}}>Sondereinsatzgruppe</option>
            <option value="RD"   {{typ==="RD"  ?"selected":""}}>Rettungswache</option>
            <option value="FRRD" {{typ==="FRRD"?"selected":""}}>Rettungsdienst + Feuerwehr</option>
          </select>
        </td>
      </tr>

      <!-- one combined position string + hidden lat/lon; JS keeps them in sync -->
      <tr>
        <th><label for="w-pos">Position&nbsp;(lat, lon)</label></th>
        <td>
          <input type="text" id="w-pos" name="position"
                 value="{{latitude}}, {{longitude}}"
                 class="regular-text"
                 pattern="^\s*-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*$"
                 title="Format: Breitengrad, Längengrad">
          <input type="hidden" id="w-lat" name="latitude"  value="{{latitude}}">
          <input type="hidden" id="w-lon" name="longitude" value="{{longitude}}">
        </td>
      </tr>
	  
	  <tr>
  <th><label for="w-arr">Anfahrts-Position</label></th>
  <td><input type="text" id="w-arr" name="arrival_pos"
             value="{{arrival_pos}}" placeholder="52.704615, 12.520004"></td>
</tr>
<tr>
  <th><label for="w-dep">Abfahrts-Position</label></th>
  <td><input type="text" id="w-dep"  name="departure_pos"
             value="{{departure_pos}}" placeholder="52.704615, 12.520004"></td>
</tr>

	  
      <!-- interactive OpenLayers map -->
      <tr>
        <th>Koordinaten-Karte</th>
        <td>
          <div id="map_wache_edit" style="height:280px; border:1px solid #ccc;"></div>
          <p class="description">Marker ziehen oder Position eingeben.</p>
		  <p class="description">
      <strong>Arrival-Marker:</strong> Shift&nbsp;+&nbsp;Klick &nbsp;|&nbsp;
      <strong>Departure-Marker:</strong> Strg&nbsp;+&nbsp;Klick &nbsp;|&nbsp;
      <strong>Marker löschen:</strong> Feld leeren
    </p>
        </td>
      </tr>

      <tr>
        <th><label for="w-bild">Bild (optional)</label></th>
        <td>
          <input type="file" id="w-bild" name="bild_datei" accept="image/*">
        </td>
      </tr>
    </table>

    <p class="submit">
      <button type="submit" class="button button-primary">Speichern</button>
      <button type="button" id="wache-edit-cancel" class="button">Abbrechen</button>
      <button type="button" class="button-delete-wache" data-id="{{id}}">Wache löschen</button>
    </p>
  </form>
</script>

