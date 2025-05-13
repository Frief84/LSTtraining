// ---------------------------------------------------------------------------
// includes/js/leitstellen_editor.js
// ---------------------------------------------------------------------------
//
// Geo-JSON editor for operational areas **plus** popup handler for
// “+ Neue Leitstelle”  (create station).
// All public helpers are attached to the global `window` object so they can
// be reused in other admin scripts.
//
// ---------------------------------------------------------------------------

(function (window, document, ol) {

  /* =========================
   * 1) Geo-JSON polygon editor
   * ========================= */

  /**
   * Initialise polygon editor for an emergency control centre.
   *
   * @param {string} mapElementId  DOM id of the map container
   * @param {string} initialGeoJson  GeoJSON string coming from the DB
   */
  function initLeitstellenEditor(mapElementId, initialGeoJson) {

    const format = new ol.format.GeoJSON();

    const vectorSource = new ol.source.Vector({
      features: format.readFeatures(initialGeoJson, { featureProjection: 'EPSG:3857' })
    });

    const vectorLayer = new ol.layer.Vector({
      source: vectorSource,
      style: new ol.style.Style({
        stroke: new ol.style.Stroke({ width: 2, color: '#FF0000' }),
        fill:   new ol.style.Fill  ({ color: 'rgba(255,0,0,0.1)' })
      })
    });

    const map = new ol.Map({
      target: mapElementId,
      layers: [
        new ol.layer.Tile({ source: new ol.source.OSM() }),
        vectorLayer
      ],
      view: new ol.View({ center: [0, 0], zoom: 2 })
    });

    /* save button → AJAX */
    document.getElementById('save-leitstelle')
      .addEventListener('click', () => {
        const features = vectorSource.getFeatures();
        const geojson = format.writeFeatures(features, { featureProjection: 'EPSG:3857' });

        wp.ajax.post('save_leitstelle', {
          id:      document.getElementById('leitstelle-id').value,
          geojson: geojson
        }).done(() => {
          alert('Leitstelle gespeichert!');
        }).fail(error => {
          alert('Fehler: ' + error);
        });
      });
  }

  /* expose editor globally */
  window.initLeitstellenEditor = initLeitstellenEditor;

  /* =========================================================
   * 2) “+ Neue Leitstelle” popup (create-station work-flow)
   * ========================================================= */

  /**
   * Reset all inputs, switch to “create” mode, show popup.
   * Called whenever the “+ Neue Leitstelle” button is clicked.
   */
  function openLeitstellePopupForCreate() {

    /* headline */
    const heading = document.querySelector('#edit-leitstelle-formular h2');
    if (heading) heading.textContent = 'Leitstelle erstellen';

    /* clear inputs */
    [
      'lst_update_id', 'lst_update_name', 'lst_update_ort',
      'lst_update_bl', 'lst_update_land', 'lst_update_lat', 'lst_update_lon'
    ].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });

    /* switch mode */
    const mode = document.getElementById('lst_form_mode');
    if (mode) mode.value = 'create';

    /* reset / init map */
    if (typeof resetEditMaps  === 'function') resetEditMaps();
    if (typeof ensureEditMap  === 'function') ensureEditMap();   // <— add helper below

    /* show overlay + popup */
    const overlay = document.getElementById('popup-overlay');
    if (overlay) overlay.style.display = 'block';

    const popup = document.getElementById('edit-leitstelle-formular');
    if (popup)  popup.style.display = 'block';
  }

  /**
   * Make sure an edit map exists and is centred on Germany.
   * Dummy placeholder – replace by your real implementation or import it
   * from another file if you already have one.
   */
  function ensureEditMap() {
    // create map if it doesn’t exist, otherwise call map.updateSize()
    // -> add real code here or keep as no-op if not required
  }
  window.ensureEditMap = ensureEditMap;

  /**
   * Centre existing map on Germany and clear polygon/marker.
   */
  function resetEditMaps() {
    if (window.mapEdit) {
      window.mapEdit.getView().setCenter(ol.proj.fromLonLat([9.0, 51.0]));
      window.mapEdit.getLayers().item(1).getSource().clear();
    }
    const poly = document.getElementById('geojson_edit');
    if (poly) poly.value = '';
  }
  window.resetEditMaps = resetEditMaps;

  /* register button listener once DOM is ready */
  document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btn-new-leitstelle');
    if (!btn) {
      console.warn('[lsttraining] “+ Neue Leitstelle” button not found.');
      return;
    }
    btn.addEventListener('click', e => {
      e.preventDefault();
      openLeitstellePopupForCreate();
    });
  });

})(window, document, ol);
