const start = [13.071311195346201, 52.40090501022394];
const ziel = [13.127166048176974, 52.364541475722675];

const map = new ol.Map({
  target: 'map',
  layers: [
    new ol.layer.Tile({ source: new ol.source.OSM() })
  ],
  view: new ol.View({
    center: ol.proj.fromLonLat([13.0624, 52.4009]),
    zoom: 12
  })
});

// Wachen laden
fetch(LST_PLUGIN + 'get_wachen.php')
  .then(res => res.json())
  .then(data => {
    const features = data.map(wache => {
      const feature = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([wache.longitude, wache.latitude])),
        name: wache.name
      });

      feature.setStyle(new ol.style.Style({
        image: new ol.style.Icon({
          src: LST_PLUGIN + 'img/wachen/' + wache.bild_datei,
          scale: 2,
          anchor: [0.5, 0.5],
          anchorXUnits: 'fraction',
          anchorYUnits: 'fraction',
          crossOrigin: 'anonymous'
        })
      }));

      return feature;
    });

    const vectorSource = new ol.source.Vector({ features });
    const vectorLayer = new ol.layer.Vector({ source: vectorSource });
    map.addLayer(vectorLayer);
  });

function starteFahrt(fahrzeugPfad, start, ziel) {
  fetch(LST_PLUGIN + 'get_route.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ coordinates: [start, ziel] })
  })
    .then(res => res.json())
    .then(routeGeoJSON => {
      const route = routeGeoJSON.features[0];
      const coords = route.geometry.coordinates;
      const segments = route.properties.segments[0].steps;

      // ✅ Fix: FeatureCollection richtig einlesen
      const routeFeatures = new ol.format.GeoJSON().readFeatures(routeGeoJSON, {
        dataProjection: 'EPSG:4326',
        featureProjection: 'EPSG:3857'
      });

      const routeLayer = new ol.layer.Vector({
        source: new ol.source.Vector({ features: routeFeatures }),
        style: new ol.style.Style({
          stroke: new ol.style.Stroke({ color: '#007AFF', width: 4 })
        })
      });
      map.addLayer(routeLayer);

      // Fahrzeug-Icon
      const fahrzeugFeature = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat(coords[0]))
      });

      fahrzeugFeature.setStyle(new ol.style.Style({
        image: new ol.style.Icon({
          src: fahrzeugPfad,
          scale: 0.3,
          anchor: [0.5, 1],
          anchorXUnits: 'fraction',
          anchorYUnits: 'fraction',
          crossOrigin: 'anonymous'
        })
      }));

      const fahrzeugLayer = new ol.layer.Vector({
        source: new ol.source.Vector({ features: [fahrzeugFeature] })
      });
      map.addLayer(fahrzeugLayer);

      // Interpolierte Animation pro Segment
      let currentSegment = 0;

      function animateSegment() {
        if (currentSegment >= coords.length - 1) return;

        const from = ol.proj.fromLonLat(coords[currentSegment]);
        const to = ol.proj.fromLonLat(coords[currentSegment + 1]);

        let duration = 1000;
        if (segments && segments[currentSegment]) {
          duration = segments[currentSegment].duration * 1000;
        }

        const stepStart = Date.now();

        function moveStep() {
          const elapsed = Date.now() - stepStart;
          const t = Math.min(elapsed / duration, 1);

          const x = from[0] + (to[0] - from[0]) * t;
          const y = from[1] + (to[1] - from[1]) * t;
          fahrzeugFeature.getGeometry().setCoordinates([x, y]);

          if (t < 1) {
            requestAnimationFrame(moveStep);
          } else {
            currentSegment++;
            animateSegment();
          }
        }

        moveStep();
      }

      animateSegment();
    });
}

starteFahrt(LST_PLUGIN + 'img/fahrzeug/default_RW.png', start, ziel);
