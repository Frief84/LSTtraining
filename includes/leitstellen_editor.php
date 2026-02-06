<?php
/**
 * Editor for playable dispatch centres ("Leitstellen")
 * GeoJSON wird per JS geladen und in leitstellen.geojson gespeichert
 */

$leitstelle_id = isset($_GET['ls_id']) ? (int)$_GET['ls_id'] : 0;

require_once plugin_dir_path(__FILE__) . '/db.php';
require_once plugin_dir_path(__FILE__) . '/einsatzgebiet-editor.php';

$pdo         = lsttraining_get_connection();
$leitstellen = [];
$suchbegriff = isset($_GET['suchbegriff']) ? (string)$_GET['suchbegriff'] : '';

if (!lsttraining_user_can('leitstellen', $leitstelle_id)) {
    wp_die('Keine Berechtigung.');
}

/* -------------------------------------------------------------------------
 * DELETE
 * ---------------------------------------------------------------------- */
if (isset($_GET['delete_id']) && $pdo) {
    $del_id = (int)$_GET['delete_id'];

    $pdo->prepare('DELETE FROM leitstellen WHERE id = ?')->execute([$del_id]);

    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'delete',
        'entity_id'   => $del_id,
        'meta'        => ['page' => 'leitstellen_editor.php'],
    ]);

    add_settings_error('lsttraining_msg', 'deleted', 'Leitstelle gelöscht.', 'updated');
}

/* -------------------------------------------------------------------------
 * CREATE
 * ---------------------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['lst_form_mode'])
    && $_POST['lst_form_mode'] === 'create'
    && $pdo
) {
    $stmt = $pdo->prepare(
        'INSERT INTO leitstellen
            (name, ort, bundesland, land, latitude, longitude, geojson)
         VALUES (?,?,?,?,?,?,?)'
    );

    $stmt->execute([
        sanitize_text_field($_POST['lst_update_name'] ?? ''),
        sanitize_text_field($_POST['lst_update_ort'] ?? ''),
        sanitize_text_field($_POST['lst_update_bl'] ?? ''),
        sanitize_text_field($_POST['lst_update_land'] ?? ''),
        (float)($_POST['lst_update_lat'] ?? 0),
        (float)($_POST['lst_update_lon'] ?? 0),
        wp_unslash($_POST['geojson_edit'] ?? ''),
    ]);

    $new_id = (int)$pdo->lastInsertId();

    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'create',
        'entity_id'   => $new_id,
        'meta'        => ['page' => 'leitstellen_editor.php'],
    ]);

    add_settings_error('lsttraining_msg', 'lst_ok', 'Leitstelle angelegt.', 'updated');
}

/* -------------------------------------------------------------------------
 * UPDATE
 * ---------------------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['lst_update_id'])
    && (!isset($_POST['lst_form_mode']) || $_POST['lst_form_mode'] !== 'create')
    && $pdo
) {
    $upd_id = (int)$_POST['lst_update_id'];

    $pdo->prepare(
        'UPDATE leitstellen
            SET name = ?,
                ort = ?,
                bundesland = ?,
                land = ?,
                latitude = ?,
                longitude = ?
          WHERE id = ?'
    )->execute([
        sanitize_text_field($_POST['lst_update_name'] ?? ''),
        sanitize_text_field($_POST['lst_update_ort'] ?? ''),
        sanitize_text_field($_POST['lst_update_bl'] ?? ''),
        sanitize_text_field($_POST['lst_update_land'] ?? ''),
        (float)($_POST['lst_update_lat'] ?? 0),
        (float)($_POST['lst_update_lon'] ?? 0),
        $upd_id,
    ]);

    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'update',
        'entity_id'   => $upd_id,
        'meta'        => ['page' => 'leitstellen_editor.php'],
    ]);

    $geojson = '';
    if (isset($_POST['geojson_edit'])) {
        $geojson = wp_unslash($_POST['geojson_edit']);
    } elseif (isset($_POST['geojson_einsatzgebiet_edit'])) {
        $geojson = wp_unslash($_POST['geojson_einsatzgebiet_edit']);
    }

    if (trim((string)$geojson) !== '') {
        $pdo->prepare('UPDATE leitstellen SET geojson = ? WHERE id = ?')
            ->execute([$geojson, $upd_id]);
    }

    add_settings_error('lsttraining_msg', 'saved', 'Leitstelle gespeichert.', 'updated');
}

/* -------------------------------------------------------------------------
 * LIST
 * ---------------------------------------------------------------------- */
if ($pdo) {
    if ($suchbegriff !== '') {
        $stmt = $pdo->prepare(
            'SELECT id,name,ort,bundesland,land,latitude,longitude
               FROM leitstellen
              WHERE name LIKE ?
                 OR id = ?
           ORDER BY name ASC'
        );
        $stmt->execute(['%' . $suchbegriff . '%', $suchbegriff]);
    } else {
        $stmt = $pdo->query(
            'SELECT id,name,ort,bundesland,land,latitude,longitude
               FROM leitstellen
           ORDER BY name ASC'
        );
    }
    $leitstellen = $stmt->fetchAll(PDO::FETCH_OBJ);
}
?>

<div class="wrap">
    <h1>Leitstellen verwalten</h1>

    <?php settings_errors('lsttraining_msg'); ?>

    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="lsttraining_leitstellen">
        <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID …"
               value="<?php echo esc_attr($suchbegriff); ?>" style="width:300px;">
        <button class="button">Suchen</button>
    </form>

    <button id="btn-new-leitstelle" class="button button-primary">+ Neue Leitstelle</button>

    <table class="widefat">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Ort</th>
                <th>Bundesland</th><th>Land</th><th>Koordinaten</th><th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($leitstellen as $l) : ?>
            <tr>
                <td><?php echo esc_html($l->id); ?></td>
                <td><?php echo esc_html($l->name); ?></td>
                <td><?php echo esc_html($l->ort); ?></td>
                <td><?php echo esc_html($l->bundesland); ?></td>
                <td><?php echo esc_html($l->land); ?></td>
                <td><?php echo esc_html($l->latitude); ?>,&nbsp;<?php echo esc_html($l->longitude); ?></td>
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

                    <a href="<?php echo esc_url(admin_url('admin.php?page=lsttraining_leitstellen&delete_id=' . (int)$l->id)); ?>"
                       class="button button-link-delete"
                       onclick="return confirm('Wirklich löschen?');"
                    >Löschen</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="popup-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:9998;"></div>

<div id="edit-leitstelle-formular" style="display:none; position:fixed; top:5%; left:50%; transform:translateX(-50%);
        background:#fff; padding:20px; max-width:800px; width:90%;
        border:1px solid #ccc; z-index:9999; box-shadow:0 0 15px rgba(0,0,0,.3);">

    <h2>Leitstelle bearbeiten</h2>

    <form method="post" style="display:flex; flex-wrap:wrap; gap:20px;">
        <input type="hidden" name="lst_form_mode" id="lst_form_mode" value="update">
        <input type="hidden" name="lst_update_id" id="lst_update_id" value="<?php echo esc_attr((int)$leitstelle_id); ?>">

        <div style="flex:1 1 48%;">
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

        <div style="flex:1 1 48%;"><div id="map_edit" style="height:300px;"></div></div>

        <div style="width:100%;">
            <?php
            lsttraining_einsatzgebiet_editor(
                'einsatzgebiet_edit',
                'geojson_edit',
                '',
                0,
                'leitstelle',
                ''
            );
            ?>

            <div class="lsttraining-actions-row">
                <button type="button"
                        class="button open-einsatzgebiet-editor"
                        data-map-id="einsatzgebiet_edit"
                        data-leitstelle-id="0"
                        data-center=""
                        data-context="leitstelle">
                    Einsatzgebiet bearbeiten
                </button>

                <button type="button"
                        class="button open-wachen-editor"
                        data-base-url="<?php echo esc_url(admin_url('admin.php?page=lsttraining_leitstellen_wachen')); ?>">
                    Wachen bearbeiten
                </button>

                <button type="button" class="button open-leitstelle-hospitals-editor">
                    Krankenhäuser bearbeiten
                </button>

                <button type="button" class="button open-leitstelle-pois-editor">
                    POIs bearbeiten
                </button>

                <button
				  type="button"
				  class="button"
				  id="w_zuord_button_l"
				  disabled
				  title="Bitte zuerst speichern">
				  Zuordnung der Wachen bearbeiten
				</button>

            </div>

            <p style="margin-top:12px;">
                <button class="button button-primary">Speichern</button>
                <button type="button" class="button"
                        onclick="document.getElementById('popup-overlay').style.display='none';
                                 document.getElementById('edit-leitstelle-formular').style.display='none';">
                    Abbrechen
                </button>
            </p>
        </div>
    </form>
</div>

<script>
(function () {
  function getInt(v) {
    var n = parseInt(v, 10);
    return isNaN(n) ? 0 : n;
  }

  function syncLeitstellenButtons() {
    var idEl = document.getElementById('lst_update_id');
    if (!idEl) return;

    var id = getInt(idEl.value);

    var zuoBtn = document.getElementById('w_zuord_button_l');
    if (zuoBtn) {
      var ok = id > 0;
      zuoBtn.disabled = !ok;
      zuoBtn.title = ok ? '' : 'Bitte zuerst speichern';
    }

    var wachenBtn = document.querySelector('.open-wachen-editor');
    if (wachenBtn) {
      var base = wachenBtn.getAttribute('data-base-url') || '';
      wachenBtn.disabled = !(id > 0);
      wachenBtn.title = (id > 0) ? '' : 'Bitte zuerst speichern';
      wachenBtn.onclick = function () {
        var curId = getInt((document.getElementById('lst_update_id') || {}).value);
        if (curId <= 0) return;
        window.location.href = base + '&ls_id=' + encodeURIComponent(String(curId));
      };
    }

    if (zuoBtn) {
	  zuoBtn.onclick = function (e) {
		e.preventDefault();
		e.stopPropagation();

		var curId = getInt((document.getElementById('lst_update_id') || {}).value);
		if (curId <= 0) return;

		if (typeof window.openZuordnungPopup === 'function') {
		  window.openZuordnungPopup({ entityType: 'leitstelle', entityId: curId });
		} else {
		  console.error('openZuordnungPopup ist nicht geladen');
		}
	  };
	}

    var egBtn = document.querySelector('.open-einsatzgebiet-editor');
    if (egBtn) {
      egBtn.setAttribute('data-leitstelle-id', String(id));
    }
  }

  document.addEventListener('DOMContentLoaded', syncLeitstellenButtons);

  document.addEventListener('click', function (e) {
    var edit = e.target.closest('.edit-leitstelle');
    if (!edit) return;

    setTimeout(function () {
      syncLeitstellenButtons();
    }, 0);
  });

  document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'lst_update_id') {
      syncLeitstellenButtons();
    }
  });
})();
</script>

<script type="text/html" id="tmpl-leitstellen-hospitals-editor">
  <div class="leitstellen-hospitals-content">
    <form id="leitstellen-hospitals-form">
      <input type="hidden" name="leitstelle_id" value="{{ data.leitstelle_id }}">
      <div id="leitstellen-hospitals-map" style="height:300px; margin-bottom:1em; border:1px solid #ccc;"></div>
      <input type="text" id="leitstellen-hospitals-filter" placeholder="Nach ID oder Name filtern…" style="width:100%; margin-bottom:8px; padding:4px; box-sizing:border-box;">
      <div id="leitstellen-hospitals-selector" style="max-height:200px; overflow-y:auto; margin-bottom:1em;">
        <# _.each( data.hospitals, function( h ){ #>
          <label style="display:block; padding:4px;">
            <input class="hos-toggle" type="checkbox" value="{{ h.id }}">
            {{ h.name }}
          </label>
        <# }); #>
      </div>
      <p class="description">
        Wähle hier aus, welche Krankenhäuser für diese Leitstelle verfügbar sein sollen.
      </p>
      <p class="submit" style="display:flex; gap:0.5em;">
        <button type="submit" class="button button-primary">Speichern</button>
        <button type="button" id="leitstellen-hospitals-cancel" class="button">Abbrechen</button>
      </p>
    </form>
  </div>
</script>

<script type="text/html" id="tmpl-leitstellen-pois-editor">
  <div class="leitstellen-pois-content">
    <div class="lst-poi-mapwrap">
      <div id="leitstellen-pois-map" style="height: 420px;"></div>

      <div id="lst-poi-list" class="lst-poi-list-overlay">
        <div class="lst-poi-list-head">
          <strong>POIs</strong>
          <button type="button" class="button button-small" id="lst-poi-close-list">×</button>
        </div>
        <div class="lst-poi-list-body">
          <table class="widefat fixed striped" id="leitstellen-pois-table">
            <thead>
              <tr>
                <th>Typ</th>
                <th>Genus</th>
                <th>Name</th>
                <th>Kommentar</th>
              </tr>
            </thead>
            <tbody>
              <# _.each(data.pois || [], function(p){ #>
                <tr class="poi-row" data-id="{{ p.id }}">
                  <td>{{ p.poi_type }}</td>
                  <td>{{ p.genus }}</td>
                  <td>{{ p.name }}</td>
                  <td>{{ p.comment }}</td>
                </tr>
              <# }); #>
            </tbody>
          </table>
        </div>
      </div>

      <div id="lst-poi-editor" class="lst-poi-editor">
        <div class="lst-poi-editor-head">
          <strong>POI bearbeiten</strong>
          <button type="button" class="button button-small" id="lst-poi-editor-close">×</button>
        </div>

        <form id="leitstellen-pois-form">
          <input type="hidden" id="poi_id" value="" />

          <p>
            <label for="poi_type"><strong>POI-Art</strong></label><br />
            <select id="poi_type">
              <# _.each(data.poi_types || [], function(t){ #>
                <option value="{{ t.tag }}">{{ t.tag }}</option>
              <# }); #>
            </select>
            <div id="poi_type_desc" class="description" style="margin-top:6px;"></div>
          </p>

          <p>
            <label for="poi_genus"><strong>Genus</strong></label><br />
            <select id="poi_genus">
              <option value="der">der</option>
              <option value="die">die</option>
              <option value="das">das</option>
            </select>
          </p>

          <p>
            <label for="poi_name"><strong>Bezeichnung</strong></label><br />
            <input type="text" id="poi_name" />
          </p>

          <p>
            <label for="poi_comment"><strong>Kommentar</strong></label><br />
            <textarea id="poi_comment" rows="4"></textarea>
          </p>

          <div class="row-2col">
            <div class="col">
              <label for="poi_lat"><strong>Lat</strong></label>
              <input type="text" id="poi_lat" />
            </div>
            <div class="col">
              <label for="poi_lon"><strong>Lon</strong></label>
              <input type="text" id="poi_lon" />
            </div>
          </div>

          <div class="lst-poi-actions">
            <button type="submit" class="button button-primary">Speichern</button>
            <button type="button" class="button" id="leitstellen-pois-delete" disabled>Löschen</button>
            <button type="button" class="button" id="leitstellen-pois-cancel">Schließen</button>
          </div>
        </form>
      </div>

      <div id="lst-poi-import-panel" class="lst-poi-import-panel hidden" role="dialog" aria-modal="true">
        <div class="lst-poi-import-head">
          <strong>POI-LSTSim Import</strong>
          <button type="button" class="button button-small" id="lst-poi-import-close">×</button>
        </div>

        <p class="description" style="margin-top:8px;">
          TSV/CSV einfügen: ID (optional), Koordinaten (lat,lon), Genus (M/F/N oder der/die/das), Name, Tags, Kommentar.
          IDs werden ignoriert.
        </p>

        <textarea id="lst-poi-import-text" rows="6" style="width:100%;"></textarea>

        <div class="lst-poi-import-actions">
          <button type="button" class="button" id="lst-poi-import-parse">Vorschau</button>
          <button type="button" class="button button-primary" id="lst-poi-import-run" disabled>Importieren</button>
        </div>

        <div id="lst-poi-import-preview" style="margin-top:10px;"></div>
      </div>

    </div>

    <div class="lst-poi-toolbar">
      <input type="text" id="leitstellen-pois-filter" placeholder="Filtern (Typ/Name/Kommentar)..." />
      <button type="button" class="button" id="leitstellen-pois-new">Neu</button>
      <button type="button" class="button" id="lst-poi-toggle-list">Liste</button>
      <button type="button" class="button" id="lst-poi-open-editor">Editor</button>
      <button type="button" class="button" id="lst-poi-import">POI-LSTSim Import</button>
    </div>

  </div>
</script>

<div id="leitstellen-pois-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2><?php esc_html_e('POIs für Leitstelle bearbeiten','lsttraining'); ?></h2>
      <button class="modal-close">×</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

<div id="leitstellen-hospitals-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2><?php esc_html_e('Krankenhäuser für Leitstelle bearbeiten','lsttraining'); ?></h2>
      <button class="modal-close">×</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>
