// ---------------------------------------------------------------------------
// includes/js/leitstellen_editor.js
// ---------------------------------------------------------------------------

(function(window, document, ol) {
    console.log('[leitstellen_editor.js] loaded');

    function initLeitstellenEditor(mapElementId, initialGeoJson) {
        const format = new ol.format.GeoJSON();
        const vectorSource = new ol.source.Vector({
            features: format.readFeatures(initialGeoJson, {
                featureProjection: 'EPSG:3857'
            })
        });
        const vectorLayer = new ol.layer.Vector({
            source: vectorSource,
            style: new ol.style.Style({
                stroke: new ol.style.Stroke({
                    color: '#0074D9',
                    width: 2
                }),
                fill: new ol.style.Fill({
                    color: 'rgba(0,116,217,0.1)'
                })
            })
        });
        new ol.Map({
            target: mapElementId,
            layers: [
                new ol.layer.Tile({
                    source: new ol.source.OSM()
                }),
                vectorLayer
            ],
            view: new ol.View({
                center: [0, 0],
                zoom: 2
            })
        });
        document.getElementById('save-leitstelle')
            .addEventListener('click', () => {
                const features = vectorSource.getFeatures();
                const geojson = format.writeFeatures(features, {
                    featureProjection: 'EPSG:3857'
                });
                wp.ajax.post('save_leitstelle', {
                    id: document.getElementById('leitstelle-id').value,
                    geojson: geojson
                }).done(() => {
                    alert('Leitstelle gespeichert!');
                }).fail(err => {
                    alert('Fehler: ' + err);
                });
            });
    }
    window.initLeitstellenEditor = initLeitstellenEditor;

    function openLeitstellePopupForCreate() {
        const heading = document.querySelector('#edit-leitstelle-formular h2');
        if (heading) heading.textContent = 'Leitstelle erstellen';

        [
            'lst_update_id',
            'lst_update_name',
            'lst_update_ort',
            'lst_update_bl',
            'lst_update_land',
            'lst_update_lat',
            'lst_update_lon'
        ].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const policeImage = document.getElementById('lst_update_police_vehicle_image');
        if (policeImage) policeImage.value = 'img/fahrzeug/default.png';
        const policeSignals = document.getElementById('lst_update_police_signal_lights_json');
        if (policeSignals) policeSignals.value = '';
        const rescueImage = document.getElementById('lst_update_rescue_vehicle_image');
        if (rescueImage) rescueImage.value = 'img/fahrzeug/default.png';
        const rescueSignals = document.getElementById('lst_update_rescue_signal_lights_json');
        if (rescueSignals) rescueSignals.value = '';
        const neighbors = document.getElementById('lst_neighbor_nebenleitstellen');
        if (neighbors) {
            Array.from(neighbors.options).forEach(option => { option.selected = false; });
        }

        const mode = document.getElementById('lst_form_mode');
        if (mode) mode.value = 'create';

        if (typeof resetEditMaps === 'function') resetEditMaps();
        if (typeof ensureEditMap === 'function') ensureEditMap();

        const overlay = document.getElementById('popup-overlay');
        if (overlay) overlay.style.display = 'block';

        const popup = document.getElementById('edit-leitstelle-formular');
        if (popup) popup.style.display = 'block';
        if (typeof window.updateAllDefaultVehiclePreviews === 'function') {
            window.updateAllDefaultVehiclePreviews();
        }
    }
    window.openLeitstellePopupForCreate = openLeitstellePopupForCreate;

    function ensureEditMap() {
        /* no-op */
    }
    window.ensureEditMap = ensureEditMap;

    function resetEditMaps() {
        if (window.mapEdit) {
            window.mapEdit.getView().setCenter(ol.proj.fromLonLat([9.0, 51.0]));
            window.mapEdit.getLayers().item(1).getSource().clear();
        }
        const poly = document.getElementById('geojson_edit');
        if (poly) poly.value = '';
    }
    window.resetEditMaps = resetEditMaps;

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.edit-leitstelle').forEach((btn) => {
            btn.addEventListener('click', () => {
                const neighbors = document.getElementById('lst_neighbor_nebenleitstellen');
                if (!neighbors) return;
                let ids = [];
                try {
                    ids = JSON.parse(btn.dataset.neighborIds || '[]');
                } catch (e) {
                    ids = [];
                }
                ids = Array.isArray(ids) ? ids.map(String) : [];
                Array.from(neighbors.options).forEach(option => {
                    option.selected = ids.indexOf(String(option.value)) !== -1;
                });
            });
        });

        const btn = document.getElementById('btn-new-leitstelle');
        if (btn) {
            btn.addEventListener('click', e => {
                e.preventDefault();
                openLeitstellePopupForCreate();
            });
        }
    });

})(window, document, ol);

(function($) {
    var signalLights = [];
    var dragLightIndex = null;
    var failedSignalSprites = {};
    var activeSignalJsonField = null;
    var activeSignalImageField = null;

    function pluginBaseUrl() {
        var script = document.querySelector('script[src*="/js/leitstellen_editor.js"]');
        if (!script || !script.src) return '';
        return script.src.split('/js/leitstellen_editor.js')[0].replace(/\/?$/, '/');
    }

    function spriteMap() {
        var configured = window.lstLeitstellenAjax && lstLeitstellenAjax.signal_sprite_urls ? lstLeitstellenAjax.signal_sprite_urls : {};
        var base = pluginBaseUrl();
        return {
            beacon: configured.beacon || (base ? base + 'img/signal/beacon.svg' : ''),
            strobe: configured.strobe || (base ? base + 'img/signal/strobe.svg' : ''),
            bar: configured.bar || (base ? base + 'img/signal/lightbar.svg' : ''),
            glow: configured.glow || (base ? base + 'img/signal/glow.svg' : ''),
            editor_point: configured.editor_point || (base ? base + 'img/signal/editor-point.svg' : '')
        };
    }

    function spriteUrl(type) {
        var map = spriteMap();
        var key = ['beacon', 'strobe', 'bar', 'glow'].indexOf(String(type || '')) !== -1 ? String(type) : 'beacon';
        if (map[key] && !failedSignalSprites[key]) return map[key];
        if (map.editor_point && !failedSignalSprites.editor_point) return map.editor_point;
        return '';
    }

    function clamp(value, min, max) {
        value = Number(value);
        if (!Number.isFinite(value)) return min;
        return Math.max(min, Math.min(max, value));
    }

    function normalizeSignalLights(raw) {
        var decoded = raw;
        if (typeof raw === 'string' && raw.trim()) {
            try { decoded = JSON.parse(raw); } catch (e) { decoded = {}; }
        }
        var lights = Array.isArray(decoded) ? decoded : (decoded && Array.isArray(decoded.lights) ? decoded.lights : []);
        return lights.map(function(light) {
            return {
                x: clamp(light && light.x, 0, 1),
                y: clamp(light && light.y, 0, 1),
                type: ['beacon', 'strobe', 'bar', 'glow'].indexOf(String(light && light.type || '')) !== -1 ? String(light.type) : 'beacon',
                interval: Math.round(clamp(light && light.interval || 420, 120, 2000)),
                phase: Math.round(clamp(light && light.phase || 0, 0, 5000)),
                size: clamp(light && light.size || 1, 0.4, 2.5)
            };
        });
    }

    function signalLightsJson() {
        return signalLights.length ? JSON.stringify({ version: 1, lights: signalLights }) : '';
    }

    function publicImageUrl(value) {
        value = String(value || '').trim();
        if (!value) return '';
        if (/^(https?:)?\/\//i.test(value) || value.charAt(0) === '/') return value;
        var base = pluginBaseUrl();
        return base ? base + value.replace(/^\/+/, '') : value;
    }

    function previewElements(inputId) {
        return {
            input: document.getElementById(inputId),
            image: document.querySelector('[data-lst-default-image-preview-for="' + inputId + '"]'),
            empty: document.querySelector('[data-lst-default-image-empty-for="' + inputId + '"]'),
            status: document.querySelector('[data-lst-default-image-status-for="' + inputId + '"]')
        };
    }

    function updateDefaultVehiclePreview(inputId) {
        var refs = previewElements(inputId);
        if (!refs.input || !refs.image) return;
        var raw = String(refs.input.value || '').trim();
        var url = publicImageUrl(raw);

        refs.image.onload = function() {
            refs.image.style.display = 'block';
            refs.image.closest('.lst-default-vehicle-card__preview') &&
                refs.image.closest('.lst-default-vehicle-card__preview').classList.remove('is-error');
            if (refs.empty) refs.empty.style.display = 'none';
            if (refs.status) refs.status.textContent = raw || '';
        };
        refs.image.onerror = function() {
            refs.image.style.display = 'none';
            refs.image.closest('.lst-default-vehicle-card__preview') &&
                refs.image.closest('.lst-default-vehicle-card__preview').classList.add('is-error');
            if (refs.empty) {
                refs.empty.textContent = raw ? 'Bild nicht verfügbar' : 'Kein Bild geladen';
                refs.empty.style.display = 'block';
            }
            if (refs.status) refs.status.textContent = raw || '';
        };

        if (!url) {
            refs.image.removeAttribute('src');
            refs.image.style.display = 'none';
            if (refs.empty) {
                refs.empty.textContent = 'Kein Bild geladen';
                refs.empty.style.display = 'block';
            }
            if (refs.status) refs.status.textContent = '';
            return;
        }

        if (refs.status) refs.status.textContent = raw;
        refs.image.src = url;
    }

    function updateAllDefaultVehiclePreviews() {
        $('[data-lst-default-image-input]').each(function() {
            updateDefaultVehiclePreview(this.id);
        });
    }

    window.updateDefaultVehiclePreview = updateDefaultVehiclePreview;
    window.updateAllDefaultVehiclePreviews = updateAllDefaultVehiclePreviews;

    function selectedLightIndex() {
        var index = parseInt($('#lst-default-signal-delete').attr('data-index'), 10);
        return Number.isFinite(index) && index >= 0 ? index : -1;
    }

    function setSelectedLight(index) {
        index = Number.isFinite(index) ? index : -1;
        $('#lst-default-signal-delete')
            .prop('disabled', index < 0 || !signalLights[index])
            .attr('data-index', index >= 0 ? String(index) : '');
        if (signalLights[index]) {
            $('#lst-default-signal-type').val(signalLights[index].type);
            $('#lst-default-signal-interval').val(signalLights[index].interval);
            $('#lst-default-signal-phase').val(signalLights[index].phase);
            $('#lst-default-signal-size').val(signalLights[index].size);
        }
        renderSignalLights();
    }

    function stageRect() {
        var img = $('#lst-default-signal-preview').get(0);
        if (!img || !img.complete || !img.naturalWidth) return null;
        var rect = img.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return null;
        return rect;
    }

    function pointFromEvent(event) {
        var rect = stageRect();
        if (!rect) return null;
        return {
            x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
            y: clamp((event.clientY - rect.top) / rect.height, 0, 1)
        };
    }

    function renderSignalLights() {
        var $layer = $('[data-lst-default-signal-layer]');
        var $img = $('#lst-default-signal-preview');
        var src = publicImageUrl(activeSignalImageField ? activeSignalImageField.value : '');
        $img.attr('src', src || '').toggle(!!src);
        $('[data-lst-default-signal-empty]').toggle(!src);
        $layer.empty().toggle(!!src);
        if (!src) return;

        var selected = selectedLightIndex();
        signalLights.forEach(function(light, index) {
            var icon = spriteUrl(light.type);
            var $point = $('<button type="button" class="lst-signal-point"></button>');
            $point
                .attr('data-index', String(index))
                .attr('data-signal-type', light.type || 'beacon')
                .attr('title', light.type + ' ' + Math.round(light.x * 100) + '/' + Math.round(light.y * 100))
                .toggleClass('is-selected', index === selected)
                .toggleClass('is-bar', light.type === 'bar')
                .toggleClass('has-sprite', !!icon)
                .css({
                    left: (light.x * 100) + '%',
                    top: (light.y * 100) + '%',
                    transform: 'translate(-50%, -50%) scale(' + light.size + ')',
                    animationDuration: Math.max(120, light.interval) + 'ms',
                    animationDelay: '-' + Math.max(0, light.phase) + 'ms',
                    backgroundImage: icon ? 'url("' + icon.replace(/"/g, '%22') + '")' : ''
                });
            if (icon) {
                $('<img alt="">')
                    .attr('src', icon)
                    .on('error', function() {
                        var key = icon === spriteMap().editor_point ? 'editor_point' : String(light.type || 'beacon');
                        failedSignalSprites[key] = true;
                        renderSignalLights();
                    })
                    .appendTo($point);
            }
            $layer.append($point);
        });
    }

    function applyPreset(name) {
        var presets = {
            rd: [
                { x: 0.38, y: 0.18, type: 'beacon', interval: 420, phase: 0, size: 1 },
                { x: 0.62, y: 0.18, type: 'beacon', interval: 420, phase: 210, size: 1 }
            ],
            pol: [
                { x: 0.42, y: 0.18, type: 'bar', interval: 360, phase: 0, size: 1 },
                { x: 0.58, y: 0.18, type: 'bar', interval: 360, phase: 180, size: 1 }
            ],
            clear: []
        };
        signalLights = normalizeSignalLights(presets[name] || []);
        setSelectedLight(signalLights.length ? 0 : -1);
    }

    function updateSelectedFromControls() {
        var index = selectedLightIndex();
        if (index < 0 || !signalLights[index]) return;
        signalLights[index].type = $('#lst-default-signal-type').val() || 'beacon';
        signalLights[index].interval = Math.round(clamp($('#lst-default-signal-interval').val(), 120, 2000));
        signalLights[index].phase = Math.round(clamp($('#lst-default-signal-phase').val(), 0, 5000));
        signalLights[index].size = clamp($('#lst-default-signal-size').val(), 0.4, 2.5);
        renderSignalLights();
    }

    function ensureSignalModal() {
        if ($('#lst-default-signal-modal').length) return;
        $('body').append(
            '<div id="lst-default-signal-modal" class="lst-default-signal-modal" style="display:none">' +
                '<div class="lst-default-signal-dialog">' +
                    '<h2 data-lst-default-signal-title>Blaulichter bearbeiten</h2>' +
                    '<section class="lst-signal-editor">' +
                        '<div class="lst-signal-editor__head">' +
                            '<strong>Default-Blaulichter</strong>' +
                            '<div class="lst-signal-editor__presets">' +
                                '<button type="button" class="button" data-lst-default-signal-preset="rd">RTW/KTW</button>' +
                                '<button type="button" class="button" data-lst-default-signal-preset="pol">Polizei</button>' +
                                '<button type="button" class="button" data-lst-default-signal-preset="clear">Leer</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="lst-signal-editor__body">' +
                            '<div class="lst-signal-stage" data-lst-default-signal-stage>' +
                                '<img id="lst-default-signal-preview" alt="Fahrzeugvorschau">' +
                                '<div class="lst-signal-layer" data-lst-default-signal-layer></div>' +
                                '<p data-lst-default-signal-empty>Bitte erst ein Fahrzeugbild wählen.</p>' +
                            '</div>' +
                            '<div class="lst-signal-panel">' +
                                '<label>Typ<select id="lst-default-signal-type">' +
                                    '<option value="beacon">Rundumleuchte</option>' +
                                    '<option value="strobe">Frontblitzer</option>' +
                                    '<option value="bar">Lichtbalken</option>' +
                                    '<option value="glow">Glow</option>' +
                                '</select></label>' +
                                '<label>Intervall <input type="number" id="lst-default-signal-interval" value="420" min="120" max="2000" step="20"></label>' +
                                '<label>Phase <input type="number" id="lst-default-signal-phase" value="0" min="0" max="5000" step="20"></label>' +
                                '<label>Größe <input type="number" id="lst-default-signal-size" value="1" min="0.4" max="2.5" step="0.1"></label>' +
                                '<button type="button" class="button" id="lst-default-signal-delete" disabled>Ausgewähltes Licht löschen</button>' +
                                '<small>Klick auf das Bild setzt ein Licht. Ziehen verschiebt es.</small>' +
                            '</div>' +
                        '</div>' +
                    '</section>' +
                    '<div class="lst-default-signal-actions">' +
                        '<button type="button" class="button" data-lst-default-signal-cancel>Abbrechen</button>' +
                        '<button type="button" class="button button-primary" data-lst-default-signal-save>Speichern</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        $('[data-lst-default-signal-stage]').on('click', function(event) {
            if ($(event.target).closest('.lst-signal-point').length) return;
            var point = pointFromEvent(event);
            if (!point || !(activeSignalImageField && activeSignalImageField.value)) return;
            signalLights.push({
                x: point.x,
                y: point.y,
                type: $('#lst-default-signal-type').val() || 'beacon',
                interval: Math.round(clamp($('#lst-default-signal-interval').val() || 420, 120, 2000)),
                phase: Math.round(clamp($('#lst-default-signal-phase').val() || 0, 0, 5000)),
                size: clamp($('#lst-default-signal-size').val() || 1, 0.4, 2.5)
            });
            setSelectedLight(signalLights.length - 1);
        });
        $('[data-lst-default-signal-layer]').on('pointerdown', '.lst-signal-point', function(event) {
            event.preventDefault();
            event.stopPropagation();
            dragLightIndex = parseInt($(this).attr('data-index'), 10);
            setSelectedLight(dragLightIndex);
            this.setPointerCapture && this.setPointerCapture(event.originalEvent.pointerId);
        });
        $(document).on('pointermove.lstDefaultSignals', function(event) {
            if (dragLightIndex === null || !signalLights[dragLightIndex]) return;
            var point = pointFromEvent(event);
            if (!point) return;
            signalLights[dragLightIndex].x = point.x;
            signalLights[dragLightIndex].y = point.y;
            renderSignalLights();
        });
        $(document).on('pointerup.lstDefaultSignals pointercancel.lstDefaultSignals', function() {
            dragLightIndex = null;
        });
        $('[data-lst-default-signal-preset]').on('click', function() {
            applyPreset($(this).attr('data-lst-default-signal-preset') || 'clear');
        });
        $('#lst-default-signal-type, #lst-default-signal-interval, #lst-default-signal-phase, #lst-default-signal-size').on('input change', updateSelectedFromControls);
        $('#lst-default-signal-delete').on('click', function() {
            var index = selectedLightIndex();
            if (index < 0 || !signalLights[index]) return;
            signalLights.splice(index, 1);
            setSelectedLight(signalLights.length ? Math.min(index, signalLights.length - 1) : -1);
        });
        $('[data-lst-default-signal-cancel], #lst-default-signal-modal').on('click', function(event) {
            if (event.target !== this && !$(event.target).is('[data-lst-default-signal-cancel]')) return;
            $('#lst-default-signal-modal').hide();
        });
        $('[data-lst-default-signal-save]').on('click', function() {
            if (activeSignalJsonField) {
                activeSignalJsonField.value = signalLightsJson();
            }
            $('#lst-default-signal-modal').hide();
        });
    }

    $(document).on('click', '.lst-default-image-upload', function(event) {
        event.preventDefault();
        var target = document.getElementById($(this).attr('data-target') || '');
        if (!target || !window.wp || !wp.media) return;
        var frame = wp.media({
            title: 'Fahrzeugbild auswählen',
            button: { text: 'Bild verwenden' },
            multiple: false
        });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first();
            var data = attachment ? attachment.toJSON() : null;
            if (data && data.url) {
                target.value = data.url;
                updateDefaultVehiclePreview(target.id);
            }
        });
        frame.open();
    });

    $(document).on('input change', '[data-lst-default-image-input]', function() {
        updateDefaultVehiclePreview(this.id);
    });

    $(document).on('click', '.lst-default-signal-editor-open', function(event) {
        event.preventDefault();
        ensureSignalModal();
        activeSignalImageField = document.getElementById($(this).attr('data-image-field') || '');
        activeSignalJsonField = document.getElementById($(this).attr('data-json-field') || '');
        $('[data-lst-default-signal-title]').text($(this).attr('data-title') || 'Blaulichter bearbeiten');
        signalLights = normalizeSignalLights(activeSignalJsonField ? activeSignalJsonField.value : '');
        if (!signalLights.length) {
            $('#lst-default-signal-type').val('beacon');
            $('#lst-default-signal-interval').val('420');
            $('#lst-default-signal-phase').val('0');
            $('#lst-default-signal-size').val('1');
        }
        setSelectedLight(signalLights.length ? 0 : -1);
        updateAllDefaultVehiclePreviews();
        renderSignalLights();
        $('#lst-default-signal-modal').css('display', 'flex');
    });

    $(function() {
        updateAllDefaultVehiclePreviews();
    });
})(jQuery);

function updateWachenZuordButtonState() {
    var $btn = jQuery('#w_zuord_button_l');

    var geojson = jQuery('#edit-leitstelle-formular')
        .find('[name="geojson_edit"]')
        .first()
        .val();

    var hasGeo = false;

    if (geojson) {
        try {
            var parsed = JSON.parse(geojson);

            hasGeo =
                parsed &&
                parsed.features &&
                parsed.features.length > 0;

        } catch (e) {
            hasGeo = false;
        }
    }

    if (hasGeo) {
        $btn.prop('disabled', false);
        $btn.attr('title', 'Zuordnung der Wachen bearbeiten');
    } else {
        $btn.prop('disabled', true);
        $btn.attr('title', 'Bitte zuerst ein Einsatzgebiet anlegen');
    }
}

(function($) {

    window.openLeitstelleHospitalsEditor = openLeitstelleHospitalsEditor;

    function openLeitstelleHospitalsEditor(id) {
        console.log('[leitstellen_editor] Button geklickt, ID=', id);

        $.getJSON(window.lstLeitstellenAjax.ajax_url, {
                action: 'get_leitstelle_hospitals',
                leitstelle_id: id,
                nonce: window.lstLeitstellenAjax.nonce
            })
            .done(function(json) {
                if (!json.success) {
                    return alert('Fehler beim Laden: ' + json.data);
                }

                const data = json.data;
                const tpl = wp.template('leitstellen-hospitals-editor');
                const $modal = $('#leitstellen-hospitals-modal');

                $modal.find('.modal-body').html(tpl({
                    leitstelle_id: data.leitstelle_id,
                    hospitals: data.hospitals,
                    selected_ids: data.existing || [],
                    geojson: data.geojson,
                    leitstelle_lat: data.leitstelle_lat,
                    leitstelle_lon: data.leitstelle_lon
                }));

                const hasExisting = Array.isArray(data.existing) && data.existing.length > 0;
                const existingArr = (data.existing || []).map(function(n) {
                    return String(n);
                });

                data.hospitals.forEach(function(h) {
                    const sid = String(h.id);
                    if (hasExisting && existingArr.includes(sid)) {
                        $modal.find('.hos-toggle[value="' + sid + '"]').prop('checked', true);
                    }
                });

                $modal.find('#leitstellen-hospitals-filter')
                    .off('input')
                    .on('input', function() {
                        const term = String(this.value || '').toLowerCase();

                        $modal.find('#leitstellen-hospitals-selector .hospital-row, #leitstellen-hospitals-selector label').each(function() {
                            const $row = $(this);
                            const txt = $row.text().toLowerCase();
                            const idv = String($row.find('input').val() || '');
                            $row.toggle(txt.includes(term) || idv.includes(term));
                        });
                    });

                const mapDiv = $modal.find('#leitstellen-hospitals-map')[0];
                const format = new ol.format.GeoJSON();

                let geojsonObj;
                try {
                    geojsonObj = typeof data.geojson === 'string' ? JSON.parse(data.geojson) : data.geojson;
                } catch (e) {
                    return alert('Ungültiges Einsatzgebiet');
                }

                const polyFeats = format.readFeatures(geojsonObj, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857'
                });

                const vectorSource = new ol.source.Vector({
                    features: polyFeats
                });

                const vectorLayer = new ol.layer.Vector({
                    source: vectorSource,
                    style: new ol.style.Style({
                        stroke: new ol.style.Stroke({
                            color: '#0074D9',
                            width: 2
                        }),
                        fill: new ol.style.Fill({
                            color: 'rgba(0,116,217,0.1)'
                        })
                    })
                });

                const tooltipEl = document.createElement('div');
                tooltipEl.className = 'hospital-tooltip';
                document.body.appendChild(tooltipEl);

                const tooltipOverlay = new ol.Overlay({
                    element: tooltipEl,
                    offset: [10, -10],
                    positioning: 'bottom-center'
                });

                const hospSource = new ol.source.Vector();

                data.hospitals.forEach(function(h) {
                    const coord = ol.proj.fromLonLat([h.longitude, h.latitude]);

                    const feat = new ol.Feature({
                        geometry: new ol.geom.Point(coord),
                        id: String(h.id),
                        name: h.name
                    });

                    const inPoly = vectorSource.getFeatures().some(function(pf) {
                        return pf.getGeometry().intersectsCoordinate(coord);
                    });

                    const isActive = hasExisting ? existingArr.includes(String(h.id)) : inPoly;

                    feat.setStyle(new ol.style.Style({
                        image: new ol.style.Circle({
                            radius: 7,
                            fill: new ol.style.Fill({
                                color: isActive ? 'red' : 'lightblue'
                            }),
                            stroke: new ol.style.Stroke({
                                color: '#fff',
                                width: 2
                            })
                        })
                    }));

                    hospSource.addFeature(feat);

                    if (!hasExisting && inPoly) {
                        $modal.find('.hos-toggle[value="' + h.id + '"]').prop('checked', true);
                    }
                });

                const hospLayer = new ol.layer.Vector({
                    source: hospSource
                });

                const map = new ol.Map({
                    target: mapDiv,
                    layers: [
                        new ol.layer.Tile({
                            source: new ol.source.OSM()
                        }),
                        vectorLayer,
                        hospLayer
                    ],
                    overlays: [tooltipOverlay],
                    view: new ol.View({
                        center: ol.proj.fromLonLat([data.leitstelle_lon, data.leitstelle_lat]),
                        zoom: 10
                    })
                });

                map.on('pointermove', function(e) {
                    const f = map.forEachFeatureAtPixel(e.pixel, function(feat) {
                        return feat;
                    });
                    if (f && f.get('name')) {
                        tooltipEl.innerHTML = f.get('name');
                        tooltipOverlay.setPosition(e.coordinate);
                        tooltipEl.style.display = '';
                    } else {
                        tooltipEl.style.display = 'none';
                    }
                });

                const selectHosp = new ol.interaction.Select({
                    layers: [hospLayer],
                    hitTolerance: 6,
                    style: null,
                    condition: ol.events.condition.singleClick
                });

                const dragPan = map.getInteractions().getArray().find(function(i) {
                    return i instanceof ol.interaction.DragPan;
                });

                selectHosp.on('select', function() {
                    if (dragPan) dragPan.setActive(false);
                    setTimeout(function() {
                        if (dragPan) dragPan.setActive(true);
                    }, 0);
                });

                map.addInteraction(selectHosp);

                selectHosp.on('select', function(evt) {
                    evt.selected.forEach(function(feat) {
                        const hid = feat.get('id');
                        const $chk = $modal.find('.hos-toggle[value="' + hid + '"]');
                        const now = !$chk.prop('checked');

                        $chk.prop('checked', now);

                        feat.setStyle(new ol.style.Style({
                            image: new ol.style.Circle({
                                radius: 7,
                                fill: new ol.style.Fill({
                                    color: now ? 'red' : 'lightblue'
                                }),
                                stroke: new ol.style.Stroke({
                                    color: '#fff',
                                    width: 2
                                })
                            })
                        }));
                    });

                    selectHosp.getFeatures().clear();
                });

                $modal.find('#leitstellen-hospitals-cancel, .modal-close, .modal-overlay')
                    .off('click')
                    .on('click', function() {
                        $modal.addClass('hidden');
                    });

                $modal.find('#leitstellen-hospitals-form')
                    .off('submit')
                    .on('submit', function(e) {
                        e.preventDefault();

                        const selected = $modal.find('.hos-toggle:checked')
                            .map(function(_, el) {
                                return el.value;
                            })
                            .get();

                        $.ajax({
                            url: window.lstLeitstellenAjax.ajax_url,
                            method: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'save_leitstelle_hospitals',
                                leitstelle_id: id,
                                nonce: window.lstLeitstellenAjax.nonce,
                                hospitals: JSON.stringify(selected)
                            },
                            success: function(resp) {
                                if (!resp.success) {
                                    return alert('Fehler beim Speichern: ' + resp.data);
                                }
                                alert('Gespeichert');
                                $modal.addClass('hidden');
                            },
                            error: function(jq, status, err) {
                                console.error('Save-Error:', status, err);
                                alert('Fehler beim Speichern: ' + status);
                            }
                        });
                    });

                $modal.removeClass('hidden');
            })
            .fail(function(_, status, err) {
                console.error('[leitstellen_editor] AJAX-Fehler', status, err);
                alert('AJAX-Fehler: ' + status);
            });
    }

    $(document).on('click', '.open-leitstelle-hospitals-editor', function(e) {
        e.preventDefault();

        var id = $('#edit-leitstelle-formular').find('input[name="lst_update_id"]').first().val();
        if (!id) {
            id = $('#edit-leitstelle-formular').find('#lst_update_id').first().val();
        }

        if (!id || String(id) === '0') {
            alert('Bitte zuerst speichern.');
            return;
        }

        openLeitstelleHospitalsEditor(id);
    });

    console.log('[leitstellen_editor.js] Hospitals ready');
})(jQuery);


// ---------------------------------------------------------------------------
// OSM Layer Refresh (Leitstelle) sequenziell + Progress
// angepasst für scan/download + retry_after_ms vor success=false
// ---------------------------------------------------------------------------
(function($) {
    var osmQueueRunning = false;

    function getCurrentLeitstelleId() {
        var $f = $('#edit-leitstelle-formular');
        var v = $f.find('input[name="lst_update_id"]').first().val();
        if (!v) v = $f.find('#lst_update_id').first().val();
        v = parseInt(v, 10);
        return isNaN(v) ? 0 : v;
    }

    function findOsmButton() {
        var ids = ['#btn-osm-refresh', '#lst-osm-refresh-all', '#lst-osm-refresh', '#osm-refresh'];
        for (var i = 0; i < ids.length; i++) {
            var $b = $(ids[i]);
            if ($b.length) return $b;
        }
        return $();
    }

    function setOsmBusy(isBusy) {
        var $btn = findOsmButton();
        var $sp = $('#lst-osm-refresh-spinner');
        if ($btn.length) $btn.prop('disabled', !!isBusy);
        if ($sp.length) $sp.css('visibility', isBusy ? 'visible' : 'hidden');
    }

    function setOsmStatus(html, type) {
        var $box = $('#lst-osm-refresh-status');
        if (!$box.length) return;

        $box.removeClass('notice-success notice-error notice-warning notice-info');
        if (type === 'success') $box.addClass('notice-success');
        else if (type === 'error') $box.addClass('notice-error');
        else if (type === 'warning') $box.addClass('notice-warning');
        else $box.addClass('notice-info');

        $box.html(html);
        $box.show();
    }

    function ensureOsmProgressUi() {
        var $wrap = $('#lst-osm-progress-wrap');
        if ($wrap.length) return $wrap;

        var $btn = findOsmButton();
        if (!$btn.length) return $();

        $wrap = $(
            '<span id="lst-osm-progress-wrap" style="display:inline-flex;align-items:center;gap:8px;margin-left:10px;vertical-align:middle;">' +
            '<progress id="lst-osm-progress" value="0" max="100" style="width:200px;"></progress>' +
            '<span id="lst-osm-progress-text">0.0%</span>' +
            '<span id="lst-osm-progress-layer" style="opacity:.75;"></span>' +
            '</span>'
        );

        $btn.after($wrap);
        return $wrap;
    }

    function layerLabel(layerKey) {
        var labels = {
            'roads_lines': 'Straßen und befahrbare Wege',
            'landuse_residential': 'Wohngebiete',
            'landuse_industrial': 'Industrie',
            'landuse_commercial': 'Gewerbe',
            'landuse_retail': 'Einzelhandel',
            'landuse_allotments': 'Kleingärten',
            'landuse_farmland': 'Ackerland',
            'landuse_animal_keeping': 'Tierhaltung',
            'landuse_forest': 'Wald',
            'landuse_logging': 'Forstwirtschaft',
            'landuse_meadow': 'Wiese',
            'landuse_railway': 'Bahnanlagen',
            'landuse_cemetery': 'Friedhof',
            'landuse_landfill': 'Deponie',
            'landuse_quarry': 'Tagebau/Steinbruch',
            'landuse_recreation_ground': 'Erholungsgebiet',
            'landuse_religious': 'Religiöse Fläche'
        };
        return labels[layerKey] ? labels[layerKey] : String(layerKey || '');
    }

    function updateProgress(overallFloat, layerKey, layerPct, phase, dirtyDone, dirtyTotal, initialDownload) {
        ensureOsmProgressUi();

        var overallClamped = Math.max(0, Math.min(100, Number(overallFloat || 0)));
        var overallBar = Math.floor(overallClamped);
        var overallText = overallClamped.toFixed(1) + '%';

        var layerInfo = '';
        if (layerKey) {
            var lp = (typeof layerPct === 'number') ? layerPct : null;
            var phaseText = '';

            if (phase === 'scan') {
                phaseText = 'Scan';
            } else if (phase === 'download') {
                phaseText = initialDownload ? 'Initialdownload' : 'Download';
            } else if (phase) {
                phaseText = String(phase);
            }

            layerInfo = layerLabel(layerKey);

            if (phaseText) {
                layerInfo += ' – ' + phaseText;
            }

            if (phase === 'download' && typeof dirtyDone === 'number' && typeof dirtyTotal === 'number' && dirtyTotal > 0) {
                layerInfo += ' (' + dirtyDone + '/' + dirtyTotal + ')';
            } else if (lp !== null) {
                layerInfo += ' (' + String(lp) + '%)';
            }
        }

        $('#lst-osm-progress').val(overallBar);
        $('#lst-osm-progress-text').text(overallText);
        $('#lst-osm-progress-layer').text(layerInfo);
    }

    function getOsmNonce() {
        return (window.lstLeitstellenAjax && window.lstLeitstellenAjax.osm_nonce) ?
            window.lstLeitstellenAjax.osm_nonce :
            (window.lstLeitstellenAjax ? window.lstLeitstellenAjax.nonce : '');
    }

    function getAjaxUrl() {
        return (window.lstLeitstellenAjax ? window.lstLeitstellenAjax.ajax_url : ajaxurl);
    }

    function makeRunToken() {
        return 'rt_' + String(Date.now()) + '_' + String(Math.random()).slice(2);
    }

    function postLayerStep(lsId, layerKey, nonce, runToken, cursor, chunk, reset, force, endpointOffset) {
        return $.ajax({
            url: getAjaxUrl(),
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'lsttraining_osm_refresh_layer_step',
                leitstelle_id: lsId,
                layer: layerKey,
                nonce: nonce,
                run_token: runToken,
                cursor: cursor,
                chunk: chunk,
                scan_budget: (layerKey === 'roads_lines' ? 3 : 5),
                reset: reset ? '1' : '0',
                force: force ? '1' : '0',
                endpoint_offset: Math.max(0, Number(endpointOffset || 0))
            }
        });
    }

    function sleepMs(ms) {
        return new Promise(function(resolve) {
            setTimeout(resolve, ms);
        });
    }

    function renderResult(lsId, results) {
        var parts = [];
        parts.push('<strong>OSM Cache aktualisiert</strong><br>');
        parts.push('Leitstelle: ' + (lsId || ''));

        var keys = Object.keys(results || {});
        if (keys.length) {
            parts.push('<ul style="margin:6px 0 0 18px;">');
            keys.forEach(function(k) {
                var r = results[k];
                var msg = '';

                if (r && r.ok) {
                    msg = layerLabel(k) + ': ' + (r.feature_count || 0) + ' Features';

                    if (typeof r.dirty_total === 'number') {
                        msg += ', ' + r.dirty_total + ' geänderte Tiles';
                    }

                    if (r.unchanged) {
                        msg += ' (unverändert)';
                    }
                } else {
                    msg = layerLabel(k) + ': Fehler' + (r && r.message ? ' – ' + r.message : '');
                }

                parts.push('<li>' + msg + '</li>');
            });
            parts.push('</ul>');
        }

        setOsmStatus(parts.join(''), 'success');
    }

    function chunkForLayer(layerKey) {
        return (layerKey === 'roads_lines') ? 1 : 2;
    }

    function pauseAfterStepMs(layerKey, phase) {
        if (phase === 'scan') {
            return (layerKey === 'roads_lines') ? 2500 : 1500;
        }
        return (layerKey === 'roads_lines') ? 3000 : 2000;
    }

    function abortRun(message, type) {
        osmQueueRunning = false;
        setOsmBusy(false);
        if (message) {
            setOsmStatus(message, type || 'error');
        }
    }

    function isRetryableServerAbort(xhr) {
        if (!xhr || xhr.status === 0) {
            return true;
        }

        var status = Number(xhr.status || 0);
        var body = xhr.responseText ? String(xhr.responseText) : '';
        var hasJsonError = !!(xhr.responseJSON && xhr.responseJSON.data);

        return !hasJsonError &&
            (status === 500 || status === 502 || status === 503 || status === 504) &&
            (body === '' || /Internal Server Error|Bad Gateway|Service Unavailable|Gateway Time-out/i.test(body));
    }

    $(document).on('click', '#btn-osm-refresh, #lst-osm-refresh-all, #lst-osm-refresh, #osm-refresh', function(e) {
        e.preventDefault();

        if (osmQueueRunning) {
            setOsmStatus('OSM-Aktualisierung läuft bereits.', 'warning');
            return;
        }

        if (osmQueueRunning) {
            setOsmStatus('OSM-Aktualisierung läuft bereits.', 'warning');
            return;
        }

        var lsId = getCurrentLeitstelleId();
        if (!lsId) {
            abortRun('Bitte zuerst die Leitstelle speichern, damit eine ID existiert.', 'error');
            return;
        }

        var nonce = getOsmNonce();
        if (!nonce) {
            abortRun('Nonce fehlt.', 'error');
            return;
        }

        var confirmed = window.confirm(
            'Der OSM-Tile-Abgleich kann sehr lange dauern.\n\n' +
            'Bitte lasse diese Seite geöffnet und schließe oder aktualisiere sie während des Vorgangs nicht.\n\n' +
            'OK = Vorgang starten\n' +
            'Abbrechen = Vorgang nicht starten'
        );

        if (!confirmed) {
            setOsmStatus('OSM-Aktualisierung wurde abgebrochen.', 'warning');
            return;
        }

        osmQueueRunning = true;

        var layersQueue = [
            'roads_lines',
            'landuse_residential',
            'landuse_industrial',
            'landuse_commercial',
            'landuse_retail',
            'landuse_allotments',
            'landuse_farmland',
            'landuse_animal_keeping',
            'landuse_forest',
            'landuse_logging',
            'landuse_meadow',
            'landuse_railway',
            'landuse_cemetery',
            'landuse_landfill',
            'landuse_quarry',
            'landuse_recreation_ground',
            'landuse_religious'
        ];

        setOsmBusy(true);
        ensureOsmProgressUi();
        updateProgress(0, '', null, '', null, null);
        setOsmStatus('OSM-Daten werden geladen und gespeichert …', 'warning');

        var total = layersQueue.length;
        var doneLayers = 0;
        var combined = {};
        var syncStartedAt = Date.now();
        var maxContinuousRunMs = 45 * 60 * 1000;

        (async function runQueue() {
            for (var i = 0; i < layersQueue.length; i++) {
                var layerKey = layersQueue[i];

                var cursor = 0;
                var first = true;
                var layerDone = false;
                var lastFeatureCount = 0;
                var lastDirtyTotal = 0;
                var lastPhase = 'scan';
                var lastLayerProgress = 0;
                var lastInitialDownload = false;
                var endpointOffset = 0;
                var networkRetryCount = 0;
                var rt = makeRunToken();

                while (!layerDone) {
                    if ((Date.now() - syncStartedAt) >= maxContinuousRunMs) {
                        abortRun(
                            'Der Sync wurde nach 45 Minuten kontrolliert pausiert, bevor das Hosting den Langlauf beendet. ' +
                            'Der Fortschritt ist gespeichert. Bitte nach kurzer Pause erneut auf <strong>OSM Tiles sync</strong> klicken.',
                            'warning'
                        );
                        return;
                    }

                    updateProgress(
                        ((doneLayers + (lastLayerProgress / 100)) / total) * 100,
                        layerKey,
                        lastLayerProgress,
                        lastPhase,
                        null,
                        null,
                        lastInitialDownload
                    );

                    try {
                        var resp = await postLayerStep(
                            lsId,
                            layerKey,
                            nonce,
                            rt,
                            cursor,
                            chunkForLayer(layerKey),
                            first,
                            false,
                            endpointOffset
                        );

                        if (!resp || typeof resp !== 'object') {
                            abortRun('Fehler bei <code>' + layerLabel(layerKey) + '</code>: Leere oder ungültige Antwort.', 'error');
                            return;
                        }

                        if (resp.data && typeof resp.data.retry_after_ms === 'number' && resp.data.retry_after_ms > 0) {
                            var waitMs = Math.min(180000, Math.max(1000, resp.data.retry_after_ms));
                            var msg = resp.data.message ? String(resp.data.message) : 'Warte vor dem nächsten Schritt …';

                            endpointOffset += 1;
                            setOsmStatus(
                                msg + ' Retry in ' + Math.round(waitMs / 1000) + 's',
                                'warning'
                            );

                            await sleepMs(waitMs);
                            first = false;
                            continue;
                        }

                        if (!resp.success || !resp.data) {
                            var hardMsg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Unbekannter Fehler.';
                            abortRun('Fehler bei <code>' + layerLabel(layerKey) + '</code>: ' + hardMsg, 'error');
                            return;
                        }

                        if (resp.data && resp.data.success === false) {
                            var msgSoft = resp.data.message || 'Layer wird bereits aktualisiert.';

                            if (msgSoft.indexOf('bereits aktualisiert') !== -1) {
                                setOsmStatus(
                                    'Layer <code>' + layerLabel(layerKey) + '</code> läuft bereits – warte kurz …',
                                    'warning'
                                );
                                await sleepMs(1200);
                                first = false;
                                continue;
                            }

                            abortRun('Fehler bei <code>' + layerLabel(layerKey) + '</code>: ' + msgSoft, 'error');
                            return;
                        }

                        endpointOffset = 0;
                        networkRetryCount = 0;
                        lastPhase = resp.data.phase || lastPhase;

                        var layerProgress = lastLayerProgress;
                        if (typeof resp.data.progress === 'number') {
                            layerProgress = resp.data.progress;
                        } else if (
                            lastPhase === 'download' &&
                            typeof resp.data.dirty_done === 'number' &&
                            typeof resp.data.dirty_total === 'number' &&
                            resp.data.dirty_total > 0
                        ) {
                            layerProgress = (resp.data.dirty_done / resp.data.dirty_total) * 100;
                        }
                        lastLayerProgress = Math.max(lastLayerProgress, Math.min(100, layerProgress));
                        lastInitialDownload = lastPhase === 'download' && !!resp.data.initial_download;

                        var overall = ((doneLayers + (lastLayerProgress / 100)) / total) * 100;

                        updateProgress(
                            overall,
                            layerKey,
                            lastLayerProgress,
                            lastPhase,
                            resp.data.dirty_done,
                            resp.data.dirty_total,
                            lastInitialDownload
                        );

                        if (typeof resp.data.cursor === 'number') {
                            cursor = resp.data.cursor;
                        }

                        if (typeof resp.data.feature_count === 'number') {
                            lastFeatureCount = resp.data.feature_count;
                        }

                        if (typeof resp.data.dirty_total === 'number') {
                            lastDirtyTotal = resp.data.dirty_total;
                        }

                        if (resp.data.message) {
                            setOsmStatus(resp.data.message, 'info');
                        } else if (lastPhase === 'scan') {
                            setOsmStatus('Prüfe Änderungen für <code>' + layerLabel(layerKey) + '</code> …', 'info');
                        } else if (lastPhase === 'download') {
                            setOsmStatus('Lade geänderte Tiles für <code>' + layerLabel(layerKey) + '</code> …', 'info');
                        }

                        layerDone = !!resp.data.done;

                        if (layerDone) {
                            combined[layerKey] = {
                                ok: true,
                                feature_count: lastFeatureCount,
                                dirty_total: lastDirtyTotal,
                                unchanged: !!(resp.data.final && resp.data.final.unchanged),
                                used_cache: !!(resp.data.final && resp.data.final.used_cache)
                            };
                        }

                    } catch (xhr) {
                        console.error('[OSM AJAX ERROR]', xhr);
                        console.error('[OSM AJAX STATUS]', xhr && xhr.status, xhr && xhr.statusText);
                        console.error('[OSM AJAX RESPONSE]', xhr && xhr.responseText);

                        if (isRetryableServerAbort(xhr) && (Date.now() - syncStartedAt) >= (30 * 60 * 1000)) {
                            abortRun(
                                'Der Server hat den längeren Sync-Lauf beendet. Der Fortschritt ist gespeichert. ' +
                                'Bitte nach kurzer Pause erneut auf <strong>OSM Tiles sync</strong> klicken.',
                                'warning'
                            );
                            return;
                        }

                        if (isRetryableServerAbort(xhr) && networkRetryCount < 6) {
                            networkRetryCount += 1;
                            endpointOffset += 1;
                            var retryDelay = Math.min(15000, 1500 * networkRetryCount);

                            setOsmStatus(
                                'Serververbindung beim Sync von <code>' + layerLabel(layerKey) +
                                '</code> unterbrochen. Neuer Versuch ' + networkRetryCount +
                                '/6 in ' + Math.round(retryDelay / 1000) + 's …',
                                'warning'
                            );

                            await sleepMs(retryDelay);
                            first = false;
                            continue;
                        }

                        var body = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
                        var shortBody = body ? body.slice(0, 600) : '';

                        var msg2 = 'Request fehlgeschlagen.';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            msg2 = xhr.responseJSON.data.message;
                        } else if (shortBody) {
                            msg2 = shortBody;
                        }

                        abortRun('Fehler bei <code>' + layerLabel(layerKey) + '</code>: ' + msg2, 'error');
                        return;
                    }

                    first = false;
                    await sleepMs(pauseAfterStepMs(layerKey, lastPhase));
                }

                doneLayers += 1;
            }

            updateProgress(100, '', null, '', null, null);
            renderResult(lsId, combined);

        })().finally(function() {
            osmQueueRunning = false;
            setOsmBusy(false);
        });
    });
})(jQuery);

// ---------------------------------------------------------------------------
// Zuordnung-Button (Leitstelle) nur aktiv, wenn ID + Einsatzgebiet vorhanden
// ---------------------------------------------------------------------------
(function() {
    function getLeitstellenIdFromEditForm() {
        var frm = document.getElementById('edit-leitstelle-formular');
        if (!frm) return '';

        var el = frm.querySelector('input[name="lst_update_id"]');
        if (!el) el = frm.querySelector('#lst_update_id');

        return el ? String(el.value || '').trim() : '';
    }

    function getLeitstellenGeoJsonFromEditForm() {
        var frm = document.getElementById('edit-leitstelle-formular');
        if (!frm) return '';

        var el = frm.querySelector('[name="geojson_edit"], [name="geojson_einsatzgebiet_edit"], #geojson_edit');
        return el ? String(el.value || '').trim() : '';
    }

    function hasValidGeoJson() {
        var raw = getLeitstellenGeoJsonFromEditForm();
        if (!raw) return false;

        try {
            var parsed = JSON.parse(raw);

            if (!parsed) return false;

            if (parsed.type === 'FeatureCollection') {
                return Array.isArray(parsed.features) && parsed.features.length > 0;
            }

            if (parsed.type === 'Feature') {
                return !!parsed.geometry;
            }

            return !!parsed.type;
        } catch (e) {
            return false;
        }
    }

    function syncWachenZuordButton() {
        var btn = document.getElementById('w_zuord_button_l');
        if (!btn) return;

        var id = getLeitstellenIdFromEditForm();
        var hasId = (/^\d+$/).test(id) && id !== '0';
        var hasGeo = hasValidGeoJson();

        if (hasId && hasGeo) {
            btn.disabled = false;
            btn.title = 'Zuordnung der Wachen bearbeiten';
        } else {
            btn.disabled = true;
            btn.title = hasId ?
                'Bitte zuerst ein Einsatzgebiet anlegen' :
                'Bitte zuerst speichern';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('w_zuord_button_l');
        if (!btn) return;

        btn.addEventListener('click', function(e) {
            var id = getLeitstellenIdFromEditForm();
            var hasId = (/^\d+$/).test(id) && id !== '0';
            var hasGeo = hasValidGeoJson();

            if (!(hasId && hasGeo)) {
                e.preventDefault();
                syncWachenZuordButton();
                return;
            }

            if (typeof openZuordnungPopup === 'function') {
                e.preventDefault();
                openZuordnungPopup({
                    entityType: 'leitstelle',
                    entityId: id
                });
            }
        });

        syncWachenZuordButton();

        document.addEventListener('input', function(e) {
            if (
                e.target &&
                (
                    e.target.name === 'lst_update_id' ||
                    e.target.name === 'geojson_edit' ||
                    e.target.name === 'geojson_einsatzgebiet_edit' ||
                    e.target.id === 'geojson_edit'
                )
            ) {
                syncWachenZuordButton();
            }
        });

        document.addEventListener('change', function(e) {
            if (
                e.target &&
                (
                    e.target.name === 'lst_update_id' ||
                    e.target.name === 'geojson_edit' ||
                    e.target.name === 'geojson_einsatzgebiet_edit' ||
                    e.target.id === 'geojson_edit'
                )
            ) {
                syncWachenZuordButton();
            }
        });

        window.syncWachenZuordButton = syncWachenZuordButton;
    });
})();
