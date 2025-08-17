// === Inline-Zuordnungs-Popup ohne IFrame ===================================
function openZuordnungPopup(cfg) {
    var type = cfg.entityType; // 'leitstelle' | 'nebenleitstelle'
    var id = String(cfg.entityId);

    // 1) Overlay-DOM erzeugen (Single-Instance)
    var exists = document.getElementById('zuo-overlay');
    if (exists) exists.remove();

    var wrap = document.createElement('div');
    wrap.id = 'zuo-overlay';
    wrap.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99998;display:flex;align-items:center;justify-content:center;';
    wrap.innerHTML =
        '<div id="zuo-modal" style="background:#fff;max-width:1100px;width:96%;max-height:90vh;display:flex;flex-direction:column;border:1px solid #ccc;box-shadow:0 0 15px rgba(0,0,0,.35);">' +
        '<div style="display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #e5e5e5;">' +
        '<h2 style="margin:0;flex:1;">Zuordnung der Wachen bearbeiten</h2>' +
        '<button type="button" id="zuo-close" class="button">Schließen</button>' +
        '</div>' +
        '<div style="padding:10px;overflow:auto;flex:1;">' +
        '<div id="zuo-map" style="height:460px;border:1px solid #ddd;margin-bottom:10px;"></div>' +
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">' +
        '<button type="button" class="button button-primary" id="zuo-assign">Wachen im Einsatzgebiet zuordnen</button>' +
        '<button type="button" class="button" id="zuo-unassign">Alle Wachen aus dem Einsatzgebiet löschen</button>' +
        '<button type="button" class="button" id="zuo-loadview" title="Lädt Wachen im sichtbaren Kartenausschnitt">Wachen im Kartenausschnitt laden</button>' +
        '<input type="text" id="zuo-search" placeholder="Wache suchen (ID oder Name)" style="flex:0 0 260px;">' +
        '<button type="button" class="button" id="zuo-searchbtn">Suchen</button>' +
        '<span id="zuo-status" style="margin-left:auto;"></span>' +
        '</div>' +
        '<table class="widefat fixed"><thead><tr>' +
        '<th style="width:60px;">ID</th><th>Name</th><th style="width:160px;">Typ</th><th style="width:220px;">Koordinaten</th><th style="width:120px;">Status</th>' +
        '</tr></thead><tbody id="zuo-tbody"><tr><td colspan="5"><em>Lade Einsatzgebiet…</em></td></tr></tbody></table>' +
        '</div>' +
        '</div>';

    document.body.appendChild(wrap);
    document.body.style.overflow = 'hidden';

    function close() {
        document.body.style.overflow = '';
        wrap.remove();
    }
    wrap.querySelector('#zuo-close').addEventListener('click', close);
    wrap.addEventListener('click', function(e) {
        if (e.target === wrap) close();
    });

    // 2) OpenLayers initialisieren
    var map = new ol.Map({
        target: 'zuo-map',
        layers: [new ol.layer.Tile({
            source: new ol.source.OSM()
        })],
        view: new ol.View({
            center: ol.proj.fromLonLat([10, 51]),
            zoom: 6
        })
    });
    var polyLayer = new ol.layer.Vector({
        source: new ol.source.Vector()
    });
    var wachenLayer = new ol.layer.Vector({
        source: new ol.source.Vector()
    });
    map.addLayer(polyLayer);
    map.addLayer(wachenLayer);
	var wachenIndex = new Map(); // wacheId -> Feature
	
    function styleGray() {
        return new ol.style.Style({
            image: new ol.style.Circle({
                radius: 5,
                stroke: new ol.style.Stroke({
                    color: '#777',
                    width: 1
                }),
                fill: new ol.style.Fill({
                    color: 'rgba(120,120,120,0.5)'
                })
            })
        });
    }

    function styleAssigned() {
        return new ol.style.Style({
            image: new ol.style.Circle({
                radius: 6,
                stroke: new ol.style.Stroke({
                    color: '#16537e',
                    width: 1
                }),
                fill: new ol.style.Fill({
                    color: 'rgba(22,83,126,0.6)'
                })
            })
        });
    }

    function setStatus(txt) {
        var s = document.getElementById('zuo-status');
        if (s) s.textContent = txt || '';
    }

    function post(action, data) {
        data = data || {};
        data.action = action;

        // Nonce mitschicken – Namen breit abdecken
        if (window.lstZuordnungAjax && window.lstZuordnungAjax.nonce) {
            data.wpnonce = window.lstZuordnungAjax.nonce; // falls PHP 'wpnonce' prüft
            data._wpnonce = window.lstZuordnungAjax.nonce; // WP-Standard
            data.nonce = window.lstZuordnungAjax.nonce; // falls 'nonce' erwartet
        }

        // ajax_url-Fallback
        var ajaxUrl = (window.lstZuordnungAjax && window.lstZuordnungAjax.ajax_url) ||
            window.ajaxurl ||
            '/wp-admin/admin-ajax.php';

        return fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams(data).toString()
        }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }


    function loadPolygonAndWachen() {
        // 1) Lokales GeoJSON aus dem Editor (falls vorhanden)
        var override = (document.getElementById('geojson_edit')?.value || '').trim();

        // Helper: GeoJSON sicher zu Feature(s) parsen und Karte zeichnen
        function drawPolygonFromString(gjStr) {
            var fmt = new ol.format.GeoJSON();
            var src = polyLayer.getSource();
            src.clear();

            var obj;
            try {
                obj = JSON.parse(gjStr);
            } catch (e) {
                throw new Error('Ungültiges GeoJSON im Editor.');
            }

            // Immer Features erzeugen (FeatureCollection bevorzugt)
            var features;
            try {
                features = fmt.readFeatures(obj, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857'
                });
            } catch (e) {
                // Fallback: Einzel-Feature versuchen
                var f = fmt.readFeature(obj, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857'
                });
                features = f ? [f] : [];
            }

            if (!features || !features.length) {
                throw new Error('Einsatzgebiet ist leer.');
            }

            src.addFeatures(features);
            var extent = src.getExtent();
            if (!ol.extent.isEmpty(extent)) {
                map.getView().fit(extent, {
                    padding: [20, 20, 20, 20],
                    maxZoom: 14,
                    duration: 250
                });
            }

            // Rückgabe als String für nachfolgende Requests
            return JSON.stringify(obj);
        }

        

        // 2) Polygon beziehen (aus Editor oder Server) und dann Wachen abfragen
        var polygonPromise;
        if (override) {
            // Direkt lokal zeichnen; bei Fehlern auf Server-Fallback gehen
            try {
                var normalized = drawPolygonFromString(override);
                polygonPromise = Promise.resolve(normalized);
            } catch (e) {
                // Fallback: vom Server laden
                polygonPromise = post('lsttraining_get_entity_polygon', {
                        entity_type: type,
                        entity_id: id
                    })
                    .then(function(res) {
                        if (!res || !res.success || !res.data || !res.data.geojson) {
                            throw new Error((res && res.data && res.data.msg) || 'Fehler beim Laden des Einsatzgebiets');
                        }
                        return drawPolygonFromString(res.data.geojson);
                    });
            }
        } else {
            // Kein lokales GeoJSON: Server fragen
            polygonPromise = post('lsttraining_get_entity_polygon', {
                    entity_type: type,
                    entity_id: id
                })
                .then(function(res) {
                    if (!res || !res.success || !res.data || !res.data.geojson) {
                        throw new Error((res && res.data && res.data.msg) || 'Fehler beim Laden des Einsatzgebiets');
                    }
                    return drawPolygonFromString(res.data.geojson);
                });
        }

        // 3) Wachen im Polygon vom Server holen (Editor-GeoJSON als Override mitgeben)
        polygonPromise
            .then(function(gjStr) {
                return post('lsttraining_find_wachen_in_polygon', {
                    entity_type: type,
                    entity_id: id,
                    geojson: (document.getElementById('geojson_edit')?.value || '').trim() || gjStr
                });
            })
            .then(function(res2) {
                if (!res2 || !res2.success) {
                    throw new Error((res2 && res2.data && res2.data.msg) || 'Fehler beim Laden der Wachen');
                }
                renderWachen((res2.data && res2.data.wachen) ? res2.data.wachen : []);
                setStatus('');
            })
            .catch(function(err) {
                var tbody = document.getElementById('zuo-tbody');
                if (tbody) {
                    tbody.innerHTML =
                        '<tr><td colspan="5"><em>' +
                        escapeHtml(err && err.message ? err.message : String(err)) +
                        '</em></td></tr>';
                }
                setStatus('Fehler: ' + (err && err.message ? err.message : 'unbekannt'));
            });
    } // Ende loadPolygonAndWachen

    // ---- Hilfsfunktionen (werden hochgehoisted) -------------------------
    function escapeHtml(s) {
        return String(s).replace(/[&<>\"']/g, function(c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [c];
        });
    }

    function disableBtns(dis) {
        var a = document.getElementById('zuo-assign');
        var u = document.getElementById('zuo-unassign');
        if (a) a.disabled = dis;
        if (u) u.disabled = dis;
    }

    function assignAll() {
        setStatus('Bitte warten…');
        disableBtns(true);
        var payload = {
            entity_type: type,
            entity_id: id
        };
        var ov = (document.getElementById('geojson_edit')?.value || '').trim();
        if (ov) payload.geojson = ov;
        post('lsttraining_assign_wachen_in_polygon', payload)
            .then(function(r) {
                var n = (r && r.data && r.data.assigned) ? r.data.assigned : 0;
                setStatus(n + ' Wachen zugeordnet.');
                loadPolygonAndWachen();
            })
            .finally(function() {
                disableBtns(false);
            });
    }

    function unassignAll() {
        setStatus('Bitte warten…');
        disableBtns(true);
        var payload = {
            entity_type: type,
            entity_id: id
        };
        var ov = (document.getElementById('geojson_edit')?.value || '').trim();
        if (ov) payload.geojson = ov;
        post('lsttraining_unassign_wachen_in_polygon', payload)
            .then(function(r) {
                var n = (r && r.data && r.data.removed) ? r.data.removed : 0;
                setStatus(n + ' Zuordnungen gelöscht.');
                loadPolygonAndWachen();
            })
            .finally(function() {
                disableBtns(false);
            });
    }

   // ---- Buttons verdrahten + Initial-Ladung ----------------------------
var btnAssign   = document.getElementById('zuo-assign');
var btnUnassign = document.getElementById('zuo-unassign');
var btnLoadView = document.getElementById('zuo-loadview');
var btnSearch   = document.getElementById('zuo-searchbtn');
var inpSearch   = document.getElementById('zuo-search');

if (btnAssign)   btnAssign  .addEventListener('click', assignAll);
if (btnUnassign) btnUnassign.addEventListener('click', unassignAll);

// Erste Befüllung mit Polygon-Logik
loadPolygonAndWachen();

function featureStyle(assigned){ return assigned ? styleAssigned() : styleGray(); }

// Stil + Tabelle nach Toggle aktualisieren
function applyAssignmentChange(wacheId, assigned){
  var f = wachenIndex.get(wacheId);
  if (f){
    f.set('assigned', !!assigned);
    f.setStyle(featureStyle(!!assigned));
  }
  var tbody = document.getElementById('zuo-tbody');
  if (!tbody) return;
  var row = tbody.querySelector('tr[data-wid="'+wacheId+'"]');
  if (row){
    var tdStatus = row.querySelector('td[data-col="status"]');
    if (tdStatus) tdStatus.textContent = assigned ? 'zugeordnet' : 'nicht zugeordnet';
  }
}

// BBOX des sichtbaren Bereichs als lon/lat
function getViewBBoxLonLat(){
  var extent = map.getView().calculateExtent(map.getSize());
  var ll = ol.proj.toLonLat([extent[0], extent[1]]); // [lon,lat]
  var ur = ol.proj.toLonLat([extent[2], extent[3]]);
  return {
    minLon: Math.min(ll[0], ur[0]),
    minLat: Math.min(ll[1], ur[1]),
    maxLon: Math.max(ll[0], ur[0]),
    maxLat: Math.max(ll[1], ur[1])
  };
}

// Tabelle rendern (merge mit Index)
function renderWachen(list){
  var tbody = document.getElementById('zuo-tbody');
  var rows = [];
	
	  wachenLayer.getSource().clear();
  wachenIndex.clear();

  (list || []).forEach(function(w){
    var lon = parseFloat(w.longitude), lat = parseFloat(w.latitude);
    if (!isFinite(lat) || !isFinite(lon)) return;

    // robustes Assigned-Casting
    var isAssigned = (w.assigned === 1 || w.assigned === '1' || w.assigned === true);

    var f = wachenIndex.get(w.id);
    if (!f){
      f = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
        wacheId: w.id,
        kind: 'wache'
      });
      wachenLayer.getSource().addFeature(f);
      wachenIndex.set(w.id, f);
    } else {
      f.setGeometry(new ol.geom.Point(ol.proj.fromLonLat([lon, lat])));
    }
    f.set('assigned', isAssigned);
    f.setStyle(featureStyle(isAssigned));

    rows.push(
      '<tr data-wid="'+String(w.id)+'">'+
        '<td>'+String(w.id)+'</td>'+
        '<td>'+escapeHtml(w.name || '')+'</td>'+
        '<td>'+escapeHtml(w.typ  || '')+'</td>'+
        '<td>'+lat+', '+lon+'</td>'+
        '<td data-col="status">'+(isAssigned ? 'zugeordnet' : 'nicht zugeordnet')+'</td>'+
      '</tr>'
    );
  });

  tbody.innerHTML = rows.length
    ? rows.join('')
    : '<tr><td colspan="5"><em>Keine Wachen gefunden.</em></td></tr>';
}

// Marker-Klick → toggle assignment
map.on('singleclick', function(evt){
  var hit = null;
  map.forEachFeatureAtPixel(evt.pixel, function(f){
    if (f && f.get('kind') === 'wache'){ hit = f; return true; }
  });
  if (!hit) return;

  var wid = hit.get('wacheId');
  var newAssign = hit.get('assigned') ? 0 : 1;

  setStatus('Ändere Zuordnung…');
  post('lsttraining_toggle_wache_assignment', {
    entity_type: type,
    entity_id : id,
    wache_id  : wid,
    assign    : newAssign
  })
  .then(function(r){
    if (!r || !r.success) throw new Error((r && r.data) || 'Fehler beim Speichern');
    applyAssignmentChange(wid, !!newAssign);
    setStatus('Gespeichert.');
    setTimeout(function(){ setStatus(''); }, 600);
  })
  .catch(function(err){
    console.error(err);
    setStatus('Fehler: ' + (err.message || err));
  });
});

// Wachen im aktuellen Ausschnitt nur LADEN (nicht zuordnen)
function loadWachenInView(){
  var bb = getViewBBoxLonLat();
  setStatus('Lade Wachen im Ausschnitt…');
  post('lsttraining_get_wachen_bbox', {
    entity_type: type,
    entity_id : id,
    min_lon   : bb.minLon,
    min_lat   : bb.minLat,
    max_lon   : bb.maxLon,
    max_lat   : bb.maxLat,
    limit     : 800
  })
  .then(function(r){
    if (!r || !r.success) throw new Error((r && r.data) || 'Fehler beim Laden');
    renderWachen((r.data && r.data.wachen) ? r.data.wachen : []);
    setStatus('');
  })
  .catch(function(err){
    setStatus('Fehler: ' + (err.message || err));
  });
}
if (btnLoadView) btnLoadView.addEventListener('click', loadWachenInView);

// Suche (optional – nutzt denselben Endpoint; Server kann „search“ auswerten)
function searchWache(){
  var term = (inpSearch && inpSearch.value ? inpSearch.value.trim() : '');
  if (!term) return;

  setStatus('Suche…');
  post('lsttraining_get_wachen_bbox', {
    entity_type: type,
    entity_id : id,
    search    : term,
    limit     : 1000
  })
  .then(function(r){
    if (!r || !r.success) throw new Error((r && r.data) || 'Fehler bei der Suche');
    renderWachen((r.data && r.data.wachen) ? r.data.wachen : []);
    setStatus('');
    var feats = wachenLayer.getSource().getFeatures();
    if (feats.length){
      var ext = ol.extent.createEmpty();
      feats.forEach(function(f){ ol.extent.extend(ext, f.getGeometry().getExtent()); });
      map.getView().fit(ext, { padding:[20,20,20,20], maxZoom:14, duration:250 });
    }
  })
  .catch(function(err){
    setStatus('Fehler: ' + (err.message || err));
  });
}
if (btnSearch) btnSearch.addEventListener('click', searchWache);
if (inpSearch) inpSearch.addEventListener('keydown', function(e){
  if (e.key === 'Enter'){ e.preventDefault(); searchWache(); }
});
} // <<< schließt function openZuordnungPopup



// Export in den globalen Namespace (außerhalb der Funktion!)
window.openZuordnungPopup = openZuordnungPopup;

