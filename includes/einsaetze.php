<?php
/**
 * Einsatzeditor für LST Training
 *
 * Ziel:
 * - fachliche Definition von Einsatzvorlagen
 * - Verwaltung von Zeitfenstern, Jahreszeiten, Wetterbedingungen,
 *   modularen Anrufer-Bausteinen und Follow-ups
 *
 * Hinweis:
 * Diese Datei setzt die finale Zielstruktur voraus:
 *   - einsaetze
 *   - einsatz_time_windows
 *   - einsatz_seasons
 *   - einsatz_weather_conditions
 *   - einsatz_caller_parts
 *   - einsatz_followups
 *
 * Sie ist bewusst an die vorhandene Struktur von leitstellen_editor.php / hospitals.php angepasst:
 * WordPress-Adminseite + wp.template() + AJAX via admin-ajax.php.
 */

if (!defined('ABSPATH')) { exit; }

$plugin_root = dirname(__DIR__);
$poi_types = [];
$poi_json = $plugin_root . '/data/poi_types.json';

if (is_readable($poi_json)) {
    $tmp = json_decode((string) file_get_contents($poi_json), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (isset($tmp['types']) && is_array($tmp['types'])) {
            foreach ($tmp['types'] as $row) {
                $poi_types[] = [
                    'tag'         => isset($row['tag']) ? (string) $row['tag'] : '',
                    'description' => isset($row['description']) ? (string) $row['description'] : '',
                    'color'       => isset($row['color']) ? (string) $row['color'] : '',
                ];
            }
        } elseif (is_array($tmp)) {
            foreach ($tmp as $row) {
                if (is_array($row)) {
                    $poi_types[] = [
                        'tag'         => isset($row['tag']) ? (string) $row['tag'] : '',
                        'description' => isset($row['description']) ? (string) $row['description'] : '',
                        'color'       => isset($row['color']) ? (string) $row['color'] : '',
                    ];
                }
            }
        }
    }
}
?>
<div class="wrap lst-admin-page lst-einsaetze-page">
    <h1 class="wp-heading-inline">Einsätze</h1>
    <a href="#" class="page-title-action" id="lst-einsatz-new">Neu hinzufügen</a>
    <hr class="wp-header-end">

    <div class="lst-card lst-einsatz-toolbar">
        <div class="lst-toolbar-row">
            <div class="lst-field">
                <label for="lst-einsatz-search"><strong>Suche</strong></label>
                <input type="text" id="lst-einsatz-search" class="regular-text" placeholder="Titel, Beschreibung, Typ oder ID">
            </div>

            <div class="lst-field">
                <label for="lst-einsatz-filter-art"><strong>Einsatzart</strong></label>
                <select id="lst-einsatz-filter-art">
                    <option value="">Alle</option>
                    <option value="RD">RD</option>
                    <option value="FW">FW</option>
                </select>
            </div>

            <div class="lst-field">
                <label for="lst-einsatz-filter-enabled"><strong>Status</strong></label>
                <select id="lst-einsatz-filter-enabled">
                    <option value="">Alle</option>
                    <option value="1">Aktiv</option>
                    <option value="0">Inaktiv</option>
                </select>
            </div>

            <div class="lst-toolbar-spacer"></div>

            <div class="lst-spinner-wrap">
                <span class="spinner is-active" id="lst-einsatz-list-spinner" style="visibility:hidden;"></span>
            </div>
        </div>
    </div>

    <div class="lst-card">
        <table class="widefat fixed striped" id="lst-einsatz-table">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Titel</th>
                    <th style="width:90px;">Art</th>
                    <th>Einsatztyp</th>
                    <th style="width:100px;">Ort</th>
                    <th style="width:90px;">Aktiv</th>
                    <th style="width:220px;">Aktionen</th>
                </tr>
            </thead>
            <tbody id="lst-einsatz-table-body">
                <tr>
                    <td colspan="7">Keine Einträge geladen.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="lst-modal hidden" id="lst-einsatz-modal" aria-hidden="true">
    <div class="modal-overlay"></div>
    <div class="modal-content lst-modal-lg">
        <div class="modal-header">
            <h2 id="lst-einsatz-modal-title">Einsatz</h2>
            <button type="button" class="modal-close" aria-label="Schließen">×</button>
        </div>

        <div class="modal-body"></div>
    </div>
</div>

<script type="text/html" id="tmpl-lst-einsatz-table-rows">
<# if (!data.items || !data.items.length) { #>
    <tr>
        <td colspan="7">Keine Einsätze gefunden.</td>
    </tr>
<# } else { #>
    <# _.each(data.items, function(item) { #>
        <tr>
            <td>{{ item.id }}</td>
            <td>
                <strong>{{ item.title || '' }}</strong>
                <# if (item.description) { #>
                    <div class="lst-muted">{{ item.description }}</div>
                <# } #>
            </td>
            <td>{{ item.einsatzart || '' }}</td>
            <td>{{ item.einsatztyp || '' }}</td>
            <td>{{ item.scope_type || '' }}</td>
            <td>
                <# if (String(item.enabled) === '1') { #>
                    <span class="lst-status-pill is-enabled">Ja</span>
                <# } else { #>
                    <span class="lst-status-pill is-disabled">Nein</span>
                <# } #>
            </td>
            <td>
                <button type="button" class="button button-small lst-einsatz-edit" data-id="{{ item.id }}">Bearbeiten</button>
                <button type="button" class="button button-small lst-einsatz-copy" data-id="{{ item.id }}">Kopieren</button>
                <button type="button" class="button button-small lst-einsatz-delete" data-id="{{ item.id }}">Löschen</button>
            </td>
        </tr>
    <# }); #>
<# } #>
</script>

<script type="text/html" id="tmpl-lst-einsatz-editor">
<form id="lst-einsatz-form" class="lst-einsatz-form" onsubmit="return false;">
    <input type="hidden" id="lst-einsatz-id" value="{{ data.id || '' }}">
    <input type="hidden" id="lst-einsatz-editor-mode" value="{{ data.__editor_mode || (data.id ? 'edit' : 'create') }}">

    <#
        var editorMode = data.__editor_mode || (data.id ? 'edit' : 'create');
        var editorTitle = editorMode === 'copy' ? 'Einsatz kopieren' : (data.id ? 'Einsatz bearbeiten' : 'Einsatz anlegen');
    #>

    <div class="lst-wizard-head">
        <div>
            <span class="lst-wizard-kicker">Einsatz-Editor</span>
            <h2>{{ editorTitle }}</h2>
            <p>Führe den Einsatz Schritt für Schritt durch: Was passiert, wo und wann, wer ruft an, welche Kräfte werden gebraucht und welche Varianten können später eintreten.</p>
        </div>
        <aside class="lst-wizard-summary" aria-live="polite">
            <strong>Zusammenfassung</strong>
            <dl>
                <div><dt>Titel</dt><dd data-lst-summary="title">-</dd></div>
                <div><dt>Ort</dt><dd data-lst-summary="scope">-</dd></div>
                <div><dt>Grundbedarf</dt><dd data-lst-summary="resources">-</dd></div>
                <div><dt>Varianten</dt><dd data-lst-summary="followups">-</dd></div>
            </dl>
        </aside>
    </div>

    <div class="lst-tabs">
        <div class="lst-tab-nav">
            <button type="button" class="lst-tab-btn is-active" data-tab="general"><span>1</span><strong>Grunddaten</strong><em>Art, Titel, Stichwort</em></button>
            <button type="button" class="lst-tab-btn" data-tab="location"><span>2</span><strong>Einsatzort</strong><em>Gebiet, POI oder Karte</em></button>
            <button type="button" class="lst-tab-btn" data-tab="time"><span>3</span><strong>Zeit &amp; Bedingungen</strong><em>Zeitfenster, Saison, Wetter</em></button>
            <button type="button" class="lst-tab-btn" data-tab="caller"><span>4</span><strong>Anruf</strong><em>Profile und Meldungsbausteine</em></button>
            <button type="button" class="lst-tab-btn" data-tab="situation"><span>5</span><strong>Lage &amp; Bedarf</strong><em>Startlage und Fahrzeuge</em></button>
            <button type="button" class="lst-tab-btn" data-tab="followups"><span>6</span><strong>Lagevarianten</strong><em>Rückmeldungen und Zusatzbedarf</em></button>
        </div>

        <div class="lst-tab-panel is-active" data-tab-panel="general">
            <div class="lst-step-intro">
                <h3>Was ist das für ein Einsatz?</h3>
                <p>Diese Angaben sieht man später in Listen, Einsatzkarten und als Einsatzstichwort.</p>
            </div>
            <div class="lst-grid lst-grid-2">
                <div class="lst-field">
                    <label for="lst-title"><strong>Anzeigename des Einsatzes</strong></label>
                    <input type="text" id="lst-title" class="regular-text" value="{{ data.title || '' }}">
                </div>

                <div class="lst-field">
                    <label for="lst-einsatztyp"><strong>Einsatzstichwort / Typ</strong></label>
                    <input type="text" id="lst-einsatztyp" class="regular-text" value="{{ data.einsatztyp || '' }}">
                </div>

                <div class="lst-field">
                    <label for="lst-einsatzart"><strong>Fachbereich</strong></label>
                    <select id="lst-einsatzart">
                        <option value="RD" <# if ((data.einsatzart || 'RD') === 'RD') { #>selected<# } #>>Rettungsdienst</option>
                        <option value="FW" <# if ((data.einsatzart || '') === 'FW') { #>selected<# } #>>Feuerwehr</option>
                    </select>
                </div>

                <div class="lst-field lst-field-check">
                    <label>
                        <input type="checkbox" id="lst-enabled" value="1" <# if (String(data.enabled || '1') === '1') { #>checked<# } #>>
                        Einsatz aktiv
                    </label>
                </div>
            </div>

            <div class="lst-field">
                <label for="lst-description"><strong>Interne Beschreibung</strong></label>
                <textarea id="lst-description" rows="4" class="large-text">{{ data.description || '' }}</textarea>
            </div>

            <details class="lst-expert-box">
                <summary>Expertenfeld: interne Tags</summary>
                <div class="lst-field">
                    <label for="lst-tags-json"><strong>Tags JSON</strong></label>
                    <input type="text" id="lst-tags-json" class="large-text code" value="{{ data.tags_json || '' }}" placeholder='["verkehr", "schule"]'>
                    <p class="description">Nur nötig, wenn du Einsatzvorlagen zusätzlich maschinell verschlagworten willst.</p>
                </div>
            </details>
        </div>

        <div class="lst-tab-panel" data-tab-panel="location" style="display:none;">
            <div class="lst-step-intro">
                <h3>Wo darf dieser Einsatz entstehen?</h3>
                <p>Wähle, ob die Simulation den Ort frei auswählt, Gebietsdaten nutzt, einen POI sucht oder einen festen Kartenpunkt verwendet.</p>
            </div>
            <div class="lst-field">
                <label><strong>Ortsmodus</strong></label>
                <div class="lst-radio-group">
                    <label><input type="radio" name="lst_scope_type" value="anywhere" <# if ((data.scope_type || 'anywhere') === 'anywhere') { #>checked<# } #>> Überall im Einsatzgebiet</label>
                    <label><input type="radio" name="lst_scope_type" value="landscape" <# if ((data.scope_type || '') === 'landscape') { #>checked<# } #>> Nach Gebietstyp</label>
                    <label><input type="radio" name="lst_scope_type" value="poi_type" <# if ((data.scope_type || '') === 'poi_type') { #>checked<# } #>> An bestimmtem POI-Typ</label>
                    <label><input type="radio" name="lst_scope_type" value="fixed_point" <# if ((data.scope_type || '') === 'fixed_point') { #>checked<# } #>> Fester Punkt auf Karte</label>
                </div>
            </div>

            <div class="lst-scope-panel" data-scope-panel="anywhere">
                <p class="description">Der Einsatz kann überall im Leitstellengebiet entstehen.</p>
            </div>

            <div class="lst-scope-panel" data-scope-panel="landscape" style="display:none;">
    <div class="lst-box">
        <h3>Gebietstypen</h3>
        <#
            var landscapeSelected = [];
            if (data.landscape_tags_json) {
                try {
                    landscapeSelected = JSON.parse(data.landscape_tags_json);
                } catch (e) {
                    landscapeSelected = [];
                }
            }

            var landscapeOptions = [
    { key: 'residential', label: 'Wohngebiet' },
    { key: 'industrial', label: 'Industrie' },
    { key: 'commercial', label: 'Gewerbe' },
    { key: 'retail', label: 'Einzelhandel' },

    { key: 'allotments', label: 'Kleingärten' },
    { key: 'farmland', label: 'Ackerland' },
    { key: 'animal_keeping', label: 'Tierhaltung' },

    { key: 'forest', label: 'Wald' },
    { key: 'logging', label: 'Forstwirtschaft' },
    { key: 'meadow', label: 'Wiese' },

    { key: 'recreation_ground', label: 'Freizeitfläche' },

    { key: 'roads_lines', label: 'Straßen' },
    { key: 'roads_motorway', label: 'Autobahn' },
    { key: 'railway', label: 'Bahngelände' },
    { key: 'cemetery', label: 'Friedhof' },
    { key: 'landfill', label: 'Deponie' },
    { key: 'quarry', label: 'Steinbruch' },

    { key: 'religious', label: 'Religiöse Fläche' }
];
        #>

        <div class="lst-check-grid lst-landscape-grid">
            <# _.each(landscapeOptions, function(opt) { #>
                <label>
                    <input type="checkbox"
                           class="lst-landscape-tag"
                           value="{{ opt.key }}"
                           <# if (_.contains(landscapeSelected, opt.key)) { #>checked<# } #>>
                    {{ opt.label }}
                    <span class="lst-code-inline">{{ opt.key }}</span>
                </label>
            <# }); #>
        </div>

        <p class="description">Mehrfachauswahl möglich. Die Simulation sucht einen passenden Ort in diesen Gebietstypen.</p>
    </div>
</div>

            <div class="lst-scope-panel" data-scope-panel="poi_type" style="display:none;">
                <div class="lst-field">
                    <label for="lst-poi-type"><strong>POI-Typ / Objektart</strong></label>
                    <select id="lst-poi-type">
                        <option value="">Bitte wählen</option>
                        <# _.each(data.poi_types || [], function(poi) { #>
                            <option value="{{ poi.tag }}" <# if ((data.poi_type || '') === poi.tag) { #>selected<# } #>>
                                {{ poi.tag }}<# if (poi.description) { #> - {{ poi.description }}<# } #>
                            </option>
                        <# }); #>
                    </select>
                </div>
            </div>

            <div class="lst-scope-panel" data-scope-panel="fixed_point" style="display:none;">
                <div class="lst-grid lst-grid-3">
                    <div class="lst-field">
                        <label for="lst-fixed-latitude"><strong>Breitengrad</strong></label>
                        <input type="text" id="lst-fixed-latitude" class="regular-text" value="{{ data.fixed_latitude || '' }}">
                    </div>

                    <div class="lst-field">
                        <label for="lst-fixed-longitude"><strong>Längengrad</strong></label>
                        <input type="text" id="lst-fixed-longitude" class="regular-text" value="{{ data.fixed_longitude || '' }}">
                    </div>

                    <div class="lst-field">
                        <label for="lst-fixed-radius"><strong>Radius in m</strong></label>
                        <input type="number" id="lst-fixed-radius" class="small-text" min="0" step="1" value="{{ data.fixed_radius_m || '' }}">
                    </div>
                </div>

                <div id="lst-einsatz-map" class="lst-map-box"></div>
                <p class="description">Per Klick auf die Karte wird der Punkt gesetzt.</p>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="time" style="display:none;">
    <div class="lst-step-intro">
        <h3>Wann ist dieser Einsatz plausibel?</h3>
        <p>Ohne Einschränkung ist der Einsatz immer möglich. Zeitfenster, Jahreszeiten und Wetter grenzen ihn ein.</p>
    </div>
    <div class="lst-box-header">
        <h3>Zeitfenster</h3>
        <button type="button" class="button button-secondary" id="lst-add-time-window">Zeitfenster hinzufügen</button>
    </div>

    <table class="widefat striped lst-subtable" id="lst-time-window-table">
        <thead>
            <tr>
                <th style="width:180px;">Tagtyp</th>
                <th style="width:160px;">Start</th>
                <th style="width:160px;">Ende</th>
                <th style="width:100px;">Aktion</th>
            </tr>
        </thead>
        <tbody>
            <# if (!data.time_windows || !data.time_windows.length) { #>
                <tr class="lst-time-window-empty-row">
                    <td colspan="4">Noch keine Zeitfenster vorhanden.</td>
                </tr>
            <# } else { #>
                <# _.each(data.time_windows, function(row) { #>
                    <tr class="lst-time-window-row">
                        <td>
                            <select class="lst-day-type">
                                <option value="any" <# if ((row.day_type || 'any') === 'any') { #>selected<# } #>>Alle</option>
                                <option value="weekday" <# if ((row.day_type || '') === 'weekday') { #>selected<# } #>>Werktag</option>
                                <option value="weekend" <# if ((row.day_type || '') === 'weekend') { #>selected<# } #>>Wochenende</option>
                                <option value="monday" <# if ((row.day_type || '') === 'monday') { #>selected<# } #>>Montag</option>
                                <option value="tuesday" <# if ((row.day_type || '') === 'tuesday') { #>selected<# } #>>Dienstag</option>
                                <option value="wednesday" <# if ((row.day_type || '') === 'wednesday') { #>selected<# } #>>Mittwoch</option>
                                <option value="thursday" <# if ((row.day_type || '') === 'thursday') { #>selected<# } #>>Donnerstag</option>
                                <option value="friday" <# if ((row.day_type || '') === 'friday') { #>selected<# } #>>Freitag</option>
                                <option value="saturday" <# if ((row.day_type || '') === 'saturday') { #>selected<# } #>>Samstag</option>
                                <option value="sunday" <# if ((row.day_type || '') === 'sunday') { #>selected<# } #>>Sonntag</option>
                            </select>
                        </td>
                        <td>
                            <input type="time" class="lst-start-time" value="{{ row.start_time || '' }}">
                        </td>
                        <td>
                            <input type="time" class="lst-end-time" value="{{ row.end_time || '' }}">
                        </td>
                        <td>
                            <button type="button" class="button-link-delete lst-remove-row">Entfernen</button>
                        </td>
                    </tr>
                <# }); #>
            <# } #>
        </tbody>
    </table>

    <p class="description">Keine Einträge bedeuten: zeitlich immer zulässig.</p>

    <div class="lst-grid lst-grid-2 lst-condition-grid">
        <div class="lst-box">
            <h3>Jahreszeiten</h3>
            <p class="description">Keine Auswahl bedeutet: alle Jahreszeiten.</p>
            <div class="lst-check-grid">
                <label><input type="checkbox" class="lst-season" value="spring" <# if (_.contains(data.seasons || [], 'spring')) { #>checked<# } #>> Frühling</label>
                <label><input type="checkbox" class="lst-season" value="summer" <# if (_.contains(data.seasons || [], 'summer')) { #>checked<# } #>> Sommer</label>
                <label><input type="checkbox" class="lst-season" value="autumn" <# if (_.contains(data.seasons || [], 'autumn')) { #>checked<# } #>> Herbst</label>
                <label><input type="checkbox" class="lst-season" value="winter" <# if (_.contains(data.seasons || [], 'winter')) { #>checked<# } #>> Winter</label>
            </div>
        </div>

        <div class="lst-box">
            <h3>Wetter</h3>
            <p class="description">Keine Auswahl bedeutet: jedes Wetter.</p>
            <div class="lst-check-grid">
                <label><input type="checkbox" class="lst-weather" value="clear" <# if (_.contains(data.weather_conditions || [], 'clear')) { #>checked<# } #>> Klar</label>
                <label><input type="checkbox" class="lst-weather" value="cloudy" <# if (_.contains(data.weather_conditions || [], 'cloudy')) { #>checked<# } #>> Bewölkt</label>
                <label><input type="checkbox" class="lst-weather" value="rain" <# if (_.contains(data.weather_conditions || [], 'rain')) { #>checked<# } #>> Regen</label>
                <label><input type="checkbox" class="lst-weather" value="snow" <# if (_.contains(data.weather_conditions || [], 'snow')) { #>checked<# } #>> Schnee</label>
                <label><input type="checkbox" class="lst-weather" value="storm" <# if (_.contains(data.weather_conditions || [], 'storm')) { #>checked<# } #>> Sturm</label>
                <label><input type="checkbox" class="lst-weather" value="windy" <# if (_.contains(data.weather_conditions || [], 'windy')) { #>checked<# } #>> Windig</label>
                <label><input type="checkbox" class="lst-weather" value="fog" <# if (_.contains(data.weather_conditions || [], 'fog')) { #>checked<# } #>> Nebel</label>
                <label><input type="checkbox" class="lst-weather" value="cold" <# if (_.contains(data.weather_conditions || [], 'cold')) { #>checked<# } #>> Kalt</label>
                <label><input type="checkbox" class="lst-weather" value="hot" <# if (_.contains(data.weather_conditions || [], 'hot')) { #>checked<# } #>> Heiß</label>
            </div>
        </div>
    </div>
</div>

       
<div class="lst-tab-panel lst-tab-panel--caller" data-tab-panel="caller" style="display:none;">
    <div class="lst-step-intro">
        <h3>Wie klingt der Notruf?</h3>
        <p>Wähle passende Anruferprofile und baue die Meldung aus Problem, Beobachtung und Zusatzinfo zusammen.</p>
    </div>
    <div class="lst-editor-split lst-editor-split--caller">
        <div class="lst-editor-column">
            <div class="lst-box">
                <h3>Anrufer-Typen</h3>
                <p class="description">Hier wählst du aus, welche zentralen Anruferprofile zu diesem Einsatz passen.</p>
                <div id="lst-einsatz-profile-assignment">
                    <p class="description">Die Profilzuordnung wird hier angebunden.</p>
                </div>
            </div>
        </div>

        <div class="lst-editor-column">
            <# var meldungsPartDefs = [
                { key: 'problem', label: 'Anrufgrund / Problem' },
                { key: 'observation', label: 'Beobachtung' },
                { key: 'extra', label: 'Zusatzinfo' }
            ]; #>

            <# _.each(meldungsPartDefs, function(def) { #>
                <div class="lst-box">
                    <div class="lst-box-header">
                        <h3>{{ def.label }}</h3>
                        <button type="button" class="button button-secondary lst-einsatz-add-part" data-part-key="{{ def.key }}">Baustein hinzufügen</button>
                    </div>

                    <table class="widefat striped lst-subtable lst-einsatz-part-table" data-part-key="{{ def.key }}">
                        <thead>
                            <tr>
                                <th>Text</th>
                                <th style="width:120px;">Sortierung</th>
                                <th style="width:120px;">Aktiv</th>
                                <th style="width:100px;">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <# if (!data.caller_parts || !data.caller_parts[def.key] || !data.caller_parts[def.key].length) { #>
                                <tr class="lst-einsatz-part-empty-row">
                                    <td colspan="4">Noch keine Bausteine.</td>
                                </tr>
                            <# } else { #>
                                <# _.each(data.caller_parts[def.key], function(row) { #>
                                    <tr class="lst-einsatz-part-row">
                                        <td><input type="text" class="regular-text lst-einsatz-part-text" value="{{ row.text || '' }}"></td>
                                        <td><input type="number" min="0" step="1" class="small-text lst-einsatz-part-sort-order" value="{{ row.sort_order || 0 }}"></td>
                                        <td><label><input type="checkbox" class="lst-einsatz-part-enabled" value="1" <# if (String(row.enabled || '1') === '1') { #>checked<# } #>> aktiv</label></td>
                                        <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
                                    </tr>
                                <# }); #>
                            <# } #>
                        </tbody>
                    </table>
                </div>
            <# }); #>

            <div class="lst-box">
                <div class="lst-box-header">
                    <h3>Vorschau</h3>
                    <button type="button" class="button button-secondary" id="lst-generate-caller-preview">3 Beispiele generieren</button>
                </div>
                <ol id="lst-caller-preview-list" class="lst-preview-list"></ol>
            </div>
        </div>
    </div>
</div>

        <div class="lst-tab-panel lst-tab-panel--situation" data-tab-panel="situation" style="display:none;">
            <div class="lst-step-intro">
                <h3>Welche Lage und welchen Grundbedarf hat der Einsatz?</h3>
                <p>Diese Startlage erscheint in der Einsatzkarte. Der Fahrzeugbedarf beschreibt, was fachlich gebraucht wird; alarmiert wird später in der Simulation.</p>
            </div>
            <div class="lst-situation-flow">
                <div class="lst-box">
                    <h3>Start-Lagebeschreibung</h3>
                    <div class="lst-field">
                        <label for="lst-lagemeldung"><strong>Sichtbarer Lage-/Beschreibungstext</strong></label>
                        <textarea id="lst-lagemeldung" rows="4" class="large-text">{{ data.lagemeldung || '' }}</textarea>
                    </div>
                </div>

                <div class="lst-box">
                    <h3>Patienten und Rettungsmittel</h3>
                    <input type="hidden" id="lst-patientenzahl" value="{{ data.patientenzahl || 0 }}">
                    <input type="hidden" id="lst-patient-ktw" value="0">
                    <input type="hidden" id="lst-patient-rtw" value="0">
                    <input type="hidden" id="lst-patient-notarzt" value="0">
                    <input type="hidden" id="lst-patient-anforderung" value="{{ data.patient_anforderung || '' }}">
                    <input type="hidden" id="lst-notarzt-benoetigt" value="{{ String(data.notarzt_benoetigt || '0') === '1' ? '1' : '0' }}">
                    <input type="hidden" id="lst-feuerwehr-benoetigt" value="{{ String(data.feuerwehr_benoetigt || '0') === '1' ? '1' : '0' }}">
                    <div class="lst-patient-editor">
                        <div class="lst-box-header">
                            <h4>Patienten</h4>
                            <button type="button" class="button button-secondary lst-add-patient" data-patient-target="#lst-base-patient-list">Patient hinzufügen</button>
                        </div>
                        <p class="description">Jede Zeile beschreibt einen Patienten. Rettungsmittel und optionales Klinikziel werden je Patient festgelegt. 0 % = verstorben, ab Zielwert transportbereit.</p>
                        <div class="lst-patient-editor-list" id="lst-base-patient-list"></div>
                    </div>
                </div>

                <div class="lst-box lst-resource-editor" id="lst-base-required-resources">
                    <div class="lst-box-header">
                        <h3>Zusätzlicher Sonderbedarf</h3>
                        <button type="button" class="button button-secondary lst-add-resource" data-target="#lst-base-resource-list">Fahrzeugklasse hinzufügen</button>
                    </div>
                    <p class="description">Nur zusätzliche Fahrzeuge eintragen, die nicht bereits aus Patienten, KTW, RTW und Notarztbedarf entstehen.</p>
                    <div class="lst-resource-list" id="lst-base-resource-list"></div>
                    <table class="widefat striped lst-resource-summary">
                        <thead>
                            <tr>
                                <th>Fahrzeugklasse</th>
                                <th style="width:120px;">Anzahl</th>
                            </tr>
                        </thead>
                        <tbody id="lst-base-resource-summary">
                            <tr><td colspan="2">Noch keine Fahrzeuge ausgewählt.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="followups" style="display:none;">
            <div class="lst-step-intro">
                <h3>Was kann sich nach Einsatzbeginn ändern?</h3>
                <p>Lagevarianten können nach Eintreffen einer Einheit eine S5-Rückmeldung erzeugen, die sichtbare Lage aktualisieren und zusätzlichen Kräftebedarf auslösen.</p>
            </div>
            <div class="lst-box-header">
                <h3>Lagevarianten / Rückmeldungen</h3>
                <button type="button" class="button button-secondary" id="lst-add-followup">Lagevariante hinzufügen</button>
            </div>

            <div id="lst-followup-cards" class="lst-followup-cards">
                <# if (!data.followups || !data.followups.length) { #>
                    <div class="lst-followup-empty-row lst-empty-state">
                        <h4>Noch keine Lagevarianten</h4>
                        <p>Lege eine Variante an, wenn sich die Lage nach Einsatzbeginn ändern kann, z.B. durch eine Rückmeldung nach Eintreffen oder zusätzlichen Kräftebedarf.</p>
                        <button type="button" class="button button-primary lst-add-followup-inline">Erste Lagevariante hinzufügen</button>
                    </div>
                <# } else { #>
                    <# _.each(data.followups, function(row, index) { #>
                        <div class="lst-box lst-followup-card lst-followup-row" data-followup-index="{{ index }}">
                            <div class="lst-followup-card-head">
                                <div>
                                    <span class="lst-wizard-kicker">Lagevariante</span>
                                    <h3 data-followup-title>{{ row.label || ('Lagevariante ' + (index + 1)) }}</h3>
                                    <div class="lst-followup-chips" aria-live="polite">
                                        <span data-followup-chip="trigger">-</span>
                                        <span data-followup-chip="probability">-</span>
                                        <span data-followup-chip="resources">-</span>
                                    </div>
                                </div>
                                <button type="button" class="button-link-delete lst-remove-row">Entfernen</button>
                            </div>

                            <div class="lst-followup-section">
                                <h4>Wann tritt die Variante ein?</h4>
                                <div class="lst-grid lst-grid-3">
                                    <div class="lst-field">
                                        <label><strong>Name der Variante</strong></label>
                                        <input type="text" class="regular-text lst-followup-label" value="{{ row.label || '' }}" placeholder="z.B. Patient schwerer verletzt als gemeldet">
                                    </div>
                                    <div class="lst-field">
                                        <label><strong>Auslöser</strong></label>
                                        <select class="lst-followup-trigger">
                                            <option value="on_unit_arrival" <# if (row.trigger_mode === 'on_unit_arrival') { #>selected<# } #>>Nach Eintreffen einer Einheit</option>
                                            <option value="on_missing_resources" <# if (row.trigger_mode === 'on_missing_resources') { #>selected<# } #>>Wenn Kräfte fehlen</option>
                                            <option value="random" <# if (!row.trigger_mode || row.trigger_mode === 'random') { #>selected<# } #>>Zufällig im Zeitfenster</option>
                                            <option value="manual" <# if (row.trigger_mode === 'manual') { #>selected<# } #>>Manuell</option>
                                            <option value="on_dispatcher_question" <# if (row.trigger_mode === 'on_dispatcher_question') { #>selected<# } #>>Auf Nachfrage des Disponenten</option>
                                            <option value="on_transport_started" <# if (row.trigger_mode === 'on_transport_started') { #>selected<# } #>>Bei Transportbeginn</option>
                                            <option value="on_hospital_arrival" <# if (row.trigger_mode === 'on_hospital_arrival') { #>selected<# } #>>Bei Klinikankunft</option>
                                            <option value="on_vehicle_available" <# if (row.trigger_mode === 'on_vehicle_available') { #>selected<# } #>>Bei Freimeldung</option>
                                        </select>
                                    </div>
                                    <div class="lst-field">
                                        <label><strong>Wahrscheinlichkeit</strong></label>
                                        <select class="lst-followup-probability">
                                            <# _.each([100, 90, 75, 50, 25, 10, 0], function(prob) { #>
                                                <option value="{{ prob }}" <# if (String(row.probability_percent != null ? row.probability_percent : 100) === String(prob)) { #>selected<# } #>>{{ prob }} %</option>
                                            <# }); #>
                                        </select>
                                    </div>
                                    <div class="lst-field">
                                        <label><strong>Frühestens nach Sekunden</strong></label>
                                        <input type="number" min="0" step="1" class="small-text lst-followup-min" value="{{ row.min_after_sec || '' }}">
                                    </div>
                                    <div class="lst-field">
                                        <label><strong>Spätestens nach Sekunden</strong></label>
                                        <input type="number" min="0" step="1" class="small-text lst-followup-max" value="{{ row.max_after_sec || '' }}">
                                    </div>
                                </div>
                                <p class="description">Das Zeitfenster zählt je nach Auslöser ab Einsatzbeginn oder ab Eintreffen der ersten Einheit. Die Variante aktualisiert beim Auslösen die sichtbare Lagebeschreibung; bei 0 Sekunden sofort.</p>
                            </div>

                            <div class="lst-followup-section lst-situation-section">
                                <h4>Was findet das ersteintreffende Fahrzeug vor?</h4>
                                <div class="lst-field">
                                    <label><strong>Sichtbarer Lage-/Beschreibungstext</strong></label>
                                    <textarea class="large-text lst-followup-text" rows="5" placeholder="Beschreibe die Lage, die das ersteintreffende Fahrzeug per S5 meldet.">{{ row.text || '' }}</textarea>
                                </div>
                                <p class="description">Dieser Text wird als S5-Lagemeldung gesendet und ersetzt beim Eintreten der Variante die sichtbare Lagebeschreibung.</p>
                                <div class="lst-patient-editor">
                                    <div class="lst-box-header">
                                        <h4>Patienten in dieser Variante</h4>
                                        <button type="button" class="button button-secondary lst-add-patient" data-patient-target="[data-followup-index='{{ index }}'] .lst-followup-patient-list">Patient hinzufügen</button>
                                    </div>
                                    <p class="description">Diese Patientenzeilen bestimmen Triage, Zustand, Klinikziel und zusätzlichen Rettungsmittelbedarf dieser Lagevariante.</p>
                                    <div class="lst-patient-editor-list lst-followup-patient-list"></div>
                                </div>
                            </div>

                            <div class="lst-followup-section">
                                <div class="lst-box-header">
                                    <div>
                                        <h4>Zusätzlicher Sonderbedarf</h4>
                                        <p class="description">Nur zusätzliche Fahrzeuge eintragen, die nicht bereits aus Patienten, KTW, RTW und Notarztbedarf entstehen.</p>
                                    </div>
                                    <button type="button" class="button button-secondary lst-add-resource" data-target="[data-followup-index='{{ index }}'] .lst-followup-resource-list">Fahrzeugklasse hinzufügen</button>
                                </div>
                                <div class="lst-resource-list lst-followup-resource-list"></div>
                            </div>

                            <details class="lst-followup-expert">
                                <summary>Experteneinstellungen</summary>
                                <div class="lst-grid lst-grid-3">
                                    <div class="lst-field">
                                        <label><strong>Reihenfolge</strong></label>
                                        <input type="number" min="1" step="1" class="small-text lst-followup-step" value="{{ row.step_no || (index + 1) }}">
                                    </div>
                                    <div class="lst-field">
                                        <label><strong>Kommunikationsart</strong></label>
                                        <select class="lst-followup-kind">
                                            <option value="unit_report" <# if (row.kind === 'unit_report') { #>selected<# } #>>Rückmeldung Einheit</option>
                                            <option value="dispatcher_question" <# if (row.kind === 'dispatcher_question') { #>selected<# } #>>Rückfrage Disponent</option>
                                            <option value="caller_answer" <# if (row.kind === 'caller_answer') { #>selected<# } #>>Antwort Anrufer</option>
                                            <option value="update" <# if (!row.kind || row.kind === 'update') { #>selected<# } #>>Lage-Update</option>
                                        </select>
                                    </div>
                                    <div class="lst-field">
                                        <label><strong>Wer meldet sich?</strong></label>
                                        <select class="lst-followup-speaker">
                                            <option value="caller" <# if (row.speaker_type === 'caller') { #>selected<# } #>>Anrufer</option>
                                            <option value="fire_unit" <# if (row.speaker_type === 'fire_unit') { #>selected<# } #>>Feuerwehr</option>
                                            <option value="ems_unit" <# if (row.speaker_type === 'ems_unit') { #>selected<# } #>>Rettungsdienst</option>
                                            <option value="police" <# if (row.speaker_type === 'police') { #>selected<# } #>>Polizei</option>
                                            <option value="dispatch" <# if (row.speaker_type === 'dispatch') { #>selected<# } #>>Leitstelle</option>
                                            <option value="system" <# if (!row.speaker_type || row.speaker_type === 'system') { #>selected<# } #>>System</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="lst-followup-expert-subsection">
                                    <h4>Bedingungen</h4>
                                    <div class="lst-grid lst-grid-3">
                                        <label><input type="checkbox" class="lst-followup-cond-arrived"> Nur wenn Fahrzeug eingetroffen</label>
                                        <label><input type="checkbox" class="lst-followup-cond-missing"> Nur wenn Fahrzeug fehlt</label>
                                        <div class="lst-field">
                                            <label><strong>Fahrzeugklasse</strong></label>
                                            <select class="lst-followup-cond-type"></select>
                                        </div>
                                        <div class="lst-field">
                                            <label><strong>Mindestanzahl</strong></label>
                                            <input type="number" min="1" step="1" class="small-text lst-followup-cond-count" value="1">
                                        </div>
                                    </div>
                                </div>
                                <div class="lst-field">
                                    <label><strong>Optionale Folgeeffekte</strong></label>
                                    <textarea class="large-text lst-followup-effect-note" rows="2" placeholder="Optionaler Hinweis für spätere Simulationslogik"></textarea>
                                </div>
                            </details>
                        </div>
                    <# }); #>
                <# } #>
            </div>
        </div>
    </div>

    <div class="lst-modal-actions">
        <button type="button" class="button lst-wizard-prev" data-lst-wizard-prev>Zurück</button>
        <button type="button" class="button button-secondary lst-wizard-next" data-lst-wizard-next>Weiter</button>
        <button type="button" class="button" id="lst-einsatz-cancel">Abbrechen</button>
        <button type="submit" class="button button-primary" id="lst-einsatz-save">Speichern</button>
        <span class="spinner" id="lst-einsatz-save-spinner" style="visibility:hidden;"></span>
    </div>
</form>
</script>

<script>
window.lstEinsatzBootstrap = {
    poi_types: <?php echo wp_json_encode($poi_types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};
</script>
