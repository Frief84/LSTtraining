
/* ---------------------------------------------------------- */
/* Open polygon editor popup                                  */
/* ---------------------------------------------------------- */
function normalizeNebenGeoJSON(rawGeo) {
  try {
    const parsed = typeof rawGeo === 'string' ? JSON.parse(rawGeo || '[]') : rawGeo;
    if (parsed?.type === 'FeatureCollection' && Array.isArray(parsed.features)) {
      return parsed;
    }
    if (parsed?.type === 'Feature') {
      return { type: 'FeatureCollection', features: [parsed] };
    }
    if (Array.isArray(parsed)) {
      return { type: 'FeatureCollection', features: parsed };
    }
  } catch (_) {}

  return { type: 'FeatureCollection', features: [] };
}

function cleanupNebenEinsatzgebietPopup(nextMapId) {
  document.querySelectorAll('body > textarea.einsatzgebiet-geojson-hidden').forEach((field) => field.remove());

  document.querySelectorAll('.einsatzgebiet-popup').forEach((popup) => {
    if (popup.closest('#edit-leitstelle-formular')) return;

    const oldMapId = popup.dataset.mapId;
    if (oldMapId && window._openlayersMaps?.[oldMapId]) {
      try { window._openlayersMaps[oldMapId].setTarget(null); } catch (_) {}
      delete window._openlayersMaps[oldMapId];
    }
    popup.remove();
  });

  if (nextMapId && window._openlayersMaps?.[nextMapId]) {
    try { window._openlayersMaps[nextMapId].setTarget(null); } catch (_) {}
    delete window._openlayersMaps[nextMapId];
  }
}

function syncNebenEinsatzgebietButton(btn, id, gps) {
  if (!btn) return;
  const nebenId = String(id || '').trim();

  btn.dataset.context = 'neben';
  btn.dataset.leitstelleId = nebenId || '0';
  btn.dataset.mapId = nebenId ? `einsatzgebiet_${nebenId}` : 'einsatzgebiet_edit';
  btn.dataset.center = (gps || document.getElementById('neben_update_gps')?.value || '').trim();
  btn.disabled = !(/^\d+$/.test(nebenId) && nebenId !== '0');
  btn.title = btn.disabled ? 'Bitte zuerst speichern' : '';
}

function nebenHasEinsatzgebiet() {
  const geoStr = (document.getElementById('geojson_edit')?.value || '').trim();
  if (geoStr && geoStr !== '[]') {
    try {
      const geo = JSON.parse(geoStr);
      if (Array.isArray(geo)) return geo.length > 0;
      if (geo?.type === 'FeatureCollection') return Array.isArray(geo.features) && geo.features.length > 0;
      if (geo?.type === 'Feature') return !!geo.geometry;
      if (geo?.type && (geo.coordinates || geo.geometries)) return true;
    } catch (_) {}
  }

  return !!(window.nebenPolygonSource && window.nebenPolygonSource.getFeatures().length > 0);
}

function syncNebenFlaecheButton() {
  const btn = document.getElementById('calc-flaeche');
  if (!btn) return;

  const hasGeo = nebenHasEinsatzgebiet();
  btn.disabled = !hasGeo;
  btn.title = hasGeo ? '' : 'Bitte zuerst ein Einsatzgebiet speichern';
}

function syncNebenZuordnungButton() {
  const btn = document.getElementById('btn-open-zuordnung-neben');
  const idField = document.getElementById('neben_update_id');
  syncNebenFlaecheButton();
  if (!btn || !idField) return;

  const id = (idField.value || '').trim();
  const hasId = (/^\d+$/).test(id) && id !== '0';
  const hasGeo = nebenHasEinsatzgebiet();

  btn.disabled = !(hasId && hasGeo);
  btn.title = !hasId
    ? 'Bitte zuerst speichern'
    : (!hasGeo ? 'Bitte zuerst ein Einsatzgebiet speichern' : '');
}

document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('.open-einsatzgebiet-editor');
  if (!btn) return;
  if ((btn.dataset.context || '') !== 'neben') return;

  ev.preventDefault();

  const leitstelleId = (btn.dataset.leitstelleId || document.getElementById('neben_update_id')?.value || '').trim();
  if (!(/^\d+$/.test(leitstelleId) && leitstelleId !== '0')) {
    alert('Bitte speichere die Nebenstelle zuerst, damit ein Einsatzgebiet zugeordnet werden kann.');
    return;
  }

  const mapId = `einsatzgebiet_${leitstelleId}`;
  const geoField = document.getElementById('geojson_edit');
  const rawGeo = geoField?.value || btn.dataset.geojson || '[]';
  const poly = normalizeNebenGeoJSON(rawGeo);

  syncNebenEinsatzgebietButton(btn, leitstelleId, btn.dataset.center || '');
  cleanupNebenEinsatzgebietPopup(mapId);
  if (geoField) geoField.value = JSON.stringify(poly);
  syncNebenZuordnungButton();

  // Anstatt loadPolygon(…) direkt den Popup-HTML anfordern
  const qs = new URLSearchParams({
    action        : 'lsttraining_render_einsatzgebiet_editor',
    map_id        : mapId,
    input_id      : 'geojson_edit',
    leitstelle_id: leitstelleId,
    context       : 'neben',
    center        : btn.dataset.center || ''
  });

  fetch(`${ajaxurl}?${qs.toString()}`)
    .then(r => r.text())
    .then(html => {
      // Popup aus dem Server-HTML erzeugen
      const tmp = document.createElement('div');
      tmp.innerHTML = html.trim();
      const popup = tmp.querySelector('.einsatzgebiet-popup');
      if (!popup) {
        throw new Error('Einsatzgebiet-Popup im AJAX-HTML nicht gefunden.');
      }

      popup.dataset.mapId = mapId;
      popup.dataset.geojsonId = 'geojson_edit';
      popup.dataset.leitstelleId = leitstelleId;
      popup.dataset.context = 'neben';
      popup.dataset.center = btn.dataset.center || '';

      const mapDiv = popup.querySelector('[data-einsatzgebiet-map]');
      if (mapDiv) mapDiv.id = mapId;

      document.body.appendChild(popup);

      // GeoJSON injizieren
      if (geoField) geoField.value = JSON.stringify(poly);

      popup.style.display = 'block';
      const overlay = document.getElementById('popup-overlay');
      if (overlay) overlay.style.display = 'block';

      // Editor initialisieren (funktioniert jetzt auch ohne initiales Polygon)
      if (typeof window.initEinsatzgebietEditor === 'function') {
        window.initEinsatzgebietEditor(popup);
      }
	  
// nach initEinsatzgebietEditor(popup)
requestAnimationFrame(() => {
  const map    = window._openlayersMaps?.[mapId];
  const srcTo  = popup._egVectorSource;
  const srcFrom = window.nebenPolygonSource; // kommt aus der Nebenstellen-Karte

  if (!map || !srcTo || !srcFrom) return;

  const feats = srcFrom.getFeatures().map(f => f.clone());
  srcTo.clear();
  srcTo.addFeatures(feats);

  const ext = srcTo.getExtent();
  if (!ol.extent.isEmpty(ext)) {
    map.getView().fit(ext, { padding: [50,50,50,50], duration: 200 });
  }

  // Hidden-Feld synchron halten (für Save)
  const fmt = new ol.format.GeoJSON();
  const geo = fmt.writeFeatures(feats, {
    dataProjection: 'EPSG:4326',
    featureProjection: map.getView().getProjection()
  });
  if (geoField) geoField.value = geo;
});

	  
    })
    .catch(err => {
      console.error('Fehler beim Laden des Einsatzgebiet-Editors:', err);
      // Fallback: wenn das Popup mal nicht vom Server kommt, können wir es hier alternativ bauen
    });
});




window.initNebenstelleMap = function(gps, geojson = null, hauptLat = null, hauptLon = null) {
    // 1) Default-Zentrum
    let lat = 51.0,
        lon = 10.0;
    if (gps && gps.includes(',')) {
        const coords = gps.split(',').map(parseFloat);
        if (!isNaN(coords[0]) && !isNaN(coords[1])) {
            lat = coords[0];
            lon = coords[1];
        }
    }

    // 2) View und Basemap
    const view = new ol.View({
        center: ol.proj.fromLonLat([lon, lat]),
        zoom: 11
    });
    const baseLayer = new ol.layer.Tile({
        source: new ol.source.OSM()
    });

    // 3) Marker für Nebenstelle
    const nebenMarker = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat]))
    });
    nebenMarker.setStyle(new ol.style.Style({
        image: new ol.style.RegularShape({
            points: 30,
            radius: 8,
            fill: new ol.style.Fill({
                color: '#ff0000'
            }),
            stroke: new ol.style.Stroke({
                color: '#fff',
                width: 2
            })
        })
    }));
    const markerLayer = new ol.layer.Vector({
        source: new ol.source.Vector({
            features: [nebenMarker]
        })
    });

    // 4) Karte initialisieren
    const map = new ol.Map({
        target: 'nebenstelle_map',
        layers: [baseLayer, markerLayer],
        view: view
    });

    // 5) Klick & Drag-Interaction
    map.on('click', e => {
        const [lng, lt] = ol.proj.toLonLat(e.coordinate);
        nebenMarker.setGeometry(new ol.geom.Point(e.coordinate));
        document.getElementById('neben_update_gps').value =
            lt.toFixed(6) + ',' + lng.toFixed(6);
    });
    const modify = new ol.interaction.Modify({
        source: markerLayer.getSource()
    });
    modify.on('modifyend', e => {
        const coord = e.features.item(0).getGeometry().getCoordinates();
        const [lng, lt] = ol.proj.toLonLat(coord);
        document.getElementById('neben_update_gps').value =
            lt.toFixed(6) + ',' + lng.toFixed(6);
    });
    map.addInteraction(modify);

    // 6) GeoJSON-Polygon (Einsatzgebiet)
    window.nebenPolygonSource = null;
    if (geojson && geojson.trim() !== '') {
        try {
            let obj = JSON.parse(geojson);
            if (Array.isArray(obj)) obj = {
                type: 'FeatureCollection',
                features: obj
            };

            const format = new ol.format.GeoJSON();
            const features = format.readFeatures(obj, {
                featureProjection: map.getView().getProjection()
            });
            const polygonSource = new ol.source.Vector({
                features
            });
            window.nebenPolygonSource = polygonSource;

            const polygonLayer = new ol.layer.Vector({
                source: polygonSource,
                style: new ol.style.Style({
                    stroke: new ol.style.Stroke({
                        color: 'rgba(0,128,255,0.8)',
                        width: 2
                    }),
                    fill: new ol.style.Fill({
                        color: 'rgba(0,128,255,0.2)'
                    })
                })
            });
            map.addLayer(polygonLayer);

            // **Fit to extent** des Polygons
            const extent = polygonSource.getExtent();
            if (!ol.extent.isEmpty(extent)) {
                map.getView().fit(extent, {
                    padding: [20, 20, 20, 20],
                    duration: 500
                });
            }
        } catch (err) {
            console.warn('Ungültiges GeoJSON für Nebenstelle', err);
        }
    }

    // 7) Leitstellen-Marker (optional)
    if (hauptLat && hauptLon) {
        const coord = ol.proj.fromLonLat([parseFloat(hauptLon), parseFloat(hauptLat)]);
        const leitFeature = new ol.Feature({
            geometry: new ol.geom.Point(coord)
        });
        leitFeature.setStyle(new ol.style.Style({
            image: new ol.style.Circle({
                radius: 6,
                fill: new ol.style.Fill({
                    color: 'blue'
                }),
                stroke: new ol.style.Stroke({
                    color: '#fff',
                    width: 1
                })
            })
        }));
        const leitLayer = new ol.layer.Vector({
            source: new ol.source.Vector({
                features: [leitFeature]
            })
        });
        map.addLayer(leitLayer);
    }

    // 8) Globals setzen
    window.nebenstelleMap = map;
    window.nebenMarkerFeature = nebenMarker;
	window._openlayersMaps = window._openlayersMaps || {};
  window._openlayersMaps['nebenstelle_map'] = map;
};


document.getElementById('neben_update_gps')?.addEventListener('blur', function() {
    const val = this.value.trim();
    if (!val.includes(',')) return;
    const [lat, lon] = val.split(',').map(x => parseFloat(x));
    if (isNaN(lat) || isNaN(lon)) return;
    const coord = ol.proj.fromLonLat([lon, lat]);
    if (window.nebenstelleMap && window.nebenMarkerFeature) {
        window.nebenMarkerFeature.setGeometry(new ol.geom.Point(coord));
        window.nebenstelleMap.getView().setCenter(coord);
    }
});

// Öffnet Editor mit vorhandenen Daten; EG-Button aktiv; Speichern = UPDATE
// Hilfsfunktionen (einmalig definieren, wenn noch nicht vorhanden)
function setVal(id, val) {
  var el = document.getElementById(id);
  if (el) el.value = val;
}
function getTrim(id) {
  var el = document.getElementById(id);
  return el ? el.value.trim() : '';
}


window.loadNebenstelleAndOpen = async function (id, name, zust, einwohner, flaeche, gps, nachbar) {
  let geoStr = '[]';
  try {
    const url  = `${ajaxurl}?action=lsttraining_get_neben_einsatzgebiet&neben_id=${encodeURIComponent(id)}&t=${Date.now()}`;
    const res  = await fetch(url, { credentials: 'same-origin' });
    const json = await res.json();
if (json && json.success && json.data) {
      let data = json.data;
      if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch(e) { data = null; }
      }
      if (Array.isArray(data)) data = { type: 'FeatureCollection', features: data };
      if (!data.type && data.features) data.type = 'FeatureCollection';
      geoStr = JSON.stringify(data);
    }
  } catch (_) {}

  return window.editNebenstelle(id, name, zust, einwohner, flaeche, gps, nachbar, geoStr);
};


window.editNebenstelle = function (id, name, zust, einwohner, flaeche, gps, nachbar, geojson) {
  var overlay  = document.getElementById('popup-overlay');
  var formBox  = document.getElementById('edit-nebenstelle-formular');
  var saveBtn  = document.getElementById('nebenstelle-save-button');
  var idField  = document.getElementById('neben_update_id');
  var egBtn    = formBox ? formBox.querySelector('.open-einsatzgebiet-editor') : null;
  var geoField = document.getElementById('geojson_edit');

  // Alte Handler lösen
  if (saveBtn) saveBtn.onclick = null;

  // Felder befüllen
  setVal('neben_update_name',            name || '');
  setVal('neben_update_zustandigkeit',   zust || '');
  setVal('neben_update_einwohner',       (einwohner != null ? String(einwohner) : ''));
  setVal('neben_update_flaeche',         (flaeche   != null ? String(flaeche)   : ''));
  setVal('neben_update_gps',             gps || '');
  setVal('neben_update_nachbar',         (nachbar != null ? String(nachbar) : ''));

  // GeoJSON als String abspeichern
  var geoStr = (typeof geojson === 'string')
    ? (geojson && geojson.length ? geojson : '[]')
    : JSON.stringify(geojson || []);
  if (geoField) geoField.value = geoStr;
  syncNebenZuordnungButton();

  if (idField) {
  idField.value = String(id);
  idField.dispatchEvent(new Event('input'));
}

  // Titel
  if (formBox) {
    var heading = formBox.querySelector('h2');
    if (heading) heading.textContent = 'Nebenleitstelle bearbeiten';
  }

  // Buttons
  if (saveBtn) {
    saveBtn.textContent = 'Speichern';
    saveBtn.disabled = false;
    // gewünschte ID wird beim Bearbeiten nicht verwendet
    saveBtn.dataset.desiredId = '';
  }

  // EG-Button aktivieren + Daten setzen
  if (egBtn) {
    syncNebenEinsatzgebietButton(egBtn, id, gps || '');
    egBtn.dataset.geojson = geoStr;
  }

  // Modal öffnen
  if (overlay) overlay.style.display = 'block';
  if (formBox)  formBox.style.display  = 'block';

  // Karte (vorherige Instanz aufräumen)
  var mapContainer = document.getElementById('nebenstelle_map');
  if (mapContainer) mapContainer.innerHTML = '';
  if (window.nebenstelleMap) {
    window.nebenstelleMap.setTarget(null);
    window.nebenstelleMap = null;
  }
  // Initialisierung mit vorhandenen Werten
  if (typeof window.initNebenstelleMap === 'function') {
    window.initNebenstelleMap(gps || '', geoStr);
  }
  syncNebenZuordnungButton();

  // Speichern = UPDATE
  if (saveBtn) {
    saveBtn.onclick = function (e) {
      e.preventDefault();

      var nameNow = getTrim('neben_update_name');
      if (!nameNow) { alert('Bitte einen Namen vergeben.'); return; }

      var fd = new FormData();
      fd.append('action', 'lsttraining_save_nebenleitstelle');
      if (window.LSTTRAINING && window.LSTTRAINING.nonce_nebenstellen) {
        fd.append('_ajax_nonce', window.LSTTRAINING.nonce_nebenstellen);
      }
      fd.append('id', String(id));
      fd.append('name', nameNow);
      fd.append('zustandigkeit', getTrim('neben_update_zustandigkeit'));
      fd.append('einwohner',     getTrim('neben_update_einwohner') || '0');
      fd.append('flaeche',       getTrim('neben_update_flaeche')   || '0');
      fd.append('gps',           getTrim('neben_update_gps'));

      saveBtn.disabled = true;
      fetch(ajaxurl, { method: 'POST', body: fd })
        .then(function(res){ return res.json(); })
        .then(function(json){
          if (!json || !json.success) {
            var msg = (json && json.data && (json.data.msg || json.data)) || 'Speichern fehlgeschlagen.';
            alert(msg);
            return;
          }
          
		  // Erfolgreich – Modal schließen
    		if (overlay) overlay.style.display = 'none';
    		if (formBox) formBox.style.display = 'none';
		  
          if (typeof window.refreshNebenstellenListe === 'function') {
            window.refreshNebenstellenListe();
          }
        })
        .catch(function(err){
          console.error('RAW response?', err);
          alert('Speichern fehlgeschlagen (Antwort war kein gültiges JSON).');
        })
        .finally(function(){
          saveBtn.disabled = false;
        });
    };
  }
};

window.closeNebenstellePopup = function () {
  // 1) Popup schließen
  document.getElementById('popup-overlay')?.style.setProperty('display', 'none');
  document.getElementById('edit-nebenstelle-formular')?.style.setProperty('display', 'none');

  // 2) Karte updaten: GeoJSON aus dem Hidden-Feld holen
  const geoStr = document.getElementById('geojson_edit')?.value || '[]';
  const gps    = document.getElementById('neben_update_gps')?.value || '';

  // 3) Falls Map schon existiert, Features ersetzen und zoomen
  const map    = window.nebenstelleMap;
  const src    = window.nebenPolygonSource;
  if (map && src) {
    // a) GeoJSON parsen
    let obj;
    try {
      obj = JSON.parse(geoStr);
      if (Array.isArray(obj)) obj = { type:'FeatureCollection', features: obj };
    } catch (e) {
      console.warn('Ungültiges GeoJSON beim Popup-Close:', e);
      return;
    }

    // b) Neue Features laden
    const fmt = new ol.format.GeoJSON();
    const feats = fmt.readFeatures(obj, {
      dataProjection: 'EPSG:4326',
      featureProjection: map.getView().getProjection()
    });
	src.clear();
	src.addFeatures(feats);

    // c) Auf Polygon-Extent zoomen
    const ext = src.getExtent();
    if (!ol.extent.isEmpty(ext)) {
      map.getView().fit(ext, { padding:[20,20,20,20], duration:300 });
    }
  }
  // 4) Falls Map noch nicht existiert oder komplett neu ziehen willst:
  else {
    window.initNebenstelleMap(gps, geoStr);
  }
};


// Nachbar-Feld ausblenden (optional)
const feld = document.getElementById('neben_update_nachbar');
const feldRow = feld && feld.closest ? feld.closest('tr') : null;
if (feldRow) {
    feldRow.style.display = 'none';
}

;
function initNebenstellenFilter() {
    const input = document.getElementById('nebenstellen-search');
    // erst gezielt versuchen, dann Fallback
    const tbody = document.querySelector('#nebenstellen-table tbody') || document.querySelector('.widefat tbody');
    if (!input || !tbody) return;

    // mehrfaches Binden vermeiden
    if (input._lstFilterBound) return;
    input._lstFilterBound = true;

    input.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();
        tbody.querySelectorAll('tr').forEach(row => {
            const hay = (row.dataset.search || row.innerText).toLowerCase();
            row.style.display = term === '' || hay.includes(term) ? '' : 'none';
        });
    });
}

document.addEventListener('DOMContentLoaded', initNebenstellenFilter);


(function() {
    // 1) DOM-Elemente referenzieren
    const modal = document.getElementById('copy-leit-modal');
    const btnCancel = document.getElementById('cancel-copy-leit');
    const btnConfirm = document.getElementById('confirm-copy-leit');
    const inputSearch = document.getElementById('copy_ls_search');
    const selectLS = document.getElementById('copy_ls_select');
    const hiddenId = document.getElementById('neben_update_id');

    // 2) Daten & Default-Option
    const allLS = lstNebenstellenAjax.allLeitstellen || [];
    const defaultOpt = '<option value="0">– bitte wählen –</option>';

    // 3) Funktion zum Aufbau des Dropdowns
    function buildOptions(term = '') {
        const lower = term.trim().toLowerCase();
        const filtered = allLS.filter(ls =>
            ls.name.toLowerCase().includes(lower)
        );
        const opts = [
            defaultOpt,
            ...filtered.map(ls =>
                `<option value="${ls.id}">${ls.name}</option>`
            )
        ];
        selectLS.innerHTML = opts.join('');
    }

    // 4) Dropdown initial befüllen & Live-Filter setzen
    buildOptions();
    inputSearch.addEventListener('input', e => buildOptions(e.target.value));

    // 5) Öffnen des Copy-Modals per Button
    document.querySelectorAll('.open-copy-leit-modal').forEach(openBtn => {
        openBtn.addEventListener('click', () => {
            // buildOptions() erneut aufrufen, damit das Dropdown aktuell ist
            buildOptions();
            btnConfirm.disabled = true;
            modal.classList.remove('hidden');
            inputSearch.focus();
        });
    });

    // 6) Abbrechen-Button
    btnCancel.addEventListener('click', () => {
        modal.classList.add('hidden');
    });

    // 7) Confirm-Knopf aktivieren, sobald eine Leitstelle ausgewählt wurde
    selectLS.addEventListener('change', () => {
        btnConfirm.disabled = (selectLS.value === '0');
    });

    btnConfirm.addEventListener('click', () => {
        const nebenId = document.getElementById('neben_update_id').value;
        const leitId = selectLS.value;
        if (leitId === '0') return;
        const sourceName = selectLS.options[selectLS.selectedIndex]?.textContent?.trim() || 'die gewählte Leitstelle';
        const targetName = document.getElementById('neben_update_name')?.value?.trim() || 'diese Nebenstelle';
        const confirmed = window.confirm(
            'Möchtest du "' + sourceName + '" wirklich in die Nebenstelle "' + targetName + '" übernehmen?\n\n' +
            'Standort, Einsatzgebiet und Wachen werden übernommen und in der Simulation für die Nebenstelle verwendet.'
        );
        if (!confirmed) return;

        fetch(lstNebenstellenAjax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'lsttraining_copy_leitstelle',
                    _wpnonce: lstNebenstellenAjax.nonce_copy,
                    neben_id: nebenId,
                    leit_id: leitId,
                })
            })
            .then(response => {
                if (!response.ok) throw new Error('Server-Fehler ' + response.status);
                return response.json();
            })
            .then(json => {
                if (!json.success) {
                    // wenn PHP einen Fehler gesendet hat
                    throw new Error(json.data || 'Unbekannter Fehler');
                }
                // ---- NEUER BLOCK: Erfolg anzeigen ----
                alert(json.data || 'Nebenstelle übernommen');
                console.log('AJAX Success:', json);
                // --------------------------------------
                modal.classList.add('hidden');
                if (typeof fetchNebenstellen === 'function') fetchNebenstellen();
            })
            .catch(err => {
                console.error('AJAX Error:', err);
                alert('Konnte nicht übernehmen: ' + err.message);
            });
    });

    // Fläche berechnen, sobald der Button existiert
    document.addEventListener('DOMContentLoaded', () => {
        const btnCalc = document.getElementById('calc-flaeche');
        const inputFlaeche = document.getElementById('neben_update_flaeche');

        if (btnCalc && inputFlaeche) {
            syncNebenFlaecheButton();
            btnCalc.addEventListener('click', () => {
                let geojsonObj = null;

                // 1) Bevorzugte Quelle: die sichtbare Nebenstellen-Karte
                const source = window.nebenPolygonSource;
                const features = source ? source.getFeatures() : [];

                if (features.length) {
                    geojsonObj = new ol.format.GeoJSON().writeFeaturesObject(features, {
                        featureProjection: 'EPSG:3857',
                        dataProjection: 'EPSG:4326'
                    });
                } else {
                    // 2) Fallback: gespeichertes Einsatzgebiet aus dem Hidden-Feld
                    const geoStr = (document.getElementById('geojson_edit')?.value || '').trim();
                    if (geoStr) {
                        try {
                            geojsonObj = JSON.parse(geoStr);
                            if (Array.isArray(geojsonObj)) {
                                geojsonObj = { type: 'FeatureCollection', features: geojsonObj };
                            }
                            if (geojsonObj?.type === 'Feature') {
                                geojsonObj = { type: 'FeatureCollection', features: [geojsonObj] };
                            }
                        } catch (_) {
                            geojsonObj = null;
                        }
                    }
                }

                if (!geojsonObj || (geojsonObj.type === 'FeatureCollection' && !geojsonObj.features?.length)) {
                    syncNebenFlaecheButton();
                    return alert('Einsatzgebiet ist leer!');
                }

                // 3) Fläche mit Turf berechnen (m2 -> km2)
                const areaM2 = turf.area(geojsonObj);
                const areaKm2 = (areaM2 / 1e6).toFixed(2);

                // 4) ins Input schreiben
                inputFlaeche.value = areaKm2;
            });
        }
    });

	document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-delete-nebenstelle').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const id = btn.dataset.id;
      if (!id || !confirm('Nebenstelle wirklich löschen?')) return;

      fetch(lstNebenstellenAjax.ajax_url, {
        method:      'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({
          action:  'lsttraining_delete_nebenstelle',
          _wpnonce: lstNebenstellenAjax.nonce_delete,
          id:       id
        })
      })
      .then(r => r.json())
      .then(json => {
        if (!json.success) {
          throw new Error(json.data || 'Fehler beim Löschen');
        }
        // Zeile aus Tabelle entfernen
        const row = btn.closest('tr');
        if (row) row.remove();
      })
      .catch(err => {
        console.error(err);
        alert('Konnte nicht löschen: ' + err.message);
      });
    });
  });
});

// Öffnet nur den Editor im Create-Modus; kein Server-Call hier.
window.createNebenstelle = function () {
  const overlay   = document.getElementById('popup-overlay');
  const formBox   = document.getElementById('edit-nebenstelle-formular');
  const saveBtn   = document.getElementById('nebenstelle-save-button');
  const egBtn     = formBox.querySelector('.open-einsatzgebiet-editor');
  const idField   = document.getElementById('neben_update_id');
  const geoField  = document.getElementById('geojson_edit');
  const createBtn = document.getElementById('create-nebenstelle');

  if (saveBtn) saveBtn.onclick = null;

  // Felder leeren
  ['neben_update_name','neben_update_zustandigkeit','neben_update_einwohner','neben_update_flaeche','neben_update_gps','neben_update_nachbar']
    .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  if (geoField) geoField.value = '[]';
  syncNebenZuordnungButton();

  // Titel & ID
  const heading = formBox.querySelector('h2');
  if (heading) heading.textContent = 'Nebenleitstelle erstellen';
  if (idField) idField.value = ''; // noch keine ID

  // gewünschte ID (nur merken, nicht speichern)
  const desiredId = createBtn ? (createBtn.getAttribute('data-next-id') || '') : '';
  if (saveBtn) saveBtn.dataset.desiredId = desiredId;

  // Buttons
  if (saveBtn) {
    saveBtn.textContent = 'Speichern um Einsatzgebiet anzulegen';
    saveBtn.disabled = false;
  }
  syncNebenEinsatzgebietButton(egBtn, '', '');

  // Modal öffnen
  if (overlay) overlay.style.display = 'block';
  if (formBox)  formBox.style.display  = 'block';

  // leere Karte
  const mapContainer = document.getElementById('nebenstelle_map');
  if (mapContainer) mapContainer.innerHTML = '';
  if (window.nebenstelleMap) { window.nebenstelleMap.setTarget(null); window.nebenstelleMap = null; }
  window.initNebenstelleMap('', '[]');

  // Save-Handler: 1. Klick = INSERT, weitere Klicks = UPDATE
  if (saveBtn) {
    saveBtn.onclick = async function (e) {
      e.preventDefault();

      const nebenId = (document.getElementById('neben_update_id')?.value || '').trim();
      const name    = (document.getElementById('neben_update_name')?.value || '').trim();
      if (!name) { alert('Bitte einen Namen vergeben.'); return; }

      const fd = new FormData();
      fd.append('action', 'lsttraining_save_nebenleitstelle');
      if (window.LSTTRAINING?.nonce_nebenstellen) {
        fd.append('_ajax_nonce', window.LSTTRAINING.nonce_nebenstellen);
      }
      fd.append('id', nebenId); // leer => INSERT
      fd.append('name', name);
      fd.append('zustandigkeit', (document.getElementById('neben_update_zustandigkeit')?.value || '').trim());
      fd.append('einwohner', (document.getElementById('neben_update_einwohner')?.value || '0').trim());
      fd.append('flaeche', (document.getElementById('neben_update_flaeche')?.value || '0').trim());
      fd.append('gps', (document.getElementById('neben_update_gps')?.value || '').trim());

      if (!nebenId && this.dataset.desiredId) {
        fd.append('desired_id', this.dataset.desiredId);
      }

      this.disabled = true;
      try {
        const res  = await fetch(ajaxurl, { method: 'POST', body: fd });
        const json = await res.json();
        if (!json || !json.success) { alert(json?.data?.msg || json?.data || 'Speichern fehlgeschlagen.'); return; }

        // INSERT → echte ID setzen, EG aktivieren, Label wechseln, Modal offen lassen
        if (!nebenId) {
          const newId = json.data?.id;
if (newId && idField) {
  idField.value = String(newId);
  idField.dispatchEvent(new Event('input'));
}

          if (egBtn) {
            const gpsVal = document.getElementById('neben_update_gps')?.value?.trim() || '';
            syncNebenEinsatzgebietButton(egBtn, newId, gpsVal);
          }

          this.textContent = 'Speichern'; // ab jetzt UPDATE
          syncNebenZuordnungButton();
          return; // Modal bleibt offen
        }

        // UPDATE → Modal bleibt offen
        // optional: kleine Erfolgsmeldung einblenden

      } catch (err) {
        console.error('RAW response?', err);
        alert('Speichern fehlgeschlagen (Antwort war kein gültiges JSON).');
      } finally {
        this.disabled = false;
      }
    };
  }
};

function wireZuordnungButtonNeben() {
  var btn     = document.getElementById('btn-open-zuordnung-neben');
  var idField = document.getElementById('neben_update_id');
  if (!btn || !idField) return;

  // Mehrfach-Bindung verhindern
  if (btn._zuoBound) return;
  btn._zuoBound = true;

  function getValidId() {
    var v = (idField.value || '').trim();
    return (/^\d+$/).test(v) && v !== '0' ? v : null;
  }

  function syncBtn() {
    syncNebenZuordnungButton();
  }

  btn.addEventListener('click', function (e) {
    e.preventDefault();
    var id = getValidId();
    if (!id || !nebenHasEinsatzgebiet()) {
      syncNebenZuordnungButton();
      return;
    }
    // vorausgesetzt: zuordnung_modal.js ist enqueued und exportiert window.openZuordnungPopup
    window.openZuordnungPopup({ entityType: 'nebenleitstelle', entityId: id });
  });

  // bei ID-Änderungen Button-State aktualisieren
  idField.addEventListener('input',  syncBtn);
  idField.addEventListener('change', syncBtn);

  // initialer Zustand
  syncBtn();
}



// Hook up the create button
document.addEventListener('DOMContentLoaded', function () {
 wireZuordnungButtonNeben();											
											
  const createBtn = document.getElementById('create-nebenstelle');
  if (createBtn) {
    createBtn.addEventListener('click', function (e) {
      e.preventDefault();
      window.createNebenstelle();
    });
  }

  // Wenn GPS-Feld sich ändert, center an den Editor-Button hängen
  const gpsInput = document.getElementById('neben_update_gps');
  const egBtn = document.querySelector('#edit-nebenstelle-formular .open-einsatzgebiet-editor');
  if (gpsInput && egBtn) {
    gpsInput.addEventListener('input', function () {
      const nebenId = document.getElementById('neben_update_id')?.value || egBtn.dataset.leitstelleId || '';
      syncNebenEinsatzgebietButton(egBtn, nebenId, (gpsInput.value || '').trim());
    });
  }
});

// Aktualisiert die Zeile der Nebenstelle in der Tabelle – oder fällt auf Fetch/Reload zurück
window.refreshNebenstellenListe = function () {
  // 1) Versuche: Falls es schon eine Fetch-Funktion gibt, nutze die (du rufst sie z.B. nach "copy").
  if (typeof window.fetchNebenstellen === 'function') {
    window.fetchNebenstellen();
    return;
  }

  // 2) Sonst: Row im DOM direkt updaten aus den aktuellen Formularwerten
  const id    = (document.getElementById('neben_update_id')?.value || '').trim();
  const name  = (document.getElementById('neben_update_name')?.value || '').trim();
  const zust  = (document.getElementById('neben_update_zustandigkeit')?.value || '').trim();
  const einw  = (document.getElementById('neben_update_einwohner')?.value || '').trim();
  const flae  = (document.getElementById('neben_update_flaeche')?.value || '').trim();
  const gps   = (document.getElementById('neben_update_gps')?.value || '').trim();

  if (!id) return;

  // versuche übliche Datenattribute
  let row = document.querySelector(`#nebenstellen-table tr[data-id="${id}"]`)
        || document.querySelector(`#nebenstellen-table tr[data-neben-id="${id}"]`)
        || document.querySelector(`tr[data-id="${id}"]`);

  if (!row) {
    // 3) Wenn keine Zeile gefunden wurde, als Fallback komplette Liste neu laden
    if (typeof window.fetchNebenstellen === 'function') {
      window.fetchNebenstellen();
    } else {
      // letzter Fallback: Reload
      location.reload();
    }
    return;
  }

  // 4) Zellen anpassen – ggf. Indizes an deine Spaltenreihenfolge anpassen!
  const tds = row.querySelectorAll('td');

  // Beispiel: [0]=ID, [1]=Name, [2]=Zuständigkeit, [3]=Einwohner, [4]=Fläche, [5]=GPS
  // Passe die Indizes an deine tatsächliche Tabelle an:
  if (tds[1]) tds[1].textContent = name;
  if (tds[2]) tds[2].textContent = zust;
  if (tds[3]) tds[3].textContent = einw;
  if (tds[4]) tds[4].textContent = flae;
  if (tds[5]) tds[5].textContent = gps;

  // 5) Für die Suchfunktion den zusammengesetzten Text aktualisieren
  const search = [id, name, zust, einw, flae, gps].filter(Boolean).join(' ').toLowerCase();
  row.dataset.search = search;
};

// Aktualisiert die Nebenstellen-Karte (#nebenstelle_map) aus einem GeoJSON-String (EPSG:4326)
window.updateNebenstellenMapFromGeo = function(geoStr) {
  try {
    if (!window.nebenstelleMap) return; // Karte noch nicht offen
    let obj = geoStr ? JSON.parse(geoStr) : null;
    if (!obj) return;

    // immer FeatureCollection herstellen
    if (Array.isArray(obj)) obj = { type:'FeatureCollection', features: obj };
    if (obj.type === 'Feature') obj = { type:'FeatureCollection', features:[obj] };

    const fmt  = new ol.format.GeoJSON();
    const feats = fmt.readFeatures(obj, {
      dataProjection: 'EPSG:4326',
      featureProjection: window.nebenstelleMap.getView().getProjection()
    });

    // Source vorhanden? sonst neu anlegen + Layer hinzufügen
    if (!window.nebenPolygonSource) {
      window.nebenPolygonSource = new ol.source.Vector();
      const polygonLayer = new ol.layer.Vector({
        source: window.nebenPolygonSource,
        style: new ol.style.Style({
          stroke: new ol.style.Stroke({ color:'rgba(0,128,255,0.8)', width:2 }),
          fill:   new ol.style.Fill({   color:'rgba(0,128,255,0.2)' })
        })
      });
      window.nebenstelleMap.addLayer(polygonLayer);
    }

    window.nebenPolygonSource.clear();
  if (feats && feats.length) {
      window.nebenPolygonSource.addFeatures(feats);
      const ext = window.nebenPolygonSource.getExtent();
      if (!ol.extent.isEmpty(ext)) {
        window.nebenstelleMap.getView().fit(ext, { padding:[20,20,20,20], duration:300 });
      }
    }
    syncNebenZuordnungButton();
  } catch (e) {
    console.warn('updateNebenstellenMapFromGeo: Parse/Update-Fehler', e, geoStr);
  }
};											

document.addEventListener('lsttraining:einsatzgebiet-saved', function (event) {
  const detail = event.detail || {};
  if (detail.context !== 'neben') return;

  const currentId = (document.getElementById('neben_update_id')?.value || '').trim();
  if (String(detail.entityId || '') !== currentId) return;

  const geojson = detail.geojson || document.getElementById('geojson_edit')?.value || '[]';
  const geoField = document.getElementById('geojson_edit');
  if (geoField) geoField.value = geojson;

  const egBtn = document.querySelector('#edit-nebenstelle-formular .open-einsatzgebiet-editor');
  if (egBtn) egBtn.dataset.geojson = geojson;

  if (typeof window.updateNebenstellenMapFromGeo === 'function') {
    window.updateNebenstellenMapFromGeo(geojson);
  }
  syncNebenZuordnungButton();
});

	  document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('btn-open-zuordnung-neben');
  if (!btn) return;

  function getId(){ return (document.getElementById('neben_update_id') || {}).value || ''; }
  function valid(v){ return /^\d+$/.test(v) && v !== '0'; }

  function sync(){
    syncNebenZuordnungButton();
  }

  if (!btn._bound){
    btn._bound = true;
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var id = getId();
      if (!valid(id) || !nebenHasEinsatzgebiet()) {
        syncNebenZuordnungButton();
        return;
      }
      window.openZuordnungPopup({ entityType:'nebenleitstelle', entityId:id });
    });
  }

  sync();
  var idEl = document.getElementById('neben_update_id');
  if (idEl && !idEl._obs){
    idEl._obs = true;
    idEl.addEventListener('input',  sync);
    idEl.addEventListener('change', sync);
  }
});



})();
