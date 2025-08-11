
console.log('[neben] ready')
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

  if (idField) idField.value = String(id);

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
    egBtn.disabled = false;
    egBtn.setAttribute('data-leitstelle-id', String(id));
    egBtn.setAttribute('data-map-id', 'einsatzgebiet_' + String(id));
    egBtn.setAttribute('data-center', gps || '');
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

if (typeof window.editNebenstelle !== 'function') {
  window.editNebenstelle = window.editNebenstelle;
}
function editNebenstelle(id, name, zust, einwohner, flaeche, gps, nachbar, geojson) {
  return window.editNebenstelle(id, name, zust, einwohner, flaeche, gps, nachbar, geojson);
}





window.closeNebenstellePvopup = function () {
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
    const feats = new ol.format.GeoJSON().readFeatures(obj, {
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
if (feld && feld.closest) {
    feld.closest('tr').style.display = 'none';
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

        fetch(lstNebenstellenAjax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'lsttraining_copy_leitstelle',
                    wpnonce: lstNebenstellenAjax.nonce_copy,
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
            btnCalc.addEventListener('click', () => {
                // 1) Quelle: das Einsatzgebiet aus nebenPolygonSource
                const source = window.nebenPolygonSource;
                if (!source) {
                    return alert('Kein Einsatzgebiet geladen!');
                }
                const features = source.getFeatures();
                if (!features.length) {
                    return alert('Einsatzgebiet ist leer!');
                }

                // 2) in GeoJSON umwandeln
                const geojsonObj = new ol.format.GeoJSON().writeFeaturesObject(features, {
                    featureProjection: 'EPSG:3857',
                    dataProjection: 'EPSG:4326'
                });

                // 3) Fläche mit Turf berechnen (m² → km²)
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
  if (egBtn) egBtn.disabled = true; // erst nach echtem INSERT

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
          if (newId && idField) idField.value = String(newId);

          if (egBtn) {
            egBtn.disabled = false;
            egBtn.setAttribute('data-leitstelle-id', newId);
            egBtn.setAttribute('data-map-id', 'einsatzgebiet_' + newId);
            const gpsVal = document.getElementById('neben_update_gps')?.value?.trim() || '';
            egBtn.setAttribute('data-center', gpsVal);
          }

          this.textContent = 'Speichern'; // ab jetzt UPDATE
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




// Hook up the create button
document.addEventListener('DOMContentLoaded', function () {
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
      egBtn.setAttribute('data-center', (gpsInput.value || '').trim());
    });
  }
});


})();