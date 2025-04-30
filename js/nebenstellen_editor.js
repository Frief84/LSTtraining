window.initNebenstelleMap = function(gps, geojson = null, hauptLat = null, hauptLon = null) {

    let lat = 51.0, lon = 10.0; // Deutschland-Mitte
	if (gps && gps.includes(',')) {
		const coords = gps.split(',').map(parseFloat);
		if (!isNaN(coords[0]) && !isNaN(coords[1])) {
			lat = coords[0];
			lon = coords[1];
		}
	}
    const view = new ol.View({ center: ol.proj.fromLonLat([lon, lat]), zoom: 11 });

    const nebenMarker = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat]))
    });
    nebenMarker.setStyle(new ol.style.Style({
        image: new ol.style.RegularShape({
            points: 30,
            radius: 8,
            fill: new ol.style.Fill({ color: '#ff0000' }),
            stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
        })
    }));
	window.nebenMarkerFeature = nebenMarker;
    const vectorSource = new ol.source.Vector({ features: [nebenMarker] });
    const markerLayer = new ol.layer.Vector({ source: vectorSource });

    const baseLayer = new ol.layer.Tile({ source: new ol.source.OSM() });

    const map = new ol.Map({
        target: 'nebenstelle_map',
        layers: [baseLayer, markerLayer],
        view: view
    });

    map.on('click', function (e) {
        const coords = ol.proj.toLonLat(e.coordinate);
        const [lonNew, latNew] = coords;
        nebenMarker.setGeometry(new ol.geom.Point(e.coordinate));
        document.getElementById('neben_update_gps').value = latNew.toFixed(6) + ',' + lonNew.toFixed(6);
    });
	
	// Drag & Drop für Nebenstellen-Marker
const drag = new ol.interaction.Modify({ source: vectorSource });
drag.on('modifyend', function (e) {
    const coord = e.features.item(0).getGeometry().getCoordinates();
    const [lon, lat] = ol.proj.toLonLat(coord);
    document.getElementById('neben_update_gps').value = lat.toFixed(6) + ',' + lon.toFixed(6);
});
map.addInteraction(drag);

    // GeoJSON Gebiet laden
		if (geojson && geojson.trim() !== '') {
		try {
			let parsed = JSON.parse(geojson);
			if (Array.isArray(parsed)) {
				parsed = {
					type: 'FeatureCollection',
					features: parsed
				};
			}

			const format = new ol.format.GeoJSON();
			const features = format.readFeatures(parsed, {
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
			map.addLayer(polygonLayer);

			const extent = polygonSource.getExtent();
			if (!ol.extent.isEmpty(extent)) {
				map.getView().fit(extent, {
					padding: [20, 20, 20, 20],
					duration: 500
				});
			} else {
				console.warn("Kein darstellbares Polygon vorhanden – extent ist leer");
				map.getView().setZoom(6);
			}
		} catch (e) {
			console.warn("Ungültiges GeoJSON für Nebenstelle", e);
		}
	}

    // Hauptleitstelle anzeigen (optional)
    if (hauptLat && hauptLon) {
        const leitFeature = new ol.Feature({
            geometry: new ol.geom.Point(ol.proj.fromLonLat([parseFloat(hauptLon), parseFloat(hauptLat)]))
        });
        leitFeature.setStyle(new ol.style.Style({
            image: new ol.style.Circle({
                radius: 6,
                fill: new ol.style.Fill({ color: 'blue' }),
                stroke: new ol.style.Stroke({ color: '#fff', width: 1 })
            })
        }));
        const leitLayer = new ol.layer.Vector({
            source: new ol.source.Vector({ features: [leitFeature] })
        });
        map.addLayer(leitLayer);
    }

    window.nebenstelleMap = map;
};

document.getElementById('neben_update_gps')?.addEventListener('blur', function () {
    const val = this.value.trim();
    if (!val.includes(',')) return;

    const [lat, lon] = val.split(',').map(x => parseFloat(x));
    if (isNaN(lat) || isNaN(lon)) return;

    const coord = ol.proj.fromLonLat([lon, lat]);
    if (window.nebenstelleMap && window.nebenMarkerFeature) {
        window.nebenMarkerFeature.setGeometry(new ol.geom.Point(coord));
        window.nebenstelleMap.getView().setCenter(coord);
    }
});

window.editNebenstelle = function(id, name, zust, einwohner, flaeche, gps, nachbar, geojson) {
    document.getElementById('neben_update_id').value = id;
    document.getElementById('neben_update_name').value = name;
    document.getElementById('neben_update_zustandigkeit').value = zust;
    document.getElementById('neben_update_einwohner').value = einwohner;
    document.getElementById('neben_update_flaeche').value = flaeche;
    document.getElementById('neben_update_gps').value = gps;
    document.getElementById('neben_update_nachbar').value = nachbar;
    document.getElementById('geojson_edit').value = geojson;

    document.getElementById('popup-overlay').style.display = 'block';
    document.getElementById('edit-nebenstelle-formular').style.display = 'block';

    // ggf. alte Karte entfernen
    if (window.nebenstelleMap) {
        window.nebenstelleMap.setTarget(null);
        window.nebenstelleMap = null;
    }
    document.getElementById('nebenstelle_map').innerHTML = '';

    setTimeout(() => {
        window.initNebenstelleMap(gps, geojson);
    }, 200);
};

window.closeNebenstellePopup = function () {
    document.getElementById('popup-overlay')?.style.setProperty('display', 'none');
    document.getElementById('edit-nebenstelle-formular')?.style.setProperty('display', 'none');
};
	
	var feld = document.getElementById('neben_update_nachbar');
if (feld && feld.closest) {
    feld.closest('tr').style.display = 'none';
}


	