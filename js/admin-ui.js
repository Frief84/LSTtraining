// public/js/admin-ui.js

window.mapNeu = null;
window.mapEdit = null;
window.dragInteractionNeu = null;
window.dragInteractionEdit = null;

window.initMapWithMarker = function(mapId, latInput, lonInput, initialCoords, assignMap, assignInteraction) {
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

    const vectorSource = new ol.source.Vector({ features: [feature] });
    const vectorLayer = new ol.layer.Vector({ source: vectorSource });

    const map = new ol.Map({
        target: mapId,
        layers: [new ol.layer.Tile({ source: new ol.source.OSM() }), vectorLayer],
        view: new ol.View({ center: coord, zoom: 8 })
    });

    const drag = new ol.interaction.Modify({ source: vectorSource });
    drag.on('modifyend', function (e) {
        const lonLat = ol.proj.toLonLat(e.features.item(0).getGeometry().getCoordinates());
        document.getElementById(latInput).value = lonLat[1].toFixed(6);
        document.getElementById(lonInput).value = lonLat[0].toFixed(6);
    });
    map.addInteraction(drag);

    window[assignMap] = map;
    window[assignInteraction] = drag;
};

window.openCreateForm = function () {
    const createForm = document.getElementById('neue-leitstelle-formular');
    const editForm = document.getElementById('edit-leitstelle-formular');

    editForm.style.display = 'none';
    createForm.style.display = 'block';

    // Reset fields
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

window.editLeitstelle = function (id, name, ort, bl, land, lat, lon, geojson) {
    console.log('editLeitstelle wurde aufgerufen', id);

    const createForm = document.getElementById('neue-leitstelle-formular');
    const editForm = document.getElementById('edit-leitstelle-formular');

    createForm.style.display = 'none';
    editForm.style.display = 'block';

    document.getElementById('lst_update_id').value = id;
    document.getElementById('lst_update_name').value = name;
    document.getElementById('lst_update_ort').value = ort;
    document.getElementById('lst_update_bl').value = bl;
    document.getElementById('lst_update_land').value = land;
    document.getElementById('lst_update_lat').value = lat;
    document.getElementById('lst_update_lon').value = lon;
    document.getElementById('geojson_edit').value = geojson || '';

    document.getElementById('edit-leitstelle-formular').scrollIntoView({ behavior: 'smooth' });

    setTimeout(() => {
        if (window.mapEdit) {
            window.mapEdit.setTarget(null);
            window.mapEdit = null;
        }

        document.getElementById('map_edit').innerHTML = '';
        window.initMapWithMarker('map_edit', 'lst_update_lat', 'lst_update_lon', [parseFloat(lon), parseFloat(lat)], 'mapEdit', 'dragInteractionEdit');
    }, 100);

    // Optional: polygon-editor öffnen
    setTimeout(() => {
        const btn = document.querySelector('#edit-leitstelle-formular button[onclick*="Einsatzgebiet bearbeiten"]');
        if (btn && btn.offsetParent !== null) {
            btn.click();
        }
    }, 300);
};
