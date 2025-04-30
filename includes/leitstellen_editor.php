<?php
/**
 * Editor für spielbare Leitstellen
 * Datei: includes/leitstellen_editor.php
 */

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

// Suchformular
?>
<form method="get" style="margin-bottom: 20px;">
    <input type="hidden" name="page" value="lsttraining_leitstellen">
    <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID..." value="<?php echo esc_attr($suchbegriff); ?>" style="width:300px;">
    <button class="button">Suchen</button>
</form>

<table class="widefat">
    <thead><tr><th>ID</th><th>Name</th><th>Ort</th><th>Bundesland</th><th>Land</th><th>Koordinaten</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($leitstellen as $l): ?>
        <tr>
            <td><?php echo esc_html($l->id); ?></td>
            <td><?php echo esc_html($l->name); ?></td>
            <td><?php echo esc_html($l->ort); ?></td>
            <td><?php echo esc_html($l->bundesland); ?></td>
            <td><?php echo esc_html($l->land); ?></td>
            <td><?php echo esc_html($l->latitude); ?>, <?php echo esc_html($l->longitude); ?></td>
            <td>
                <a href="#" class="button">Bearbeiten</a>
                <a href="<?php echo admin_url('admin.php?page=lsttraining_leitstellen&delete_id=' . $l->id); ?>" class="button button-link-delete" onclick="return confirm('Wirklich löschen?')">Löschen</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
