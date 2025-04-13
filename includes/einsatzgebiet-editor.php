<?php
// einsatzgebiet-editor.php

function lsttraining_einsatzgebiet_editor($mapId = 'polygon_map', $inputId = 'einsatzgebiet_geojson', $geojson = '') {
    $uid = uniqid(); // generiert eindeutige ID je Instanz

    echo '<div style="margin-top: 20px;">';
    echo '<label><strong>Einsatzgebiet:</strong></label>';
    echo '<p>Klicke auf „Einsatzgebiet bearbeiten“, um ein Polygon zu zeichnen oder zu bearbeiten.</p>';
    echo '<button type="button" class="button" onclick="document.getElementById(\'container_' . $uid . '\').style.display = (document.getElementById(\'container_' . $uid . '\').style.display === \'none\' ? \'block\' : \'none\')">Einsatzgebiet bearbeiten</button>';
    echo '<div id="container_' . $uid . '" style="display:none; margin-top: 15px;">';
    echo '<div id="' . esc_attr($mapId) . '" style="height: 300px; border: 1px solid #ccc;"></div>';
    echo '<textarea name="' . esc_attr($inputId) . '" id="' . esc_attr($inputId) . '" style="display:none">' . esc_textarea($geojson) . '</textarea>';
    echo '</div>';

    echo '<link rel="stylesheet" href="' . plugin_dir_url(__FILE__) . '../openlayers/ol.css">';
    echo '<script src="' . plugin_dir_url(__FILE__) . '../openlayers/ol.js"></script>';

    echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        const format = new ol.format.GeoJSON();
        const vectorSource = new ol.source.Vector();

        const map = new ol.Map({
            target: '" . esc_js($mapId) . "',
            layers: [
                new ol.layer.Tile({ source: new ol.source.OSM() }),
                new ol.layer.Vector({ source: vectorSource })
            ],
            view: new ol.View({ center: ol.proj.fromLonLat([13.4, 52.5]), zoom: 7 })
        });

        const draw = new ol.interaction.Draw({ source: vectorSource, type: 'Polygon' });
        const modify = new ol.interaction.Modify({ source: vectorSource });

        map.addInteraction(draw);
        map.addInteraction(modify);

        function updateGeoJSON() {
            const features = vectorSource.getFeatures();
            const geojson = format.writeFeatures(features, {
                featureProjection: map.getView().getProjection()
            });
            document.getElementById('" . esc_js($inputId) . "').value = geojson;
        }

        draw.on('drawend', updateGeoJSON);
        modify.on('modifyend', updateGeoJSON);

        const existing = document.getElementById('" . esc_js($inputId) . "').value;
        if (existing) {
            const features = format.readFeatures(existing, {
                featureProjection: map.getView().getProjection()
            });
            vectorSource.addFeatures(features);
        }
    });
    </script>";
}
