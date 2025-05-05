// includes/js/leitstellen_editor.js

(function(window, document, ol) {
  // Initialisierung des Editors
  function initLeitstellenEditor(mapElementId, initialGeoJson) {
    const format = new ol.format.GeoJSON();
    const vectorSource = new ol.source.Vector({
      features: format.readFeatures(initialGeoJson, {
        featureProjection: 'EPSG:3857'
      })
    });

    const vectorLayer = new ol.layer.Vector({
      source: vectorSource,
      style: new ol.style.Style({
        stroke: new ol.style.Stroke({ width: 2, color: '#FF0000' }),
        fill: new ol.style.Fill({ color: 'rgba(255,0,0,0.1)' })
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

    // Event-Handler zum Speichern der GeoJSON-Daten
    document.getElementById('save-leitstelle').addEventListener('click', () => {
      const features = vectorSource.getFeatures();
      const geojson = format.writeFeatures(features, {
        featureProjection: 'EPSG:3857'
      });
      // AJAX-Call zum Speichern
      wp.ajax.post('save_leitstelle', {
        id: document.getElementById('leitstelle-id').value,
        geojson: geojson
      }).done(response => {
        alert('Leitstelle gespeichert!');
      }).fail(error => {
        alert('Fehler: ' + error);
      });
    });
  }

  // Globale Freigabe
  window.initLeitstellenEditor = initLeitstellenEditor;

})(window, document, ol);
