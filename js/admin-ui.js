// public/js/admin-ui.js

window.mapNeu = null;
window.mapEdit = null;
window.dragInteractionNeu = null;
window.dragInteractionEdit = null;

window.initMapWithMarker = function(mapId, latInput, lonInput, initialCoords, assignMap, assignInteraction, polygonGeoJson = null) {
    if (window[assignMap]) {
        window[assignMap].setTarget(null);
        window[assignMap] = null;
    }

    const container = document.getElementById(mapId);
    if (container) container.innerHTML = '';

    const coord = ol.proj.fromLonLat(initialCoords);
    const feature = new ol.Feature({ geometry: new ol.geom.Point(coord) });
    feature.setStyle(new ol.style.Style({
        image: new ol.style.RegularShape({
            points: 30,
            radius: 8,
            fill: new ol.style.Fill({ color: '#ff0000' }),
            stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
        })
    }));

    const markerSource = new ol.source.Vector({ features: [feature] });
    const markerLayer = new ol.layer.Vector({ source: markerSource });

    const baseLayer = new ol.layer.Tile({ source: new ol.source.OSM() });

    const map = new ol.Map({
        target: mapId,
        layers: [baseLayer, markerLayer],
        view: new ol.View({ center: coord, zoom: 8 })
    });

    const drag = new ol.interaction.Modify({ source: markerSource });
    drag.on('modifyend', function(e) {
        const lonLat = ol.proj.toLonLat(e.features.item(0).getGeometry().getCoordinates());
        document.getElementById(latInput).value = lonLat[1].toFixed(6);
        document.getElementById(lonInput).value = lonLat[0].toFixed(6);
    });
    map.addInteraction(drag);

    if (polygonGeoJson && polygonGeoJson.trim() !== '') {
        try {
            const format = new ol.format.GeoJSON();
            const features = format.readFeatures(polygonGeoJson, { featureProjection: map.getView().getProjection() });
            const polygonSource = new ol.source.Vector({ features });

            const polygonLayer = new ol.layer.Vector({
                source: polygonSource,
                style: new ol.style.Style({
                    stroke: new ol.style.Stroke({ color: 'rgba(0, 128, 255, 0.8)', width: 2 }),
                    fill: new ol.style.Fill({ color: 'rgba(0, 128, 255, 0.2)' })
                })
            });
            polygonLayer.set('isPolygonLayer', true);
            map.addLayer(polygonLayer);
            map.getView().fit(polygonSource.getExtent(), { padding: [20, 20, 20, 20], duration: 500 });
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

    ['name', 'ort', 'bl', 'land', 'lat', 'lon'].forEach(field => {
        const el = createForm.querySelector(`input[name="lst_neu_${field}"]`);
        if (el) el.value = '';
    });
    document.getElementById('geojson_neu').value = '';

    if (!window.mapNeu) {
        setTimeout(() => {
            window.initMapWithMarker('map_neu', 'lst_neu_lat', 'lst_neu_lon', [13.4, 52.5], 'mapNeu', 'dragInteractionNeu');
        }, 100);
    }
};

window.editLeitstelle = function(id, name, ort, bl, land, lat, lon) {
    const createForm = document.getElementById('neue-leitstelle-formular');
    const editForm = document.getElementById('edit-leitstelle-formular');

    if (editForm.style.display === 'block') {
        alert('Es wird bereits eine Leitstelle bearbeitet.');
        return;
    }

    if (createForm) createForm.style.display = 'none';
    if (editForm) editForm.style.display = 'block';

    ['id', 'name', 'ort', 'bl', 'land', 'lat', 'lon'].forEach(key => {
        const el = document.getElementById(`lst_update_${key}`);
        if (el) el.value = eval(key);
    });

    const geojsonEl = document.getElementById('geojson_edit');
    if (geojsonEl) geojsonEl.value = '';

    // Einsatzgebiet-Button aktualisieren
    const egBtn = editForm.querySelector('.open-einsatzgebiet-editor');
    if (egBtn) {
        const center = `${lat},${lon}`;
        egBtn.dataset.mapId = `einsatzgebiet_edit_${id}`;
        egBtn.dataset.geojson = '';
        egBtn.dataset.leitstelleId = id;
        egBtn.dataset.center = center;
        egBtn.dataset.context = 'leitstelle';
    }

    editForm.scrollIntoView({ behavior: 'smooth' });

    fetch(`${ajaxurl}?action=lsttraining_get_einsatzgebiet&leitstelle_id=${id}&t=${Date.now()}`)
        .then(r => r.json())
        .then(result => {
            let polygon = result.success && result.data ? result.data : null;
            if (polygon && typeof polygon === 'string') polygon = JSON.parse(polygon);
            if (polygon && polygon.type === 'FeatureCollection') {
                const geojsonStr = JSON.stringify(polygon);
                if (geojsonEl) geojsonEl.value = geojsonStr;

                // Setze GeoJSON auch in Button, falls vorhanden
                if (egBtn) {
                    egBtn.dataset.geojson = geojsonStr;
                }
            }

            window.initMapWithMarker(
                'map_edit', 'lst_update_lat', 'lst_update_lon', [parseFloat(lon), parseFloat(lat)],
                'mapEdit', 'dragInteractionEdit',
                polygon && polygon.type === 'FeatureCollection' ? JSON.stringify(polygon) : null
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


document.addEventListener('click', function (e) {
    const btn = e.target.closest('.open-einsatzgebiet-editor');
    if (!btn) return;

    const mapId = btn.dataset.mapId;
    const popupId = 'popup_' + mapId;
    const geojsonId = 'geojson_' + mapId;
    const context = btn.dataset.context;
    const leitstelleId = btn.dataset.leitstelleId;

    let geojsonUrl = '';
    if (context === 'neben') {
        geojsonUrl = `${ajaxurl}?action=lsttraining_get_neben_einsatzgebiet&neben_id=${leitstelleId}`;
    } else {
        geojsonUrl = `${ajaxurl}?action=lsttraining_get_einsatzgebiet&leitstelle_id=${leitstelleId}`;
    }

    fetch(geojsonUrl)
        .then(r => r.json())
        .then(result => {
            if (!result.success || !result.data) {
                console.warn('Kein GeoJSON geladen');
                return;
            }

            // Editor dynamisch nachladen
            fetch(`${ajaxurl}?action=lsttraining_render_einsatzgebiet_editor&map_id=${mapId}&input_id=${geojsonId}&leitstelle_id=${leitstelleId}&context=${context}&center=${btn.dataset.center}`)
                .then(r => r.text())
                .then(html => {
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    const popup = wrapper.firstElementChild;
                    document.body.appendChild(popup);

                    // Setze GeoJSON ins versteckte Textfeld
                    const geoEl = document.getElementById(geojsonId);
                    if (geoEl) geoEl.value = JSON.stringify(result.data);

                    popup.style.display = 'block';

                    if (typeof window.initEinsatzgebietEditor === 'function') {
                        window.initEinsatzgebietEditor(popup);
                    }
                });
        });
});



