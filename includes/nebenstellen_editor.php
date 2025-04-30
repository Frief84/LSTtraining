<?php
/**
 * Editor für Nebenleitstellen mit Popup, GeoJSON und Standortkarte
 * Datei: includes/nebenstellen_editor.php
 */

if (!current_user_can('manage_options')) {
    wp_die('Keine Berechtigung.');
}

require_once plugin_dir_path(__FILE__) . '/db.php';
require_once plugin_dir_path(__FILE__) . '/einsatzgebiet-editor.php';
$pdo = lsttraining_get_connection();
$nebenstellen = [];
$suchbegriff = $_GET['suchbegriff'] ?? '';

// Löschen
if (isset($_GET['delete_id']) && $pdo) {
    $stmt = $pdo->prepare("DELETE FROM nebenleistellen WHERE id = ?");
    $stmt->execute([intval($_GET['delete_id'])]);
}

// Bearbeiten inkl. GeoJSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neben_update_id']) && $pdo) {
    $stmt = $pdo->prepare("UPDATE nebenleistellen SET name = ?, zustandigkeit = ?, einwohner = ?, flaeche_km2 = ?, gps = ?, nachbarleitstelle = ?, geojson = ? WHERE id = ?");
    $stmt->execute([
        sanitize_text_field($_POST['neben_update_name']),
        sanitize_text_field($_POST['neben_update_zustandigkeit']),
        intval($_POST['neben_update_einwohner']),
        floatval($_POST['neben_update_flaeche']),
        sanitize_text_field($_POST['neben_update_gps']),
        intval($_POST['neben_update_nachbar']),
        stripslashes($_POST['geojson_edit'] ?? ''),
        intval($_POST['neben_update_id'])
    ]);
}

// Neue Nebenleitstelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neben_neu_name']) && $pdo) {
    $stmt = $pdo->prepare("INSERT INTO nebenleistellen (name, zustandigkeit, einwohner, flaeche_km2, gps, nachbarleitstelle, geojson) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        sanitize_text_field($_POST['neben_neu_name']),
        sanitize_text_field($_POST['neben_neu_zustandigkeit']),
        intval($_POST['neben_neu_einwohner']),
        floatval($_POST['neben_neu_flaeche']),
        sanitize_text_field($_POST['neben_neu_gps']),
        intval($_POST['neben_neu_nachbar']),
        stripslashes($_POST['geojson_edit'] ?? '')
    ]);
}

// Liste laden
if ($pdo) {
    if ($suchbegriff !== '') {
        $stmt = $pdo->prepare("SELECT * FROM nebenleistellen WHERE name LIKE ? OR id = ? ORDER BY name ASC");
        $stmt->execute(['%' . $suchbegriff . '%', $suchbegriff]);
    } else {
        $stmt = $pdo->query("SELECT * FROM nebenleistellen ORDER BY name ASC");
    }
    $nebenstellen = $stmt->fetchAll(PDO::FETCH_OBJ);
}

echo '<div class="wrap">';
echo '<h1>Nebenleitstellen verwalten</h1>';

?>
<form method="get" style="margin-bottom: 20px;">
    <input type="hidden" name="page" value="lsttraining_nebenleitstellen">
    <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID..." value="<?php echo esc_attr($suchbegriff); ?>" style="width:300px;">
    <button class="button">Suchen</button>
</form>

<table class="widefat">
    <thead><tr><th>ID</th><th>Name</th><th>Zuständigkeit</th><th>Einwohner</th><th>Fläche</th><th>Standort</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($nebenstellen as $n): ?>
        <?php
        $geojson = $n->geojson;
        $onclick = sprintf(
            "editNebenstelle(%d, %s, %s, %d, %f, %s, %d, %s); return false;",
            $n->id,
            json_encode($n->name),
            json_encode($n->zustandigkeit),
            $n->einwohner,
            $n->flaeche_km2,
            json_encode($n->gps),
            $n->nachbarleitstelle,
            json_encode($geojson)
        );
        ?>
        <tr>
            <td><?php echo esc_html($n->id); ?></td>
            <td><?php echo esc_html($n->name); ?></td>
            <td><?php echo esc_html($n->zustandigkeit); ?></td>
            <td><?php echo esc_html($n->einwohner); ?></td>
            <td><?php echo esc_html($n->flaeche_km2); ?></td>
            <td><?php echo esc_html($n->gps); ?></td>
            <td>
                <a href="#" class="button" onclick="<?php echo htmlspecialchars($onclick); ?>">Bearbeiten</a>
                <a href="<?php echo admin_url('admin.php?page=lsttraining_nebenleitstellen&delete_id=' . $n->id); ?>" class="button button-link-delete" onclick="return confirm('Wirklich löschen?')">Löschen</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div id="popup-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9998;"></div>
<div id="edit-nebenstelle-formular" style="display:none; position:fixed; top:5%; left:50%; transform:translateX(-50%); background:#fff; border:1px solid #ccc; padding:20px; z-index:9999; max-width:700px; width:95%; box-shadow:0 0 10px rgba(0,0,0,0.3)">
  <h2>Nebenleitstelle bearbeiten</h2>
  <form method="post">
    <input type="hidden" name="neben_update_id" id="neben_update_id">
    <table class="form-table">
      <tr><td>Name</td><td><input type="text" name="neben_update_name" id="neben_update_name" required></td></tr>
      <tr><td>Zuständigkeit</td><td><input type="text" name="neben_update_zustandigkeit" id="neben_update_zustandigkeit"></td></tr>
      <tr><td>Einwohner</td><td><input type="number" name="neben_update_einwohner" id="neben_update_einwohner"></td></tr>
      <tr><td>Fläche (km²)</td><td><input type="number" step="0.01" name="neben_update_flaeche" id="neben_update_flaeche"></td></tr>
      <tr><td>Standort</td><td><input type="text" name="neben_update_gps" id="neben_update_gps" placeholder="z.B. 48.12345, 9.12345"></td></tr>
      <tr><td colspan="2"><div id="nebenstelle_map" style="height:250px;"></div></td></tr>
      <tr><td>Nachbarleitstelle</td><td><input type="number" name="neben_update_nachbar" id="neben_update_nachbar"></td></tr>
    </table>
    <div class="form-map">
      <?php lsttraining_einsatzgebiet_editor('einsatzgebiet_edit', 'geojson_edit', '', 0); ?>
    </div>
    <p>
      <button class="button button-primary">Aktualisieren</button>
      <button type="button" class="button" onclick="closeNebenstellePopup()">Abbrechen</button>
    </p>
  </form>
</div>

<script>
let nebenstelleMap, marker;

function initNebenstelleMap(latlng) {
  const [lat, lon] = latlng.split(',').map(parseFloat);
  const view = new ol.View({ center: ol.proj.fromLonLat([lon, lat]), zoom: 11 });
  const vectorSource = new ol.source.Vector();

  marker = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])) });
  vectorSource.addFeature(marker);

  const markerLayer = new ol.layer.Vector({ source: vectorSource });

  nebenstelleMap = new ol.Map({
    target: 'nebenstelle_map',
    layers: [
      new ol.layer.Tile({ source: new ol.source.OSM() }),
      markerLayer
    ],
    view: view
  });

  nebenstelleMap.on('click', function (e) {
    const coords = ol.proj.toLonLat(e.coordinate);
    const [lon, lat] = coords;
    marker.setGeometry(new ol.geom.Point(e.coordinate));
    document.getElementById('neben_update_gps').value = lat.toFixed(6) + ',' + lon.toFixed(6);
  });
}

function editNebenstelle(id, name, zustandigkeit, einwohner, flaeche, gps, nachbar, geojson) {
  document.getElementById('neben_update_id').value = id;
  document.getElementById('neben_update_name').value = name;
  document.getElementById('neben_update_zustandigkeit').value = zustandigkeit;
  document.getElementById('neben_update_einwohner').value = einwohner;
  document.getElementById('neben_update_flaeche').value = flaeche;
  document.getElementById('neben_update_gps').value = gps;
  document.getElementById('neben_update_nachbar').value = nachbar;
  document.getElementById('geojson_edit').value = geojson;
  document.getElementById('popup-overlay').style.display = 'block';
  document.getElementById('edit-nebenstelle-formular').style.display = 'block';
  window.dispatchEvent(new Event('resize'));
  setTimeout(() => initNebenstelleMap(gps || '51.0,10.0'), 300);
}

function closeNebenstellePopup() {
  document.getElementById('popup-overlay').style.display = 'none';
  document.getElementById('edit-nebenstelle-formular').style.display = 'none';
}
</script>
