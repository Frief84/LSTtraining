jQuery(function ($) {
    let map = null;
    let vectorSource = null;
    let vectorLayer = null;
    let currentPoiTypes = [];

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
        $('#lst-einsatz-modal').removeClass('hidden');
    }

    function hideModal() {
        $('#lst-einsatz-modal').addClass('hidden');
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
        item.caller_parts = item.caller_parts || {
            greeting: [],
            person: [],
            location: [],
            problem: [],
            extra: []
        };
        item.poi_types = currentPoiTypes;
        return item;
    }

    function setTab(tabKey) {
        $('.lst-tab-btn').removeClass('is-active');
        $('.lst-tab-panel').removeClass('is-active').hide();

        $('.lst-tab-btn[data-tab="' + tabKey + '"]').addClass('is-active');
        $('.lst-tab-panel[data-tab-panel="' + tabKey + '"]').addClass('is-active').show();

        if (tabKey === 'location' && $('input[name="lst_scope_type"]:checked').val() === 'fixed_point') {
            setTimeout(initMapIfNeeded, 50);
            setTimeout(syncMapFromFields, 80);
        }
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

    function openEditor(item) {
        item = normalizeItem(item);

        const tpl = wp.template('lst-einsatz-editor');
        $('#lst-einsatz-modal .modal-body').html(tpl(item));
        $('#lst-einsatz-modal-title').text(item.id ? 'Einsatz bearbeiten' : 'Neuer Einsatz');

        showModal();
        bindDynamicUi();
        restoreEmptyRows();

        setTab('general');

        const scopeType = $('input[name="lst_scope_type"]:checked').val() || 'anywhere';
        setScopePanels(scopeType);
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
                        <option value="any" ${row.day_type === 'any' ? 'selected' : ''}>any</option>
                        <option value="weekday" ${row.day_type === 'weekday' ? 'selected' : ''}>weekday</option>
                        <option value="weekend" ${row.day_type === 'weekend' ? 'selected' : ''}>weekend</option>
                        <option value="monday" ${row.day_type === 'monday' ? 'selected' : ''}>monday</option>
                        <option value="tuesday" ${row.day_type === 'tuesday' ? 'selected' : ''}>tuesday</option>
                        <option value="wednesday" ${row.day_type === 'wednesday' ? 'selected' : ''}>wednesday</option>
                        <option value="thursday" ${row.day_type === 'thursday' ? 'selected' : ''}>thursday</option>
                        <option value="friday" ${row.day_type === 'friday' ? 'selected' : ''}>friday</option>
                        <option value="saturday" ${row.day_type === 'saturday' ? 'selected' : ''}>saturday</option>
                        <option value="sunday" ${row.day_type === 'sunday' ? 'selected' : ''}>sunday</option>
                    </select>
                </td>
                <td><input type="time" class="lst-start-time" value="${esc(row.start_time || '')}"></td>
                <td><input type="time" class="lst-end-time" value="${esc(row.end_time || '')}"></td>
                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
            </tr>
        `);
    }

    function addCallerRow(partKey, row) {
        const tbody = $('.lst-caller-part-table[data-part-key="' + partKey + '"] tbody');
        tbody.find('.lst-caller-empty-row').remove();

        const text = row && row.text ? row.text : '';
        const sortOrder = row && row.sort_order != null ? row.sort_order : 0;
        const enabled = !row || String(row.enabled) !== '0';

        tbody.append(`
            <tr class="lst-caller-part-row">
                <td><input type="text" class="regular-text lst-caller-part-text" value="${esc(text)}"></td>
                <td><input type="number" class="small-text lst-caller-part-sort-order" min="0" step="1" value="${esc(sortOrder)}"></td>
                <td><label><input type="checkbox" class="lst-caller-part-enabled" value="1" ${enabled ? 'checked' : ''}> aktiv</label></td>
                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
            </tr>
        `);
    }

    function addFollowupRow(row) {
        const tbody = $('#lst-followup-table tbody');
        tbody.find('.lst-followup-empty-row').remove();

        row = row || {};

        tbody.append(`
            <tr class="lst-followup-row">
                <td><input type="number" min="1" step="1" class="small-text lst-followup-step" value="${esc(row.step_no || '')}"></td>
                <td>
                    <select class="lst-followup-kind">
                        <option value="dispatcher_question" ${row.kind === 'dispatcher_question' ? 'selected' : ''}>dispatcher_question</option>
                        <option value="caller_answer" ${row.kind === 'caller_answer' ? 'selected' : ''}>caller_answer</option>
                        <option value="update" ${!row.kind || row.kind === 'update' ? 'selected' : ''}>update</option>
                        <option value="unit_report" ${row.kind === 'unit_report' ? 'selected' : ''}>unit_report</option>
                    </select>
                </td>
                <td><textarea class="large-text lst-followup-text" rows="2">${esc(row.text || '')}</textarea></td>
                <td><input type="number" min="0" step="1" class="small-text lst-followup-min" value="${esc(row.min_after_sec || '')}"></td>
                <td><input type="number" min="0" step="1" class="small-text lst-followup-max" value="${esc(row.max_after_sec || '')}"></td>
                <td><input type="text" class="large-text code lst-followup-condition" value="${esc(row.condition_json || '')}"></td>
                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
            </tr>
        `);
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
            extra: []
        };

        $('.lst-caller-part-table').each(function () {
            const partKey = $(this).data('part-key');
            $(this).find('tbody tr.lst-caller-part-row').each(function () {
                const text = ($(this).find('.lst-caller-part-text').val() || '').trim();
                const sortOrder = parseInt($(this).find('.lst-caller-part-sort-order').val() || '0', 10);
                const enabled = $(this).find('.lst-caller-part-enabled').is(':checked') ? 1 : 0;

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

    function collectFollowups() {
        const rows = [];
        $('#lst-followup-table tbody tr.lst-followup-row').each(function () {
            rows.push({
                step_no: $(this).find('.lst-followup-step').val() || '',
                kind: $(this).find('.lst-followup-kind').val() || 'update',
                text: $(this).find('.lst-followup-text').val() || '',
                min_after_sec: $(this).find('.lst-followup-min').val() || '',
                max_after_sec: $(this).find('.lst-followup-max').val() || '',
                condition_json: $(this).find('.lst-followup-condition').val() || ''
            });
        });
        return rows;
    }

    function pickRandom(arr) {
        if (!Array.isArray(arr) || !arr.length) return '';
        return arr[Math.floor(Math.random() * arr.length)] || '';
    }

    function buildPreviewText() {
        const tpl = ($('#lst-caller-template').val() || '').trim();
        const grouped = collectCallerParts();

        const values = {
            greeting: pickRandom(grouped.greeting.map(r => r.text)),
            person: pickRandom(grouped.person.map(r => r.text)),
            location: pickRandom(grouped.location.map(r => r.text)),
            problem: pickRandom(grouped.problem.map(r => r.text)),
            extra: pickRandom(grouped.extra.map(r => r.text))
        };

        return tpl
            .replace(/\{greeting\}/g, values.greeting)
            .replace(/\{person\}/g, values.person)
            .replace(/\{location\}/g, values.location)
            .replace(/\{problem\}/g, values.problem)
            .replace(/\{extra\}/g, values.extra)
            .replace(/\s+/g, ' ')
            .replace(/\.\s*\./g, '.')
            .trim();
    }

    function renderCallerPreview() {
        const list = $('#lst-caller-preview-list');
        list.empty();

        for (let i = 0; i < 3; i++) {
            list.append('<li>' + esc(buildPreviewText()) + '</li>');
        }
    }

    function restoreEmptyRows() {
        const twBody = $('#lst-time-window-table tbody');
        if (twBody.length && !twBody.find('tr').length) {
            twBody.append('<tr class="lst-time-window-empty-row"><td colspan="4">Noch keine Zeitfenster vorhanden.</td></tr>');
        }

        $('.lst-caller-part-table').each(function () {
            const tbody = $(this).find('tbody');
            if (!tbody.find('tr').length) {
                tbody.append('<tr class="lst-caller-empty-row"><td colspan="4">Noch keine Bausteine.</td></tr>');
            }
        });

        const fuBody = $('#lst-followup-table tbody');
        if (fuBody.length && !fuBody.find('tr').length) {
            fuBody.append('<tr class="lst-followup-empty-row"><td colspan="7">Noch keine Follow-ups vorhanden.</td></tr>');
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

        $(document).on('click.lstEinsatzDyn', '.lst-tab-btn', function () {
            setTab($(this).data('tab'));
        });

        $(document).on('change.lstEinsatzDyn', 'input[name="lst_scope_type"]', function () {
            setScopePanels($(this).val());
        });

        $(document).on('click.lstEinsatzDyn', '#lst-add-time-window', function () {
            addTimeWindowRow({ day_type: 'weekday', start_time: '', end_time: '' });
        });

        $(document).on('click.lstEinsatzDyn', '.lst-add-caller-part', function () {
            addCallerRow($(this).data('part-key'), {});
        });

        $(document).on('click.lstEinsatzDyn', '#lst-add-followup', function () {
            addFollowupRow({});
        });

        $(document).on('click.lstEinsatzDyn', '.lst-remove-row', function () {
            $(this).closest('tr').remove();
            restoreEmptyRows();
        });

        $(document).on('click.lstEinsatzDyn', '#lst-generate-caller-preview', function () {
            renderCallerPreview();
        });

        $(document).on('input.lstEinsatzDyn change.lstEinsatzDyn', '#lst-fixed-latitude, #lst-fixed-longitude, #lst-fixed-radius', function () {
            syncMapFromFields();
        });
    }

    function saveCurrent() {
        $('#lst-einsatz-save-spinner').css('visibility', 'visible');

        postAjax({
            action: 'lst_save_einsatz',
            id: $('#lst-einsatz-id').val() || 0,
            title: $('#lst-title').val() || '',
            description: $('#lst-description').val() || '',
            einsatzart: $('#lst-einsatzart').val() || 'RD',
            einsatztyp: $('#lst-einsatztyp').val() || '',
            enabled: $('#lst-enabled').is(':checked') ? 1 : 0,
            tags_json: $('#lst-tags-json').val() || '',
            scope_type: $('input[name="lst_scope_type"]:checked').val() || 'anywhere',
            landscape_tags_json: collectLandscapeTagsJson(),
            poi_type: $('#lst-poi-type').val() || '',
            fixed_latitude: $('#lst-fixed-latitude').val() || '',
            fixed_longitude: $('#lst-fixed-longitude').val() || '',
            fixed_radius_m: $('#lst-fixed-radius').val() || '',
            caller_template_text: $('#lst-caller-template').val() || '',
            lagemeldung: $('#lst-lagemeldung').val() || '',
            patientenzahl: $('#lst-patientenzahl').val() || 0,
            patient_anforderung: $('#lst-patient-anforderung').val() || '',
            notarzt_benoetigt: $('#lst-notarzt-benoetigt').is(':checked') ? 1 : 0,
            feuerwehr_benoetigt: $('#lst-feuerwehr-benoetigt').is(':checked') ? 1 : 0,
            time_windows: JSON.stringify(collectTimeWindows()),
            seasons: JSON.stringify(collectSeasons()),
            weather_conditions: JSON.stringify(collectWeather()),
            caller_parts: JSON.stringify(collectCallerParts()),
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
            alert('Speichern fehlgeschlagen.');
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
                extra: []
            },
            followups: [],
            caller_template_text: '{greeting} hier ist {person}. {location}. {problem}. {extra}'
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

    loadList();
});