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

window.toggleForm = function() {
    document.getElementById('neue-leitstelle-formular').style.display = 'block';
    if (!window.mapNeu) {
        setTimeout(() => window.initMapWithMarker('map_neu', 'lst_neu_lat', 'lst_neu_lon', [13.4, 52.5], 'mapNeu', 'dragInteractionNeu'), 100);
    }
};

window.editLeitstelle = function(id, name, ort, bl, land, lat, lon) {
    document.getElementById('lst_update_id').value = id;
    document.getElementById('lst_update_name').value = name;
    document.getElementById('lst_update_ort').value = ort;
    document.getElementById('lst_update_bl').value = bl;
    document.getElementById('lst_update_land').value = land;
    document.getElementById('lst_update_lat').value = lat;
    document.getElementById('lst_update_lon').value = lon;
    document.getElementById('edit-leitstelle-formular').style.display = 'block';

    setTimeout(() => window.initMapWithMarker('map_edit', 'lst_update_lat', 'lst_update_lon', [parseFloat(lon), parseFloat(lat)], 'mapEdit', 'dragInteractionEdit'), 100);
    window.scrollTo(0, document.getElementById('edit-leitstelle-formular').offsetTop);
};
