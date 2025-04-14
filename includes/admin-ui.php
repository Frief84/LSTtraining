<?php
/**
 * Admin UI for LSTtraining Plugin
 *
 * @package LSTtraining
 */

function lsttraining_render_leitstellen() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    require_once plugin_dir_path(__FILE__) . '/db.php';
    require_once plugin_dir_path(__FILE__) . '/einsatzgebiet-editor.php';
    $pdo = lsttraining_get_connection();
    $leitstellen = [];
    $suchbegriff = $_GET['suchbegriff'] ?? '';

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
    }

    // Neue Leitstelle
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lst_neu_name']) && $pdo) {
        $stmt = $pdo->prepare("INSERT INTO leitstellen (name, ort, bundesland, land, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            sanitize_text_field($_POST['lst_neu_name']),
            sanitize_text_field($_POST['lst_neu_ort']),
            sanitize_text_field($_POST['lst_neu_bl']),
            sanitize_text_field($_POST['lst_neu_land']),
            floatval($_POST['lst_neu_lat']),
            floatval($_POST['lst_neu_lon'])
        ]);
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

    echo '<div class="wrap">';
    echo '<h1>Leitstellen verwalten</h1>';

    echo '<form method="get" style="margin-bottom: 20px;">';
    echo '<input type="hidden" name="page" value="lsttraining_leitstellen">';
    echo '<input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID..." value="' . esc_attr($suchbegriff) . '" style="width:300px;">';
    echo '<button class="button">Suchen</button>';
    echo '</form>';

    echo '<table class="widefat"><thead><tr><th>ID</th><th>Name</th><th>Ort</th><th>Bundesland</th><th>Land</th><th>Koordinaten</th><th>Aktionen</th></tr></thead><tbody>';
foreach ($leitstellen as $l) {
    $geojson = ''; // optional: später aus einsatzgebiete laden
	if ($pdo) {
    $stmtGeo = $pdo->prepare("SELECT polygon FROM einsatzgebiete WHERE leitstelle_id = ?");
    $stmtGeo->execute([$l->id]);
    $result = $stmtGeo->fetch(PDO::FETCH_ASSOC);
		if ($result && !empty($result['polygon'])) {
			$geojson = $result['polygon'];
		}
	}

    // Bereite den JavaScript-Aufruf als String vor (sauber escaped)
$onclickCode = sprintf(
    "editLeitstelle(%d, %s, %s, %s, %s, %f, %f, %s); return false;",
    $l->id,
    json_encode($l->name),
    json_encode($l->ort),
    json_encode($l->bundesland),
    json_encode($l->land),
    $l->latitude,
    $l->longitude,
    json_encode(json_decode($geojson, true))
);

    // Erzeuge den Button, wobei onclick korrekt HTML-escaped ist
    $button = '<a href="#" class="button" onclick="' . htmlspecialchars($onclickCode) . '">Bearbeiten</a>';

    echo '<tr>';
    echo '<td>' . esc_html($l->id) . '</td>';
    echo '<td>' . esc_html($l->name) . '</td>';
    echo '<td>' . esc_html($l->ort) . '</td>';
    echo '<td>' . esc_html($l->bundesland) . '</td>';
    echo '<td>' . esc_html($l->land) . '</td>';
    echo '<td>' . esc_html($l->latitude) . ', ' . esc_html($l->longitude) . '</td>';
    echo '<td>' . $button . ' ';
    echo '<a href="' . admin_url('admin.php?page=lsttraining_leitstellen&delete_id=' . $l->id) . '" class="button button-link-delete" onclick="return confirm(\'Wirklich löschen?\')">Löschen</a></td>';
    echo '</tr>';
}
    echo '</tbody></table>';
// Formular bearbeiten

echo <<<HTML
<div id="edit-leitstelle-formular" style="display:none">
  <h2>Leitstelle bearbeiten</h2>
  <form method="post">
    <input type="hidden" name="lst_update_id" id="lst_update_id">
    <div class="form-container">
      <table class="form-table">
        <tr><td>Name</td><td><input type="text" name="lst_update_name" id="lst_update_name" required></td></tr>
        <tr><td>Ort</td><td><input type="text" name="lst_update_ort" id="lst_update_ort"></td></tr>
        <tr><td>Bundesland</td><td><input type="text" name="lst_update_bl" id="lst_update_bl"></td></tr>
        <tr><td>Land</td><td><input type="text" name="lst_update_land" id="lst_update_land"></td></tr>
        <tr><td>Breitengrad</td><td><input type="number" step="0.000001" name="lst_update_lat" id="lst_update_lat"></td></tr>
        <tr><td>Längengrad</td><td><input type="number" step="0.000001" name="lst_update_lon" id="lst_update_lon"></td></tr>
      </table>
      <div id="map_edit" class="map-wrapper"></div>
    </div>
HTML;

lsttraining_einsatzgebiet_editor('einsatzgebiet_edit', 'geojson_edit', $geojson, $l->id);

// HTML Ende des Formulars
echo <<<HTML
    <p><button class="button button-primary">Aktualisieren</button></p>
  </form>
</div> <!-- end of edit-leitstelle-formular -->
HTML;

// Script separat (damit es korrekt geparst wird)
echo <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    const field = document.getElementById('geojson_edit');
    try {
        const json = JSON.parse(field?.value ?? '');
        if (!json || !json.features || json.features.length === 0) {
            throw new Error("leer");
        }
    } catch (e) {
        const notice = document.createElement('div');
        notice.textContent = 'Es wurde noch kein Einsatzgebiet für diese Leitstelle festgelegt.';
        notice.style.background = '#fff3cd';
        notice.style.border = '1px solid #ffeeba';
        notice.style.padding = '10px';
        notice.style.marginTop = '10px';
        notice.style.color = '#856404';
        field?.parentNode?.insertBefore(notice, field);
    }
});
</script>
HTML;

// Danach wieder weiter mit dem nächsten HTML
echo <<<HTML
<hr>
<h2><button class="button" onclick="openCreateForm()">Neue Leitstelle erstellen</button></h2>
HTML;
	
}

add_action('wp_ajax_lsttraining_save_einsatzgebiet', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $leitstelle_id = intval($_POST['leitstelle_id'] ?? 0);
    $geojson = stripslashes($_POST['geojson'] ?? '');

    if ($leitstelle_id <= 0 || empty($geojson)) {
        wp_send_json_error('Ungültige Daten');
    }

    require_once plugin_dir_path(__FILE__) . '/db.php';
    $pdo = lsttraining_get_connection();

    if (!$pdo) {
        wp_send_json_error('DB-Verbindung fehlgeschlagen');
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM einsatzgebiete WHERE leitstelle_id = ?");
        $stmt->execute([$leitstelle_id]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE einsatzgebiete SET polygon = ? WHERE leitstelle_id = ?");
            $stmt->execute([$geojson, $leitstelle_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO einsatzgebiete (leitstelle_id, bezeichnung, polygon) VALUES (?, ?, ?)");
            $stmt->execute([$leitstelle_id, 'Standardgebiet', $geojson]);
        }

        wp_send_json_success();
    } catch (Exception $e) {
        wp_send_json_error('Fehler beim Speichern: ' . $e->getMessage());
    }
});



add_action('wp_ajax_lsttraining_get_einsatzgebiet', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $leitstelle_id = intval($_GET['leitstelle_id'] ?? 0);

    if ($leitstelle_id <= 0) {
        wp_send_json_error('Invalid ID');
    }

    require_once plugin_dir_path(__FILE__) . '/db.php';
    $pdo = lsttraining_get_connection();

    $stmt = $pdo->prepare("SELECT polygon FROM einsatzgebiete WHERE leitstelle_id = ?");
    $stmt->execute([$leitstelle_id]);

    $result = $stmt->fetchColumn();

    if ($result) {
        $decoded = json_decode($result, true); // Als Array oder false wenn Fehler
        if (json_last_error() === JSON_ERROR_NONE) {
            wp_send_json_success($decoded); // korrektes JS-Objekt im Frontend
        } else {
            wp_send_json_error('Ungültiges GeoJSON in DB');
        }
    } else {
        wp_send_json_success(null); // Kein Polygon vorhanden
    }
});



/**
 * Enqueue all scripts and styles for LSTtraining admin interface
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_lsttraining' || strpos($hook, 'lsttraining_') !== false) {

        wp_enqueue_style(
            'lsttraining-admin-style',
            plugin_dir_url(__FILE__) . '../css/admin-ui.css',
            array(),
            '1.0'
        );

        wp_enqueue_script(
            'openlayers',
            plugin_dir_url(__FILE__) . '../openlayers/ol.js',
            array(),
            'latest',
            true
        );

        wp_enqueue_style(
            'openlayers-style',
            plugin_dir_url(__FILE__) . '../openlayers/ol.css',
            array(),
            'latest'
        );	
		
		        wp_enqueue_script(
            'lsttraining-admin-ui',
            plugin_dir_url(__FILE__) . '../js/admin-ui.js',
            array('jquery'),
            '1.0',
            true
        );
				
				wp_enqueue_script(
			'lsttraining-einsatzgebiet-editor',
			plugin_dir_url(__FILE__) . '../js/einsatzgebiet-editor.js',
			array('jquery'),
			'1.0',
			true
		);
    }
});
