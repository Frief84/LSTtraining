/* global ol */
(() => {
  let selectedFile = null;

  /* ---------------- Datei wählen ---------------- */
  document.addEventListener('change', e => {
    if (e.target.id !== 'geojson-file') return;
    selectedFile = e.target.files[0] || null;
    document.getElementById('geojson-process').disabled = !selectedFile;
  });
	
	function getEinsatzgebietMap() {
	  const popup = document.querySelector('.einsatzgebiet-popup');
	  if (!popup) return null;

	  const mapId = popup.dataset.mapId;          // kommt aus einsatzgebiet-editor.php
	  return window._openlayersMaps?.[mapId] || null;
	}
  /* --------------- Button klick ----------------- */
  document.addEventListener('click', async e => {
    if (e.target.id !== 'geojson-process') return;
    if (!selectedFile) return alert('Bitte zuerst eine GeoJSON-Datei wählen.');

    /* 1) Datei lesen */
    let fc;
    try { fc = JSON.parse(await selectedFile.text()); }
    catch { return alert('Ungültige GeoJSON-Datei.'); }

    /* 2) Vereinfachen & verschmelzen (Turf) */
    const tol = 0.0002;
    fc.features = fc.features.map(f => turf.simplify(f, { tolerance: tol }));
    let outline = fc.features[0];
    for (let i = 1; i < fc.features.length; i++) {
      outline = turf.union(outline, fc.features[i]);
    }
	
	/* 2b)  Innenringe entfernen  -------------------------------------------- */
	if (outline.geometry.type === 'Polygon') {
	  // Nur den ersten Ring (Außen-Ring) behalten
	  outline.geometry.coordinates = [outline.geometry.coordinates[0]];
	}
	else if (outline.geometry.type === 'MultiPolygon') {
	  // Bei jedem Teil-Polygon nur den Außen-Ring behalten
	  outline.geometry.coordinates = outline.geometry.coordinates
		.map(coords => [coords[0]]);
	}

    /* 3) Ergebnis ins versteckte Feld */
    document.getElementById('geojson').value = JSON.stringify(outline);

    /* 4) Vorschau – vorhandene Map & Layer nutzen */
    const mapEl   = document.querySelector('[data-einsatzgebiet-map]'); // DIV hat dieses Data-Attribut
    if (!mapEl) return alert('Karte nicht gefunden.');
    const mapId   = mapEl.id;                   // z. B. "map_123"
const map = getEinsatzgebietMap();
if (!map) return alert('Karte noch nicht initialisiert (Popup zuerst öffnen).');

    // Preview-Layer anlegen oder updaten
    const fmt   = new ol.format.GeoJSON();
    const feats = fmt.readFeatures(outline, {
      dataProjection: 'EPSG:4326',
      featureProjection: map.getView().getProjection()
    });
    const src   = new ol.source.Vector({ features: feats });

    if (!window.previewLayer) {
      window.previewLayer = new ol.layer.Vector({ source: src });
      map.addLayer(window.previewLayer);
    } else {
      window.previewLayer.setSource(src);
    }
    map.getView().fit(src.getExtent(), { padding: [40, 40, 40, 40] });
	
	if (typeof window.vectorSource === 'object') {         // kommt aus initEinsatzgebietEditor
	  window.vectorSource.clear();
	  window.vectorSource.addFeatures(feats);              // feats haben wir für previewLayer erstellt
	}

    alert('Einsatzgebiet übernommen – jetzt Formular speichern.');
  });
})();
