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
window.editLeitstelle = function (id, name, ort, bl, land, lat, lon) {
  const createFrm = document.getElementById('neue-leitstelle-formular');
  const editFrm   = document.getElementById('edit-leitstelle-formular');

  if (editFrm.style.display === 'block') {
    alert('Another dispatch centre is already being edited.');
    return;
  }

  if (createFrm) createFrm.style.display = 'none';
  editFrm.style.display = 'block';

  /* fill text inputs */
  ['id','name','ort','bl','land','lat','lon'].forEach((k) => {
    const el = document.getElementById(`lst_update_${k}`);
    if (el) el.value = eval(k);
  });

  /* clear polygon field */
  document.getElementById('geojson_edit').value = '[]';

  /* configure polygon-editor button */
  const egBtn = editFrm.querySelector('.open-einsatzgebiet-editor');
  if (egBtn) {
    egBtn.dataset.mapId        = `einsatzgebiet_${id}`;   // unique container ID
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

      window.initMapWithMarker(
        'map_edit',
        'lst_update_lat',
        'lst_update_lon',
        [parseFloat(lon), parseFloat(lat)],
        'mapEdit',
        'dragInteractionEdit',
        JSON.stringify(poly)
      );
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
        btn.dataset.lon
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
});

/* helper: reset existing map (center Germany, clear polygon/marker) */
function resetEditMaps() {
  if (window.mapEdit) {
    mapEdit.getView().setCenter(ol.proj.fromLonLat([9.0, 51.0]));
    mapEdit.getLayers().item(1).getSource().clear();
  }
  const poly = document.getElementById('geojson_edit');
  if (poly) poly.value = '';
}


