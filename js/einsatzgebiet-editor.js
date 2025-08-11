window._openlayersMaps = window._openlayersMaps || {};

window.initEinsatzgebietEditor = function (container) {
    const mapId          = container.dataset.mapId;
    const geojsonId      = container.dataset.geojsonId;
    const leitstelleId   = container.dataset.leitstelleId;
    const geojsonTextarea = document.getElementById(geojsonId);

    // Falls Karte schon existiert → nur anzeigen
    if (window._openlayersMaps?.[mapId]) {
        requestAnimationFrame(() => {
            container.style.display = "block";
            window._openlayersMaps[mapId].updateSize();
        });
        return;
    }

    const deleteButton   = container.querySelector('.btn-einsatzgebiet-delete');
    const manualTextarea = container.querySelector('#manual_geojson');
    const format         = new ol.format.GeoJSON();

		const vectorSource   = new ol.source.Vector();
    const vectorLayer    = new ol.layer.Vector({ source: vectorSource });
	
	window._egSources = window._egSources || {};
	window._egSources[mapId] = vectorSource;
	
    // für Upload-/Vorschau-Script sichtbar machen:
    window.vectorSource  = vectorSource;
    window.vectorLayer   = vectorLayer;
		
	
	window.vectorSource  = vectorSource;
	window.vectorLayer   = vectorLayer;

    // Initiale Kartenansicht
    let center = [13.4, 52.5];
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

    // Roter Marker
    if (centerAttr && centerAttr.includes(',')) {
        const [lat, lon] = centerAttr.split(',').map(parseFloat);
        if (!isNaN(lat) && !isNaN(lon)) {
            const markerCoords = ol.proj.fromLonLat([lon, lat]);
            const redMarker    = new ol.Feature({ geometry: new ol.geom.Point(markerCoords) });
            redMarker.setStyle(new ol.style.Style({
                image: new ol.style.Circle({
                    radius: 6,
                    fill: new ol.style.Fill({ color: 'red' }),
                    stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
                })
            }));
            map.addLayer(new ol.layer.Vector({
                source: new ol.source.Vector({ features: [redMarker] })
            }));
        }
    }

    window._openlayersMaps = window._openlayersMaps || {};
    window._openlayersMaps[mapId] = map;

    // Zeichnen & Modifizieren
    map.addInteraction(new ol.interaction.Draw({ source: vectorSource, type: 'Polygon' }));
    map.addInteraction(new ol.interaction.Modify({ source: vectorSource }));

    function updateGeoJSON() {
        const features = vectorSource.getFeatures();
  	const geojson  = format.writeFeatures(features, {
     dataProjection: 'EPSG:4326',
     featureProjection: map.getView().getProjection()
 });
        geojsonTextarea.value = geojson;
        if (manualTextarea) {
            manualTextarea.value = JSON.stringify(JSON.parse(geojson), null, 2);
        }
    }

    map.on('drawend', updateGeoJSON);
    map.on('modifyend', updateGeoJSON);

    // Vorhandenes GeoJSON laden (nur wenn nicht '[]')
    const rawValue = geojsonTextarea?.value?.trim();
    let parsed = null;
    if (rawValue && rawValue !== '[]') {
        try {
            parsed = JSON.parse(rawValue);
        } catch (err) {
            console.warn('GeoJSON konnte nicht geparst werden, überspringe Laden:', err);
        }
    }
    if (parsed) {
        if (Array.isArray(parsed)) {
            parsed = { type: 'FeatureCollection', features: parsed };
        } else if (parsed.type === 'Feature') {
            parsed = { type: 'FeatureCollection', features: [parsed] };
        }
    }
	if (parsed?.type === 'FeatureCollection' && parsed.features.length) {
     if (parsed.crs) delete parsed.crs;
     const feats = format.readFeatures(parsed, {
         dataProjection: 'EPSG:4326',
         featureProjection: map.getView().getProjection()
     });
     vectorSource.clear();
     vectorSource.addFeatures(feats);

        if (deleteButton) deleteButton.style.display = 'inline-block';
        requestAnimationFrame(() => {
            const ext = vectorSource.getExtent();
            if (!ol.extent.isEmpty(ext)) {
                map.getView().fit(ext, { padding: [50,50,50,50], duration: 200, maxZoom: 8 });
            }
        });
    } else {
        console.info('Kein vorhandenes Einsatzgebiet geladen.');
    }

    // Kontextmenü zum Reduzieren
    map.getViewport().addEventListener('contextmenu', e => {
        e.preventDefault();
        const feats = vectorSource.getFeatures();
        if (!feats.length) return;
        const coords = feats[0].getGeometry().getCoordinates()[0];
        if (coords.length <= 4) {
            vectorSource.clear();
            if (deleteButton) deleteButton.style.display = 'none';
        } else {
            coords.splice(-2, 1);
            feats[0].getGeometry().setCoordinates([coords]);
        }
        updateGeoJSON();
    });

    // Save-Button: Skip AJAX when creating new Nebenstelle (id = 0)
    container.querySelector('.btn-einsatzgebiet-save')?.addEventListener('click', () => {
        updateGeoJSON();
        const rawGeoJson = geojsonTextarea.value;
        if (container.dataset.context === 'neben' && container.dataset.leitstelleId === '0') {
            // Create-Modus: nur schließen
            container.style.display = 'none';
            return;
        }
        // Edit-Modus: original AJAX speichern
        const context = container.dataset.context === 'neben' ? 'neben' : 'leitstelle';
        const action  = (context === 'neben')
            ? 'lsttraining_save_neben_einsatzgebiet'
            : 'lsttraining_save_einsatzgebiet';
        const body = new URLSearchParams({
            action,
            geojson: rawGeoJson,
            [context === 'neben' ? 'neben_id' : 'leitstelle_id']: leitstelleId
        });
        fetch(ajaxurl, { method: 'POST', body })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    alert('Fehler: ' + res.data);
                    return;
                }
                container.style.display = 'none';
                alert('Einsatzgebiet gespeichert');
                // Hauptkarte live updaten...
            });
    });

    // Delete & Close
    deleteButton?.addEventListener('click', () => {
        vectorSource.clear();
        updateGeoJSON();
        deleteButton.style.display = 'none';
    });
    container.querySelector('.btn-einsatzgebiet-close')?.addEventListener('click', () => {
        container.style.display = 'none';
    });

    // Editor anzeigen
    container.style.display = 'block';
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