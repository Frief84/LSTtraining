// public/js/admin-ui.js

window.mapNeu = null;
window.mapEdit = null;
window.dragInteractionNeu = null;
window.dragInteractionEdit = null;

window.initMapWithMarker = function(mapId, latInput, lonInput, initialCoords, assignMap, assignInteraction, polygonGeoJson = null) {
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
    drag.on('modifyend', function (e) {
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

window.openCreateForm = function () {
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

window.editLeitstelle = function (id, name, ort, bl, land, lat, lon) {
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
    document.getElementById('geojson_edit').value = '';

    document.getElementById('edit-leitstelle-formular').scrollIntoView({ behavior: 'smooth' });

    fetch(`${ajaxurl}?action=lsttraining_get_einsatzgebiet&leitstelle_id=${id}&t=${Date.now()}`)
        .then(r => r.json())
        .then(result => {
            let polygon = null;

            if (result.success && result.data) {
                try {
                    polygon = typeof result.data === 'string' ? JSON.parse(result.data) : result.data;
                    const geojsonString = JSON.stringify(polygon);
                    document.getElementById('geojson_edit').value = geojsonString;
                    console.log('GeoJSON geladen:', polygon);
                } catch (e) {
                    console.warn('GeoJSON konnte nicht geparst werden', e);
                }
            }

            window.initMapWithMarker(
                'map_edit',
                'lst_update_lat',
                'lst_update_lon',
                [parseFloat(lon), parseFloat(lat)],
                'mapEdit',
                'dragInteractionEdit',
                polygon ? JSON.stringify(polygon) : null
            );

            // Einsatzgebiet-Editor initialisieren (falls vorhanden)
            const popup = document.querySelector('.einsatzgebiet-popup');
            if (popup && typeof window.initEinsatzgebietEditor === 'function') {
                window.initEinsatzgebietEditor(popup);
            }
        });
};

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.edit-leitstelle').forEach(btn => {
    btn.addEventListener('click', function (e) {
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
