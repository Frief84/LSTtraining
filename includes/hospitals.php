
<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Keine Berechtigung.' );
}

require_once plugin_dir_path( __FILE__ ) . '/db.php';
$pdo = lsttraining_get_connection();

?>
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
        <th width="50">ID</th>
        <th>Name</th>
        <th>Versorgungsstufe</th>
        <th>Trauma-Level</th>
        <th>Koordinaten</th>
        <th width="120">Aktionen</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($krankenhaeuser)) : ?>
        <tr><td colspan="6">Keine Krankenhäuser gefunden.</td></tr>
      <?php else : ?>
        <?php foreach ($krankenhaeuser as $kh) : ?>
          <tr>
            <td><?php echo esc_html($kh['id']); ?></td>
            <td><?php echo esc_html($kh['name']); ?></td>
            <td><?php echo esc_html($kh['versorgungsstufe']); ?></td>
            <td><?php echo esc_html($kh['trauma_level']); ?></td>
            <td><?php echo esc_html($kh['latitude'] . ', ' . $kh['longitude']); ?></td>
            <td>
              <button class="button edit-krankenhaus" data-id="<?php echo esc_attr($kh['id']); ?>">
                Bearbeiten
              </button>
              <a href="<?php echo esc_url(admin_url('admin.php?page=lsttraining_krankenhaeuser&delete_id=' . $kh['id'])); ?>"
                 class="button button-link-delete"
                 onclick="return confirm('Krankenhaus wirklich löschen?');">
                 Löschen
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div id="krankenhaus-edit-modal" class="edit-modal hidden">
  <div class="edit-overlay"></div>
  <div class="edit-container">
    <h2>Krankenhaus bearbeiten</h2>
    <div class="edit-content">
      <!-- Formular wird via JS geladen -->
    </div>
  </div>
</div>
