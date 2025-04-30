<?php
// einsatzgebiet-editor.php

function lsttraining_einsatzgebiet_editor($mapId = 'polygon_map', $inputId = 'einsatzgebiet_geojson', $geojson = '', $leitstelle_id = 0) {
    $uid = uniqid();

    echo '<div id="popup_' . $uid . '" class="einsatzgebiet-popup" style="
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
    "
    data-map-id="' . esc_attr($mapId) . '"
    data-geojson-id="' . esc_attr($inputId) . '"
    data-leitstelle-id="' . intval($leitstelle_id) . '">';

    echo '<h3>Einsatzgebiet bearbeiten</h3>';
	echo '<div style="background:#eef3f9; border:1px solid #cce; padding:10px; margin-bottom:15px;">';
	echo '<strong>Hinweise zur Bearbeitung:</strong>';
	echo '<ul style="margin-top:5px;">';
	echo '<li><strong>Linksklick</strong> in der Karte fügt einen Punkt zum Polygon hinzu.</li>';
	echo '<li><strong>Rechtsklick</strong> entfernt den letzten Punkt oder löscht das Polygon.</li>';
	echo '<li>Ein bestehendes GeoJSON kann unten eingefügt und übernommen werden.</li>';
	echo '<li>Für externe Bearbeitung kannst du das Tool <a href="https://opendatalab.de/projects/geojson-utilities/" target="_blank">GeoJSON Utilities</a> nutzen.</li>';
	echo '</ul>';
	echo '</div>';

    echo '<div id="' . esc_attr($mapId) . '" style="height: 300px; border: 1px solid #ccc; margin-bottom: 10px;"></div>';
    echo '<textarea name="' . esc_attr($inputId) . '" id="' . esc_attr($inputId) . '" style="display:none">' . esc_textarea($geojson) . '</textarea>';
    echo '<p>
        <button type="button" class="button button-primary btn-einsatzgebiet-save">Speichern</button>
        <button type="button" class="button btn-einsatzgebiet-close">Schließen</button>
		<button type="button" class="button btn-einsatzgebiet-delete" style="display:none">Einsatzgebiet löschen</button>
    </p>';
	echo '<div style="margin-top: 15px;">';
	echo '<label for="manual_geojson"><strong>GeoJSON manuell einfügen:</strong></label><br>';
	echo '<textarea id="manual_geojson" style="width: 100%; height: 100px;"></textarea>';
	echo '<button type="button" class="button" id="btn-geojson-import">GeoJSON übernehmen</button>';
	echo '</div>';

    echo '</div>';

    echo '<button type="button" class="button" onclick="document.getElementById(\'popup_' . $uid . '\').style.display = \'block\'">Einsatzgebiet bearbeiten</button>';
}
