/* global ol, turf, ajaxurl */
(() => {
  // ------------------------------------------------------------
  // Helpers: DOM + Popup + Map
  // ------------------------------------------------------------
  function getPopupFromEl(el) {
    return el ? el.closest('.einsatzgebiet-popup') : null;
  }

  function getPopup() {
    return document.getElementById('lst-eg-popup') || document.querySelector('.einsatzgebiet-popup');
  }

  function getEls(popup) {
    if (!popup) return {};
    return {
      popup,
      fileInput: popup.querySelector('[data-eg-file]'),
      manual: popup.querySelector('[data-eg-manual]'),
      processBtn: popup.querySelector('[data-eg-process]'),
      saveBtn: popup.querySelector('.btn-einsatzgebiet-save'),
      deleteBtn: popup.querySelector('.btn-einsatzgebiet-delete'),
      closeBtns: popup.querySelectorAll('.btn-einsatzgebiet-close'),
      mapDiv: popup.querySelector('[data-einsatzgebiet-map]')
    };
  }

  function getMap(popup) {
    if (!popup) return null;
    const mapId = popup.dataset.mapId;
    return window._openlayersMaps && mapId ? window._openlayersMaps[mapId] : null;
  }

  function hasManualText(manualEl) {
    return !!(manualEl && manualEl.value && manualEl.value.trim() !== '');
  }

  // ------------------------------------------------------------
  // Enable/Disable Process Button
  // ------------------------------------------------------------
  function updateProcessEnabled(popup) {
    const { fileInput, manual, processBtn } = getEls(popup);
    if (!processBtn) return;

    const hasFile = !!(fileInput && fileInput.files && fileInput.files.length > 0);
    const hasManual = hasManualText(manual);

    processBtn.disabled = !(hasFile || hasManual);
  }

  // ------------------------------------------------------------
  // GeoJSON Normalisierung / Verarbeitung
  // ------------------------------------------------------------
  function normalizeToFeatureCollection(obj) {
    if (!obj) return { type: 'FeatureCollection', features: [] };

    if (obj.type === 'FeatureCollection' && Array.isArray(obj.features)) return obj;

    if (obj.type === 'Feature') {
      return { type: 'FeatureCollection', features: [obj] };
    }

    // reine Geometry
    if (obj.type && (obj.coordinates || obj.geometries)) {
      return {
        type: 'FeatureCollection',
        features: [{ type: 'Feature', properties: {}, geometry: obj }]
      };
    }

    // Array<Feature>
    if (Array.isArray(obj)) {
      return { type: 'FeatureCollection', features: obj };
    }

    throw new Error('Unbekanntes GeoJSON-Format');
  }

  function stripHoles(feature) {
    if (!feature || !feature.geometry) return feature;
    const g = feature.geometry;

    if (g.type === 'Polygon') {
      g.coordinates = [g.coordinates[0]];
    } else if (g.type === 'MultiPolygon') {
      g.coordinates = g.coordinates.map(coords => [coords[0]]);
    }
    return feature;
  }

  async function readGeoJSONFromInputs(popup) {
    const { fileInput, manual } = getEls(popup);

    const hasFile = !!(fileInput && fileInput.files && fileInput.files.length > 0);
    const hasManual = hasManualText(manual);

    if (hasFile && hasManual) {
      alert('Bitte entweder eine Datei auswählen oder das Textfeld nutzen, nicht beides.');
      return null;
    }

    if (!hasFile && !hasManual) {
      alert('Bitte eine Datei auswählen oder GeoJSON in das Textfeld einfügen.');
      return null;
    }

    let raw = '';
    if (hasManual) {
      raw = manual.value.trim();
    } else {
      raw = await fileInput.files[0].text();
    }

    let obj;
    try {
      obj = JSON.parse(raw);
    } catch (e) {
      alert('Ungültiges JSON. Bitte prüfe die GeoJSON-Struktur.');
      return null;
    }

    try {
      return normalizeToFeatureCollection(obj);
    } catch (e) {
      alert('GeoJSON muss FeatureCollection, Feature oder Geometry sein.');
      return null;
    }
  }

  // ------------------------------------------------------------
  // Hidden Field schreiben + Map Preview aktualisieren
  // ------------------------------------------------------------
  function writeToHidden(popup, outlineFeature) {
    const geoId = popup.dataset.geojsonId;
    const target = geoId ? document.getElementById(geoId) : null;

    if (!target) {
      console.error('Hidden field not found for data-geojson-id:', geoId);
      return false;
    }

    const fc = { type: 'FeatureCollection', features: [outlineFeature] };
    target.value = JSON.stringify(fc);
    return true;
  }

  function updateMapPreview(popup, outlineFeature) {
    const map = getMap(popup);
    if (!map) {
      alert('Karte noch nicht initialisiert (Popup zuerst öffnen).');
      return;
    }

    const fmt = new ol.format.GeoJSON();
    const feats = fmt.readFeatures(
      { type: 'FeatureCollection', features: [outlineFeature] },
      {
        dataProjection: 'EPSG:4326',
        featureProjection: map.getView().getProjection()
      }
    );

    // Haupt-Vector-Layer (aus initEinsatzgebietEditor) finden:
    // wir nehmen den ersten Vector-Layer, der nicht explizit Preview ist.
    // Falls du keinen Preview-Layer möchtest, reicht das komplett.
    let mainVectorLayer = null;
    map.getLayers().forEach(layer => {
      if (mainVectorLayer) return;
      if (layer instanceof ol.layer.Vector) {
        mainVectorLayer = layer;
      }
    });

    if (!mainVectorLayer) {
      // Fallback: Preview Layer anlegen
      if (!popup._egPreviewLayer) {
        popup._egPreviewLayer = new ol.layer.Vector({ source: new ol.source.Vector() });
        map.addLayer(popup._egPreviewLayer);
      }
      mainVectorLayer = popup._egPreviewLayer;
    }

    const src = mainVectorLayer.getSource();
    src.clear();
    src.addFeatures(feats);

    if (src.getFeatures().length > 0) {
      map.getView().fit(src.getExtent(), {
        padding: [40, 40, 40, 40],
        maxZoom: 14
      });
    }

    requestAnimationFrame(() => map.updateSize());
  }

  // ------------------------------------------------------------
  // AJAX Save (Leitstelle)
  // ------------------------------------------------------------
  async function ajaxSaveEinsatzgebiet(popup) {
    const leitstelleId = parseInt(popup.dataset.leitstelleId || '0', 10);
    if (!leitstelleId) {
      alert('Leitstellen-ID fehlt.');
      return false;
    }

    const geoId = popup.dataset.geojsonId;
    const target = geoId ? document.getElementById(geoId) : null;
    const geojson = target ? (target.value || '').trim() : '';

    if (!geojson) {
      alert('Kein GeoJSON vorhanden. Bitte zeichnen oder importieren.');
      return false;
    }

    const fd = new FormData();
    fd.append('action', 'lsttraining_save_einsatzgebiet');
    fd.append('leitstelle_id', String(leitstelleId));
    fd.append('geojson', geojson);

    const url = window.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : null);
    if (!url) {
      alert('ajaxurl nicht gefunden.');
      return false;
    }

    const res = await fetch(url, { method: 'POST', body: fd });
    const json = await res.json().catch(() => null);

    if (!json || !json.success) {
      const msg = json && json.data ? json.data : 'Speichern fehlgeschlagen.';
      alert(msg);
      return false;
    }

    return true;
  }

  // ------------------------------------------------------------
  // Event Delegation
  // ------------------------------------------------------------
  // Enable Button bei Datei/Text
  document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-eg-file]')) return;
    const popup = getPopupFromEl(e.target);
    if (!popup) return;
    updateProcessEnabled(popup);
  });

  document.addEventListener('input', (e) => {
    if (!e.target.matches('[data-eg-manual]')) return;
    const popup = getPopupFromEl(e.target);
    if (!popup) return;
    updateProcessEnabled(popup);
  });

  // Process Button: Turf simplify + union + holes entfernen
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-eg-process]');
    if (!btn) return;

    const popup = getPopupFromEl(btn);
    if (!popup) return;

    const { fileInput, manual } = getEls(popup);

    updateProcessEnabled(popup);
    if (btn.disabled) return;

    const fc = await readGeoJSONFromInputs(popup);
    if (!fc) return;

    if (!fc.features || fc.features.length === 0) {
      alert('GeoJSON enthält keine Features.');
      return;
    }

    // 1) Vereinfachen
    const tol = 0.0002;
    const simplified = fc.features.map(f => turf.simplify(f, { tolerance: tol }));

    // 2) Union
    let outline = simplified[0];
    for (let i = 1; i < simplified.length; i++) {
      outline = turf.union(outline, simplified[i]);
      if (!outline) {
        alert('Union fehlgeschlagen. Prüfe die Geometrien.');
        return;
      }
    }

    // 3) Innenringe entfernen
    outline = stripHoles(outline);

    // 4) Ergebnis speichern + Karte aktualisieren
    if (!writeToHidden(popup, outline)) return;
    updateMapPreview(popup, outline);

    // 5) UI bereinigen: nur die genutzte Quelle behalten
    const usedManual = hasManualText(manual);
    if (usedManual) {
      if (fileInput) fileInput.value = '';
    } else {
      if (manual) manual.value = '';
    }

    updateProcessEnabled(popup);

    alert('Einsatzgebiet übernommen. Du kannst jetzt speichern.');
  });

  // Save Button: direkt in DB speichern
  document.addEventListener('click', async (e) => {
    const saveBtn = e.target.closest('.btn-einsatzgebiet-save');
    if (!saveBtn) return;

    const popup = getPopupFromEl(saveBtn) || getPopup();
    if (!popup) return;

    const ok = await ajaxSaveEinsatzgebiet(popup);
    if (ok) {
      alert('Einsatzgebiet gespeichert.');
    }
  });

  // Delete Button: nur lokal leeren + Hidden leeren (DB nur nach Save)
  document.addEventListener('click', (e) => {
    const delBtn = e.target.closest('.btn-einsatzgebiet-delete');
    if (!delBtn) return;

    const popup = getPopupFromEl(delBtn);
    if (!popup) return;

    if (!confirm('Einsatzgebiet wirklich löschen?')) return;

    const geoId = popup.dataset.geojsonId;
    const target = geoId ? document.getElementById(geoId) : null;
    if (target) target.value = '';

    const map = getMap(popup);
    if (map) {
      // Vector-Layer leeren
      map.getLayers().forEach(layer => {
        if (layer instanceof ol.layer.Vector) {
          const src = layer.getSource();
          if (src) src.clear();
        }
      });
      requestAnimationFrame(() => map.updateSize());
    }

    const { fileInput, manual } = getEls(popup);
    if (fileInput) fileInput.value = '';
    if (manual) manual.value = '';

    updateProcessEnabled(popup);
  });

  // Close Buttons: Popup + Overlay schließen
  document.addEventListener('click', (e) => {
    const closeBtn = e.target.closest('.btn-einsatzgebiet-close');
    if (!closeBtn) return;

    const popup = getPopupFromEl(closeBtn);
    if (!popup) return;

    popup.style.display = 'none';

    const overlay = document.getElementById('popup-overlay');
    if (overlay) overlay.style.display = 'none';
  });

  // Initial state: bei DOM ready + beim Öffnen einmal aktualisieren
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.einsatzgebiet-popup').forEach(popup => {
      updateProcessEnabled(popup);
    });
  });

  document.addEventListener('click', (e) => {
    const openBtn = e.target.closest('.open-einsatzgebiet-editor');
    if (!openBtn) return;

    const popup = getPopup();
    if (!popup) return;

    // Overlay an (falls vorhanden)
    const overlay = document.getElementById('popup-overlay');
    if (overlay) overlay.style.display = 'block';

    // Popup wird i.d.R. an anderer Stelle angezeigt, aber wir rechnen den Status sauber nach
    setTimeout(() => updateProcessEnabled(popup), 0);
  });
})();
