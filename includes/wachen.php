<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Keine Berechtigung.' );
}

require_once plugin_dir_path( __FILE__ ) . '/db.php';

$pdo = lsttraining_get_connection();

// Ausgewählte IDs aus dem Request
$filter_leitstelle      = isset( $_GET['ls_id'] )  ? intval( $_GET['ls_id'] )  : 0;
$filter_nebenleitstelle = isset( $_GET['nls_id'] ) ? intval( $_GET['nls_id'] ) : 0;

// 1) Alle Leitstellen und Nebenleitstellen laden
$all_ls  = $pdo->query( 'SELECT id, name FROM leitstellen ORDER BY name' )->fetchAll( PDO::FETCH_ASSOC );
$all_nls = $all_ls; // da Nebenleitstellen in derselben Tabelle

// 2) Filter-Formular ausgeben
?>
<div class="wrap">
  <h1>Wachen verwalten</h1>

  <form method="get" style="margin-bottom:20px;">
    <input type="hidden" name="page" value="lsttraining_leitstellen_wachen">

    <label>Leitstelle:
      <select name="ls_id" onchange="this.form.submit()">
        <option value="0">– keine –</option>
        <?php foreach ( $all_ls as $ls ) : ?>
          <option value="<?php echo esc_attr( $ls['id'] ) ?>"
            <?php selected( $filter_leitstelle, $ls['id'] ) ?>>
            <?php echo esc_html( $ls['name'] ) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    &nbsp;&nbsp;

    <label>Nebenleitstelle:
      <select name="nls_id" onchange="this.form.submit()">
        <option value="0">– keine –</option>
        <?php foreach ( $all_nls as $nls ) : ?>
          <option value="<?php echo esc_attr( $nls['id'] ) ?>"
            <?php selected( $filter_nebenleitstelle, $nls['id'] ) ?>>
            <?php echo esc_html( $nls['name'] ) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

<?php
// 3) Abfrage der Wachen mit entsprechendem Filter
$sql = 'SELECT id, name, typ, latitude, longitude, bild_datei, leitstelle_id, nebenleitstelle_id
          FROM wachen
         WHERE 1=1';

$params = [];
if ( $filter_leitstelle ) {
    $sql .= ' AND leitstelle_id = ?';
    $params[] = $filter_leitstelle;
}
if ( $filter_nebenleitstelle ) {
    $sql .= ' AND nebenleitstelle_id = ?';
    $params[] = $filter_nebenleitstelle;
}

$stmt = $pdo->prepare( $sql );
$stmt->execute( $params );
$wachen = $stmt->fetchAll( PDO::FETCH_OBJ );
?>
<div id="wachen-map" style="height: 400px; margin-bottom: 20px;"></div>
  <table class="widefat">
    <thead>
      <tr>
        <th>ID</th><th>Name</th><th>Typ</th><th>Koordinaten</th><th>Aktionen</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ( $wachen as $w ) : ?>
        <tr>
          <td><?php echo esc_html( $w->id ); ?></td>
          <td><?php echo esc_html( $w->name ); ?></td>
          <td><?php echo esc_html( $w->typ ); ?></td>
          <td><?php echo esc_html( $w->latitude . ', ' . $w->longitude ); ?></td>
          <td>
            <a href="#" class="button edit-wache" data-id="<?php echo esc_attr( $w->id ); ?>">Bearbeiten</a>
            <a href="<?php echo admin_url( 'admin.php?page=lsttraining_leitstellen_wachen&delete_id=' . $w->id );?>"
               class="button button-link-delete"
               onclick="return confirm('Wirklich löschen?');">
               Löschen
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div id="wache-edit-modal" class="hidden">
  <div class="wache-edit-overlay"></div>
  <div class="wache-edit-content"></div>
</div>

<div id="wache-edit-modal" class="hidden">
  <div class="wache-edit-overlay"></div>
  <div class="wache-edit-content">
    <!-- JS wird dieses Template klonen und ausfüllen -->
    <script type="text/html" id="tmpl-wache-edit-form">
      <form id="wache-edit-form">
        <input type="hidden" name="id" value="{{id}}">
        <table class="form-table">
          <tr>
            <th><label for="w-name">Name</label></th>
            <td><input type="text" id="w-name" name="name" value="{{name}}" class="regular-text"></td>
          </tr>
          <tr>
            <th><label for="w-typ">Typ</label></th>
            <td><input type="text" id="w-typ" name="typ" value="{{typ}}" class="regular-text"></td>
          </tr>
          <tr>
            <th><label for="w-lat">Latitude</label></th>
            <td><input type="text" id="w-lat" name="latitude" value="{{latitude}}" class="regular-text"></td>
          </tr>
          <tr>
            <th><label for="w-lon">Longitude</label></th>
            <td><input type="text" id="w-lon" name="longitude" value="{{longitude}}" class="regular-text"></td>
          </tr>
        </table>
        <p class="submit">
          <button type="submit" class="button button-primary">Speichern</button>
          <button type="button" id="wache-edit-cancel" class="button">Abbrechen</button>
        </p>
      </form>
    </script>
  </div>
</div>

