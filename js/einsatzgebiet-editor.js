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
    const parsed = JSON.parse(existing);

    if (parsed && parsed.type === "FeatureCollection") {
        if (parsed.crs) {
    delete parsed.crs;
}

const features = format.readFeatures(parsed, {
    featureProjection: map.getView().getProjection()
});

        if (features.length > 0) {
            vectorSource.clear();
            vectorSource.addFeatures(features);

            if (deleteButton) {
                deleteButton.style.display = 'inline-block';
            }

            requestAnimationFrame(() => {
                const extent = vectorSource.getExtent();
                if (!ol.extent.isEmpty(extent)) {
                    map.getView().fit(extent, {
                        padding: [50, 50, 50, 50],
                        duration: 200,
                        maxZoom: 8
                    });
                }
            });
        }
    } else {
        console.warn("GeoJSON ist vorhanden, aber kein FeatureCollection", parsed);
    }
} catch (e) {
    if (existing) {
        console.warn("GeoJSON konnte nicht geparst werden:", e);
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
        updateGeoJSON();
        let rawGeoJson = geojsonTextarea.value;

        try {
            const parsed = JSON.parse(rawGeoJson);
            rawGeoJson = JSON.stringify(parsed);
        } catch (err) {
            alert('GeoJSON ist ungültig und konnte nicht gespeichert werden.');
            console.error(err);
            return;
        }

        const context = container.dataset.context || 'leitstelle';
        const action = (context === 'neben') ? 'lsttraining_save_neben_einsatzgebiet' : 'lsttraining_save_einsatzgebiet';

        const bodyData = {
            action: action,
            geojson: rawGeoJson
        };
        bodyData[context === 'neben' ? 'neben_id' : 'leitstelle_id'] = leitstelleId;

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(bodyData)
        })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    alert('Einsatzgebiet gespeichert');
                    container.style.display = 'none';

                    if (context === 'leitstelle' && window.mapEdit) {
                        const format = new ol.format.GeoJSON();
                        const editFeatures = format.readFeatures(rawGeoJson, {
                            featureProjection: window.mapEdit.getView().getProjection()
                        });

                        const editLayers = window.mapEdit.getLayers().getArray();
                        const polygonLayer = editLayers.find(layer => layer.get('isPolygonLayer'));

                        if (polygonLayer) {
                            polygonLayer.getSource().clear();
                            polygonLayer.getSource().addFeatures(editFeatures);
                        } else {
                            const newPolygonLayer = new ol.layer.Vector({
                                source: new ol.source.Vector({ features: editFeatures }),
                                style: new ol.style.Style({
                                    stroke: new ol.style.Stroke({ color: 'rgba(0, 128, 255, 0.8)', width: 2 }),
                                    fill: new ol.style.Fill({ color: 'rgba(0, 128, 255, 0.2)' })
                                })
                            });
                            newPolygonLayer.set('isPolygonLayer', true);
                            window.mapEdit.addLayer(newPolygonLayer);
                        }

                        const newExtent = ol.extent.createEmpty();
                        editFeatures.forEach(f => ol.extent.extend(newExtent, f.getGeometry().getExtent()));
                        if (!ol.extent.isEmpty(newExtent)) {
                            window.mapEdit.getView().fit(newExtent, {
                                padding: [50, 50, 50, 50],
                                duration: 300,
                                maxZoom: 8
                            });
                        }
                    }
                } else {
                    alert('Fehler: ' + result.data);
                }
            });
    });

	requestAnimationFrame(() => {
    container.style.display = "block";
    map.updateSize(); // <== wichtig!
	});

    importButton?.addEventListener('click', () => {
    const input = manualTextarea?.value.trim();
    if (!input) {
        alert("Bitte GeoJSON einfügen.");
        return;
    }

    try {
        const parsed = JSON.parse(input);

        if (parsed.type !== 'FeatureCollection' || !Array.isArray(parsed.features)) {
            alert("Ungültiges GeoJSON: Es muss ein FeatureCollection-Objekt mit 'features' sein.");
            console.warn("GeoJSON-Struktur falsch:", parsed);
            return;
        }

        const features = format.readFeatures(parsed, {
            featureProjection: map.getView().getProjection()
        });

        if (features.length === 0) {
            alert("Keine Features im GeoJSON enthalten.");
            return;
        }

        vectorSource.clear();
        vectorSource.addFeatures(features);
        updateGeoJSON();

        const extent = vectorSource.getExtent();
        if (!ol.extent.isEmpty(extent)) {
            map.getView().fit(extent, {
                padding: [40, 40, 40, 40],
                duration: 300,
                maxZoom: 8
            });
        }

        if (deleteButton) {
            deleteButton.style.display = 'inline-block';
        }

        alert("GeoJSON erfolgreich übernommen.");
    } catch (err) {
        alert("GeoJSON ist ungültig oder konnte nicht geparst werden.");
        console.error("Fehler beim Parsen:", err);
    }
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
