(function ($) {
    'use strict';

    var seasonLabels = {
        spring: 'Frühling',
        summer: 'Sommer',
        autumn: 'Herbst',
        winter: 'Winter'
    };

    var modeLabels = {
        singleplayer: 'Einzelspieler',
        multiplayer: 'Multiplayer',
        einsatzleiter: 'Einsatzleiter',
        leiter: 'Einsatzleiter',
        leiter_multiplayer: 'Einsatzleiter'
    };

    var areaPreview = {
        map: null,
        source: null,
        layer: null,
        markerSource: null,
        markerLayer: null,
        target: null,
        statusTimer: null
    };

    var dispatchMap = {
        map: null,
        stationSource: null,
        vehicleSource: null,
        incidentSource: null,
        routeSource: null,
        selectedVehicleId: null,
        selectedIncidentId: null,
        selectedStationId: null,
        bootstrap: null,
        lastSnapshot: null,
        hasFit: false,
        homeTarget: null,
        vehicleAnimation: {},
        markerMode: 'marker'
    };

    var simRuntime = {
        isBootstrapLoading: false,
        isSnapshotLoading: false,
        isTickLoading: false,
        isActionLoading: false,
        snapshotErrors: 0,
        snapshotTimer: null,
        tickTimer: null,
        clockTimer: null,
        popouts: {},
        stopped: false,
        animationFrame: null,
        nonceRefreshPromise: null,
        nominatimFallbackLogged: {},
        radioRequestsBeeped: {},
        forceSpawnOptions: null,
        isForceOptionsLoading: false,
        isForceSpawnLoading: false
    };

    var popoutConfig = {
        map: {
            title: 'Livekarte',
            selector: '.lsttraining-dispatch-mapwrap',
            width: 1100,
            height: 760,
            type: 'map'
        },
        vehicles: {
            title: 'Fahrzeuge',
            selector: '.lsttraining-dispatch-panel--vehicles',
            width: 520,
            height: 720
        },
        incidents: {
            title: 'Laufende Einsätze',
            selector: '.lsttraining-dispatch-panel--incidents',
            width: 520,
            height: 720
        },
        fms: {
            title: 'FMS-Meldungen',
            selector: '.lsttraining-dispatch-panel--fms',
            width: 760,
            height: 420
        },
        calls: {
            title: 'Anruferverlauf',
            selector: '.lsttraining-dispatch-panel--calls',
            width: 760,
            height: 520
        }
    };

    var simLayout = {
        storageKey: 'lsttraining_sim_layout_v1',
        gap: 8,
        minMap: 320,
        minVehicles: 240,
        minIncidents: 260,
        minTop: 260,
        minBottom: 180,
        values: null
    };

    function showMessage($root, type, message) {
        var $message = $root.find('[data-lst-message]');
        $message
            .removeClass('lsttraining-message--error lsttraining-message--success')
            .addClass(type === 'success' ? 'lsttraining-message--success' : 'lsttraining-message--error')
            .text(message)
            .prop('hidden', false);
    }

    function logSimulationError(label, message, details) {
        if (!window.console) {
            return;
        }

        var method = typeof window.console.error === 'function' ? 'error' : 'warn';
        if (typeof window.console[method] !== 'function') {
            return;
        }

        window.console[method]('[LSTtraining] ' + label + ': ' + message, details || {});
    }

    function incidentUsedNominatimFallback(incident) {
        var meta = incident && incident.meta ? incident.meta : {};
        var caller = meta && meta.caller ? meta.caller : {};
        return String(meta.address_source || '') === 'reverse_geocode_nominatim' ||
            String(caller.address_source || '') === 'reverse_geocode_nominatim';
    }

    function logNominatimFallbacks(snapshot) {
        if (!window.console || typeof window.console.log !== 'function') {
            return;
        }

        (snapshot && snapshot.incidents ? snapshot.incidents : []).forEach(function (incident) {
            var id = String(incident && (incident.id || incident.einsatz_id) || '');
            if (!id || simRuntime.nominatimFallbackLogged[id] || !incidentUsedNominatimFallback(incident)) {
                return;
            }

            simRuntime.nominatimFallbackLogged[id] = true;
            window.console.log('Nominatim Fallback');
        });
    }

    function clearMessage($root) {
        $root.find('[data-lst-message]').prop('hidden', true).text('');
    }

    function seasonFromDate(dateValue) {
        var month = parseInt((dateValue || '').slice(5, 7), 10);
        if (month >= 3 && month <= 5) {
            return 'spring';
        }
        if (month >= 6 && month <= 8) {
            return 'summer';
        }
        if (month >= 9 && month <= 11) {
            return 'autumn';
        }
        return 'winter';
    }

    function updateSeason($root) {
        var dateValue = $root.find('[name="start_date"]').val();
        var override = $root.find('[data-lst-season-select]').val();
        var season = override === 'auto' ? seasonFromDate(dateValue) : override;
        var label = seasonLabels[season] || 'Automatisch';

        $root.find('[data-lst-season-label]').text(label);
    }

    function setNow($root) {
        var now = new Date();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        var hours = String(now.getHours()).padStart(2, '0');
        var minutes = String(now.getMinutes()).padStart(2, '0');

        $root.find('[name="start_date"]').val(now.getFullYear() + '-' + month + '-' + day);
        $root.find('[name="start_time"]').val(hours + ':' + minutes);
        updateSeason($root);
    }

    function getCurrentUrl() {
        var url = new URL(window.location.href);
        url.searchParams.delete('lst_instance');
        url.searchParams.delete('lst_sim_view');
        return url.toString();
    }

    function instanceUrl(instanzId) {
        var url = new URL(getCurrentUrl());
        url.searchParams.set('lst_instance', instanzId);
        url.searchParams.set('lst_sim_view', '1');
        return url.toString();
    }

    function ajaxErrorMessage(xhr, fallback) {
        var contentType = xhr && typeof xhr.getResponseHeader === 'function' ? (xhr.getResponseHeader('content-type') || '') : '';
        var responseText = xhr && typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
        if (xhr && xhr.message) {
            return xhr.message;
        }
        if (xhr && xhr.status === 400 && contentType.indexOf('text/html') !== -1 && (!responseText || responseText === '0')) {
            return lsttrainingFrontend.texts.missingAjaxAction || fallback;
        }
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
            return xhr.responseJSON.error;
        }
        if (xhr && xhr.responseJSON && typeof xhr.responseJSON.data === 'string') {
            return xhr.responseJSON.data;
        }
        return fallback;
    }

    function isNonceError(xhr) {
        var message = ajaxErrorMessage(xhr, '');
        var responseText = xhr && typeof xhr.responseText === 'string' ? xhr.responseText : '';
        return /Ungültiger Sicherheits-Token/i.test(message) ||
            /Ungültiger Sicherheits-Token/i.test(responseText) ||
            ((xhr && (xhr.status === 400 || xhr.status === 403)) && /nonce|token/i.test(message + ' ' + responseText));
    }

    function isNonceResponse(response) {
        var message = response && response.data && response.data.message ? String(response.data.message) : '';
        return response && response.success === false && /Ungültiger Sicherheits-Token/i.test(message);
    }

    function refreshSimulationNonce() {
        if (simRuntime.nonceRefreshPromise) {
            return simRuntime.nonceRefreshPromise;
        }

        simRuntime.nonceRefreshPromise = $.post(lsttrainingFrontend.ajax_url, {
            action: 'lsttraining_sim_refresh_nonce'
        }).then(function (response) {
            if (!response || !response.success || !response.data || !response.data.nonce) {
                return $.Deferred().reject({
                    message: 'Sitzung abgelaufen. Bitte Seite neu laden.'
                }).promise();
            }
            lsttrainingFrontend.nonce = response.data.nonce;
            if (response.data.rest_nonce) {
                lsttrainingFrontend.rest_nonce = response.data.rest_nonce;
            }
            return response;
        }, function () {
            return $.Deferred().reject({
                message: 'Sitzung abgelaufen. Bitte Seite neu laden.'
            }).promise();
        }).always(function () {
            simRuntime.nonceRefreshPromise = null;
        });

        return simRuntime.nonceRefreshPromise;
    }

    function simPost(payload) {
        var retried = false;
        function send() {
            var data = $.extend({}, payload, {
                nonce: lsttrainingFrontend.nonce
            });
            return $.post(lsttrainingFrontend.ajax_url, data).then(function (response) {
                if (!retried && isNonceResponse(response)) {
                    retried = true;
                    return refreshSimulationNonce().then(send);
                }
                return response;
            }, function (xhr) {
                if (!retried && isNonceError(xhr)) {
                    retried = true;
                    return refreshSimulationNonce().then(send);
                }
                return $.Deferred().reject(xhr).promise();
            });
        }
        return send();
    }

    function routePost(payload) {
        var retried = false;
        function send() {
            return $.ajax({
                url: (lsttrainingFrontend.rest_url || '/wp-json/lst/v1/').replace(/\/?$/, '/') + 'route',
                method: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-WP-Nonce': lsttrainingFrontend.rest_nonce || ''
                },
                data: JSON.stringify(payload)
            }).then(null, function (xhr) {
                if (!retried && isNonceError(xhr)) {
                    retried = true;
                    return refreshSimulationNonce().then(send);
                }
                return $.Deferred().reject(xhr).promise();
            });
        }
        return send();
    }

    function openSimulationWindow() {
        try {
            return window.open('about:blank', '_blank');
        } catch (error) {
            return null;
        }
    }

    function navigateToSimulation(url, targetWindow) {
        if (targetWindow && !targetWindow.closed) {
            targetWindow.location.href = url;
            return;
        }

        window.location.href = url;
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function formatTime(value) {
        if (!value) {
            return '';
        }

        var date = new Date(String(value).replace(' ', 'T'));
        if (!Number.isNaN(date.getTime())) {
            return [
                String(date.getHours()).padStart(2, '0'),
                String(date.getMinutes()).padStart(2, '0'),
                String(date.getSeconds()).padStart(2, '0')
            ].join(':');
        }

        var match = String(value).match(/(\d{2}:\d{2}(?::\d{2})?)/);
        return match ? match[1] : String(value);
    }

    function updateSimClock($root) {
        var now = new Date();
        var date = [
            String(now.getDate()).padStart(2, '0'),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getFullYear())
        ].join('.');
        var time = [
            String(now.getHours()).padStart(2, '0'),
            String(now.getMinutes()).padStart(2, '0'),
            String(now.getSeconds()).padStart(2, '0')
        ].join(':');

        $root.find('[data-lst-sim-date]').text(date);
        $root.find('[data-lst-sim-time]').text(time);
    }

    function startSimClock($root) {
        updateSimClock($root);
        simRuntime.clockTimer = window.setInterval(function () {
            updateSimClock($root);
        }, 1000);
    }

    function fmsLabel(value) {
        return 'S' + (value || '2');
    }

    function supportsMapImage(url) {
        var clean = String(url || '').split('?')[0].split('#')[0].toLowerCase();
        return clean === '' || /\.(png|webp|gif|svg|jpe?g)$/.test(clean);
    }

    function vehicleMarkerMode(snapshot) {
        var preferences = dispatchMap.bootstrap && dispatchMap.bootstrap.preferences
            ? dispatchMap.bootstrap.preferences
            : (snapshot && snapshot.preferences ? snapshot.preferences : {});
        var mode = String(preferences.vehicle_marker_mode || '');
        if (mode === 'image' || mode === 'tactical') {
            return mode;
        }
        return 'marker';
    }

    function bootstrapStations() {
        var bootstrap = dispatchMap.bootstrap || {};
        var snapshot = dispatchMap.lastSnapshot || {};
        return bootstrap.stations || snapshot.stations || [];
    }

    function bootstrapBaseVehicles() {
        var bootstrap = dispatchMap.bootstrap || {};
        var snapshot = dispatchMap.lastSnapshot || {};
        return bootstrap.base_vehicles || snapshot.available_vehicles || [];
    }

    function simulationInstance() {
        var bootstrap = dispatchMap.bootstrap || {};
        var snapshot = dispatchMap.lastSnapshot || {};
        return bootstrap.instance || snapshot.instance || {};
    }

    function vehicleVisibleOnMap(vehicle) {
        return ['1', '3', '5', '7'].indexOf(String(vehicle && vehicle.fms_status)) !== -1;
    }

    function vehicleHasLiveMarker(vehicle) {
        var live = dispatchMap.lastSnapshot && dispatchMap.lastSnapshot.vehicles ? dispatchMap.lastSnapshot.vehicles : [];
        return live.some(function (item) {
            return String(item.status_id || '') === String(vehicle && vehicle.status_id || '') ||
                String(item.fahrzeug_id || '') === String(vehicle && vehicle.fahrzeug_id || '');
        });
    }

    function tacticalVehicleLetter(vehicle) {
        var type = String(vehicle && (vehicle.fahrzeugtyp || vehicle.rufname) || '').toUpperCase();
        if (type.indexOf('NEF') !== -1 || type.indexOf('NAW') !== -1 || type.indexOf('RTH') !== -1 || type.indexOf('ITH') !== -1 || type.indexOf('BABY-NAW') !== -1) {
            return 'NA';
        }
        if (type.indexOf('RTW') !== -1) {
            return 'RTW';
        }
        if (type.indexOf('KTW') !== -1) {
            return 'KTW';
        }
        if (type.indexOf('HLF') !== -1 || type.indexOf('LF') !== -1 || type.indexOf('TLF') !== -1 || type.indexOf('DLK') !== -1) {
            return 'FW';
        }
        if (type.indexOf('THW') !== -1) {
            return 'THW';
        }
        return (type || 'FZ').slice(0, 3);
    }

    function stationColor(kind) {
        switch (String(kind || '').toLowerCase()) {
            case 'rd':
                return '#15803d';
            case 'fw':
                return '#c92828';
            case 'thw':
                return '#1f66d1';
            default:
                return '#6b7280';
        }
    }

    function setAreaStatus($root, message, isError, autoHide) {
        var $status = $root.find('[data-lst-area-status]');

        if (areaPreview.statusTimer) {
            window.clearTimeout(areaPreview.statusTimer);
            areaPreview.statusTimer = null;
        }

        $status
            .toggleClass('lsttraining-area-status--error', !!isError)
            .text(message)
            .prop('hidden', false);

        if (autoHide && !isError) {
            areaPreview.statusTimer = window.setTimeout(function () {
                $status.prop('hidden', true);
                areaPreview.statusTimer = null;
            }, 2000);
        }
    }

    function ensureAreaMap($root) {
        var target = $root.find('[data-lst-area-map]').get(0);

        if (!target || typeof ol === 'undefined') {
            return null;
        }

        if (areaPreview.map && areaPreview.target === target) {
            return areaPreview;
        }

        areaPreview.target = target;
        areaPreview.source = new ol.source.Vector();
        areaPreview.markerSource = new ol.source.Vector();
        areaPreview.layer = new ol.layer.Vector({
            source: areaPreview.source,
            style: new ol.style.Style({
                stroke: new ol.style.Stroke({
                    color: 'rgba(0,128,255,0.8)',
                    width: 2
                }),
                fill: new ol.style.Fill({
                    color: 'rgba(0,128,255,0.2)'
                })
            })
        });
        areaPreview.markerLayer = new ol.layer.Vector({
            source: areaPreview.markerSource,
            style: new ol.style.Style({
                image: new ol.style.RegularShape({
                    points: 30,
                    radius: 8,
                    fill: new ol.style.Fill({ color: '#ff0000' }),
                    stroke: new ol.style.Stroke({ color: '#ffffff', width: 2 })
                })
            })
        });

        areaPreview.map = new ol.Map({
            target: target,
            layers: [
                new ol.layer.Tile({
                    source: new ol.source.OSM({
                        crossOrigin: 'anonymous'
                    })
                }),
                areaPreview.layer,
                areaPreview.markerLayer
            ],
            view: new ol.View({
                center: ol.proj.fromLonLat([13.0645, 52.3906]),
                zoom: 9
            })
        });

        window.setTimeout(function () {
            areaPreview.map.updateSize();
        }, 0);

        return areaPreview;
    }

    function clearAreaPreview($root, message, isError) {
        var preview = areaPreview.map ? ensureAreaMap($root) : null;
        if (preview && preview.source) {
            preview.source.clear();
        }
        if (preview && preview.markerSource) {
            preview.markerSource.clear();
        }
        setAreaStatus($root, message || 'Wähle eine Leitstelle aus, um das Einsatzgebiet anzuzeigen.', isError);
    }

    function geoJsonFeatures(geojson) {
        var format;
        var features = [];
        var parsed = geojson;

        if (!parsed || typeof ol === 'undefined') {
            return [];
        }

        if (typeof parsed === 'string') {
            try {
                parsed = JSON.parse(parsed);
            } catch (error) {
                return [];
            }
        }

        format = new ol.format.GeoJSON();
        try {
            features = format.readFeatures(parsed, {
                dataProjection: 'EPSG:4326',
                featureProjection: 'EPSG:3857'
            });

            if (!features.length && parsed.type && parsed.type !== 'Feature' && parsed.type !== 'FeatureCollection') {
                features = [
                    format.readFeature({
                        type: 'Feature',
                        properties: {},
                        geometry: parsed
                    }, {
                        dataProjection: 'EPSG:4326',
                        featureProjection: 'EPSG:3857'
                    })
                ].filter(Boolean);
            }
        } catch (error) {
            features = [];
        }

        return features;
    }

    function fitAreaPreview(preview, extent) {
        if (!preview || !preview.map || !extent || !isFinite(extent[0]) || !isFinite(extent[1]) || !isFinite(extent[2]) || !isFinite(extent[3])) {
            return;
        }

        preview.map.updateSize();
        window.setTimeout(function () {
            preview.map.updateSize();
            preview.map.getView().fit(extent, {
                padding: [24, 24, 24, 24],
                maxZoom: 12,
                duration: 250
            });
        }, 0);
    }

    function addLeitstelleMarker(preview, markerCoords) {
        var lat;
        var lon;
        var marker;

        if (!preview || !preview.markerSource || !markerCoords) {
            return;
        }

        lat = parseFloat(markerCoords.lat);
        lon = parseFloat(markerCoords.lon);
        if (!isFinite(lat) || !isFinite(lon)) {
            return;
        }

        marker = new ol.Feature({
            geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat]))
        });
        preview.markerSource.addFeature(marker);
    }

    function renderAreaPreview($root, geojson, markerCoords) {
        var preview = ensureAreaMap($root);
        var features;
        var extent;
        var markerExtent;

        if (!preview) {
            setAreaStatus($root, 'Kartenbibliothek konnte nicht geladen werden.', true);
            return;
        }

        preview.source.clear();
        preview.markerSource.clear();
        features = geoJsonFeatures(geojson);
        if (features.length) {
            preview.source.addFeatures(features);
        }
        addLeitstelleMarker(preview, markerCoords);
        extent = features.length ? preview.source.getExtent() : null;

        if (preview.markerSource.getFeatures().length) {
            markerExtent = preview.markerSource.getExtent();
            if (extent && isFinite(extent[0]) && typeof ol.extent !== 'undefined') {
                ol.extent.extend(extent, markerExtent);
            } else {
                extent = markerExtent;
            }
        }

        if (!features.length) {
            fitAreaPreview(preview, extent);
            setAreaStatus($root, 'Kein Einsatzgebiet hinterlegt.', false);
            return;
        }

        fitAreaPreview(preview, extent);
        setAreaStatus($root, 'Einsatzgebiet geladen.', false, true);
    }

    function loadAreaPreview($root, leitstelleId, markerCoords) {
        if (!leitstelleId) {
            clearAreaPreview($root);
            return;
        }

        clearAreaPreview($root, 'Einsatzgebiet wird geladen...');

        $.post(lsttrainingFrontend.ajax_url, {
            action: 'lsttraining_get_einsatzgebiet',
            leitstelle_id: leitstelleId
        }).done(function (response) {
            if (!response || !response.success) {
                setAreaStatus($root, 'Einsatzgebiet konnte nicht geladen werden.', true);
                return;
            }

            renderAreaPreview($root, response.data, markerCoords);
        }).fail(function (xhr) {
            clearAreaPreview($root, ajaxErrorMessage(xhr, 'Einsatzgebiet konnte nicht geladen werden.'), true);
        });
    }

    function loadLeitstellen($root) {
        var $select = $root.find('[data-lst-leitstellen]');
        $select.prop('disabled', true).html(
            $('<option>', {
                value: '',
                text: lsttrainingFrontend.texts.loadingLeitstellen
            })
        );

        $.post(lsttrainingFrontend.ajax_url, {
            action: 'lsttraining_frontend_get_leitstellen',
            nonce: lsttrainingFrontend.nonce
        }).done(function (response) {
            var items = response && response.success && response.data ? response.data.items : [];
            $select.empty();

            if (!items.length) {
                $select.append($('<option>', {
                    value: '',
                    text: lsttrainingFrontend.texts.noLeitstellen
                }));
                return;
            }

            $select.append($('<option>', {
                value: '',
                text: 'Bitte auswählen'
            }));

            items.forEach(function (item) {
                var parts = [item.name, item.ort, item.bundesland].filter(Boolean);
                $select.append($('<option>', {
                    value: item.id,
                    text: parts.join(' - '),
                    'data-lat': item.latitude || '',
                    'data-lon': item.longitude || ''
                }));
            });
        }).fail(function (xhr) {
            showMessage($root, 'error', ajaxErrorMessage(xhr, 'Leitstellen konnten nicht geladen werden.'));
            $select.empty().append($('<option>', {
                value: '',
                text: 'Fehler beim Laden'
            }));
        }).always(function () {
            $select.prop('disabled', false);
        });
    }

    function instanceMeta(item) {
        var settings = item.settings || {};
        var parts = [];

        if (item.leitstelle_name) {
            parts.push(item.leitstelle_name);
        }
        if (item.leitstelle_ort) {
            parts.push(item.leitstelle_ort);
        }
        if (settings.start_date || settings.start_time) {
            parts.push([settings.start_date, settings.start_time].filter(Boolean).join(' '));
        } else if (item.started_at) {
            parts.push(item.started_at);
        }

        return parts.join(' - ');
    }

    function renderOpenInstances($root, items, message) {
        var $list = $root.find('[data-lst-open-instances]');
        $list.empty();

        if (message) {
            $list.append($('<p>', {
                class: 'lsttraining-muted lsttraining-muted--error',
                text: message
            }));
        }

        if (!items || !items.length) {
            $list.append($('<p>', {
                class: 'lsttraining-muted',
                text: lsttrainingFrontend.texts.noInstances
            }));
            return;
        }

        items.forEach(function (item) {
            var mode = item.mode || (item.settings && item.settings.mode) || '';
            var canJoin = !!item.can_join;
            var $card = $('<article>', {
                class: 'lsttraining-open-card',
                'data-instance-id': item.id
            });

            $('<div>', { class: 'lsttraining-open-card__status' }).appendTo($card);
            $('<strong>', { text: item.name || 'Simulation ' + item.id }).appendTo($card);
            $('<span>', {
                class: 'lsttraining-open-card__mode',
                text: modeLabels[mode] || item.mode_label || mode
            }).appendTo($card);
            $('<p>', { text: instanceMeta(item) }).appendTo($card);
            $('<small>', {
                text: (parseInt(item.participants_count, 10) || 0) + ' Teilnehmer - ' +
                    (parseInt(item.fahrzeuge_count, 10) || 0) + ' Fahrzeuge'
            }).appendTo($card);

            $('<button>', {
                type: 'button',
                class: 'lsttraining-btn lsttraining-btn--small lsttraining-btn--join',
                text: canJoin ? 'Beitreten' : 'Öffnen',
                'data-lst-join-instance': item.id,
                'data-lst-can-join': canJoin ? '1' : '0'
            }).appendTo($card);

            $list.append($card);
        });
    }

    function loadOpenInstances($root) {
        var $list = $root.find('[data-lst-open-instances]');
        $list.html($('<p>', {
            class: 'lsttraining-muted',
            text: lsttrainingFrontend.texts.loadingInstances
        }));

        $.post(lsttrainingFrontend.ajax_url, {
            action: 'lsttraining_frontend_get_open_instances',
            nonce: lsttrainingFrontend.nonce
        }).done(function (response) {
            var items = response && response.success && response.data ? response.data.items : [];
            var message = response && response.success && response.data ? response.data.message : '';
            renderOpenInstances($root, items, message);
        }).fail(function (xhr) {
            $list.html($('<p>', {
                class: 'lsttraining-muted lsttraining-muted--error',
                text: ajaxErrorMessage(xhr, 'Offene Spiele konnten nicht geladen werden.')
            }));
        });
    }

    function joinInstance($root, instanzId, $button, targetWindow) {
        clearMessage($root);
        $button.prop('disabled', true).addClass('is-loading');

        $.post(lsttrainingFrontend.ajax_url, {
            action: 'lsttraining_frontend_join_instance',
            nonce: lsttrainingFrontend.nonce,
            instanz_id: instanzId,
            current_url: getCurrentUrl()
        }).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.redirect_url) {
                showMessage($root, 'error', lsttrainingFrontend.texts.joinError);
                return;
            }

            showMessage($root, 'success', lsttrainingFrontend.texts.joinSuccess);
            window.setTimeout(function () {
                navigateToSimulation(response.data.redirect_url, targetWindow);
            }, 500);
        }).fail(function (xhr) {
            if (targetWindow && !targetWindow.closed) {
                targetWindow.close();
            }
            showMessage($root, 'error', ajaxErrorMessage(xhr, lsttrainingFrontend.texts.joinError));
        }).always(function () {
            $button.prop('disabled', false).removeClass('is-loading');
        });
    }

    function collectFormData($form) {
        return {
            action: 'lsttraining_frontend_create_instance',
            nonce: lsttrainingFrontend.nonce,
            current_url: getCurrentUrl(),
            leitstelle_id: $form.find('[name="leitstelle_id"]').val(),
            start_date: $form.find('[name="start_date"]').val(),
            start_time: $form.find('[name="start_time"]').val(),
            season_override: $form.find('[name="season_override"]').val(),
            mode: $form.find('[name="mode"]:checked').val()
        };
    }

    function renderEinsaetze($root, items) {
        var $list = $root.find('[data-lst-einsaetze]');
        var maxId = parseInt($root.attr('data-last-einsatz-id') || '0', 10) || 0;

        if (!items || !items.length) {
            if (!$list.children('.lsttraining-einsatz-card').length) {
                $list.html($('<p>', {
                    class: 'lsttraining-muted',
                    text: lsttrainingFrontend.texts.noEinsaetze
                }));
            }
            return;
        }

        $list.find('.lsttraining-muted').remove();
        items.forEach(function (item) {
            var id = parseInt(item.id, 10) || 0;
            if (!id || $list.find('[data-einsatz-id="' + id + '"]').length) {
                return;
            }
            maxId = Math.max(maxId, id);

            var meta = item.meta || {};
            var $card = $('<article>', {
                class: 'lsttraining-einsatz-card',
                'data-einsatz-id': id
            });

            $('<div>', {
                class: 'lsttraining-open-card__status'
            }).appendTo($card);
            $('<strong>', {
                text: (item.einsatzart || '') + ' - ' + (item.einsatztyp || 'Einsatz')
            }).appendTo($card);
            $('<span>', {
                class: 'lsttraining-open-card__mode',
                text: item.state || 'new'
            }).appendTo($card);
            $('<p>', {
                text: item.caller_text || item.lagemeldung || ''
            }).appendTo($card);
            $('<small>', {
                text: [
                    item.poi_name_snapshot || item.poi_type || '',
                    meta.landuse_layer || meta.density_source || '',
                    item.latitude && item.longitude ? Number(item.latitude).toFixed(5) + ', ' + Number(item.longitude).toFixed(5) : ''
                ].filter(Boolean).join(' - ')
            }).appendTo($card);

            $list.prepend($card);
        });

        $root.attr('data-last-einsatz-id', String(maxId));
    }

    function loadEinsaetze($root) {
        var instanzId = $root.attr('data-instance-id');
        var sinceId = parseInt($root.attr('data-last-einsatz-id') || '0', 10) || 0;

        simPost({
            action: 'lsttraining_sim_get_updates',
            instanz_id: instanzId,
            since_id: sinceId
        }).done(function (response) {
            var items = response && response.success && response.data ? response.data.items : [];
            renderEinsaetze($root, items);
        }).fail(function (xhr) {
            var $list = $root.find('[data-lst-einsaetze]');
            if (!$list.children('.lsttraining-einsatz-card').length) {
                $list.html($('<p>', {
                    class: 'lsttraining-muted lsttraining-muted--error',
                    text: ajaxErrorMessage(xhr, 'Einsätze konnten nicht geladen werden.')
                }));
            }
        });
    }

    function dispatchPoint(lon, lat) {
        lon = Number(lon);
        lat = Number(lat);
        if (!Number.isFinite(lon) || !Number.isFinite(lat)) {
            return null;
        }
        return ol.proj.fromLonLat([lon, lat]);
    }

    function setMapStatus($root, message, isError) {
        $root.find('[data-lst-map-status]')
            .toggleClass('lsttraining-map-status--error', !!isError)
            .text(message || '')
            .prop('hidden', !message);
    }

    function getWorkspace($root) {
        return $root.find('.lsttraining-dispatch__workspace').get(0);
    }

    function layoutTotalWidth(workspace) {
        return Math.max(0, workspace.getBoundingClientRect().width - (simLayout.gap * 2));
    }

    function layoutTotalHeight(workspace) {
        return Math.max(0, workspace.getBoundingClientRect().height - simLayout.gap);
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function saveSimLayout() {
        if (!simLayout.values) {
            return;
        }

        try {
            window.localStorage.setItem(simLayout.storageKey, JSON.stringify(simLayout.values));
        } catch (error) {
            // Browser storage is optional for the simulation layout.
        }
    }

    function loadSimLayout(workspace) {
        var totalWidth = layoutTotalWidth(workspace);
        var totalHeight = layoutTotalHeight(workspace);
        var defaults = {
            map: Math.max(simLayout.minMap, totalWidth - 420 - 380),
            vehicles: 420,
            incidents: 380,
            top: Math.max(simLayout.minTop, Math.round(totalHeight * 0.52))
        };
        var stored = null;

        try {
            stored = JSON.parse(window.localStorage.getItem(simLayout.storageKey) || 'null');
        } catch (error) {
            stored = null;
        }

        simLayout.values = $.extend({}, defaults, stored || {});
        normalizeSimLayout(workspace);
    }

    function normalizeSimLayout(workspace) {
        var totalWidth = layoutTotalWidth(workspace);
        var totalHeight = layoutTotalHeight(workspace);
        var values = simLayout.values || {};

        values.incidents = clamp(Number(values.incidents) || 380, simLayout.minIncidents, totalWidth - simLayout.minMap - simLayout.minVehicles);
        values.map = clamp(Number(values.map) || (totalWidth - values.incidents - 420), simLayout.minMap, totalWidth - values.incidents - simLayout.minVehicles);
        values.vehicles = Math.max(simLayout.minVehicles, totalWidth - values.map - values.incidents);
        values.top = clamp(Number(values.top) || Math.round(totalHeight * 0.52), simLayout.minTop, totalHeight - simLayout.minBottom);
        simLayout.values = values;
    }

    function activePopoutKeys() {
        return Object.keys(simRuntime.popouts).filter(function (key) {
            return !!popoutEntry(key);
        });
    }

    function isPanelPoppedOut(key) {
        return activePopoutKeys().indexOf(key) !== -1;
    }

    function expandedAreaRow(keys, columns) {
        var row;

        if (!keys.length) {
            return null;
        }

        row = keys.slice();
        while (row.length < columns) {
            if (row[0] === 'map' || row[0] === 'fms') {
                row.splice(1, 0, row[0]);
            } else {
                row.push(row[row.length - 1]);
            }
        }

        return row.slice(0, columns).join(' ');
    }

    function applyPopoutLayout($root, workspace) {
        var top = ['map', 'vehicles', 'incidents'].filter(function (key) {
            return !isPanelPoppedOut(key);
        });
        var bottom = ['fms', 'calls'].filter(function (key) {
            return !isPanelPoppedOut(key);
        });
        var rows = [];
        var columns = Math.max(top.length, bottom.length, 1);
        var columnTemplate;

        if (top.length) {
            rows.push(expandedAreaRow(top, columns));
        }
        if (bottom.length) {
            rows.push(expandedAreaRow(bottom, columns));
        }
        if (!rows.length) {
            rows.push('.' + (columns > 1 ? ' .' : ''));
        }

        if (top.indexOf('map') !== -1 && columns > 1) {
            columnTemplate = 'minmax(420px, 1.7fr) repeat(' + (columns - 1) + ', minmax(260px, 1fr))';
        } else {
            columnTemplate = 'repeat(' + columns + ', minmax(260px, 1fr))';
        }

        workspace.classList.add('has-popouts');
        workspace.style.gridTemplateAreas = rows.map(function (row) {
            return '"' + row + '"';
        }).join(' ');
        workspace.style.gridTemplateColumns = columnTemplate;
        workspace.style.gridTemplateRows = rows.length > 1
            ? 'minmax(260px, 1fr) minmax(180px, 0.65fr)'
            : 'minmax(0, 1fr)';

        if (dispatchMap.map) {
            window.setTimeout(function () {
                dispatchMap.map.updateSize();
            }, 0);
        }
    }

    function applySimLayout($root) {
        var workspace = getWorkspace($root);
        var values;

        if (!workspace) {
            return;
        }

        if (activePopoutKeys().length) {
            applyPopoutLayout($root, workspace);
            return;
        }

        workspace.classList.remove('has-popouts');
        workspace.style.gridTemplateAreas = '"map vehicles incidents" "fms fms calls"';

        if (window.matchMedia('(max-width: 980px)').matches) {
            workspace.style.gridTemplateColumns = '';
            workspace.style.gridTemplateRows = '';
            workspace.style.gridTemplateAreas = '';
            return;
        }

        if (!simLayout.values) {
            loadSimLayout(workspace);
        }

        normalizeSimLayout(workspace);
        values = simLayout.values;

        workspace.style.gridTemplateColumns = values.map + 'px ' + values.vehicles + 'px ' + values.incidents + 'px';
        workspace.style.gridTemplateRows = values.top + 'px minmax(' + simLayout.minBottom + 'px, 1fr)';
        workspace.style.setProperty('--lst-resize-map-x', values.map + (simLayout.gap / 2) + 'px');
        workspace.style.setProperty('--lst-resize-incidents-x', values.map + simLayout.gap + values.vehicles + (simLayout.gap / 2) + 'px');
        workspace.style.setProperty('--lst-resize-row-y', values.top + (simLayout.gap / 2) + 'px');

        if (dispatchMap.map) {
            window.setTimeout(function () {
                dispatchMap.map.updateSize();
            }, 0);
        }
    }

    function resetSimLayout($root) {
        var workspace = getWorkspace($root);
        if (!workspace) {
            return;
        }

        try {
            window.localStorage.removeItem(simLayout.storageKey);
        } catch (error) {
            // Browser storage is optional for the simulation layout.
        }

        simLayout.values = null;
        loadSimLayout(workspace);
        applySimLayout($root);
        setMapStatus($root, 'Layout zurückgesetzt.', false);
    }

    function initResizableLayout($root) {
        var workspace = getWorkspace($root);

        if (!workspace) {
            return;
        }

        applySimLayout($root);

        $root.on('pointerdown', '[data-lst-resize]', function (event) {
            var handle = $(this).attr('data-lst-resize');
            var rect = workspace.getBoundingClientRect();
            var totalWidth = layoutTotalWidth(workspace);
            var totalHeight = layoutTotalHeight(workspace);

            if (window.matchMedia('(max-width: 980px)').matches) {
                return;
            }

            event.preventDefault();
            workspace.classList.add('is-resizing');

            $(document).on('pointermove.lsttrainingResize', function (moveEvent) {
                var x = moveEvent.clientX - rect.left;
                var y = moveEvent.clientY - rect.top;
                var values = simLayout.values;

                if (!values) {
                    loadSimLayout(workspace);
                    values = simLayout.values;
                }

                if (handle === 'map') {
                    values.map = clamp(x - (simLayout.gap / 2), simLayout.minMap, totalWidth - values.incidents - simLayout.minVehicles);
                    values.vehicles = Math.max(simLayout.minVehicles, totalWidth - values.map - values.incidents);
                } else if (handle === 'incidents') {
                    values.incidents = clamp(totalWidth - x + (simLayout.gap / 2), simLayout.minIncidents, totalWidth - values.map - simLayout.minVehicles);
                    values.vehicles = Math.max(simLayout.minVehicles, totalWidth - values.map - values.incidents);
                } else if (handle === 'rows') {
                    values.top = clamp(y - (simLayout.gap / 2), simLayout.minTop, totalHeight - simLayout.minBottom);
                }

                applySimLayout($root);
            });

            $(document).one('pointerup.lsttrainingResize pointercancel.lsttrainingResize', function () {
                workspace.classList.remove('is-resizing');
                $(document).off('pointermove.lsttrainingResize');
                saveSimLayout();
            });
        });

        $(window).on('resize.lsttrainingLayout', function () {
            applySimLayout($root);
        });
    }

    function frontendCssUrl() {
        var href = $('#lsttraining-frontend-css').attr('href');
        if (href) {
            return href;
        }

        var link = Array.prototype.find.call(document.querySelectorAll('link[rel="stylesheet"]'), function (item) {
            return item.href && item.href.indexOf('frontend.css') !== -1;
        });
        return link ? link.href : '';
    }

    function openLayersCssUrl() {
        var href = $('#lst-openlayers-css-css').attr('href');
        if (href) {
            return href;
        }

        var link = Array.prototype.find.call(document.querySelectorAll('link[rel="stylesheet"]'), function (item) {
            return item.href && item.href.indexOf('openlayers/ol.css') !== -1;
        });
        return link ? link.href : '';
    }

    function popoutWindowHtml(title, kind) {
        var css = frontendCssUrl();
        var olCss = openLayersCssUrl();
        var cssLink = css ? '<link rel="stylesheet" href="' + esc(css) + '">' : '';
        var olCssLink = olCss ? '<link rel="stylesheet" href="' + esc(olCss) + '">' : '';
        var mapContent = kind === 'map'
            ? '<main class="lsttraining-popout-mapwrap"><div class="lsttraining-dispatch-map" data-lst-popout-map></div></main>'
            : '<main class="lsttraining-popout-content" data-lst-popout-content></main>';

        return '<!doctype html><html><head><meta charset="utf-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1">' +
            '<title>' + esc(title) + '</title>' +
            olCssLink +
            cssLink +
            '</head><body class="lsttraining-sim-body lsttraining-popout-body">' +
            '<div class="lsttraining-sim-shell lsttraining-popout-shell" data-lst-popout-shell="' + esc(kind) + '">' +
            '<header class="lsttraining-popout-toolbar">' +
            '<strong>' + esc(title) + '</strong>' +
            '<button type="button" class="lsttraining-mini-btn" data-lst-dock-popout>Andocken</button>' +
            '</header>' +
            mapContent +
            '</div>' +
            '</body></html>';
    }

    function popoutFeatures(config) {
        var width = config.width || 720;
        var height = config.height || 520;
        var left = Math.max(0, Math.round((window.screenX || window.screenLeft || 0) + 80));
        var top = Math.max(0, Math.round((window.screenY || window.screenTop || 0) + 80));

        return [
            'popup=yes',
            'resizable=yes',
            'scrollbars=yes',
            'menubar=no',
            'toolbar=no',
            'location=no',
            'status=no',
            'width=' + width,
            'height=' + height,
            'left=' + left,
            'top=' + top
        ].join(',');
    }

    function popoutEntry(key) {
        var entry = simRuntime.popouts[key];
        if (!entry || !entry.win || entry.win.closed) {
            return null;
        }
        return entry;
    }

    function bindPopoutEvents($root, key) {
        var entry = popoutEntry(key);
        if (!entry) {
            return;
        }

        var $doc = $(entry.win.document);
        $doc.off('.lsttrainingPopout')
            .on('click.lsttrainingPopout', '[data-lst-dock-popout]', function () {
                dockPopout($root, key);
            })
            .on('click.lsttrainingPopout', '[data-lst-focus-vehicle]', function () {
                selectVehicle($root, $(this).attr('data-lst-focus-vehicle'), true);
            })
            .on('click.lsttrainingPopout', '[data-lst-focus-incident]', function () {
                selectIncident($root, $(this).attr('data-lst-focus-incident'), true);
            })
            .on('click.lsttrainingPopout', '[data-lst-route-incident]', function () {
                var incidentId = $(this).attr('data-lst-route-incident');
                dispatchMap.selectedIncidentId = String(incidentId);
                clearRoute();
                renderSnapshot($root, dispatchMap.lastSnapshot || {});
            })
            .on('click.lsttrainingPopout', '[data-lst-accept-call]', function () {
                acceptCall($root, $(this).attr('data-lst-accept-call'));
            })
            .on('click.lsttrainingPopout', '[data-lst-ack-unit-report]', function () {
                acknowledgeUnitReport($root, $(this).attr('data-lst-ack-unit-report'));
            })
            .on('click.lsttrainingPopout', '[data-lst-open-unit-report]', function () {
                openUnitReport($root, $(this).attr('data-lst-open-unit-report'));
            })
            .on('click.lsttrainingPopout', '[data-lst-open-dispatch]', function () {
                openDispatchModal($root, $(this).attr('data-lst-open-dispatch'));
            })
            .on('click.lsttrainingPopout', '[data-lst-no-dispatch]', function () {
                closeCallWithoutDispatch($root, $(this).attr('data-lst-no-dispatch'));
            })
            .on('click.lsttrainingPopout', '[data-lst-alarm-vehicle]', function () {
                alarmVehicle($root, $(this).attr('data-lst-alarm-vehicle'), $(this).attr('data-lst-alarm-incident'));
            })
            .on('click.lsttrainingPopout', '[data-lst-close-station-info]', function () {
                hideStationInfo($root);
            });

        entry.win.onbeforeunload = function () {
            dockPopout($root, key, true);
        };
    }

    function panelCloneHtml($root, key) {
        var config = popoutConfig[key];
        var $clone = $root.find(config.selector).first().clone();
        $clone.removeClass('is-popped-out');
        $clone.find('[data-lst-popout]').remove();
        $clone.find('.lsttraining-popout-placeholder').remove();
        return $clone.prop('outerHTML') || '';
    }

    function syncPopout($root, key) {
        var entry = popoutEntry(key);
        if (!entry || entry.type === 'map') {
            return;
        }

        var target = entry.win.document.querySelector('[data-lst-popout-content]');
        if (target) {
            target.innerHTML = panelCloneHtml($root, key);
        }
        bindPopoutEvents($root, key);
    }

    function syncAllPopouts($root) {
        Object.keys(simRuntime.popouts).forEach(function (key) {
            syncPopout($root, key);
        });
    }

    function dockPopout($root, key, fromUnload) {
        var entry = simRuntime.popouts[key];
        var config = popoutConfig[key];
        if (!entry || !config) {
            return;
        }

        if (config.type === 'map' && dispatchMap.map && dispatchMap.homeTarget) {
            dispatchMap.map.setTarget(dispatchMap.homeTarget);
            window.setTimeout(function () {
                dispatchMap.map.updateSize();
            }, fromUnload ? 80 : 50);
        }

        $root.find(config.selector).removeClass('is-popped-out');
        delete simRuntime.popouts[key];
        applySimLayout($root);

        if (!fromUnload && entry.win && !entry.win.closed) {
            entry.win.close();
        }
    }

    function openPopout($root, key) {
        var config = popoutConfig[key];
        if (!config) {
            return;
        }

        var existing = popoutEntry(key);
        if (existing) {
            existing.win.focus();
            return;
        }

        var win = window.open('', 'lsttraining_' + key, popoutFeatures(config));
        if (!win) {
            showMessage($root, 'error', 'Popout wurde vom Browser blockiert. Bitte Popups für diese Seite erlauben.');
            return;
        }

        win.document.open();
        win.document.write(popoutWindowHtml(config.title, key));
        win.document.close();

        simRuntime.popouts[key] = {
            win: win,
            type: config.type || 'panel'
        };

        $root.find(config.selector).addClass('is-popped-out');
        bindPopoutEvents($root, key);
        applySimLayout($root);

        if (config.type === 'map') {
            ensureDispatchMap($root);
            var target = win.document.querySelector('[data-lst-popout-map]');
            if (dispatchMap.map && target) {
                dispatchMap.map.setTarget(target);
                window.setTimeout(function () {
                    dispatchMap.map.updateSize();
                }, 80);
            }
            return;
        }

        syncPopout($root, key);
    }

    function initPopouts($root) {
        Object.keys(popoutConfig).forEach(function (key) {
            var config = popoutConfig[key];
            var $target = $root.find(config.selector).first();
            if (!$target.length || $target.find('[data-lst-popout="' + key + '"]').length) {
                return;
            }

            var $button = $('<button>', {
                type: 'button',
                class: 'lsttraining-mini-btn lsttraining-popout-btn',
                'data-lst-popout': key,
                text: 'Ausgliedern'
            });

            if (config.type === 'map') {
                $target.append($button);
            } else {
                $target.find('.lsttraining-panel-head').first().append($button);
            }
        });
    }

    function ensureDispatchMap($root) {
        var target = $root.find('[data-lst-dispatch-map]').get(0);
        if (!target || typeof ol === 'undefined') {
            return null;
        }

        if (dispatchMap.map) {
            return dispatchMap.map;
        }

        dispatchMap.stationSource = new ol.source.Vector();
        dispatchMap.vehicleSource = new ol.source.Vector();
        dispatchMap.incidentSource = new ol.source.Vector();
        dispatchMap.routeSource = new ol.source.Vector();
        dispatchMap.homeTarget = target;

        dispatchMap.map = new ol.Map({
            target: target,
            layers: [
                new ol.layer.Tile({
                    source: new ol.source.OSM()
                }),
                new ol.layer.Vector({
                    source: dispatchMap.routeSource,
                    style: new ol.style.Style({
                        stroke: new ol.style.Stroke({
                            color: '#18c8ff',
                            width: 5
                        })
                    })
                }),
                new ol.layer.Vector({
                    source: dispatchMap.stationSource
                }),
                new ol.layer.Vector({
                    source: dispatchMap.incidentSource
                }),
                new ol.layer.Vector({
                    source: dispatchMap.vehicleSource
                })
            ],
            view: new ol.View({
                center: ol.proj.fromLonLat([13.0624, 52.4009]),
                zoom: 11
            })
        });

        dispatchMap.map.on('singleclick', function (event) {
            var hit = dispatchMap.map.forEachFeatureAtPixel(event.pixel, function (feature) {
                var type = feature.get('type');
                if (type === 'vehicle') {
                    hideStationInfo($root);
                    selectVehicle($root, feature.get('id'), true);
                    return true;
                }
                if (type === 'incident') {
                    hideStationInfo($root);
                    selectIncident($root, feature.get('id'), true);
                    return true;
                }
                if (type === 'station') {
                    showStationInfo($root, feature.get('id'));
                    return true;
                }
                return false;
            });
            if (!hit) {
                hideStationInfo($root);
            }
        });

        window.setTimeout(function () {
            dispatchMap.map.updateSize();
        }, 50);

        return dispatchMap.map;
    }

    function vehicleStyle(vehicle, selected) {
        var imageUrl = vehicle.image_url || '';
        var mode = dispatchMap.markerMode || 'marker';
        if (mode === 'image' && imageUrl && supportsMapImage(imageUrl)) {
            var isPoliceSupport = vehicle && vehicle.support_type === 'police';
            return new ol.style.Style({
                image: new ol.style.Icon({
                    src: imageUrl,
                    scale: selected ? 0.12 : 0.09,
                    anchor: isPoliceSupport ? [0.5, 0.68] : [0.5, 0.85],
                    anchorXUnits: 'fraction',
                    anchorYUnits: 'fraction',
                    crossOrigin: 'anonymous'
                }),
                text: new ol.style.Text({
                    text: vehicle.rufname || '',
                    offsetY: isPoliceSupport ? 10 : 18,
                    textAlign: 'center',
                    textBaseline: 'top',
                    font: '700 11px Arial, sans-serif',
                    fill: new ol.style.Fill({ color: '#ffffff' }),
                    stroke: new ol.style.Stroke({ color: '#06111c', width: 4 })
                })
            });
        }

        var color = vehicle.fms_status === '1' ? '#3f7d34' : (vehicle.fms_status === '5' ? '#ef3c35' : '#2f8dd8');
        if (mode === 'tactical') {
            return new ol.style.Style({
                image: new ol.style.RegularShape({
                    points: 4,
                    radius: selected ? 16 : 13,
                    angle: Math.PI / 4,
                    fill: new ol.style.Fill({ color: '#ffffff' }),
                    stroke: new ol.style.Stroke({ color: selected ? '#ffd21f' : color, width: 4 })
                }),
                text: new ol.style.Text({
                    text: tacticalVehicleLetter(vehicle),
                    font: '800 10px Arial, sans-serif',
                    fill: new ol.style.Fill({ color: '#111111' }),
                    stroke: new ol.style.Stroke({ color: '#ffffff', width: 2 }),
                    offsetY: 0
                })
            });
        }

        return new ol.style.Style({
            image: new ol.style.Circle({
                radius: selected ? 11 : 9,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: selected ? '#ffd21f' : '#ffffff', width: 3 })
            }),
            text: new ol.style.Text({
                text: vehicle.rufname || '',
                offsetY: -22,
                font: '700 12px Arial, sans-serif',
                fill: new ol.style.Fill({ color: '#ffffff' }),
                stroke: new ol.style.Stroke({ color: '#06111c', width: 4 })
            })
        });
    }

    function stationStyle(station) {
        var color = stationColor(station.kind);
        return new ol.style.Style({
            image: new ol.style.RegularShape({
                points: 4,
                radius: 10,
                angle: Math.PI / 4,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: '#ffffff', width: 2 })
            })
        });
    }

    function incidentStyle(incident, selected) {
        var color = incident.state === 'active' ? '#ffd21f' : '#ef3c35';
        return new ol.style.Style({
            image: new ol.style.RegularShape({
                points: 3,
                radius: selected ? 16 : 13,
                angle: 0,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: '#ffffff', width: 3 })
            }),
            text: new ol.style.Text({
                text: incident.einsatztyp || 'Einsatz',
                offsetY: -28,
                font: '800 12px Arial, sans-serif',
                fill: new ol.style.Fill({ color: '#ffffff' }),
                stroke: new ol.style.Stroke({ color: '#06111c', width: 4 })
            })
        });
    }

    function vehicleKey(vehicle) {
        return String(vehicle && (vehicle.fahrzeug_id || vehicle.status_id) || '');
    }

    function vehicleLonLat(vehicle) {
        var lon = Number(vehicle && vehicle.longitude);
        var lat = Number(vehicle && vehicle.latitude);
        return Number.isFinite(lon) && Number.isFinite(lat) ? [lon, lat] : null;
    }

    function prepareVehicleAnimation(previous, snapshot) {
        var previousVehicles = {};
        (previous && previous.vehicles ? previous.vehicles : []).forEach(function (vehicle) {
            previousVehicles[vehicleKey(vehicle)] = vehicleLonLat(vehicle);
        });

        (snapshot && snapshot.vehicles ? snapshot.vehicles : []).forEach(function (vehicle) {
            var key = vehicleKey(vehicle);
            var end = vehicleLonLat(vehicle);
            var oldAnimation = dispatchMap.vehicleAnimation[key];
            var start = oldAnimation && oldAnimation.current ? oldAnimation.current : previousVehicles[key];
            if (!key || !end) {
                return;
            }
            if (!start || Math.abs(start[0] - end[0]) < 0.000001 && Math.abs(start[1] - end[1]) < 0.000001) {
                dispatchMap.vehicleAnimation[key] = {
                    current: end,
                    start: end,
                    end: end,
                    started: Date.now(),
                    duration: 0
                };
                return;
            }
            dispatchMap.vehicleAnimation[key] = {
                current: start,
                start: start,
                end: end,
                started: Date.now(),
                duration: 11000
            };
        });
    }

    function animatedVehicleCoordinate(vehicle) {
        var key = vehicleKey(vehicle);
        var animation = dispatchMap.vehicleAnimation[key];
        if (animation && animation.current) {
            return animation.current;
        }
        return vehicleLonLat(vehicle);
    }

    function startVehicleAnimationLoop() {
        if (simRuntime.animationFrame || !dispatchMap.map || !dispatchMap.vehicleSource) {
            return;
        }

        var step = function () {
            var now = Date.now();
            var active = false;
            Object.keys(dispatchMap.vehicleAnimation).forEach(function (key) {
                var animation = dispatchMap.vehicleAnimation[key];
                if (!animation || !animation.end) {
                    return;
                }
                if (!animation.duration) {
                    animation.current = animation.end;
                    return;
                }
                var progress = Math.min(1, Math.max(0, (now - animation.started) / animation.duration));
                animation.current = [
                    animation.start[0] + ((animation.end[0] - animation.start[0]) * progress),
                    animation.start[1] + ((animation.end[1] - animation.start[1]) * progress)
                ];
                if (progress < 1) {
                    active = true;
                }
            });

            dispatchMap.vehicleSource.getFeatures().forEach(function (feature) {
                var vehicle = feature.get('vehicle');
                var lonLat = animatedVehicleCoordinate(vehicle);
                if (lonLat) {
                    feature.getGeometry().setCoordinates(ol.proj.fromLonLat(lonLat));
                }
            });

            if (active && !simRuntime.stopped) {
                simRuntime.animationFrame = window.requestAnimationFrame(step);
            } else {
                simRuntime.animationFrame = null;
            }
        };

        simRuntime.animationFrame = window.requestAnimationFrame(step);
    }

    function renderMap($root, snapshot) {
        var map = ensureDispatchMap($root);
        if (!map) {
            return;
        }

        var extent = ol.extent.createEmpty();
        dispatchMap.stationSource.clear();
        dispatchMap.vehicleSource.clear();
        dispatchMap.incidentSource.clear();

        bootstrapStations().forEach(function (station) {
            var coords = dispatchPoint(station.longitude, station.latitude);
            if (!coords) {
                return;
            }

            var feature = new ol.Feature({
                geometry: new ol.geom.Point(coords),
                type: 'station',
                id: String(station.id || ''),
                station: station,
                label: [station.name, station.typ].filter(Boolean).join(' - ')
            });
            feature.setStyle(stationStyle(station));
            dispatchMap.stationSource.addFeature(feature);
            ol.extent.extend(extent, feature.getGeometry().getExtent());
        });

        (snapshot.vehicles || []).forEach(function (vehicle) {
            var lonLat = animatedVehicleCoordinate(vehicle);
            var coords = lonLat ? dispatchPoint(lonLat[0], lonLat[1]) : null;
            if (!coords || !vehicleVisibleOnMap(vehicle)) {
                return;
            }

            var feature = new ol.Feature({
                geometry: new ol.geom.Point(coords),
                type: 'vehicle',
                id: String(vehicle.fahrzeug_id || vehicle.status_id || ''),
                vehicle: vehicle
            });
            feature.setStyle(vehicleStyle(vehicle, vehicleKey(vehicle) === String(dispatchMap.selectedVehicleId)));
            dispatchMap.vehicleSource.addFeature(feature);
            ol.extent.extend(extent, feature.getGeometry().getExtent());
        });

        (snapshot.incidents || []).forEach(function (incident) {
            var coords = dispatchPoint(incident.longitude, incident.latitude);
            if (!coords || !incidentAccepted(incident)) {
                return;
            }

            var feature = new ol.Feature({
                geometry: new ol.geom.Point(coords),
                type: 'incident',
                id: String(incident.id || '')
            });
            feature.setStyle(incidentStyle(incident, String(incident.id) === String(dispatchMap.selectedIncidentId)));
            dispatchMap.incidentSource.addFeature(feature);
            ol.extent.extend(extent, feature.getGeometry().getExtent());
        });

        if (!ol.extent.isEmpty(extent) && !dispatchMap.hasFit) {
            map.getView().fit(extent, {
                padding: [60, 60, 60, 60],
                maxZoom: 14,
                duration: 250
            });
            dispatchMap.hasFit = true;
            setMapStatus($root, '', false);
            startVehicleAnimationLoop();
            return;
        }

        var instance = simulationInstance();
        var center = dispatchPoint(instance.leitstelle_longitude, instance.leitstelle_latitude);
        if (center && !dispatchMap.hasFit) {
            map.getView().setCenter(center);
            map.getView().setZoom(11);
            dispatchMap.hasFit = true;
            setMapStatus($root, 'Noch keine Fahrzeug- oder Einsatzkoordinaten vorhanden.', false);
        } else {
            setMapStatus($root, ol.extent.isEmpty(extent) ? 'Keine Kartenkoordinaten vorhanden.' : '', ol.extent.isEmpty(extent));
        }

        startVehicleAnimationLoop();
    }

    function renderVehicles($root, vehicles) {
        var $list = $root.find('[data-lst-vehicles]');
        $root.find('[data-lst-vehicle-count]').text(String((vehicles || []).length));

        if (!vehicles || !vehicles.length) {
            $list.html($('<p>', {
                class: 'lsttraining-muted',
                text: 'Keine Fahrzeuge in dieser Instanz.'
            }));
            return;
        }

        $list.html(vehicles.map(function (vehicle) {
            var id = String(vehicle.fahrzeug_id || vehicle.status_id || '');
            var selected = id === String(dispatchMap.selectedVehicleId);
            var mapAction = vehicleVisibleOnMap(vehicle) && vehicleHasLiveMarker(vehicle)
                ? '<button type="button" class="lsttraining-mini-btn" data-lst-focus-vehicle="' + esc(id) + '">Karte</button>'
                : '<span class="lsttraining-mini-note">kein Marker</span>';
            var image = vehicle.image_url
                ? '<img src="' + esc(vehicle.image_url) + '" alt="">'
                : '<span class="lsttraining-vehicle-fallback">' + esc((vehicle.fahrzeugtyp || '?').slice(0, 3)) + '</span>';
            return '<article class="lsttraining-vehicle-card' + (selected ? ' is-selected' : '') + '" data-vehicle-id="' + esc(id) + '">' +
                '<div class="lsttraining-vehicle-card__media">' + image + '</div>' +
                '<div class="lsttraining-vehicle-card__body">' +
                    '<strong>' + esc(vehicle.rufname || ('Fahrzeug ' + id)) + '</strong>' +
                    '<span>' + esc(vehicle.fahrzeugtyp || 'Fahrzeug') + '</span>' +
                    '<small>' + esc(vehicle.wache_name || '') + '</small>' +
                '</div>' +
                '<span class="lsttraining-fms-badge lsttraining-fms-badge--s' + esc(vehicle.fms_status || '2') + '">' + esc(fmsLabel(vehicle.fms_status)) + '</span>' +
                mapAction +
            '</article>';
        }).join(''));
    }

    function renderDispatchIncidents($root, incidents) {
        var $list = $root.find('[data-lst-einsaetze]');
        var accepted = (incidents || []).filter(incidentAccepted);
        var prepared = accepted.filter(incidentPrepared);
        var ringing = (incidents || []).filter(function (incident) {
            return !incidentAccepted(incident);
        });
        var pending = accepted.filter(function (incident) {
            return !incidentPrepared(incident);
        });
        var selectedIncident;

        $root.find('[data-lst-incident-count]').text(String(prepared.length));

        if (!prepared.length) {
            $list.html($('<p>', {
                class: 'lsttraining-muted',
                text: pending.length
                    ? 'Angenommene Anrufe im Anruferverlauf zu einem Einsatz erstellen.'
                    : ringing.length
                    ? 'Eingehender Notruf vorhanden. Bitte zuerst im Anruferverlauf annehmen.'
                    : lsttrainingFrontend.texts.noEinsaetze
            }));
            return;
        }

        selectedIncident = prepared.find(function (incident) {
            return String(incident.id) === String(dispatchMap.selectedIncidentId);
        }) || prepared[0];
        dispatchMap.selectedIncidentId = String(selectedIncident.id);

        $list.html(prepared.map(function (incident) {
            var selected = String(incident.id) === String(dispatchMap.selectedIncidentId);
            var place = incident.display_address || dispatchMeta(incident, 'generated_address', '') || incident.poi_name_snapshot || '';
            var assigned = assignedUnitsHtml(incident.id, true);
            var feedback = Array.isArray(incident.feedback) && incident.feedback.length
                ? '<div class="lsttraining-assigned-units lsttraining-assigned-units--compact">' + incident.feedback.slice(0, 3).map(function (line) {
                    return '<span class="lsttraining-assigned-unit"><em>' + esc(line) + '</em></span>';
                }).join('') + '</div>'
                : '';
            var title = incident.title || ((incident.einsatzart || '') + ' - ' + (incident.einsatztyp || 'Einsatz'));
            return '<article class="lsttraining-einsatz-card' + (selected ? ' is-selected' : '') + '" data-einsatz-id="' + esc(incident.id) + '">' +
                '<div class="lsttraining-open-card__status"></div>' +
                '<strong>' + esc(title) + '</strong>' +
            '<p>' + esc(incident.lagemeldung || '') + '</p>' +
            '<small>' + esc(place) + '</small>' +
            requiredResourcesHtml(incident, true) +
            patientsHtml(incident, true) +
            assigned +
            feedback +
            '<div class="lsttraining-card-actions">' +
                    '<button type="button" class="lsttraining-mini-btn" data-lst-focus-incident="' + esc(incident.id) + '">Auf Karte zeigen</button>' +
                    '<button type="button" class="lsttraining-mini-btn" data-lst-open-dispatch="' + esc(incident.id) + '">Einsatz bearbeiten</button>' +
                '</div>' +
            '</article>';
        }).join(''));
    }

    function pendingUnitReportsHtml(incident) {
        var reports = incident && Array.isArray(incident.pending_unit_reports) ? incident.pending_unit_reports : [];
        if (!reports.length) {
            return '';
        }

        return '<div class="lsttraining-unit-reports">' + reports.map(function (report) {
            var label = report.rufname ? report.rufname + ': S5' : 'S5 Rückmeldung';
            return '<div class="lsttraining-unit-report" data-lst-unit-report="' + esc(report.event_id) + '">' +
                '<strong>' + esc(label) + '</strong>' +
                '<p>' + esc(report.text || 'Lagemeldung wartet auf Bestätigung.') + '</p>' +
                '<button type="button" class="lsttraining-mini-btn" data-lst-ack-unit-report="' + esc(report.event_id) + '">Lagemeldung bestätigen</button>' +
            '</div>';
        }).join('') + '</div>';
    }

    var resourceClassLabels = {
        rettungswagen: 'Rettungswagen',
        krankentransport: 'Krankentransportwagen',
        notarzt: 'Notarztmittel',
        loeschfahrzeug: 'Löschfahrzeug',
        hubrettung: 'Hubrettungsfahrzeug',
        ruestung: 'Rüst-/Hilfeleistungsfahrzeug',
        fuehrung: 'Führungsfahrzeug',
        logistik: 'Logistik',
        gefahrgut: 'Gefahrgut',
        atemschutz_messung: 'Atemschutz/Messung',
        san_betreuung: 'Sanitäts-/Betreuungskomponente',
        thw_bergung: 'THW-Bergung',
        thw_fuehrung: 'THW-Führung',
        thw_logistik: 'THW-Logistik',
        sonderkomponente: 'Sonderkomponente'
    };

    function resourceClassLabel(type) {
        return resourceClassLabels[String(type || '')] || String(type || 'Fahrzeug');
    }

    function incidentResourceStatus(incident) {
        if (incident && Array.isArray(incident.resource_status)) {
            return incident.resource_status;
        }
        if (incident && Array.isArray(incident.required_resources)) {
            return incident.required_resources.map(function (row) {
                return {
                    type: row.type || '',
                    label: row.label || resourceClassLabel(row.type),
                    needed: Number(row.count || row.needed || 1) || 1,
                    assigned: 0,
                    missing: Number(row.count || row.needed || 1) || 1
                };
            });
        }
        return [];
    }

    function missingResourceTypes(incident) {
        return incidentResourceStatus(incident).filter(function (row) {
            return Number(row.missing || 0) > 0;
        }).map(function (row) {
            return String(row.type || '');
        }).filter(Boolean);
    }

    function vehicleTypeGroup(vehicle) {
        var cls = String(vehicle && vehicle.resource_class || '');
        if (cls.indexOf('thw_') === 0) return 'thw';
        return cls || 'other';
    }

    function vehicleMatchesIncidentNeed(vehicle, incident) {
        var cls = String(vehicle && vehicle.resource_class || '');
        var missing = missingResourceTypes(incident);
        if (cls === '') return false;
        if (missing.indexOf(cls) !== -1) return true;
        return cls === 'rettungswagen' && missing.indexOf('krankentransport') !== -1;
    }

    function requiredResourcesHtml(incident, compact) {
        var rows = incidentResourceStatus(incident);
        if (!rows.length) {
            return compact ? '' : '<div class="lsttraining-required-resources lsttraining-required-resources--empty">Kein Fahrzeugbedarf hinterlegt.</div>';
        }

        return '<div class="lsttraining-required-resources' + (compact ? ' lsttraining-required-resources--compact' : '') + '">' +
            (compact ? '' : '<strong>Benötigte Fahrzeugklassen</strong>') +
            rows.map(function (row) {
                var needed = Number(row.needed || row.count || 1) || 1;
                var assigned = Number(row.assigned || 0) || 0;
                var missing = Math.max(0, Number(row.missing != null ? row.missing : needed - assigned) || 0);
                return '<span class="lsttraining-resource-chip' + (missing > 0 ? ' is-missing' : ' is-ok') + '">' +
                    '<em>' + esc(needed + 'x ' + (row.label || resourceClassLabel(row.type))) + '</em>' +
                    '<b>' + esc(assigned + '/' + needed) + '</b>' +
                '</span>';
            }).join('') +
        '</div>';
    }

    function triageLabel(value) {
        var labels = {
            I: 'SK I',
            II: 'SK II',
            III: 'SK III',
            IV: 'SK IV',
            V: 'SK V'
        };
        return labels[String(value || '').toUpperCase()] || 'SK ?';
    }

    function patientNeedText(patient) {
        var needs = [];
        if (patient.requires_notarzt) needs.push('Notarztmittel');
        if (patient.requires_rtw) needs.push('RTW');
        if (patient.requires_ktw) needs.push('KTW/RTW');
        return needs.length ? needs.join(', ') : 'kein Transportbedarf';
    }

    function patientsHtml(incident, compact) {
        var patients = incident && Array.isArray(incident.patients) ? incident.patients : [];
        if (!patients.length) {
            return '';
        }
        return '<div class="lsttraining-patient-status' + (compact ? ' lsttraining-patient-status--compact' : '') + '">' +
            (compact ? '' : '<strong>Patientenlage</strong>') +
            patients.map(function (patient) {
                var progress = Math.max(0, Math.min(100, Number(patient.care_progress_percent || 0) || 0));
                var triage = String(patient.triage_category || '').toUpperCase();
                var status = patient.status_label || (progress <= 0 ? 'Verstorben' : (patient.transport_ready ? 'Transportbereit' : 'In Versorgung'));
                var injury = patient.injury_summary || patient.label || 'Patient';
                return '<div class="lsttraining-patient-row is-triage-' + esc(triage || 'unknown') + '">' +
                    '<div class="lsttraining-patient-row__head">' +
                        '<strong>' + esc(patient.label || 'Patient') + '</strong>' +
                        '<span>' + esc(triageLabel(triage)) + '</span>' +
                        '<em>' + esc(progress + ' %') + '</em>' +
                    '</div>' +
                    '<div class="lsttraining-patient-bar"><i style="width:' + esc(progress) + '%"></i></div>' +
                    '<small>' + esc(injury) + ' · ' + esc(patientNeedText(patient)) + ' · ' + esc(status) + '</small>' +
                '</div>';
            }).join('') +
        '</div>';
    }

    function dispatchMeta(incident, key, fallback) {
        return incident && incident.meta && incident.meta[key] != null ? incident.meta[key] : fallback;
    }

    function dispatchZusatzText(incident) {
        var text = String(dispatchMeta(incident, 'zusatz_text', '') || '').trim();
        var lagemeldung = String(incident && incident.lagemeldung || '').trim();
        var callerText = String(incident && incident.caller_text || '').trim();
        if (!text || text === lagemeldung || text === callerText) {
            return '';
        }
        return text;
    }

    function openDispatchModal($root, incidentId) {
        var incident = findIncident(incidentId);
        var $modal = $root.find('[data-lst-dispatch-modal]');
        var vehicles = snapshotVehicleList().slice();
        var place;

        if (!$modal.length || !incident) {
            return;
        }

        place = incident.display_address || dispatchMeta(incident, 'generated_address', '') || incident.poi_name_snapshot || 'Einsatzort';
        vehicles.sort(function (a, b) {
            var matchA = vehicleMatchesIncidentNeed(a, incident) ? 0 : 1;
            var matchB = vehicleMatchesIncidentNeed(b, incident) ? 0 : 1;
            if (matchA !== matchB) {
                return matchA - matchB;
            }
            return vehicleDistanceToIncident(a, incident) - vehicleDistanceToIncident(b, incident);
        });

        $modal.html(
            '<div class="lsttraining-dispatch-modal__dialog" role="dialog" aria-modal="true" aria-label="' + (incidentPrepared(incident) ? 'Einsatz bearbeiten' : 'Neuen Einsatz erstellen') + '">' +
                '<header><strong>' + (incidentPrepared(incident) ? 'Einsatz bearbeiten' : 'Neuen Einsatz erstellen') + '</strong><button type="button" data-lst-close-dispatch-modal aria-label="Schließen">×</button></header>' +
                '<div class="lsttraining-dispatch-modal__body" data-lst-dispatch-form data-incident-id="' + esc(incident.id) + '">' +
                    '<p><strong>Einsatzort:</strong> ' + esc(place) + '</p>' +
                    '<label class="lsttraining-check-line"><input type="checkbox" name="signal_allowed" value="1" ' + (dispatchMeta(incident, 'signal_allowed', false) ? 'checked' : '') + '> Signalfahrt</label>' +
                    '<label><span>Einsatzcode:</span><input type="text" name="einsatzcode" value="' + esc(dispatchMeta(incident, 'einsatzcode', incident.einsatztyp || '')) + '"></label>' +
                    '<label><span>Ausrückorder:</span><input type="text" name="ausrueckorder" value="' + esc(dispatchMeta(incident, 'ausrueckorder', '')) + '"></label>' +
                    '<label><span>Einsatzkategorie:</span><input type="text" name="einsatzkategorie" value="' + esc(dispatchMeta(incident, 'einsatzkategorie', incident.einsatzart || '')) + '"></label>' +
                    '<label><span>Zusatz / Freitext:</span><textarea name="zusatz_text" rows="3">' + esc(dispatchZusatzText(incident)) + '</textarea></label>' +
                    '<label><span>Abholzeit:</span><input type="time" name="abholzeit" value="' + esc(dispatchMeta(incident, 'abholzeit', '')) + '"></label>' +
                    '<label class="lsttraining-check-line"><input type="checkbox" name="polizei_verstaendigen" value="1" ' + (dispatchMeta(incident, 'polizei_verstaendigen', false) ? 'checked' : '') + '> Polizei verständigen</label>' +
                    requiredResourcesHtml(incident, false) +
                    '<div class="lsttraining-dispatch-modal__filters">' +
                        '<strong>Zugeordnete Fahrzeuge:</strong>' +
                        '<button type="button" class="lsttraining-mini-btn is-active" data-lst-vehicle-filter="all">Egal</button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-vehicle-filter="rettungswagen">Rettungswagen</button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-vehicle-filter="krankentransport">Krankentransport</button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-vehicle-filter="notarzt">Notarztmittel</button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-vehicle-filter="loeschfahrzeug">Löschfahrzeug</button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-vehicle-filter="fuehrung">Führung</button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-vehicle-filter="thw">THW</button>' +
                    '</div>' +
                    '<div class="lsttraining-dispatch-modal__vehicles">' +
                        vehicles.slice(0, 80).map(function (vehicle) {
                            var statusId = String(vehicle.status_id || '');
                            var distance = vehicleDistanceToIncident(vehicle, incident);
                            var assigned = vehicleIsAssigned(vehicle);
                            var matchesNeed = vehicleMatchesIncidentNeed(vehicle, incident);
                            return '<label class="lsttraining-dispatch-vehicle-row' + (matchesNeed ? ' is-resource-match' : '') + '" data-vehicle-filter-row="' + esc(vehicleTypeGroup(vehicle)) + '">' +
                                '<input type="checkbox" name="alarm_status_id" value="' + esc(statusId) + '" ' + (assigned ? 'disabled' : '') + '>' +
                                '<span><strong>' + esc(vehicle.rufname || ('Fahrzeug ' + statusId)) + '</strong><small>' + esc((vehicle.resource_class_label || resourceClassLabel(vehicle.resource_class)) + (vehicle.fahrzeugtyp ? ' · ' + vehicle.fahrzeugtyp : '')) + '</small></span>' +
                                '<em>' + esc(formatDistance(distance)) + '</em>' +
                                '<span class="lsttraining-fms-badge lsttraining-fms-badge--s' + esc(vehicle.fms_status || '2') + '">' + esc(fmsLabel(vehicle.fms_status)) + '</span>' +
                            '</label>';
                        }).join('') +
                    '</div>' +
                    '<footer>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-save-dispatch> Einsatz erstellen </button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-save-dispatch-alarm> Einsatz erstellen & alarmieren </button>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-save-dispatch-alarm-support> Einsatz erstellen, alarmieren & Unterstützung anfordern </button>' +
                    '</footer>' +
                '</div>' +
            '</div>'
        ).prop('hidden', false);
    }

    function closeDispatchModal($root) {
        $root.find('[data-lst-dispatch-modal]').prop('hidden', true).empty();
    }

    function dispatchFormData($root, $form) {
        return {
            action: 'lsttraining_sim_save_dispatch',
            nonce: lsttrainingFrontend.nonce,
            instanz_id: $root.attr('data-instance-id'),
            einsatz_id: $form.attr('data-incident-id'),
            signal_allowed: $form.find('[name="signal_allowed"]').is(':checked') ? 1 : 0,
            einsatzcode: $form.find('[name="einsatzcode"]').val() || '',
            ausrueckorder: $form.find('[name="ausrueckorder"]').val() || '',
            einsatzkategorie: $form.find('[name="einsatzkategorie"]').val() || '',
            zusatz_text: $form.find('[name="zusatz_text"]').val() || '',
            abholzeit: $form.find('[name="abholzeit"]').val() || '',
            polizei_verstaendigen: $form.find('[name="polizei_verstaendigen"]').is(':checked') ? 1 : 0
        };
    }

    function saveDispatch($root, $form) {
        setMapStatus($root, 'Einsatzdaten werden gespeichert...', false);
        return simPost(dispatchFormData($root, $form));
    }

    function selectedDispatchVehicles($form) {
        return $form.find('[name="alarm_status_id"]:checked').map(function () {
            return $(this).val();
        }).get();
    }

    function renderLog($root, selector, items, emptyText, formatter) {
        var $log = $root.find(selector);
        if (!items || !items.length) {
            $log.html($('<p>', {
                class: 'lsttraining-muted',
                text: emptyText
            }));
            return;
        }

        $log.html(items.map(formatter).join(''));
    }

    function playRadioRequestBeep() {
        var AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) {
            return;
        }
        try {
            var ctx = new AudioContext();
            var oscillator = ctx.createOscillator();
            var gain = ctx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.08;
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.start();
            window.setTimeout(function () {
                oscillator.stop();
                ctx.close();
            }, 180);
        } catch (e) {
            // Browser may block audio until the next user gesture; the visual request remains visible.
        }
    }

    function renderRadioRequests($root, requests) {
        var pending = (requests || []).filter(function (request) {
            return !request.opened_at;
        });
        var $callsPanel = $root.find('.lsttraining-dispatch-panel--calls').first();
        var $box = $callsPanel.find('[data-lst-radio-requests]');
        if (!$box.length) {
            $box = $('<div class="lsttraining-radio-requests" data-lst-radio-requests></div>');
            $callsPanel.find('[data-lst-call-log]').before($box);
        }

        pending.forEach(function (request) {
            var key = String(request.event_id || '');
            if (key && !simRuntime.radioRequestsBeeped[key]) {
                simRuntime.radioRequestsBeeped[key] = true;
                playRadioRequestBeep();
            }
        });

        if (!pending.length) {
            $box.empty().prop('hidden', true);
            return;
        }

        $box.prop('hidden', false).html(pending.map(function (request) {
            var vehicle = request.rufname || ('Fahrzeug ' + (request.status_id || request.fahrzeug_id || ''));
            return '<button type="button" class="lsttraining-radio-request" data-lst-open-unit-report="' + esc(request.event_id) + '">' +
                '<strong>' + esc(vehicle) + '</strong>' +
                '<span>Sprechwunsch</span>' +
            '</button>';
        }).join(''));
    }

    function renderSnapshot($root, snapshot) {
        var previous = dispatchMap.lastSnapshot || {};
        dispatchMap.markerMode = vehicleMarkerMode(snapshot || {});
        prepareVehicleAnimation(previous, snapshot || {});
        dispatchMap.lastSnapshot = snapshot || {};
        logNominatimFallbacks(dispatchMap.lastSnapshot);
        renderVehicles($root, snapshotVehicleList());
        renderDispatchIncidents($root, dispatchMap.lastSnapshot.incidents || []);
        renderRadioRequests($root, dispatchMap.lastSnapshot.radio_requests || []);
        renderMap($root, dispatchMap.lastSnapshot);
        refreshStationInfo($root);

        renderLog($root, '[data-lst-fms-log]', dispatchMap.lastSnapshot.fms_log || [], 'Noch keine FMS-Meldungen.', function (item) {
            var arrow = item.direction === 'up' ? '&#8593;' : '&#8595;';
            return '<div class="lsttraining-log-row">' +
                '<time>' + esc(formatTime(item.ts)) + '</time>' +
                '<span class="lsttraining-log-arrow">' + arrow + '</span>' +
                '<span>' + esc(item.text || '') + '</span>' +
            '</div>';
        });

        renderLog($root, '[data-lst-call-log]', dispatchMap.lastSnapshot.call_log || [], 'Noch kein Anruferverlauf.', function (item) {
            var accept = item.can_accept
                ? '<button type="button" class="lsttraining-mini-btn" data-lst-accept-call="' + esc(item.einsatz_id) + '">Entgegennehmen</button>'
                : '';
            var createDispatch = item.can_open_dispatch
                ? '<button type="button" class="lsttraining-mini-btn" data-lst-open-dispatch="' + esc(item.einsatz_id) + '">Einsatz erstellen</button>'
                : '';
            var closeCall = item.can_close_without_dispatch
                ? '<button type="button" class="lsttraining-mini-btn lsttraining-mini-btn--muted" data-lst-no-dispatch="' + esc(item.einsatz_id) + '">Keinen Einsatz schicken</button>'
                : '';
            var ack = item.can_ack_unit_report
                ? '<button type="button" class="lsttraining-mini-btn" data-lst-ack-unit-report="' + esc(item.event_id) + '">Lagemeldung bestätigen</button>'
                : '';
            var vehicle = item.rufname
                ? '<button type="button" class="lsttraining-link-btn" data-lst-focus-vehicle="' + esc(item.status_id || item.fahrzeug_id) + '">' + esc(item.rufname) + '</button>: '
                : '';
            return '<div class="lsttraining-log-row">' +
                '<time>' + esc(formatTime(item.ts)) + '</time>' +
                '<span>' + vehicle + esc(item.text || '') + '<span class="lsttraining-call-actions">' + accept + createDispatch + closeCall + ack + '</span></span>' +
            '</div>';
        });

        syncAllPopouts($root);
    }

    function loadBootstrap($root, silent) {
        var instanzId = $root.attr('data-instance-id');
        if (simRuntime.isBootstrapLoading) {
            return $.Deferred().reject({ message: 'Simulationsbasis wird bereits geladen.' }).promise();
        }

        simRuntime.isBootstrapLoading = true;
        if (!silent) {
            setMapStatus($root, 'Simulationsbasis wird geladen...', false);
        }

        return simPost({
            action: 'lsttraining_sim_get_bootstrap',
            instanz_id: instanzId
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                showMessage($root, 'error', lsttrainingFrontend.texts.bootstrapError || lsttrainingFrontend.texts.snapshotError);
                return;
            }
            dispatchMap.bootstrap = response.data;
            clearMessage($root);
            renderSnapshot($root, dispatchMap.lastSnapshot || {});
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, lsttrainingFrontend.texts.bootstrapError || lsttrainingFrontend.texts.snapshotError);
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isBootstrapLoading = false;
        });
    }

    function loadSnapshot($root, silent) {
        var instanzId = $root.attr('data-instance-id');
        if (!dispatchMap.bootstrap) {
            showMessage($root, 'error', lsttrainingFrontend.texts.bootstrapError || 'Simulationsbasis konnte nicht geladen werden.');
            return;
        }
        if (simRuntime.isSnapshotLoading) {
            return;
        }

        simRuntime.isSnapshotLoading = true;
        if (!silent) {
            setMapStatus($root, 'Simulationsdaten werden geladen...', false);
        }

        simPost({
            action: 'lsttraining_sim_get_snapshot',
            instanz_id: instanzId
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                simRuntime.snapshotErrors++;
                showMessage($root, 'error', lsttrainingFrontend.texts.snapshotError);
                return;
            }
            simRuntime.snapshotErrors = 0;
            clearMessage($root);
            renderSnapshot($root, response.data);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, lsttrainingFrontend.texts.snapshotError);
            simRuntime.snapshotErrors++;
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isSnapshotLoading = false;
        });
    }

    function findVehicle(id) {
        var vehicles = snapshotVehicleList();
        return vehicles.find(function (vehicle) {
            return String(vehicle.fahrzeug_id || '') === String(id) || String(vehicle.status_id || '') === String(id);
        }) || null;
    }

    function snapshotVehicleList() {
        var snapshot = dispatchMap.lastSnapshot || {};
        var byStatus = {};
        function mergeVehicle(vehicle) {
            var key = String(vehicle.status_id || vehicle.fahrzeug_id || '');
            if (key) {
                byStatus[key] = $.extend({}, byStatus[key] || {}, vehicle);
            }
        }
        bootstrapBaseVehicles().forEach(mergeVehicle);
        (snapshot.vehicle_statuses || []).forEach(mergeVehicle);
        (snapshot.available_vehicles || []).forEach(mergeVehicle);
        (snapshot.vehicles || []).forEach(mergeVehicle);
        return Object.keys(byStatus).map(function (key) {
            return byStatus[key];
        });
    }

    function findIncident(id) {
        var incidents = dispatchMap.lastSnapshot && dispatchMap.lastSnapshot.incidents ? dispatchMap.lastSnapshot.incidents : [];
        return incidents.find(function (incident) {
            return String(incident.id || '') === String(id);
        }) || null;
    }

    function findStation(id) {
        var stations = bootstrapStations();
        return stations.find(function (station) {
            return String(station.id || '') === String(id);
        }) || null;
    }

    function vehicleCoordinate(vehicle) {
        var live = vehicleLonLat(vehicle);
        var station;
        if (live) {
            return live;
        }
        station = findStation(vehicle && vehicle.wache_id);
        if (station) {
            return vehicleLonLat({
                longitude: station.longitude,
                latitude: station.latitude
            });
        }
        return null;
    }

    function vehicleTargetCoordinate(vehicle) {
        var lon = Number(vehicle && vehicle.ziel_longitude);
        var lat = Number(vehicle && vehicle.ziel_latitude);
        return Number.isFinite(lon) && Number.isFinite(lat) ? [lon, lat] : null;
    }

    function stationVehicles(stationId) {
        var vehicles = snapshotVehicleList();
        return vehicles.filter(function (vehicle) {
            return String(vehicle.wache_id || '') === String(stationId || '') &&
                ['1', '2'].indexOf(String(vehicle.fms_status || '')) !== -1 &&
                !vehicleIsAssigned(vehicle);
        });
    }

    function stationKindLabel(kind, fallback) {
        if (kind === 'rd') {
            return 'Rettungsdienst';
        }
        if (kind === 'fw') {
            return 'Feuerwehr';
        }
        if (kind === 'thw') {
            return 'THW';
        }
        return fallback || 'Wache';
    }

    function incidentAccepted(incident) {
        return String(incident && (incident.call_status || (incident.meta && incident.meta.call_status) || 'ringing')) === 'accepted' ||
            String(incident && incident.state) === 'active';
    }

    function incidentPrepared(incident) {
        return String(incident && (incident.disposition_status || (incident.meta && incident.meta.disposition_status) || '')) === 'prepared';
    }

    function incidentAssignments(incidentId) {
        var assignments = dispatchMap.lastSnapshot && dispatchMap.lastSnapshot.assignments ? dispatchMap.lastSnapshot.assignments : [];
        return assignments.filter(function (assignment) {
            return String(assignment.einsatz_id || '') === String(incidentId || '');
        });
    }

    function findVehicleByAssignment(assignment) {
        var vehicles = snapshotVehicleList();
        return vehicles.find(function (vehicle) {
            return String(vehicle.status_id || '') === String(assignment.status_id || '') ||
                String(vehicle.fahrzeug_id || '') === String(assignment.fahrzeug_id || '');
        }) || null;
    }

    function assignedUnitsHtml(incidentId, compact) {
        var assignments = incidentAssignments(incidentId);
        if (!assignments.length) {
            return compact
                ? '<div class="lsttraining-assigned-units lsttraining-assigned-units--empty">Keine Fahrzeuge zugewiesen.</div>'
                : '<div class="lsttraining-assigned-units"><strong>Zugewiesene Fahrzeuge</strong><span>Keine Fahrzeuge zugewiesen.</span></div>';
        }

        return '<div class="lsttraining-assigned-units' + (compact ? ' lsttraining-assigned-units--compact' : '') + '">' +
            (compact ? '' : '<strong>Zugewiesene Fahrzeuge</strong>') +
            assignments.map(function (assignment) {
                var vehicle = findVehicleByAssignment(assignment);
                var fms = assignment.fms_status || (vehicle && vehicle.fms_status ? vehicle.fms_status : '');
                var name = assignment.rufname || (vehicle && vehicle.rufname) || ('Fahrzeug ' + (assignment.status_id || assignment.fahrzeug_id || ''));
                return '<span class="lsttraining-assigned-unit">' +
                    '<em>' + esc(name) + '</em>' +
                    (fms ? '<b class="lsttraining-fms-badge lsttraining-fms-badge--s' + esc(fms) + '">' + esc(fmsLabel(fms)) + '</b>' : '') +
                '</span>';
            }).join('') +
        '</div>';
    }

    function vehicleDistanceToIncident(vehicle, incident) {
        var start = vehicleCoordinate(vehicle);
        var lat1 = start ? Number(start[1]) : NaN;
        var lon1 = start ? Number(start[0]) : NaN;
        var lat2 = Number(incident && incident.latitude);
        var lon2 = Number(incident && incident.longitude);
        var earth = 6371000;
        var dLat;
        var dLon;
        var a;

        if (!Number.isFinite(lat1) || !Number.isFinite(lon1) || !Number.isFinite(lat2) || !Number.isFinite(lon2)) {
            return Number.POSITIVE_INFINITY;
        }

        lat1 = lat1 * Math.PI / 180;
        lat2 = lat2 * Math.PI / 180;
        dLat = lat2 - lat1;
        dLon = (lon2 - lon1) * Math.PI / 180;
        a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) * Math.sin(dLon / 2);

        return earth * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)));
    }

    function formatDistance(meters) {
        meters = Number(meters);
        if (!Number.isFinite(meters)) {
            return '';
        }
        if (meters >= 1000) {
            return (meters / 1000).toFixed(meters >= 10000 ? 0 : 1).replace('.', ',') + ' km';
        }
        return Math.round(meters) + ' m';
    }

    function vehicleIsAssigned(vehicle) {
        var assignments = dispatchMap.lastSnapshot && dispatchMap.lastSnapshot.assignments ? dispatchMap.lastSnapshot.assignments : [];
        var statusId = String(vehicle && vehicle.status_id || '');
        return assignments.some(function (assignment) {
            return String(assignment.status_id || '') === statusId;
        });
    }

    function ensureStationInfoPanel($root) {
        var $panel = $root.find('[data-lst-station-info]');
        if ($panel.length) {
            return $panel;
        }

        $panel = $('<div>', {
            class: 'lsttraining-station-info',
            'data-lst-station-info': '',
            hidden: true
        });
        $root.find('.lsttraining-dispatch-mapwrap').append($panel);
        return $panel;
    }

    function hideStationInfo($root) {
        dispatchMap.selectedStationId = null;
        ensureStationInfoPanel($root).prop('hidden', true).empty();
    }

    function refreshStationInfo($root) {
        if (!dispatchMap.selectedStationId) {
            return;
        }
        showStationInfo($root, dispatchMap.selectedStationId);
    }

    function showStationInfo($root, stationId) {
        var station = findStation(stationId);
        var $panel = ensureStationInfoPanel($root);
        if (!station) {
            hideStationInfo($root);
            return;
        }

        dispatchMap.selectedStationId = String(stationId);
        var vehicles = stationVehicles(stationId);
        var vehicleRows = vehicles.length
            ? vehicles.slice(0, 12).map(function (vehicle) {
                return '<li><strong>' + esc(vehicle.rufname || 'Fahrzeug') + '</strong><span>' + esc(fmsLabel(vehicle.fms_status)) + '</span></li>';
            }).join('')
            : '<li class="is-empty">Keine stationierten Fahrzeuge in dieser Instanz.</li>';

        var extra = vehicles.length > 12
            ? '<p class="lsttraining-station-info__more">+' + esc(String(vehicles.length - 12)) + ' weitere Fahrzeuge</p>'
            : '';

        $panel.html(
            '<button type="button" class="lsttraining-station-info__close" data-lst-close-station-info aria-label="Wacheninfo schließen">×</button>' +
            '<p class="lsttraining-kicker">' + esc(stationKindLabel(station.kind, station.typ)) + '</p>' +
            '<strong>' + esc(station.name || 'Wache') + '</strong>' +
            '<span>' + esc(vehicles.length + ' stationierte Fahrzeuge') + '</span>' +
            '<ul>' + vehicleRows + '</ul>' +
            extra
        ).prop('hidden', false);
    }

    function focusCoordinate($root, lon, lat, zoom) {
        var map = ensureDispatchMap($root);
        var coords = dispatchPoint(lon, lat);
        if (!map || !coords) {
            return;
        }
        map.getView().animate({
            center: coords,
            zoom: zoom || 14,
            duration: 300
        });
    }

    function clearRoute() {
        if (dispatchMap.routeSource) {
            dispatchMap.routeSource.clear();
        }
    }

    function findAssignmentByVehicle(vehicleId) {
        var assignments = dispatchMap.lastSnapshot && dispatchMap.lastSnapshot.assignments ? dispatchMap.lastSnapshot.assignments : [];
        return assignments.find(function (assignment) {
            return String(assignment.status_id || '') === String(vehicleId || '') ||
                String(assignment.fahrzeug_id || '') === String(vehicleId || '');
        }) || null;
    }

    function showAssignmentRoute($root, assignment) {
        var coordinates = assignment && Array.isArray(assignment.route_coordinates) ? assignment.route_coordinates : [];
        var geojson;
        var features;
        if (!coordinates.length || !dispatchMap.routeSource) {
            clearRoute();
            return false;
        }
        geojson = {
            type: 'Feature',
            properties: {},
            geometry: {
                type: 'LineString',
                coordinates: coordinates
            }
        };
        features = new ol.format.GeoJSON().readFeatures(geojson, {
            dataProjection: 'EPSG:4326',
            featureProjection: 'EPSG:3857'
        });
        dispatchMap.routeSource.clear();
        dispatchMap.routeSource.addFeatures(features);
        if (features.length) {
            ensureDispatchMap($root).getView().fit(dispatchMap.routeSource.getExtent(), {
                padding: [70, 70, 70, 70],
                maxZoom: 15,
                duration: 300
            });
        }
        setMapStatus($root, 'Fahrzeugroute geladen.', false);
        return true;
    }

    function selectVehicle($root, id, focus) {
        dispatchMap.selectedVehicleId = String(id);
        var vehicle = findVehicle(id);
        var assignment = findAssignmentByVehicle(id);
        var coords = vehicleCoordinate(vehicle);
        if (assignment && assignment.einsatz_id) {
            dispatchMap.selectedIncidentId = String(assignment.einsatz_id);
        }
        renderSnapshot($root, dispatchMap.lastSnapshot || {});
        if (focus && coords) {
            focusCoordinate($root, coords[0], coords[1], 14);
        }
        if (assignment && showAssignmentRoute($root, assignment)) {
            return;
        }
        if (vehicle && dispatchMap.selectedIncidentId && vehicleTargetCoordinate(vehicle)) {
            requestRoute($root, id, dispatchMap.selectedIncidentId);
        } else {
            clearRoute();
        }
    }

    function selectIncident($root, id, focus) {
        dispatchMap.selectedIncidentId = String(id);
        var incident = findIncident(id);
        clearRoute();
        renderSnapshot($root, dispatchMap.lastSnapshot || {});
        if (focus && incident) {
            focusCoordinate($root, incident.longitude, incident.latitude, 14);
        }
    }

    function requestRoute($root, vehicleId, incidentId) {
        var vehicle = findVehicle(vehicleId);
        var start = vehicleCoordinate(vehicle);
        var end = vehicleTargetCoordinate(vehicle);
        if (!start || !end || !Number.isFinite(start[0]) || !Number.isFinite(start[1]) || !Number.isFinite(end[0]) || !Number.isFinite(end[1])) {
            setMapStatus($root, 'Für diese gespeicherte Fahrzeugroute fehlen Zielkoordinaten.', true);
            return;
        }

        setMapStatus($root, 'Route wird berechnet...', false);
        routePost({
            instanz_id: Number($root.attr('data-instance-id')),
            leitstelle_id: Number(simulationInstance().leitstelle_id || 0) || null,
            coordinates: [start, end]
        }).done(function (response) {
            if (!response || !response.ok || !response.data) {
                setMapStatus($root, response && response.error ? response.error : lsttrainingFrontend.texts.routingError, true);
                return;
            }

            var features = new ol.format.GeoJSON().readFeatures(response.data, {
                dataProjection: 'EPSG:4326',
                featureProjection: 'EPSG:3857'
            });
            dispatchMap.routeSource.clear();
            dispatchMap.routeSource.addFeatures(features);
            if (features.length) {
                ensureDispatchMap($root).getView().fit(dispatchMap.routeSource.getExtent(), {
                    padding: [70, 70, 70, 70],
                    maxZoom: 15,
                    duration: 300
                });
            }
            setMapStatus($root, response.cached ? 'Route aus Cache geladen.' : 'Route geladen.', false);
            window.setTimeout(function () {
                setMapStatus($root, '', false);
            }, 2200);
        }).fail(function (xhr) {
            setMapStatus($root, ajaxErrorMessage(xhr, lsttrainingFrontend.texts.routingError), true);
        });
    }

    function acceptCall($root, einsatzId) {
        if (simRuntime.isActionLoading) {
            return;
        }

        simRuntime.isActionLoading = true;
        clearMessage($root);
        setMapStatus($root, 'Anruf wird angenommen...', false);

        simPost({
            action: 'lsttraining_sim_accept_call',
            einsatz_id: einsatzId
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : lsttrainingFrontend.texts.acceptError;
                showMessage($root, 'error', message);
                setMapStatus($root, message, true);
                return;
            }

            dispatchMap.selectedIncidentId = String(einsatzId);
            setMapStatus($root, 'Anruf angenommen.', false);
            loadSnapshot($root, true);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, lsttrainingFrontend.texts.acceptError);
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isActionLoading = false;
        });
    }

    function acknowledgeUnitReport($root, eventId) {
        if (simRuntime.isActionLoading) {
            return;
        }

        simRuntime.isActionLoading = true;
        clearMessage($root);
        setMapStatus($root, 'Lagemeldung wird bestätigt...', false);

        simPost({
            action: 'lsttraining_sim_ack_unit_report',
            instanz_id: $root.attr('data-instance-id'),
            event_id: eventId
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : 'Lagemeldung konnte nicht bestätigt werden.';
                showMessage($root, 'error', message);
                setMapStatus($root, message, true);
                return;
            }

            setMapStatus($root, response.data && response.data.message ? response.data.message : 'Lagemeldung bestätigt.', false);
            loadSnapshot($root, true);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, 'Lagemeldung konnte nicht bestätigt werden.');
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isActionLoading = false;
        });
    }

    function openUnitReport($root, eventId) {
        if (simRuntime.isActionLoading) {
            return;
        }

        simRuntime.isActionLoading = true;
        clearMessage($root);
        setMapStatus($root, 'Sprechwunsch wird geöffnet...', false);

        simPost({
            action: 'lsttraining_sim_open_unit_report',
            instanz_id: $root.attr('data-instance-id'),
            event_id: eventId
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : 'Sprechwunsch konnte nicht geöffnet werden.';
                showMessage($root, 'error', message);
                setMapStatus($root, message, true);
                return;
            }
            setMapStatus($root, 'Sprechwunsch geöffnet.', false);
            loadSnapshot($root, true);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, 'Sprechwunsch konnte nicht geöffnet werden.');
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isActionLoading = false;
        });
    }

    function closeCallWithoutDispatch($root, einsatzId) {
        if (simRuntime.isActionLoading) {
            return;
        }

        simRuntime.isActionLoading = true;
        clearMessage($root);
        setMapStatus($root, 'Anruf wird ohne Einsatz abgeschlossen...', false);

        simPost({
            action: 'lsttraining_sim_update_einsatz_state',
            einsatz_id: einsatzId,
            state: 'closed'
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : 'Anruf konnte nicht abgeschlossen werden.';
                showMessage($root, 'error', message);
                setMapStatus($root, message, true);
                return;
            }

            setMapStatus($root, 'Anruf ohne Einsatz abgeschlossen.', false);
            if (String(dispatchMap.selectedIncidentId) === String(einsatzId)) {
                dispatchMap.selectedIncidentId = null;
            }
            loadSnapshot($root, true);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, 'Anruf konnte nicht abgeschlossen werden.');
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isActionLoading = false;
        });
    }

    function alarmVehicle($root, statusId, incidentId, signalAllowed) {
        var vehicle = findVehicle(statusId);
        var incident = findIncident(incidentId);
        var start = vehicleCoordinate(vehicle);
        var end = incident ? [Number(incident.longitude), Number(incident.latitude)] : null;

        if (simRuntime.isActionLoading) {
            return $.Deferred().reject({ message: 'Eine Aktion läuft bereits.' }).promise();
        }
        if (!vehicle || !incident || !start || !end || !Number.isFinite(start[0]) || !Number.isFinite(start[1]) || !Number.isFinite(end[0]) || !Number.isFinite(end[1])) {
            setMapStatus($root, 'Für die Alarmierung fehlen Fahrzeug- oder Einsatzkoordinaten.', true);
            return $.Deferred().reject({ message: 'Für die Alarmierung fehlen Fahrzeug- oder Einsatzkoordinaten.' }).promise();
        }
        if (typeof signalAllowed === 'undefined') {
            signalAllowed = !!dispatchMeta(incident, 'signal_allowed', false);
        }

        simRuntime.isActionLoading = true;
        clearMessage($root);
        clearRoute();
        setMapStatus($root, 'Route wird berechnet...', false);

        return routePost({
            instanz_id: Number($root.attr('data-instance-id')),
            leitstelle_id: Number(simulationInstance().leitstelle_id || 0) || null,
            coordinates: [start, end]
        }).then(function (routeResponse) {
            if (!routeResponse || !routeResponse.ok || !routeResponse.data) {
                var routeMessage = routeResponse && routeResponse.error ? routeResponse.error : lsttrainingFrontend.texts.routingError;
                setMapStatus($root, routeMessage, true);
                return $.Deferred().reject({ message: routeMessage }).promise();
            }

            return simPost({
                action: 'lsttraining_sim_alarm_vehicle',
                instanz_id: $root.attr('data-instance-id'),
                einsatz_id: incidentId,
                status_id: statusId,
                sondersignal_allowed: signalAllowed ? 1 : 0,
                route_geojson: JSON.stringify(routeResponse.data)
            });
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : lsttrainingFrontend.texts.alarmError;
                showMessage($root, 'error', message);
                setMapStatus($root, message, true);
                return;
            }

            setMapStatus($root, response.data && response.data.message ? response.data.message : 'Fahrzeug alarmiert.', false);
            loadSnapshot($root, true);
        }).fail(function (xhr) {
            var message = xhr && xhr.message ? xhr.message : ajaxErrorMessage(xhr, lsttrainingFrontend.texts.alarmError);
            showMessage($root, 'error', message);
            setMapStatus($root, message, true);
        }).always(function () {
            simRuntime.isActionLoading = false;
        });
    }

    function runTick($root, silent) {
        var instanzId = $root.attr('data-instance-id');
        var $button = $root.find('[data-lst-run-tick]');
        if (simRuntime.isTickLoading || simRuntime.isSnapshotLoading) {
            return;
        }

        simRuntime.isTickLoading = true;
        if (!silent) {
            clearMessage($root);
            $button.prop('disabled', true).addClass('is-loading');
        }

        simPost({
            action: 'lsttraining_sim_tick',
            instanz_id: instanzId
        }).done(function (response) {
            if (!silent && response && response.success && response.data && response.data.message) {
                setMapStatus($root, response.data.message, false);
            }
        }).fail(function (xhr) {
            if (!silent) {
                showMessage($root, 'error', ajaxErrorMessage(xhr, lsttrainingFrontend.texts.tickError));
            }
        }).always(function () {
            simRuntime.isTickLoading = false;
            if (!silent) {
                $button.prop('disabled', false).removeClass('is-loading');
            }
        });
    }

    function forceSpawnOptionLabel(item) {
        var title = item && item.title ? item.title : '';
        var type = item && item.einsatztyp ? item.einsatztyp : '';
        var art = item && item.einsatzart ? item.einsatzart : '';
        var id = item && item.id ? '#' + item.id : '';
        var label = [title, type].filter(Boolean).join(' - ');
        if (!label) {
            label = id || 'Einsatzvorlage';
        }
        return (id ? id + ' ' : '') + label + (art ? ' (' + art + ')' : '');
    }

    function resetForceSpawnButton($root) {
        if (simRuntime.isForceSpawnLoading) {
            return;
        }
        $root.find('[data-lst-force-spawn]').prop('disabled', false).removeClass('is-loading');
    }

    function renderForceSpawnModal($root, items) {
        var $modal = $root.find('[data-lst-dispatch-modal]');
        var optionsHtml = '<option value="">Zufälliger Einsatz</option>' + (items || []).map(function (item) {
            return '<option value="' + esc(item.id || '') + '">' + esc(forceSpawnOptionLabel(item)) + '</option>';
        }).join('');

        $modal.html(
            '<div class="lsttraining-dispatch-modal__dialog" role="dialog" aria-modal="true" aria-label="Neuen Anruf erzeugen">' +
                '<header><strong>Neuen Anruf erzeugen</strong><button type="button" data-lst-close-dispatch-modal aria-label="Schließen">×</button></header>' +
                '<div class="lsttraining-dispatch-modal__body">' +
                    '<label><span>Einsatzvorlage:</span><select name="force_einsatz_id" data-lst-force-spawn-select>' + optionsHtml + '</select></label>' +
                    '<p class="lsttraining-muted">Zufällig nutzt dieselbe gewichtete Auswahl wie der normale Einsatzgenerator.</p>' +
                    '<footer>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-submit-force-spawn>Anruf erzeugen</button>' +
                    '</footer>' +
                '</div>' +
            '</div>'
        ).prop('hidden', false);
        resetForceSpawnButton($root);
    }

    function renderForceSpawnOptionsError($root, message) {
        var $modal = $root.find('[data-lst-dispatch-modal]');
        $modal.html(
            '<div class="lsttraining-dispatch-modal__dialog" role="dialog" aria-modal="true" aria-label="Einsatzvorlagen konnten nicht geladen werden">' +
                '<header><strong>Neuen Anruf erzeugen</strong><button type="button" data-lst-close-dispatch-modal aria-label="Schließen">×</button></header>' +
                '<div class="lsttraining-dispatch-modal__body">' +
                    '<p class="lsttraining-muted">' + esc(message || 'Einsatzvorlagen konnten nicht geladen werden.') + '</p>' +
                    '<footer>' +
                        '<button type="button" class="lsttraining-mini-btn" data-lst-retry-force-spawn-options>Erneut versuchen</button>' +
                    '</footer>' +
                '</div>' +
            '</div>'
        ).prop('hidden', false);
        resetForceSpawnButton($root);
    }

    function openForceSpawnModal($root) {
        var instanzId = $root.attr('data-instance-id');
        var $modal = $root.find('[data-lst-dispatch-modal]');

        if (simRuntime.forceSpawnOptions) {
            renderForceSpawnModal($root, simRuntime.forceSpawnOptions);
            return;
        }
        if (simRuntime.isForceOptionsLoading) {
            return;
        }

        simRuntime.isForceOptionsLoading = true;
        clearMessage($root);
        $modal.html(
            '<div class="lsttraining-dispatch-modal__dialog" role="dialog" aria-modal="true" aria-label="Einsatzvorlagen laden">' +
                '<header><strong>Neuen Anruf erzeugen</strong><button type="button" data-lst-close-dispatch-modal aria-label="Schließen">×</button></header>' +
                '<div class="lsttraining-dispatch-modal__body"><p class="lsttraining-muted">Einsatzvorlagen werden geladen...</p></div>' +
            '</div>'
        ).prop('hidden', false);

        simPost({
            action: 'lsttraining_sim_force_spawn_options',
            instanz_id: instanzId
        }).done(function (response) {
            if (!response || !response.success) {
                var message = response && response.data && response.data.message ? response.data.message : 'Einsatzvorlagen konnten nicht geladen werden.';
                setMapStatus($root, message, true);
                showMessage($root, 'error', message);
                renderForceSpawnOptionsError($root, message);
                return;
            }

            simRuntime.forceSpawnOptions = response.data && response.data.items ? response.data.items : [];
            renderForceSpawnModal($root, simRuntime.forceSpawnOptions);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, 'Einsatzvorlagen konnten nicht geladen werden.');
            setMapStatus($root, message, true);
            showMessage($root, 'error', message);
            renderForceSpawnOptionsError($root, message);
        }).always(function () {
            simRuntime.isForceOptionsLoading = false;
        });
    }

    function submitForceSpawn($root) {
        var value = $root.find('[data-lst-force-spawn-select]').val() || '';
        closeDispatchModal($root);
        forceSpawn($root, value);
    }

    function forceSpawn($root, einsatzId) {
        var instanzId = $root.attr('data-instance-id');
        var $button = $root.find('[data-lst-force-spawn]');
        var payload = {
            action: 'lsttraining_sim_force_spawn',
            nonce: lsttrainingFrontend.nonce,
            instanz_id: instanzId
        };
        if (simRuntime.isForceSpawnLoading) {
            return;
        }
        if (parseInt(einsatzId, 10) > 0) {
            payload.einsatz_id = parseInt(einsatzId, 10);
        }

        simRuntime.isForceSpawnLoading = true;
        clearMessage($root);
        $button.prop('disabled', true).addClass('is-loading');
        setMapStatus($root, 'Neuer Anruf wird erzeugt...', false);

        simPost(payload).done(function (response) {
            var message = response && response.data && response.data.message ? response.data.message : 'Neuer Anruf geprüft.';
            if (!response || !response.success) {
                setMapStatus($root, message, true);
                showMessage($root, 'error', message);
                logSimulationError('Neuer Anruf konnte nicht erzeugt werden', message, response && response.data ? response.data : response);
                return;
            }

            setMapStatus($root, message, !response.data.spawned);
            if (!response.data.spawned) {
                logSimulationError('Neuer Anruf hat keinen Einsatz erzeugt', message, {
                    response: response,
                    diagnostics: response.data ? response.data.diagnostics : null
                });
            }
            loadSnapshot($root, true);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, 'Neuer Anruf konnte nicht erzeugt werden.');
            setMapStatus($root, message, true);
            showMessage($root, 'error', message);
            logSimulationError('Neuer Anruf AJAX-Fehler', message, xhr && xhr.responseJSON ? xhr.responseJSON : {
                status: xhr ? xhr.status : 0,
                responseText: xhr ? xhr.responseText : ''
            });
        }).always(function () {
            simRuntime.isForceSpawnLoading = false;
            resetForceSpawnButton($root);
        });
    }

    function scheduleSnapshotLoop($root) {
        if (simRuntime.stopped) {
            return;
        }

        var delay = simRuntime.snapshotErrors >= 3 ? 30000 : 10000;
        simRuntime.snapshotTimer = window.setTimeout(function () {
            loadSnapshot($root, true);
            scheduleSnapshotLoop($root);
        }, delay);
    }

    function scheduleTickLoop($root) {
        if (simRuntime.stopped) {
            return;
        }

        simRuntime.tickTimer = window.setTimeout(function () {
            runTick($root, true);
            scheduleTickLoop($root);
        }, 30000);
    }

    $(function () {
        var $root = $('[data-lsttraining-start]');
        var $instance = $('[data-lsttraining-instance]');

        if ($instance.length) {
            initResizableLayout($instance);
            initPopouts($instance);
            ensureDispatchMap($instance);
            resetForceSpawnButton($instance);
            startSimClock($instance);
            loadBootstrap($instance, false).done(function (response) {
                if (response && response.success) {
                    loadSnapshot($instance, false);
                    scheduleSnapshotLoop($instance);
                    scheduleTickLoop($instance);
                }
            });

            $instance.on('click', '[data-lst-run-tick]', function () {
                runTick($instance, false);
            });

            $instance.on('click', '[data-lst-force-spawn]', function (e) {
                e.preventDefault();
                openForceSpawnModal($instance);
            });

            $instance.on('click', '[data-lst-popout]', function () {
                openPopout($instance, $(this).attr('data-lst-popout'));
            });

            $instance.on('click', '[data-lst-close-station-info]', function () {
                hideStationInfo($instance);
            });

            $instance.on('click', '[data-lst-layout-reset]', function () {
                resetSimLayout($instance);
            });

            $instance.on('click', '[data-lst-focus-vehicle]', function () {
                selectVehicle($instance, $(this).attr('data-lst-focus-vehicle'), true);
            });

            $instance.on('click', '[data-lst-focus-incident]', function () {
                selectIncident($instance, $(this).attr('data-lst-focus-incident'), true);
            });

            $instance.on('click', '[data-lst-route-incident]', function () {
                var incidentId = $(this).attr('data-lst-route-incident');
                dispatchMap.selectedIncidentId = String(incidentId);
                clearRoute();
                renderSnapshot($instance, dispatchMap.lastSnapshot || {});
            });

            $instance.on('click', '[data-lst-accept-call]', function () {
                acceptCall($instance, $(this).attr('data-lst-accept-call'));
            });

            $instance.on('click', '[data-lst-ack-unit-report]', function () {
                acknowledgeUnitReport($instance, $(this).attr('data-lst-ack-unit-report'));
            });

            $instance.on('click', '[data-lst-open-unit-report]', function () {
                openUnitReport($instance, $(this).attr('data-lst-open-unit-report'));
            });

            $instance.on('click', '[data-lst-no-dispatch]', function () {
                closeCallWithoutDispatch($instance, $(this).attr('data-lst-no-dispatch'));
            });

            $instance.on('click', '[data-lst-open-dispatch]', function () {
                openDispatchModal($instance, $(this).attr('data-lst-open-dispatch'));
            });

            $instance.on('click', '[data-lst-close-dispatch-modal]', function () {
                closeDispatchModal($instance);
            });

            $instance.on('click', '[data-lst-retry-force-spawn-options]', function (e) {
                e.preventDefault();
                openForceSpawnModal($instance);
            });

            $instance.on('click', '[data-lst-submit-force-spawn]', function (e) {
                e.preventDefault();
                submitForceSpawn($instance);
            });

            $instance.on('click', '[data-lst-vehicle-filter]', function () {
                var filter = $(this).attr('data-lst-vehicle-filter');
                var $modal = $instance.find('[data-lst-dispatch-modal]');
                $modal.find('[data-lst-vehicle-filter]').removeClass('is-active');
                $(this).addClass('is-active');
                $modal.find('[data-vehicle-filter-row]').each(function () {
                    var rowFilter = $(this).attr('data-vehicle-filter-row');
                    $(this).prop('hidden', filter !== 'all' && rowFilter !== filter);
                });
            });

            $instance.on('click', '[data-lst-save-dispatch], [data-lst-save-dispatch-alarm], [data-lst-save-dispatch-alarm-support]', function () {
                var $form = $instance.find('[data-lst-dispatch-form]');
                var shouldAlarm = $(this).is('[data-lst-save-dispatch-alarm], [data-lst-save-dispatch-alarm-support]');
                var selected = selectedDispatchVehicles($form);
                var incidentId = $form.attr('data-incident-id');
                var signalAllowed = $form.find('[name="signal_allowed"]').is(':checked');

                if (shouldAlarm && !selected.length) {
                    showMessage($instance, 'error', 'Bitte mindestens ein Fahrzeug für die Alarmierung auswählen.');
                    return;
                }

                saveDispatch($instance, $form).done(function (response) {
                    if (!response || !response.success) {
                        var message = response && response.data && response.data.message ? response.data.message : 'Einsatzdaten konnten nicht gespeichert werden.';
                        showMessage($instance, 'error', message);
                        setMapStatus($instance, message, true);
                        return;
                    }

                    if (!shouldAlarm) {
                        setMapStatus($instance, 'Einsatzdaten gespeichert.', false);
                        closeDispatchModal($instance);
                        loadSnapshot($instance, true);
                        return;
                    }

                    var chain = $.Deferred().resolve().promise();
                    selected.forEach(function (statusId) {
                        chain = chain.then(function () {
                            return alarmVehicle($instance, statusId, incidentId, signalAllowed);
                        });
                    });
                    chain.always(function () {
                        closeDispatchModal($instance);
                        loadSnapshot($instance, true);
                    });
                }).fail(function (xhr) {
                    var message = ajaxErrorMessage(xhr, 'Einsatzdaten konnten nicht gespeichert werden.');
                    showMessage($instance, 'error', message);
                    setMapStatus($instance, message, true);
                });
            });

            $instance.on('click', '[data-lst-alarm-vehicle]', function () {
                alarmVehicle($instance, $(this).attr('data-lst-alarm-vehicle'), $(this).attr('data-lst-alarm-incident'));
            });

            $(window).on('beforeunload', function () {
                simRuntime.stopped = true;
                if (simRuntime.snapshotTimer) {
                    window.clearTimeout(simRuntime.snapshotTimer);
                }
                if (simRuntime.tickTimer) {
                    window.clearTimeout(simRuntime.tickTimer);
                }
                if (simRuntime.clockTimer) {
                    window.clearInterval(simRuntime.clockTimer);
                }
                if (simRuntime.animationFrame) {
                    window.cancelAnimationFrame(simRuntime.animationFrame);
                    simRuntime.animationFrame = null;
                }
                Object.keys(simRuntime.popouts).forEach(function (key) {
                    var entry = simRuntime.popouts[key];
                    if (entry && entry.win && !entry.win.closed) {
                        entry.win.close();
                    }
                });
                $(window).off('resize.lsttrainingLayout');
                $(document).off('pointermove.lsttrainingResize pointerup.lsttrainingResize pointercancel.lsttrainingResize');
            });
        }

        if (!$root.length) {
            return;
        }

        loadLeitstellen($root);
        loadOpenInstances($root);
        clearAreaPreview($root);
        updateSeason($root);

        $root.on('click', '[data-lst-now]', function () {
            setNow($root);
        });

        $root.on('change input', '[name="start_date"], [data-lst-season-select]', function () {
            updateSeason($root);
        });

        $root.on('change', '[data-lst-leitstellen]', function () {
            var $option = $(this).find('option:selected');
            loadAreaPreview($root, $(this).val(), {
                lat: $option.attr('data-lat'),
                lon: $option.attr('data-lon')
            });
        });

        $root.on('click', '[data-lst-refresh-instances]', function () {
            loadOpenInstances($root);
        });

        $root.on('click', '[data-lst-join-instance]', function () {
            var $button = $(this);
            var instanzId = $button.attr('data-lst-join-instance');
            var targetWindow = openSimulationWindow();

            if ($button.attr('data-lst-can-join') === '0') {
                navigateToSimulation(instanceUrl(instanzId), targetWindow);
                return;
            }

            joinInstance($root, instanzId, $button, targetWindow);
        });

        $root.on('submit', '#lsttraining-start-form', function (event) {
            event.preventDefault();

            var $form = $(this);
            var $submit = $form.find('[data-lst-submit]');
            clearMessage($root);

            if (!$form.find('[name="leitstelle_id"]').val()) {
                showMessage($root, 'error', 'Bitte wähle eine Leitstelle aus.');
                return;
            }

            $submit.prop('disabled', true).addClass('is-loading');
            var targetWindow = openSimulationWindow();

            $.post(lsttrainingFrontend.ajax_url, collectFormData($form))
                .done(function (response) {
                    if (!response || !response.success || !response.data || !response.data.redirect_url) {
                        if (targetWindow && !targetWindow.closed) {
                            targetWindow.close();
                        }
                        showMessage(
                            $root,
                            'error',
                            response && response.data && response.data.message
                                ? response.data.message
                                : lsttrainingFrontend.texts.startError
                        );
                        return;
                    }

                    showMessage($root, 'success', lsttrainingFrontend.texts.startSuccess);
                    window.setTimeout(function () {
                        navigateToSimulation(response.data.redirect_url, targetWindow);
                    }, 500);
                })
                .fail(function (xhr) {
                    if (targetWindow && !targetWindow.closed) {
                        targetWindow.close();
                    }
                    showMessage($root, 'error', ajaxErrorMessage(xhr, lsttrainingFrontend.texts.startError));
                })
                .always(function () {
                    $submit.prop('disabled', false).removeClass('is-loading');
                });
        });
    });
})(jQuery);
