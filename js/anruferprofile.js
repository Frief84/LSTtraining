jQuery(function ($) {

    function apiPost(payload, cb) {
    $.post(
        lstAnruferprofileAjax.ajax_url,
        $.extend(
            {
                nonce: lstAnruferprofileAjax.nonce
            },
            payload
        ),
        cb
    );
}

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderList(items) {
        const tpl = wp.template('lst-anruferprofile-table-rows');
        $('#lst-anruferprofile-table-body').html(
            tpl({
                items: items || []
            })
        );
    }

    function setTab(tabKey) {
        $('.lst-tab-btn').removeClass('is-active');
        $('.lst-tab-panel').removeClass('is-active').hide();

        $('.lst-tab-btn[data-tab="' + tabKey + '"]').addClass('is-active');
        $('.lst-tab-panel[data-tab-panel="' + tabKey + '"]').addClass('is-active').show();
    }

    function loadList() {
        $('#lst-anruferprofile-list-spinner').css('visibility', 'visible');

        apiPost(
            {
                action: 'lst_get_anruferprofile_list',
                search: $('#lst-anruferprofile-search').val() || '',
                category: $('#lst-anruferprofile-filter-category').val() || '',
                enabled: $('#lst-anruferprofile-filter-enabled').val() || ''
            },
            function (res) {
                $('#lst-anruferprofile-list-spinner').css('visibility', 'hidden');

                if (!res || !res.success) {
                    console.error('lst_get_anruferprofile_list Fehler', res);
                    renderList([]);
                    return;
                }

                renderList(res.data.items || []);
            }
        );
    }

    function openEditor(item) {
        item = item || {};
        item.parts = item.parts || {};

        const tpl = wp.template('lst-anruferprofile-editor');
        $('#lst-anruferprofile-modal .modal-body').html(tpl(item));
        $('#lst-anruferprofile-modal-title').text(
            item.id ? 'Anruferprofil bearbeiten' : 'Anruferprofil anlegen'
        );
        $('#lst-anruferprofile-modal').removeClass('hidden');

        setTab('general');
    }

    function closeEditor() {
        $('#lst-anruferprofile-modal').addClass('hidden');
        $('#lst-anruferprofile-modal .modal-body').empty();
    }

    function fetchOne(id, asCopy) {
        apiPost(
            {
                action: 'lst_get_anruferprofile',
                id: id
            },
            function (res) {
                if (!res || !res.success || !res.data || !res.data.item) {
                    console.error('lst_get_anruferprofile Fehler', res);
                    return;
                }

                const item = res.data.item;

                if (asCopy) {
                    item.id = '';
                    item.name = (item.name || 'Profil') + ' (Kopie)';
                }

                openEditor(item);
            }
        );
    }

    function addPartRow(partKey, row) {
        const tbody = $('.lst-ap-part-table[data-part-key="' + partKey + '"] tbody');
        tbody.find('.lst-ap-part-empty-row').remove();

        row = row || {};

        const html = `
            <tr class="lst-ap-part-row">
                <td>
                    <input type="text" class="regular-text lst-ap-part-text" value="${escapeHtml(row.text || '')}">
                </td>
                <td>
                    <input type="number" min="0" step="1" class="small-text lst-ap-part-sort-order" value="${escapeHtml(row.sort_order != null ? row.sort_order : 0)}">
                </td>
                <td>
                    <label>
                        <input type="checkbox" class="lst-ap-part-enabled" value="1" ${String(row.enabled != null ? row.enabled : 1) === '1' ? 'checked' : ''}>
                        aktiv
                    </label>
                </td>
                <td>
                    <button type="button" class="button-link-delete lst-remove-row">Entfernen</button>
                </td>
            </tr>
        `;

        tbody.append(html);
    }

    function ensureEmptyRow(table) {
        const tbody = table.find('tbody');

        if (tbody.find('tr').length) {
            return;
        }

        tbody.append('<tr class="lst-ap-part-empty-row"><td colspan="4">Noch keine Bausteine.</td></tr>');
    }

    function collectParts() {
        const result = {};

        $('.lst-ap-part-table').each(function () {
            const partKey = $(this).data('part-key');
            result[partKey] = [];

            $(this)
                .find('tbody tr.lst-ap-part-row')
                .each(function () {
                    const text = ($(this).find('.lst-ap-part-text').val() || '').trim();
                    const sortOrder = parseInt($(this).find('.lst-ap-part-sort-order').val() || '0', 10);
                    const enabled = $(this).find('.lst-ap-part-enabled').is(':checked') ? 1 : 0;

                    if (!text) {
                        return;
                    }

                    result[partKey].push({
                        text: text,
                        sort_order: isNaN(sortOrder) ? 0 : sortOrder,
                        enabled: enabled
                    });
                });
        });

        return result;
    }

    function rand(rows) {
        if (!Array.isArray(rows) || !rows.length) {
            return '';
        }

        const enabledRows = rows.filter(function (r) {
            return String(r.enabled != null ? r.enabled : 1) === '1';
        });

        const source = enabledRows.length ? enabledRows : rows;
        const row = source[Math.floor(Math.random() * source.length)];

        return row && row.text ? row.text : '';
    }

    function fillPlaceholders(str) {
        return String(str || '')
            .replace(/\{full_name\}/g, $('#lst-ap-preview-name').val() || 'Frau Schneider')
            .replace(/\{address_full\}/g, $('#lst-ap-preview-address').val() || 'Musterstraße 12')
            .replace(/\{poi_name\}/g, $('#lst-ap-preview-poi').val() || 'Seniorenheim Sonnenhof')
            .replace(/\{company_name\}/g, $('#lst-ap-preview-company').val() || 'Firma Beispiel GmbH')
            .replace(/\{problem\}/g, $('#lst-ap-preview-problem').val() || 'hier ist eine Person gestürzt und hat starke Schmerzen')
            .replace(/\s+/g, ' ')
            .trim();
    }

  function generatePreview() {
    apiPost(
        {
            action: 'lst_preview_anruferprofile',
            parts: JSON.stringify(collectParts()),
            gender_key: $('#lst-ap-preview-gender').val() || '',
            address_full: $('#lst-ap-preview-address').val() || '',
            poi_name: $('#lst-ap-preview-poi').val() || '',
            company_name: $('#lst-ap-preview-company').val() || '',
            problem: $('#lst-ap-preview-problem').val() || ''
        },
        function (res) {
            const list = $('#lst-ap-preview-list');
            list.empty();

            if (!res || !res.success || !res.data || !res.data.examples) {
                console.error('lst_preview_anruferprofile Fehler', res);
                return;
            }

            res.data.examples.forEach(function (text) {
                list.append('<li>' + escapeHtml(text) + '</li>');
            });
        }
    );
}

    function saveProfile() {
        $('#lst-anruferprofile-save-spinner').css('visibility', 'visible');

        apiPost(
            {
                action: 'lst_save_anruferprofile',
                id: parseInt($('#lst-anruferprofile-id').val() || '0', 10),
                name: $('#lst-ap-name').val() || '',
                category: $('#lst-ap-category').val() || 'private',
                tone: $('#lst-ap-tone').val() || 'calm',
                emotion_level: $('#lst-ap-emotion-level').val() || 1,
                enabled: $('#lst-ap-enabled').is(':checked') ? 1 : 0,
                uses_name: $('#lst-ap-uses-name').is(':checked') ? 1 : 0,
                uses_address: $('#lst-ap-uses-address').is(':checked') ? 1 : 0,
                uses_poi_name: $('#lst-ap-uses-poi-name').is(':checked') ? 1 : 0,
                uses_company_name: $('#lst-ap-uses-company-name').is(':checked') ? 1 : 0,
                sort_order: $('#lst-ap-sort-order').val() || 0,
                notes: $('#lst-ap-notes').val() || '',
                parts: JSON.stringify(collectParts())
            },
            function (res) {
                $('#lst-anruferprofile-save-spinner').css('visibility', 'hidden');

                if (!res || !res.success) {
                    console.error('lst_save_anruferprofile Fehler', res);
                    alert(
                        res && res.data && res.data.message
                            ? res.data.message
                            : 'Speichern fehlgeschlagen.'
                    );
                    return;
                }

                closeEditor();
                loadList();
            }
        );
    }

    $('#lst-anruferprofile-new').on('click', function (e) {
        e.preventDefault();
        openEditor({});
    });

    $('#lst-anruferprofile-search, #lst-anruferprofile-filter-category, #lst-anruferprofile-filter-enabled').on(
        'change keyup',
        function () {
            loadList();
        }
    );

    $(document).on('click', '.lst-anruferprofile-edit', function () {
        fetchOne($(this).data('id'), false);
    });

    $(document).on('click', '.lst-anruferprofile-copy', function () {
        fetchOne($(this).data('id'), true);
    });

    $(document).on('click', '.lst-anruferprofile-delete', function () {
        const id = $(this).data('id');

        if (!confirm('Wirklich löschen?')) {
            return;
        }

        apiPost(
            {
                action: 'lst_delete_anruferprofile',
                id: id
            },
            function (res) {
                if (!res || !res.success) {
                    console.error('lst_delete_anruferprofile Fehler', res);
                    alert(
                        res && res.data && res.data.message
                            ? res.data.message
                            : 'Löschen fehlgeschlagen.'
                    );
                    return;
                }

                loadList();
            }
        );
    });

    $(document).on('click', '.lst-tab-btn', function () {
        setTab($(this).data('tab'));
    });

    $(document).on('click', '.lst-ap-add-part', function () {
        addPartRow($(this).data('part-key'), {});
    });

    $(document).on('click', '.lst-remove-row', function () {
        const table = $(this).closest('table');
        $(this).closest('tr').remove();
        ensureEmptyRow(table);
    });

    $(document).on('click', '#lst-ap-generate-preview', function () {
        generatePreview();
    });

    $(document).on(
        'click',
        '#lst-anruferprofile-cancel, #lst-anruferprofile-modal .modal-close, #lst-anruferprofile-modal .modal-overlay',
        function () {
            closeEditor();
        }
    );

    $(document).on('submit', '#lst-anruferprofile-form', function (e) {
        e.preventDefault();
        saveProfile();
    });

    loadList();
});