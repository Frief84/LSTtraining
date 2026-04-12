<?php
if (!defined('ABSPATH')) { exit; }
?>
<div class="wrap lst-admin-page lst-anruferprofile-page">
    <h1 class="wp-heading-inline">Anruferprofile</h1>
    <a href="#" class="page-title-action" id="lst-anruferprofile-new">Neu hinzufügen</a>
    <hr class="wp-header-end">

    <div class="lst-card lst-anruferprofile-toolbar">
        <div class="lst-toolbar-row">
            <div class="lst-field">
                <label for="lst-anruferprofile-search"><strong>Suche</strong></label>
                <input type="text" id="lst-anruferprofile-search" class="regular-text" placeholder="Name, Kategorie oder ID">
            </div>

            <div class="lst-field">
                <label for="lst-anruferprofile-filter-category"><strong>Kategorie</strong></label>
                <select id="lst-anruferprofile-filter-category">
                    <option value="">Alle</option>
                    <option value="private">private</option>
                    <option value="relative">relative</option>
                    <option value="nursing">nursing</option>
                    <option value="company">company</option>
                    <option value="school">school</option>
                    <option value="authority">authority</option>
                    <option value="security">security</option>
                    <option value="public_staff">public_staff</option>
                </select>
            </div>

            <div class="lst-field">
                <label for="lst-anruferprofile-filter-enabled"><strong>Status</strong></label>
                <select id="lst-anruferprofile-filter-enabled">
                    <option value="">Alle</option>
                    <option value="1">Aktiv</option>
                    <option value="0">Inaktiv</option>
                </select>
            </div>

            <div class="lst-toolbar-spacer"></div>

            <div class="lst-spinner-wrap">
                <span class="spinner is-active" id="lst-anruferprofile-list-spinner" style="visibility:hidden;"></span>
            </div>
        </div>
    </div>

    <div class="lst-card">
        <table class="widefat fixed striped" id="lst-anruferprofile-table">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Name</th>
                    <th style="width:120px;">Kategorie</th>
                    <th style="width:120px;">Tonfall</th>
                    <th style="width:80px;">Name</th>
                    <th style="width:80px;">Adresse</th>
                    <th style="width:80px;">Aktiv</th>
                    <th style="width:220px;">Aktionen</th>
                </tr>
            </thead>
            <tbody id="lst-anruferprofile-table-body">
                <tr><td colspan="8">Keine Einträge geladen.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="lst-modal hidden" id="lst-anruferprofile-modal" aria-hidden="true">
    <div class="modal-overlay"></div>
    <div class="modal-content lst-modal-lg">
        <div class="modal-header">
            <h2 id="lst-anruferprofile-modal-title">Anruferprofil</h2>
            <button type="button" class="modal-close" aria-label="Schließen">×</button>
        </div>
        <div class="modal-body"></div>
    </div>
</div>

<script type="text/html" id="tmpl-lst-anruferprofile-table-rows">
<# if (!data.items || !data.items.length) { #>
    <tr><td colspan="8">Keine Anruferprofile gefunden.</td></tr>
<# } else { #>
    <# _.each(data.items, function(item) { #>
        <tr>
            <td>{{ item.id }}</td>
            <td>
                <strong>{{ item.name || '' }}</strong>
                <# if (item.notes) { #><div class="lst-muted">{{ item.notes }}</div><# } #>
            </td>
            <td>{{ item.category || '' }}</td>
            <td>{{ item.tone || '' }}</td>
            <td><# if (String(item.uses_name) === '1') { #>Ja<# } else { #>Nein<# } #></td>
            <td><# if (String(item.uses_address) === '1') { #>Ja<# } else { #>Nein<# } #></td>
            <td>
                <# if (String(item.enabled) === '1') { #>
                    <span class="lst-status-pill is-enabled">Ja</span>
                <# } else { #>
                    <span class="lst-status-pill is-disabled">Nein</span>
                <# } #>
            </td>
            <td>
                <button type="button" class="button button-small lst-anruferprofile-edit" data-id="{{ item.id }}">Bearbeiten</button>
                <button type="button" class="button button-small lst-anruferprofile-copy" data-id="{{ item.id }}">Kopieren</button>
                <button type="button" class="button button-small lst-anruferprofile-delete" data-id="{{ item.id }}">Löschen</button>
            </td>
        </tr>
    <# }); #>
<# } #>
</script>

<script type="text/html" id="tmpl-lst-anruferprofile-editor">
<form id="lst-anruferprofile-form" onsubmit="return false;">
    <input type="hidden" id="lst-anruferprofile-id" value="{{ data.id || '' }}">

    <div class="lst-tabs">
        <div class="lst-tab-nav">
            <button type="button" class="lst-tab-btn is-active" data-tab="general">Allgemein</button>
            <button type="button" class="lst-tab-btn" data-tab="behaviour">Verhalten</button>
            <button type="button" class="lst-tab-btn" data-tab="parts">Sprachbausteine</button>
            <button type="button" class="lst-tab-btn" data-tab="preview">Vorschau</button>
        </div>

        <div class="lst-tab-panel is-active" data-tab-panel="general">
            <div class="lst-grid lst-grid-2">
                <div class="lst-field">
                    <label for="lst-ap-name"><strong>Name</strong></label>
                    <input type="text" id="lst-ap-name" class="regular-text" value="{{ data.name || '' }}">
                </div>
                <div class="lst-field lst-field-check">
                    <label><input type="checkbox" id="lst-ap-enabled" value="1" <# if (String(data.enabled || '1') === '1') { #>checked<# } #>> Profil aktiv</label>
                </div>
                <div class="lst-field">
                    <label for="lst-ap-category"><strong>Kategorie</strong></label>
                    <select id="lst-ap-category">
                        <option value="private" <# if ((data.category || 'private') === 'private') { #>selected<# } #>>private</option>
                        <option value="relative" <# if ((data.category || '') === 'relative') { #>selected<# } #>>relative</option>
                        <option value="nursing" <# if ((data.category || '') === 'nursing') { #>selected<# } #>>nursing</option>
                        <option value="company" <# if ((data.category || '') === 'company') { #>selected<# } #>>company</option>
                        <option value="school" <# if ((data.category || '') === 'school') { #>selected<# } #>>school</option>
                        <option value="authority" <# if ((data.category || '') === 'authority') { #>selected<# } #>>authority</option>
                        <option value="security" <# if ((data.category || '') === 'security') { #>selected<# } #>>security</option>
                        <option value="public_staff" <# if ((data.category || '') === 'public_staff') { #>selected<# } #>>public_staff</option>
                    </select>
                </div>
                <div class="lst-field">
                    <label for="lst-ap-tone"><strong>Tonfall</strong></label>
                    <select id="lst-ap-tone">
                        <option value="calm" <# if ((data.tone || 'calm') === 'calm') { #>selected<# } #>>calm</option>
                        <option value="concerned" <# if ((data.tone || '') === 'concerned') { #>selected<# } #>>concerned</option>
                        <option value="agitated" <# if ((data.tone || '') === 'agitated') { #>selected<# } #>>agitated</option>
                        <option value="panicked" <# if ((data.tone || '') === 'panicked') { #>selected<# } #>>panicked</option>
                        <option value="professional" <# if ((data.tone || '') === 'professional') { #>selected<# } #>>professional</option>
                        <option value="terse" <# if ((data.tone || '') === 'terse') { #>selected<# } #>>terse</option>
                    </select>
                </div>
                <div class="lst-field">
                    <label for="lst-ap-emotion-level"><strong>Emotionslevel</strong></label>
                    <input type="number" min="1" max="4" step="1" id="lst-ap-emotion-level" class="small-text" value="{{ data.emotion_level || 1 }}">
                </div>
                <div class="lst-field">
                    <label for="lst-ap-sort-order"><strong>Sortierung</strong></label>
                    <input type="number" min="0" step="1" id="lst-ap-sort-order" class="small-text" value="{{ data.sort_order || 0 }}">
                </div>
            </div>
            <div class="lst-field">
                <label for="lst-ap-notes"><strong>Notizen</strong></label>
                <textarea id="lst-ap-notes" rows="4" class="large-text">{{ data.notes || '' }}</textarea>
            </div>
        </div>

        <div class="lst-tab-panel" data-tab-panel="behaviour" style="display:none;">
            <div class="lst-check-grid">
                <label><input type="checkbox" id="lst-ap-uses-name" value="1" <# if (String(data.uses_name || '1') === '1') { #>checked<# } #>> nennt Namen</label>
                <label><input type="checkbox" id="lst-ap-uses-address" value="1" <# if (String(data.uses_address || '1') === '1') { #>checked<# } #>> nennt Adresse</label>
                <label><input type="checkbox" id="lst-ap-uses-poi-name" value="1" <# if (String(data.uses_poi_name || '0') === '1') { #>checked<# } #>> nennt POI-Namen</label>
                <label><input type="checkbox" id="lst-ap-uses-company-name" value="1" <# if (String(data.uses_company_name || '0') === '1') { #>checked<# } #>> nennt Firmen-/Einrichtungsnamen</label>
            </div>
        </div>

       <div class="lst-tab-panel" data-tab-panel="parts" style="display:none;">
    <# var partMap = data.parts || {}; #>
    <# var partDefs = [
        { key: 'greeting', label: 'Begrüßung' },
        { key: 'self_intro', label: 'Selbstvorstellung' },
        { key: 'location_intro', label: 'Orts-/Adressnennung' },
        { key: 'problem_intro', label: 'Problem-Einleitung' },
        { key: 'urgency', label: 'Dringlichkeit' },
        { key: 'closing', label: 'Abschluss' },
        { key: 'callback_request', label: 'Rückrufbitte' }
    ]; #>

    <div class="lst-box lst-placeholder-help">
        <h3>Verfügbare Platzhalter</h3>
        <p>Diese Platzhalter kannst du in den Textbausteinen verwenden. Sie werden bei der Vorschau und später bei der Einsatzgenerierung automatisch ersetzt.</p>

        <table class="widefat striped lst-subtable lst-placeholder-table">
            <thead>
                <tr>
                    <th style="width:180px;">Platzhalter</th>
                    <th style="width:260px;">Bedeutung</th>
                    <th>Beispiel</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>{full_name}</code></td>
                    <td>Voller Name des Anrufers</td>
                    <td>Sabine Müller</td>
                </tr>
                <tr>
                    <td><code>{first_name}</code></td>
                    <td>Vorname des Anrufers</td>
                    <td>Sabine</td>
                </tr>
                <tr>
                    <td><code>{last_name}</code></td>
                    <td>Nachname des Anrufers</td>
                    <td>Müller</td>
                </tr>
                <tr>
                    <td><code>{formal_name}</code></td>
                    <td>Formale Anrede mit Nachname</td>
                    <td>Frau Müller</td>
                </tr>
                <tr>
                    <td><code>{title_last_name}</code></td>
                    <td>Anrede + Nachname</td>
                    <td>Herr Becker</td>
                </tr>
                <tr>
                    <td><code>{address_full}</code></td>
                    <td>Adresse des Einsatzortes</td>
                    <td>Musterstraße 12</td>
                </tr>
                <tr>
                    <td><code>{poi_name}</code></td>
                    <td>Name des POI</td>
                    <td>Seniorenheim Sonnenhof</td>
                </tr>
                <tr>
                    <td><code>{company_name}</code></td>
                    <td>Firma oder Einrichtung</td>
                    <td>Firma Beispiel GmbH</td>
                </tr>
                <tr>
                    <td><code>{problem}</code></td>
                    <td>Gemeldete Lage</td>
                    <td>hier ist eine Person gestürzt und hat starke Schmerzen</td>
                </tr>
            </tbody>
        </table>

        <p class="description">
            Beispiel: <code>Guten Tag, hier ist {formal_name} aus {poi_name}. {problem}.</code>
        </p>
    </div>

    <# _.each(partDefs, function(def) { #>
        <div class="lst-box">
            <div class="lst-box-header">
                <h3>{{ def.label }}</h3>
                <button type="button" class="button button-secondary lst-ap-add-part" data-part-key="{{ def.key }}">Baustein hinzufügen</button>
            </div>
            <table class="widefat striped lst-subtable lst-ap-part-table" data-part-key="{{ def.key }}">
                <thead>
                    <tr>
                        <th>Text</th>
                        <th style="width:120px;">Sortierung</th>
                        <th style="width:120px;">Aktiv</th>
                        <th style="width:100px;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <# if (!partMap[def.key] || !partMap[def.key].length) { #>
                        <tr class="lst-ap-part-empty-row"><td colspan="4">Noch keine Bausteine.</td></tr>
                    <# } else { #>
                        <# _.each(partMap[def.key], function(row) { #>
                            <tr class="lst-ap-part-row">
                                <td>
                                    <input
                                        type="text"
                                        class="regular-text lst-ap-part-text"
                                        value="{{ row.text || '' }}"
                                        placeholder="z. B. hier ist {formal_name}"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        class="small-text lst-ap-part-sort-order"
                                        value="{{ row.sort_order || 0 }}"
                                    >
                                </td>
                                <td>
                                    <label>
                                        <input
                                            type="checkbox"
                                            class="lst-ap-part-enabled"
                                            value="1"
                                            <# if (String(row.enabled || '1') === '1') { #>checked<# } #>
                                        >
                                        aktiv
                                    </label>
                                </td>
                                <td>
                                    <button type="button" class="button-link-delete lst-remove-row">Entfernen</button>
                                </td>
                            </tr>
                        <# }); #>
                    <# } #>
                </tbody>
            </table>
        </div>
    <# }); #>
</div>

        <div class="lst-tab-panel" data-tab-panel="preview" style="display:none;">
            <div class="lst-box-header">
                <h3>Beispielanrufe</h3>
                <button type="button" class="button button-secondary" id="lst-ap-generate-preview">3 Beispiele generieren</button>
            </div>
            <div class="lst-grid lst-grid-2">
    <div class="lst-field">
        <label for="lst-ap-preview-gender"><strong>Beispielgeschlecht</strong></label>
        <select id="lst-ap-preview-gender">
            <option value="">zufällig</option>
            <option value="female">female</option>
            <option value="male">male</option>
            <option value="neutral">neutral</option>
        </select>
    </div>

    <div class="lst-field">
        <label for="lst-ap-preview-address"><strong>Beispieladresse</strong></label>
        <input type="text" id="lst-ap-preview-address" class="regular-text" value="Musterstraße 12">
    </div>

    <div class="lst-field">
        <label for="lst-ap-preview-poi"><strong>Beispiel-POI</strong></label>
        <input type="text" id="lst-ap-preview-poi" class="regular-text" value="Seniorenheim Sonnenhof">
    </div>

    <div class="lst-field">
        <label for="lst-ap-preview-company"><strong>Beispielfirma</strong></label>
        <input type="text" id="lst-ap-preview-company" class="regular-text" value="Firma Beispiel GmbH">
    </div>

    <div class="lst-field lst-grid-span-2">
        <label for="lst-ap-preview-problem"><strong>Beispiellage</strong></label>
        <input type="text" id="lst-ap-preview-problem" class="regular-text" value="hier ist eine Person gestürzt und hat starke Schmerzen">
    </div>
</div>
            <ol id="lst-ap-preview-list" class="lst-preview-list"></ol>
        </div>
    </div>

    <div class="lst-modal-actions">
        <button type="button" class="button" id="lst-anruferprofile-cancel">Abbrechen</button>
        <button type="submit" class="button button-primary" id="lst-anruferprofile-save">Speichern</button>
        <span class="spinner" id="lst-anruferprofile-save-spinner" style="visibility:hidden;"></span>
    </div>
</form>
</script>
