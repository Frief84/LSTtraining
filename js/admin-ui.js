// public/js/admin-ui.js

window.mapNeu = null;
window.mapEdit = null;
window.dragInteractionNeu = null;
window.dragInteractionEdit = null;

window.initMapWithMarker = function(mapId, latInput, lonInput, initialCoords, assignMap, assignInteraction, polygonGeoJson = null) {

    if (window[assignMap]) {
        window[assignMap].setTarget(null); // trennt alte Karte vom DOM
        window[assignMap] = null;
    }
    const container = document.getElementById(mapId);
    if (container) container.innerHTML = ''; // räumt das Map-Container-DIV auf

    const coord = ol.proj.fromLonLat(initialCoords);
    const feature = new ol.Feature({
        geometry: new ol.geom.Point(coord)
    });
    feature.setStyle(new ol.style.Style({
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

    const markerSource = new ol.source.Vector({
        features: [feature]
    });
    const markerLayer = new ol.layer.Vector({
        source: markerSource
    });

    const baseLayer = new ol.layer.Tile({
        source: new ol.source.OSM()
    });

    const map = new ol.Map({
        target: mapId,
        layers: [baseLayer, markerLayer],
        view: new ol.View({
            center: coord,
            zoom: 8
        })
    });

    const drag = new ol.interaction.Modify({
        source: markerSource
    });
    drag.on('modifyend', function(e) {
        const lonLat = ol.proj.toLonLat(e.features.item(0).getGeometry().getCoordinates());
        document.getElementById(latInput).value = lonLat[1].toFixed(6);
        document.getElementById(lonInput).value = lonLat[0].toFixed(6);
    });
    map.addInteraction(drag);

    // Optionales Polygon (nicht editierbar)
    if (polygonGeoJson && polygonGeoJson.trim() !== '') {
        try {
            const format = new ol.format.GeoJSON();
            const features = format.readFeatures(polygonGeoJson, {
                featureProjection: map.getView().getProjection()
            });

            const polygonSource = new ol.source.Vector({
                features
            });

            const polygonLayer = new ol.layer.Vector({
                source: polygonSource,
                style: new ol.style.Style({
                    stroke: new ol.style.Stroke({
                        color: 'rgba(0, 128, 255, 0.8)',
                        width: 2
                    }),
                    fill: new ol.style.Fill({
                        color: 'rgba(0, 128, 255, 0.2)'
                    })
                })
            });
            polygonLayer.set('isPolygonLayer', true);
            map.addLayer(polygonLayer);

            map.getView().fit(polygonSource.getExtent(), {
                padding: [20, 20, 20, 20],
                duration: 500
            });
        } catch (err) {
            console.warn("Ungültiges GeoJSON", err);
        }
    }

    window[assignMap] = map;
    window[assignInteraction] = drag;
};

window.openCreateForm = function() {
    const createForm = document.getElementById('neue-leitstelle-formular');
    const editForm = document.getElementById('edit-leitstelle-formular');

    editForm.style.display = 'none';
    createForm.style.display = 'block';

    createForm.querySelector('input[name="lst_neu_name"]').value = '';
    createForm.querySelector('input[name="lst_neu_ort"]').value = '';
    createForm.querySelector('input[name="lst_neu_bl"]').value = '';
    createForm.querySelector('input[name="lst_neu_land"]').value = '';
    createForm.querySelector('input[name="lst_neu_lat"]').value = '';
    createForm.querySelector('input[name="lst_neu_lon"]').value = '';
    document.getElementById('geojson_neu').value = '';

    if (!window.mapNeu) {
        setTimeout(() => {
            window.initMapWithMarker('map_neu', 'lst_neu_lat', 'lst_neu_lon', [13.4, 52.5], 'mapNeu', 'dragInteractionNeu');
        }, 100);
    }
};

window.editLeitstelle = function(id, name, ort, bl, land, lat, lon) {
    console.log('editLeitstelle wurde aufgerufen', id);

    const createForm = document.getElementById('neue-leitstelle-formular');
    const editForm = document.getElementById('edit-leitstelle-formular');

    if (editForm.style.display === 'block') {
        alert('Es wird bereits eine Leitstelle bearbeitet.');
        return;
    }

    if (createForm) createForm.style.display = 'none';
    if (editForm) editForm.style.display = 'block';

    document.getElementById('lst_update_id').value = id;
    document.getElementById('lst_update_name').value = name;
    document.getElementById('lst_update_ort').value = ort;
    document.getElementById('lst_update_bl').value = bl;
    document.getElementById('lst_update_land').value = land;
    document.getElementById('lst_update_lat').value = lat;
    document.getElementById('lst_update_lon').value = lon;

    // Versuche, dynamisches GeoJSON-Feld zu finden und zu leeren (falls es schon existiert)
    const geojsonEl = document.querySelector('[id^="geojson_"]');
    if (geojsonEl) geojsonEl.value = '';

    document.getElementById('edit-leitstelle-formular').scrollIntoView({
        behavior: 'smooth'
    });

    fetch(`${ajaxurl}?action=lsttraining_get_einsatzgebiet&leitstelle_id=${id}&t=${Date.now()}`)
        .then(r => r.json())
        .then(result => {
            let polygon = null;

            if (result.success && result.data) {
                try {
                    polygon = typeof result.data === 'string' ?
                        JSON.parse(result.data) :
                        result.data;

                    if (polygon.type === 'FeatureCollection') {
                        const geojsonString = JSON.stringify(polygon);

                        // Setze GeoJSON in verstecktes Textfeld (wenn vorhanden)
                        const geojsonEl = document.querySelector('[id^="geojson_"]');
                        if (geojsonEl) geojsonEl.value = geojsonString;

                        const mapId = `einsatzgebiet_${id}`;
                        const geojsonId = `geojson_${mapId}`;
                        const container = document.getElementById(`popup_${mapId}`);
                        const map = window._openlayersMaps[mapId];

                        if (container && map) {
                            const geoField = document.getElementById(geojsonId);
                            if (geoField) geoField.value = geojsonString;

                            const features = new ol.format.GeoJSON().readFeatures(geojsonString, {
                                featureProjection: map.getView().getProjection()
                            });

                            const vectorLayer = map.getLayers().getArray().find(layer =>
                                layer instanceof ol.layer.Vector &&
                                !(layer.get('isMarker') || layer.get('isRedMarker'))
                            );

                            if (vectorLayer) {
                                vectorLayer.getSource().clear();
                                vectorLayer.getSource().addFeatures(features);

                                const extent = vectorLayer.getSource().getExtent();
                                if (!ol.extent.isEmpty(extent)) {
                                    map.getView().fit(extent, {
                                        padding: [50, 50, 50, 50],
                                        duration: 300,
                                        maxZoom: 8
                                    });
                                }
                            }
                        }


                        // Setze GeoJSON auf Button für späteres Popup-Öffnen
                        const openButton = document.querySelector(`.open-einsatzgebiet-editor[data-map-id="einsatzgebiet_${id}"]`);
                        if (openButton) {
                            openButton.dataset.geojson = geojsonString;
                        }

                        // Wenn das Popup bereits offen ist, versuche GeoJSON manuell in die Karte einzuladen
                        const mapInstance = window._openlayersMaps[`einsatzgebiet_${id}`];
                        if (mapInstance && polygon && polygon.type === "FeatureCollection") {
                            const features = new ol.format.GeoJSON().readFeatures(polygon, {
                                featureProjection: mapInstance.getView().getProjection()
                            });

                            const vectorLayer = mapInstance.getLayers().getArray().find(
                                l => l instanceof ol.layer.Vector && l.getSource() instanceof ol.source.Vector
                            );

                            if (vectorLayer) {
                                vectorLayer.getSource().clear();
                                vectorLayer.getSource().addFeatures(features);

                                const extent = vectorLayer.getSource().getExtent();
                                if (!ol.extent.isEmpty(extent)) {
                                    mapInstance.getView().fit(extent, {
                                        padding: [50, 50, 50, 50],
                                        duration: 300,
                                        maxZoom: 8
                                    });
                                }
                            }
                        }

                        console.log('GeoJSON geladen:', polygon);
                    } else {
                        console.warn('Kein gültiges GeoJSON FeatureCollection:', polygon);
                    }
                } catch (e) {
                    console.warn('GeoJSON konnte nicht geparst werden', e);
                }
            }

            // Initialisiere Karte mit Marker und optional Polygon
            window.initMapWithMarker(
                'map_edit',
                'lst_update_lat',
                'lst_update_lon',
                [parseFloat(lon), parseFloat(lat)],
                'mapEdit',
                'dragInteractionEdit',
                polygon && polygon.type === 'FeatureCollection' ?
                JSON.stringify(polygon) :
                null
            );
        });
};



document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-leitstelle').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            window.editLeitstelle(
                this.dataset.id,
                this.dataset.name,
                this.dataset.ort,
                this.dataset.bl,
                this.dataset.land,
                this.dataset.lat,
                this.dataset.lon
            );
        });
    });
});


document.addEventListener('click', function(e) {
    const btn = e.target.closest('.open-einsatzgebiet-editor');
    if (!btn) return;

    const mapId = btn.dataset.mapId;
    const geojsonId = 'geojson_' + mapId;

    // Falls bereits ein Popup für diese ID existiert
    const existingPopup = document.getElementById(`popup_${mapId}`);
    if (existingPopup) {
        existingPopup.style.display = 'block';
        return;
    }

    const container = document.createElement('div');
    container.id = `popup_${mapId}`;
    container.className = 'einsatzgebiet-popup';
    container.style = `
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -40%);
        z-index: 9999;
        background: #fff;
        border: 1px solid #ccc;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        padding: 20px;
        width: 650px;
        max-width: 95%;
        height: 750px;
        overflow: auto;
    `;

    container.dataset.mapId = mapId;
    container.dataset.geojsonId = geojsonId;
    container.dataset.leitstelleId = btn.dataset.leitstelleId;
    container.dataset.context = btn.dataset.context;
    container.dataset.center = btn.dataset.center;

    container.innerHTML = `
    <h3>Einsatzgebiet bearbeiten</h3>

    <div style="background:#eef3f9; border:1px solid #cce; padding:10px; margin-bottom:15px;">
        <strong>Hinweise zur Bearbeitung:</strong>
        <ul style="margin-top:5px;">
            <li><strong>Linksklick</strong> in der Karte fügt einen Punkt zum Polygon hinzu.</li>
            <li><strong>Rechtsklick</strong> entfernt den letzten Punkt oder löscht das Polygon.</li>
            <li>Ein bestehendes GeoJSON kann unten eingefügt und übernommen werden.</li>
            <li>Für externe Bearbeitung kannst du das Tool 
                <a href="https://opendatalab.de/projects/geojson-utilities/" target="_blank">GeoJSON Utilities</a> nutzen.
            </li>
        </ul>
    </div>

    <div id="${mapId}" style="height: 300px; border: 1px solid #ccc; margin-bottom: 10px;"></div>
    <textarea id="${geojsonId}" style="display:none"></textarea>
    <p>
        <button type="button" class="button button-primary btn-einsatzgebiet-save">Speichern</button>
        <button type="button" class="button btn-einsatzgebiet-close">Schließen</button>
        <button type="button" class="button btn-einsatzgebiet-delete" style="display:none">Einsatzgebiet löschen</button>
    </p>
    <div style="margin-top: 15px;">
        <label><strong>GeoJSON manuell einfügen:</strong></label><br>
        <textarea id="manual_geojson" style="width: 100%; height: 100px;"></textarea><br>
        <button type="button" class="button" id="btn-geojson-import">GeoJSON übernehmen</button>
    </div>
`;

    document.body.appendChild(container);
	
	// Setze das GeoJSON direkt in das versteckte Feld, bevor die Karte es liest
const geoField = document.getElementById(geojsonId);
if (geoField && btn.dataset.geojson) {
    geoField.value = btn.dataset.geojson;
}
    window.initEinsatzgebietEditor(container);
})