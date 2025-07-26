window._openlayersMaps = window._openlayersMaps || {};

window.initEinsatzgebietEditor = function (container) {
    const mapId = container.dataset.mapId;
    const geojsonId = container.dataset.geojsonId;
    const leitstelleId = container.dataset.leitstelleId;
    const geojsonTextarea = document.getElementById(geojsonId);

    // Falls Karte schon existiert → nur anzeigen
    if (window._openlayersMaps[mapId]) {
    requestAnimationFrame(() => {
        container.style.display = "block";
        window._openlayersMaps[mapId].updateSize();
    });
    return;
}

    const deleteButton = container.querySelector('.btn-einsatzgebiet-delete');
    const importButton = container.querySelector('#btn-geojson-import');
    const manualTextarea = container.querySelector('#manual_geojson');

    const format = new ol.format.GeoJSON();
    const vectorSource = new ol.source.Vector();
	window.vectorSource = vectorSource;
    const vectorLayer = new ol.layer.Vector({ source: vectorSource });

    let center = [13.4, 52.5]; // Fallback Deutschland
    const centerAttr = container.dataset.center;
    let centerCoords = ol.proj.fromLonLat(center);

    if (centerAttr && centerAttr.includes(',')) {
        const [lat, lon] = centerAttr.split(',').map(parseFloat);
        if (!isNaN(lat) && !isNaN(lon)) {
            center = [lon, lat];
            centerCoords = ol.proj.fromLonLat(center);
        }
    }

    const map = new ol.Map({
        target: mapId,
        layers: [
            new ol.layer.Tile({ source: new ol.source.OSM() }),
            vectorLayer
        ],
        view: new ol.View({
            center: centerCoords,
            zoom: 10
        })
    });

	
	// ROTER Marker für Leitstelle oder Nebenstelle
	if (centerAttr && centerAttr.includes(',')) {
		const [lat, lon] = centerAttr.split(',').map(parseFloat);
		if (!isNaN(lat) && !isNaN(lon)) {
			const markerCoords = ol.proj.fromLonLat([lon, lat]);
			const redMarker = new ol.Feature({
				geometry: new ol.geom.Point(markerCoords)
			});
			redMarker.setStyle(new ol.style.Style({
				image: new ol.style.Circle({
					radius: 6,
					fill: new ol.style.Fill({ color: 'red' }),
					stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
				})
			}));
			const redLayer = new ol.layer.Vector({
				source: new ol.source.Vector({ features: [redMarker] })
			});
			map.addLayer(redLayer);
		}
	}
	
    window._openlayersMaps[mapId] = map;

    
    const draw = new ol.interaction.Draw({ source: vectorSource, type: 'Polygon' });
    const modify = new ol.interaction.Modify({ source: vectorSource });
    map.addInteraction(draw);
    map.addInteraction(modify);

    function updateGeoJSON() {
        const features = vectorSource.getFeatures();
        const geojson = format.writeFeatures(features, {
            featureProjection: map.getView().getProjection()
        });
        geojsonTextarea.value = geojson;
		if (manualTextarea) manualTextarea.value = JSON.stringify(JSON.parse(geojson), null, 2);
    }

    draw.on('drawend', function (evt) {
        const newFeature = evt.feature;
        const features = vectorSource.getFeatures();
        if (features.length > 1) {
            vectorSource.clear();
            vectorSource.addFeature(newFeature);
        }
        updateGeoJSON();
    });

    modify.on('modifyend', updateGeoJSON);

    const existing = geojsonTextarea.value;

try {
    let parsed = existing ? JSON.parse(existing) : null;

    /* akzeptiere auch Array- oder Einzel-Feature  → FeatureCollection */
    if (Array.isArray(parsed)) {
        parsed = { type: 'FeatureCollection', features: parsed };
    } else if (parsed && parsed.type === 'Feature') {
        parsed = { type: 'FeatureCollection', features: [parsed] };
    }

    if (parsed && parsed.type === 'FeatureCollection') {
        if (parsed.crs) delete parsed.crs;          // altes CRS-Feld entfernen

        const features = format.readFeatures(parsed, {
            featureProjection: map.getView().getProjection()
        });

        if (features.length > 0) {
            vectorSource.clear();
            vectorSource.addFeatures(features);
			
			if (manualTextarea) {
			manualTextarea.value = JSON.stringify(parsed, null, 2);   // hübsch eingerückt
		}
			
            if (deleteButton) deleteButton.style.display = 'inline-block';

            requestAnimationFrame(() => {
                const extent = vectorSource.getExtent();
                if (!ol.extent.isEmpty(extent)) {
                    map.getView().fit(extent, {
                        padding : [50, 50, 50, 50],
                        duration: 200,
                        maxZoom : 8
                    });
                }
            });
        }
    } else if (existing) {
        console.warn('GeoJSON ist vorhanden, aber kein FeatureCollection', parsed);
    }

} catch (e) {
    if (existing) {
        console.warn('GeoJSON konnte nicht geparst werden:', e);
    }
}


    map.getViewport().addEventListener('contextmenu', function (e) {
        e.preventDefault();
        const features = vectorSource.getFeatures();
        if (features.length === 0) return;

        const polygon = features[0].getGeometry();
        const coords = polygon.getCoordinates()[0];

        if (coords.length <= 4) {
            vectorSource.clear();
            if (deleteButton) deleteButton.style.display = 'none';
        } else {
            coords.splice(coords.length - 2, 1);
            polygon.setCoordinates([coords]);
        }

        updateGeoJSON();
    });

   container.querySelector('.btn-einsatzgebiet-save')?.addEventListener('click', () => {

  /* 1 | Aktuelle GeoJSON aus der Karte holen */
  updateGeoJSON();
  let rawGeoJson = geojsonTextarea.value;

  try {           // valide JSON erzwingen
    rawGeoJson = JSON.stringify(JSON.parse(rawGeoJson));
  } catch (err) {
    alert('GeoJSON ist ungültig.');
    console.error(err);
    return;
  }

  /* 2 | Ajax-Call */
  const context   = container.dataset.context === 'neben' ? 'neben' : 'leitstelle';
  const action    = (context === 'neben')
        ? 'lsttraining_save_neben_einsatzgebiet'
        : 'lsttraining_save_einsatzgebiet';

  const bodyData  = { action, geojson: rawGeoJson };
  bodyData[ context === 'neben' ? 'neben_id' : 'leitstelle_id' ] = leitstelleId;

  fetch( ajaxurl, {
    method : 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body   : new URLSearchParams(bodyData)
  })
  .then(r => r.json())
  .then(result => {

    if (!result.success) {
      alert('Fehler: ' + result.data);
      return;
    }

    /* 3 | Modal schließen */
    container.style.display = 'none';
    alert('Einsatzgebiet gespeichert');

    /* 4 | Nebenstellen-Hauptkarte live aktualisieren */
    const nebenMap = window._openlayersMaps?.['nebenstelle_map']; // liegt dort seit Listen-Aufbau
    if (!nebenMap) return;    // keine Hauptkarte? → fertig

    const fmt    = new ol.format.GeoJSON();
    const feats  = fmt.readFeatures(rawGeoJson, {
                    featureProjection: nebenMap.getView().getProjection()
                  });

    /* Layer anlegen / ersetzen */
    let polyLayer = nebenMap.getLayers().getArray()
                     .find(l => l.get('isPolygonLayer'));
    if (!polyLayer) {
      polyLayer = new ol.layer.Vector({
        source: new ol.source.Vector(),
        style : new ol.style.Style({
          stroke: new ol.style.Stroke({ color:'rgba(0,128,255,0.8)', width:2 }),
          fill  : new ol.style.Fill ({ color:'rgba(0,128,255,0.2)' })
        })
      });
      polyLayer.set('isPolygonLayer', true);
      nebenMap.addLayer(polyLayer);
    }

    polyLayer.getSource().clear();
    polyLayer.getSource().addFeatures(feats);

    const ext = polyLayer.getSource().getExtent();
    if (!ol.extent.isEmpty(ext)) {
      nebenMap.getView().fit(ext, {
        padding : [50,50,50,50],
        duration: 300,
        maxZoom : 8
      });
    }
  });
}); 
    deleteButton?.addEventListener('click', () => {
        vectorSource.clear();
        updateGeoJSON();
        deleteButton.style.display = 'none';
    });

    container.querySelector('.btn-einsatzgebiet-close')?.addEventListener('click', () => {
        container.style.display = 'none';
    });

    container.style.display = "block";
};
	
	window.openEinsatzgebietPopup = function () {
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

