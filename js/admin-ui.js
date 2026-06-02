/*  public/js/admin-ui.js  –  v1.0.5  (2025-05-04)
    ------------------------------------------------
    • Loads / saves dispatch centres (“Leitstellen”) and their polygons.
    • Hidden polygon field is always  <input id="geojson_edit" name="geojson_edit">
    • Each popup gets its own map container ID  →  <div id="einsatzgebiet_<ID>>
    • GeoJSON is pulled lazily via Ajax; no huge data-* attributes.
    • Works with the bundled OpenLayers v6.
*/

/* ---------------------------------------------------------- */
/* Globals – two OpenLayers maps (create / edit)               */
/* ---------------------------------------------------------- */
window.mapNeu             = null;
window.mapEdit            = null;
window.dragInteractionNeu = null;
window.dragInteractionEdit = null;

window.wireZuordnungButtonCommon = function (opts) {
  var btn = document.getElementById(opts.buttonId);
  if (!btn) return;

  if (btn._zuoBound) return;
  btn._zuoBound = true;

  function getValidId() {
    var raw = (opts.getEntityId() || '').trim();
    return (/^\d+$/).test(raw) && raw !== '0' ? raw : null;
  }

  function syncState() {
    var id = getValidId();
    if (id) {
      btn.disabled = false;
      btn.removeAttribute('title');
    } else {
      btn.disabled = true;
      btn.title = 'Bitte zuerst speichern';
    }
  }

  function onClick(e) {
    e.preventDefault();
    var id = getValidId();
    if (!id) return;
    openZuordnungPopup({ entityType: opts.entityType, entityId: id });
  }

  syncState();
  btn.addEventListener('click', onClick);

  (opts.watchIds || []).forEach(function(inputId){
    var el = document.getElementById(inputId);
    if (el && !el._zuoObs) {
      el._zuoObs = true;
      el.addEventListener('input',  syncState);
      el.addEventListener('change', syncState);
    }
  });
};



/* ---------------------------------------------------------- */
/* Helper: create a map with a draggable marker                */
/* ---------------------------------------------------------- */
window.initMapWithMarker = function (
  mapId,                // DOM-ID of the map container
  latInput, lonInput,   // IDs of the <input> elements holding coords
  initialCoords,        // [lon, lat] WGS-84
  assignMap,            // global var name -> map instance
  assignInteraction,    // global var name -> modify interaction
  polygonGeoJson = null // optional: GeoJSON string
) {
  /* destroy older instance */
  if (window[assignMap]) {
    window[assignMap].setTarget(null);
    window[assignMap] = null;
  }

  const container = document.getElementById(mapId);
  if (container) container.innerHTML = '';

  /* OSM base */
  const baseLayer = new ol.layer.Tile({ source: new ol.source.OSM() });

  /* centre marker */
  const coord   = ol.proj.fromLonLat(initialCoords);
  const marker  = new ol.Feature({ geometry: new ol.geom.Point(coord) });
  marker.setStyle(
    new ol.style.Style({
      image: new ol.style.RegularShape({
        points : 30,
        radius : 8,
        fill   : new ol.style.Fill({ color: '#ff0000' }),
        stroke : new ol.style.Stroke({ color: '#fff', width: 2 })
      })
    })
  );
  const markerLayer = new ol.layer.Vector({
    source: new ol.source.Vector({ features: [marker] })
  });

  /* map instance */
  const map = new ol.Map({
    target : mapId,
    layers : [baseLayer, markerLayer],
    view   : new ol.View({ center: coord, zoom: 8 })
  });

  /* marker draggable */
  const drag = new ol.interaction.Modify({ source: markerLayer.getSource() });
  drag.on('modifyend', (ev) => {
    const lonlat = ol.proj.toLonLat(
      ev.features.item(0).getGeometry().getCoordinates()
    );
    document.getElementById(latInput).value = lonlat[1].toFixed(6);
    document.getElementById(lonInput).value = lonlat[0].toFixed(6);
  });
  map.addInteraction(drag);

  /* optional polygon overlay */
  if (polygonGeoJson && polygonGeoJson.trim() !== '') {
    try {
      const fmt   = new ol.format.GeoJSON();
      const feats = fmt.readFeatures(polygonGeoJson, {
        featureProjection: map.getView().getProjection()
      });
      const polyLayer = new ol.layer.Vector({
        source: new ol.source.Vector({ features: feats }),
        style : new ol.style.Style({
          stroke: new ol.style.Stroke({ color: 'rgba(0,128,255,0.8)', width: 2 }),
          fill  : new ol.style.Fill({ color: 'rgba(0,128,255,0.2)' })
        })
      });
      map.addLayer(polyLayer);
      map.getView().fit(polyLayer.getSource().getExtent(), {
        padding : [20, 20, 20, 20],
        duration: 500
      });
    } catch (err) {
      console.warn('initMapWithMarker: invalid GeoJSON', err);
    }
  }

  window[assignMap]        = map;
  window[assignInteraction] = drag;
};

/* ---------------------------------------------------------- */
/* "Create new dispatch centre"                               */
/* ---------------------------------------------------------- */
window.openCreateForm = function () {
  const createFrm = document.getElementById('neue-leitstelle-formular');
  const editFrm   = document.getElementById('edit-leitstelle-formular');

  editFrm.style.display  = 'none';
  createFrm.style.display = 'block';

  /* clear inputs */
  ['name','ort','bl','land','lat','lon'].forEach((f) => {
    const el = createFrm.querySelector(`input[name="lst_neu_${f}"]`);
    if (el) el.value = '';
  });
  document.getElementById('geojson_neu').value = '[]';

  /* lazy map init */
  if (!window.mapNeu) {
    setTimeout(() => {
      window.initMapWithMarker(
        'map_neu',
        'lst_neu_lat',
        'lst_neu_lon',
        [13.4, 52.5],          // Berlin
        'mapNeu',
        'dragInteractionNeu'
      );
    }, 80);
  }
};

/* ---------------------------------------------------------- */
/* Open the “edit” popup                                      */
/* ---------------------------------------------------------- */
function setLeitstelleNeighborSelection(rawIds) {
  const select = document.getElementById('lst_neighbor_nebenleitstellen');
  if (!select) return;
  const ownId = currentEditedLeitstelleId();
  let ids = [];
  if (Array.isArray(rawIds)) {
    ids = rawIds.map(String);
  } else if (typeof rawIds === 'string' && rawIds.trim()) {
    try {
      const parsed = JSON.parse(rawIds);
      ids = Array.isArray(parsed) ? parsed.map(String) : String(rawIds).split(',').map((v) => v.trim());
    } catch (e) {
      ids = String(rawIds).split(',').map((v) => v.trim());
    }
  }
  Array.from(select.options).forEach((option) => {
    const isOwn = ownId && String(option.value) === ownId;
    option.selected = !isOwn && ids.indexOf(String(option.value)) !== -1;
  });
  updateNeighborSelfOptionState();
  if (typeof window.refreshNeighborLeitstellenMapSelection === 'function') {
    window.refreshNeighborLeitstellenMapSelection();
  }
}

function currentEditedLeitstelleId() {
  const id = (document.getElementById('lst_update_id') || {}).value || '';
  return String(id).trim();
}

function normalizedNeighborName(value) {
  return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

function currentEditedLeitstelleName() {
  return normalizedNeighborName((document.getElementById('lst_update_name') || {}).value || '');
}

function isOwnNeighborCandidate(id, name) {
  const ownId = currentEditedLeitstelleId();
  const ownName = currentEditedLeitstelleName();
  if (ownId && String(id) === ownId) return true;
  return !!ownName && normalizedNeighborName(name) === ownName;
}

function updateNeighborSelfOptionState() {
  const select = document.getElementById('lst_neighbor_nebenleitstellen');
  if (!select) return;
  Array.from(select.options).forEach((option) => {
    const isOwn = isOwnNeighborCandidate(option.value, option.textContent || '');
    option.disabled = isOwn;
    option.hidden = isOwn;
    if (isOwn) option.selected = false;
  });
}

function neighborSelectedIds() {
  const select = document.getElementById('lst_neighbor_nebenleitstellen');
  const selected = {};
  if (!select) return selected;
  Array.from(select.selectedOptions || []).forEach((option) => {
    selected[String(option.value)] = true;
  });
  return selected;
}

function parseNeighborGps(gps) {
  const match = String(gps || '').match(/(-?\d+(?:[.,]\d+)?)\s*[,;]\s*(-?\d+(?:[.,]\d+)?)/);
  if (!match) return null;
  const lat = parseFloat(match[1].replace(',', '.'));
  const lon = parseFloat(match[2].replace(',', '.'));
  if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
    return null;
  }
  return { lat, lon };
}

function normalizeGeoJson(raw) {
  if (!raw) return null;
  let data = raw;
  if (typeof raw === 'string') {
    try { data = JSON.parse(raw); } catch (e) { return null; }
  }
  if (Array.isArray(data)) {
    data = { type: 'FeatureCollection', features: data };
  } else if (data && data.type === 'Feature') {
    data = { type: 'FeatureCollection', features: [data] };
  }
  return data && data.type === 'FeatureCollection' ? data : null;
}

function neighborMapStyle(feature) {
  const selected = !!neighborSelectedIds()[String(feature.get('neighborId') || '')];
  const kind = feature.get('kind');
  if (kind === 'home-area') {
    return new ol.style.Style({
      stroke: new ol.style.Stroke({ color: 'rgba(37, 99, 235, 0.8)', width: 2 }),
      fill: new ol.style.Fill({ color: 'rgba(37, 99, 235, 0.08)' })
    });
  }
  if (kind === 'neighbor-area') {
    return new ol.style.Style({
      stroke: new ol.style.Stroke({ color: selected ? 'rgba(22, 163, 74, 0.9)' : 'rgba(100, 116, 139, 0.45)', width: selected ? 2 : 1 }),
      fill: new ol.style.Fill({ color: selected ? 'rgba(22, 163, 74, 0.14)' : 'rgba(100, 116, 139, 0.06)' })
    });
  }
  if (kind === 'home-marker') {
    return new ol.style.Style({
      image: new ol.style.Circle({
        radius: 8,
        fill: new ol.style.Fill({ color: '#2563eb' }),
        stroke: new ol.style.Stroke({ color: '#ffffff', width: 2 })
      })
    });
  }
  return new ol.style.Style({
    image: new ol.style.Circle({
      radius: selected ? 8 : 6,
      fill: new ol.style.Fill({ color: selected ? '#16a34a' : '#94a3b8' }),
      stroke: new ol.style.Stroke({ color: selected ? '#ffffff' : '#e2e8f0', width: selected ? 2 : 1 })
    }),
    text: selected ? new ol.style.Text({
      text: String(feature.get('label') || ''),
      offsetY: -18,
      font: '600 12px sans-serif',
      fill: new ol.style.Fill({ color: '#14532d' }),
      stroke: new ol.style.Stroke({ color: '#ffffff', width: 3 })
    }) : null
  });
}

function ensureNeighborMapShell() {
  const existing = document.getElementById('lst_neighbor_map');
  if (existing) return existing;

  const select = document.getElementById('lst_neighbor_nebenleitstellen');
  if (!select) return null;

  const card = select.closest('.lst-leitstelle-card') || select.parentElement;
  if (!card) return null;

  const picker = document.createElement('div');
  picker.className = 'lst-neighbor-picker lst-neighbor-picker--injected';

  const list = document.createElement('div');
  list.className = 'lst-neighbor-picker__list';

  const label = card.querySelector('label[for="lst_neighbor_nebenleitstellen"]');
  if (label) list.appendChild(label);
  list.appendChild(select);

  const mapWrap = document.createElement('div');
  mapWrap.className = 'lst-neighbor-picker__map-wrap';
  mapWrap.innerHTML = [
    '<div id="lst_neighbor_map" class="lst-neighbor-map" aria-label="Nachbarleitstellen visuell auswählen"></div>',
    '<div class="lst-neighbor-map__empty" data-lst-neighbor-map-empty hidden>Keine Nebenleitstellen mit gültigen Koordinaten vorhanden.</div>',
    '<div class="lst-neighbor-legend" aria-hidden="true">',
    '<span><i class="is-home"></i> Leitstelle</span>',
    '<span><i class="is-selected"></i> ausgewählt</span>',
    '<span><i class="is-available"></i> weitere</span>',
    '</div>'
  ].join('');

  picker.appendChild(list);
  picker.appendChild(mapWrap);

  const heading = card.querySelector('h3');
  if (heading && heading.nextSibling) {
    card.insertBefore(picker, heading.nextSibling);
  } else {
    card.insertBefore(picker, card.firstChild);
  }

  return document.getElementById('lst_neighbor_map');
}

window.refreshNeighborLeitstellenMapSelection = function () {
  if (window.lstNeighborMapSource) {
    window.lstNeighborMapSource.changed();
  }
};

window.initNeighborLeitstellenMap = function (homeGeoJson) {
  const target = ensureNeighborMapShell();
  if (!target) return;
  updateNeighborSelfOptionState();
  if (typeof ol === 'undefined') {
    const empty = document.querySelector('[data-lst-neighbor-map-empty]');
    if (empty) {
      empty.hidden = false;
      empty.textContent = 'Karte konnte nicht geladen werden: OpenLayers fehlt.';
    }
    return;
  }

  if (window.lstNeighborMap) {
    window.lstNeighborMap.setTarget(null);
    window.lstNeighborMap = null;
  }
  target.innerHTML = '';

  const source = new ol.source.Vector();
  window.lstNeighborMapSource = source;
  const vectorLayer = new ol.layer.Vector({ source, style: neighborMapStyle });
  const map = new ol.Map({
    target: target,
    layers: [
      new ol.layer.Tile({ source: new ol.source.OSM() }),
      vectorLayer
    ],
    view: new ol.View({ center: ol.proj.fromLonLat([9.0, 51.0]), zoom: 6 })
  });
  window.lstNeighborMap = map;

  const format = new ol.format.GeoJSON();
  const homeArea = normalizeGeoJson(homeGeoJson || (document.getElementById('geojson_edit') || {}).value || '');
  if (homeArea) {
    try {
      format.readFeatures(homeArea, {
        dataProjection: 'EPSG:4326',
        featureProjection: map.getView().getProjection()
      }).forEach((feature) => {
        feature.set('kind', 'home-area');
        source.addFeature(feature);
      });
    } catch (e) {}
  }

  const lat = parseFloat((document.getElementById('lst_update_lat') || {}).value || '');
  const lon = parseFloat((document.getElementById('lst_update_lon') || {}).value || '');
  if (Number.isFinite(lat) && Number.isFinite(lon)) {
    const home = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])) });
    home.set('kind', 'home-marker');
    home.set('label', 'Leitstelle');
    source.addFeature(home);
  }

  let visibleNeighborCount = 0;
  (window.lstNeighborLeitstellenData || []).forEach((neighbor) => {
    if (isOwnNeighborCandidate(neighbor.id, neighbor.name || '')) return;
    const coords = parseNeighborGps(neighbor.gps);
    if (!coords) return;
    visibleNeighborCount += 1;

    const area = normalizeGeoJson(neighbor.geojson);
    if (area) {
      try {
        format.readFeatures(area, {
          dataProjection: 'EPSG:4326',
          featureProjection: map.getView().getProjection()
        }).forEach((feature) => {
          feature.set('kind', 'neighbor-area');
          feature.set('neighborId', String(neighbor.id));
          feature.set('label', neighbor.name || '');
          source.addFeature(feature);
        });
      } catch (e) {}
    }

    const marker = new ol.Feature({
      geometry: new ol.geom.Point(ol.proj.fromLonLat([coords.lon, coords.lat]))
    });
    marker.set('kind', 'neighbor-marker');
    marker.set('neighborId', String(neighbor.id));
    marker.set('label', neighbor.name || ('Nebenleitstelle ' + neighbor.id));
    source.addFeature(marker);
  });

  const empty = document.querySelector('[data-lst-neighbor-map-empty]');
  if (empty) empty.hidden = visibleNeighborCount > 0;

  map.on('click', function (event) {
    let handled = false;
    map.forEachFeatureAtPixel(event.pixel, function (feature) {
      const id = String(feature.get('neighborId') || '');
      if (!id || handled) return false;
      const select = document.getElementById('lst_neighbor_nebenleitstellen');
      const option = select ? Array.from(select.options).find((item) => String(item.value) === id) : null;
      if (option) {
        option.selected = !option.selected;
        option.parentElement.dispatchEvent(new Event('change', { bubbles: true }));
        handled = true;
      }
      return true;
    });
  });

  map.on('pointermove', function (event) {
    const hit = map.hasFeatureAtPixel(event.pixel, {
      layerFilter: function (layer) { return layer === vectorLayer; }
    });
    target.style.cursor = hit ? 'pointer' : '';
  });

  const extent = source.getExtent();
  if (!ol.extent.isEmpty(extent)) {
    map.getView().fit(extent, { padding: [24, 24, 24, 24], maxZoom: 11, duration: 150 });
  }
  setTimeout(function () { map.updateSize(); }, 0);
};

window.editLeitstelle = function (id, name, ort, bl, land, lat, lon, policeImage, policeSignals, rescueImage, rescueSignals, neighborIds) {
  const createFrm = document.getElementById('neue-leitstelle-formular');
  const editFrm   = document.getElementById('edit-leitstelle-formular');

  if (editFrm.style.display === 'block') {
    alert('Another dispatch centre is already being edited.');
    return;
  }

  if (createFrm) createFrm.style.display = 'none';
  editFrm.style.display = 'block';

   /* fill text inputs */
  const values = { id, name, ort, bl, land, lat, lon };
  ['id','name','ort','bl','land','lat','lon'].forEach((k) => {
    const el = document.getElementById(`lst_update_${k}`);
    if (el) el.value = values[k];
  });
  updateNeighborSelfOptionState();
  const policeImageEl = document.getElementById('lst_update_police_vehicle_image');
  if (policeImageEl) policeImageEl.value = policeImage || 'img/fahrzeug/default_pol.png';
  const policeSignalsEl = document.getElementById('lst_update_police_signal_lights_json');
  if (policeSignalsEl) policeSignalsEl.value = policeSignals || '';
  const rescueImageEl = document.getElementById('lst_update_rescue_vehicle_image');
  if (rescueImageEl) rescueImageEl.value = rescueImage || 'img/fahrzeug/default.png';
  const rescueSignalsEl = document.getElementById('lst_update_rescue_signal_lights_json');
  if (rescueSignalsEl) rescueSignalsEl.value = rescueSignals || '';
  setLeitstelleNeighborSelection(neighborIds || []);
  const mode = document.getElementById('lst_form_mode');
  if (mode) mode.value = 'update';
  if (typeof window.updateAllDefaultVehiclePreviews === 'function') {
    window.updateAllDefaultVehiclePreviews();
  }

  /* clear polygon field */
  document.getElementById('geojson_edit').value = '[]';

  /* configure polygon-editor button */
  const egBtn = editFrm.querySelector('.open-einsatzgebiet-editor');
  if (egBtn) {
    egBtn.dataset.mapId        = `einsatzgebiet_${id}`;
    egBtn.dataset.leitstelleId = id;
    egBtn.dataset.center       = `${lat},${lon}`;
    egBtn.dataset.context      = 'leitstelle';
  }

  editFrm.scrollIntoView({ behavior: 'smooth' });

  /* fetch polygon */
  fetch(`${ajaxurl}?action=lsttraining_get_einsatzgebiet&leitstelle_id=${id}&t=${Date.now()}`)
    .then((r) => r.json())
    .then((res) => {
      let poly = res.success && res.data
        ? res.data
        : { type: 'FeatureCollection', features: [] };

      if (Array.isArray(poly)) {
        poly = { type: 'FeatureCollection', features: poly };
      }

    document.getElementById('geojson_edit').value = JSON.stringify(poly);

	if (typeof window.syncWachenZuordButton === 'function') {
	  window.syncWachenZuordButton();
	} else if (typeof window.updateWachenZuordButtonState === 'function') {
	  window.updateWachenZuordButtonState();
	}

	window.initMapWithMarker(
	  'map_edit',
	  'lst_update_lat',
	  'lst_update_lon',
	  [parseFloat(lon), parseFloat(lat)],
	  'mapEdit',
	  'dragInteractionEdit',
	  JSON.stringify(poly)
	);
    if (typeof window.initNeighborLeitstellenMap === 'function') {
      window.initNeighborLeitstellenMap(JSON.stringify(poly));
    }
    });

};

/* ---------------------------------------------------------- */
/* Table buttons (“Bearbeiten …”)                             */
/* ---------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.edit-leitstelle').forEach((btn) => {
    btn.addEventListener('click', (ev) => {
      ev.preventDefault();
      window.editLeitstelle(
        btn.dataset.id,
        btn.dataset.name,
        btn.dataset.ort,
        btn.dataset.bl,
        btn.dataset.land,
        btn.dataset.lat,
        btn.dataset.lon,
        btn.dataset.policeImage,
        btn.dataset.policeSignalLights,
        btn.dataset.rescueImage,
        btn.dataset.rescueSignalLights,
        btn.dataset.neighborIds
      );
    });
  });
});



function resetValue(id) {
  const el = document.getElementById(id);
  if (el) el.value = '';            // nur wenn Element existiert
}

function ensureEditMap() {
  // existiert schon? → nur Größe & Marker resetten
  if (window.mapEdit) {
    window.mapEdit.getView()
                  .setCenter(ol.proj.fromLonLat([9.0, 51.0]))  // Mitte DE
                  .setZoom(7);

    // Marker / Feature-Layer leeren
    const src = mapEdit.getLayers().item(1).getSource();
    if (src) src.clear();

  } else {
    // noch keine Map vorhanden – komplett anlegen
    window.initMapWithMarker(
      'map_edit',          // DIV-ID
      'lst_update_lat',    // hidden lat-Input
      'lst_update_lon',    // hidden lon-Input
      [9.0, 51.0],         // Start-Center
      'mapEdit',           // globale Referenz
      'dragInteractionEdit'
    );
  }

  // Timing-Problem: Map war eben noch in display:none
  // ⇒ Größe nachträglich aktualisieren
  setTimeout(() => mapEdit.updateSize(), 0);
}

function openLeitstellePopupForCreate() {
  // heading
  const heading = document.querySelector('#edit-leitstelle-formular h2');
  if (heading) heading.textContent = 'Leitstelle erstellen';

  // clear inputs
  [
    'lst_update_id','lst_update_name','lst_update_ort',
    'lst_update_bl','lst_update_land','lst_update_lat','lst_update_lon'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const policeImageEl = document.getElementById('lst_update_police_vehicle_image');
  if (policeImageEl) policeImageEl.value = 'img/fahrzeug/default_pol.png';
  const policeSignalsEl = document.getElementById('lst_update_police_signal_lights_json');
  if (policeSignalsEl) policeSignalsEl.value = '';
  const rescueImageEl = document.getElementById('lst_update_rescue_vehicle_image');
  if (rescueImageEl) rescueImageEl.value = 'img/fahrzeug/default.png';
  const rescueSignalsEl = document.getElementById('lst_update_rescue_signal_lights_json');
  if (rescueSignalsEl) rescueSignalsEl.value = '';
  setLeitstelleNeighborSelection([]);
  if (typeof window.updateAllDefaultVehiclePreviews === 'function') {
    window.updateAllDefaultVehiclePreviews();
  }

  // mode = create
  const mode = document.getElementById('lst_form_mode');
  if (mode) mode.value = 'create';

  // map reset / init
  if (typeof resetEditMaps === 'function') resetEditMaps();
  if (typeof ensureEditMap  === 'function') ensureEditMap();

  // show overlay + popup
  const overlay = document.getElementById('popup-overlay');
  if (overlay) overlay.style.display = 'block';

  const popup = document.getElementById('edit-leitstelle-formular');
  if (popup)  popup.style.display = 'block';
  if (typeof window.initNeighborLeitstellenMap === 'function') {
    window.initNeighborLeitstellenMap('');
  }

	if (typeof window.syncWachenZuordButton === 'function') {
	  window.syncWachenZuordButton();
	} else if (typeof window.updateWachenZuordButtonState === 'function') {
	  window.updateWachenZuordButtonState();
	}
}

/* register click handler after DOM is ready */
document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const page = params.get('page');

  if (page !== 'lsttraining_leitstellen') {
    // Nicht die richtige Seite – kein Button vorhanden
    return;
  }

  const btn = document.getElementById('btn-new-leitstelle');
  if (!btn) {
    console.warn('[lsttraining] Button #btn-new-leitstelle nicht gefunden.');
    return;
  }

  btn.addEventListener('click', e => {
    e.preventDefault();
    openLeitstellePopupForCreate();
  });

  const neighbors = document.getElementById('lst_neighbor_nebenleitstellen');
  if (neighbors && !neighbors._neighborMapBound) {
    neighbors._neighborMapBound = true;
    neighbors.addEventListener('change', function () {
      updateNeighborSelfOptionState();
      if (typeof window.refreshNeighborLeitstellenMapSelection === 'function') {
        window.refreshNeighborLeitstellenMapSelection();
      }
    });
  }
  if (neighbors && typeof window.initNeighborLeitstellenMap === 'function') {
    window.initNeighborLeitstellenMap((document.getElementById('geojson_edit') || {}).value || '');
  }
});

/* helper: reset existing map (center Germany, clear polygon/marker) */
function resetEditMaps() {
  if (window.mapEdit) {
    mapEdit.getView().setCenter(ol.proj.fromLonLat([9.0, 51.0]));
    mapEdit.getLayers().item(1).getSource().clear();
  }
	  const poly = document.getElementById('geojson_edit');
	if (poly) poly.value = '';

	if (typeof window.syncWachenZuordButton === 'function') {
	  window.syncWachenZuordButton();
	} else if (typeof window.updateWachenZuordButtonState === 'function') {
	  window.updateWachenZuordButtonState();
}
}

document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('.open-einsatzgebiet-editor');
  if (!btn) return;
  if ((btn.dataset.context || '') !== 'leitstelle') return;

  const editFrm = document.getElementById('edit-leitstelle-formular');
  const popup = editFrm ? editFrm.querySelector('.einsatzgebiet-popup')
                        : document.querySelector('.einsatzgebiet-popup');
  if (!popup) { alert('Einsatzgebiet-Editor nicht gefunden.'); return; }

  const mapDiv = popup.querySelector('[data-einsatzgebiet-map]');
  if (!mapDiv) { console.error('Map-DIV mit data-einsatzgebiet-map fehlt.'); return; }

  const newMapId = btn.dataset.mapId;               // z.B. einsatzgebiet_1
  const oldMapId = popup.dataset.mapId || mapDiv.id;

  window._openlayersMaps = window._openlayersMaps || {};
  if (oldMapId && window._openlayersMaps[oldMapId]) {
    try { window._openlayersMaps[oldMapId].setTarget(null); } catch (e) {}
    delete window._openlayersMaps[oldMapId];
  }

  popup.dataset.mapId        = newMapId;
  popup.dataset.leitstelleId = btn.dataset.leitstelleId;
  popup.dataset.center       = btn.dataset.center;
  popup.dataset.context      = 'leitstelle';

  mapDiv.id = newMapId;

  popup.style.display = 'block';
 const overlay = document.getElementById('popup-overlay');
  if (overlay) overlay.style.display = 'block';

  popup.style.display = 'block';
	
  if (typeof window.initEinsatzgebietEditor === 'function') {
    window.initEinsatzgebietEditor(popup);
  } else {
    console.error('initEinsatzgebietEditor() fehlt. Script js/einsatzgebiet-editor.js nicht geladen?');
  }
});


