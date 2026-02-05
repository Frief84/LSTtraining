window._openlayersMaps = window._openlayersMaps || {};

window.initEinsatzgebietEditor = function (container) {
  if (!container) return;

  const mapId     = container.dataset.mapId;
  const geojsonId = container.dataset.geojsonId;
  const centerStr = container.dataset.center || '';

  const geojsonTextarea = geojsonId ? document.getElementById(geojsonId) : null;

  // Map target: optional fallback auf data-einsatzgebiet-map
  let mapTarget = document.getElementById(mapId);
  if (!mapTarget) {
    const candidate = container.querySelector('[data-einsatzgebiet-map]');
    if (candidate) {
      candidate.id = mapId;
      mapTarget = candidate;
    }
  }
  if (!mapTarget) {
    console.error('Map target not found:', mapId);
    return;
  }

  // Popup sichtbar machen BEVOR OpenLayers arbeitet
  container.style.display = 'block';

  // Controls, die wir hier brauchen (Turf-Import läuft woanders)
  const fileInput   = container.querySelector('#geojson-file');     // wichtig: Bindestrich
  const manualInput = container.querySelector('#manual_geojson');

  // ------------------------------------------------------------
  // Hilfsfunktion: normalisieren + anwenden
  // ------------------------------------------------------------
  function normalizeToFeatureCollection(obj) {
    if (!obj) return { type: 'FeatureCollection', features: [] };

    if (obj.type === 'FeatureCollection' && Array.isArray(obj.features)) return obj;
    if (obj.type === 'Feature') return { type: 'FeatureCollection', features: [obj] };

    // reine Geometry
    if (obj.type && (obj.coordinates || obj.geometries)) {
      return {
        type: 'FeatureCollection',
        features: [{ type: 'Feature', properties: {}, geometry: obj }]
      };
    }

    // Array von Features
    if (Array.isArray(obj)) {
      return { type: 'FeatureCollection', features: obj };
    }

    throw new Error('Unbekanntes GeoJSON-Format');
  }

  function applyGeoJSONToMap(fc) {
    const map = window._openlayersMaps[mapId];
    const format = container._egFormat;
    const vectorSource = container._egVectorSource;

    if (!map || !format || !vectorSource) {
      console.error('Einsatzgebiet-Editor State fehlt (map/format/vectorSource).');
      return;
    }

    vectorSource.clear();

    let feats = [];
    try {
      feats = format.readFeatures(fc, { featureProjection: map.getView().getProjection() });
    } catch (e) {
      console.error(e);
      return;
    }

    vectorSource.addFeatures(feats);

    if (feats.length > 0) {
      map.getView().fit(vectorSource.getExtent(), {
        padding: [40, 40, 40, 40],
        maxZoom: 14
      });
    }

    requestAnimationFrame(() => map.updateSize());
  }

  // ------------------------------------------------------------
  // Map erstellen ODER bestehenden State verwenden
  // ------------------------------------------------------------
  if (!window._openlayersMaps[mapId]) {
    // Center bestimmen
    let centerCoords = ol.proj.fromLonLat([13.072128, 52.400705]);
    if (centerStr && centerStr.includes(',')) {
      const parts = centerStr.split(',').map(v => parseFloat(v.trim()));
      if (parts.length === 2 && !isNaN(parts[0]) && !isNaN(parts[1])) {
        centerCoords = ol.proj.fromLonLat([parts[1], parts[0]]);
      }
    }

    const format = new ol.format.GeoJSON();
    const vectorSource = new ol.source.Vector();
    const vectorLayer = new ol.layer.Vector({ source: vectorSource });

    const map = new ol.Map({
      target: mapId,
      layers: [
        new ol.layer.Tile({ source: new ol.source.OSM() }),
        vectorLayer
      ],
      view: new ol.View({ center: centerCoords, zoom: 10 })
    });

    window._openlayersMaps[mapId] = map;

    // State am Container merken (damit reopen funktioniert)
    container._egFormat = format;
    container._egVectorSource = vectorSource;

    requestAnimationFrame(() => map.updateSize());

    // Bestehendes GeoJSON laden
    if (geojsonTextarea && geojsonTextarea.value.trim() !== '') {
      try {
        const obj = JSON.parse(geojsonTextarea.value);
        const fc = normalizeToFeatureCollection(obj);
        applyGeoJSONToMap(fc);
      } catch (e) {
        console.error('Invalid GeoJSON:', e);
      }
    }

    // Draw Interaction
    const draw = new ol.interaction.Draw({ source: vectorSource, type: 'Polygon' });
    map.addInteraction(draw);

    draw.on('drawend', () => {
      // Features -> FeatureCollection (als JSON-String)
      const fcText = format.writeFeatures(
        vectorSource.getFeatures(),
        { featureProjection: map.getView().getProjection() }
      );

      if (geojsonTextarea) geojsonTextarea.value = fcText;

      // UI bereinigen: manual/file nur als Inputquellen
      if (manualInput) manualInput.value = '';
      if (fileInput) fileInput.value = '';
    });

    // Delete Button
    const deleteBtn = container.querySelector('.btn-einsatzgebiet-delete');
    if (deleteBtn && !deleteBtn._bound) {
      deleteBtn._bound = true;
      deleteBtn.onclick = function () {
        if (!confirm('Einsatzgebiet wirklich löschen?')) return;
        vectorSource.clear();
        if (geojsonTextarea) geojsonTextarea.value = '';
        if (manualInput) manualInput.value = '';
        if (fileInput) fileInput.value = '';
      };
    }
  } else {
    // Map existiert schon -> resize
    requestAnimationFrame(() => window._openlayersMaps[mapId].updateSize());

    // Falls State fehlt, nachziehen
    if (!container._egFormat) container._egFormat = new ol.format.GeoJSON();

    if (!container._egVectorSource) {
      const map = window._openlayersMaps[mapId];
      let vLayer = null;
      map.getLayers().forEach((layer) => {
        if (!vLayer && layer instanceof ol.layer.Vector) vLayer = layer;
      });
      if (vLayer) container._egVectorSource = vLayer.getSource();
    }

    // Wenn sich das Hidden-Feld zwischenzeitlich geändert hat (z.B. Turf-Process),
    // optional neu laden und anzeigen:
    if (geojsonTextarea && geojsonTextarea.value.trim() !== '') {
      try {
        const obj = JSON.parse(geojsonTextarea.value);
        const fc = normalizeToFeatureCollection(obj);
        applyGeoJSONToMap(fc);
      } catch (e) {
        // nicht abbrechen, nur loggen
        console.error('Invalid GeoJSON:', e);
      }
    }
  }

  // Close Button (immer binden)
  const closeBtn = container.querySelector('.btn-einsatzgebiet-close');
  if (closeBtn && !closeBtn._bound) {
    closeBtn._bound = true;
    closeBtn.onclick = function () {
      container.style.display = 'none';
    };
  }
};

window.openEinsatzgebietPopup = function() {
    const container = document.querySelector('.einsatzgebiet-popup');
    if (!container) {
        alert("Einsatzgebiet-Editor nicht gefunden.");
        return;
    }

    if (typeof window.initEinsatzgebietEditor === 'function') {
        window.initEinsatzgebietEditor(container);
    }

    container.style.display = 'block';


    const mapId = container.dataset.mapId;
    const map = window._openlayersMaps?.[mapId];
    if (map) {
        requestAnimationFrame(() => map.updateSize());
    }
};
	
	document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('#btn-geojson-import');
  if (!btn) return;

  const popup = btn.closest('.einsatzgebiet-popup');
  if (!popup) {
    console.error('#btn-geojson-import: kein .einsatzgebiet-popup gefunden');
    return;
  }

  const fileInput  = popup.querySelector('#geojson_file');
  const manualArea = popup.querySelector('#manual_geojson');

  const hasFile   = !!(fileInput && fileInput.files && fileInput.files.length > 0);
  const manualTxt = (manualArea && manualArea.value || '').trim();
  const hasManual = manualTxt !== '';

  if (hasFile && hasManual) {
    alert('Bitte nutze entweder eine Datei ODER das Textfeld (nicht beides).');
    return;
  }
  if (!hasFile && !hasManual) {
    alert('Bitte wähle eine GeoJSON-Datei aus oder füge GeoJSON in das Textfeld ein.');
    return;
  }

  let geojsonText = '';

  if (hasFile) {
    const file = fileInput.files[0];
    geojsonText = await file.text();
  } else {
    geojsonText = manualTxt;
  }

  // JSON prüfen + normalisieren (FeatureCollection)
  let obj;
  try {
    obj = JSON.parse(geojsonText);
  } catch (e) {
    alert('Ungültiges JSON. Bitte prüfe die GeoJSON-Struktur.');
    return;
  }

  // akzeptiere: FeatureCollection, Feature, Geometry oder Array<Feature>
  if (Array.isArray(obj)) {
    obj = { type: 'FeatureCollection', features: obj };
  } else if (obj && obj.type === 'Feature') {
    obj = { type: 'FeatureCollection', features: [obj] };
  } else if (obj && (obj.type === 'Polygon' || obj.type === 'MultiPolygon' || obj.type === 'GeometryCollection')) {
    obj = { type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: obj }] };
  }

  if (!obj || obj.type !== 'FeatureCollection' || !Array.isArray(obj.features)) {
    alert('GeoJSON muss eine FeatureCollection (oder Feature/Polygon) sein.');
    return;
  }

  // in hidden field schreiben (dein System nutzt geojson_edit)
  const hidden = document.getElementById('geojson_edit');
  if (!hidden) {
    console.error('Hidden field #geojson_edit nicht gefunden');
    return;
  }
  hidden.value = JSON.stringify(obj);

  // Karte updaten
  const mapId = popup.dataset.mapId;
  window._openlayersMaps = window._openlayersMaps || {};
  const map = window._openlayersMaps[mapId];

  if (!map) {
    console.warn('Keine Map gefunden für mapId:', mapId, '→ initEinsatzgebietEditor() muss vorher laufen');
    return;
  }

  // Vector-Layer finden oder anlegen (wir nehmen den ersten Vector-Layer nach OSM)
  let vectorLayer = null;
  map.getLayers().forEach((layer) => {
    if (!vectorLayer && layer instanceof ol.layer.Vector) vectorLayer = layer;
  });

  if (!vectorLayer) {
    vectorLayer = new ol.layer.Vector({ source: new ol.source.Vector() });
    map.addLayer(vectorLayer);
  }

  const source = vectorLayer.getSource();
  source.clear();

  const fmt = new ol.format.GeoJSON();
  let feats = [];
  try {
    feats = fmt.readFeatures(obj, { featureProjection: map.getView().getProjection() });
  } catch (e) {
    alert('GeoJSON konnte nicht in Features umgewandelt werden.');
    return;
  }
  source.addFeatures(feats);

  if (feats.length > 0) {
    map.getView().fit(source.getExtent(), { padding: [40, 40, 40, 40], maxZoom: 14 });
  }

  // UI bereinigen: nur die genutzte Quelle behalten
  if (hasFile && manualArea) manualArea.value = '';
  if (hasManual && fileInput) fileInput.value = '';

  alert('Einsatzgebiet importiert.');


});
