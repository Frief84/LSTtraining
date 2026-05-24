jQuery(function ($) {
    const emptyParts = {
        greeting: [],
        self_intro: [],
        location_intro: [],
        problem_intro: [],
        urgency: [],
        callback_request: [],
        closing: []
    };

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getAjaxBase() {
        return {
            ajax_url: (window.lstAnruferprofileAjax && lstAnruferprofileAjax.ajax_url) ? lstAnruferprofileAjax.ajax_url : ajaxurl,
            nonce: (window.lstAnruferprofileAjax && lstAnruferprofileAjax.nonce) ? lstAnruferprofileAjax.nonce : ''
        };
    }

    function postAjax(payload) {
        const cfg = getAjaxBase();
        return $.post(cfg.ajax_url, $.extend({}, payload, { nonce: cfg.nonce }));
    }

    function showModal() {
        $('#lst-anruferprofile-modal').removeClass('hidden');
    }

    function hideModal() {
        $('#lst-anruferprofile-modal').addClass('hidden');
    }

    function renderList(items) {
        const tpl = wp.template('lst-anruferprofile-table-rows');
        $('#lst-anruferprofile-table-body').html(tpl({ items: items || [] }));
    }

    function normalizeItem(item) {
        item = item || {};
        item.parts = item.parts && typeof item.parts === 'object' ? item.parts : {};
        Object.keys(emptyParts).forEach(function (key) {
            item.parts[key] = Array.isArray(item.parts[key]) ? item.parts[key] : [];
        });
        return item;
    }

    function setTab(tabKey) {
        $('.lst-tab-btn').removeClass('is-active');
        $('.lst-tab-panel').removeClass('is-active').hide();

        $('.lst-tab-btn[data-tab="' + tabKey + '"]').addClass('is-active');
        $('.lst-tab-panel[data-tab-panel="' + tabKey + '"]').addClass('is-active').show();
    }

    function loadList() {
        $('#lst-anruferprofile-list-spinner').css('visibility', 'visible');

        postAjax({
            action: 'lst_get_anruferprofile_list',
            search: $('#lst-anruferprofile-search').val() || '',
            category: $('#lst-anruferprofile-filter-category').val() || '',
            enabled: $('#lst-anruferprofile-filter-enabled').val() || ''
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_get_anruferprofile_list Fehler', res);
                renderList([]);
                return;
            }
            renderList(res.data.items || []);
        })
        .fail(function (xhr) {
            console.error('lst_get_anruferprofile_list fehlgeschlagen', xhr);
            console.error('Response text:', xhr.responseText);
            renderList([]);
        })
        .always(function () {
            $('#lst-anruferprofile-list-spinner').css('visibility', 'hidden');
        });
    }

    function openEditor(item) {
        item = normalizeItem(item);

        const tpl = wp.template('lst-anruferprofile-editor');
        $('#lst-anruferprofile-modal .modal-body').html(tpl(item));
        $('#lst-anruferprofile-modal-title').text(item.id ? 'Anruferprofil bearbeiten' : 'Neues Anruferprofil');

        showModal();
        bindDynamicUi();
        restoreEmptyRows();
        setTab('general');
    }

    function fetchOne(id, asCopy) {
        postAjax({
            action: 'lst_get_anruferprofile',
            id: id
        })
        .done(function (res) {
            if (!res || !res.success || !res.data || !res.data.item) {
                console.error('lst_get_anruferprofile Fehler', res);
                return;
            }

            const item = res.data.item;
            if (asCopy) {
                item.id = '';
                item.name = (item.name || 'Anruferprofil') + ' (Kopie)';
            }

            openEditor(item);
        })
        .fail(function (xhr) {
            console.error('lst_get_anruferprofile fehlgeschlagen', xhr);
            console.error('Response text:', xhr.responseText);
        });
    }

    function addPartRow(partKey, row) {
        const tbody = $('.lst-ap-part-table[data-part-key="' + partKey + '"] tbody');
        tbody.find('.lst-ap-part-empty-row').remove();

        row = row || {};
        const text = row.text || '';
        const sortOrder = row.sort_order != null ? row.sort_order : 0;
        const enabled = String(row.enabled == null ? '1' : row.enabled) !== '0';

        tbody.append(`
            <tr class="lst-ap-part-row">
                <td><input type="text" class="regular-text lst-ap-part-text" value="${esc(text)}" placeholder="z. B. hier ist {formal_name}"></td>
                <td><input type="number" min="0" step="1" class="small-text lst-ap-part-sort-order" value="${esc(sortOrder)}"></td>
                <td><label><input type="checkbox" class="lst-ap-part-enabled" value="1" ${enabled ? 'checked' : ''}> aktiv</label></td>
                <td><button type="button" class="button-link-delete lst-remove-row">Entfernen</button></td>
            </tr>
        `);
    }

    function collectParts() {
        const grouped = {
            greeting: [],
            self_intro: [],
            location_intro: [],
            problem_intro: [],
            urgency: [],
            callback_request: [],
            closing: []
        };

        $('.lst-ap-part-table').each(function () {
            const partKey = $(this).data('part-key');
            if (!Object.prototype.hasOwnProperty.call(grouped, partKey)) {
                return;
            }

            $(this).find('tbody tr.lst-ap-part-row').each(function () {
                const text = ($(this).find('.lst-ap-part-text').val() || '').trim();
                const sortOrder = parseInt($(this).find('.lst-ap-part-sort-order').val() || '0', 10);
                const enabled = $(this).find('.lst-ap-part-enabled').is(':checked') ? 1 : 0;

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

    function restoreEmptyRows() {
        $('.lst-ap-part-table').each(function () {
            const tbody = $(this).find('tbody');
            if (!tbody.find('tr').length) {
                tbody.append('<tr class="lst-ap-part-empty-row"><td colspan="4">Noch keine Bausteine.</td></tr>');
            }
        });
    }

    function showAjaxError(xhr, fallback) {
        let msg = fallback;
        try {
            const json = JSON.parse(xhr.responseText);
            if (json && json.data && json.data.message) {
                msg = json.data.message;
            }
        } catch (e) {}
        alert(msg);
    }

    function saveCurrent() {
        $('#lst-anruferprofile-save-spinner').css('visibility', 'visible');

        postAjax({
            action: 'lst_save_anruferprofile',
            id: $('#lst-anruferprofile-id').val() || 0,
            name: $('#lst-ap-name').val() || '',
            category: $('#lst-ap-category').val() || 'private',
            tone: $('#lst-ap-tone').val() || 'calm',
            uses_name: $('#lst-ap-uses-name').is(':checked') ? 1 : 0,
            uses_address: $('#lst-ap-uses-address').is(':checked') ? 1 : 0,
            uses_poi_name: $('#lst-ap-uses-poi-name').is(':checked') ? 1 : 0,
            uses_company_name: $('#lst-ap-uses-company-name').is(':checked') ? 1 : 0,
            emotion_level: $('#lst-ap-emotion-level').val() || 1,
            enabled: $('#lst-ap-enabled').is(':checked') ? 1 : 0,
            sort_order: $('#lst-ap-sort-order').val() || 0,
            notes: $('#lst-ap-notes').val() || '',
            parts: JSON.stringify(collectParts())
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_save_anruferprofile Fehler', res);
                alert((res && res.data && res.data.message) ? res.data.message : 'Speichern fehlgeschlagen.');
                return;
            }

            hideModal();
            loadList();
        })
        .fail(function (xhr) {
            console.error('lst_save_anruferprofile fehlgeschlagen', xhr);
            console.error('Response text:', xhr.responseText);
            showAjaxError(xhr, 'Speichern fehlgeschlagen.');
        })
        .always(function () {
            $('#lst-anruferprofile-save-spinner').css('visibility', 'hidden');
        });
    }

    function generatePreview() {
        const list = $('#lst-ap-preview-list');
        list.empty();

        postAjax({
            action: 'lst_preview_anruferprofile',
            parts: JSON.stringify(collectParts()),
            gender_key: $('#lst-ap-preview-gender').val() || '',
            address_full: $('#lst-ap-preview-address').val() || '',
            poi_name: $('#lst-ap-preview-poi').val() || '',
            company_name: $('#lst-ap-preview-company').val() || '',
            problem: $('#lst-ap-preview-problem').val() || '',
            observation: $('#lst-ap-preview-observation').val() || '',
            extra: $('#lst-ap-preview-extra').val() || ''
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_preview_anruferprofile Fehler', res);
                list.append('<li>Vorschau konnte nicht erzeugt werden.</li>');
                return;
            }

            (res.data.examples || []).forEach(function (text) {
                list.append('<li>' + esc(text) + '</li>');
            });
        })
        .fail(function (xhr) {
            console.error('lst_preview_anruferprofile fehlgeschlagen', xhr);
            console.error('Response text:', xhr.responseText);
            list.append('<li>Vorschau konnte nicht erzeugt werden.</li>');
        });
    }

    function bindDynamicUi() {
        $(document).off('.lstAnruferprofileDyn');

        $(document).on('click.lstAnruferprofileDyn', '.lst-tab-btn', function () {
            setTab($(this).data('tab'));
        });

        $(document).on('click.lstAnruferprofileDyn', '.lst-ap-add-part', function () {
            addPartRow($(this).data('part-key'), {});
        });

        $(document).on('click.lstAnruferprofileDyn', '.lst-remove-row', function () {
            $(this).closest('tr').remove();
            restoreEmptyRows();
        });

        $(document).on('click.lstAnruferprofileDyn', '#lst-ap-generate-preview', function () {
            generatePreview();
        });
    }

    $('#lst-anruferprofile-new').on('click', function (e) {
        e.preventDefault();

        openEditor({
            name: '',
            category: 'private',
            tone: 'calm',
            uses_name: 1,
            uses_address: 1,
            uses_poi_name: 0,
            uses_company_name: 0,
            emotion_level: 1,
            enabled: 1,
            sort_order: 0,
            notes: '',
            parts: $.extend(true, {}, emptyParts)
        });
    });

    $('#lst-anruferprofile-search, #lst-anruferprofile-filter-category, #lst-anruferprofile-filter-enabled').on('change keyup', function () {
        loadList();
    });

    $(document).on('click', '.lst-anruferprofile-edit', function () {
        fetchOne($(this).data('id'), false);
    });

    $(document).on('click', '.lst-anruferprofile-copy', function () {
        fetchOne($(this).data('id'), true);
    });

    $(document).on('click', '.lst-anruferprofile-delete', function () {
        const id = $(this).data('id');
        if (!confirm('Wirklich löschen?')) return;

        postAjax({
            action: 'lst_delete_anruferprofile',
            id: id
        })
        .done(function (res) {
            if (!res || !res.success) {
                console.error('lst_delete_anruferprofile Fehler', res);
                alert((res && res.data && res.data.message) ? res.data.message : 'Löschen fehlgeschlagen.');
                return;
            }
            loadList();
        })
        .fail(function (xhr) {
            console.error('lst_delete_anruferprofile fehlgeschlagen', xhr);
            console.error('Response text:', xhr.responseText);
            showAjaxError(xhr, 'Löschen fehlgeschlagen.');
        });
    });

    $(document).on('click', '#lst-anruferprofile-cancel, #lst-anruferprofile-modal .modal-close, #lst-anruferprofile-modal .modal-overlay', function () {
        hideModal();
    });

    $(document).on('submit', '#lst-anruferprofile-form', function (e) {
        e.preventDefault();
        saveCurrent();
    });

    loadList();
});
