jQuery(function ($) {
    let map = null;
    let vectorSource = null;
    let vectorLayer = null;
    let currentPoiTypes = [];
    let currentVehicleTypes = [];
    let currentCallerProfiles = [];
    let currentHospitalDepartments = {};
    let callerProfilesLoading = false;
    let callerProfilesLoadFailed = false;
    let callerProfilesReady = false;
    let followupCardSeq = 0;
    const wizardSteps = ['general', 'location', 'time', 'caller', 'situation'];
    let flowView = 'form';
    let flowZoom = 1;
    let flowSelectedKey = 'foundation';
    function flowKey() { return 'flow_' + (window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Math.random().toString(36).slice(2) + Date.now().toString(36)); }

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getAjaxBase() {
        return {
            ajax_url: (window.lstEinsaetzeAjax && lstEinsaetzeAjax.ajax_url) ? lstEinsaetzeAjax.ajax_url : ajaxurl,
            nonce: (window.lstEinsaetzeAjax && lstEinsaetzeAjax.nonce) ? lstEinsaetzeAjax.nonce : ''
        };
    }

    function postAjax(payload) {
        const cfg = getAjaxBase();
        return $.post(cfg.ajax_url, $.extend({}, payload, { nonce: cfg.nonce }));
    }

    function showModal() {
        $('#lst-einsatz-modal')
            .removeClass('hidden')
            .attr('aria-hidden', 'false')
            .removeAttr('inert');
    }

    function hideModal() {
        const $modal = $('#lst-einsatz-modal');
        if ($modal.has(document.activeElement).length) {
            document.activeElement.blur();
        }

        $modal
            .attr('aria-hidden', 'true')
            .attr('inert', '')
            .addClass('hidden');
    }

    function renderList(items) {
        const tpl = wp.template('lst-einsatz-table-rows');
        $('#lst-einsatz-table-body').html(tpl({ items: items || [] }));
    }

    function normalizeItem(item) {
        item = item || {};
        item.time_windows = Array.isArray(item.time_windows) ? item.time_windows : [];
        item.seasons = Array.isArray(item.seasons) ? item.seasons : [];
        item.weather_conditions = Array.isArray(item.weather_conditions) ? item.weather_conditions : [];
        item.followups = Array.isArray(item.followups) ? item.followups : [];
        item.followups.forEach(function (row) {
            const effect = parseJsonValue(row.effect_json || '', {});
            const label = String(row.label || '').toLowerCase();
            row.flow_stage = ['after_rescue', 'final_situation', 'incident_end', 'followup_call', 'arrival_default', 'arrival_variant'].includes(effect.flow_stage)
                ? (effect.flow_stage === 'after_rescue' ? 'final_situation' : effect.flow_stage)
                : (/nach befreiung/.test(label) ? 'final_situation' : (/einsatzende/.test(label) ? 'incident_end' : ((row.kind === 'caller_answer' || row.speaker_type === 'caller') && row.flow_role !== 'initial' ? 'followup_call' : '')));
        });
        item.followups.forEach(row => {
            if (!row.flow_key) row.flow_key = flowKey();
            if (!row.flow_role) row.flow_role = row.kind === 'unit_report' && row.trigger_mode === 'on_unit_arrival' ? 'initial' : 'development';
        });
        let arrivalDefault = item.followups.find(row => row.flow_stage === 'arrival_default');
        if (!arrivalDefault) {
            arrivalDefault = item.followups.find(row => row.flow_role === 'initial' && /eintreffen.*erkundung/i.test(String(row.label || '')));
            if (arrivalDefault) {
                arrivalDefault.flow_stage = 'arrival_default';
            } else {
                arrivalDefault = {
                    flow_key: flowKey(), flow_role: 'initial', flow_stage: 'arrival_default',
                    label: 'Eintreffen und Erkundung', kind: 'unit_report', speaker_type: 'fire_unit',
                    trigger_mode: 'on_unit_arrival', probability_percent: 0, min_after_sec: 0, max_after_sec: 0,
                    text: item.lagemeldung || '', effect_json: JSON.stringify({ radio_report_text: item.lagemeldung || '' })
                };
                item.followups.unshift(arrivalDefault);
            }
        }
        const defaultEffect = parseJsonValue(arrivalDefault.effect_json || '', {});
        arrivalDefault.effect_json = JSON.stringify(Object.assign({}, defaultEffect, {
            flow_stage: 'arrival_default', flow_parent_keys: [],
            radio_report_text: defaultEffect.radio_report_text || arrivalDefault.text || item.lagemeldung || ''
        }));
        arrivalDefault.probability_percent = 0;
        const previousInitialKey = arrivalDefault.flow_key;
        const migratedVariants = item.followups.filter(row => row !== arrivalDefault && row.flow_role === 'development'
            && /^Lage [A-D](?:$|[\s–:-])/i.test(String(row.label || ''))
            && (() => {
                const effect = parseJsonValue(row.effect_json || '', {});
                const parents = Array.isArray(effect.flow_parent_keys) ? effect.flow_parent_keys : parseJsonValue(row.applicable_initial_keys || [], []);
                return Array.isArray(parents) && (!parents.length || parents.includes(previousInitialKey));
            })());
        migratedVariants.forEach(row => {
            row.flow_role = 'initial';
            row.flow_stage = 'arrival_variant';
            row.kind = 'unit_report';
            row.trigger_mode = 'on_unit_arrival';
            row.applicable_initial_keys = [];
            const effect = parseJsonValue(row.effect_json || '', {});
            effect.flow_stage = 'arrival_variant';
            effect.flow_parent_keys = (Array.isArray(effect.flow_parent_keys) ? effect.flow_parent_keys : []).filter(key => key !== previousInitialKey);
            row.effect_json = JSON.stringify(effect);
        });
        item.followups.forEach(row => {
            if (row !== arrivalDefault && row.flow_role === 'initial') {
                row.flow_stage = 'arrival_variant';
                const effect = parseJsonValue(row.effect_json || '', {});
                effect.flow_stage = 'arrival_variant';
                row.effect_json = JSON.stringify(effect);
            }
        });
        if (migratedVariants.length) {
            const existing = item.followups.filter(row => row.flow_stage === 'arrival_variant' && !migratedVariants.includes(row));
            const reserved = existing.reduce((sum, row) => sum + Math.max(0, Number(row.probability_percent || 0)), 0);
            const each = Math.floor(Math.max(0, 100 - reserved) / (migratedVariants.length + 1));
            migratedVariants.forEach(row => { row.probability_percent = each; });
        }
        item.followups.forEach((row, index) => { row.step_no = index + 1; });
        item.caller_profiles = Array.isArray(item.caller_profiles) ? item.caller_profiles : [];
        item.base_required_resources_json = item.base_required_resources_json || '';
        item.patient_profile_json = item.patient_profile_json || '';
        item.caller_parts = item.caller_parts || {
            greeting: [],
            person: [],
            location: [],
            problem: [],
            observation: [],
            extra: []
        };
        item.selected_poi_types = parseJsonValue(item.poi_types_json || '', []);
        if (!Array.isArray(item.selected_poi_types)) item.selected_poi_types = [];
        if (!item.selected_poi_types.length && item.poi_type) item.selected_poi_types = [item.poi_type];
        item.leitstellen = window.lstEinsatzLeitstellen || [];
        item.poi_types = currentPoiTypes;
        return item;
    }

    function selectedCallerProfileMap(item) {
        const selected = {};
        (item.caller_profiles || []).forEach(function (row) {
            const id = parseInt(row.profile_id || row.id || 0, 10);
            if (!id) return;
            selected[id] = {
                profile_id: id,
                weight: parseInt(row.weight || 100, 10) || 100
            };
        });
        return selected;
    }

    function renderCallerProfileAssignment(item) {
        const $target = $('#lst-einsatz-profile-assignment');
        if (!$target.length) return;

        item = item || {};
        const selected = selectedCallerProfileMap(item);

        if (callerProfilesLoading && !currentCallerProfiles.length) {
            callerProfilesReady = false;
            $target.html('<p class="description">Anruferprofile werden geladen...</p>');
            return;
        }

        if (!currentCallerProfiles.length) {
            callerProfilesReady = !callerProfilesLoadFailed && !callerProfilesLoading;
            $target.html(callerProfilesLoadFailed
                ? '<p class="description">Anruferprofile konnten nicht geladen werden. Bitte erneut öffnen oder später speichern.</p>'
                : '<p class="description">Noch keine aktiven Anruferprofile vorhanden.</p>');
            return;
        }
        callerProfilesReady = true;

        const rows = currentCallerProfiles.map(function (profile) {
            const id = parseInt(profile.id || 0, 10);
            const assigned = selected[id] || null;
            const checked = assigned ? ' checked' : '';
            const weight = assigned ? assigned.weight : 100;

            return `
                <tr class="lst-caller-profile-row" data-profile-id="${esc(id)}">
                    <td>
                        <label>
                            <input type="checkbox" class="lst-caller-profile-enabled" value="${esc(id)}"${checked}>
                            <strong>${esc(profile.name || ('Profil #' + id))}</strong>
                        </label>
                        <div class="description">
                            ${esc(profile.category || 'Profil')}${profile.tone ? ' · ' + esc(profile.tone) : ''}
                        </div>
                    </td>
                    <td style="width:140px;">
                        <input type="number" class="small-text lst-caller-profile-weight" min="1" max="1000" step="1" value="${esc(weight)}">
                    </td>
                </tr>
            `;
        }).join('');

        $target.html(`
            <table class="widefat striped lst-subtable">
                <thead>
                    <tr>
                        <th>Profil</th>
                        <th style="width:140px;">Gewichtung</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
            <p class="description">Gewichtung 100 ist normal. Höhere Werte werden beim konkreten Einsatz häufiger gewählt.</p>
        `);
    }

    function loadCallerProfilesForEditor(item) {
        if (currentCallerProfiles.length) {
            callerProfilesLoadFailed = false;
            callerProfilesReady = true;
            renderCallerProfileAssignment(item);
            return;
        }

        callerProfilesLoading = true;
        callerProfilesLoadFailed = false;
        callerProfilesReady = false;
        renderCallerProfileAssignment(item);

        postAjax({
            action: 'lst_get_anruferprofile_list',
            enabled: '1'
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_get_anruferprofile_list Fehler', res);
                currentCallerProfiles = [];
                callerProfilesLoadFailed = true;
                return;
            }

            currentCallerProfiles = Array.isArray(res.data.items) ? res.data.items : [];
            callerProfilesLoadFailed = false;
        })
        .fail(function (xhr) {
            console.error('lst_get_anruferprofile_list fehlgeschlagen', xhr);
            currentCallerProfiles = [];
            callerProfilesLoadFailed = true;
        })
        .always(function () {
            callerProfilesLoading = false;
            renderCallerProfileAssignment(item);
        });
    }

    function scopeLabel(scopeType) {
        const labels = {
            anywhere: 'Überall im Einsatzgebiet',
            landscape: 'Nach Gebietstyp',
            poi_type: 'An bestimmtem POI-Typ',
            fixed_point: 'Fester Punkt auf Karte'
        };
        return labels[scopeType] || 'Überall im Einsatzgebiet';
    }

    function updateWizardSummary() {
        const title = ($('#lst-title').val() || '').trim() || 'Unbenannter Einsatz';
        const scopeType = $('input[name="lst_scope_type"]:checked').val() || 'anywhere';
        const resources = collectResources('#lst-base-resource-list');
        const followupCount = $('.lst-followup-row').length;

        $('[data-lst-summary="title"]').text(title);
        $('[data-lst-summary="scope"]').text(scopeLabel(scopeType));
        $('[data-lst-summary="resources"]').text(resources.length
            ? resources.map(function (row) { return row.count + 'x ' + row.label; }).join(', ')
            : 'Kein Grundbedarf');
        $('[data-lst-summary="followups"]').text(followupCount === 1 ? '1 Lagevariante' : followupCount + ' Lagevarianten');
    }

    function updateWizardStepState(tabKey) {
        const activeIndex = Math.max(0, wizardSteps.indexOf(tabKey));
        $('.lst-tab-btn').each(function () {
            const key = $(this).data('tab');
            const index = wizardSteps.indexOf(key);
            $(this)
                .toggleClass('is-active', key === tabKey)
                .toggleClass('is-complete', index > -1 && index < activeIndex);
        });

        $('[data-lst-wizard-prev]').prop('disabled', activeIndex <= 0);
        $('[data-lst-wizard-next]')
            .prop('disabled', activeIndex >= wizardSteps.length - 1)
            .toggle(activeIndex < wizardSteps.length - 1);
    }

    function setTab(tabKey) {
        if (wizardSteps.indexOf(tabKey) === -1) {
            tabKey = 'general';
        }

        if (tabKey !== 'situation') restoreFlowProperties();
        $('.lst-tab-panel').removeClass('is-active').hide();

        const $panel = $('.lst-tab-panel[data-tab-panel="' + tabKey + '"]');
        $panel.addClass('is-active').show().scrollTop(0);
        $('#lst-einsatz-modal .modal-body').scrollTop(0);
        updateWizardStepState(tabKey);
        updateWizardSummary();
        if (tabKey === 'situation') renderFlowGraph();

        if (tabKey === 'location' && $('input[name="lst_scope_type"]:checked').val() === 'fixed_point') {
            setTimeout(initMapIfNeeded, 50);
            setTimeout(syncMapFromFields, 80);
        }
    }

    function moveWizard(delta) {
        const current = $('.lst-tab-btn.is-active').data('tab') || 'general';
        const index = Math.max(0, wizardSteps.indexOf(current));
        const nextIndex = Math.min(wizardSteps.length - 1, Math.max(0, index + delta));
        setTab(wizardSteps[nextIndex]);
    }

    function setScopePanels(scopeType) {
        $('.lst-scope-panel').hide();
        $('.lst-scope-panel[data-scope-panel="' + scopeType + '"]').show();

        if (scopeType === 'fixed_point') {
            setTimeout(initMapIfNeeded, 50);
            setTimeout(syncMapFromFields, 80);
        }
    }

    function loadList() {
        $('#lst-einsatz-list-spinner').css('visibility', 'visible');

        postAjax({
            action: 'lst_get_einsaetze',
            search: $('#lst-einsatz-search').val() || '',
            einsatzart: $('#lst-einsatz-filter-art').val() || '',
            enabled: $('#lst-einsatz-filter-enabled').val() || ''
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_get_einsaetze Fehler', res);
                renderList([]);
                return;
            }
            renderList(res.data.items || []);
        })
        .fail(function (xhr) {
            console.error('lst_get_einsaetze fehlgeschlagen', xhr);
        })
        .always(function () {
            $('#lst-einsatz-list-spinner').css('visibility', 'hidden');
        });
    }

    function cleanupLegacyPatientRequirementFields() {
        const $modal = $('#lst-einsatz-modal');
        const removeGridWithDescription = function ($grid) {
            const $next = $grid.next('.description');
            if ($next.length && /KTW|RTW|Notarzt|Rettungsmittelbedarf/.test($next.text())) {
                $next.remove();
            }
            $grid.remove();
        };

        $modal.find('h3').each(function () {
            const $heading = $(this);
            if ($heading.text().trim() === 'Patienten- und Rettungsmittelbedarf') {
                $heading.text('Patienten und Rettungsmittel');
            }
        });

        const $baseLegacyGrid = $modal
            .find('#lst-patientenzahl, #lst-patient-ktw, #lst-patient-rtw, #lst-patient-notarzt')
            .filter(function () {
                return String($(this).attr('type') || '').toLowerCase() !== 'hidden';
            })
            .first()
            .closest('.lst-grid');
        if ($baseLegacyGrid.length) {
            removeGridWithDescription($baseLegacyGrid);
        }

        $modal
            .find('.lst-followup-patient-total, .lst-followup-patient-ktw, .lst-followup-patient-rtw, .lst-followup-patient-notarzt')
            .closest('.lst-grid')
            .each(function () {
                removeGridWithDescription($(this));
            });

        $modal.find('.lst-patient-editor > .description').each(function () {
            const $description = $(this);
            if ($description.text().indexOf('Hier stellst du Triage') !== -1) {
                $description.text('Jede Zeile beschreibt einen Patienten. Rettungsmittel und optionales Klinikziel werden je Patient festgelegt. 0 % = verstorben, ab Zielwert transportbereit.');
            }
        });
    }

    let currentStichwoerter = [];
    function loadStichwoerter(selectedId) {
        const lsId = parseInt($('#lst-einsatz-leitstelle').val() || 0, 10);
        const $select = $('#lst-stichwort-id');
        $select.html('<option value="">Bitte Stichwort wählen</option>');
        currentStichwoerter = [];
        if (!lsId) return;
        postAjax({action: 'lst_get_stichwoerter', leitstelle_id: lsId}).done(function (res) {
            if (!res || !res.success) return;
            currentStichwoerter = Array.isArray(res.data.items) ? res.data.items : [];
            currentStichwoerter.forEach(function (row) {
                $select.append($('<option></option>').val(row.id).text(row.code + ' – ' + row.bezeichnung + ' (' + row.einsatzart + ')'));
            });
            if (selectedId) $select.val(String(selectedId));
        });
    }

    function openEditor(item) {
        item = normalizeItem(item);
        const mode = item.__editor_mode || (item.id ? 'edit' : 'create');
        const editorTitle = mode === 'copy' ? 'Einsatz kopieren' : (item.id ? 'Einsatz bearbeiten' : 'Einsatz anlegen');

        const tpl = wp.template('lst-einsatz-editor');
        $('#lst-einsatz-modal .modal-body').html(tpl(item));
        $('#lst-einsatz-modal-title').text(editorTitle);
        cleanupLegacyPatientRequirementFields();

        showModal();
        bindDynamicUi();
        restoreEmptyRows();
        followupCardSeq = $('.lst-followup-row').length;
        hydrateResourceEditors(item);
        loadStichwoerter(item.stichwort_id || 0);
        $('.lst-followup-row').each(function () {
            const $card = $(this);
            if (!$card.attr('data-flow-key')) $card.attr('data-flow-key', flowKey());
            if ($card.attr('data-flow-stage') === 'arrival_default') {
                $card.find('.lst-followup-role').val('initial').prop('disabled', true);
                $card.find('.lst-followup-probability').val('0').closest('.lst-field').hide();
                $card.find('.lst-remove-row').first().hide();
            }
        });
        refreshFlowParents();
        queueAllPatientHospitalPreviews();
        callerProfilesReady = false;
        callerProfilesLoadFailed = false;
        loadCallerProfilesForEditor(item);

        setTab('general');

        const scopeType = $('input[name="lst_scope_type"]:checked').val() || 'anywhere';
        setScopePanels(scopeType);
        updateWizardSummary();
    }

    function fetchOne(id, asCopy) {
        postAjax({
            action: 'lst_get_einsatz',
            id: id
        })
        .done(function (res) {
            if (!res || !res.success || !res.data || !res.data.item) {
                console.error('lst_get_einsatz Fehler', res);
                return;
            }

            const item = res.data.item;
            if (asCopy) {
                item.id = '';
                item.title = (item.title || 'Einsatz') + ' (Kopie)';
                item.__editor_mode = 'copy';
            } else {
                item.__editor_mode = 'edit';
            }

            openEditor(item);
        })
        .fail(function (xhr) {
            console.error('lst_get_einsatz fehlgeschlagen', xhr);
        });
    }

    function addTimeWindowRow(row) {
        const tbody = $('#lst-time-window-table tbody');
        tbody.find('.lst-time-window-empty-row').remove();

        row = row || {};

        tbody.append(`
            <tr class="lst-time-window-row">
                <td>
                    <select class="lst-day-type">
                        <option value="any" ${row.day_type === 'any' ? 'selected' : ''}>Alle</option>
                        <option value="weekday" ${row.day_type === 'weekday' ? 'selected' : ''}>Werktag</option>
                        <option value="weekend" ${row.day_type === 'weekend' ? 'selected' : ''}>Wochenende</option>
                        <option value="monday" ${row.day_type === 'monday' ? 'selected' : ''}>Montag</option>
                        <option value="tuesday" ${row.day_type === 'tuesday' ? 'selected' : ''}>Dienstag</option>
                        <option value="wednesday" ${row.day_type === 'wednesday' ? 'selected' : ''}>Mittwoch</option>
                        <option value="thursday" ${row.day_type === 'thursday' ? 'selected' : ''}>Donnerstag</option>
                        <option value="friday" ${row.day_type === 'friday' ? 'selected' : ''}>Freitag</option>
                        <option value="saturday" ${row.day_type === 'saturday' ? 'selected' : ''}>Samstag</option>
                        <option value="sunday" ${row.day_type === 'sunday' ? 'selected' : ''}>Sonntag</option>
                    </select>
                </td>
                <td><input type="time" class="lst-start-time" value="${esc(row.start_time || '')}"></td>
                <td><input type="time" class="lst-end-time" value="${esc(row.end_time || '')}"></td>
                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
            </tr>
        `);
    }

    function addCallerRow(partKey, row) {
        const tbody = $('.lst-einsatz-part-table[data-part-key="' + partKey + '"], .lst-caller-part-table[data-part-key="' + partKey + '"]').find('tbody');
        tbody.find('.lst-einsatz-part-empty-row, .lst-caller-empty-row').remove();

        const text = row && row.text ? row.text : '';
        const sortOrder = row && row.sort_order != null ? row.sort_order : 0;
        const enabled = !row || String(row.enabled) !== '0';

        tbody.append(`
            <tr class="lst-einsatz-part-row">
                <td><input type="text" class="regular-text lst-einsatz-part-text" value="${esc(text)}"></td>
                <td><input type="number" class="small-text lst-einsatz-part-sort-order" min="0" step="1" value="${esc(sortOrder)}"></td>
                <td><label><input type="checkbox" class="lst-einsatz-part-enabled" value="1" ${enabled ? 'checked' : ''}> aktiv</label></td>
                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
            </tr>
        `);
    }

    function parseJsonValue(raw, fallback) {
        if (!raw) return fallback;
        if (typeof raw !== 'string') return raw;
        try {
            return JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function normalizeResourceRows(raw) {
        let rows = [];
        if (Array.isArray(raw)) {
            rows = raw;
        } else if (raw && Array.isArray(raw.resources)) {
            rows = raw.resources;
        } else if (raw && typeof raw === 'object') {
            rows = Object.keys(raw).map(function (type) {
                return { type: type, count: raw[type] };
            });
        }

        return rows.map(function (row) {
            const type = String(row.type || row.vehicle_type || row.fahrzeugtyp || '').trim();
            const count = parseInt(row.count || row.amount || row.anzahl || 1, 10) || 1;
            const normalized = normalizeResourceType(type);
            return normalized ? { type: normalized, count: Math.max(1, count) } : null;
        }).filter(Boolean);
    }

    const resourceClasses = [
        { group: 'Rettungsdienst', value: 'rettungswagen', label: 'Rettungswagen' },
        { group: 'Rettungsdienst', value: 'krankentransport', label: 'Krankentransportwagen' },
        { group: 'Rettungsdienst', value: 'notarzt', label: 'Notarztmittel' },
        { group: 'Rettungsdienst', value: 'san_betreuung', label: 'Sanitäts-/Betreuungskomponente' },
        { group: 'Feuerwehr', value: 'loeschfahrzeug', label: 'Löschfahrzeug' },
        { group: 'Feuerwehr', value: 'hubrettung', label: 'Hubrettungsfahrzeug' },
        { group: 'Feuerwehr', value: 'ruestung', label: 'Rüst-/Hilfeleistungsfahrzeug' },
        { group: 'Feuerwehr', value: 'fuehrung', label: 'Führungsfahrzeug' },
        { group: 'Feuerwehr', value: 'logistik', label: 'Logistik' },
        { group: 'Feuerwehr', value: 'gefahrgut', label: 'Gefahrgut' },
        { group: 'Feuerwehr', value: 'atemschutz_messung', label: 'Atemschutz/Messung' },
        { group: 'THW', value: 'thw_bergung', label: 'THW-Bergung' },
        { group: 'THW', value: 'thw_fuehrung', label: 'THW-Führung' },
        { group: 'THW', value: 'thw_logistik', label: 'THW-Logistik' },
        { group: 'Sonstige', value: 'sonderkomponente', label: 'Sonderkomponente' }
    ];

    const resourceClassMap = resourceClasses.reduce(function (out, item) {
        out[item.value] = item;
        return out;
    }, {});

    function resourceClassLabel(type) {
        const normalized = normalizeResourceType(type);
        return normalized && resourceClassMap[normalized] ? resourceClassMap[normalized].label : String(type || '');
    }

    function normalizeResourceType(type) {
        const raw = String(type || '').trim();
        const value = raw.toUpperCase();
        const canonical = raw.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');

        if (resourceClassMap[canonical]) return canonical;
        if (!value) return '';
        if (value.indexOf('THW MTW') !== -1 || value.indexOf('TRUPPFÜHRER') !== -1) return 'thw_fuehrung';
        if (value.indexOf('THW LKW') !== -1 || value.indexOf('MZGW') !== -1 || value.indexOf('MLW') !== -1) return 'thw_logistik';
        if (value.indexOf('THW') === 0 || value.indexOf('GKW') !== -1) return 'thw_bergung';
        if (/^(NEF|NAW|RTH|ITH|BABY-NAW)/.test(value)) return 'notarzt';
        if (/^(KTW|KTW-B|KTW-4)/.test(value)) return 'krankentransport';
        if (/^(RTW|NKTW|ITW|GRTW)/.test(value)) return 'rettungswagen';
        if (value.indexOf('GW-SAN') !== -1 || value.indexOf('BETREU') !== -1 || value.indexOf('MANV') !== -1 || value.indexOf('SAN') !== -1) return 'san_betreuung';
        if (value.indexOf('DEKON') !== -1 || value.indexOf('GEFAHR') !== -1 || value.indexOf('GW-G') !== -1) return 'gefahrgut';
        if (value.indexOf('ATEMSCHUTZ') !== -1 || value.indexOf('GW-MESS') !== -1 || value.indexOf('MESS') !== -1) return 'atemschutz_messung';
        if (/^(DLK|DLA|TMB)/.test(value)) return 'hubrettung';
        if (/^(RW|VRW|VLF)/.test(value)) return 'ruestung';
        if (/^(ELW|KDOW|KDO|ORGL|LNA)/.test(value)) return 'fuehrung';
        if (value.indexOf('LOGISTIK') !== -1 || value.indexOf('GW-L') !== -1 || value.indexOf('WLF') !== -1 || value.indexOf('AB-') === 0) return 'logistik';
        if (/^(HLF|LF|TLF|FLF|LÖSCHBOOT|FLB)/.test(value)) return 'loeschfahrzeug';
        return 'sonderkomponente';
    }

    function vehicleTypeOptionsHtml(selected) {
        const normalizedSelected = normalizeResourceType(selected);
        const grouped = {};
        resourceClasses.forEach(function (item) {
            grouped[item.group] = grouped[item.group] || [];
            grouped[item.group].push(item);
        });

        let html = '<option value="">Fahrzeugklasse wählen</option>';
        Object.keys(grouped).forEach(function (group) {
            if (!grouped[group].length) return;
            html += '<optgroup label="' + esc(group) + '">';
            grouped[group].forEach(function (item) {
                html += '<option value="' + esc(item.value) + '"' + (item.value === normalizedSelected ? ' selected' : '') + '>' + esc(item.label) + '</option>';
            });
            html += '</optgroup>';
        });
        return html;
    }

    function addResourceRow(target, row) {
        const $target = $(target);
        const data = row || {};

        $target.append(`
            <div class="lst-resource-row">
                <select class="lst-resource-type">${vehicleTypeOptionsHtml(data.type || '')}</select>
                <button type="button" class="button lst-resource-minus" aria-label="Anzahl verringern">-</button>
                <input type="number" min="1" step="1" class="small-text lst-resource-count" value="${esc(data.count || 1)}">
                <button type="button" class="button lst-resource-plus" aria-label="Anzahl erhöhen">+</button>
                <button type="button" class="button-link-delete lst-remove-resource">Entfernen</button>
            </div>
        `);
        updateBaseResourceSummary();
        updateFollowupCardSummary($target.closest('.lst-followup-row'));
        updateWizardSummary();
    }

    function collectResources(target) {
        const rows = [];
        $(target).find('.lst-resource-row').each(function () {
            const type = normalizeResourceType($(this).find('.lst-resource-type').val() || '');
            const count = parseInt($(this).find('.lst-resource-count').val() || '1', 10) || 1;
            if (!type) return;
            rows.push({ type: type, label: resourceClassLabel(type), count: Math.max(1, count) });
        });
        return rows;
    }

    function resourceSummaryText(rows) {
        rows = Array.isArray(rows) ? rows : [];
        if (!rows.length) {
            return 'Kein Zusatzbedarf';
        }
        return rows.map(function (row) {
            return '+' + row.count + ' ' + row.label;
        }).join(', ');
    }

    function followupTriggerLabel(value) {
        const labels = {
            on_unit_arrival: 'Nach Eintreffen',
            on_missing_resources: 'Wenn Kräfte fehlen',
            random: 'Zufällig',
            manual: 'Manuell',
            on_dispatcher_question: 'Auf Nachfrage',
            on_transport_started: 'Bei Transportbeginn',
            on_hospital_arrival: 'Bei Klinikankunft',
            on_vehicle_available: 'Bei Freimeldung'
        };
        return labels[value] || 'Nach Eintreffen';
    }

    function updateFollowupCardSummary($card) {
        if (!$card || !$card.length) return;
        const label = ($card.find('.lst-followup-label').val() || '').trim() || $card.find('[data-followup-title]').text() || 'Lagevariante';
        const trigger = $card.find('.lst-followup-trigger').val() || 'on_unit_arrival';
        const probability = $card.find('.lst-followup-probability').val() || '100';
        const patient = patientRequirementsFromRows(collectPatientRows($card.find('.lst-followup-patient-list')));
        const resources = mergeResourceRows(
            patientResourcesFromCounts(patient.ktw, patient.rtw, patient.notarzt),
            collectResources($card.find('.lst-followup-resource-list'))
        );

        $card.find('[data-followup-title]').text(label);
        $card.find('[data-followup-chip="trigger"]').text(followupTriggerLabel(trigger));
        $card.find('[data-followup-chip="probability"]').text(probability + ' %');
        $card.find('[data-followup-chip="resources"]').text(resourceSummaryText(resources));
    }

    function updateAllFollowupCardSummaries() {
        $('.lst-followup-row').each(function () {
            updateFollowupCardSummary($(this));
        });
    }

    function summarizeResources(rows) {
        const out = {};
        rows.forEach(function (row) {
            out[row.type] = (out[row.type] || 0) + (parseInt(row.count, 10) || 1);
        });
        return out;
    }

    function updateBaseResourceSummary() {
        const patient = patientRequirementsFromRows(collectPatientRows($('#lst-base-patient-list')));
        const summary = summarizeResources(mergeResourceRows(
            patientResourcesFromCounts(patient.ktw, patient.rtw, patient.notarzt),
            collectResources('#lst-base-resource-list')
        ));
        const $tbody = $('#lst-base-resource-summary');
        if (!$tbody.length) return;

        const types = Object.keys(summary).sort();
        if (!types.length) {
            $tbody.html('<tr><td colspan="2">Noch keine Fahrzeuge ausgewählt.</td></tr>');
            updateWizardSummary();
            return;
        }

        $tbody.html(types.map(function (type) {
            return '<tr><td>' + esc(resourceClassLabel(type)) + '</td><td>' + esc(summary[type]) + '</td></tr>';
        }).join(''));
        updateWizardSummary();
    }

    function effectNoteFromJson(raw) {
        const decoded = parseJsonValue(raw, {});
        if (!decoded || typeof decoded !== 'object') return '';
        return String(decoded.note || decoded.message || '');
    }

    const patientResourceTypes = ['krankentransport', 'rettungswagen', 'notarzt'];

    function intValue(value) {
        return Math.max(0, parseInt(value || '0', 10) || 0);
    }

    function boolValue(value) {
        if (value === true || value === 1) return true;
        if (value === false || value === 0 || value == null) return false;
        const normalized = String(value).trim().toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'ja';
    }

    function triageOptionsHtml(value) {
        value = String(value || 'III').toUpperCase();
        return ['I', 'II', 'III', 'IV', 'V'].map(function (item) {
            const labels = { I: 'I Rot', II: 'II Gelb', III: 'III Grün', IV: 'IV Blau', V: 'V Schwarz' };
            return '<option value="' + item + '"' + (value === item ? ' selected' : '') + '>' + labels[item] + '</option>';
        }).join('');
    }

    function normalizeHospitalDepartment(value) {
        const code = String(value || '').trim().toUpperCase();
        return code && Object.prototype.hasOwnProperty.call(currentHospitalDepartments, code) ? code : '';
    }

    function hospitalDepartmentLabel(code) {
        code = normalizeHospitalDepartment(code);
        if (!code) return 'Automatisch';
        const item = currentHospitalDepartments[code] || {};
        return String(item.label || code) + ' (' + code + ')';
    }

    function hospitalDepartmentOptionsHtml(value) {
        value = normalizeHospitalDepartment(value);
        const rows = ['<option value="">Automatisch nach Lage und Triage</option>'];
        Object.keys(currentHospitalDepartments).forEach(function (code) {
            const selected = value === code ? ' selected' : '';
            rows.push('<option value="' + esc(code) + '"' + selected + '>' + esc(hospitalDepartmentLabel(code)) + '</option>');
        });
        return rows.join('');
    }

    function normalizePatientRows(raw) {
        const decoded = Array.isArray(raw) ? raw : parseJsonValue(raw || '', []);
        const rows = decoded && decoded.patients && Array.isArray(decoded.patients) ? decoded.patients : decoded;
        return Array.isArray(rows) ? rows.map(function (row, index) {
            row = row && typeof row === 'object' ? row : {};
            const progress = Math.max(0, Math.min(100, parseInt(row.care_progress_percent != null ? row.care_progress_percent : (row.percent != null ? row.percent : 50), 10) || 0));
            const dead = progress === 0;
            const rawPreferredDepartment = String(row.preferred_hospital_department || '').trim().toUpperCase();
            const preferredDepartment = normalizeHospitalDepartment(rawPreferredDepartment);
            const invalidPreferredDepartment = String(row.invalid_preferred_hospital_department || '').trim().toUpperCase();
            return {
                patient_id: String(row.patient_id || ('p' + (index + 1))),
                label: String(row.label || ('Patient ' + (index + 1))),
                triage_category: dead ? 'V' : String(row.triage_category || 'III').toUpperCase(),
                injury_summary: String(row.injury_summary || row.description || ''),
                requires_ktw: dead ? false : boolValue(row.requires_ktw),
                requires_rtw: dead ? false : boolValue(row.requires_rtw),
                requires_notarzt: dead ? false : boolValue(row.requires_notarzt),
                preferred_hospital_department: preferredDepartment,
                invalid_preferred_hospital_department: rawPreferredDepartment && !preferredDepartment ? rawPreferredDepartment : invalidPreferredDepartment,
                care_progress_percent: progress,
                care_target_percent: Math.max(1, Math.min(100, parseInt(row.care_target_percent || 100, 10) || 100))
            };
        }) : [];
    }

    function patientRequirementsFromLegacyText(text) {
        const requirements = { total: 0, ktw: 0, rtw: 0, notarzt: 0 };
        const value = String(text || '');
        const patterns = [
            { key: 'ktw', re: /(\d+)\s*(?:x\s*)?(?:ktw|krankentransport)/ig },
            { key: 'rtw', re: /(\d+)\s*(?:x\s*)?(?:rtw|rettungswagen)/ig },
            { key: 'notarzt', re: /(\d+)\s*(?:x\s*)?(?:notarzt|nef|naw|rth|ith|notarztmittel)/ig }
        ];
        patterns.forEach(function (pattern) {
            let match;
            while ((match = pattern.re.exec(value)) !== null) {
                requirements[pattern.key] += intValue(match[1]);
            }
        });
        requirements.total = Math.max(requirements.ktw + requirements.rtw, requirements.notarzt);
        return requirements;
    }

    function derivePatientRows(requirements) {
        const rows = [];
        const total = Math.max(intValue(requirements.total), intValue(requirements.ktw) + intValue(requirements.rtw), intValue(requirements.notarzt));
        for (let i = 0; i < total; i++) {
            const needsNotarzt = i < intValue(requirements.notarzt);
            const needsRtw = needsNotarzt || i < intValue(requirements.rtw);
            const needsKtw = !needsRtw && i < (intValue(requirements.rtw) + intValue(requirements.ktw));
            rows.push({
                patient_id: 'p' + (i + 1),
                label: 'Patient ' + (i + 1),
                triage_category: needsNotarzt ? 'I' : (needsRtw ? 'II' : 'III'),
                injury_summary: '',
                requires_ktw: needsKtw,
                requires_rtw: needsRtw,
                requires_notarzt: needsNotarzt,
                preferred_hospital_department: '',
                care_progress_percent: 50,
                care_target_percent: 100
            });
        }
        return rows;
    }

    function addPatientRow($target, row) {
        row = normalizePatientRows([row || {}])[0] || normalizePatientRows([{}])[0];
        const hasManualHospital = Boolean(row.preferred_hospital_department);
        const invalidHospital = String(row.invalid_preferred_hospital_department || '');
        const hospitalDetailsOpen = hasManualHospital || Boolean(invalidHospital) ? ' open' : '';
        const invalidHospitalMessage = invalidHospital
            ? '<p class="lst-patient-routing-warning">Der gespeicherte Fachbereich <code>' + esc(invalidHospital) + '</code> ist nicht mehr verfügbar und wurde auf Automatik gesetzt.</p>'
            : '';
        $target.append(`
            <div class="lst-patient-editor-row">
                <div class="lst-patient-main">
                    <input type="text" class="regular-text lst-patient-label lst-patient-label--compact" value="${esc(row.label)}" placeholder="Patient">
                    <select class="lst-patient-triage" aria-label="Triage">${triageOptionsHtml(row.triage_category)}</select>
                    <input type="text" class="regular-text lst-patient-injury" value="${esc(row.injury_summary)}" placeholder="Verletzungsbild / Zustand">
                    <div class="lst-patient-care">
                        <label><span>Prozent</span><input type="number" min="0" max="100" step="1" class="small-text lst-patient-progress" value="${esc(row.care_progress_percent)}"></label>
                        <label><span>Transport ab</span><input type="number" min="1" max="100" step="1" class="small-text lst-patient-target" value="${esc(row.care_target_percent)}"></label>
                        <button type="button" class="button-link-delete lst-remove-patient">Entfernen</button>
                    </div>
                </div>
                <div class="lst-patient-needs" aria-label="Rettungsmittelbedarf">
                    <span>Bedarf</span>
                    <label><input type="checkbox" class="lst-patient-ktw" ${row.requires_ktw ? 'checked' : ''}> KTW</label>
                    <label><input type="checkbox" class="lst-patient-rtw" ${row.requires_rtw ? 'checked' : ''}> RTW</label>
                    <label><input type="checkbox" class="lst-patient-notarzt" ${row.requires_notarzt ? 'checked' : ''}> Notarztmittel</label>
                </div>
                <details class="lst-patient-hospital"${hospitalDetailsOpen}>
                    <summary>
                        <span>Klinikziel: <strong class="lst-patient-hospital-status">${esc(hospitalDepartmentLabel(row.preferred_hospital_department))}</strong></span>
                        <em>Zielklinik festlegen</em>
                    </summary>
                    <div class="lst-patient-hospital-controls">
                        <label>
                            <span>Bevorzugter Fachbereich</span>
                            <select class="lst-patient-hospital-department">${hospitalDepartmentOptionsHtml(row.preferred_hospital_department)}</select>
                        </label>
                        ${invalidHospitalMessage}
                        <div class="lst-patient-routing-preview" aria-live="polite">
                            <p class="description">Vorschau wird berechnet.</p>
                        </div>
                    </div>
                </details>
                <p class="description">0 % = verstorben, ab Zielwert transportbereit.</p>
            </div>
        `);
    }

    function collectPatientRows($scope) {
        const rows = [];
        $scope.find('.lst-patient-editor-row').each(function (index) {
            const $row = $(this);
            const progress = Math.max(0, Math.min(100, parseInt($row.find('.lst-patient-progress').val() || '0', 10) || 0));
            const dead = progress === 0;
            rows.push({
                patient_id: 'p' + (index + 1),
                label: ($row.find('.lst-patient-label').val() || ('Patient ' + (index + 1))).trim(),
                triage_category: dead ? 'V' : ($row.find('.lst-patient-triage').val() || 'III'),
                injury_summary: ($row.find('.lst-patient-injury').val() || '').trim(),
                requires_ktw: dead ? false : $row.find('.lst-patient-ktw').is(':checked'),
                requires_rtw: dead ? false : $row.find('.lst-patient-rtw').is(':checked'),
                requires_notarzt: dead ? false : $row.find('.lst-patient-notarzt').is(':checked'),
                preferred_hospital_department: normalizeHospitalDepartment($row.find('.lst-patient-hospital-department').val() || ''),
                care_progress_percent: progress,
                care_target_percent: Math.max(1, Math.min(100, parseInt($row.find('.lst-patient-target').val() || '100', 10) || 100))
            });
        });
        return rows;
    }

    function patientRequirementsFromRows(rows) {
        const requirements = { total: 0, ktw: 0, rtw: 0, notarzt: 0 };
        normalizePatientRows(rows).forEach(function (row) {
            requirements.total++;
            if (row.care_progress_percent <= 0) return;
            if (row.requires_notarzt) requirements.notarzt++;
            if (row.requires_rtw) requirements.rtw++;
            else if (row.requires_ktw) requirements.ktw++;
        });
        return requirements;
    }

    function patientRequirementsFromJson(raw) {
        const decoded = parseJsonValue(raw, {});
        return decoded && typeof decoded === 'object' && decoded.patient_requirements && typeof decoded.patient_requirements === 'object'
            ? decoded.patient_requirements
            : {};
    }

    function patientRequirementsFromText(raw) {
        const text = String(raw || '').toLowerCase();
        const requirements = { total: 0, ktw: 0, rtw: 0, notarzt: 0 };
        text.replace(/(\d+)\s*x?\s*(ktw|krankentransport|rtw|rettungswagen|notarzt|notarztmittel|nef|rth|naw|ith)/g, function (match, count, type) {
            count = intValue(count);
            if (type === 'ktw' || type === 'krankentransport') {
                requirements.ktw += count;
            } else if (type === 'rtw' || type === 'rettungswagen') {
                requirements.rtw += count;
            } else if (type === 'notarzt' || type === 'notarztmittel' || type === 'nef' || type === 'rth' || type === 'naw' || type === 'ith') {
                requirements.notarzt += count;
            }
            return match;
        });
        requirements.total = Math.max(requirements.ktw + requirements.rtw, requirements.notarzt);
        return requirements;
    }

    function legacySituationTextFromJson(raw) {
        const decoded = parseJsonValue(raw, {});
        const situation = decoded && typeof decoded === 'object' && decoded.situation_report && typeof decoded.situation_report === 'object'
            ? decoded.situation_report
            : {};
        const keys = ['environment', 'damage_event', 'people', 'patients', 'hazards', 'summary'];
        return keys.map(function (key) {
            return String(situation[key] || '').trim();
        }).filter(Boolean).join('\n');
    }

    function patientResourcesFromCounts(ktw, rtw, notarzt) {
        const rows = [];
        ktw = intValue(ktw);
        rtw = intValue(rtw);
        notarzt = intValue(notarzt);
        if (ktw > 0) rows.push({ type: 'krankentransport', label: resourceClassLabel('krankentransport'), count: ktw });
        if (rtw > 0) rows.push({ type: 'rettungswagen', label: resourceClassLabel('rettungswagen'), count: rtw });
        if (notarzt > 0) rows.push({ type: 'notarzt', label: resourceClassLabel('notarzt'), count: notarzt });
        return rows;
    }

    function mergeResourceRows() {
        const summary = {};
        Array.prototype.slice.call(arguments).forEach(function (rows) {
            normalizeResourceRows(rows || []).forEach(function (row) {
                summary[row.type] = (summary[row.type] || 0) + (parseInt(row.count, 10) || 1);
            });
        });
        return Object.keys(summary).map(function (type) {
            return { type: type, label: resourceClassLabel(type), count: summary[type] };
        });
    }

    function splitPatientAndManualResources(rows) {
        const patient = { krankentransport: 0, rettungswagen: 0, notarzt: 0 };
        const manual = [];
        normalizeResourceRows(rows || []).forEach(function (row) {
            if (patientResourceTypes.indexOf(row.type) !== -1) {
                patient[row.type] += parseInt(row.count, 10) || 1;
            } else {
                manual.push(row);
            }
        });
        return { patient: patient, manual: manual };
    }

    function updateFollowupCardMode($card) {
        const call = isFlowCall($card);
        $card.find('.lst-situation-section').prop('hidden', false);
        $card.find('.lst-situation-section > h4').text(call ? 'Was teilt der Folgeanrufer mit?' : 'Was findet die Einheit vor?');
        $card.find('.lst-followup-radio-report').prev('label').find('strong').text(call ? 'Wortlaut des Folgeanrufs' : 'Rückmeldung an die Leitstelle');
        updateFollowupCardSummary($card);
    }

    function hasPatientRequirements(requirements) {
        requirements = requirements && typeof requirements === 'object' ? requirements : {};
        return Boolean(intValue(requirements.total) || intValue(requirements.ktw) || intValue(requirements.rtw) || intValue(requirements.notarzt));
    }

    function mergePatientRequirements() {
        const out = { total: 0, ktw: 0, rtw: 0, notarzt: 0 };
        Array.prototype.slice.call(arguments).forEach(function (requirements) {
            requirements = requirements && typeof requirements === 'object' ? requirements : {};
            out.total = Math.max(out.total, intValue(requirements.total));
            out.ktw = Math.max(out.ktw, intValue(requirements.ktw));
            out.rtw = Math.max(out.rtw, intValue(requirements.rtw));
            out.notarzt = Math.max(out.notarzt, intValue(requirements.notarzt));
        });
        return out;
    }

    function buildEffectJson(note, patientRequirements, patients, stage, parentKeys, reportText) {
        const text = String(note || '').trim();
        const patient = patientRequirements && typeof patientRequirements === 'object' ? patientRequirements : {};
        const payload = {};
        if (['arrival_default', 'arrival_variant', 'final_situation', 'incident_end', 'followup_call'].includes(stage)) payload.flow_stage = stage;
        payload.flow_parent_keys = Array.isArray(parentKeys) ? parentKeys : [];
        payload.radio_report_text = String(reportText || '').trim();
        if (text) {
            payload.note = text;
        }
        if (intValue(patient.total) || intValue(patient.ktw) || intValue(patient.rtw) || intValue(patient.notarzt)) {
            payload.patient_requirements = {
                total: intValue(patient.total),
                ktw: intValue(patient.ktw),
                rtw: intValue(patient.rtw),
                notarzt: intValue(patient.notarzt)
            };
        }
        patients = normalizePatientRows(patients || []);
        if (patients.length) {
            payload.patients = patients;
        }
        return Object.keys(payload).length ? JSON.stringify(payload) : '';
    }

    function hospitalRoutingScope($row) {
        const $card = $row.closest('.lst-followup-row');
        return $card.length ? $card.find('.lst-followup-patient-list') : $('#lst-base-patient-list');
    }

    function refreshPatientHospitalStatus($row) {
        const selected = normalizeHospitalDepartment($row.find('.lst-patient-hospital-department').val() || '');
        $row.find('.lst-patient-hospital-status').text(hospitalDepartmentLabel(selected));
    }

    function renderPatientHospitalPreview($row, item, automaticNotice) {
        const preferences = Array.isArray(item && item.department_preferences) ? item.department_preferences : [];
        const sequence = preferences.join(' > ') || '-';
        const reason = String(item && item.reason_label || '');
        const manual = String(item && item.mode || '') === 'manual';
        const lines = manual
            ? '<p><strong>Festgelegt:</strong> ' + esc(sequence) + '</p><p class="description">' + esc(item.notice || '') + '</p>'
            : '<p><strong>Erwartete Zielbereiche:</strong> ' + esc(sequence) + '</p><p class="description">Grund: ' + esc(reason) + '</p><p class="description">' + esc(automaticNotice || '') + '</p>';
        $row.find('.lst-patient-routing-preview').html(lines);
    }

    function requestPatientHospitalPreview($scope) {
        if (!$scope || !$scope.length) return;
        const rows = collectPatientRows($scope);
        if (!rows.length) return;
        const $followup = $scope.closest('.lst-followup-row');
        const requestId = (parseInt($scope.data('routing-request-id') || '0', 10) || 0) + 1;
        $scope.data('routing-request-id', requestId);
        postAjax({
            action: 'lst_preview_patient_hospital_routing',
            einsatzart: $('#lst-einsatzart').val() || 'RD',
            einsatztyp: $('#lst-einsatztyp').val() || '',
            lagemeldung: $followup.length ? ($followup.find('.lst-followup-text').val() || '') : ($('#lst-lagemeldung').val() || ''),
            patients: JSON.stringify(rows)
        })
        .done(function (res) {
            if (requestId !== $scope.data('routing-request-id')) return;
            if (!res || !res.success || !res.data) {
                $scope.find('.lst-patient-routing-preview').html('<p class="description">Vorschau konnte nicht berechnet werden.</p>');
                return;
            }
            const byId = {};
            (res.data.items || []).forEach(function (item) {
                byId[String(item.patient_id || '')] = item;
            });
            $scope.find('.lst-patient-editor-row').each(function (index) {
                const patientId = String(rows[index] && rows[index].patient_id || '');
                renderPatientHospitalPreview($(this), byId[patientId] || {}, res.data.automatic_notice || '');
            });
        })
        .fail(function () {
            if (requestId !== $scope.data('routing-request-id')) return;
            $scope.find('.lst-patient-routing-preview').html('<p class="description">Vorschau konnte nicht berechnet werden.</p>');
        });
    }

    function queuePatientHospitalPreview($scope) {
        if (!$scope || !$scope.length) return;
        clearTimeout($scope.data('routing-preview-timer'));
        $scope.data('routing-preview-timer', setTimeout(function () {
            requestPatientHospitalPreview($scope);
        }, 220));
    }

    function queueAllPatientHospitalPreviews() {
        queuePatientHospitalPreview($('#lst-base-patient-list'));
        $('.lst-followup-patient-list').each(function () {
            queuePatientHospitalPreview($(this));
        });
    }

    function applyConditionUi($card, raw) {
        const condition = parseJsonValue(raw, {});
        const resources = normalizeResourceRows(condition && condition.resources ? condition.resources : []);
        const first = resources[0] || {};

        $card.find('.lst-followup-cond-arrived').prop('checked', Boolean(condition && condition.unit_arrived));
        $card.find('.lst-followup-cond-missing').prop('checked', Boolean(condition && condition.missing_resources));
        $card.find('.lst-followup-cond-type').html(vehicleTypeOptionsHtml(first.type || ''));
        $card.find('.lst-followup-cond-count').val(first.count || 1);
    }

    function collectConditionJson($card) {
        const unitArrived = $card.find('.lst-followup-cond-arrived').is(':checked');
        const missingResources = $card.find('.lst-followup-cond-missing').is(':checked');
        const type = $card.find('.lst-followup-cond-type').val() || '';
        const count = parseInt($card.find('.lst-followup-cond-count').val() || '1', 10) || 1;

        if (!unitArrived && !missingResources && !type) {
            return '';
        }

        return JSON.stringify({
            unit_arrived: unitArrived,
            missing_resources: missingResources,
            resources: type ? [{ type: type, count: Math.max(1, count) }] : []
        });
    }

    function hydrateResourceEditors(item) {
        const baseSplit = splitPatientAndManualResources(parseJsonValue(item.base_required_resources_json || '', []));
        const legacyBaseRequirements = mergePatientRequirements(
            patientRequirementsFromText(item.patient_anforderung || ''),
            {
                total: intValue(item.patientenzahl),
                ktw: baseSplit.patient.krankentransport || 0,
                rtw: baseSplit.patient.rettungswagen || 0,
                notarzt: baseSplit.patient.notarzt || (String(item.notarzt_benoetigt || '0') === '1' ? 1 : 0)
            }
        );
        $('#lst-patientenzahl').val(legacyBaseRequirements.total);
        $('#lst-patient-ktw').val(legacyBaseRequirements.ktw);
        $('#lst-patient-rtw').val(legacyBaseRequirements.rtw);
        $('#lst-patient-notarzt').val(legacyBaseRequirements.notarzt);
        const baseRows = normalizePatientRows(item.patient_profile_json || []);
        const $basePatientList = $('#lst-base-patient-list').empty();
        (baseRows.length ? baseRows : derivePatientRows(legacyBaseRequirements)).forEach(function (row) {
            addPatientRow($basePatientList, row);
        });
        baseSplit.manual.forEach(function (row) {
            addResourceRow('#lst-base-resource-list', row);
        });

        $('.lst-followup-row').each(function () {
            const $card = $(this);
            const index = parseInt($card.data('followup-index'), 10);
            const row = item.followups[index] || {};
            const effect = parseJsonValue(row.effect_json || '', {});
            $card.data('flow-parents', Array.isArray(effect.flow_parent_keys) ? effect.flow_parent_keys : parseJsonValue(row.applicable_initial_keys || [], []));
            $card.data('flow-parent-explicit', Array.isArray(effect.flow_parent_keys));
            const followupSplit = splitPatientAndManualResources(parseJsonValue(row.required_resources_json || '', []));
            const savedPatientRequirements = patientRequirementsFromJson(row.effect_json || '');
            const legacyText = legacySituationTextFromJson(row.effect_json || '');
            if (!$card.find('.lst-followup-text').val() && legacyText) {
                $card.find('.lst-followup-text').val(legacyText);
            }

            followupSplit.manual.forEach(function (resource) {
                addResourceRow($card.find('.lst-followup-resource-list'), resource);
            });
            applyConditionUi($card, row.condition_json || '');
            $card.find('.lst-followup-effect-note').val(effectNoteFromJson(row.effect_json || ''));
            $card.find('.lst-followup-radio-report').val(String(effect.radio_report_text || row.text || ''));
            const effectDecoded = parseJsonValue(row.effect_json || '', {});
            const followupPatients = normalizePatientRows(effectDecoded && effectDecoded.patients ? effectDecoded.patients : []);
            const $followupPatientList = $card.find('.lst-followup-patient-list').empty();
            const legacyFollowupRequirements = mergePatientRequirements(savedPatientRequirements, {
                ktw: followupSplit.patient.krankentransport,
                rtw: followupSplit.patient.rettungswagen,
                notarzt: followupSplit.patient.notarzt
            });
            (followupPatients.length ? followupPatients : derivePatientRows(legacyFollowupRequirements)).forEach(function (patient) {
                addPatientRow($followupPatientList, patient);
            });
            updateFollowupCardMode($card);
            updateFollowupCardSummary($card);
        });

        $('.lst-followup-cond-type').each(function () {
            if (!$(this).children().length) {
                $(this).html(vehicleTypeOptionsHtml(''));
            }
        });
        updateBaseResourceSummary();
    }

    function addFollowupRow(row) {
        const container = $('#lst-followup-cards');
        container.find('.lst-followup-empty-row').remove();

        row = row || {};
        const displayNumber = $('.lst-followup-row').length + 1;
        const index = 'new-' + (++followupCardSeq);
        const probability = row.probability_percent != null ? row.probability_percent : 100;
        const kind = row.kind || 'unit_report';
        const speakerType = row.speaker_type || 'fire_unit';
        const triggerMode = row.trigger_mode || 'on_unit_arrival';
        const minAfter = row.min_after_sec != null && row.min_after_sec !== '' ? row.min_after_sec : 60;
        const maxAfter = row.max_after_sec != null && row.max_after_sec !== '' ? row.max_after_sec : 180;
        const rowText = row.text || legacySituationTextFromJson(row.effect_json || '');

        container.append(`
            <div class="lst-box lst-followup-card lst-followup-row" data-followup-index="${esc(index)}" data-flow-key="${esc(row.flow_key || flowKey())}">
                <div class="lst-flow-role-fields">
                    <label>Art <select class="lst-followup-role"><option value="initial" ${(row.flow_role || 'initial') === 'initial' ? 'selected' : ''} >Eintreffvariante</option><option value="followup_call">Folgeanruf</option><option value="development" ${!row.flow_stage && row.flow_role === 'development' ? 'selected' : ''}>Entwicklung</option><option value="final_situation" ${row.flow_stage === 'final_situation' ? 'selected' : ''}>Endlage</option><option value="incident_end" ${row.flow_stage === 'incident_end' ? 'selected' : ''}>Einsatzende</option></select></label>
                    <label>Mögliche Vorgänger <select class="lst-followup-parents" multiple aria-label="Mögliche Vorgänger"></select></label>
                </div>
                <div class="lst-followup-card-head">
                    <div>
                        <span class="lst-wizard-kicker">Lagevariante</span>
                        <h3 data-followup-title>${esc(row.label || ('Lagevariante ' + displayNumber))}</h3>
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
                            <input type="text" class="regular-text lst-followup-label" value="${esc(row.label || '')}" placeholder="z.B. Patient schwerer verletzt als gemeldet">
                        </div>
                        <div class="lst-field">
                            <label><strong>Auslöser</strong></label>
                            <select class="lst-followup-trigger">
                                <option value="on_unit_arrival" ${triggerMode === 'on_unit_arrival' ? 'selected' : ''}>Nach Eintreffen einer Einheit</option>
                                <option value="on_missing_resources" ${triggerMode === 'on_missing_resources' ? 'selected' : ''}>Wenn Kräfte fehlen</option>
                                <option value="random" ${triggerMode === 'random' ? 'selected' : ''}>Zufällig im Zeitfenster</option>
                                <option value="manual" ${triggerMode === 'manual' ? 'selected' : ''}>Manuell</option>
                                <option value="on_dispatcher_question" ${triggerMode === 'on_dispatcher_question' ? 'selected' : ''}>Auf Nachfrage des Disponenten</option>
                                <option value="on_transport_started" ${triggerMode === 'on_transport_started' ? 'selected' : ''}>Bei Transportbeginn</option>
                                <option value="on_hospital_arrival" ${triggerMode === 'on_hospital_arrival' ? 'selected' : ''}>Bei Klinikankunft</option>
                                <option value="on_vehicle_available" ${triggerMode === 'on_vehicle_available' ? 'selected' : ''}>Bei Freimeldung</option>
                            </select>
                        </div>
                        <div class="lst-field">
                            <label><strong>Wahrscheinlichkeit</strong></label>
                            <input type="number" class="small-text lst-followup-probability" min="0" max="100" step="1" value="${esc(probability)}"> %
                        </div>
                        <div class="lst-field">
                            <label><strong>Frühestens nach Sekunden</strong></label>
                            <input type="number" min="0" step="1" class="small-text lst-followup-min" value="${esc(minAfter)}">
                        </div>
                        <div class="lst-field">
                            <label><strong>Spätestens nach Sekunden</strong></label>
                            <input type="number" min="0" step="1" class="small-text lst-followup-max" value="${esc(maxAfter)}">
                        </div>
                    </div>
                    <p class="description">Das Zeitfenster zählt je nach Auslöser ab Einsatzbeginn oder ab Eintreffen der ersten Einheit. Die Variante aktualisiert beim Auslösen die sichtbare Lagebeschreibung; bei 0 Sekunden sofort.</p>
                </div>

                <div class="lst-followup-section lst-situation-section">
                    <h4>Was findet das ersteintreffende Fahrzeug vor?</h4>
                    <div class="lst-field">
                        <label><strong>Sichtbarer Lage-/Beschreibungstext</strong></label>
                        <textarea class="large-text lst-followup-text" rows="5" placeholder="Beschreibe die Lage, die das ersteintreffende Fahrzeug per S5 meldet.">${esc(rowText)}</textarea>
                    </div>
                    <label><strong>Rückmeldung an die Leitstelle</strong></label>
                    <textarea class="large-text lst-followup-radio-report" rows="4" placeholder="Was meldet die Einheit der Leitstelle?">${esc(parseJsonValue(row.effect_json || '', {}).radio_report_text || rowText)}</textarea>
                    <p class="description">Diese Rückmeldung wird beim Eintreten dieser Lage gefunkt. Die Lagebeschreibung darüber bleibt getrennt.</p>
                    <div class="lst-patient-editor">
                        <div class="lst-box-header">
                            <h4>Patienten in dieser Variante</h4>
                            <button type="button" class="button button-secondary lst-add-patient" data-patient-target="[data-followup-index='${esc(index)}'] .lst-followup-patient-list">Patient hinzufügen</button>
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
                        <button type="button" class="button button-secondary lst-add-resource" data-target="[data-followup-index='${esc(index)}'] .lst-followup-resource-list">Fahrzeugklasse hinzufügen</button>
                    </div>
                    <div class="lst-resource-list lst-followup-resource-list"></div>
                </div>

                <details class="lst-followup-expert">
                    <summary>Experteneinstellungen</summary>
                    <div class="lst-grid lst-grid-3">
                        <div class="lst-field">
                            <label><strong>Reihenfolge</strong></label>
                            <input type="number" min="1" step="1" class="small-text lst-followup-step" value="${esc(row.step_no || displayNumber)}">
                        </div>
                        <div class="lst-field">
                            <label><strong>Kommunikationsart</strong></label>
                            <select class="lst-followup-kind">
                                <option value="unit_report" ${kind === 'unit_report' ? 'selected' : ''}>Rückmeldung Einheit</option>
                                <option value="dispatcher_question" ${kind === 'dispatcher_question' ? 'selected' : ''}>Rückfrage Disponent</option>
                                <option value="caller_answer" ${kind === 'caller_answer' ? 'selected' : ''}>Antwort Anrufer</option>
                                <option value="update" ${kind === 'update' ? 'selected' : ''}>Lage-Update</option>
                            </select>
                        </div>
                        <div class="lst-field">
                            <label><strong>Wer meldet sich?</strong></label>
                            <select class="lst-followup-speaker">
                                <option value="caller" ${speakerType === 'caller' ? 'selected' : ''}>Anrufer</option>
                                <option value="fire_unit" ${speakerType === 'fire_unit' ? 'selected' : ''}>Feuerwehr</option>
                                <option value="ems_unit" ${speakerType === 'ems_unit' ? 'selected' : ''}>Rettungsdienst</option>
                                <option value="police" ${speakerType === 'police' ? 'selected' : ''}>Polizei</option>
                                <option value="dispatch" ${speakerType === 'dispatch' ? 'selected' : ''}>Leitstelle</option>
                                <option value="system" ${speakerType === 'system' ? 'selected' : ''}>System</option>
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
                                <select class="lst-followup-cond-type">${vehicleTypeOptionsHtml('')}</select>
                            </div>
                            <div class="lst-field">
                                <label><strong>Mindestanzahl</strong></label>
                                <input type="number" min="1" step="1" class="small-text lst-followup-cond-count" value="1">
                            </div>
                        </div>
                    </div>
                    <div class="lst-field">
                        <label><strong>Optionale Folgeeffekte</strong></label>
                        <textarea class="large-text lst-followup-effect-note" rows="2" placeholder="Optionaler Hinweis für spätere Simulationslogik">${esc(effectNoteFromJson(row.effect_json || ''))}</textarea>
                    </div>
                </details>
            </div>
        `);

        const $card = container.find('.lst-followup-row').last();
        if (row.flow_stage) $card.attr('data-flow-stage', row.flow_stage);
        if (row.flow_stage && row.flow_stage !== 'arrival_variant' && row.flow_stage !== 'arrival_default') $card.find('.lst-followup-role').val(row.flow_stage);
        const effect = parseJsonValue(row.effect_json || '', {});
        $card.data('flow-parents', Array.isArray(effect.flow_parent_keys) ? effect.flow_parent_keys : parseJsonValue(row.applicable_initial_keys || [], []));
        $card.data('flow-parent-explicit', true);
        refreshFlowParents();
        const followupSplit = splitPatientAndManualResources(parseJsonValue(row.required_resources_json || '', []));
        followupSplit.manual.forEach(function (resource) {
            addResourceRow($card.find('.lst-followup-resource-list'), resource);
        });
        applyConditionUi($card, row.condition_json || '');
        const effectDecoded = parseJsonValue(row.effect_json || '', {});
        const savedPatientRequirements = patientRequirementsFromJson(row.effect_json || '');
        const legacyFollowupRequirements = mergePatientRequirements(savedPatientRequirements, {
            ktw: followupSplit.patient.krankentransport,
            rtw: followupSplit.patient.rettungswagen,
            notarzt: followupSplit.patient.notarzt
        });
        const followupPatients = normalizePatientRows(effectDecoded && effectDecoded.patients ? effectDecoded.patients : []);
        (followupPatients.length ? followupPatients : derivePatientRows(legacyFollowupRequirements)).forEach(function (patient) {
            addPatientRow($card.find('.lst-followup-patient-list'), patient);
        });
        updateFollowupCardMode($card);
        updateWizardSummary();
    }

    function collectTimeWindows() {
        const out = [];
        $('#lst-time-window-table tbody tr.lst-time-window-row').each(function () {
            out.push({
                day_type: $(this).find('.lst-day-type').val() || '',
                start_time: $(this).find('.lst-start-time').val() || '',
                end_time: $(this).find('.lst-end-time').val() || ''
            });
        });
        return out;
    }

    function collectSeasons() {
        const out = [];
        $('.lst-season:checked').each(function () {
            out.push($(this).val());
        });
        return out;
    }

    function collectWeather() {
        const out = [];
        $('.lst-weather:checked').each(function () {
            out.push($(this).val());
        });
        return out;
    }
	
	function collectLandscapeTagsJson() {
    const values = [];

    $('.lst-landscape-tag:checked').each(function () {
        values.push($(this).val());
    });

    return JSON.stringify(values);
}

    function collectCallerParts() {
        const grouped = {
            greeting: [],
            person: [],
            location: [],
            problem: [],
            observation: [],
            extra: []
        };

        $('.lst-einsatz-part-table, .lst-caller-part-table').each(function () {
            const partKey = $(this).data('part-key');
            if (!grouped[partKey]) {
                grouped[partKey] = [];
            }

            $(this).find('tbody tr.lst-einsatz-part-row, tbody tr.lst-caller-part-row').each(function () {
                const text = ($(this).find('.lst-einsatz-part-text, .lst-caller-part-text').val() || '').trim();
                const sortOrder = parseInt($(this).find('.lst-einsatz-part-sort-order, .lst-caller-part-sort-order').val() || '0', 10);
                const enabled = $(this).find('.lst-einsatz-part-enabled, .lst-caller-part-enabled').is(':checked') ? 1 : 0;

                if (!text) return;

                grouped[partKey].push({
                    text: text,
                    sort_order: isNaN(sortOrder) ? 0 : sortOrder,
                    enabled: enabled
                });
            });
        });

        return grouped;
    }

    function collectCallerProfiles() {
        const rows = [];
        $('#lst-einsatz-profile-assignment .lst-caller-profile-row').each(function () {
            const $row = $(this);
            if (!$row.find('.lst-caller-profile-enabled').is(':checked')) {
                return;
            }

            const profileId = parseInt($row.data('profile-id') || 0, 10);
            const weight = parseInt($row.find('.lst-caller-profile-weight').val() || '100', 10) || 100;
            if (!profileId) {
                return;
            }

            rows.push({
                profile_id: profileId,
                weight: Math.max(1, Math.min(1000, weight))
            });
        });
        return rows;
    }

    function refreshFlowParents() {
        const initial = $('.lst-followup-row').filter(function () { return $(this).find('.lst-followup-role').val() === 'initial' && $(this).attr('data-flow-stage') !== 'arrival_default'; }).map(function () {
            return { key: $(this).attr('data-flow-key'), title: $(this).find('.lst-followup-label').val() || 'Erstlage' };
        }).get();
        const candidates = $('.lst-followup-row').filter(function () { return $(this).find('.lst-followup-role').val() !== 'incident_end'; }).map(function () {
            const $card = $(this);
            return { key: $card.attr('data-flow-key'), title: $card.find('.lst-followup-label').val() || 'Unbenannt' };
        }).get();
        $('.lst-followup-row').each(function () {
            const $card = $(this), $select = $card.find('.lst-followup-parents');
            const selected = $card.data('flow-parents') || $select.val() || [];
            $select.html(candidates.filter(row => row.key !== $card.attr('data-flow-key') && ($card.find('.lst-followup-role').val() !== 'initial' || isFlowCall($('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === row.key; })))).map(row => '<option value="' + esc(row.key) + '">' + esc(row.title) + '</option>').join(''));
            $select.val(selected);
            $card.find('.lst-followup-parents').closest('label').toggle(!isFlowCall($card) && $card.attr('data-flow-stage') !== 'arrival_default');
            $card.removeData('flow-parents');
        });
        const sum = $('.lst-followup-row').filter(function () { return $(this).find('.lst-followup-role').val() === 'initial' && $(this).attr('data-flow-stage') !== 'arrival_default'; }).toArray().reduce(function (value, el) {
            return value + Number($(el).find('.lst-followup-probability').val() || 0);
        }, 0);
        const count = initial.length;
        const message = 'Eintreffvarianten: ' + sum + ' %, Standardlage: ' + Math.max(0, 100 - sum) + ' %';
        $('#lst-flow-probability-sum, #lst-flow-graph-validation').text(message).toggleClass('lst-flow-invalid', sum > 100);
    }

    function flowPositions() {
        try { return JSON.parse(localStorage.getItem(flowLayoutKey()) || '{}'); }
        catch (e) { return {}; }
    }
    function saveFlowPosition(key, point) {
        const positions = flowPositions(); positions[key] = point;
        localStorage.setItem(flowLayoutKey(), JSON.stringify(positions));
    }
    let flowMountedNode = null;
    let flowMountPlaceholder = null;
    function restoreFlowProperties() {
        if (flowMountedNode && flowMountPlaceholder && flowMountPlaceholder.parentNode) {
            $(flowMountPlaceholder).before(flowMountedNode);
            $(flowMountPlaceholder).remove();
        }
        flowMountedNode = null;
        flowMountPlaceholder = null;
    }
    function isFlowCall($card) {
        return $card.find('.lst-followup-role').val() === 'followup_call'
            || $card.find('.lst-followup-kind').val() === 'caller_answer'
            || $card.find('.lst-followup-speaker').val() === 'caller';
    }
    function flowLayoutKey() {
        return 'lst_einsatz_flow_positions_v5_' + ($('#lst-einsatz-id').val() || 'new');
    }
    function flowWouldCycle(from, to) {
        const seen = new Set();
        function visit(key) {
            if (key === to) return true;
            if (seen.has(key)) return false;
            seen.add(key);
            const $card = $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === key; });
            return ($card.find('.lst-followup-parents').val() || []).some(visit);
        }
        return visit(from);
    }
    function renderFlowGraph() {
        if (flowView !== 'graph' || !$('#lst-flow-canvas').length) return;
        restoreFlowProperties();
        refreshFlowParents();
        const $canvas = $('#lst-flow-canvas'), $nodes = $canvas.find('.lst-flow-nodes').empty(), $svg = $canvas.find('svg').empty();
        const positions = flowPositions();
        const cards = $('.lst-followup-row').toArray().map(el => $(el));
        const arrivalDefault = cards.find($card => $card.attr('data-flow-stage') === 'arrival_default');
        const initials = cards.filter($card => $card.find('.lst-followup-role').val() === 'initial' && $card.attr('data-flow-stage') !== 'arrival_default');
        const calls = cards.filter($card => $card.find('.lst-followup-role').val() !== 'initial' && isFlowCall($card))
            .sort(($a, $b) => Number($a.find('.lst-followup-min').val() || 0) - Number($b.find('.lst-followup-min').val() || 0)
                || Number($a.find('.lst-followup-step').val() || 0) - Number($b.find('.lst-followup-step').val() || 0));
        const developments = cards.filter($card => $card.find('.lst-followup-role').val() === 'development' && !isFlowCall($card));
        const finalSituations = cards.filter($card => $card.find('.lst-followup-role').val() === 'final_situation');
        const endings = cards.filter($card => $card.find('.lst-followup-role').val() === 'incident_end');
        const nodeWidth = 260, nodeHeight = 124, columnGap = 320, rowGap = 210;
        const centerX = 380, top = 30;
        const points = { call: positions.call || { x: centerX, y: top } };
        calls.forEach(($card, i) => {
            const key = $card.attr('data-flow-key');
            points[key] = positions[key] || { x: centerX, y: top + (i + 1) * rowGap };
        });
        const foundationY = top + (calls.length + 1) * rowGap;
        points.foundation = positions.foundation || { x: centerX, y: foundationY };
        const arrivalY = foundationY + rowGap;
        if (arrivalDefault) points[arrivalDefault.attr('data-flow-key')] = positions[arrivalDefault.attr('data-flow-key')] || { x: centerX, y: arrivalY };
        const initialY = arrivalY + rowGap;
        initials.forEach(($card, i) => {
            const key = $card.attr('data-flow-key');
            const columns = Math.min(3, initials.length);
            points[key] = positions[key] || { x: 60 + (i % columns) * columnGap, y: initialY + Math.floor(i / columns) * rowGap };
        });
        const developmentY = initialY + Math.max(1, Math.ceil(initials.length / 3)) * rowGap;
        const developmentByKey = new Map(developments.map($card => [$card.attr('data-flow-key'), $card]));
        const developmentDepth = ($card, seen = new Set()) => {
            const key = $card.attr('data-flow-key');
            if (seen.has(key)) return 0;
            seen.add(key);
            const parents = $card.find('.lst-followup-parents').val() || [];
            return parents.reduce((depth, parent) => Math.max(depth, developmentByKey.has(parent) ? 1 + developmentDepth(developmentByKey.get(parent), new Set(seen)) : 0), 0);
        };
        const columnsByDepth = {};
        let maxDevelopmentDepth = 0;
        developments.forEach($card => {
            const key = $card.attr('data-flow-key'), depth = developmentDepth($card);
            const column = columnsByDepth[depth] || 0;
            columnsByDepth[depth] = column + 1;
            maxDevelopmentDepth = Math.max(maxDevelopmentDepth, depth);
            points[key] = positions[key] || { x: 60 + column * columnGap, y: developmentY + depth * rowGap };
        });
        const finalSituationY = developmentY + (maxDevelopmentDepth + 1) * rowGap;
        finalSituations.forEach(($card, i) => {
            const key = $card.attr('data-flow-key');
            points[key] = positions[key] || { x: centerX + i * columnGap, y: finalSituationY };
        });
        const endY = finalSituationY + rowGap;
        endings.forEach(($card, i) => {
            const key = $card.attr('data-flow-key');
            points[key] = positions[key] || { x: centerX + i * columnGap, y: endY };
        });
        const all = [
            { key: 'call', title: 'Erstanruf', type: 'call', text: 'Anruferprofile und Meldungsbausteine', $card: null },
            { key: 'foundation', title: 'Grundlage', type: 'foundation', text: $('#lst-lagemeldung').val(), $card: null }
        ].concat(cards.map($card => ({
            key: $card.attr('data-flow-key'),
            title: $card.find('.lst-followup-label').val() || 'Unbenannt',
            type: $card.attr('data-flow-stage') === 'arrival_default' ? 'arrival-base' : ($card.find('.lst-followup-role').val() === 'initial' ? 'initial' : (isFlowCall($card) ? 'call-update' : $card.find('.lst-followup-role').val())),
            text: isFlowCall($card) ? $card.find('.lst-followup-radio-report').val() : $card.find('.lst-followup-text').val(), $card
        })));
        all.forEach(node => {
            const point = points[node.key];
            const probability = node.type === 'initial' ? ' · ' + node.$card.find('.lst-followup-probability').val() + ' %' : '';
            $('<div role="button" tabindex="0" class="lst-flow-node lst-flow-node--' + node.type + '"><strong>' + esc(node.title) + probability + '</strong><span class="lst-flow-node-text">' + esc(node.text || (node.type === 'call' ? 'Notruf bearbeiten' : 'Keine Beschreibung')) + '</span>'
                + (['initial', 'arrival-base', 'development', 'final_situation', 'call-update'].includes(node.type) ? '<span class="lst-flow-port lst-flow-port--out" data-flow-port-out="' + esc(node.key) + '" title="Verbindung ziehen" aria-label="Verbindung ziehen"></span>' : '')
                + (['initial', 'arrival-base', 'development', 'final_situation', 'incident_end'].includes(node.type) ? '<span class="lst-flow-port lst-flow-port--in" data-flow-port-in="' + esc(node.key) + '" title="Verbindung hier ablegen"></span>' : '') + '</div>')
                .attr('data-flow-node', node.key).css({ left: point.x, top: point.y }).toggleClass('is-selected', node.key === flowSelectedKey).appendTo($nodes);
        });
        const edges = [];
        let previous = 'call';
        calls.forEach($card => {
            const key = $card.attr('data-flow-key');
            edges.push([previous, key, 'call']);
            previous = key;
        });
        edges.push([previous, 'foundation', 'call']);
        if (arrivalDefault) edges.push(['foundation', arrivalDefault.attr('data-flow-key'), 'situation']);
        initials.forEach($card => { if (arrivalDefault) edges.push([arrivalDefault.attr('data-flow-key'), $card.attr('data-flow-key'), 'situation']); });
        const linked = initials.concat(developments, finalSituations, endings);
        linked.forEach($card => {
            const selectedParents = $card.find('.lst-followup-parents').val() || [];
            const parents = selectedParents;
            parents.forEach(parent => edges.push([parent, $card.attr('data-flow-key'), 'editable']));
        });
        const width = Math.max(1040, ...Object.values(points).map(point => point.x + nodeWidth + 50));
        const height = Math.max(720, ...Object.values(points).map(point => point.y + nodeHeight + 80));
        $nodes.css({ width, height });
        $svg.attr({ width, height, viewBox: '0 0 ' + width + ' ' + height });
        $canvas.find('.lst-flow-viewport').css({ width, height, transform: 'scale(' + flowZoom + ')', transformOrigin: 'top left' });
        $('#lst-flow-zoom-value').text(Math.round(flowZoom * 100) + ' %');
        $svg.prepend('<defs><marker id="lst-flow-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10 Z" fill="#64748b"/></marker><marker id="lst-flow-arrow-call" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10 Z" fill="#0f766e"/></marker></defs>');
        edges.forEach(([from, to, kind]) => {
            if (!points[from] || !points[to]) return;
            const a = points[from], b = points[to];
            const x1 = a.x + nodeWidth / 2, y1 = a.y + nodeHeight;
            const x2 = b.x + nodeWidth / 2, y2 = b.y;
            const bend = Math.max(45, (y2 - y1) / 2);
            const curve = 'M ' + x1 + ' ' + y1 + ' C ' + x1 + ' ' + (y1 + bend) + ', ' + x2 + ' ' + (y2 - bend) + ', ' + x2 + ' ' + y2;
            $(document.createElementNS('http://www.w3.org/2000/svg', 'path')).attr({
                d: curve,
                fill: 'none', stroke: kind === 'call' ? '#0f766e' : '#64748b',
                'stroke-width': 2.5, 'marker-end': kind === 'call' ? 'url(#lst-flow-arrow-call)' : 'url(#lst-flow-arrow)'
            }).appendTo($svg);
            if (kind === 'editable') {
                $(document.createElementNS('http://www.w3.org/2000/svg', 'path')).attr({
                    d: curve, fill: 'none', stroke: 'transparent', 'stroke-width': 18,
                    class: 'lst-flow-edge-remove', 'data-flow-from': from, 'data-flow-to': to,
                    role: 'button', 'aria-label': 'Verbindung entfernen'
                }).appendTo($svg).append(document.createElementNS('http://www.w3.org/2000/svg', 'title')).find('title').text('Verbindung entfernen');
            }
        });
        const selected = all.find(node => node.key === flowSelectedKey) || all[0];
        const $properties = $('#lst-flow-properties');
        $properties.empty().append('<h3>Eigenschaften: ' + esc(selected.title) + '</h3>');
        if (selected.$card) {
            const parents = selected.$card.find('.lst-followup-parents').val() || [];
            if (parents.length) {
                const $links = $('<div class="lst-flow-parent-links"><strong>Verbindungen von</strong></div>').appendTo($properties);
                parents.forEach(parent => {
                    const source = all.find(node => node.key === parent);
                    $('<div class="lst-flow-parent-link"></div>').append(
                        $('<span></span>').text(source ? source.title : parent),
                        $('<button type="button" class="button button-small">Entfernen</button>').attr({ 'data-flow-remove-from': parent, 'data-flow-remove-to': selected.key })
                    ).appendTo($links);
                });
            }
        }
        flowMountedNode = selected.type === 'call' ? $('.lst-editor-split--caller')[0]
            : (selected.type === 'foundation' ? $('.lst-situation-flow')[0] : selected.$card[0]);
        if (flowMountedNode) {
            flowMountPlaceholder = document.createComment('Lage-Eigenschaften im Graph');
            $(flowMountedNode).before(flowMountPlaceholder);
            $(flowMountedNode).detach().appendTo($properties);
        }

    }

    function collectFollowups() {
        const rows = [];
        $('#lst-followup-cards .lst-followup-row').each(function () {
            const $card = $(this);
            const patientRows = collectPatientRows($card.find('.lst-followup-patient-list'));
            const patientRequirements = patientRequirementsFromRows(patientRows);
            const patientResources = patientResourcesFromCounts(patientRequirements.ktw, patientRequirements.rtw, patientRequirements.notarzt);
            rows.push({
                flow_key: $card.attr('data-flow-key'),
                flow_role: $card.find('.lst-followup-role').val() === 'initial' ? 'initial' : 'development',
                applicable_initial_keys: $card.find('.lst-followup-role').val() === 'initial' || isFlowCall($card) ? [] : ($card.find('.lst-followup-parents').val() || []).filter(key => $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === key; }).find('.lst-followup-role').val() === 'initial'),
                step_no: $card.find('.lst-followup-step').val() || '',
                label: $card.find('.lst-followup-label').val() || '',
                kind: $card.find('.lst-followup-role').val() === 'followup_call' ? 'caller_answer' : ($card.find('.lst-followup-kind').val() || 'unit_report'),
                text: $card.find('.lst-followup-text').val() || '',
                min_after_sec: $card.find('.lst-followup-min').val() || '',
                max_after_sec: $card.find('.lst-followup-max').val() || '',
                probability_percent: $card.find('.lst-followup-probability').val() === '' ? 100 : Number($card.find('.lst-followup-probability').val()),
                speaker_type: $card.find('.lst-followup-role').val() === 'followup_call' ? 'caller' : ($card.find('.lst-followup-speaker').val() || 'fire_unit'),
                trigger_mode: $card.find('.lst-followup-trigger').val() || 'on_unit_arrival',
                condition_json: collectConditionJson($card),
                required_resources_json: JSON.stringify(mergeResourceRows(patientResources, collectResources($card.find('.lst-followup-resource-list')))),
                effect_json: buildEffectJson($card.find('.lst-followup-effect-note').val() || '', patientRequirements, patientRows, $card.attr('data-flow-stage') || $card.find('.lst-followup-role').val(), $card.find('.lst-followup-parents').val() || [], $card.find('.lst-followup-radio-report').val())
            });
        });
        return rows;
    }

    function renderCallerPreview() {
        const list = $('#lst-caller-preview-list');
        list.empty().append('<li>Vorschau wird erzeugt...</li>');

        postAjax({
            action: 'lst_preview_einsatz_caller',
            caller_parts: JSON.stringify(collectCallerParts()),
            caller_profiles: JSON.stringify(collectCallerProfiles())
        })
        .done(function (res) {
            list.empty();
            if (!res || !res.success) {
                list.append('<li>Vorschau konnte nicht erzeugt werden.</li>');
                return;
            }
            const examples = Array.isArray(res.data.examples) ? res.data.examples : [];
            if (!examples.length) {
                list.append('<li>Bitte zuerst mindestens einen aktiven Meldungsbaustein eintragen.</li>');
                return;
            }
            examples.forEach(function (text) {
                list.append('<li>' + esc(text) + '</li>');
            });
        })
        .fail(function () {
            list.empty().append('<li>Vorschau konnte nicht erzeugt werden.</li>');
        });
    }

    function restoreEmptyRows() {
        const twBody = $('#lst-time-window-table tbody');
        if (twBody.length && !twBody.find('tr').length) {
            twBody.append('<tr class="lst-time-window-empty-row"><td colspan="4">Noch keine Zeitfenster vorhanden.</td></tr>');
        }

        $('.lst-einsatz-part-table, .lst-caller-part-table').each(function () {
            const tbody = $(this).find('tbody');
            if (!tbody.find('tr').length) {
                tbody.append('<tr class="lst-einsatz-part-empty-row"><td colspan="4">Noch keine Bausteine.</td></tr>');
            }
        });

        const fuCards = $('#lst-followup-cards');
        if (fuCards.length && !$('.lst-followup-row').length) {
            fuCards.html(`
                <div class="lst-followup-empty-row lst-empty-state">
                    <h4>Noch keine Lagevarianten</h4>
                    <p>Lege eine Variante an, wenn sich die Lage nach Einsatzbeginn ändern kann, z.B. durch eine Rückmeldung nach Eintreffen oder zusätzlichen Kräftebedarf.</p>
                    <button type="button" class="button button-primary lst-add-followup-inline">Erste Lagevariante hinzufügen</button>
                </div>
            `);
        }
    }

    function ensureMapLayer() {
        if (!vectorSource) {
            vectorSource = new ol.source.Vector();
        }
        if (!vectorLayer) {
            vectorLayer = new ol.layer.Vector({ source: vectorSource });
        }
    }

    function initMapIfNeeded() {
        const mapEl = document.getElementById('lst-einsatz-map');
        if (!mapEl || map) return;

        ensureMapLayer();

        map = new ol.Map({
            target: 'lst-einsatz-map',
            layers: [
                new ol.layer.Tile({ source: new ol.source.OSM() }),
                vectorLayer
            ],
            view: new ol.View({
                center: ol.proj.fromLonLat([10.4515, 51.1657]),
                zoom: 6
            })
        });

        map.on('click', function (evt) {
            const lonLat = ol.proj.toLonLat(evt.coordinate);
            $('#lst-fixed-latitude').val(lonLat[1].toFixed(6));
            $('#lst-fixed-longitude').val(lonLat[0].toFixed(6));
            syncMapFromFields();
        });
    }

    function syncMapFromFields() {
        if (!map || !vectorSource) return;

        const lat = parseFloat($('#lst-fixed-latitude').val() || '');
        const lon = parseFloat($('#lst-fixed-longitude').val() || '');
        const radius = parseFloat($('#lst-fixed-radius').val() || '');

        vectorSource.clear();

        if (isNaN(lat) || isNaN(lon)) {
            map.updateSize();
            return;
        }

        const center = ol.proj.fromLonLat([lon, lat]);

        vectorSource.addFeature(new ol.Feature({
            geometry: new ol.geom.Point(center)
        }));

        if (!isNaN(radius) && radius > 0) {
            vectorSource.addFeature(new ol.Feature({
                geometry: new ol.geom.Circle(center, radius)
            }));
        }

        map.getView().setCenter(center);
        map.getView().setZoom(radius > 0 ? 15 : 16);
        map.updateSize();
    }

    function bindDynamicUi() {
        $(document).off('.lstEinsatzDyn');
        flowView = 'form'; flowSelectedKey = 'foundation'; flowZoom = 1;
        $(document).on('change.lstEinsatzDyn', '#lst-einsatz-leitstelle', function () {
            $('#lst-stichwort-id').val('');
            loadStichwoerter(0);
        });
        $(document).on('change.lstEinsatzDyn', '#lst-stichwort-id', function () {
            const row = currentStichwoerter.find(item => String(item.id) === String($(this).val()));
            if (!row) return;
            const previous = $('#lst-einsatztyp').val().trim();
            let title = $('#lst-title').val().trim();
            if (previous && title.indexOf(previous + ' – ') === 0) title = title.slice(previous.length + 3);
            if (title.indexOf(row.code + ' – ') !== 0) title = row.code + ' – ' + (title || row.bezeichnung);
            $('#lst-title').val(title);
            $('#lst-einsatztyp').val(row.code);
            $('#lst-einsatzart').val(row.einsatzart);
            $('#lst-base-resource-list').empty();
            (row.fahrzeuge || []).forEach(resource => addResourceRow('#lst-base-resource-list', resource));
            updateBaseResourceSummary(); updateWizardSummary();
        });
        $(document).on('click.lstEinsatzDyn', '#lst-einsatz-modal [data-lst-flow-view]', function (event) {
            event.preventDefault();
            const $panel = $(this).closest('.lst-tab-panel--situation');
            flowView = $(this).attr('data-lst-flow-view') === 'graph' ? 'graph' : 'form';
            $panel.find('.lst-flow-form').toggle(flowView === 'form');
            const $graph = $panel.find('#lst-flow-graph');
            if (flowView === 'graph') {
                $graph.removeAttr('hidden').show();
            } else {
                restoreFlowProperties();
                $graph.attr('hidden', 'hidden').hide();
            }
            $panel.find('[data-lst-flow-view]').each(function () {
                const active = $(this).attr('data-lst-flow-view') === flowView;
                $(this).toggleClass('button-primary', active).attr('aria-pressed', active ? 'true' : 'false');
            });
            if (flowView === 'graph') {
                try {
                    renderFlowGraph();
                } catch (error) {
                    console.error('Lage-Graph konnte nicht gezeichnet werden', error);
                    $graph.find('#lst-flow-properties').text('Der Graph konnte nicht gezeichnet werden. Bitte den Einsatzeditor erneut öffnen.');
                }
            }
        });
        const flowCanvas = document.getElementById('lst-flow-canvas');
        if (flowCanvas) {
            flowCanvas.addEventListener('wheel', function (event) {
                if (flowView !== 'graph') return;
                event.preventDefault();
                const bounds = flowCanvas.getBoundingClientRect();
                const cursorX = event.clientX - bounds.left;
                const cursorY = event.clientY - bounds.top;
                const contentX = (flowCanvas.scrollLeft + cursorX) / flowZoom;
                const contentY = (flowCanvas.scrollTop + cursorY) / flowZoom;
                const next = Math.max(0.5, Math.min(2, Math.round((flowZoom + (event.deltaY < 0 ? 0.1 : -0.1)) * 100) / 100));
                if (next === flowZoom) return;
                flowZoom = next;
                renderFlowGraph();
                flowCanvas.scrollLeft = contentX * flowZoom - cursorX;
                flowCanvas.scrollTop = contentY * flowZoom - cursorY;
            }, { passive: false });
        }
        $(document).on('mousedown.lstEinsatzDyn', '#lst-flow-canvas', function (event) {
            if (event.button !== 1) return;
            event.preventDefault();
            const canvas = this;
            const startX = event.clientX, startY = event.clientY;
            const scrollX = canvas.scrollLeft, scrollY = canvas.scrollTop;
            $(canvas).addClass('is-panning');
            $(document).off('.lstFlowPan').on('mousemove.lstFlowPan', function (move) {
                move.preventDefault();
                canvas.scrollLeft = scrollX - (move.clientX - startX);
                canvas.scrollTop = scrollY - (move.clientY - startY);
            }).one('mouseup.lstFlowPan', function () {
                $(canvas).removeClass('is-panning');
                $(document).off('.lstFlowPan');
            });
        });
        $(document).on('auxclick.lstEinsatzDyn', '#lst-flow-canvas', function (event) {
            if (event.button === 1) event.preventDefault();
        });
        $(document).on('click.lstEinsatzDyn', '[data-lst-flow-zoom]', function () {
            const action = $(this).attr('data-lst-flow-zoom');
            flowZoom = action === 'reset' ? 1 : Math.max(0.5, Math.min(2, Math.round((flowZoom + (action === 'in' ? 0.25 : -0.25)) * 100) / 100));
            renderFlowGraph();
        });
        $(document).on('click.lstEinsatzDyn', '[data-lst-flow-arrange]', function () {
            localStorage.removeItem(flowLayoutKey());
            flowZoom = 1;
            renderFlowGraph();
            const canvas = document.getElementById('lst-flow-canvas');
            if (canvas) { canvas.scrollLeft = 0; canvas.scrollTop = 0; }
        });
        $(document).on('change.lstEinsatzDyn', '.lst-followup-kind, .lst-followup-speaker, .lst-followup-min, .lst-followup-step', function () {
            if (flowView === 'graph') renderFlowGraph();
        });
        $(document).on('click.lstEinsatzDyn', '[data-lst-add-flow]', function () {
            const role = $(this).data('lst-add-flow');
            const parentKey = flowSelectedKey;
            addFollowupRow({ flow_role: role === 'arrival_variant' ? 'initial' : 'development', flow_stage: ['arrival_variant', 'final_situation', 'incident_end', 'followup_call'].includes(role) ? role : '', kind: role === 'followup_call' ? 'caller_answer' : 'unit_report', speaker_type: role === 'followup_call' ? 'caller' : 'fire_unit', trigger_mode: role === 'arrival_variant' ? 'on_unit_arrival' : 'random', probability_percent: role === 'arrival_variant' ? 0 : 100 });
            const $newCard = $('#lst-followup-cards .lst-followup-row').last();
            $newCard.data('flow-parent-explicit', true);
            flowSelectedKey = $newCard.attr('data-flow-key');
            renderFlowGraph();
        });
        $(document).on('change.lstEinsatzDyn input.lstEinsatzDyn', '.lst-followup-role, .lst-followup-label, .lst-followup-probability, .lst-followup-parents, .lst-followup-text, .lst-followup-radio-report, #lst-lagemeldung', function () {
            if ($(this).is('.lst-followup-role')) {
                const role = $(this).val();
                $(this).closest('.lst-followup-row').attr('data-flow-stage',
                    role === 'initial' ? 'arrival_variant' : (['followup_call', 'final_situation', 'incident_end'].includes(role) ? role : ''));
            }
            if ($(this).is('.lst-followup-role') && $(this).val() === 'followup_call') {
                $(this).closest('.lst-followup-row').find('.lst-followup-kind').val('caller_answer');
                $(this).closest('.lst-followup-row').find('.lst-followup-speaker').val('caller');
                $(this).closest('.lst-followup-row').find('.lst-followup-trigger').val('random');
            } else if ($(this).is('.lst-followup-role') && $(this).closest('.lst-followup-row').find('.lst-followup-kind').val() === 'caller_answer') {
                $(this).closest('.lst-followup-row').find('.lst-followup-kind').val('unit_report');
                $(this).closest('.lst-followup-row').find('.lst-followup-speaker').val('fire_unit');
            }
            if ($(this).is('.lst-followup-role') && $(this).val() === 'initial') $(this).closest('.lst-followup-row').find('.lst-followup-trigger').val('on_unit_arrival');
            if ($(this).is('.lst-followup-role') && $(this).val() === 'development' && $(this).closest('.lst-followup-row').find('.lst-followup-trigger').val() === 'on_unit_arrival') $(this).closest('.lst-followup-row').find('.lst-followup-trigger').val('random');
            if ($(this).is('.lst-followup-role')) updateFollowupCardMode($(this).closest('.lst-followup-row'));
            refreshFlowParents();
            if (flowView !== 'graph') return;
            if ($(this).is('.lst-followup-role, .lst-followup-parents')) {
                if ($(this).is('.lst-followup-parents')) $(this).closest('.lst-followup-row').data('flow-parent-explicit', true);
                renderFlowGraph();
                return;
            }
            const $card = $(this).closest('.lst-followup-row');
            const key = $card.attr('data-flow-key') || 'foundation';
            const title = key === 'foundation' ? 'Grundlage' : ($card.find('.lst-followup-label').val() || 'Unbenannt');
            const probability = $card.find('.lst-followup-role').val() === 'initial' ? ' · ' + $card.find('.lst-followup-probability').val() + ' %' : '';
            const $node = $('.lst-flow-node').filter(function () { return $(this).attr('data-flow-node') === key; });
            $node.find('strong').text(title + probability);
            $node.find('.lst-flow-node-text').text(key === 'foundation' ? ($('#lst-lagemeldung').val() || 'Keine Lagebeschreibung') : (isFlowCall($card) ? ($card.find('.lst-followup-radio-report').val() || 'Kein Anruftext') : ($card.find('.lst-followup-text').val() || 'Keine Lagebeschreibung')));
            if (key === flowSelectedKey) $('#lst-flow-properties > h3').text('Eigenschaften: ' + title);
        });
        $(document).on('click.lstEinsatzDyn keydown.lstEinsatzDyn', '[data-flow-node]', function (event) {
            if ($(event.target).closest('[data-flow-port-out], [data-flow-port-in]').length) return;
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
            if (event.type === 'keydown') event.preventDefault();
            flowSelectedKey = $(this).attr('data-flow-node');
            renderFlowGraph();
        });
        function removeFlowConnection(from, to) {
            const $card = $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === to; });
            if (!$card.length) return;
            const $parents = $card.find('.lst-followup-parents');
            $parents.val(($parents.val() || []).filter(key => key !== from));
            $card.data('flow-parent-explicit', true);
            flowSelectedKey = to;
            renderFlowGraph();
        }
        $(document).on('click.lstEinsatzDyn', '.lst-flow-edge-remove', function (event) {
            event.preventDefault();
            event.stopPropagation();
            removeFlowConnection($(this).attr('data-flow-from'), $(this).attr('data-flow-to'));
        });
        $(document).on('click.lstEinsatzDyn', '[data-flow-remove-from]', function () {
            removeFlowConnection($(this).attr('data-flow-remove-from'), $(this).attr('data-flow-remove-to'));
        });
        $(document).on('mousedown.lstEinsatzDyn', '[data-flow-port-out]', function (event) {
            if (event.button !== 0) return;
            event.preventDefault();
            event.stopPropagation();
            const from = $(this).attr('data-flow-port-out');
            const canvas = document.getElementById('lst-flow-canvas');
            const viewport = $(canvas).find('.lst-flow-viewport')[0];
            const svg = $(canvas).find('.lst-flow-edges');
            const origin = this.getBoundingClientRect();
            const sourceX = (origin.left + origin.width / 2 - viewport.getBoundingClientRect().left) / flowZoom;
            const sourceY = (origin.top + origin.height / 2 - viewport.getBoundingClientRect().top) / flowZoom;
            const preview = $(document.createElementNS('http://www.w3.org/2000/svg', 'path')).attr({
                fill: 'none', stroke: '#1d4ed8', 'stroke-width': 3, 'stroke-dasharray': '7 5'
            }).appendTo(svg);
            $(canvas).addClass('is-connecting');
            $(document).off('.lstFlowConnect').on('mousemove.lstFlowConnect', function (move) {
                const bounds = viewport.getBoundingClientRect();
                const x = (move.clientX - bounds.left) / flowZoom;
                const y = (move.clientY - bounds.top) / flowZoom;
                preview.attr('d', 'M ' + sourceX + ' ' + sourceY + ' C ' + sourceX + ' ' + (sourceY + 80) + ', ' + x + ' ' + (y - 80) + ', ' + x + ' ' + y);
            }).one('mouseup.lstFlowConnect', function (up) {
                const target = $(document.elementFromPoint(up.clientX, up.clientY)).closest('[data-flow-port-in], .lst-flow-node--initial, .lst-flow-node--development, .lst-flow-node--final_situation, .lst-flow-node--incident_end');
                const to = target.attr('data-flow-port-in') || target.attr('data-flow-node');
                const $card = $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === to; });
                if ($card.length && to !== from && (['development', 'final_situation', 'incident_end'].includes($card.find('.lst-followup-role').val()) || ($card.find('.lst-followup-role').val() === 'initial' && $card.attr('data-flow-stage') !== 'arrival_default' && isFlowCall($('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === from; })))) && !flowWouldCycle(from, to)) {
                    const current = $card.find('.lst-followup-parents').val() || [];
                    $card.find('.lst-followup-parents').val(Array.from(new Set(current.concat(from))));
                    $card.data('flow-parent-explicit', true);
                    flowSelectedKey = to;
                }
                $(canvas).removeClass('is-connecting');
                $(document).off('.lstFlowConnect');
                renderFlowGraph();
            });
        });
        $(document).on('mousedown.lstEinsatzDyn', '[data-flow-node]', function (event) {
            if (event.button !== 0 || $(event.target).closest('[data-flow-port-out], [data-flow-port-in]').length) return;
            const key = $(this).attr('data-flow-node'), startX = event.clientX, startY = event.clientY;
            flowSelectedKey = key;
            $('.lst-flow-node').removeClass('is-selected');
            $(this).addClass('is-selected');
            const old = flowPositions()[key] || { x: parseInt($(this).css('left'), 10), y: parseInt($(this).css('top'), 10) };
            const move = function (e) { saveFlowPosition(key, { x: Math.max(0, old.x + (e.clientX - startX) / flowZoom), y: Math.max(0, old.y + (e.clientY - startY) / flowZoom) }); renderFlowGraph(); };
            $(document).on('mousemove.lstFlowDrag', move).one('mouseup.lstFlowDrag', function () { $(document).off('.lstFlowDrag'); });
        });
        $(document).on('input.lstEinsatzDyn change.lstEinsatzDyn', '[data-lst-flow-property]', function (event) {
            const key = flowSelectedKey, $card = $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === key; });
            const field = $(this).data('lst-flow-property');
            const selector = { title: '.lst-followup-label', text: '.lst-followup-text', probability: '.lst-followup-probability', parents: '.lst-followup-parents' }[field];
            if (key === 'foundation' && field === 'text') $('#lst-lagemeldung').val($(this).val());
            else if ($card.length && selector) $card.find(selector).val($(this).val());
            if (field === 'parents' || (event.type === 'change') && (field === 'title' || field === 'probability')) renderFlowGraph();
        });
        $(document).on('click.lstEinsatzDyn', '[data-lst-flow-add-resource]', function () {
            const type = $('#lst-flow-properties [data-lst-flow-resource-type]').val();
            if (!type) return;
            const $card = $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === flowSelectedKey; });
            const target = flowSelectedKey === 'foundation' ? '#lst-base-resource-list' : $card.find('.lst-followup-resource-list');
            addResourceRow(target, { type: type, count: 1 });
            renderFlowGraph();
        });
        $(document).on('click.lstEinsatzDyn', '[data-lst-flow-open-form]', function () {
            $('[data-lst-flow-view="form"]').trigger('click');
            const $card = $('.lst-followup-row').filter(function () { return $(this).attr('data-flow-key') === flowSelectedKey; });
            if ($card.length) $card[0].scrollIntoView({ block: 'center' });
        });

        $(document).on('click.lstEinsatzDyn', '.lst-tab-btn', function () {
            setTab($(this).data('tab'));
        });

        $(document).on('click.lstEinsatzDyn', '[data-lst-wizard-prev]', function () {
            moveWizard(-1);
        });

        $(document).on('click.lstEinsatzDyn', '[data-lst-wizard-next]', function () {
            moveWizard(1);
        });

        $(document).on('change.lstEinsatzDyn', 'input[name="lst_scope_type"]', function () {
            setScopePanels($(this).val());
            updateWizardSummary();
        });

        $(document).on('click.lstEinsatzDyn', '#lst-add-time-window', function () {
            addTimeWindowRow({ day_type: 'weekday', start_time: '', end_time: '' });
        });

        $(document).on('click.lstEinsatzDyn', '.lst-einsatz-add-part, .lst-add-caller-part', function () {
            addCallerRow($(this).data('part-key'), {});
        });

        $(document).on('click.lstEinsatzDyn', '#lst-add-followup', function () {
            addFollowupRow({});
        });

        $(document).on('click.lstEinsatzDyn', '.lst-add-followup-inline', function () {
            addFollowupRow({});
        });

        $(document).on('change.lstEinsatzDyn', '.lst-followup-kind', function () {
            updateFollowupCardMode($(this).closest('.lst-followup-row'));
            updateWizardSummary();
        });

        $(document).on('click.lstEinsatzDyn', '.lst-add-resource', function () {
            addResourceRow($(this).data('target'), {});
        });

        $(document).on('click.lstEinsatzDyn', '.lst-add-patient', function () {
            const $target = $($(this).data('patient-target'));
            addPatientRow($target, {});
            queuePatientHospitalPreview($target);
            updateBaseResourceSummary();
            updateFollowupCardSummary($(this).closest('.lst-followup-row'));
            updateWizardSummary();
        });

        $(document).on('click.lstEinsatzDyn', '.lst-remove-patient', function () {
            const $card = $(this).closest('.lst-followup-row');
            const $scope = hospitalRoutingScope($(this).closest('.lst-patient-editor-row'));
            $(this).closest('.lst-patient-editor-row').remove();
            queuePatientHospitalPreview($scope);
            updateBaseResourceSummary();
            updateFollowupCardSummary($card);
            updateWizardSummary();
        });

        $(document).on('click.lstEinsatzDyn', '.lst-resource-minus', function () {
            const input = $(this).siblings('.lst-resource-count');
            const current = parseInt(input.val() || '1', 10) || 1;
            input.val(Math.max(1, current - 1)).trigger('change');
        });

        $(document).on('click.lstEinsatzDyn', '.lst-resource-plus', function () {
            const input = $(this).siblings('.lst-resource-count');
            const current = parseInt(input.val() || '1', 10) || 1;
            input.val(current + 1).trigger('change');
        });

        $(document).on('click.lstEinsatzDyn', '.lst-remove-resource', function () {
            const $card = $(this).closest('.lst-followup-row');
            $(this).closest('.lst-resource-row').remove();
            updateBaseResourceSummary();
            updateFollowupCardSummary($card);
            updateWizardSummary();
        });

        $(document).on('change.lstEinsatzDyn input.lstEinsatzDyn', '.lst-resource-type, .lst-resource-count', function () {
            updateBaseResourceSummary();
            updateFollowupCardSummary($(this).closest('.lst-followup-row'));
            updateWizardSummary();
        });

        $(document).on('click.lstEinsatzDyn', '.lst-remove-row', function () {
            const $row = $(this).closest('tr, .lst-followup-row');
            const removedSelected = $row[0] === flowMountedNode;
            if (removedSelected) {
                $(flowMountPlaceholder).remove();
                flowMountedNode = null;
                flowMountPlaceholder = null;
                flowSelectedKey = 'foundation';
            }
            $row.remove();
            restoreEmptyRows();
            updateWizardSummary();
            refreshFlowParents();
            if (flowView === 'graph') renderFlowGraph();
        });

        $(document).on('click.lstEinsatzDyn', '#lst-generate-caller-preview', function () {
            renderCallerPreview();
        });

        $(document).on('input.lstEinsatzDyn change.lstEinsatzDyn', '#lst-fixed-latitude, #lst-fixed-longitude, #lst-fixed-radius', function () {
            syncMapFromFields();
        });

        $(document).on('input.lstEinsatzDyn change.lstEinsatzDyn', '#lst-title, #lst-einsatztyp, .lst-followup-label, .lst-followup-trigger, .lst-followup-probability, .lst-followup-min, .lst-followup-max, .lst-patient-editor-row input, .lst-patient-editor-row select, .lst-caller-profile-enabled, .lst-caller-profile-weight', function () {
            updateBaseResourceSummary();
            updateFollowupCardSummary($(this).closest('.lst-followup-row'));
            updateWizardSummary();
        });

        $(document).on('change.lstEinsatzDyn', '.lst-patient-hospital-department', function () {
            const $row = $(this).closest('.lst-patient-editor-row');
            refreshPatientHospitalStatus($row);
            queuePatientHospitalPreview(hospitalRoutingScope($row));
        });

        $(document).on('input.lstEinsatzDyn change.lstEinsatzDyn', '#lst-einsatzart, #lst-einsatztyp, #lst-lagemeldung, .lst-followup-text, .lst-patient-injury, .lst-patient-triage', function () {
            const $row = $(this).closest('.lst-patient-editor-row');
            if ($row.length) {
                queuePatientHospitalPreview(hospitalRoutingScope($row));
                return;
            }
            const $followup = $(this).closest('.lst-followup-row');
            if ($followup.length) {
                queuePatientHospitalPreview($followup.find('.lst-followup-patient-list'));
                return;
            }
            queueAllPatientHospitalPreviews();
        });
    }

    function saveCurrent() {
        restoreFlowProperties();
        if (callerProfilesLoading || !callerProfilesReady) {
            alert(callerProfilesLoadFailed
                ? 'Anruferprofile konnten nicht geladen werden. Bitte den Einsatzeditor erneut öffnen und dann speichern.'
                : 'Anruferprofile werden noch geladen. Bitte kurz warten und dann speichern.');
            return;
        }
        refreshFlowParents();
        let invalidFlow = '';
        $('.lst-followup-row').each(function () {
            const $card = $(this), role = $card.find('.lst-followup-role').val();
            if (isFlowCall($card)) {
                if (!$card.find('.lst-followup-radio-report').val().trim()) invalidFlow = 'Bitte für jeden Folgeanruf einen Anruftext eintragen.';
                return;
            }
            if (!$card.find('.lst-followup-radio-report').val().trim()) invalidFlow = 'Bitte für jede Lage eine Rückmeldung an die Leitstelle eintragen.';
            if (role === 'initial' && !($card.find('.lst-followup-parents').val() || []).length) return;
            const key = $card.attr('data-flow-key'), parents = $card.find('.lst-followup-parents').val() || [];
            if (!parents.length) invalidFlow = 'Bitte für jede Folgelage mindestens einen Vorgänger verbinden.';
            if (parents.some(parent => flowWouldCycle(parent, key))) invalidFlow = 'Die Lageverbindungen enthalten einen Kreis.';
        });
        if (invalidFlow) { alert(invalidFlow); setTab('situation'); return; }
        const initialRows = $('.lst-followup-row').filter(function () { return $(this).find('.lst-followup-role').val() === 'initial' && $(this).attr('data-flow-stage') !== 'arrival_default'; });
        const initialSum = initialRows.toArray().reduce((sum, row) => sum + Number($(row).find('.lst-followup-probability').val() || 0), 0);
        if (initialSum > 100) {
            alert('Die Wahrscheinlichkeiten der Eintreffvarianten dürfen zusammen höchstens 100 % ergeben. Aktuell: ' + initialSum + ' %.');
            setTab('situation'); return;
        }

        $('#lst-einsatz-save-spinner').css('visibility', 'visible');
        const basePatientRows = collectPatientRows($('#lst-base-patient-list'));
        const basePatient = patientRequirementsFromRows(basePatientRows);
        const basePatientResources = patientResourcesFromCounts(basePatient.ktw, basePatient.rtw, basePatient.notarzt);
        const patientRequirementText = [
            basePatient.ktw ? basePatient.ktw + ' KTW' : '',
            basePatient.rtw ? basePatient.rtw + ' RTW' : '',
            basePatient.notarzt ? basePatient.notarzt + ' Notarztmittel' : ''
        ].filter(Boolean).join(', ');

        postAjax({
            action: 'lst_save_einsatz',
            id: $('#lst-einsatz-id').val() || 0,
            title: $('#lst-title').val() || '',
            description: $('#lst-description').val() || '',
            einsatzart: $('#lst-einsatzart').val() || 'RD',
            einsatztyp: $('#lst-einsatztyp').val() || '',
            leitstelle_id: $('#lst-einsatz-leitstelle').val() || 0,
            stichwort_id: $('#lst-stichwort-id').val() || 0,
            enabled: $('#lst-enabled').is(':checked') ? 1 : 0,
            tags_json: $('#lst-tags-json').val() || '',
            scope_type: $('input[name="lst_scope_type"]:checked').val() || 'anywhere',
            landscape_tags_json: collectLandscapeTagsJson(),
            poi_types_json: JSON.stringify($('#lst-poi-types').val() || []),
            poi_type: ($('#lst-poi-types').val() || [])[0] || '',
            fixed_latitude: $('#lst-fixed-latitude').val() || '',
            fixed_longitude: $('#lst-fixed-longitude').val() || '',
            fixed_radius_m: $('#lst-fixed-radius').val() || '',
            lagemeldung: $('#lst-lagemeldung').val() || '',
            patientenzahl: basePatient.total,
            patient_anforderung: patientRequirementText,
            notarzt_benoetigt: basePatient.notarzt > 0 ? 1 : 0,
            feuerwehr_benoetigt: $('#lst-feuerwehr-benoetigt').val() === '1' ? 1 : 0,
            base_required_resources_json: JSON.stringify(mergeResourceRows(basePatientResources, collectResources('#lst-base-resource-list'))),
            patient_profile_json: JSON.stringify(basePatientRows),
            time_windows: JSON.stringify(collectTimeWindows()),
            seasons: JSON.stringify(collectSeasons()),
            weather_conditions: JSON.stringify(collectWeather()),
            caller_parts: JSON.stringify(collectCallerParts()),
            caller_profiles: JSON.stringify(collectCallerProfiles()),
            caller_profiles_loaded: callerProfilesReady ? 1 : 0,
            followups: JSON.stringify(collectFollowups())
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_save_einsatz Fehler', res);
                alert((res && res.data && res.data.message) ? res.data.message : 'Speichern fehlgeschlagen.');
                return;
            }

            hideModal();
            loadList();
        })
        .fail(function (xhr) {
            console.error('lst_save_einsatz fehlgeschlagen', xhr);
            const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                ? xhr.responseJSON.data.message
                : (xhr && xhr.responseText ? xhr.responseText : 'Speichern fehlgeschlagen.');
            alert(message);
        })
        .always(function () {
            $('#lst-einsatz-save-spinner').css('visibility', 'hidden');
        });
    }

    $('#lst-einsatz-new').on('click', function (e) {
        e.preventDefault();

        openEditor({
            title: '',
            description: '',
            einsatzart: 'RD',
            einsatztyp: '',
            leitstelle_id: (window.lstEinsatzLeitstellen && window.lstEinsatzLeitstellen[0] ? window.lstEinsatzLeitstellen[0].id : ''),
            stichwort_id: '',
            enabled: 1,
            scope_type: 'anywhere',
            time_windows: [],
            seasons: [],
            weather_conditions: [],
            caller_parts: {
                greeting: [],
                person: [],
                location: [],
                problem: [],
                observation: [],
                extra: []
            },
            caller_profiles: [],
            followups: [],
            base_required_resources_json: '',
            patient_profile_json: '',
            __editor_mode: 'create'
        });
    });

    $('#lst-einsatz-search, #lst-einsatz-filter-art, #lst-einsatz-filter-enabled').on('change keyup', function () {
        loadList();
    });

    $(document).on('click', '.lst-einsatz-edit', function () {
        fetchOne($(this).data('id'), false);
    });

    $(document).on('click', '.lst-einsatz-copy', function () {
        fetchOne($(this).data('id'), true);
    });

    $(document).on('click', '.lst-einsatz-delete', function () {
        const id = $(this).data('id');
        if (!confirm('Wirklich löschen?')) return;

        postAjax({
            action: 'lst_delete_einsatz',
            id: id
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_delete_einsatz Fehler', res);
                alert((res && res.data && res.data.message) ? res.data.message : 'Löschen fehlgeschlagen.');
                return;
            }
            loadList();
        })
        .fail(function (xhr) {
            console.error('lst_delete_einsatz fehlgeschlagen', xhr);
            alert('Löschen fehlgeschlagen.');
        });
    });

    $(document).on('click', '#lst-einsatz-cancel, #lst-einsatz-modal .modal-close, #lst-einsatz-modal .modal-overlay', function () {
        hideModal();
    });

    $(document).on('submit', '#lst-einsatz-form', function (e) {
        e.preventDefault();
        saveCurrent();
    });

    if (window.lstEinsatzBootstrap && Array.isArray(window.lstEinsatzBootstrap.poi_types)) {
        currentPoiTypes = window.lstEinsatzBootstrap.poi_types;
    }
    if (window.lstEinsaetzeAjax && Array.isArray(window.lstEinsaetzeAjax.fahrzeugtypen)) {
        currentVehicleTypes = window.lstEinsaetzeAjax.fahrzeugtypen;
    }
    if (window.lstEinsaetzeAjax && window.lstEinsaetzeAjax.departments && typeof window.lstEinsaetzeAjax.departments === 'object') {
        currentHospitalDepartments = window.lstEinsaetzeAjax.departments;
    }
    if (!currentVehicleTypes.length) {
        currentVehicleTypes = ['RTW', 'NEF', 'KTW', 'HLF 20', 'LF 20', 'DLK 23/12', 'GW-San', 'ELW 1', 'MTW'];
    }

    loadList();
});
