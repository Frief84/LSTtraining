<?php
// einsatzgebiet-editor.php

function lsttraining_einsatzgebiet_editor(
    $mapId = 'polygon_map',
    $inputId = 'einsatzgebiet_geojson',
    $geojson = '',
    $leitstelle_id = 0,
    $context = 'leitstelle',
    $center = ''
) {
    static $popup_rendered = false;

    $dataContext = ($context === 'neben') ? 'neben' : 'leitstelle';

    // Das Hidden-Feld muss pro Formular existieren (das bleibt wie bisher)
    echo '<textarea id="' . esc_attr($inputId) . '" class="einsatzgebiet-geojson-hidden" style="display:none;">' . esc_textarea($geojson) . '</textarea>';

    // Popup wirklich nur einmal im DOM rendern
    if ($popup_rendered) {
        return;
    }
    $popup_rendered = true;

    echo '<div id="lst-eg-popup" class="einsatzgebiet-popup"
        data-map-id="' . esc_attr($mapId) . '"
        data-geojson-id="' . esc_attr($inputId) . '"
        data-leitstelle-id="' . intval($leitstelle_id) . '"
        data-context="' . esc_attr($dataContext) . '"
        data-center="' . esc_attr($center) . '"
        data-geojson="' . esc_attr($geojson) . '"
        style="display:none;"
    >';

    echo '<div class="einsatzgebiet-popup__header">';
    echo '<h3 class="einsatzgebiet-popup__title">Einsatzgebiet bearbeiten</h3>';
    echo '<button type="button" class="button btn-einsatzgebiet-close">Schließen</button>';
    echo '</div>';

    echo '<div class="einsatzgebiet-hinweise">
        <strong>Hinweise zur Bearbeitung:</strong>
        <ul>
            <li><strong>Linksklick</strong> in der Karte fügt einen Punkt zum Polygon hinzu.</li>
            <li><strong>Rechtsklick</strong> entfernt den letzten Punkt oder löscht das Polygon.</li>
            <li>Import: Bitte entweder Datei wählen oder das Textfeld nutzen (nicht beides).</li>
            <li>Für externe Bearbeitung kannst du das Tool
                <a href="https://opendatalab.de/projects/geojson-utilities/" target="_blank" rel="noopener noreferrer">GeoJSON Utilities</a>
                nutzen.
            </li>
        </ul>
    </div>';

    echo '<div class="einsatzgebiet-popup__body">';

    echo '<div class="einsatzgebiet-map-wrap">
        <div data-einsatzgebiet-map class="einsatzgebiet-map" id="' . esc_attr($mapId) . '"></div>
    </div>';

    echo '<div class="einsatzgebiet-import">
        <label class="einsatzgebiet-label"><strong>GeoJSON importieren (Datei ODER Textfeld):</strong></label>

        <div class="einsatzgebiet-import__file">
            <label><strong>GeoJSON-Datei</strong></label><br>
            <input type="file"
                data-eg-file
                accept=".geojson,application/geo+json,application/json"
                class="regular-text">
        </div>

        <div class="einsatzgebiet-import__manual">
            <label><strong>Oder GeoJSON einfügen</strong></label><br>
            <textarea data-eg-manual class="einsatzgebiet-manual" rows="7" placeholder="GeoJSON hier einfügen ..."></textarea>
        </div>

        <div class="einsatzgebiet-import__actions">
            <button type="button" data-eg-process class="button button-primary" disabled>
                GeoJSON verarbeiten (Turf) &amp; übernehmen
            </button>
            <p class="description">
                Hinweis: Speichern im Popup schreibt direkt in die DB.
            </p>
        </div>
    </div>';

    echo '<div class="einsatzgebiet-actions">
        <button type="button" class="button button-primary btn-einsatzgebiet-save">Speichern</button>
        <button type="button" class="button btn-einsatzgebiet-close">Schließen</button>
        <button type="button" class="button btn-einsatzgebiet-delete">Einsatzgebiet löschen</button>
    </div>';

    echo '</div>'; // body
    echo '</div>'; // popup
}
