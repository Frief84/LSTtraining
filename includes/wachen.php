<?php
if ( ! lsttraining_user_can( 'wachen' ) ) {
    wp_die( 'Keine Berechtigung.' );
}


require_once plugin_dir_path( __FILE__ ) . '/db.php';
$pdo = lsttraining_get_connection();

// Filter aus Request
$filter_leitstelle      = isset( $_GET['ls_id'] )  ? intval( $_GET['ls_id'] )  : 0;
$filter_nebenleitstelle = isset( $_GET['nls_id'] ) ? intval( $_GET['nls_id'] ) : 0;
$selectedBundesland = isset($_GET['bundesland']) ? sanitize_text_field($_GET['bundesland']) : '';

if ($selectedBundesland !== '') {
    // Bundesland hat Vorrang
    $filter_leitstelle = 0;
    $filter_nebenleitstelle = 0;
} elseif ($filter_leitstelle) {
    // Leitstelle aktiv -> andere zurücksetzen
    $filter_nebenleitstelle = 0;
    $selectedBundesland = '';
} elseif ($filter_nebenleitstelle) {
    // Nebenleitstelle aktiv -> andere zurücksetzen
    $filter_leitstelle = 0;
    $selectedBundesland = '';
}

// 1) Alle Leitstellen laden
$all_ls  = $pdo->query(
    'SELECT id, name FROM leitstellen ORDER BY name'
)->fetchAll( PDO::FETCH_ASSOC );

// 2) Alle Nebenleitstellen laden
$all_nls = $pdo->query(
    'SELECT id, name FROM nebenleitstellen ORDER BY name'
)->fetchAll( PDO::FETCH_ASSOC );

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
<?php
// Ausgewählter Wert (GET)

$bundeslaender = [
  'Baden-Württemberg','Bayern','Berlin','Brandenburg','Bremen','Hamburg','Hessen',
  'Mecklenburg-Vorpommern','Niedersachsen','Nordrhein-Westfalen','Rheinland-Pfalz',
  'Saarland','Sachsen','Sachsen-Anhalt','Schleswig-Holstein','Thüringen'
];
?>
<div class="filters">
  <!-- Bestehende LS/NLS-Filter bleiben -->
  <label>Bundesland:</label>
  <select id="bundesland" name="bundesland">
    <option value="">— Bitte wählen —</option>
    <option value="__none__" <?php selected($selectedBundesland, '__none__'); ?>>Ohne Bundesland</option>
    <?php foreach ($bundeslaender as $bl): ?>
      <option value="<?php echo esc_attr($bl); ?>" <?php selected($selectedBundesland, $bl); ?>>
        <?php echo esc_html($bl); ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>
</form>
	
<button id="btn-new-wache" class="button button-primary" style="margin-left:10px;">
  + Neue Wache
</button>
  <!-- Karte -->
  <div id="wachen-map" style="height: 400px; margin-bottom: 20px;"></div>

  <!-- Tabelle -->
<?php
// ────────────────────────────────────────────────────────────────
// Tabelle: Wachen abfragen (mit optionalem Bundesland-Filter + Pivot-Filter)

$selectedBundesland    = isset($_GET['bundesland']) ? trim((string)$_GET['bundesland']) : '';
$filter_leitstelle     = isset($filter_leitstelle) ? $filter_leitstelle : 0;         // bleibt wie bei dir
$filter_nebenleitstelle= isset($filter_nebenleitstelle) ? $filter_nebenleitstelle : 0;
	
	$anyFilterSet = ($selectedBundesland !== '' || $filter_leitstelle || $filter_nebenleitstelle);

if (!$anyFilterSet) {
    echo '<p><em>Bitte zunächst einen Filter (Leitstelle, Nebenleitstelle oder Bundesland) wählen.</em></p>';
    // optional: leere Tabelle oder gar nichts rendern
    return;
}

$sql    = '
  SELECT
    w.id,
    w.name,
    w.typ,
    w.latitude,
    w.longitude
  FROM wachen AS w
';
$joins  = '';
$where  = [];
$params = [];

/** 1) Bundesland-Filter (hat Vorrang, wenn gesetzt) */
if ($selectedBundesland !== '') {
    if ($selectedBundesland === '__none__') {
        // Wachen ohne Bundesland
        $where[] = '(w.bundesland IS NULL OR w.bundesland = \'\')';
    } else {
        // Konkretes Bundesland
        $where[]  = 'w.bundesland = ?';
        $params[] = $selectedBundesland;
    }
} else {
    /** 2) Falls KEIN Bundesland-Filter: vorhandene LS/NLS-Filter wie bisher */
    if ($filter_leitstelle) {
        $joins .= '
          INNER JOIN wache_leitstellen AS wl
            ON w.id = wl.wache_id
        ';
        $where[]  = 'wl.leitstelle_id = ?';
        $params[] = (int)$filter_leitstelle;
    }

    if ($filter_nebenleitstelle) {
        $joins .= '
          INNER JOIN wache_nebenleitstellen AS wn
            ON w.id = wn.wache_id
        ';
        $where[]  = 'wn.nebenleitstelle_id = ?';
        $params[] = (int)$filter_nebenleitstelle;
    }
}

// SQL zusammensetzen
$sql .= $joins;
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY w.name';

// Abfrage
try {
    $stmt   = $pdo->prepare($sql);
    $stmt->execute($params);
    $wachen = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('Fehler beim Laden der Wachen:', 'lsttraining') . ' ';
    echo esc_html($e->getMessage());
    echo '</p></div>';
    $wachen = [];
}
// ────────────────────────────────────────────────────────────────
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
    <tbody id="wachen-tbody">
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

