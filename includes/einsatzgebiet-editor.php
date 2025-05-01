<?php
function lsttraining_einsatzgebiet_editor($mapId = 'polygon_map', $inputId = 'einsatzgebiet_geojson', $geojson = '', $leitstelle_id = 0, $context = 'leitstelle', $center = '') {
    $uid = uniqid();
    $dataContext = $context === 'neben' ? 'neben' : 'leitstelle';

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
    data-leitstelle-id="' . intval($leitstelle_id) . '"
    data-context="' . esc_attr($dataContext) . '"
    data-center="' . esc_attr($center) . '">';

    echo '<h3>Einsatzgebiet bearbeiten</h3>';
    echo '<div style="background:#eef3f9; border:1px solid #cce; padding:10px; margin-bottom:15px;">';
    echo '<strong>Hinweise zur Bearbeitung:</strong>';
    echo '<ul style="margin-top:5px;">
            <li><strong>Linksklick</strong> in der Karte fügt einen Punkt zum Polygon hinzu.</li>
            <li><strong>Rechtsklick</strong> entfernt den letzten Punkt oder löscht das Polygon.</li>
            <li>Ein bestehendes GeoJSON kann unten eingefügt und übernommen werden.</li>
            <li>Für externe Bearbeitung kannst du das Tool <a href="https://opendatalab.de/projects/geojson-utilities/" target="_blank">GeoJSON Utilities</a> nutzen.</li>
          </ul>';
    echo '</div>';

    echo '<div id="' . esc_attr($mapId) . '" style="height: 300px; border: 1px solid #ccc; margin-bottom: 10px;"></div>';
    echo '<textarea name="' . esc_attr($inputId) . '" id="' . esc_attr($inputId) . '" style="display:none">' . esc_textarea($geojson) . '</textarea>';

    echo '<p>
        <button type="button" class="button button-primary btn-einsatzgebiet-save">Speichern</button>
        <button type="button" class="button btn-einsatzgebiet-close">Schließen</button>
        <button type="button" class="button btn-einsatzgebiet-delete" style="display:none">Einsatzgebiet löschen</button>
    </p>';

    echo '<div style="margin-top: 15px;">
        <label for="manual_geojson"><strong>GeoJSON manuell einfügen:</strong></label><br>
        <textarea id="manual_geojson" style="width: 100%; height: 100px;"></textarea><br>
        <button type="button" class="button" id="btn-geojson-import">GeoJSON übernehmen</button>
    </div>';

    echo '</div>'; // popup schließen

    // Der Button initialisiert die Karte nur bei Bedarf
    echo '<button type="button" class="button" onclick="
        const popup = document.getElementById(\'popup_' . $uid . '\');
        if (!popup.dataset.initialized) {
            window.initEinsatzgebietEditor(popup);
        } else {
            popup.style.display = \'block\';
        }
    ">Einsatzgebiet bearbeiten</button>';
}
?>
