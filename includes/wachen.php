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
$selectedLand        = isset($_GET['land']) ? sanitize_text_field($_GET['land']) : 'Deutschland';

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
// Pfad relativ zu /includes/ → /data/bundeslaender.json
$plugin_root = dirname(__DIR__); // .../your-plugin
$json_path   = $plugin_root . '/data/bundeslaender.json';

$bundeslaender_by_land = [];
if (file_exists($json_path)) {
    $json = file_get_contents($json_path);
    $bundeslaender_by_land = json_decode($json, true) ?: [];
}

// Defaults
$selectedLand       = isset($_GET['land']) ? sanitize_text_field($_GET['land']) : 'Deutschland';
$selectedBundesland = isset($_GET['bundesland']) ? sanitize_text_field($_GET['bundesland']) : '';

// Wenn unbekanntes Land kommt → auf Deutschland zurückfallen
if (!array_key_exists($selectedLand, $bundeslaender_by_land)) {
    $selectedLand = 'Deutschland';
}

// Länder-Liste aus JSON-Schlüsseln
$laender = array_keys($bundeslaender_by_land);

// Bundesländer-Liste passend zum Land
$bundeslaender = $bundeslaender_by_land[$selectedLand] ?? [];

// JSON für data-map sicher encodieren (Umlaute erhalten, Quotes escapen)
$data_map_json = wp_json_encode($bundeslaender_by_land, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="filter-box" style="flex:1; border:1px solid #ddd; padding:10px; border-radius:4px;">
  <h2 style="margin-top:0;">Land</h2>
  <p>
    <label for="land">Land auswählen:</label><br>
    <select id="land" name="land" style="width:100%; box-sizing:border-box;">
      <?php foreach ($laender as $land): ?>
        <option value="<?php echo esc_attr($land); ?>" <?php selected($selectedLand, $land); ?>>
          <?php echo esc_html($land); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </p>
</div>

<div class="filter-box" style="flex:1; border:1px solid #ddd; padding:10px; border-radius:4px;">
  <h2 style="margin-top:0;">Bundesland</h2>
  <p>
    <label for="bundesland">Bundesland auswählen:</label><br>
    <select
      id="bundesland"
      name="bundesland"
      style="width:100%; box-sizing:border-box;"
      data-map='<?php echo esc_attr($data_map_json); ?>'
    >
      <option value="">— Bitte wählen —</option>
      <option value="__none__" <?php selected($selectedBundesland, '__none__'); ?>>Ohne Bundesland</option>
      <?php foreach ($bundeslaender as $bl): ?>
        <option value="<?php echo esc_attr($bl); ?>" <?php selected($selectedBundesland, $bl); ?>>
          <?php echo esc_html($bl); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <small class="description">„Ohne Bundesland“ findet Einträge mit NULL oder leerem Feld.</small>
  </p>
</div>
</form>
	
<button id="btn-new-wache" class="button button-primary">
  + Neue Wache
</button>
  <!-- Karte -->
  <div id="wachen-map" style="height: 400px; margin-bottom: 20px;"></div>

  <!-- Tabelle -->
<?php
// 0) Exklusivität noch einmal serverseitig absichern (BL > LS > NLS)
if ($selectedBundesland !== '') {
    $filter_leitstelle      = 0;
    $filter_nebenleitstelle = 0;
} elseif ($filter_leitstelle) {
    $filter_nebenleitstelle = 0;
    $selectedBundesland     = '';
} elseif ($filter_nebenleitstelle) {
    $filter_leitstelle  = 0;
    $selectedBundesland = '';
}

// 1) Flag + Default
$anyFilterSet = ($selectedBundesland !== '' || $filter_leitstelle || $filter_nebenleitstelle);
$wachen = [];

// 2) Wenn kein Filter: Hinweis ausgeben, aber NICHT returnen
if (!$anyFilterSet) {
    echo '<p><em>Bitte zunächst einen Filter (Leitstelle, Nebenleitstelle oder Bundesland) wählen.</em></p>';
} else {
    // 3) SQL aufbauen wie gehabt
$sql = '
  SELECT
    w.id,
    w.name,
    w.typ,
    w.latitude,
    w.longitude,
    COALESCE(v.cnt, 0) AS fahrzeuge_count
  FROM wachen AS w
';
$joins = '
  LEFT JOIN (
    SELECT wache_id, COUNT(*) AS cnt
    FROM fahrzeuge
    GROUP BY wache_id
  ) v ON v.wache_id = w.id
';
$where = [];
    $params = [];

    // 3a) Bundesland
    if ($selectedBundesland !== '') {
        if ($selectedBundesland === '__none__') {
            $where[] = '(w.bundesland IS NULL OR w.bundesland = \'\')';
        } else {
            $where[]  = 'w.bundesland = ?';
            $params[] = $selectedBundesland;
        }
    } else {
        // 3b) LS/NLS über Pivot-Tabellen
        if ($filter_leitstelle) {
            $joins   .= ' INNER JOIN wache_leitstellen AS wl ON w.id = wl.wache_id ';
            $where[]  = 'wl.leitstelle_id = ?';
            $params[] = (int)$filter_leitstelle;
        }
        if ($filter_nebenleitstelle) {
            $joins   .= ' INNER JOIN wache_nebenleitstellen AS wn ON w.id = wn.wache_id ';
            $where[]  = 'wn.nebenleitstelle_id = ?';
            $params[] = (int)$filter_nebenleitstelle;
        }
    }

    // 3c) SQL zusammensetzen
    $sql .= $joins;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY w.name';

    // 4) Abfrage
    try {
        $stmt   = $pdo->prepare($sql);
        $stmt->execute($params);
        $wachen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo '<div class="notice notice-error"><p>' .
             esc_html__('Fehler beim Laden der Wachen:', 'lsttraining') . ' ' .
             esc_html($e->getMessage()) . '</p></div>';
        $wachen = [];
    }
}
?>
<div class="filter-box" style="margin:10px 0; display:flex; gap:10px; align-items:center;">
  <label for="wachen-search" style="font-weight:600;">Suche (ID oder Name):</label>
  <input type="text"
         id="wachen-search"
         placeholder="z. B. 123 oder Babelsberg"
         style="width:300px; max-width:100%;"
         autocomplete="off">
</div>
<!-- 5) Tabelle steht IMMER im DOM, Body wird je nach $wachen gefüllt -->
<table class="widefat fixed" id="wachen-table">
	 <colgroup>
    <col style="width:60px">      <!-- ID -->
    <col>                         <!-- Name (flex) -->
    <col style="width:120px">     <!-- Typ -->
    <col style="width:220px">     <!-- Koordinaten -->
    <col style="width:100px">     <!-- Fahrzeuge -->
    <col style="width:140px">     <!-- Aktionen -->
  </colgroup>
  <thead>
    <tr>
<th width="50"  data-sort="id"   style="cursor:pointer;">ID</th>
<th            data-sort="name" style="cursor:pointer;">Name</th>
<th            data-sort="typ"  style="cursor:pointer;">Typ</th>
<th width="100">Koordinaten</th>
<th width="90" data-sort="fahrzeuge" style="cursor:pointer;">Fahrzeuge</th>

<th width="120">Aktionen</th>
    </tr>
  </thead>
  <tbody id="wachen-tbody">
    <?php if (empty($wachen)) : ?>
      <tr><td colspan="6"><em>Keine Wachen gefunden.</em></td></tr>
    <?php else : ?>
      <?php foreach ($wachen as $w) : ?>
        <tr>
          <td><?php echo esc_html($w['id']); ?></td>
          <td><?php echo esc_html($w['name']); ?></td>
          <td><?php echo esc_html($w['typ']); ?></td>
          <td><?php echo esc_html($w['latitude'] . ', ' . $w['longitude']); ?></td>
		<td class="col-fahrzeuge" style="text-align:center;">
  <?php
    $cnt = isset($w['fahrzeuge_count']) ? (int)$w['fahrzeuge_count'] : 0;

    // Wenn du die Zahl klickbar zur Fahrzeugliste machen willst:
    $url = add_query_arg(
      ['page' => 'lsttraining_fahrzeuge', 'wache_id' => $w['id']],
      admin_url('admin.php')
    );
  ?>
  <a href="<?php echo esc_url($url); ?>" title="Fahrzeuge dieser Wache anzeigen">
    <?php echo number_format_i18n($cnt); ?>
  </a>
</td>
          <td>
            <button class="button edit-wache" data-id="<?php echo esc_attr($w['id']); ?>">
              Bearbeiten
            </button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lsttraining_leitstellen_wachen&delete_id=' . $w['id'])); ?>"
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

<!-- 6) Modal + Template: immer ausgeben, außerhalb von Tabellen -->
<div id="wache-edit-modal" class="hidden">
  <div class="wache-edit-overlay"></div>
  <div class="wache-edit-content"></div>
</div>

<script type="text/html" id="tmpl-wache-edit-form">
  <form id="wache-edit-form">
    <input type="hidden" id="w-form-mode" name="mode" value="update">
    <input type="hidden" name="id" value="{{id}}">

    <table class="form-table">
      <tr>
        <th>ID</th><td><strong>{{id}}</strong></td>
      </tr>
      <tr>
        <th><label for="w-name">Name</label></th>
        <td><input type="text" id="w-name" name="name" value="{{name}}" class="regular-text" required></td>
      </tr>
      <tr>
        <th><label for="w-typ">Typ</label></th>
        <td>
          <select id="w-typ" name="typ">
            <option value="">– wählen –</option>
            <option value="FW">Feuerwache</option>
            <option value="FFW">Freiwillige Feuerwehr</option>
            <option value="SEG">Sondereinsatzgruppe</option>
            <option value="RD">Rettungswache</option>
            <option value="FRRD">Rettungsdienst + Feuerwehr</option>
          </select>
        </td>
      </tr>
	  <!-- im <script type="text/html" id="tmpl-wache-edit-form"> ... innerhalb des <form> -->
<tr>
  <th colspan="2">
    <div class="row-2col">
      <div class="col">
        <label for="mw-land">Land</label>
        <select id="mw-land" name="land"
                data-map='<?= esc_attr( wp_json_encode($bundeslaender_by_land, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ); ?>'>
          <?php foreach (array_keys($bundeslaender_by_land) as $land): ?>
            <option value="<?= esc_attr($land) ?>"><?= esc_html($land) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label for="mw-bundesland">Bundesland</label>
        <select id="mw-bundesland" name="bundesland">
          <option value="">— Bitte wählen —</option>
          <option value="">Ohne Bundesland</option>
        </select>
      </div>
    </div>
  </th>
</tr>

      <tr>
        <th><label for="w-pos">Position (lat, lon)</label></th>
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
        <td><input type="text" id="w-arr" name="arrival_pos" value="{{arrival_pos}}" placeholder="52.704615, 12.520004"></td>
      </tr>
      <tr>
        <th><label for="w-dep">Abfahrts-Position</label></th>
        <td><input type="text" id="w-dep" name="departure_pos" value="{{departure_pos}}" placeholder="52.704615, 12.520004"></td>
      </tr>
      <tr>
        <th>Koordinaten-Karte</th>
        <td>
          <div id="map_wache_edit" style="height:280px; border:1px solid #ccc;"></div>
          <p class="description">Marker ziehen oder Position eingeben.</p>
          <p class="description">
            <strong>Arrival-Marker:</strong> Shift + Klick |
            <strong>Departure-Marker:</strong> Strg + Klick |
            <strong>Marker löschen:</strong> Feld leeren
          </p>
        </td>
      </tr>
      <tr>
        <th><label for="w-bild">Bild (optional)</label></th>
        <td><input type="file" id="w-bild" name="bild_datei" accept="image/*"></td>
      </tr>
    </table>

    <p class="submit">
      <button type="submit" class="button button-primary">Speichern</button>
      <button type="button" id="wache-edit-cancel" class="button">Abbrechen</button>
      <button type="button" class="button-delete-wache" data-id="{{id}}">Wache löschen</button>
    </p>
  </form>
</script>

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

