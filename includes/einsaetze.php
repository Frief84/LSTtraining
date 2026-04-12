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

    <div class="lst-tabs">
        <div class="lst-tab-nav">
            <button type="button" class="lst-tab-btn is-active" data-tab="general">Allgemein</button>
            <button type="button" class="lst-tab-btn" data-tab="location">Ort</button>
            <button type="button" class="lst-tab-btn" data-tab="time">Zeit</button>
            <button type="button" class="lst-tab-btn" data-tab="season_weather">Jahreszeiten &amp; Wetter</button>
            <button type="button" class="lst-tab-btn" data-tab="caller">Anruf</button>
            <button type="button" class="lst-tab-btn" data-tab="situation">Lage</button>
            <button type="button" class="lst-tab-btn" data-tab="followups">Follow-ups</button>
        </div>

        <div class="lst-tab-panel is-active" data-tab-panel="general">
            <div class="lst-grid lst-grid-2">
                <div class="lst-field">
                    <label for="lst-title"><strong>Titel</strong></label>
                    <input type="text" id="lst-title" class="regular-text" value="{{ data.title || '' }}">
                </div>

                <div class="lst-field">
                    <label for="lst-einsatztyp"><strong>Einsatztyp</strong></label>
                    <input type="text" id="lst-einsatztyp" class="regular-text" value="{{ data.einsatztyp || '' }}">
                </div>

                <div class="lst-field">
                    <label for="lst-einsatzart"><strong>Einsatzart</strong></label>
                    <select id="lst-einsatzart">
                        <option value="RD" <# if ((data.einsatzart || 'RD') === 'RD') { #>selected<# } #>>RD</option>
                        <option value="FW" <# if ((data.einsatzart || '') === 'FW') { #>selected<# } #>>FW</option>
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
                <label for="lst-description"><strong>Beschreibung</strong></label>
                <textarea id="lst-description" rows="4" class="large-text">{{ data.description || '' }}</textarea>
            </div>

            <div class="lst-field">
                <label for="lst-tags-json"><strong>Tags JSON</strong></label>
                <input type="text" id="lst-tags-json" class="large-text code" value="{{ data.tags_json || '' }}" placeholder='["verkehr", "schule"]'>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="location" style="display:none;">
            <div class="lst-field">
                <label><strong>Ortsmodus</strong></label>
                <div class="lst-radio-group">
                    <label><input type="radio" name="lst_scope_type" value="anywhere" <# if ((data.scope_type || 'anywhere') === 'anywhere') { #>checked<# } #>> anywhere</label>
                    <label><input type="radio" name="lst_scope_type" value="landscape" <# if ((data.scope_type || '') === 'landscape') { #>checked<# } #>> landscape</label>
                    <label><input type="radio" name="lst_scope_type" value="poi_type" <# if ((data.scope_type || '') === 'poi_type') { #>checked<# } #>> poi_type</label>
                    <label><input type="radio" name="lst_scope_type" value="fixed_point" <# if ((data.scope_type || '') === 'fixed_point') { #>checked<# } #>> fixed_point</label>
                </div>
            </div>

            <div class="lst-scope-panel" data-scope-panel="anywhere">
                <p class="description">Der Einsatz kann überall im Leitstellengebiet entstehen.</p>
            </div>

            <div class="lst-scope-panel" data-scope-panel="landscape" style="display:none;">
    <div class="lst-box">
        <h3>Landscape-Typen</h3>
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

        <p class="description">
            Mehrfachauswahl möglich. Intern wird daraus automatisch <code>landscape_tags_json</code> erzeugt.
        </p>
    </div>
</div>

            <div class="lst-scope-panel" data-scope-panel="poi_type" style="display:none;">
                <div class="lst-field">
                    <label for="lst-poi-type"><strong>POI-Typ</strong></label>
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
                        <label for="lst-fixed-latitude"><strong>Latitude</strong></label>
                        <input type="text" id="lst-fixed-latitude" class="regular-text" value="{{ data.fixed_latitude || '' }}">
                    </div>

                    <div class="lst-field">
                        <label for="lst-fixed-longitude"><strong>Longitude</strong></label>
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
                                <option value="any" <# if ((row.day_type || 'any') === 'any') { #>selected<# } #>>any</option>
                                <option value="weekday" <# if ((row.day_type || '') === 'weekday') { #>selected<# } #>>weekday</option>
                                <option value="weekend" <# if ((row.day_type || '') === 'weekend') { #>selected<# } #>>weekend</option>
                                <option value="monday" <# if ((row.day_type || '') === 'monday') { #>selected<# } #>>monday</option>
                                <option value="tuesday" <# if ((row.day_type || '') === 'tuesday') { #>selected<# } #>>tuesday</option>
                                <option value="wednesday" <# if ((row.day_type || '') === 'wednesday') { #>selected<# } #>>wednesday</option>
                                <option value="thursday" <# if ((row.day_type || '') === 'thursday') { #>selected<# } #>>thursday</option>
                                <option value="friday" <# if ((row.day_type || '') === 'friday') { #>selected<# } #>>friday</option>
                                <option value="saturday" <# if ((row.day_type || '') === 'saturday') { #>selected<# } #>>saturday</option>
                                <option value="sunday" <# if ((row.day_type || '') === 'sunday') { #>selected<# } #>>sunday</option>
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
</div>

        <div class="lst-tab-panel" data-tab-panel="season_weather" style="display:none;">
            <div class="lst-grid lst-grid-2">
                <div class="lst-box">
                    <h3>Jahreszeiten</h3>
                    <div class="lst-check-grid">
                        <label><input type="checkbox" class="lst-season" value="spring" <# if (_.contains(data.seasons || [], 'spring')) { #>checked<# } #>> spring</label>
                        <label><input type="checkbox" class="lst-season" value="summer" <# if (_.contains(data.seasons || [], 'summer')) { #>checked<# } #>> summer</label>
                        <label><input type="checkbox" class="lst-season" value="autumn" <# if (_.contains(data.seasons || [], 'autumn')) { #>checked<# } #>> autumn</label>
                        <label><input type="checkbox" class="lst-season" value="winter" <# if (_.contains(data.seasons || [], 'winter')) { #>checked<# } #>> winter</label>
                    </div>
                </div>

                <div class="lst-box">
                    <h3>Wetter</h3>
                    <div class="lst-check-grid">
                        <label><input type="checkbox" class="lst-weather" value="clear" <# if (_.contains(data.weather_conditions || [], 'clear')) { #>checked<# } #>> clear</label>
                        <label><input type="checkbox" class="lst-weather" value="cloudy" <# if (_.contains(data.weather_conditions || [], 'cloudy')) { #>checked<# } #>> cloudy</label>
                        <label><input type="checkbox" class="lst-weather" value="rain" <# if (_.contains(data.weather_conditions || [], 'rain')) { #>checked<# } #>> rain</label>
                        <label><input type="checkbox" class="lst-weather" value="snow" <# if (_.contains(data.weather_conditions || [], 'snow')) { #>checked<# } #>> snow</label>
                        <label><input type="checkbox" class="lst-weather" value="storm" <# if (_.contains(data.weather_conditions || [], 'storm')) { #>checked<# } #>> storm</label>
                        <label><input type="checkbox" class="lst-weather" value="windy" <# if (_.contains(data.weather_conditions || [], 'windy')) { #>checked<# } #>> windy</label>
                        <label><input type="checkbox" class="lst-weather" value="fog" <# if (_.contains(data.weather_conditions || [], 'fog')) { #>checked<# } #>> fog</label>
                        <label><input type="checkbox" class="lst-weather" value="cold" <# if (_.contains(data.weather_conditions || [], 'cold')) { #>checked<# } #>> cold</label>
                        <label><input type="checkbox" class="lst-weather" value="hot" <# if (_.contains(data.weather_conditions || [], 'hot')) { #>checked<# } #>> hot</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="caller" style="display:none;">
            <div class="lst-field">
                <label for="lst-caller-template"><strong>Caller-Template</strong></label>
                <textarea id="lst-caller-template" rows="3" class="large-text code">{{ data.caller_template_text || '{greeting} hier ist {person}. {location}. {problem}. {extra}' }}</textarea>
            </div>

            <div class="lst-caller-sections">
                <# var callerParts = data.caller_parts || {}; #>
                <# _.each(['greeting','person','location','problem','extra'], function(partKey) { #>
                    <div class="lst-box">
                        <div class="lst-box-header">
                            <h3>{{ partKey }}</h3>
                            <button type="button" class="button button-secondary lst-add-caller-part" data-part-key="{{ partKey }}">Baustein hinzufügen</button>
                        </div>

                        <table class="widefat striped lst-subtable lst-caller-part-table" data-part-key="{{ partKey }}">
                            <thead>
                                <tr>
                                    <th>Text</th>
                                    <th style="width:120px;">Sortierung</th>
                                    <th style="width:120px;">Aktiv</th>
                                    <th style="width:100px;">Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <# if (!callerParts[partKey] || !callerParts[partKey].length) { #>
                                    <tr class="lst-caller-empty-row">
                                        <td colspan="4">Noch keine Bausteine.</td>
                                    </tr>
                                <# } else { #>
                                    <# _.each(callerParts[partKey], function(row) { #>
                                        <tr class="lst-caller-part-row">
                                            <td><input type="text" class="regular-text lst-caller-part-text" value="{{ row.text || '' }}"></td>
                                            <td><input type="number" class="small-text lst-caller-part-sort-order" min="0" step="1" value="{{ row.sort_order || 0 }}"></td>
                                            <td><label><input type="checkbox" class="lst-caller-part-enabled" value="1" <# if (String(row.enabled || '1') === '1') { #>checked<# } #>> aktiv</label></td>
                                            <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
                                        </tr>
                                    <# }); #>
                                <# } #>
                            </tbody>
                        </table>
                    </div>
                <# }); #>
            </div>

            <div class="lst-box">
                <div class="lst-box-header">
                    <h3>Vorschau</h3>
                    <button type="button" class="button button-secondary" id="lst-generate-caller-preview">3 Beispiele generieren</button>
                </div>
                <ol id="lst-caller-preview-list" class="lst-preview-list"></ol>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="situation" style="display:none;">
            <div class="lst-field">
                <label for="lst-lagemeldung"><strong>Lagemeldung</strong></label>
                <textarea id="lst-lagemeldung" rows="4" class="large-text">{{ data.lagemeldung || '' }}</textarea>
            </div>

            <div class="lst-grid lst-grid-2">
                <div class="lst-field">
                    <label for="lst-patientenzahl"><strong>Patientenzahl</strong></label>
                    <input type="number" id="lst-patientenzahl" class="small-text" min="0" step="1" value="{{ data.patientenzahl || 0 }}">
                </div>

                <div class="lst-field">
                    <label for="lst-patient-anforderung"><strong>Patient-Anforderung</strong></label>
                    <input type="text" id="lst-patient-anforderung" class="regular-text" value="{{ data.patient_anforderung || '' }}">
                </div>
            </div>

            <div class="lst-check-grid">
                <label><input type="checkbox" id="lst-notarzt-benoetigt" value="1" <# if (String(data.notarzt_benoetigt || '0') === '1') { #>checked<# } #>> Notarzt benötigt</label>
                <label><input type="checkbox" id="lst-feuerwehr-benoetigt" value="1" <# if (String(data.feuerwehr_benoetigt || '0') === '1') { #>checked<# } #>> Feuerwehr benötigt</label>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="followups" style="display:none;">
            <div class="lst-box-header">
                <h3>Follow-ups</h3>
                <button type="button" class="button button-secondary" id="lst-add-followup">Follow-up hinzufügen</button>
            </div>

            <table class="widefat striped lst-subtable" id="lst-followup-table">
                <thead>
                    <tr>
                        <th style="width:80px;">Step</th>
                        <th style="width:180px;">Kind</th>
                        <th>Text</th>
                        <th style="width:100px;">Min s</th>
                        <th style="width:100px;">Max s</th>
                        <th style="width:180px;">Condition JSON</th>
                        <th style="width:100px;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <# if (!data.followups || !data.followups.length) { #>
                        <tr class="lst-followup-empty-row">
                            <td colspan="7">Noch keine Follow-ups vorhanden.</td>
                        </tr>
                    <# } else { #>
                        <# _.each(data.followups, function(row) { #>
                            <tr class="lst-followup-row">
                                <td><input type="number" min="1" step="1" class="small-text lst-followup-step" value="{{ row.step_no || '' }}"></td>
                                <td>
                                    <select class="lst-followup-kind">
                                        <option value="dispatcher_question" <# if (row.kind === 'dispatcher_question') { #>selected<# } #>>dispatcher_question</option>
                                        <option value="caller_answer" <# if (row.kind === 'caller_answer') { #>selected<# } #>>caller_answer</option>
                                        <option value="update" <# if (!row.kind || row.kind === 'update') { #>selected<# } #>>update</option>
                                        <option value="unit_report" <# if (row.kind === 'unit_report') { #>selected<# } #>>unit_report</option>
                                    </select>
                                </td>
                                <td><textarea class="large-text lst-followup-text" rows="2">{{ row.text || '' }}</textarea></td>
                                <td><input type="number" min="0" step="1" class="small-text lst-followup-min" value="{{ row.min_after_sec || '' }}"></td>
                                <td><input type="number" min="0" step="1" class="small-text lst-followup-max" value="{{ row.max_after_sec || '' }}"></td>
                                <td><input type="text" class="large-text code lst-followup-condition" value="{{ row.condition_json || '' }}"></td>
                                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
                            </tr>
                        <# }); #>
                    <# } #>
                </tbody>
            </table>
        </div>
    </div>

    <div class="lst-modal-actions">
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