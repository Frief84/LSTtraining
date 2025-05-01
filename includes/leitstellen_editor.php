<?php
/**
 * Editor für spielbare Leitstellen – GeoJSON wird dynamisch per JS geladen
 */

if (!current_user_can('manage_options')) {
    wp_die('Keine Berechtigung.');
}

require_once plugin_dir_path(__FILE__) . '/db.php';
require_once plugin_dir_path(__FILE__) . '/einsatzgebiet-editor.php';

$pdo = lsttraining_get_connection();
$leitstellen = [];

$suchbegriff = isset($_GET['suchbegriff']) ? $_GET['suchbegriff'] : '';

// Löschen
if (isset($_GET['delete_id']) && $pdo) {
    $stmt = $pdo->prepare("DELETE FROM leitstellen WHERE id = ?");
    $stmt->execute([intval($_GET['delete_id'])]);
}

// Bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lst_update_id']) && $pdo) {
    $stmt = $pdo->prepare("UPDATE leitstellen SET name = ?, ort = ?, bundesland = ?, land = ?, latitude = ?, longitude = ? WHERE id = ?");
    $stmt->execute([
        sanitize_text_field($_POST['lst_update_name']),
        sanitize_text_field($_POST['lst_update_ort']),
        sanitize_text_field($_POST['lst_update_bl']),
        sanitize_text_field($_POST['lst_update_land']),
        floatval($_POST['lst_update_lat']),
        floatval($_POST['lst_update_lon']),
        intval($_POST['lst_update_id'])
    ]);

    // GeoJSON speichern
    $geojson = isset($_POST['geojson_edit']) ? stripslashes($_POST['geojson_edit']) : '';
    $leitstelle_id = intval($_POST['lst_update_id']);
    $check = $pdo->prepare("SELECT id FROM einsatzgebiete WHERE leitstelle_id = ?");
    $check->execute([$leitstelle_id]);
    if ($check->fetchColumn()) {
        $stmt = $pdo->prepare("UPDATE einsatzgebiete SET polygon = ? WHERE leitstelle_id = ?");
        $stmt->execute([$geojson, $leitstelle_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO einsatzgebiete (leitstelle_id, bezeichnung, polygon) VALUES (?, 'Standard', ?)");
        $stmt->execute([$leitstelle_id, $geojson]);
    }
}

// Liste laden
if ($pdo) {
    if ($suchbegriff !== '') {
        $stmt = $pdo->prepare("SELECT * FROM leitstellen WHERE name LIKE ? OR id = ? ORDER BY name ASC");
        $stmt->execute(['%' . $suchbegriff . '%', $suchbegriff]);
    } else {
        $stmt = $pdo->query("SELECT * FROM leitstellen ORDER BY name ASC");
    }
    $leitstellen = $stmt->fetchAll(PDO::FETCH_OBJ);
}
?>

<div class="wrap">
    <h1>Leitstellen verwalten</h1>
    <form method="get" style="margin-bottom: 20px;">
        <input type="hidden" name="page" value="lsttraining_leitstellen">
        <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID..." value="<?php echo esc_attr($suchbegriff); ?>" style="width:300px;">
        <button class="button">Suchen</button>
    </form>

    <table class="widefat">
        <thead><tr><th>ID</th><th>Name</th><th>Ort</th><th>Bundesland</th><th>Land</th><th>Koordinaten</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($leitstellen as $l) : ?>
            <tr>
                <td><?php echo esc_html($l->id); ?></td>
                <td><?php echo esc_html($l->name); ?></td>
                <td><?php echo esc_html($l->ort); ?></td>
                <td><?php echo esc_html($l->bundesland); ?></td>
                <td><?php echo esc_html($l->land); ?></td>
                <td><?php echo esc_html($l->latitude); ?>, <?php echo esc_html($l->longitude); ?></td>
                <td>
                    <a href="#" class="button edit-leitstelle"
                       data-id="<?php echo esc_attr($l->id); ?>"
                       data-name="<?php echo esc_attr($l->name); ?>"
                       data-ort="<?php echo esc_attr($l->ort); ?>"
                       data-bl="<?php echo esc_attr($l->bundesland); ?>"
                       data-land="<?php echo esc_attr($l->land); ?>"
                       data-lat="<?php echo esc_attr($l->latitude); ?>"
                       data-lon="<?php echo esc_attr($l->longitude); ?>"
                    >Bearbeiten</a>
                    <a href="<?php echo admin_url('admin.php?page=lsttraining_leitstellen&delete_id=' . $l->id); ?>" class="button button-link-delete" onclick="return confirm('Wirklich löschen?')">Löschen</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal und Editor-Formular -->
<div id="popup-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9998;"></div>

<div id="edit-leitstelle-formular" class="popup" style="display:none; position:fixed; top:5%; left:50%; transform:translateX(-50%); background:#fff; padding:20px; max-width:800px; width:90%; border:1px solid #ccc; z-index:9999; box-shadow:0 0 15px rgba(0,0,0,0.3);">
  <h2>Leitstelle bearbeiten</h2>
  <form method="post" style="display: flex; flex-wrap: wrap; gap: 20px;">
    <div class="form-table" style="flex: 1 1 48%;">
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
    <div class="map-wrapper" style="flex: 1 1 48%;">
      <div id="map_edit" style="height: 300px;"></div>
    </div>
    <div style="width: 100%;">
<?php
$leitstelle_id = isset($leitstelle->id) ? $leitstelle->id : 0;
$geojson = isset($leitstelle->geojson) ? $leitstelle->geojson : '';
$center = isset($leitstelle->latitude, $leitstelle->longitude) ? $leitstelle->latitude . ',' . $leitstelle->longitude : '';
?>

<button 
    type="button" 
    class="button open-einsatzgebiet-editor"
    data-map-id="einsatzgebiet_<?= $leitstelle_id ?>"
    data-geojson=''
    data-leitstelle-id="<?= $leitstelle_id ?>"
    data-center="<?= esc_attr($center) ?>"
    data-context="leitstelle"
>
    Einsatzgebiet bearbeiten
</button>
      <p><button class="button button-primary">Speichern</button>
         <button type="button" class="button" onclick="document.getElementById('popup-overlay').style.display='none'; document.getElementById('edit-leitstelle-formular').style.display='none';">Abbrechen</button></p>
    </div>
  </form>
</div>
