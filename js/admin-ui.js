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
      const poly = res.success && res.data
        ? res.data
        : { type: 'FeatureCollection', features: [] };

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

/* ---------------------------------------------------------- */
/* Open polygon editor popup                                  */
/* ---------------------------------------------------------- */
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('.open-einsatzgebiet-editor');
  if (!btn) return;

  const mapId        = btn.dataset.mapId;          // e.g. einsatzgebiet_42
  const geojsonId    = 'geojson_edit';             // fixed hidden field
  const context      = btn.dataset.context;        // leitstelle | neben
  const leitstelleId = btn.dataset.leitstelleId;

  /* correct Ajax action */
  const action =
    context === 'neben'
      ? `lsttraining_get_neben_einsatzgebiet&neben_id=${leitstelleId}`
      : `lsttraining_get_einsatzgebiet&leitstelle_id=${leitstelleId}`;

  fetch(`${ajaxurl}?action=${action}`)
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) {
        console.error('Polygon load failed');
        return;
      }

      const poly = res.data ?? { type: 'FeatureCollection', features: [] };

      /* request editor HTML */
      const qs = new URLSearchParams({
        action       : 'lsttraining_render_einsatzgebiet_editor',
        map_id       : mapId,
        input_id     : geojsonId,
        leitstelle_id: leitstelleId,
        context,
        center       : btn.dataset.center || ''
      });

      fetch(`${ajaxurl}?${qs.toString()}`)
        .then((r) => r.text())
        .then((html) => {
          const tmp = document.createElement('div');
          tmp.innerHTML = html.trim();
          const popup = tmp.firstElementChild;
          document.body.appendChild(popup);

          /* inject GeoJSON */
          document.getElementById(geojsonId).value = JSON.stringify(poly);

          popup.style.display = 'block';

          if (typeof window.initEinsatzgebietEditor === 'function') {
            window.initEinsatzgebietEditor(popup);
          }
        });
    });
});
