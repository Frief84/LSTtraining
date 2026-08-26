(function ($) {
    'use strict';

    var state = {
        $root: null,
        instanceId: '',
        bootstrap: null,
        snapshot: null,
        hospitals: [],
        departmentConfig: {},
        pois: [],
        osmLayers: [],
        pendingDispatchIncidentId: '',
        pendingDetailsIncidentId: '',
        pendingRevealIncidentId: '',
        activeCallModalIncidentId: '',
        selectedIncidentId: '',
        selectedVehicleId: '',
        vehicleFilter: 'all',
        vehicleSearch: '',
        radioFilter: 'all',
        playing: true,
        paused: false,
        speed: 1,
        simClock: {
            timestamp: 0,
            receivedAt: 0,
            speed: 1,
            paused: false
        },
        seenCallKeys: {},
        seenRadioRequestKeys: {},
        loggedRouteErrors: {},
        motorwayRepairRequests: {},
        snapshotSeen: false,
        popouts: {},
        modalDrag: null,
        resizeObserver: null,
        vehicleAnimation: {},
        animationFrame: null,
        timers: {
            snapshot: null,
            tick: null,
            clock: null,
            radioNext: null
        },
        busy: {
            bootstrap: false,
            snapshot: false,
            tick: false
        },
        signalSprites: {},
        failedSignalSprites: {},
        vehicleImageDimensions: {},
        vehicleImagePreload: {},
        nonceRefreshPromise: null,
        forceSpawnOptions: null,
        forceSpawnSubmitting: false,
        neighborSupportDrafts: {},
        layoutKey: '',
        layout: null,
        map: {
            main: null,
            hasFit: false,
            homeExtentSource: null,
            sources: {},
            layers: {},
            routeSource: null,
            visible: {
                incidents: true,
                vehicles: true,
                hospitals: true,
                stations: true
            }
        }
    };

    var panelTitles = {
        map: 'Karte',
        vehicles: 'Fahrzeuge',
        incidents: 'Einsätze',
        details: 'Einsatzdetails',
        radio: 'Funk'
    };

    var defaultAreas = {
        map: 'map',
        vehicles: 'vehicles',
        incidents: 'incidents',
        details: 'details',
        radio: 'radio'
    };

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function asArray(value) {
        return Array.isArray(value) ? value : [];
    }

    function asObject(value) {
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function showMessage(message, type) {
        var $message = state.$root.find('[data-lstw-message]');
        $message
            .toggleClass('lsttraining-message--success', type === 'success')
            .toggleClass('lsttraining-message--error', type !== 'success')
            .text(message || '')
            .prop('hidden', !message);
    }

    function setMapStatus(message, isError) {
        state.$root.find('[data-lstw-map-status]')
            .toggleClass('lsttraining-message--error', !!isError)
            .text(message || '')
            .prop('hidden', !message);
    }

    function setMapStatusHtml(html, isError) {
        state.$root.find('[data-lstw-map-status]')
            .toggleClass('lsttraining-message--error', !!isError)
            .html(html || '')
            .prop('hidden', !html);
    }

    function clearMapStatus() {
        setMapStatusHtml('', false);
    }

    function ajaxErrorMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }
        if (xhr && xhr.message) {
            return xhr.message;
        }
        return fallback;
    }

    function routingErrorData(error, fallback) {
        var response = error && error.responseJSON ? error.responseJSON : null;
        var data = response && response.data ? response.data : (error && error.data ? error.data : null);
        if (!data && error && typeof error === 'object' && (error.route_error_code || error.route_error_message || error.route_error_detail || error.technical_detail)) {
            data = error;
        }
        data = asObject(data);
        var message = data.message || data.route_error_message || ajaxErrorMessage(error, fallback || 'Route konnte nicht berechnet werden.');
        return {
            message: message,
            code: data.route_error_code || data.error_code || '',
            detail: data.route_error_detail || data.technical_detail || '',
            stage: data.stage || data.route_stage || '',
            httpStatus: data.http_status || data.httpStatus || (error && error.status ? error.status : ''),
            eventId: data.event_id || data.route_event_id || '',
            statusId: data.status_id || '',
            vehicleId: data.fahrzeug_id || data.vehicle_id || '',
            incidentId: data.einsatz_id || data.incident_id || '',
            raw: response || error || data
        };
    }

    function routeErrorLogKey(context, info, meta) {
        meta = meta || {};
        return [
            context || 'routing',
            info.eventId || meta.eventId || '',
            info.statusId || meta.statusId || '',
            info.vehicleId || meta.vehicleId || '',
            info.incidentId || meta.incidentId || '',
            info.code || '',
            info.detail || info.message || ''
        ].join('|');
    }

    function logRoutingError(context, error, meta, dedupe) {
        if (!window.console || typeof window.console.error !== 'function') {
            return;
        }
        meta = meta || {};
        var info = routingErrorData(error, meta.fallback || 'Route konnte nicht berechnet werden.');
        info.eventId = info.eventId || meta.eventId || '';
        info.statusId = info.statusId || meta.statusId || '';
        info.vehicleId = info.vehicleId || meta.vehicleId || '';
        info.incidentId = info.incidentId || meta.incidentId || '';
        var key = routeErrorLogKey(context, info, meta);
        if (dedupe && state.loggedRouteErrors[key]) {
            return;
        }
        if (dedupe) {
            state.loggedRouteErrors[key] = true;
        }

        var payload = {
            context: context || 'routing',
            message: info.message || '',
            code: info.code || '',
            detail: info.detail || '',
            stage: info.stage || '',
            httpStatus: info.httpStatus || '',
            eventId: info.eventId || '',
            statusId: info.statusId || '',
            vehicleId: info.vehicleId || '',
            incidentId: info.incidentId || '',
            raw: info.raw
        };

        if (typeof window.console.groupCollapsed === 'function') {
            window.console.groupCollapsed('[LSTtraining][Routing] ' + (payload.code || payload.message || 'Routingfehler'));
            window.console.error(payload.message || 'Routingfehler', payload);
            if (typeof window.console.groupEnd === 'function') {
                window.console.groupEnd();
            }
            return;
        }
        window.console.error('[LSTtraining][Routing]', payload);
    }

    function isNonceError(xhr) {
        var msg = ajaxErrorMessage(xhr, '');
        return /Sicherheits-Token|nonce|token/i.test(msg || '') || (xhr && (xhr.status === 400 || xhr.status === 403) && /nonce|token/i.test(xhr.responseText || ''));
    }

    function refreshNonce() {
        if (state.nonceRefreshPromise) {
            return state.nonceRefreshPromise;
        }

        state.nonceRefreshPromise = $.post(lsttrainingWorkspace.ajax_url, {
            action: 'lsttraining_sim_refresh_nonce'
        }).then(function (response) {
            if (!response || !response.success || !response.data || !response.data.nonce) {
                return $.Deferred().reject({ message: 'Sitzung abgelaufen. Bitte Seite neu laden.' }).promise();
            }
            lsttrainingWorkspace.nonce = response.data.nonce;
            if (response.data.rest_nonce) {
                lsttrainingWorkspace.rest_nonce = response.data.rest_nonce;
            }
            return response;
        }).always(function () {
            state.nonceRefreshPromise = null;
        });

        return state.nonceRefreshPromise;
    }

    function simPost(payload) {
        var retried = false;

        function send() {
            return $.post(lsttrainingWorkspace.ajax_url, $.extend({}, payload, {
                nonce: lsttrainingWorkspace.nonce
            })).then(function (response) {
                if (!retried && response && response.success === false && /Sicherheits-Token/i.test(response.data && response.data.message || '')) {
                    retried = true;
                    return refreshNonce().then(send);
                }
                return response;
            }, function (xhr) {
                if (!retried && isNonceError(xhr)) {
                    retried = true;
                    return refreshNonce().then(send);
                }
                return $.Deferred().reject(xhr).promise();
            });
        }

        return send();
    }

    function routePost(payload) {
        var retried = false;
        payload = $.extend({
            preference: 'fastest',
            profile: 'driving-car',
            alternatives: true
        }, payload || {});

        function send() {
            return $.ajax({
                url: (lsttrainingWorkspace.rest_url || '/wp-json/lst/v1/').replace(/\/?$/, '/') + 'route',
                method: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-WP-Nonce': lsttrainingWorkspace.rest_nonce || ''
                },
                data: JSON.stringify(payload)
            }).then(null, function (xhr) {
                if (!retried && isNonceError(xhr)) {
                    retried = true;
                    return refreshNonce().then(send);
                }
                return $.Deferred().reject(xhr).promise();
            });
        }

        return send();
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

    function timeValueTimestamp(value) {
        var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (!match) {
            return 0;
        }
        return Math.floor(Date.UTC(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]),
            Number(match[4]),
            Number(match[5]),
            Number(match[6] || 0)
        ) / 1000);
    }

    function currentSimWallTimestamp() {
        var timestamp = currentSimTimestamp();
        if (!timestamp) {
            return 0;
        }
        var now = new Date(timestamp * 1000);
        return Math.floor(Date.UTC(
            now.getFullYear(),
            now.getMonth(),
            now.getDate(),
            now.getHours(),
            now.getMinutes(),
            now.getSeconds()
        ) / 1000);
    }

    function formatElapsed(seconds) {
        seconds = Math.max(0, Math.floor(Number(seconds) || 0));
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var remaining = seconds % 60;
        return [
            String(hours).padStart(2, '0'),
            String(minutes).padStart(2, '0'),
            String(remaining).padStart(2, '0')
        ].join(':');
    }

    function incidentElapsedText(incident) {
        var start = timeValueTimestamp(incident && (incident.sim_created_at || incident.created_at));
        var now = currentSimWallTimestamp();
        return formatElapsed(start && now ? now - start : 0);
    }

    function updateIncidentElapsedTimes() {
        state.$root.find('[data-lstw-incident-elapsed]').each(function () {
            var incident = findIncident($(this).attr('data-lstw-incident-elapsed'));
            if (incident) {
                $(this).text(incidentElapsedText(incident));
            }
        });
    }

    function supportsMapImage(url) {
        var clean = String(url || '').split('?')[0].split('#')[0].toLowerCase();
        return clean === '' || /\.(png|webp|gif|svg|jpe?g)$/.test(clean);
    }

    function normalizeMarkerMode(mode) {
        mode = String(mode || '');
        return mode === 'image' || mode === 'tactical' ? mode : 'marker';
    }

    function vehicleMarkerMode(snapshot) {
        var preferences = state.bootstrap && state.bootstrap.preferences
            ? state.bootstrap.preferences
            : (snapshot && snapshot.preferences ? snapshot.preferences : {});
        return normalizeMarkerMode(preferences.vehicle_marker_mode || '');
    }

    function tacticalVehicleLetter(vehicle) {
        var type = String(vehicle && (vehicle.fahrzeugtyp || vehicle.rufname) || '').toUpperCase();
        if (type.indexOf('NEF') !== -1 || type.indexOf('NAW') !== -1 || type.indexOf('RTH') !== -1 || type.indexOf('ITH') !== -1 || type.indexOf('BABY-NAW') !== -1) {
            return 'NA';
        }
        if (type.indexOf('RTW') !== -1) return 'RTW';
        if (type.indexOf('KTW') !== -1) return 'KTW';
        if (type.indexOf('HLF') !== -1 || type.indexOf('LF') !== -1 || type.indexOf('TLF') !== -1 || type.indexOf('DLK') !== -1) return 'FW';
        if (type.indexOf('THW') !== -1) return 'THW';
        return (type || 'FZ').slice(0, 3);
    }

    function currentSimTimestamp() {
        var base = Number(state.simClock.timestamp || 0);
        if (!base) {
            return 0;
        }
        if (state.simClock.paused) {
            return base;
        }
        var elapsed = Math.max(0, (Date.now() - Number(state.simClock.receivedAt || Date.now())) / 1000);
        return Math.floor(base + (elapsed * Number(state.simClock.speed || state.speed || 1)));
    }

    function syncSimClock(data) {
        var timestamp = Number(data && (data.sim_timestamp || data.simTimestamp || (data.instance && data.instance.sim_timestamp)));
        var speed = Number(data && (data.speed || (data.instance && data.instance.speed) || state.speed));
        var paused = data && Object.prototype.hasOwnProperty.call(data, 'paused') ? !!data.paused : !!(data && data.instance && data.instance.paused);
        if (!Number.isFinite(timestamp) || timestamp <= 0) {
            return;
        }
        state.simClock.timestamp = timestamp;
        state.simClock.receivedAt = Date.now();
        state.simClock.speed = [1, 2, 5].indexOf(speed) !== -1 ? speed : state.speed;
        state.simClock.paused = paused;
        state.speed = state.simClock.speed;
        state.paused = paused;
        state.playing = !paused;
        updateRuntimeUi();
        updateClock();
    }

    function materializeLocalClock(nextSpeed, nextPaused) {
        var timestamp = currentSimTimestamp();
        if (timestamp > 0) {
            state.simClock.timestamp = timestamp;
        }
        state.simClock.receivedAt = Date.now();
        state.simClock.speed = [1, 2, 5].indexOf(Number(nextSpeed)) !== -1 ? Number(nextSpeed) : state.speed;
        state.simClock.paused = !!nextPaused;
        updateClock();
    }

    function updateClock() {
        var timestamp = currentSimTimestamp();
        if (!timestamp) {
            return;
        }
        var now = new Date(timestamp * 1000);
        state.$root.find('[data-lstw-sim-time]').text([
            String(now.getHours()).padStart(2, '0'),
            String(now.getMinutes()).padStart(2, '0'),
            String(now.getSeconds()).padStart(2, '0')
        ].join(':'));
        updateIncidentElapsedTimes();
        renderWeather();
    }

    function currentWeather() {
        return asObject((state.snapshot && state.snapshot.weather_current) || (state.bootstrap && state.bootstrap.weather_current) || (instanceContext().weather_current));
    }

    function weatherSummary() {
        return asObject((state.snapshot && state.snapshot.weather_forecast_summary) || (state.bootstrap && state.bootstrap.weather_forecast_summary) || (instanceContext().weather_forecast_summary));
    }

    function renderWeather() {
        var weather = currentWeather();
        var summary = weatherSummary();
        var label = weather.label || summary.label || weather.primary || 'unbekannt';
        var tags = asArray(weather.tags || summary.tags).filter(Boolean);
        var source = String(weather.source || summary.source || '');
        var suffix = source === 'open_meteo' ? 'Vorhersage' : (source ? 'simuliert' : '');
        state.$root.find('[data-lstw-weather-label]').text([label, suffix].filter(Boolean).join(' · '));
        var next = asObject(summary.next_change || {});
        var nextText = '';
        if (next.time && next.label) {
            var ts = timeValueTimestamp(next.time);
            if (ts) {
                var d = new Date(ts * 1000);
                nextText = 'ändert sich gegen ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ' zu ' + next.label;
            }
        }
        if (!nextText && tags.length) {
            nextText = tags.join(', ');
        }
        state.$root.find('[data-lstw-weather-next]').text(nextText);
    }

    function isPaused() {
        return !!state.paused || !state.playing;
    }

    function mutationBlocked(message) {
        if (!isPaused()) {
            return false;
        }
        showMessage(message || 'Simulation ist pausiert. Aktionen sind erst nach Play wieder möglich.', 'error');
        return true;
    }

    function persistRuntime() {
        if (!state.instanceId) {
            return;
        }
        simPost({
            action: 'lsttraining_sim_set_runtime',
            instanz_id: state.instanceId,
            paused: state.paused ? 1 : 0,
            speed: state.speed
        }).done(function (response) {
            if (response && response.success && response.data) {
                syncSimClock(response.data);
            }
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Simulationszustand konnte nicht gespeichert werden.'), 'error');
        });
    }

    function updateRuntimeUi() {
        var paused = isPaused();
        state.$root.toggleClass('is-paused', paused);
        state.$root.find('[data-lstw-pause-overlay]').prop('hidden', !paused);
        state.$root.find('[data-lstw-toggle-pause]')
            .text(paused ? 'Play' : 'Pause')
            .toggleClass('is-active', paused)
            .attr('aria-pressed', paused ? 'true' : 'false');
        state.$root.find('[data-lstw-speed]').removeClass('is-active');
        state.$root.find('[data-lstw-speed="' + state.speed + '"]').addClass('is-active');
        state.$root.find('[data-lstw-new-incident], [data-lstw-edit-incident], [data-lstw-close-incident], [data-lstw-no-incident], [data-lstw-accept-call], [data-lstw-ack-report], [data-lstw-open-report], [data-lstw-submit-force-spawn], [data-lstw-save-dispatch], [data-lstw-save-dispatch-alarm], [data-lstw-open-neighbor-support], [data-lstw-request-neighbor-support], [data-lstw-accept-neighbor-support], [data-lstw-unassign-unit], [data-lstw-recall-vehicle], [data-lstw-vehicle-radio-command], [name="alarm_status_id"], [name="neighbor_vehicle_id"]')
            .prop('disabled', paused)
            .attr('aria-disabled', paused ? 'true' : 'false');
        if (state.forceSpawnSubmitting) {
            state.$root.find('[data-lstw-submit-force-spawn]').prop('disabled', true).attr('aria-disabled', 'true');
        }
    }

    function setPaused(paused, persist) {
        materializeLocalClock(state.speed, paused);
        state.paused = !!paused;
        state.playing = !state.paused;
        state.simClock.paused = state.paused;
        updateRuntimeUi();
        updateClock();
        scheduleLoops();
        if (persist) {
            persistRuntime();
        }
    }

    function setSpeed(speed, persist) {
        speed = [1, 2, 5].indexOf(Number(speed)) !== -1 ? Number(speed) : 1;
        materializeLocalClock(speed, state.paused);
        state.speed = speed;
        state.simClock.speed = speed;
        updateRuntimeUi();
        updateClock();
        scheduleLoops();
        if (persist) {
            persistRuntime();
        }
    }

    function applyBootstrapRuntime() {
        var ctx = instanceContext();
        var settings = asObject(ctx.settings);
        state.speed = [1, 2, 5].indexOf(Number(settings.sim_speed_multiplier)) !== -1 ? Number(settings.sim_speed_multiplier) : 1;
        state.paused = String(ctx.sim_state || '') === 'paused' || !!settings.sim_paused;
        state.playing = !state.paused;
        syncSimClock(ctx);
        updateRuntimeUi();
        updateClock();
        scheduleLoops();
    }

    function instanceContext() {
        return asObject((state.bootstrap && state.bootstrap.instance) || {});
    }

    function baseVehicles() {
        return asArray((state.bootstrap && state.bootstrap.base_vehicles) || []);
    }

    function stations() {
        return asArray((state.bootstrap && state.bootstrap.stations) || [])
            .concat(asArray((state.bootstrap && state.bootstrap.neighbor_stations) || []));
    }

    function neighborDispatchCenters() {
        return asArray((state.bootstrap && state.bootstrap.neighbor_dispatch_centers) || []);
    }

    function neighborVehicles() {
        return asArray((state.snapshot && state.snapshot.neighbor_vehicle_availability) || []);
    }

    function neighborSupportRequests() {
        return asArray((state.snapshot && state.snapshot.neighbor_support_requests) || []);
    }

    function hospitals() {
        return asArray(state.hospitals);
    }

    function mergeVehicleLists() {
        var byStatus = {};
        baseVehicles().forEach(function (vehicle) {
            var key = vehicleKey(vehicle);
            if (key) {
                byStatus[key] = $.extend({}, vehicle);
            }
        });

        asArray(state.snapshot && state.snapshot.vehicle_statuses).concat(asArray(state.snapshot && state.snapshot.vehicles)).forEach(function (vehicle) {
            var key = vehicleKey(vehicle);
            if (!key) {
                return;
            }
            byStatus[key] = $.extend({}, byStatus[key] || {}, vehicle);
        });

        return Object.keys(byStatus).map(function (key) {
            return byStatus[key];
        }).sort(function (a, b) {
            return String(a.rufname || '').localeCompare(String(b.rufname || ''), 'de');
        });
    }

    function vehicleGroup(vehicle) {
        var text = String((vehicle && (vehicle.fahrzeugtyp + ' ' + vehicle.rufname + ' ' + vehicle.resource_class)) || '').toLowerCase();
        if (/rth|ith|hubschrauber|heli|christoph/.test(text)) return 'rth';
        if (/thw|technisches hilfswerk/.test(text)) return 'thw';
        if (/fw|feuer|hlf|lf|tlf|dlk|rw|gw-|elw/.test(text)) return 'fw';
        if (/rd|rett|rtw|ktw|nef|naw|krankentransport|notarzt/.test(text)) return 'rd';
        return 'fw';
    }

    function vehicleKey(vehicle) {
        return String(vehicle && (vehicle.unit_key || vehicle.status_id || vehicle.fahrzeug_id) || '');
    }

    function fmsLabel(value) {
        return 'S' + (value || '2');
    }

    function fmsClass(value) {
        var status = String(value || '2');
        return 'lstw-fms lstw-fms--s' + esc(status);
    }

    function incidentAccepted(incident) {
        return String(incident && (incident.call_status || '')) === 'accepted' || String(incident && incident.state) === 'active';
    }

    function incidentPrepared(incident) {
        return String(incident && (incident.disposition_status || (incident.meta && incident.meta.disposition_status) || '')) === 'prepared';
    }

    function incidentHasDispatch(incident) {
        var meta = asObject(incident && incident.meta);
        if (incidentPrepared(incident)) {
            return true;
        }
        if (asArray(incident && incident.assigned_units).length) {
            return true;
        }
        if (String(meta.dispatch_saved_at || '') !== '') {
            return true;
        }
        return ['einsatzcode', 'ausrueckorder', 'einsatzkategorie', 'zusatz_text', 'abholzeit'].some(function (key) {
            return String(meta[key] || '').trim() !== '';
        }) || meta.polizei_verstaendigen === true || meta.polizei_verstaendigen === 1 || meta.polizei_verstaendigen === '1';
    }

    function incidentPlace(incident) {
        var meta = asObject(incident && incident.meta);
        return incident.display_address || meta.generated_address || incident.poi_name_snapshot || '';
    }

    function incidentTitle(incident) {
        return incident.title || [incident.einsatzart, incident.einsatztyp].filter(Boolean).join(' - ') || 'Einsatz';
    }

    function currentIncidents() {
        return asArray(state.snapshot && state.snapshot.incidents);
    }

    function findIncident(id) {
        var all = currentIncidents();
        return all.find(function (incident) {
            return String(incident.id) === String(id);
        }) || null;
    }

    function findVehicle(id) {
        return mergeVehicleLists().find(function (vehicle) {
            return String(vehicle.unit_key || '') === String(id) || String(vehicle.status_id) === String(id) || String(vehicle.fahrzeug_id) === String(id);
        }) || null;
    }

    function lonLat(row) {
        var lon = Number(row && row.longitude);
        var lat = Number(row && row.latitude);
        return Number.isFinite(lon) && Number.isFinite(lat) ? [lon, lat] : null;
    }

    function vehicleLonLat(vehicle) {
        return lonLat(vehicle);
    }

    function vehicleVisibleOnMap(vehicle) {
        if (vehicle && vehicle.support_type) {
            return true;
        }
        return ['1', '3', '5', '7'].indexOf(String(vehicle && vehicle.fms_status)) !== -1;
    }

    function assignmentKeys(assignment) {
        return [assignment && assignment.unit_key, assignment && assignment.status_id, assignment && assignment.fahrzeug_id].filter(function (id) {
            return id !== undefined && id !== null && String(id) !== '' && String(id) !== '0';
        }).map(String);
    }

    function assignmentMap(assignments) {
        var map = {};
        asArray(assignments).forEach(function (assignment) {
            if (!asArray(assignment.route_segments).length && asArray(assignment.route_coordinates).length < 2) {
                return;
            }
            assignmentKeys(assignment).forEach(function (key) {
                map[key] = assignment;
            });
        });
        return map;
    }

    function cleanRouteCoordinates(coordinates) {
        return asArray(coordinates).filter(function (coord) {
            return Array.isArray(coord) && coord.length >= 2 && Number.isFinite(Number(coord[0])) && Number.isFinite(Number(coord[1]));
        }).map(function (coord) {
            return [Number(coord[0]), Number(coord[1])];
        });
    }

    function routePointAtProgress(coordinates, progress) {
        var route = cleanRouteCoordinates(coordinates);
        if (!route.length) {
            return null;
        }
        if (route.length === 1 || progress <= 0) {
            return { point: route[0], index: 0 };
        }
        if (progress >= 1) {
            return { point: route[route.length - 1], index: Math.max(0, route.length - 2) };
        }
        var lengths = [];
        var total = 0;
        for (var i = 1; i < route.length; i += 1) {
            var length = distanceMetersBetweenLonLat(route[i - 1], route[i]);
            lengths.push(length);
            total += length;
        }
        if (total <= 0) {
            return { point: route[route.length - 1], index: Math.max(0, route.length - 2) };
        }
        var target = total * clamp(progress, 0, 1);
        var walked = 0;
        for (var j = 0; j < lengths.length; j += 1) {
            var segmentLength = lengths[j];
            if (walked + segmentLength >= target || j === lengths.length - 1) {
                var ratio = segmentLength > 0 ? (target - walked) / segmentLength : 0;
                var a = route[j];
                var b = route[j + 1];
                return {
                    point: [
                        a[0] + ((b[0] - a[0]) * ratio),
                        a[1] + ((b[1] - a[1]) * ratio)
                    ],
                    index: j
                };
            }
            walked += segmentLength;
        }
        return { point: route[route.length - 1], index: Math.max(0, route.length - 2) };
    }

    function routeSubpathBetweenProgress(coordinates, fromProgress, toProgress) {
        var route = cleanRouteCoordinates(coordinates);
        fromProgress = clamp(Number(fromProgress || 0), 0, 1);
        toProgress = clamp(Number(toProgress || 0), 0, 1);
        if (route.length < 2 || toProgress <= fromProgress) {
            return [];
        }
        var start = routePointAtProgress(route, fromProgress);
        var end = routePointAtProgress(route, toProgress);
        if (!start || !end) {
            return [];
        }
        var path = [start.point];
        for (var i = start.index + 1; i <= end.index && i < route.length; i += 1) {
            path.push(route[i]);
        }
        path.push(end.point);
        return cleanRouteCoordinates(path);
    }

    function routeTailFromProgress(coordinates, progress, current) {
        var route = cleanRouteCoordinates(coordinates);
        if (route.length < 2) {
            return [];
        }
        var point = routePointAtProgress(route, clamp(Number(progress || 0), 0, 1));
        if (!point) {
            return [];
        }
        var tail = [current || point.point].concat(route.slice(point.index + 1));
        return cleanRouteCoordinates(tail);
    }

    function routeSegmentsPathBetween(previousAssignment, currentAssignment) {
        var segments = asArray(currentAssignment && currentAssignment.route_segments).length
            ? asArray(currentAssignment.route_segments)
            : asArray(previousAssignment && previousAssignment.route_segments);
        if (!segments.length) {
            return routeSubpathBetweenProgress(
                asArray(currentAssignment && currentAssignment.route_coordinates).length ? currentAssignment.route_coordinates : previousAssignment && previousAssignment.route_coordinates,
                Number(previousAssignment && previousAssignment.current_progress || 0),
                Number(currentAssignment && currentAssignment.current_progress || 0)
            );
        }
        var fromSegment = Math.max(0, Number(previousAssignment && previousAssignment.current_segment_index) || 0);
        var toSegment = Math.max(0, Number(currentAssignment && currentAssignment.current_segment_index) || 0);
        var fromProgress = clamp(Number(previousAssignment && previousAssignment.current_segment_progress || 0), 0, 1);
        var toProgress = clamp(Number(currentAssignment && currentAssignment.current_segment_progress || 0), 0, 1);
        if (toSegment < fromSegment || (toSegment === fromSegment && toProgress <= fromProgress)) {
            return [];
        }
        var path = [];
        segments.forEach(function (segment, index) {
            if (index < fromSegment || index > toSegment) {
                return;
            }
            var coords = cleanRouteCoordinates(segment && segment.coordinates);
            var part;
            if (index === fromSegment && index === toSegment) {
                part = routeSubpathBetweenProgress(coords, fromProgress, toProgress);
            } else if (index === fromSegment) {
                part = routeTailFromProgress(coords, fromProgress);
            } else if (index === toSegment) {
                part = routeSubpathBetweenProgress(coords, 0, toProgress);
            } else {
                part = coords;
            }
            part.forEach(function (coord) {
                var last = path.length ? path[path.length - 1] : null;
                if (!last || Math.abs(last[0] - coord[0]) > 0.000001 || Math.abs(last[1] - coord[1]) > 0.000001) {
                    path.push(coord);
                }
            });
        });
        return path.length >= 2 ? path : [];
    }

    function prepareVehicleAnimation(previous, snapshot) {
        var previousVehicles = {};
        asArray(previous && previous.vehicles).forEach(function (vehicle) {
            previousVehicles[vehicleKey(vehicle)] = vehicleLonLat(vehicle);
        });
        var previousAssignments = assignmentMap(previous && previous.assignments);
        var currentAssignments = assignmentMap(snapshot && snapshot.assignments);

        asArray(snapshot && snapshot.vehicles).forEach(function (vehicle) {
            var key = vehicleKey(vehicle);
            var end = vehicleLonLat(vehicle);
            var oldAnimation = state.vehicleAnimation[key];
            var start = oldAnimation && oldAnimation.current ? oldAnimation.current : previousVehicles[key];
            if (!key || !end) {
                return;
            }
            if (currentAssignments[key]) {
                var previousAssignment = previousAssignments[key] || currentAssignments[key];
                var path = routeSegmentsPathBetween(previousAssignment, currentAssignments[key]);
                if (path.length >= 2 && start) {
                    path[0] = start;
                }
                state.vehicleAnimation[key] = {
                    current: start || end,
                    start: start || end,
                    end: end,
                    path: path.length >= 2 ? path : null,
                    started: Date.now(),
                    progress: 0,
                    duration: path.length >= 2 ? 11000 : 0
                };
                return;
            }
            if (!start || Math.abs(start[0] - end[0]) < 0.000001 && Math.abs(start[1] - end[1]) < 0.000001) {
                state.vehicleAnimation[key] = {
                    current: end,
                    start: end,
                    end: end,
                    started: Date.now(),
                    duration: 0
                };
                return;
            }
            state.vehicleAnimation[key] = {
                current: start,
                start: start,
                end: end,
                started: Date.now(),
                duration: 11000
            };
        });
    }

    function animatedVehicleCoordinate(vehicle) {
        var animation = state.vehicleAnimation[vehicleKey(vehicle)];
        return animation && animation.current ? animation.current : vehicleLonLat(vehicle);
    }

    function fromLonLat(row) {
        var coord = lonLat(row);
        return coord && typeof ol !== 'undefined' ? ol.proj.fromLonLat(coord) : null;
    }

    function distanceMeters(a, b) {
        var ac = lonLat(a);
        var bc = lonLat(b);
        if (!ac || !bc) {
            return Infinity;
        }
        var rad = Math.PI / 180;
        var lat1 = ac[1] * rad;
        var lat2 = bc[1] * rad;
        var dLat = (bc[1] - ac[1]) * rad;
        var dLon = (bc[0] - ac[0]) * rad;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    function distanceMetersBetweenLonLat(a, b) {
        return distanceMeters(
            { longitude: a && a[0], latitude: a && a[1] },
            { longitude: b && b[0], latitude: b && b[1] }
        );
    }

    function normalizeRouteGeojson(response) {
        var route = response;
        var guard = 0;
        while (route && typeof route === 'object' && guard < 4) {
            if (route.type === 'FeatureCollection' || route.type === 'Feature' || route.geometry || route.features) {
                return fastestRouteGeojson(route);
            }
            if (route.data && typeof route.data === 'object') {
                route = route.data;
                guard += 1;
                continue;
            }
            if (route.route_geojson && typeof route.route_geojson === 'object') {
                route = route.route_geojson;
                guard += 1;
                continue;
            }
            break;
        }
        return null;
    }

    function routeDurationSeconds(feature) {
        var properties = asObject(feature && feature.properties);
        var summary = asObject(properties.summary);
        var duration = Number(summary.duration || properties.duration || 0);
        if (Number.isFinite(duration) && duration > 0) {
            return duration;
        }
        var segments = asArray(properties.segments);
        duration = segments.reduce(function (sum, segment) {
            var value = Number(segment && segment.duration);
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0);
        return duration > 0 ? duration : Number.POSITIVE_INFINITY;
    }

    function fastestRouteGeojson(route) {
        if (!route || route.type !== 'FeatureCollection' || !Array.isArray(route.features) || route.features.length < 2) {
            return route;
        }
        var best = route.features.slice().sort(function (a, b) {
            return routeDurationSeconds(a) - routeDurationSeconds(b);
        })[0];
        if (!best) {
            return route;
        }
        return $.extend({}, route, {
            features: [best]
        });
    }

    function mapVehicles() {
        return asArray(state.snapshot && state.snapshot.vehicles).filter(function (vehicle) {
            return lonLat(vehicle) && vehicleVisibleOnMap(vehicle);
        });
    }

    function selectedVehicleAssignment(id) {
        var key = String(id || state.selectedVehicleId || '');
        if (!key) {
            return null;
        }
        var exact = null;
        var fallback = null;
        asArray(state.snapshot && state.snapshot.assignments).forEach(function (assignment) {
            if (fallback && exact) {
                return;
            }
            if (String(assignment.mission_phase || '') === 'available') {
                return;
            }
            var hasRoute = asArray(assignment.route_coordinates).length >= 2;
            if (!hasRoute) {
                return;
            }
            if (String(assignment.unit_key || '') === key) {
                exact = assignment;
                return;
            }
            if (String(assignment.status_id || '') === key) {
                exact = assignment;
                return;
            }
            if (!fallback && String(assignment.fahrzeug_id || '') === key) {
                fallback = assignment;
            }
        });
        return exact || fallback;
    }

    function remainingRouteCoordinates(assignment) {
        var segments = asArray(assignment && assignment.route_segments).map(function (segment) {
            return $.extend({}, segment, {
                coordinates: cleanRouteCoordinates(segment && segment.coordinates)
            });
        }).filter(function (segment) {
            return segment.coordinates.length >= 2;
        });
        var selectedVehicle = findVehicle(state.selectedVehicleId);
        var selectedAnimation = selectedVehicle ? state.vehicleAnimation[vehicleKey(selectedVehicle)] : null;
        var animatedTail = [];
        if (selectedAnimation && selectedAnimation.path && selectedAnimation.path.length >= 2 && selectedAnimation.progress < 1) {
            animatedTail = routeTailFromProgress(selectedAnimation.path, selectedAnimation.progress, selectedAnimation.current);
        }
        if (segments.length) {
            var segmentIndex = Math.max(0, Math.min(segments.length - 1, Number(assignment && assignment.current_segment_index) || 0));
            var segmentProgress = clamp(Number(assignment && assignment.current_segment_progress || 0), 0, 1);
            var last = asObject(assignment && assignment.last_position);
            var current = Number.isFinite(Number(last.longitude)) && Number.isFinite(Number(last.latitude))
                ? [Number(last.longitude), Number(last.latitude)]
                : null;
            var combined = [];
            var append = function (coord) {
                if (!Array.isArray(coord) || coord.length < 2) {
                    return;
                }
                var point = [Number(coord[0]), Number(coord[1])];
                var previous = combined.length ? combined[combined.length - 1] : null;
                if (previous && Math.abs(previous[0] - point[0]) < 0.000001 && Math.abs(previous[1] - point[1]) < 0.000001) {
                    return;
                }
                combined.push(point);
            };

            animatedTail.forEach(append);
            segments.forEach(function (segment, index) {
                if (index < segmentIndex) {
                    return;
                }
                var coords = segment.coordinates;
                if (index === segmentIndex) {
                    if (segmentProgress >= 1) {
                        return;
                    }
                    routeTailFromProgress(coords, segmentProgress, animatedTail.length ? null : current).forEach(append);
                    return;
                }
                coords.forEach(append);
            });
            return combined.length >= 2 ? combined : [];
        }

        var route = cleanRouteCoordinates(assignment && assignment.route_coordinates);
        if (route.length < 2) {
            return [];
        }
        var progress = clamp(Number(assignment.current_progress || 0), 0, 1);
        if (progress >= 1) {
            return [];
        }
        var remaining = animatedTail.slice();
        var last = asObject(assignment.last_position);
        var current = Number.isFinite(Number(last.longitude)) && Number.isFinite(Number(last.latitude))
            ? [Number(last.longitude), Number(last.latitude)]
            : null;
        routeTailFromProgress(route, progress, animatedTail.length ? null : current).forEach(function (coord) {
            var previous = remaining.length ? remaining[remaining.length - 1] : null;
            if (!previous || Math.abs(previous[0] - coord[0]) > 0.000001 || Math.abs(previous[1] - coord[1]) > 0.000001) {
                remaining.push(coord);
            }
        });
        if (!remaining.length && current) {
            remaining.push(current);
        }
        return remaining.length >= 2 ? remaining : [];
    }

    function drawSelectedVehicleRoute() {
        if (!state.map.routeSource || typeof ol === 'undefined') {
            return;
        }
        state.map.routeSource.clear();
        var assignment = selectedVehicleAssignment();
        var route = remainingRouteCoordinates(assignment);
        if (route.length < 2) {
            return;
        }
        var feature = new ol.Feature({
            geometry: new ol.geom.LineString(route.map(function (coord) {
                return ol.proj.fromLonLat(coord);
            })),
            type: 'route',
            data: assignment
        });
        state.map.routeSource.addFeature(feature);
    }

    function stationVehicles(station) {
        var stationId = String(station && station.id || '');
        if (station && station.is_neighbor) {
            return neighborVehicles().filter(function (vehicle) {
                return String(vehicle.wache_id || '') === stationId;
            });
        }
        return mergeVehicleLists().filter(function (vehicle) {
            return String(vehicle.wache_id || '') === stationId;
        });
    }

    function stationPopupHtml(station) {
        var vehicles = stationVehicles(station);
        var rows = vehicles.length ? vehicles.map(function (vehicle) {
            var available = station && station.is_neighbor ? !!vehicle.available : true;
            var stateText = station && station.is_neighbor ? (vehicle.availability_state || (available ? 'verfügbar' : 'nicht verfügbar')) : fmsLabel(vehicle.fms_status);
            return '<li class="' + (available ? '' : 'is-muted') + '"><strong>' + esc(vehicle.rufname || 'Fahrzeug') + '</strong><span>' + esc(vehicle.fahrzeugtyp || vehicle.resource_class_label || '') + '</span><em>' + esc(stateText) + '</em></li>';
        }).join('') : '<li><span>Keine Fahrzeuge stationiert.</span></li>';
        var support = station && station.is_neighbor
            ? '<button type="button" data-lstw-support-neighbor="' + esc(station.nebenleitstelle_id || '') + '">Unterstützung bei dieser Leitstelle anfragen</button>'
            : '';
        var title = station && station.is_neighbor
            ? (station.name || 'Nachbarwache') + ' (' + (station.nebenleitstelle_name || 'Nachbarleitstelle') + ')'
            : (station.name || 'Wache');
        return '<div class="lstw-map-card' + (station && station.is_neighbor ? ' lstw-map-card--neighbor' : '') + '"><div class="lstw-map-card__head"><strong>' + esc(title) + '</strong><button type="button" data-lstw-map-card-close aria-label="Kartenhinweis schließen">x</button></div><small>' + esc(station.typ || '') + '</small><ul>' + rows + '</ul>' + support + '</div>';
    }

    function departmentLabel(code) {
        var key = String(code || '').toUpperCase();
        var config = asObject(state.departmentConfig[key] || state.departmentConfig[code]);
        return config.label || config.name || key;
    }

    function departmentColor(code) {
        var key = String(code || '').toUpperCase();
        var config = asObject(state.departmentConfig[key] || state.departmentConfig[code]);
        var color = String(config.color || '').trim();
        return /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(color) ? color : '';
    }

    function hospitalPopupHtml(hospital) {
        var departments = asArray(hospital && hospital.departments);
        var badges = departments.length ? departments.slice(0, 16).map(function (code) {
            var color = departmentColor(code);
            return '<span class="lstw-badge lstw-department"' + (color ? ' style="--dept-color:' + esc(color) + '"' : '') + '>' + esc(departmentLabel(code)) + '</span>';
        }).join('') : '<span class="lstw-badge">Keine Fachbereiche hinterlegt</span>';
        var helipad = hospital.helipad ? '<small>Landeplatz vorhanden</small>' : '';
        return '<div class="lstw-map-card"><div class="lstw-map-card__head"><strong>' + esc(hospital.name || 'Krankenhaus') + '</strong><button type="button" data-lstw-map-card-close aria-label="Kartenhinweis schließen">x</button></div>' +
            helipad +
            '<div class="lstw-badges">' + badges + '</div></div>';
    }

    function clearSource(name) {
        var source = state.map.sources[name];
        if (source) {
            source.clear();
        }
    }

    function featureColor(type, data) {
        if (type === 'vehicle') {
            if (data && data.support_type === 'neighbor') return '#0f766e';
            var fms = String(data && data.fms_status || '2');
            return { 1: '#64748b', 2: '#22c55e', 3: '#f59e0b', 4: '#ef4444', 5: '#3b82f6', 6: '#334155', 7: '#7c3aed', 8: '#0891b2' }[fms] || '#22c55e';
        }
        if (type === 'incident') {
            return String(data && data.einsatzart) === 'FW' ? '#ef4444' : '#3b82f6';
        }
        if (type === 'hospital') return '#06b6d4';
        if (type === 'poi') return '#c084fc';
        if (type === 'station') {
            if (data && data.is_neighbor) return '#94a3b8';
            return { rd: '#22c55e', fw: '#ef4444', thw: '#3b82f6' }[String(data && data.kind || '').toLowerCase()] || '#f59e0b';
        }
        return '#94a3b8';
    }

    function signalActive(vehicle) {
        return !!(vehicle && (Number(vehicle.sondersignal) === 1 || vehicle.sondersignal === true || String(vehicle.sondersignal) === '1'));
    }

    function signalLights(vehicle) {
        var lights = asArray(vehicle && vehicle.signal_lights).filter(function (light) {
            return Number.isFinite(Number(light.x)) && Number.isFinite(Number(light.y));
        });
        if (lights.length) {
            return lights;
        }
        var type = String(vehicle && (vehicle.fahrzeugtyp + ' ' + vehicle.rufname) || '').toUpperCase();
        if (/\b(POL|POLIZEI|STREIFEN)\b/.test(type)) {
            return [
                { x: 0.42, y: 0.18, type: 'bar', interval: 360, phase: 0, size: 1 },
                { x: 0.58, y: 0.18, type: 'bar', interval: 360, phase: 180, size: 1 }
            ];
        }
        if (/\b(HLF|LF|TLF|DLK|ELW|RW|GW|MTW|FEUERWEHR)\b/.test(type)) {
            return [
                { x: 0.34, y: 0.18, type: 'beacon', interval: 440, phase: 0, size: 1 },
                { x: 0.50, y: 0.16, type: 'beacon', interval: 520, phase: 170, size: 0.9 },
                { x: 0.66, y: 0.18, type: 'beacon', interval: 440, phase: 260, size: 1 }
            ];
        }
        return [
            { x: 0.38, y: 0.18, type: 'beacon', interval: 420, phase: 0, size: 1 },
            { x: 0.62, y: 0.18, type: 'beacon', interval: 420, phase: 210, size: 1 }
        ];
    }

    function pluginBaseUrl() {
        var script = document.querySelector('script[src*="/js/simulation-workspace.js"]');
        if (!script || !script.src) return '';
        return script.src.split('/js/simulation-workspace.js')[0].replace(/\/?$/, '/');
    }

    function signalSpriteMap() {
        var configured = window.lsttrainingWorkspace && lsttrainingWorkspace.signal_sprite_urls ? lsttrainingWorkspace.signal_sprite_urls : {};
        var base = pluginBaseUrl();
        return {
            beacon: configured.beacon || (base ? base + 'img/signal/beacon.svg' : ''),
            strobe: configured.strobe || (base ? base + 'img/signal/strobe.svg' : ''),
            bar: configured.bar || (base ? base + 'img/signal/lightbar.svg' : ''),
            glow: configured.glow || (base ? base + 'img/signal/glow.svg' : '')
        };
    }

    function signalSpriteUrl(type) {
        var key = String(type || 'beacon');
        var map = signalSpriteMap();
        if (map[key] && !state.failedSignalSprites[key]) {
            preloadSignalSprite(key, map[key]);
            return map[key];
        }
        return fallbackSignalSpriteUrl(key);
    }

    function preloadSignalSprite(key, url) {
        state.signalSpritePreload = state.signalSpritePreload || {};
        if (!url || state.signalSpritePreload[key]) {
            return;
        }
        state.signalSpritePreload[key] = true;
        var image = new Image();
        image.onerror = function () {
            state.failedSignalSprites[key] = true;
            if (state.map.layers.vehicles) {
                state.map.layers.vehicles.changed();
            }
        };
        image.src = url;
    }

    function fallbackSignalSpriteUrl(type) {
        state.signalSprites = state.signalSprites || {};
        var key = String(type || 'beacon');
        if (state.signalSprites[key]) {
            return state.signalSprites[key];
        }
        var canvas = document.createElement('canvas');
        canvas.width = key === 'bar' ? 96 : 64;
        canvas.height = 64;
        var ctx = canvas.getContext('2d');
        var cx = canvas.width / 2;
        var cy = canvas.height / 2;
        var glow = ctx.createRadialGradient(cx, cy, 2, cx, cy, 31);
        glow.addColorStop(0, 'rgba(147,197,253,0.95)');
        glow.addColorStop(0.35, 'rgba(37,99,235,0.75)');
        glow.addColorStop(1, 'rgba(37,99,235,0)');
        ctx.fillStyle = glow;
        ctx.beginPath();
        ctx.arc(cx, cy, 31, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#1d4ed8';
        ctx.strokeStyle = 'rgba(255,255,255,0.92)';
        ctx.lineWidth = 4;
        if (key === 'bar') {
            ctx.beginPath();
            ctx.moveTo(cx - 20, cy - 10);
            ctx.lineTo(cx + 20, cy - 10);
            ctx.quadraticCurveTo(cx + 30, cy - 10, cx + 30, cy);
            ctx.quadraticCurveTo(cx + 30, cy + 10, cx + 20, cy + 10);
            ctx.lineTo(cx - 20, cy + 10);
            ctx.quadraticCurveTo(cx - 30, cy + 10, cx - 30, cy);
            ctx.quadraticCurveTo(cx - 30, cy - 10, cx - 20, cy - 10);
            ctx.closePath();
            ctx.fill();
            ctx.stroke();
            ctx.fillStyle = 'rgba(191,219,254,0.95)';
            ctx.fillRect(cx - 18, cy - 5, 13, 10);
            ctx.fillRect(cx + 5, cy - 5, 13, 10);
        } else if (key === 'strobe') {
            ctx.beginPath();
            ctx.ellipse(cx, cy, 24, 11, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        } else {
            ctx.beginPath();
            ctx.arc(cx, cy, 14, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        }
        state.signalSprites[key] = canvas.toDataURL('image/png');
        return state.signalSprites[key];
    }

    function signalLightOpacity(light) {
        var interval = Math.max(120, Number(light && light.interval) || 420);
        var phase = Math.max(0, Number(light && light.phase) || 0);
        var tick = (Date.now() + phase) % interval;
        return tick < interval * 0.45 ? 1 : 0.18;
    }

    function preloadVehicleImageDimensions(url) {
        url = String(url || '');
        if (!url || state.vehicleImageDimensions[url] || state.vehicleImagePreload[url]) {
            return;
        }
        state.vehicleImagePreload[url] = true;
        var image = new Image();
        image.onload = function () {
            state.vehicleImageDimensions[url] = {
                width: image.naturalWidth || image.width || 0,
                height: image.naturalHeight || image.height || 0
            };
            if (state.map.layers.vehicles) {
                state.map.layers.vehicles.changed();
            }
        };
        image.onerror = function () {
            state.vehicleImageDimensions[url] = { width: 0, height: 0 };
        };
        image.src = url;
    }

    function vehicleImageRenderSize(url, iconScale) {
        preloadVehicleImageDimensions(url);
        var dimensions = state.vehicleImageDimensions[String(url || '')] || {};
        var width = Number(dimensions.width) || 0;
        var height = Number(dimensions.height) || 0;
        if (width <= 0 || height <= 0) {
            // Temporary fallback until the image has loaded; replaced automatically by layer.changed().
            width = 600;
            height = 360;
        }
        return {
            width: width * iconScale,
            height: height * iconScale
        };
    }

    function signalLightScale(type, renderSize, size) {
        var sourceWidth = type === 'bar' ? 512 : 256;
        var editorReferenceWidth = 520;
        var editorSpriteWidth = type === 'bar' ? 44 : 30;
        var vehicleWidth = Math.max(1, Number(renderSize && renderSize.width) || 1);
        var targetWidth = vehicleWidth * (editorSpriteWidth / editorReferenceWidth) * size;
        return Math.max(0.006, targetWidth / sourceWidth);
    }

    function signalLightStyles(vehicle, imageUrl, iconScale, anchor) {
        if (!signalActive(vehicle)) {
            return [];
        }
        var renderSize = vehicleImageRenderSize(imageUrl, iconScale);
        var anchorX = Number(anchor && anchor[0]);
        var anchorY = Number(anchor && anchor[1]);
        if (!Number.isFinite(anchorX)) anchorX = 0.5;
        if (!Number.isFinite(anchorY)) anchorY = 0.85;
        return signalLights(vehicle).map(function (light) {
            var type = String(light.type || 'beacon');
            var size = Math.max(0.4, Math.min(2.5, Number(light.size) || 1));
            var x = Math.max(0, Math.min(1, Number(light.x)));
            var y = Math.max(0, Math.min(1, Number(light.y)));
            if (!Number.isFinite(x)) x = 0.5;
            if (!Number.isFinite(y)) y = 0.5;
            var dx = (x - anchorX) * renderSize.width;
            var dy = (anchorY - y) * renderSize.height;
            return new ol.style.Style({
                image: new ol.style.Icon({
                    src: signalSpriteUrl(type),
                    imgSize: type === 'bar' ? [512, 256] : [256, 256],
                    opacity: signalLightOpacity(light),
                    scale: signalLightScale(type, renderSize, size),
                    anchor: [0.5, 0.5],
                    anchorXUnits: 'fraction',
                    anchorYUnits: 'fraction',
                    displacement: [dx, dy]
                })
            });
        });
    }

    function signalPulseStyle(vehicle, selected) {
        if (!signalActive(vehicle)) {
            return null;
        }
        var opacity = 0.22 + (signalLightOpacity({ interval: 520, phase: 0 }) * 0.22);
        return new ol.style.Style({
            image: new ol.style.Circle({
                radius: selected ? 18 : 15,
                fill: new ol.style.Fill({ color: 'rgba(37, 99, 235, ' + opacity.toFixed(2) + ')' }),
                stroke: new ol.style.Stroke({ color: 'rgba(147, 197, 253, 0.85)', width: 2 })
            })
        });
    }

    function vehicleStyle(vehicle, selected) {
        var imageUrl = vehicle && vehicle.image_url || '';
        var mode = vehicleMarkerMode(state.snapshot || {});
        if (((vehicle && vehicle.support_type) || mode === 'image') && imageUrl && supportsMapImage(imageUrl)) {
            var isSupport = vehicle && vehicle.support_type;
            var iconScale = isSupport ? (selected ? 0.11 : 0.085) : (selected ? 0.12 : 0.09);
            var iconAnchor = isSupport ? [0.5, 0.68] : [0.5, 0.85];
            var labelOffsetY = isSupport ? 10 : 18;
            var styles = [new ol.style.Style({
                image: new ol.style.Icon({
                    src: imageUrl,
                    scale: iconScale,
                    anchor: iconAnchor,
                    anchorXUnits: 'fraction',
                    anchorYUnits: 'fraction',
                    crossOrigin: 'anonymous'
                }),
                text: new ol.style.Text({
                    text: vehicle.rufname || '',
                    offsetY: labelOffsetY,
                    textAlign: 'center',
                    textBaseline: 'top',
                    font: '700 11px Arial, sans-serif',
                    fill: new ol.style.Fill({ color: '#ffffff' }),
                    stroke: new ol.style.Stroke({ color: '#06111c', width: 4 })
                })
            })];
            return styles.concat(signalLightStyles(vehicle, imageUrl, iconScale, iconAnchor));
        }

        var color = featureColor('vehicle', vehicle);
        if (mode === 'tactical') {
            var tacticalStyles = [];
            var tacticalPulse = signalPulseStyle(vehicle, selected);
            if (tacticalPulse) tacticalStyles.push(tacticalPulse);
            tacticalStyles.push(new ol.style.Style({
                image: new ol.style.RegularShape({
                    points: 4,
                    radius: selected ? 16 : 13,
                    angle: Math.PI / 4,
                    fill: new ol.style.Fill({ color: '#ffffff' }),
                    stroke: new ol.style.Stroke({ color: selected ? '#fef08a' : color, width: 4 })
                }),
                text: new ol.style.Text({
                    text: tacticalVehicleLetter(vehicle),
                    font: '800 10px Arial, sans-serif',
                    fill: new ol.style.Fill({ color: '#111111' }),
                    stroke: new ol.style.Stroke({ color: '#ffffff', width: 2 }),
                    offsetY: 0
                })
            }));
            return tacticalStyles;
        }

        var markerStyles = [];
        var markerPulse = signalPulseStyle(vehicle, selected);
        if (markerPulse) markerStyles.push(markerPulse);
        markerStyles.push(new ol.style.Style({
            image: new ol.style.Circle({
                radius: selected ? 11 : 9,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: selected ? '#fef08a' : '#ffffff', width: selected ? 3 : 2 })
            }),
            text: new ol.style.Text({
                text: vehicle && vehicle.rufname || '',
                offsetY: -22,
                font: '700 12px Arial, sans-serif',
                fill: new ol.style.Fill({ color: '#ffffff' }),
                stroke: new ol.style.Stroke({ color: '#06111c', width: 4 })
            })
        }));
        return markerStyles;
    }

    function pointStyle(type, data, selected) {
        if (type === 'vehicle') {
            return vehicleStyle(data, selected);
        }
        var color = featureColor(type, data);
        var radius = selected ? 12 : 9;
        var shape = new ol.style.Circle({
            radius: radius,
            fill: new ol.style.Fill({ color: color }),
            stroke: new ol.style.Stroke({ color: selected ? '#fef08a' : '#ffffff', width: selected ? 3 : 2 })
        });
        if (type === 'station') {
            shape = new ol.style.RegularShape({
                points: 4,
                radius: radius,
                angle: Math.PI / 4,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: data && data.is_neighbor ? '#cbd5e1' : '#ffffff', width: data && data.is_neighbor ? 1 : 2 })
            });
        }
        if (type === 'incident') {
            shape = new ol.style.RegularShape({
                points: 3,
                radius: radius + 2,
                angle: 0,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: selected ? '#fef08a' : '#ffffff', width: selected ? 3 : 2 })
            });
        }
        if (type === 'hospital') {
            shape = new ol.style.Circle({
                radius: selected ? 13 : 11,
                fill: new ol.style.Fill({ color: color }),
                stroke: new ol.style.Stroke({ color: '#ffffff', width: selected ? 3 : 2 })
            });
        }

        return new ol.style.Style({
            image: shape,
            text: new ol.style.Text({
                text: type === 'hospital' ? 'H' : (type === 'station' ? '' : (data && data.label ? String(data.label).slice(0, 18) : '')),
                offsetY: type === 'hospital' ? 1 : -22,
                font: '700 11px Arial, sans-serif',
                fill: new ol.style.Fill({ color: '#ffffff' }),
                stroke: new ol.style.Stroke({ color: type === 'hospital' ? color : '#07111f', width: type === 'hospital' ? 1 : 4 })
            })
        });
    }

    function ensureMaps() {
        var target = state.$root.find('[data-lstw-map]').get(0);
        if (!target || typeof ol === 'undefined' || state.map.main) {
            return;
        }

        ['stations', 'vehicles', 'incidents', 'hospitals', 'pois'].forEach(function (name) {
            state.map.sources[name] = new ol.source.Vector();
        });
        state.map.routeSource = new ol.source.Vector();
        state.map.homeExtentSource = new ol.source.Vector();

        state.map.layers.base = new ol.layer.Tile({ source: new ol.source.OSM() });
        state.map.layers.routes = new ol.layer.Vector({
            source: state.map.routeSource,
            style: new ol.style.Style({
                stroke: new ol.style.Stroke({ color: '#38bdf8', width: 5 })
            })
        });
        state.map.layers.stations = new ol.layer.Vector({
            source: state.map.sources.stations,
            style: function (feature) { return pointStyle('station', feature.get('data'), false); }
        });
        state.map.layers.vehicles = new ol.layer.Vector({
            source: state.map.sources.vehicles,
            style: function (feature) {
                var vehicle = feature.get('data');
                return pointStyle('vehicle', vehicle, vehicleKey(vehicle) === state.selectedVehicleId);
            }
        });
        state.map.layers.incidents = new ol.layer.Vector({
            source: state.map.sources.incidents,
            style: function (feature) {
                var incident = feature.get('data');
                return pointStyle('incident', incident, String(incident && incident.id) === String(state.selectedIncidentId));
            }
        });
        state.map.layers.hospitals = new ol.layer.Vector({
            source: state.map.sources.hospitals,
            style: function (feature) { return pointStyle('hospital', feature.get('data'), false); }
        });
        state.map.layers.pois = new ol.layer.Vector({
            source: state.map.sources.pois,
            visible: false,
            style: function (feature) { return pointStyle('poi', feature.get('data'), false); }
        });
        state.map.main = new ol.Map({
            target: target,
            layers: [
                state.map.layers.base,
                state.map.layers.routes,
                state.map.layers.stations,
                state.map.layers.hospitals,
                state.map.layers.pois,
                state.map.layers.incidents,
                state.map.layers.vehicles
            ],
            view: new ol.View({
                center: ol.proj.fromLonLat([13.0624, 52.4009]),
                zoom: 11
            })
        });

        state.map.main.on('singleclick', function (event) {
            var hit = state.map.main.forEachFeatureAtPixel(event.pixel, function (feature) {
                var type = feature.get('type');
                var data = feature.get('data');
                if (type === 'incident' && data) {
                    selectIncident(data.id, true);
                    return true;
                }
                if (type === 'vehicle' && data) {
                    selectVehicle(vehicleKey(data), false);
                    return true;
                }
                if (type === 'station' && data) {
                    setMapStatusHtml(stationPopupHtml(data), false);
                    return true;
                }
                if (type === 'hospital' && data) {
                    setMapStatusHtml(hospitalPopupHtml(data), false);
                    return true;
                }
                return false;
            });
            if (!hit) {
                selectVehicle('', false);
            }
        });

        initMapResizeObserver();
        scheduleMapResizeBurst();
    }

    function updateMapSizes() {
        if (state.map.main) {
            state.map.main.updateSize();
        }
    }

    function scheduleMapResizeBurst() {
        [0, 50, 150, 350, 800].forEach(function (delay) {
            window.setTimeout(updateMapSizes, delay);
        });
    }

    function initMapResizeObserver() {
        if (state.resizeObserver || typeof ResizeObserver === 'undefined') {
            return;
        }
        var targets = [
            state.$root.find('[data-lstw-map]').get(0),
            state.$root.find('[data-lstw-panel="map"]').get(0),
            state.$root.find('[data-lstw-board]').get(0)
        ].filter(Boolean);
        if (!targets.length) {
            return;
        }
        state.resizeObserver = new ResizeObserver(function () {
            scheduleMapResizeBurst();
        });
        targets.forEach(function (target) {
            state.resizeObserver.observe(target);
        });
    }

    function leitstelleExtent() {
        if (!state.map.main || typeof ol === 'undefined') {
            return null;
        }
        var ctx = instanceContext();
        var geojson = ctx.leitstelle_geojson || ctx.geojson || '';
        if (!geojson) {
            return null;
        }
        try {
            var decoded = typeof geojson === 'string' ? JSON.parse(geojson) : geojson;
            var features = new ol.format.GeoJSON().readFeatures(decoded, {
                dataProjection: 'EPSG:4326',
                featureProjection: state.map.main.getView().getProjection()
            });
            var extent = ol.extent.createEmpty();
            features.forEach(function (feature) {
                if (feature.getGeometry()) {
                    ol.extent.extend(extent, feature.getGeometry().getExtent());
                }
            });
            return ol.extent.isEmpty(extent) ? null : extent;
        } catch (error) {
            return null;
        }
    }

    function fitHomeExtent() {
        var home = leitstelleExtent();
        if (home) {
            state.map.main.getView().fit(home, { padding: [32, 32, 32, 32], maxZoom: 13, duration: 250 });
            state.map.hasFit = true;
            setMapStatus('', false);
            return true;
        }
        var ctx = instanceContext();
        var center = fromLonLat({ longitude: ctx.leitstelle_longitude, latitude: ctx.leitstelle_latitude });
        if (center) {
            state.map.main.getView().setCenter(center);
            state.map.main.getView().setZoom(11);
            state.map.hasFit = true;
            return true;
        }
        return false;
    }

    function renderMap() {
        ensureMaps();
        if (!state.map.main) {
            return;
        }

        var extent = ol.extent.createEmpty();
        ['stations', 'vehicles', 'incidents', 'hospitals', 'pois'].forEach(clearSource);

        stations().forEach(function (station) {
            var coords = fromLonLat(station);
            if (!coords) return;
            var feature = new ol.Feature({ geometry: new ol.geom.Point(coords), type: 'station', data: $.extend({ label: station.name }, station) });
            state.map.sources.stations.addFeature(feature);
            ol.extent.extend(extent, feature.getGeometry().getExtent());
        });
        neighborDispatchCenters().forEach(function (center) {
            var coords = fromLonLat(center);
            if (!coords) return;
            var data = $.extend({
                id: 'neighbor-center-' + center.id,
                name: center.name,
                typ: 'Nachbarleitstelle',
                is_neighbor: true,
                is_neighbor_center: true,
                nebenleitstelle_id: center.id,
                nebenleitstelle_name: center.name,
                label: center.name
            }, center);
            var feature = new ol.Feature({ geometry: new ol.geom.Point(coords), type: 'station', data: data });
            state.map.sources.stations.addFeature(feature);
            ol.extent.extend(extent, feature.getGeometry().getExtent());
        });

        mapVehicles().forEach(function (vehicle) {
            var lonLatValue = animatedVehicleCoordinate(vehicle);
            var coords = lonLatValue ? ol.proj.fromLonLat(lonLatValue) : null;
            if (!coords) return;
            var feature = new ol.Feature({ geometry: new ol.geom.Point(coords), type: 'vehicle', data: $.extend({ label: vehicle.rufname }, vehicle) });
            state.map.sources.vehicles.addFeature(feature);
        });

        hospitals().forEach(function (hospital) {
            var coords = fromLonLat(hospital);
            if (!coords) return;
            var feature = new ol.Feature({ geometry: new ol.geom.Point(coords), type: 'hospital', data: $.extend({ label: hospital.name }, hospital) });
            state.map.sources.hospitals.addFeature(feature);
        });

        currentIncidents().filter(incidentAccepted).forEach(function (incident) {
            var coords = fromLonLat(incident);
            if (!coords) return;
            var feature = new ol.Feature({ geometry: new ol.geom.Point(coords), type: 'incident', data: $.extend({ label: incident.einsatztyp || incident.einsatzart }, incident) });
            state.map.sources.incidents.addFeature(feature);
            ol.extent.extend(extent, feature.getGeometry().getExtent());
        });

        Object.keys(state.map.visible).forEach(function (name) {
            if (state.map.layers[name]) {
                state.map.layers[name].setVisible(!!state.map.visible[name]);
            }
        });
        drawSelectedVehicleRoute();

        if (!state.map.hasFit) {
            fitHomeExtent();
        }
        startVehicleAnimationLoop();
    }

    function startVehicleAnimationLoop() {
        if (state.animationFrame || !state.map.main || !state.map.sources.vehicles) {
            return;
        }

        var step = function () {
            var now = Date.now();
            var active = false;
            var hasSignal = false;
            Object.keys(state.vehicleAnimation).forEach(function (key) {
                var animation = state.vehicleAnimation[key];
                if (!animation || !animation.end) {
                    return;
                }
                if (!animation.duration) {
                    animation.current = animation.end;
                    animation.progress = 1;
                    return;
                }
                var progress = Math.min(1, Math.max(0, (now - animation.started) / animation.duration));
                animation.progress = progress;
                if (animation.path && animation.path.length >= 2) {
                    var point = routePointAtProgress(animation.path, progress);
                    animation.current = point && point.point ? point.point : animation.end;
                } else {
                    animation.current = [
                        animation.start[0] + ((animation.end[0] - animation.start[0]) * progress),
                        animation.start[1] + ((animation.end[1] - animation.start[1]) * progress)
                    ];
                }
                if (progress < 1) {
                    active = true;
                }
            });

            state.map.sources.vehicles.getFeatures().forEach(function (feature) {
                var vehicle = feature.get('data');
                if (signalActive(vehicle)) {
                    hasSignal = true;
                }
                var coord = animatedVehicleCoordinate(vehicle);
                if (coord) {
                    feature.getGeometry().setCoordinates(ol.proj.fromLonLat(coord));
                }
            });
            if (hasSignal && state.map.layers.vehicles) {
                state.map.layers.vehicles.changed();
            }
            drawSelectedVehicleRoute();

            if (active || hasSignal) {
                state.animationFrame = window.requestAnimationFrame(step);
            } else {
                state.animationFrame = null;
            }
        };

        state.animationFrame = window.requestAnimationFrame(step);
    }

    function renderVehicles() {
        var vehicles = mergeVehicleLists().filter(function (vehicle) {
            return !(vehicle && vehicle.support_type);
        });
        var search = state.vehicleSearch.toLowerCase();
        var filtered = vehicles.filter(function (vehicle) {
            var matchesGroup = state.vehicleFilter === 'all' || vehicleGroup(vehicle) === state.vehicleFilter;
            var haystack = [vehicle.rufname, vehicle.fahrzeugtyp, vehicle.wache_name, vehicle.status, vehicle.bemerkung].join(' ').toLowerCase();
            return matchesGroup && (!search || haystack.indexOf(search) !== -1);
        });
        var html = filtered.length ? filtered.map(function (vehicle) {
            var id = vehicleKey(vehicle);
            var selected = id && id === state.selectedVehicleId;
            var assignment = activeDispatchAssignment(vehicle);
            var radioAction = vehicleRadioCommands(assignment).length
                ? '<button type="button" class="lstw-vehicle-radio-btn" data-lstw-open-vehicle-radio="' + esc(id) + '" title="Funksprüche" aria-label="Funksprüche"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i></button>'
                : '';
            var detailText = [vehicle.fahrzeugtyp || 'Fahrzeug', vehicle.wache_name || 'Keine Wache', vehicle.bemerkung || ''].filter(Boolean).join(' | ');
            var line = [vehicle.fahrzeugtyp || 'Fahrzeug', vehicle.wache_name || 'Keine Wache'].filter(Boolean).join(' | ');
            return '<article class="lstw-card lstw-vehicle-card' + (selected ? ' is-selected' : '') + '" data-lstw-focus-vehicle="' + esc(id) + '">' +
                '<div class="lstw-card-main">' +
                    '<strong title="' + esc(detailText) + '">' + esc(vehicle.rufname || ('Fahrzeug ' + id)) + '</strong>' +
                    '<span>' + esc(line) + '</span>' +
                '</div>' +
                '<span class="' + fmsClass(vehicle.fms_status) + '">' + esc(fmsLabel(vehicle.fms_status)) + '</span>' +
                radioAction +
            '</article>';
        }).join('') : '<p class="lstw-empty">' + esc(lsttrainingWorkspace.texts.emptyVehicles) + '</p>';

        state.$root.find('[data-lstw-vehicle-list]').html(html);
    }

    function incidentStatusLabel(incident) {
        if (String(incident.state) === 'closed') return 'erledigt';
        if (!incidentAccepted(incident)) return 'wartend';
        if (!incidentPrepared(incident)) return 'angenommen';
        return incident.operational_status_label || 'aktiv';
    }

    function incidentPriority(incident) {
        var meta = asObject(incident && incident.meta);
        return meta.priority || meta.prioritaet || (incident.has_missing_resources ? 'hoch' : 'normal');
    }

    function incidentsForTab() {
        return currentIncidents().filter(function (incident) {
            return incidentAccepted(incident) && incidentPrepared(incident);
        });
    }

    function patientProgress(patient) {
        var value = Number(patient && (patient.care_progress_percent || patient.progress_percent || patient.percent));
        return Number.isFinite(value) ? clamp(Math.round(value), 0, 100) : 0;
    }

    function patientTriageClass(patient) {
        var triage = String(patient && patient.triage_category || '').toLowerCase();
        return triage ? ' triage-' + esc(triage) : '';
    }

    function patientRowsHtml(incident, compact) {
        if (!incident || !incident.first_arrival_report_opened) {
            return '';
        }
        var patients = asArray(incident && incident.patients);
        if (!patients.length) {
            return '';
        }
        return '<div class="' + (compact ? 'lstw-patient-list lstw-patient-list--compact' : 'lstw-patient-list') + '">' + patients.map(function (patient) {
            var triage = String(patient.triage_category || '').toUpperCase();
            var label = patient.label || 'Patient';
            var status = patient.status_label || patient.patient_status || 'Status offen';
            var injury = patient.injury_summary || patient.description || '';
            var progress = patientProgress(patient);
            return '<div class="lstw-patient' + patientTriageClass(patient) + '">' +
                '<b>' + esc(triage || '-') + '</b>' +
                '<span><strong>' + esc(label) + '</strong>' + (injury ? '<small>' + esc(injury) + '</small>' : '') + '</span>' +
                '<em>' + esc(status) + '</em>' +
                '<i title="Versorgungsstatus">' + esc(progress) + '%</i>' +
            '</div>';
        }).join('') + '</div>';
    }

    function setDetailPanelVisible(visible) {
        var isVisible = !!visible;
        var $panel = state.$root.find('[data-lstw-panel="details"]');
        $panel.toggleClass('is-open', isVisible);
        if (isVisible) {
            $panel.removeClass('is-minimized is-hidden-tab is-floating is-maximized');
        } else {
            closePopout('details');
        }
        scheduleMapResizeBurst();
    }

    function closeDetailsPanel() {
        state.selectedIncidentId = '';
        setDetailPanelVisible(false);
        renderIncidents();
        renderMap();
    }

    function renderIncidents() {
        var activeCount = currentIncidents().filter(function (incident) {
            return incidentAccepted(incident) && incidentPrepared(incident);
        }).length;
        state.$root.find('[data-lstw-incident-count]').text(String(activeCount));

        var incidents = incidentsForTab();
        var html = incidents.length ? incidents.map(function (incident) {
            var selected = String(incident.id) === String(state.selectedIncidentId);
            return '<article class="lstw-card lstw-incident-card' + (selected ? ' is-selected' : '') + '" data-lstw-focus-incident="' + esc(incident.id) + '" data-kind="' + esc(incident.einsatzart || '') + '">' +
                '<div class="lstw-card-title"><strong>' + esc(incidentTitle(incident)) + '</strong><button type="button" class="lstw-map-focus" data-lstw-show-incident-map="' + esc(incident.id) + '" aria-label="Auf Karte anzeigen" title="Auf Karte anzeigen"><span aria-hidden="true"></span></button></div>' +
                '<span>' + esc([incident.einsatzart, incident.einsatztyp].filter(Boolean).join(' - ')) + '</span>' +
                '<small>' + esc(incidentPlace(incident)) + '</small>' +
                incidentAssignedUnitsHtml(incident) +
                '<div class="lstw-badges">' +
                    '<span class="lstw-badge" data-lstw-incident-elapsed="' + esc(incident.id) + '">' + esc(incidentElapsedText(incident)) + '</span>' +
                '</div>' +
            '</article>';
        }).join('') : '<p class="lstw-empty">' + esc(lsttrainingWorkspace.texts.emptyIncidents) + '</p>';

        state.$root.find('[data-lstw-incident-list]').html(html);
        renderDetails();
    }

    function incidentAssignedUnitsHtml(incident) {
        var units = asArray(incident && incident.assigned_units);
        if (!units.length) {
            return '<div class="lstw-incident-units lstw-incident-units--empty"><span>Fahrzeuge</span><em>Keine Fahrzeuge alarmiert</em></div>';
        }

        return '<div class="lstw-incident-units"><span>Fahrzeuge</span><div>' + units.map(function (unit) {
            var label = unit.rufname || 'Fahrzeug';
            var status = assignmentStatusLabel(unit.assignment_status);
            var typeLabel = unit.resource_class ? resourceClassLabel(unit.resource_class) : (unit.fahrzeugtyp || unit.resource_class_label || 'Fahrzeug');
            return '<button type="button" class="lstw-incident-unit-chip" data-lstw-select-vehicle="' + esc(unit.unit_key || unit.status_id || unit.fahrzeug_id || '') + '" title="' + esc(label + ' - ' + fmsLabel(unit.fms_status) + ' - ' + status) + '">' +
                '<strong>' + esc(label) + '</strong>' +
                '<b class="' + fmsClass(unit.fms_status) + '">' + esc(fmsLabel(unit.fms_status)) + '</b>' +
                '<em>' + esc(typeLabel) + '</em>' +
            '</button>';
        }).join('') + '</div></div>';
    }

    function resourceClassLabel(type) {
        var key = String(type || '').toLowerCase();
        var labels = {
            rettungswagen: 'RTW',
            krankentransport: 'KTW',
            krankentransportwagen: 'KTW',
            notarzt: 'Notarztmittel',
            notarztmittel: 'Notarztmittel',
            rth: 'RTH',
            loeschfahrzeug: 'Löschfahrzeug',
            drehleiter: 'Drehleiter',
            ruestwagen: 'Rüstwagen',
            fuehrung: 'Führung',
            thw: 'THW'
        };
        return labels[key] || String(type || 'Ressource');
    }

    function incidentResourceRows(incident) {
        var resources = asArray(incident && incident.resource_status);
        if (!resources.length) {
            resources = asArray(incident && incident.required_resources);
        }
        if (!resources.length) {
            resources = asArray(asObject(incident && incident.meta).required_resources);
        }
        return resources;
    }

    function missingResourceTypes(incident) {
        return incidentResourceRows(incident).filter(function (row) {
            var needed = Number(row.needed || row.count || 1) || 1;
            var assigned = Number(row.assigned || 0) || 0;
            var missing = Number(row.missing);
            if (!Number.isFinite(missing)) {
                missing = Math.max(0, needed - assigned);
            }
            return missing > 0;
        }).map(function (row) {
            return String(row.type || row.resource_class || '').toLowerCase();
        }).filter(Boolean);
    }

    function vehicleMatchesIncidentNeed(vehicle, incident) {
        var needed = missingResourceTypes(incident);
        if (!needed.length) {
            return false;
        }
        var haystack = String([
            vehicle && vehicle.resource_class,
            vehicle && vehicle.resource_class_label,
            vehicle && vehicle.fahrzeugtyp,
            vehicle && vehicle.rufname
        ].filter(Boolean).join(' ')).toLowerCase();
        return needed.some(function (type) {
            if (haystack.indexOf(type) !== -1) return true;
            if (type === 'rettungswagen' && /\brtw\b/.test(haystack)) return true;
            if ((type === 'krankentransport' || type === 'krankentransportwagen') && /\b(ktw|rtw)\b/.test(haystack)) return true;
            if ((type === 'notarzt' || type === 'notarztmittel') && /\b(nef|naw|rth|ith)\b/.test(haystack)) return true;
            if (type === 'loeschfahrzeug' && /\b(hlf|lf|tlf)\b/.test(haystack)) return true;
            if (type === 'drehleiter' && /\b(dlk|tm)\b/.test(haystack)) return true;
            return false;
        });
    }

    function dispatchVehicleGroup(vehicle) {
        var haystack = String([
            vehicle && vehicle.resource_class,
            vehicle && vehicle.resource_class_label,
            vehicle && vehicle.fahrzeugtyp,
            vehicle && vehicle.rufname
        ].filter(Boolean).join(' ')).toUpperCase();

        if (/\b(RTH|ITH)\b/.test(haystack) || haystack.indexOf('HUBSCHRAUBER') !== -1 || haystack.indexOf('CHRISTOPH') !== -1) {
            return 'rth';
        }
        if (/\bTHW\b/.test(haystack) || haystack.indexOf('TECHNISCHES HILFSWERK') !== -1 || /\b(GKW|MLW|MTW-TZ)\b/.test(haystack)) {
            return 'thw';
        }
        if (/\b(NEF|NAW)\b/.test(haystack) || haystack.indexOf('NOTARZT') !== -1) {
            return 'nef';
        }
        if (/\bRTW\b/.test(haystack) || haystack.indexOf('RETTUNGSWAGEN') !== -1) {
            return 'rtw';
        }
        if (/\bKTW\b/.test(haystack) || haystack.indexOf('KRANKENTRANSPORT') !== -1) {
            return 'ktw';
        }
        if (/\b(FW|HLF|LF|TLF|DLK|ELW|RW|GW)\b/.test(haystack) || haystack.indexOf('FEUERWEHR') !== -1) {
            return 'fw';
        }
        return 'other';
    }

    function applyDispatchVehicleFilter(group) {
        var active = group || 'all';
        var $modal = state.$root.find('[data-lstw-modal]');
        $modal.find('[data-lstw-dispatch-filter]').each(function () {
            var isActive = $(this).attr('data-lstw-dispatch-filter') === active;
            $(this).toggleClass('is-active', isActive).attr('aria-pressed', isActive ? 'true' : 'false');
        });
        $modal.find('[data-dispatch-group]').each(function () {
            var matches = active === 'all' || $(this).attr('data-dispatch-group') === active;
            $(this).prop('hidden', !matches);
        });
    }

    function toggleDispatchSignalGlow(active) {
        state.$root.find('.lstw-modal__dialog--dispatch').toggleClass('is-signal-active', !!active);
    }

    function dispatchSignalAllowed(meta) {
        var value = asObject(meta).signal_allowed;
        return value === true || value === 1 || value === '1';
    }

    function activeDispatchAssignment(vehicle) {
        var statusId = String(vehicle && vehicle.status_id || '');
        var fahrzeugId = String(vehicle && vehicle.fahrzeug_id || '');
        if (!statusId && !fahrzeugId) {
            return null;
        }
        return asArray(state.snapshot && state.snapshot.assignments).find(function (assignment) {
            if (String(assignment.mission_phase || '') === 'available') {
                return false;
            }
            return (statusId && String(assignment.status_id || '') === statusId) ||
                (fahrzeugId && String(assignment.fahrzeug_id || '') === fahrzeugId);
        }) || null;
    }

    function recallableAssignment(assignment) {
        return assignment && !assignment.is_support && assignment.event_id &&
            ['alarmiert', 'ausrueckend', 'anfahrt'].indexOf(String(assignment.assignment_status || '')) !== -1;
    }

    function vehicleRadioCommands(assignment) {
        if (!assignment || !assignment.event_id) {
            return [];
        }
        if (assignment.is_support) {
            if (assignment.support_type !== 'neighbor' || !assignment.contact_established) {
                return [];
            }
            if (['anfahrt', 'vor_ort'].indexOf(String(assignment.assignment_status || '')) !== -1) {
                return [
                    { action: 'request_situation', label: 'Lage erfragen' },
                    { action: 'request_additional_resources', label: 'Weitere Kräfte erforderlich?' },
                    { action: 'request_transport_destination', label: 'Transportziel bekannt?' },
                    { action: 'request_notarzt_requirement', label: 'Notarzt erforderlich?' }
                ];
            }
            return [];
        }
        if (recallableAssignment(assignment)) {
            return [{ action: 'recall', label: 'Anfahrt abbrechen' }];
        }
        if (String(assignment.assignment_status || '') === 'vor_ort') {
            return [
                { action: 'request_situation', label: 'Lage erfragen' },
                { action: 'request_additional_resources', label: 'Weitere Kräfte erforderlich?' },
                { action: 'request_transport_destination', label: 'Transportziel bekannt?' },
                { action: 'request_notarzt_requirement', label: 'Notarzt erforderlich?' }
            ];
        }
        if (['transport', 'uebergabe'].indexOf(String(assignment.assignment_status || '')) !== -1) {
            return [{ action: 'request_transport_destination', label: 'Transportziel erfragen' }];
        }
        return [];
    }

    function assignmentByEventId(eventId) {
        return asArray(state.snapshot && state.snapshot.assignments).find(function (assignment) {
            return String(assignment.event_id || '') === String(eventId || '');
        }) || null;
    }

    function closeVehicleContextMenu() {
        state.$root.find('[data-lstw-vehicle-context-menu]').remove();
    }

    function openVehicleContextMenu(event, vehicle) {
        var assignment = activeDispatchAssignment(vehicle);
        var commands = vehicleRadioCommands(assignment);
        closeVehicleContextMenu();
        if (!commands.length) {
            return;
        }

        var disabled = isPaused();
        var html = '<div class="lstw-vehicle-context-menu" data-lstw-vehicle-context-menu role="menu" aria-label="Funkbefehle">' +
            commands.map(function (command) {
                var attribute = command.action === 'recall'
                    ? 'data-lstw-recall-vehicle="' + esc(assignment.event_id) + '"'
                    : 'data-lstw-vehicle-radio-command="' + esc(command.action) + '" data-event-id="' + esc(assignment.event_id) + '"';
                return '<button type="button" role="menuitem" ' + attribute + (disabled ? ' disabled aria-disabled="true"' : '') + '>' +
                    '<i class="fa-solid fa-bullhorn" aria-hidden="true"></i>' +
                    '<span>' + esc(command.label) + '</span>' +
                '</button>';
            }).join('') +
        '</div>';
        state.$root.append(html);

        var $menu = state.$root.find('[data-lstw-vehicle-context-menu]').last();
        var width = $menu.outerWidth() || 210;
        var height = $menu.outerHeight() || 40;
        var left = Math.max(8, Math.min(event.clientX, window.innerWidth - width - 8));
        var top = Math.max(8, Math.min(event.clientY, window.innerHeight - height - 8));
        $menu.css({ left: left + 'px', top: top + 'px' });
    }

    function dispatchBlockReason(vehicle, assignedStatusIds) {
        var statusId = String(vehicle && vehicle.status_id || '');
        if (statusId && assignedStatusIds && assignedStatusIds[statusId]) {
            return 'bereits zugeordnet';
        }
        if (vehicle && vehicle.dispatch_available === false) {
            return vehicle.dispatch_block_reason || 'nicht alarmierbar';
        }
        var assignment = activeDispatchAssignment(vehicle);
        if (assignment) {
            var assignmentStatus = String(assignment.assignment_status || '');
            return {
                alarmiert: 'bereits alarmiert',
                ausrueckend: 'rückt aus',
                anfahrt: 'auf Anfahrt',
                vor_ort: 'vor Ort',
                transport: 'Klinikfahrt',
                uebergabe: 'Übergabe',
                rueckfahrt: 'Rückfahrt'
            }[assignmentStatus] || 'bereits zugeordnet';
        }
        var fms = String(vehicle && vehicle.fms_status || '');
        if (fms === '6') return 'nicht verfügbar';
        if (fms === '5') return 'Sprechwunsch';
        if (fms === '8') return 'im Krankenhaus';
        if (fms === '7') return 'Patiententransport';
        if (fms === '4') return 'vor Ort';
        if (fms === '3') return 'auf Anfahrt';
        var status = String(vehicle && vehicle.status || '').toLowerCase();
        if (status && ['frei', 'einsatzbereit'].indexOf(status) === -1) {
            return 'nicht frei';
        }
        return '';
    }

    function refreshDispatchVehicleAvailability() {
        var $modal = state.$root.find('[data-lstw-modal]');
        var $dialog = $modal.find('.lstw-modal__dialog--dispatch');
        if (!$dialog.length) {
            return;
        }
        var incidentId = $modal.find('[data-lstw-save-dispatch], [data-lstw-save-dispatch-alarm]').first().attr('data-lstw-save-dispatch') ||
            $modal.find('[data-lstw-save-dispatch-alarm]').first().attr('data-lstw-save-dispatch-alarm') || '';
        var incident = findIncident(incidentId);
        var assignedStatusIds = {};
        asArray(incident && incident.assigned_units).forEach(function (unit) {
            var statusId = unit.status_id || '';
            if (statusId) {
                assignedStatusIds[String(statusId)] = true;
            }
        });
        $modal.find('[data-dispatch-status-id]').each(function () {
            var $row = $(this);
            var id = $row.attr('data-dispatch-status-id') || '';
            var vehicle = findVehicle(id);
            var reason = dispatchBlockReason(vehicle, assignedStatusIds);
            var disabled = reason !== '' || !vehicle;
            $row.toggleClass('is-disabled', disabled).attr('aria-disabled', disabled ? 'true' : 'false');
            $row.find('[name="alarm_status_id"]').prop('disabled', disabled);
            if (disabled) {
                $row.find('[name="alarm_status_id"]').prop('checked', false);
            }
            $row.find('[data-lstw-dispatch-block-reason]').text(reason);
            $row.find('> span').last().attr('class', fmsClass(vehicle && vehicle.fms_status)).text(fmsLabel(vehicle && vehicle.fms_status));
        });
    }

    function formatDistance(meters) {
        if (!Number.isFinite(meters)) {
            return '';
        }
        if (meters < 1000) {
            return Math.round(meters) + ' m';
        }
        return (meters / 1000).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' km';
    }

    function assignedUnitsHtml(incident, removable) {
        var units = asArray(incident && incident.assigned_units);
        if (!units.length) {
            return '<p class="lstw-empty">Keine Fahrzeuge zugeordnet.</p>';
        }
        return '<div class="lstw-assigned-list">' + units.map(function (unit) {
            var typeLabel = unit.resource_class_label || (unit.resource_class ? resourceClassLabel(unit.resource_class) : '');
            if (unit.foreign_unit || unit.support_type === 'neighbor') {
                typeLabel = (typeLabel || 'Fremdfahrzeug') + ' | ' + (unit.home_nebenleitstelle_name || 'Nachbarleitstelle');
            }
            var routeStatus = String(unit.route_status || '');
            var routeNote = '';
            if (routeStatus === 'failed') {
                routeNote = '<small class="lstw-route-error">Route fehlgeschlagen: ' + esc(unit.route_error_message || unit.route_error_code || 'unbekannter Grund') + '</small>';
            } else if (routeStatus === 'pending') {
                routeNote = '<small class="lstw-route-pending">Route wird berechnet</small>';
            }
            var removeLabel = recallableAssignment(unit) ? 'Anfahrt abbrechen und Rückfahrt anordnen' : 'Zuordnung auflösen';
            var remove = removable && unit.event_id ? '<button type="button" class="lstw-remove-unit" data-lstw-unassign-unit="' + esc(unit.event_id) + '" title="' + esc(removeLabel) + '" aria-label="' + esc(removeLabel) + '">x</button>' : '';
            var radio = vehicleRadioCommands(unit).map(function (command) {
                return '<button type="button" class="lstw-assigned-radio" data-lstw-vehicle-radio-command="' + esc(command.action) + '" data-event-id="' + esc(unit.event_id) + '">' + esc(command.label) + '</button>';
            }).join('');
            return '<div class="lstw-assigned-unit">' +
                '<strong>' + esc(unit.rufname || 'Fahrzeug') + '</strong>' +
                '<span>' + esc(typeLabel) + '</span>' +
                '<em>' + esc(assignmentStatusLabel(unit.assignment_status)) + '</em>' +
                '<b class="' + fmsClass(unit.fms_status) + '">' + esc(fmsLabel(unit.fms_status)) + '</b>' +
                routeNote +
                radio +
                remove +
            '</div>';
        }).join('') + '</div>';
    }

    function logSnapshotRouteFailures(snapshot) {
        asArray(snapshot && snapshot.incidents).forEach(function (incident) {
            asArray(incident && incident.assigned_units).forEach(function (unit) {
                if (String(unit && unit.route_status || '') !== 'failed') {
                    return;
                }
                logRoutingError('snapshot_route_failed', {
                    data: {
                        message: unit.route_error_message || 'Route fehlgeschlagen.',
                        route_error_code: unit.route_error_code || '',
                        route_error_detail: unit.route_error_detail || unit.route_error_message || '',
                        event_id: unit.event_id || '',
                        status_id: unit.status_id || '',
                        fahrzeug_id: unit.fahrzeug_id || '',
                        einsatz_id: incident && incident.id ? incident.id : ''
                    }
                }, {
                    eventId: unit.event_id || '',
                    statusId: unit.status_id || '',
                    vehicleId: unit.fahrzeug_id || '',
                    incidentId: incident && incident.id ? incident.id : ''
                }, true);
            });
        });
    }

    function assignmentStatusLabel(status) {
        return {
            alarmiert: 'alarmiert',
            ausrueckend: 'rückt aus',
            anfahrt: 'auf Anfahrt',
            vor_ort: 'vor Ort',
            transport: 'Klinikfahrt',
            uebergabe: 'Übergabe',
            rueckfahrt: 'Rückfahrt'
        }[String(status || '')] || 'zugeordnet';
    }

    function incidentVisibleText(incident) {
        var meta = asObject(incident && incident.meta);
        return incident.caller_text || meta.caller_text || incident.description || meta.description || 'Keine Meldung hinterlegt.';
    }

    function renderDetails() {
        var $detail = state.$root.find('[data-lstw-detail]');
        setDetailPanelVisible(false);
        $detail.html('');
    }

    function normalizeRadioItems() {
        var rows = [];
        var indexes = {};
        var aliases = {};

        function fingerprint(item, loose) {
            return [
                String(item.source_type || item.kind || ''),
                String(item.ts || '').slice(0, 19),
                String(item.rufname || item.fahrzeug_id || item.status_id || item.source_label || ''),
                loose ? '' : String(item.einsatz_id || item.instanz_einsatz_id || ''),
                String(item.text || '').replace(/\s+/g, ' ').trim().toLowerCase()
            ].join('|');
        }

        function addRadioRow(item) {
            var eventId = String(item.event_id || item.id || '');
            var eventKey = eventId ? 'event:' + eventId : '';
            var printKey = 'print:' + fingerprint(item);
            var looseKey = 'loose:' + fingerprint(item, true);
            var existingIndex = eventKey && Object.prototype.hasOwnProperty.call(indexes, eventKey)
                ? indexes[eventKey]
                : (Object.prototype.hasOwnProperty.call(aliases, printKey)
                    ? aliases[printKey]
                    : (Object.prototype.hasOwnProperty.call(aliases, looseKey) ? aliases[looseKey] : null));

            if (existingIndex !== null && existingIndex !== undefined) {
                rows[existingIndex] = $.extend({}, rows[existingIndex], item);
                if (eventKey) {
                    indexes[eventKey] = existingIndex;
                }
                aliases[printKey] = existingIndex;
                aliases[looseKey] = existingIndex;
                return;
            }

            rows.push(item);
            var index = rows.length - 1;
            if (eventKey) {
                indexes[eventKey] = index;
            }
            aliases[printKey] = index;
            aliases[looseKey] = index;
        }

        asArray(state.snapshot && state.snapshot.events).forEach(function (item) {
            var kind = String(item.kind || '');
            var sourceType = kind === 'unit_report' ? 'vehicles' : (kind === 'system' ? 'system' : (kind === 'caller_answer' ? 'caller' : 'dispatch'));
            var meta = asObject(item.meta);
            addRadioRow($.extend({
                event_id: item.id || item.event_id,
                einsatz_id: item.instanz_einsatz_id || item.einsatz_id,
                status_id: meta.status_id,
                fahrzeug_id: meta.fahrzeug_id || meta.vehicle_id,
                rufname: meta.rufname,
                fms_status: meta.fms_status,
                source_type: sourceType,
                source_label: { vehicles: 'Fahrzeuge', system: 'System', caller: 'Notruf', dispatch: 'Leitstelle' }[sourceType]
            }, item));
        });
        asArray(state.snapshot && state.snapshot.call_log).forEach(function (item) {
            addRadioRow($.extend({
                source_type: item.kind === 'unit_report' ? 'vehicles' : (item.kind === 'new_call' || item.kind === 'caller_answer' ? 'caller' : 'dispatch'),
                source_label: item.kind === 'unit_report' ? 'Fahrzeuge' : (item.kind === 'new_call' ? 'Notruf' : 'Leitstelle')
            }, item));
        });
        asArray(state.snapshot && state.snapshot.fms_log).forEach(function (item) {
            addRadioRow($.extend({ source_type: 'vehicles', source_label: 'Fahrzeuge' }, item));
        });
        asArray(state.snapshot && state.snapshot.radio_requests).forEach(function (item) {
            addRadioRow($.extend({
                source_type: 'vehicles',
                source_label: 'Fahrzeuge',
                text: item.opened_at ? (item.text || 'Lagemeldung') : 'Sprechwunsch',
                can_open_unit_report: !item.opened_at,
                can_ack_unit_report: !!item.opened_at,
                event_id: item.event_id,
                ts: item.ts
            }, item));
        });
        rows.sort(function (a, b) {
            return String(b.ts || '').localeCompare(String(a.ts || ''));
        });
        return rows.slice(0, 120);
    }

    function renderRadio() {
        var items = normalizeRadioItems();
        var filtered = state.radioFilter === 'all' ? items : items.filter(function (item) {
            return item.source_type === state.radioFilter;
        });
        state.$root.find('[data-lstw-radio-count]').text(String(items.length));

        state.$root.find('[data-lstw-radio-list]').html(filtered.length ? filtered.map(function (item) {
            var actions = '';
            if (item.can_accept) {
                actions += '<button type="button" data-lstw-accept-call="' + esc(item.einsatz_id) + '">Entgegennehmen</button>';
            }
            if (item.can_open_dispatch) {
                actions += '<button type="button" data-lstw-edit-incident="' + esc(item.einsatz_id) + '">Einsatz erstellen</button>';
            }
            if (item.can_ack_unit_report) {
                actions += '<button type="button" data-lstw-ack-report="' + esc(item.event_id) + '">Bestätigen</button>';
            }
            if (item.can_open_unit_report) {
                actions += '<button type="button" data-lstw-open-report="' + esc(item.event_id) + '">Öffnen</button>';
            }
            var selectedVehicle = item.source_type === 'vehicles' ? findVehicle(item.status_id || item.fahrzeug_id) : null;
            var rufname = item.rufname || (selectedVehicle && selectedVehicle.rufname) || '';
            var vehicleKeyValue = selectedVehicle ? vehicleKey(selectedVehicle) : String(item.status_id || item.fahrzeug_id || '');
            var sender = esc(item.source_label || 'System');
            if (item.source_type === 'vehicles' && rufname) {
                sender = vehicleKeyValue
                    ? '<button type="button" class="lstw-timeline-vehicle" data-lstw-show-vehicle-card="' + esc(vehicleKeyValue) + '" title="' + esc(rufname) + '">' + esc(rufname) + '</button>'
                    : esc(rufname);
            }
            return '<div class="lstw-timeline-row">' +
                '<time>' + esc(formatTime(item.ts)) + '</time>' +
                '<b>' + sender + '</b>' +
                '<span>' + esc(item.text || '') + (actions ? '<span class="lstw-detail-actions">' + actions + '</span>' : '') + '</span>' +
            '</div>';
        }).join('') : '<p class="lstw-empty">' + esc(lsttrainingWorkspace.texts.emptyTimeline) + '</p>');
        renderPendingCommunications();
    }

    function renderPendingCommunications() {
        var $target = state.$root.find('[data-lstw-pending-communications]');
        var callsOpen = $target.find('[data-lstw-pending-kind="calls"]').prop('open');
        var reportsOpen = $target.find('[data-lstw-pending-kind="reports"]').prop('open');
        var calls = asArray(state.snapshot && state.snapshot.call_log).filter(function (item) {
            return !!item.can_accept;
        }).sort(function (a, b) {
            return String(a.ts || '').localeCompare(String(b.ts || ''));
        });
        var reports = asArray(state.snapshot && state.snapshot.radio_requests).sort(function (a, b) {
            return String(a.ts || '').localeCompare(String(b.ts || ''));
        });

        function rows(items, kind) {
            return items.map(function (item) {
                var action = kind === 'calls'
                    ? '<button type="button" data-lstw-accept-call="' + esc(item.einsatz_id) + '">Entgegennehmen</button>'
                    : (item.opened_at
                        ? '<button type="button" data-lstw-ack-report="' + esc(item.event_id) + '">Bestätigen</button>'
                        : '<button type="button" data-lstw-open-report="' + esc(item.event_id) + '">Öffnen</button>');
                var label = kind === 'calls' ? (item.text || 'Neuer Notruf') : ((item.rufname ? item.rufname + ': ' : '') + (item.opened_at ? (item.text || 'Lagemeldung') : 'Sprechwunsch'));
                return '<div class="lstw-pending-row"><time>' + esc(formatTime(item.ts)) + '</time><span>' + esc(label) + '</span>' + action + '</div>';
            }).join('');
        }

        var html = '';
        if (calls.length) {
            html += '<details class="lstw-head-task" data-lstw-pending-kind="calls"' + (callsOpen ? ' open' : '') + '><summary>Notrufe <b>' + calls.length + '</b></summary><div class="lstw-head-task__list">' + rows(calls, 'calls') + '</div></details>';
        }
        if (reports.length) {
            html += '<details class="lstw-head-task" data-lstw-pending-kind="reports"' + (reportsOpen ? ' open' : '') + '><summary>Sprechwünsche <b>' + reports.length + '</b></summary><div class="lstw-head-task__list">' + rows(reports, 'reports') + '</div></details>';
        }
        $target.html(html).prop('hidden', !html);
    }

    function renderAll() {
        renderVehicles();
        renderIncidents();
        renderRadio();
        renderMap();
        updateRuntimeUi();
        syncPopouts();
        scheduleMapResizeBurst();
    }

    function loadBootstrap() {
        if (state.busy.bootstrap) return;
        state.busy.bootstrap = true;
        setMapStatus('Simulationsbasis wird geladen...', false);
        simPost({ action: 'lsttraining_sim_get_bootstrap', instanz_id: state.instanceId }).done(function (response) {
            if (!response || !response.success || !response.data) {
                showMessage(lsttrainingWorkspace.texts.bootstrapError, 'error');
                return;
            }
            state.bootstrap = response.data;
            applyBootstrapRuntime();
            renderAll();
            loadWorkspaceExtras();
            loadSnapshot(false);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, lsttrainingWorkspace.texts.bootstrapError), 'error');
        }).always(function () {
            state.busy.bootstrap = false;
        });
    }

    function loadWorkspaceExtras() {
        simPost({ action: 'lsttraining_sim_get_workspace_hospitals', instanz_id: state.instanceId }).done(function (response) {
            state.hospitals = response && response.success && response.data ? asArray(response.data.items) : [];
            state.departmentConfig = response && response.success && response.data ? asObject(response.data.departments) : {};
            renderMap();
            syncPopouts();
        }).fail(function (xhr) {
            setMapStatus(ajaxErrorMessage(xhr, 'Krankenhäuser konnten nicht geladen werden.'), true);
        });
    }

    function snapshotCallKeys(data) {
        var keys = {};
        asArray(data && data.incidents).forEach(function (incident) {
            if (!incidentAccepted(incident)) {
                keys['incident-' + String(incident.id || '')] = true;
            }
        });
        asArray(data && data.call_log).forEach(function (item) {
            if (String(item.kind || '') === 'new_call') {
                keys['call-' + String(item.id || item.ts || item.text || '')] = true;
            }
        });
        return keys;
    }

    function newestRingingIncident(data, previousKeys) {
        var found = null;
        asArray(data && data.incidents).forEach(function (incident) {
            var key = 'incident-' + String(incident.id || '');
            if (!found && !incidentAccepted(incident) && previousKeys && !previousKeys[key]) {
                found = incident;
            }
        });
        return found;
    }

    function snapshotRadioRequestKeys(data) {
        var keys = {};
        asArray(data && data.radio_requests).forEach(function (item) {
            if (!item.acknowledged_at && !item.opened_at) {
                keys['radio-' + String(item.id || item.event_id || item.ts || item.text || '')] = true;
            }
        });
        return keys;
    }

    function hasNewKey(next, previous) {
        return Object.keys(next).some(function (key) {
            return !previous[key];
        });
    }

    function handleAttentionReset(data) {
        var calls = snapshotCallKeys(data);
        var radio = snapshotRadioRequestKeys(data);
        if (state.snapshotSeen && state.speed !== 1 && (hasNewKey(calls, state.seenCallKeys) || hasNewKey(radio, state.seenRadioRequestKeys))) {
            setSpeed(1, true);
            setMapStatus('Neuer Anruf oder Sprechwunsch: Geschwindigkeit auf x1 gesetzt.', false);
        }
        state.seenCallKeys = calls;
        state.seenRadioRequestKeys = radio;
        state.snapshotSeen = true;
    }

    function repairMotorwayLocations(data) {
        asArray(data && data.incidents).forEach(function (incident) {
            var id = String(incident && incident.id || '');
            if (!id || !incident.motorway_location_needs_repair || state.motorwayRepairRequests[id]) {
                return;
            }
            state.motorwayRepairRequests[id] = 'pending';
            simPost({
                action: 'lsttraining_sim_repair_motorway_location',
                instanz_id: state.instanceId,
                einsatz_id: id
            }).done(function (response) {
                state.motorwayRepairRequests[id] = 'done';
                if (response && response.success && response.data && response.data.repaired) {
                    window.setTimeout(function () {
                        loadSnapshot(true);
                    }, 150);
                }
            }).fail(function () {
                state.motorwayRepairRequests[id] = 'failed';
            });
        });
    }

    function loadSnapshot(silent) {
        if (!state.bootstrap || state.busy.snapshot) return;
        state.busy.snapshot = true;
        if (!silent) setMapStatus('Simulationsdaten werden geladen...', false);
        simPost({ action: 'lsttraining_sim_get_snapshot', instanz_id: state.instanceId }).done(function (response) {
            if (!response || !response.success || !response.data) {
                showMessage(lsttrainingWorkspace.texts.snapshotError, 'error');
                return;
            }
            var previousSpeed = state.speed;
            var previousPaused = state.paused;
            prepareVehicleAnimation(state.snapshot || {}, response.data);
            state.snapshot = response.data;
            closeVehicleContextMenu();
            logSnapshotRouteFailures(state.snapshot);
            syncSimClock(response.data);
            if (previousSpeed !== state.speed || previousPaused !== state.paused) {
                scheduleLoops();
            }
            scheduleNextRadioRefresh(response.data);
            handleAttentionReset(response.data);
            repairMotorwayLocations(response.data);
            showMessage('', 'success');
            renderAll();
            refreshDispatchVehicleAvailability();
            if (state.pendingRevealIncidentId) {
                var pendingRevealId = state.pendingRevealIncidentId;
                state.pendingRevealIncidentId = '';
                focusIncidentInWorkspace(pendingRevealId, false);
            }
            if (state.pendingDetailsIncidentId) {
                var pendingDetailsId = state.pendingDetailsIncidentId;
                state.pendingDetailsIncidentId = '';
                openIncidentDetailsModal(pendingDetailsId, true);
            }
            if (state.pendingDispatchIncidentId) {
                var pendingId = state.pendingDispatchIncidentId;
                var pendingIncident = findIncident(pendingId);
                if (pendingIncident) {
                    state.pendingDispatchIncidentId = '';
                    openDispatchModal(pendingId);
                }
            }
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, lsttrainingWorkspace.texts.snapshotError), 'error');
        }).always(function () {
            state.busy.snapshot = false;
        });
    }

    function scheduleNextRadioRefresh(data) {
        window.clearTimeout(state.timers.radioNext);
        state.timers.radioNext = null;
        if (!data || isPaused()) {
            return;
        }
        var nextTs = timeValueTimestamp(data.next_radio_refresh_at);
        var nowTs = Number(data.sim_timestamp || currentSimWallTimestamp());
        if (!nextTs || !nowTs || nextTs <= nowTs) {
            return;
        }
        var speed = Math.max(1, Number(state.speed || 1));
        var delay = Math.max(250, Math.ceil(((nextTs - nowTs) * 1000) / speed) + 200);
        state.timers.radioNext = window.setTimeout(function () {
            state.timers.radioNext = null;
            loadSnapshot(true);
        }, Math.min(delay, 7000));
    }

    function runTick(silent) {
        if (isPaused() || state.busy.tick) return;
        state.busy.tick = true;
        simPost({ action: 'lsttraining_sim_tick', instanz_id: state.instanceId }).done(function () {
            loadSnapshot(true);
            if (!silent) setMapStatus('Generator geprüft.', false);
        }).always(function () {
            state.busy.tick = false;
        });
    }

    function scheduleLoops() {
        window.clearInterval(state.timers.snapshot);
        window.clearInterval(state.timers.tick);
        window.clearTimeout(state.timers.radioNext);
        state.timers.radioNext = null;
        if (isPaused()) return;

        state.timers.snapshot = window.setInterval(function () {
            loadSnapshot(true);
        }, Math.max(1000, 10000 / state.speed));

        state.timers.tick = window.setInterval(function () {
            runTick(true);
        }, Math.max(6000, 30000 / state.speed));
    }

    function selectIncident(id, focusMap) {
        state.selectedIncidentId = String(id || '');
        renderIncidents();
        renderDetails();
        if (state.map.layers.incidents) {
            state.map.layers.incidents.changed();
        }
        if (focusMap) {
            var incident = findIncident(id);
            var coords = fromLonLat(incident);
            if (coords && state.map.main) {
                state.map.main.getView().animate({ center: coords, zoom: Math.max(13, state.map.main.getView().getZoom() || 13), duration: 240 });
            }
        }
    }

    function ensureIncidentPanelVisible() {
        var layout = readLayout();
        var config = layout.panels.incidents || {};
        var changed = false;

        if (config.minimized) {
            config.minimized = false;
            changed = true;
        }
        if (config.hiddenTab) {
            if (config.group) {
                Object.keys(layout.panels).forEach(function (panelId) {
                    if (layout.panels[panelId].group === config.group) {
                        layout.panels[panelId].hiddenTab = panelId !== 'incidents';
                        layout.panels[panelId].area = config.area || layout.panels[panelId].area;
                    }
                });
            } else {
                config.hiddenTab = false;
            }
            changed = true;
        }

        layout.panels.incidents = config;
        if (changed) {
            applyLayout();
        }
    }

    function focusIncidentInWorkspace(id, focusMap) {
        if (!id) {
            return;
        }
        ensureIncidentPanelVisible();
        selectIncident(id, focusMap);
        revealIncidentInList(id);
    }

    function revealIncidentInList(id) {
        var key = String(id || '');
        if (!key) {
            return;
        }
        var $card = state.$root.find('[data-lstw-incident-list] [data-lstw-focus-incident]').filter(function () {
            return String($(this).attr('data-lstw-focus-incident') || '') === key;
        }).first();
        if ($card.length && $card[0].scrollIntoView) {
            $card[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function findRenderedVehicleCard(id) {
        var key = String(id || '');
        return state.$root.find('[data-lstw-vehicle-list] [data-lstw-focus-vehicle]').filter(function () {
            return String($(this).attr('data-lstw-focus-vehicle') || '') === key;
        }).first();
    }

    function revealVehicleInList(id) {
        var $card = findRenderedVehicleCard(id);
        if (!$card.length && (state.vehicleSearch || state.vehicleFilter !== 'all')) {
            state.vehicleSearch = '';
            state.vehicleFilter = 'all';
            state.$root.find('[data-lstw-vehicle-search]').val('');
            state.$root.find('[data-lstw-vehicle-filters] button').removeClass('is-active').filter('[data-filter="all"]').addClass('is-active');
            renderVehicles();
            $card = findRenderedVehicleCard(id);
        }
        if ($card.length && $card[0].scrollIntoView) {
            $card[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function selectVehicle(id, focusMap, revealInList) {
        var vehicle = findVehicle(id);
        var selectedId = vehicle ? vehicleKey(vehicle) : String(id || '');
        state.selectedVehicleId = selectedId;
        renderVehicles();
        if (revealInList && selectedId) {
            revealVehicleInList(selectedId);
        }
        drawSelectedVehicleRoute();
        if (state.map.layers.vehicles) {
            state.map.layers.vehicles.changed();
        }
        var coords = fromLonLat(vehicle);
        var assignment = selectedVehicleAssignment(selectedId || id);
        if (!coords && assignment && assignment.last_position) {
            coords = fromLonLat(assignment.last_position);
        }
        if (focusMap && coords && state.map.main) {
            state.map.main.getView().animate({ center: coords, zoom: Math.max(13, state.map.main.getView().getZoom() || 13), duration: 240 });
        }
    }

    function focusVehicle(id) {
        selectVehicle(id, true, true);
    }

    function closeModal() {
        if (state.$root.find('.lstw-modal__dialog--neighbor-support').length) {
            state.neighborSupportDrafts = {};
        }
        state.modalDrag = null;
        state.activeCallModalIncidentId = '';
        state.$root.find('[data-lstw-modal]').removeClass('lstw-modal--map-context').prop('hidden', true).empty();
    }

    function modalVisible() {
        return !state.$root.find('[data-lstw-modal]').prop('hidden');
    }

    function modalHtml(title, body, modifier) {
        var dialogClass = 'lstw-modal__dialog' + (modifier ? ' lstw-modal__dialog--' + esc(modifier) : '');
        var bodyClass = 'lstw-modal__body' + (modifier ? ' lstw-modal__body--' + esc(modifier) : '');
        return '<div class="' + dialogClass + '" role="dialog" aria-modal="true" data-lstw-modal-dialog>' +
            '<header class="lstw-modal__head" data-lstw-modal-drag-handle><strong>' + esc(title) + '</strong><button type="button" data-lstw-close-modal>Schließen</button></header>' +
            '<div class="' + bodyClass + '">' + body + '</div>' +
        '</div>';
    }

    function incidentDetailsModalBody(incident) {
        var editLabel = incidentPrepared(incident) ? 'Einsatz bearbeiten' : 'Einsatz erstellen';
        var blockers = asArray(incident && incident.close_blockers);
        var closeDisabled = blockers.length ? ' disabled aria-disabled="true" title="' + esc(blockers.join(' ')) + '"' : '';
        var closeNotice = blockers.length ? '<p class="lstw-close-blocked">' + esc(blockers.join(' ')) + '</p>' : '';
        return '<div class="lstw-detail-grid">' +
            '<div class="lstw-detail-row"><span>Einsatzort</span><strong>' + esc(incidentPlace(incident)) + '</strong></div>' +
            '<div class="lstw-detail-row"><span>Alarmzeit</span><strong>' + esc(formatTime(incident.sim_created_at || incident.created_at)) + '</strong></div>' +
            '</div>' +
            '<div class="lstw-detail-row"><span>Meldung</span><p>' + esc(incidentVisibleText(incident)) + '</p></div>' +
            '<div class="lstw-detail-row"><span>Zugeordnete Fahrzeuge</span>' + assignedUnitsHtml(incident, false) + '</div>' +
            closeNotice +
            '<footer class="lstw-detail-actions">' +
                '<button type="button" data-lstw-show-incident-map="' + esc(incident.id) + '">Auf Karte anzeigen</button>' +
                '<button type="button" data-lstw-open-neighbor-support="' + esc(incident.id) + '">Unterstützung anfordern</button>' +
                '<button type="button" data-lstw-edit-incident="' + esc(incident.id) + '">' + esc(editLabel) + '</button>' +
                '<button type="button" data-lstw-no-incident="' + esc(incident.id) + '"' + closeDisabled + '>Kein Einsatz</button>' +
            '</footer>';
    }

    function openIncidentDetailsModal(incidentId, force) {
        var incident = findIncident(incidentId);
        if (!incident) {
            return false;
        }
        if (!force && modalVisible()) {
            return false;
        }
        selectIncident(incidentId, true);
        state.activeCallModalIncidentId = String(incidentId || '');
        state.$root.find('[data-lstw-modal]').addClass('lstw-modal--map-context').html(modalHtml('Einsatzdetails', '<h2>' + esc(incidentTitle(incident)) + '</h2>' + incidentDetailsModalBody(incident), 'details')).prop('hidden', false);
        return true;
    }

    function dispatchDescriptionText(incident) {
        var meta = asObject(incident && incident.meta);
        var saved = String(meta.zusatz_text || '').trim();
        var lagemeldung = String(incident && incident.lagemeldung || '').trim();
        var callerText = String(incident && incident.caller_text || '').trim();
        if (saved && saved !== lagemeldung && saved !== callerText) {
            return saved;
        }
        return String(
            (incident && (incident.description || incident.template_description)) ||
            meta.lst_description ||
            meta.description ||
            meta.template_description ||
            meta.einsatz_description ||
            ''
        ).trim();
    }

    function openForceSpawnModal() {
        if (mutationBlocked('Simulation ist pausiert. Neue Einsätze können erst nach Play erzeugt werden.')) return;
        var $modal = state.$root.find('[data-lstw-modal]').removeClass('lstw-modal--map-context');
        function render(items) {
            var options = '<option value="">Zufälliger Einsatz</option>' + asArray(items).map(function (item) {
                return '<option value="' + esc(item.id) + '">' + esc('#' + item.id + ' ' + (item.title || item.einsatztyp || 'Einsatz') + (item.einsatzart ? ' (' + item.einsatzart + ')' : '')) + '</option>';
            }).join('');
            $modal.html(modalHtml('Neuer Einsatz', '<label>Einsatzvorlage<select data-lstw-force-spawn-select>' + options + '</select></label><p class="lstw-message" data-lstw-force-spawn-feedback role="alert" hidden></p><footer><button type="button" data-lstw-submit-force-spawn>Anruf erzeugen</button></footer>')).prop('hidden', false);
            updateRuntimeUi();
        }
        if (state.forceSpawnOptions) {
            render(state.forceSpawnOptions);
            return;
        }
        $modal.html(modalHtml('Neuer Einsatz', '<p class="lstw-empty">Einsatzvorlagen werden geladen...</p>')).prop('hidden', false);
        simPost({ action: 'lsttraining_sim_force_spawn_options', instanz_id: state.instanceId }).done(function (response) {
            state.forceSpawnOptions = response && response.success && response.data ? asArray(response.data.items) : [];
            render(state.forceSpawnOptions);
        }).fail(function (xhr) {
            $modal.html(modalHtml('Neuer Einsatz', '<p class="lstw-empty">' + esc(ajaxErrorMessage(xhr, 'Einsatzvorlagen konnten nicht geladen werden.')) + '</p>'));
        });
    }

    function setForceSpawnSubmitting(active) {
        var $button = state.$root.find('[data-lstw-submit-force-spawn]');
        state.forceSpawnSubmitting = !!active;
        if ($button.length) {
            if (!$button.attr('data-lstw-original-label')) {
                $button.attr('data-lstw-original-label', $button.text());
            }
            $button.text(active ? 'Anruf wird erzeugt...' : $button.attr('data-lstw-original-label'));
        }
        updateRuntimeUi();
    }

    function showForceSpawnFeedback(message) {
        state.$root.find('[data-lstw-force-spawn-feedback]')
            .text(message || '')
            .prop('hidden', !message);
    }

    function submitForceSpawn() {
        if (mutationBlocked()) return;
        if (state.forceSpawnSubmitting) return;
        var value = state.$root.find('[data-lstw-force-spawn-select]').val();
        setForceSpawnSubmitting(true);
        showForceSpawnFeedback('');
        setMapStatus('Neuer Anruf wird erzeugt...', false);
        simPost({ action: 'lsttraining_sim_force_spawn', instanz_id: state.instanceId, einsatz_id: value || 0 }).done(function (response) {
            var message = response && response.data && response.data.message ? response.data.message : 'Neuer Anruf konnte nicht erzeugt werden.';
            if (!response || !response.success || !response.data || !response.data.spawned) {
                setForceSpawnSubmitting(false);
                showForceSpawnFeedback(message);
                setMapStatus(message, true);
                return;
            }
            var createdId = response && response.data
                ? (response.data.einsatz_id || (response.data.einsatz && response.data.einsatz.id) || '')
                : '';
            setForceSpawnSubmitting(false);
            closeModal();
            if (createdId) {
                state.selectedIncidentId = String(createdId);
            }
            setMapStatus(message, false);
            loadSnapshot(true);
        }).fail(function (xhr) {
            var message = ajaxErrorMessage(xhr, 'Neuer Einsatz konnte nicht erzeugt werden.');
            setForceSpawnSubmitting(false);
            showForceSpawnFeedback(message);
            setMapStatus(message, true);
            showMessage(message, 'error');
        });
    }

    function openDispatchModal(incidentId) {
        if (mutationBlocked('Simulation ist pausiert. Einsätze können erst nach Play bearbeitet werden.')) return;
        var incident = findIncident(incidentId);
        if (!incident) return;
        state.activeCallModalIncidentId = '';
        var assignedStatusIds = {};
        asArray(incident.assigned_units).forEach(function (unit) {
            var statusId = unit.status_id || '';
            if (statusId) {
                assignedStatusIds[String(statusId)] = true;
            }
        });
        var vehicles = mergeVehicleLists().filter(function (vehicle) {
            return !(vehicle && vehicle.support_type);
        }).sort(function (a, b) {
            var aDistance = distanceMeters(a, incident);
            var bDistance = distanceMeters(b, incident);
            if (aDistance !== bDistance) {
                return aDistance - bDistance;
            }
            return String(a.rufname || '').localeCompare(String(b.rufname || ''), 'de');
        });
        var vehicleRows = vehicles.map(function (vehicle) {
            var id = vehicle.status_id || '';
            var distance = formatDistance(distanceMeters(vehicle, incident));
            var match = vehicleMatchesIncidentNeed(vehicle, incident);
            var group = dispatchVehicleGroup(vehicle);
            var blockReason = dispatchBlockReason(vehicle, assignedStatusIds);
            var disabled = blockReason !== '' || !id;
            return '<label class="lstw-check-row' + (match ? ' is-resource-match' : '') + (disabled ? ' is-disabled' : '') + '" data-dispatch-group="' + esc(group) + '" data-dispatch-status-id="' + esc(id) + '" aria-disabled="' + (disabled ? 'true' : 'false') + '">' +
                '<input type="checkbox" name="alarm_status_id" value="' + esc(id) + '"' + (disabled ? ' disabled' : '') + '>' +
                '<span><strong>' + esc(vehicle.rufname || 'Fahrzeug') + '</strong><small>' + esc(vehicle.fahrzeugtyp || vehicle.resource_class_label || '') + '<i data-lstw-dispatch-block-reason>' + esc(blockReason) + '</i></small></span>' +
                '<em>' + esc(distance || vehicle.wache_name || '') + '</em>' +
                '<span class="' + fmsClass(vehicle.fms_status) + '">' + esc(fmsLabel(vehicle.fms_status)) + '</span>' +
            '</label>';
        }).join('');
        var meta = asObject(incident.meta);
        var signalAllowed = dispatchSignalAllowed(meta);
        var hasDispatch = incidentHasDispatch(incident);
        var title = hasDispatch ? 'Einsatz bearbeiten' : 'Neuen Einsatz erstellen';
        var alarmLabel = hasDispatch ? 'Alarmierung bearbeiten' : 'Alarmieren';
        var body =
            '<p class="lstw-modal__place"><strong>Einsatzort:</strong> ' + esc(incidentPlace(incident) || 'Nicht gesetzt') + '</p>' +
            '<div class="lstw-modal__meta">' +
                '<label class="lstw-signal-toggle"><span><i class="fa-solid fa-lightbulb lstw-toggle-icon" aria-hidden="true"></i>Sondersignale erlauben</span><input type="checkbox" name="signal_allowed" data-lstw-signal-allowed value="1"' + (signalAllowed ? ' checked' : '') + '></label>' +
                '<label class="lstw-police-toggle"><span><i class="fa-solid fa-user-shield lstw-toggle-icon" aria-hidden="true"></i>Polizei mitverständigen</span><input type="checkbox" name="polizei_verstaendigen" value="1"' + (meta.polizei_verstaendigen ? ' checked' : '') + '></label>' +
                '<label><span>Einsatzcode</span><input type="text" name="einsatzcode" value="' + esc(meta.einsatzcode || incident.einsatztyp || '') + '"></label>' +
                '<label><span>Ausrückorder</span><input type="text" name="ausrueckorder" value="' + esc(meta.ausrueckorder || '') + '"></label>' +
                '<label><span>Einsatzkategorie</span><input type="text" name="einsatzkategorie" value="' + esc(meta.einsatzkategorie || incident.einsatzart || '') + '"></label>' +
                '<label><span>Abholzeit</span><input type="time" name="abholzeit" value="' + esc(meta.abholzeit || '') + '"></label>' +
            '</div>' +
            '<label class="lstw-modal__text"><span>Zusatz / Freitext</span><textarea name="zusatz_text" rows="3">' + esc(dispatchDescriptionText(incident)) + '</textarea></label>' +
            '<section class="lstw-modal__section"><div class="lstw-modal__section-head"><strong>Bereits zugeordnet</strong><button type="button" data-lstw-show-incident-map="' + esc(incident.id) + '">Auf Karte anzeigen</button></div>' + assignedUnitsHtml(incident, true) + '</section>' +
            '<section class="lstw-modal__section"><div class="lstw-modal__section-head"><strong>Fahrzeuge alarmieren</strong><div class="lstw-dispatch-filters" aria-label="Fahrzeugfilter"><button type="button" class="is-active" data-lstw-dispatch-filter="all" aria-pressed="true">Alle</button><button type="button" data-lstw-dispatch-filter="ktw" aria-pressed="false">KTW</button><button type="button" data-lstw-dispatch-filter="rtw" aria-pressed="false">RTW</button><button type="button" data-lstw-dispatch-filter="nef" aria-pressed="false" title="Notarztmittel">NEF</button><button type="button" data-lstw-dispatch-filter="rth" aria-pressed="false">RTH</button><button type="button" data-lstw-dispatch-filter="fw" aria-pressed="false">Feuerwehr</button><button type="button" data-lstw-dispatch-filter="thw" aria-pressed="false">THW</button></div><span>Entfernung</span></div><div class="lstw-modal__vehicles">' + (vehicleRows || '<p class="lstw-empty">Keine Fahrzeuge verfügbar.</p>') + '</div></section>' +
            '<footer><button type="button" data-lstw-open-neighbor-support="' + esc(incident.id) + '">Unterstützung anfordern</button><button type="button" data-lstw-save-dispatch-alarm="' + esc(incident.id) + '">' + esc(alarmLabel) + '</button><button type="button" data-lstw-save-dispatch="' + esc(incident.id) + '">Einsatz anlegen</button></footer>';

        state.$root.find('[data-lstw-modal]').removeClass('lstw-modal--map-context').html(modalHtml(title, body, 'dispatch')).prop('hidden', false);
        toggleDispatchSignalGlow(signalAllowed);
    }

    function dispatchPayload(incidentId) {
        var $modal = state.$root.find('[data-lstw-modal]');
        return {
            action: 'lsttraining_sim_save_dispatch',
            instanz_id: state.instanceId,
            einsatz_id: incidentId,
            signal_allowed: $modal.find('[name="signal_allowed"]').is(':checked') ? 1 : 0,
            einsatzcode: $modal.find('[name="einsatzcode"]').val() || '',
            ausrueckorder: $modal.find('[name="ausrueckorder"]').val() || '',
            einsatzkategorie: $modal.find('[name="einsatzkategorie"]').val() || '',
            zusatz_text: $modal.find('[name="zusatz_text"]').val() || '',
            abholzeit: $modal.find('[name="abholzeit"]').val() || '',
            polizei_verstaendigen: $modal.find('[name="polizei_verstaendigen"]').is(':checked') ? 1 : 0
        };
    }

    function selectedAlarmStatusIds() {
        return state.$root.find('[data-lstw-modal] [name="alarm_status_id"]:checked:not(:disabled)').map(function () {
            return $(this).val();
        }).get();
    }

    function neighborSupportDraftKey(incidentId, neighborId) {
        return String(incidentId || '') + ':' + String(neighborId || '');
    }

    function latestNeighborSupportRequest(incidentId, neighborId) {
        var draft = state.neighborSupportDrafts[neighborSupportDraftKey(incidentId, neighborId)];
        if (draft) return draft;
        return null;
    }

    function neighborSupportOfferRows(request) {
        var accepted = {};
        asArray(request && request.accepted_vehicle_ids).forEach(function (id) {
            accepted[String(id)] = true;
        });
        var offer = asArray(request && request.offer);
        if (!offer.length) {
            return '<p class="lstw-empty">Keine Fahrzeuge im Angebot.</p>';
        }
        return offer.map(function (vehicle) {
            var id = String(vehicle.fahrzeug_id || '');
            var already = !!accepted[id];
            var available = !!vehicle.available && !already;
            var stateText = already ? 'bereits angefordert' : (vehicle.availability_state || (available ? 'verfügbar' : 'nicht verfügbar'));
            return '<label class="lstw-check-row' + (available ? '' : ' is-disabled') + '" data-neighbor-offer-vehicle="' + esc(id) + '" aria-disabled="' + (available ? 'false' : 'true') + '">' +
                '<input type="checkbox" name="neighbor_vehicle_id" value="' + esc(id) + '"' + (available ? '' : ' disabled') + '>' +
                '<span><strong>' + esc(vehicle.rufname || 'Fahrzeug') + '</strong><small>' + esc([vehicle.fahrzeugtyp || vehicle.resource_class_label || '', vehicle.home_wache_name || ''].filter(Boolean).join(' | ')) + '<i>' + esc(stateText) + '</i></small></span>' +
                '<em>' + esc(vehicle.nebenleitstelle_name || '') + '</em>' +
            '</label>';
        }).join('');
    }

    function neighborSupportModalBody(incident, selectedNeighborId) {
        var centers = neighborDispatchCenters();
        if (!centers.length) {
            return '<p class="lstw-empty">Für diese Leitstelle sind keine Nachbarleitstellen hinterlegt.</p>';
        }
        selectedNeighborId = selectedNeighborId || (centers[0] && centers[0].id) || '';
        var options = centers.map(function (center) {
            return '<option value="' + esc(center.id) + '"' + (String(center.id) === String(selectedNeighborId) ? ' selected' : '') + '>' + esc(center.name || ('Nebenleitstelle ' + center.id)) + '</option>';
        }).join('');
        var request = latestNeighborSupportRequest(incident.id, selectedNeighborId);
        var offerTime = request && (request.offer_generated_at || request.ts) ? '<small>Antwort von ' + esc(String(request.offer_generated_at || request.ts).slice(11, 16) || request.offer_generated_at || request.ts) + '</small>' : '';
        var offerHtml = request
            ? '<section class="lstw-modal__section"><div class="lstw-modal__section-head"><strong>Gemeldete Fahrzeuge</strong><span>' + esc((request.available_count || 0) + ' verfügbar') + '</span>' + offerTime + '</div><div class="lstw-modal__vehicles">' + neighborSupportOfferRows(request) + '</div><footer><button type="button" data-lstw-accept-neighbor-support="' + esc(request.event_id) + '">Ausgewählte Fahrzeuge anfordern</button></footer></section>'
            : '<p class="lstw-empty">Noch kein Angebot angefragt.</p>';
        return '<p class="lstw-modal__place"><strong>Einsatzort:</strong> ' + esc(incidentPlace(incident) || 'Nicht gesetzt') + '</p>' +
            '<label><span>Nachbarleitstelle</span><select data-lstw-neighbor-support-select>' + options + '</select></label>' +
            '<footer><button type="button" data-lstw-request-neighbor-support="' + esc(incident.id) + '">Unterstützung anfordern</button></footer>' +
            offerHtml;
    }

    function openNeighborSupportModal(incidentId, neighborId) {
        if (mutationBlocked('Simulation ist pausiert. Unterstützung kann erst nach Play angefordert werden.')) return;
        var incident = findIncident(incidentId);
        if (!incident) {
            showMessage('Bitte zuerst einen Einsatz auswählen.', 'error');
            return;
        }
        state.$root.find('[data-lstw-modal]').removeClass('lstw-modal--map-context')
            .html(modalHtml('Unterstützung anfordern', neighborSupportModalBody(incident, neighborId || ''), 'neighbor-support'))
            .prop('hidden', false);
    }

    function refreshNeighborSupportModal() {
        var $dialog = state.$root.find('.lstw-modal__dialog--neighbor-support');
        if (!$dialog.length) return;
        var incidentId = $dialog.find('[data-lstw-request-neighbor-support]').attr('data-lstw-request-neighbor-support') || '';
        var neighborId = $dialog.find('[data-lstw-neighbor-support-select]').val() || '';
        if (incidentId) openNeighborSupportModal(incidentId, neighborId);
    }

    function requestNeighborSupport(incidentId) {
        var neighborId = state.$root.find('[data-lstw-modal] [data-lstw-neighbor-support-select]').val() || '';
        if (!neighborId) {
            showMessage('Bitte eine Nachbarleitstelle auswählen.', 'error');
            return;
        }
        simPost({
            action: 'lsttraining_sim_request_neighbor_support',
            instanz_id: state.instanceId,
            einsatz_id: incidentId,
            nebenleitstelle_id: neighborId
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                showMessage(response && response.data && response.data.message ? response.data.message : 'Unterstützungsanfrage fehlgeschlagen.', 'error');
                return;
            }
            state.neighborSupportDrafts[neighborSupportDraftKey(incidentId, neighborId)] = {
                event_id: response.data.event_id,
                einsatz_id: incidentId,
                nebenleitstelle_id: neighborId,
                nebenleitstelle_name: response.data.nebenleitstelle_name || '',
                offer: asArray(response.data.offer),
                offer_generated_at: response.data.offer_generated_at || '',
                offer_session_id: response.data.offer_session_id || '',
                available_count: response.data.available_count || 0,
                total_count: asArray(response.data.offer).length,
                accepted_vehicle_ids: []
            };
            showMessage(response.data.message || 'Nachbarleitstelle hat geantwortet.', 'success');
            refreshNeighborSupportModal();
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Unterstützungsanfrage konnte nicht gestellt werden.'), 'error');
        });
    }

    function selectedNeighborVehicleIds() {
        return state.$root.find('[data-lstw-modal] [name="neighbor_vehicle_id"]:checked:not(:disabled)').map(function () {
            return $(this).val();
        }).get();
    }

    function acceptNeighborSupport(requestEventId) {
        var selected = selectedNeighborVehicleIds();
        if (!selected.length) {
            showMessage('Bitte mindestens ein verfügbares Fremdfahrzeug auswählen.', 'error');
            return;
        }
        simPost({
            action: 'lsttraining_sim_accept_neighbor_support',
            instanz_id: state.instanceId,
            request_event_id: requestEventId,
            vehicle_ids: selected
        }).done(function (response) {
            if (!response || !response.success) {
                showMessage(response && response.data && response.data.message ? response.data.message : 'Fremdfahrzeuge konnten nicht angefordert werden.', 'error');
                return;
            }
            showMessage(response.data && response.data.message ? response.data.message : 'Fremdfahrzeuge angefordert.', 'success');
            Object.keys(state.neighborSupportDrafts).forEach(function (key) {
                if (String(state.neighborSupportDrafts[key].event_id || '') === String(requestEventId || '')) {
                    delete state.neighborSupportDrafts[key];
                }
            });
            closeModal();
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Fremdfahrzeuge konnten nicht angefordert werden.'), 'error');
        });
    }

    function setDispatchBusy(active, text) {
        var $modal = state.$root.find('[data-lstw-modal]');
        var $dialog = $modal.find('.lstw-modal__dialog--dispatch');
        if (!$dialog.length) {
            return;
        }
        $dialog.attr('aria-busy', active ? 'true' : 'false').toggleClass('is-busy', !!active);
        $dialog.find('footer button, [name="alarm_status_id"]').prop('disabled', !!active);
        var $alarm = $dialog.find('[data-lstw-save-dispatch-alarm]');
        if ($alarm.length) {
            if (!$alarm.attr('data-lstw-original-label')) {
                $alarm.attr('data-lstw-original-label', $alarm.text());
            }
            $alarm.text(active ? (text || 'Alarmierung läuft...') : $alarm.attr('data-lstw-original-label'));
        }
    }

    function alarmVehicle(statusId, incidentId, signalAllowed) {
        if (mutationBlocked('Simulation ist pausiert. Fahrzeuge können erst nach Play alarmiert werden.')) {
            return $.Deferred().reject({ message: 'Simulation ist pausiert.' }).promise();
        }
        var vehicle = findVehicle(statusId);
        var blockReason = dispatchBlockReason(vehicle, {});
        if (blockReason) {
            return $.Deferred().reject({ message: 'Dieses Fahrzeug kann nicht alarmiert werden: ' + blockReason + '.' }).promise();
        }
        var incident = findIncident(incidentId);
        if (!lonLat(vehicle) || !lonLat(incident)) {
            return $.Deferred().reject({ message: 'Fahrzeug oder Einsatz hat keine Kartenkoordinaten.' }).promise();
        }
        return simPost({
            action: 'lsttraining_sim_alarm_vehicle',
            instanz_id: state.instanceId,
            einsatz_id: incidentId,
            status_id: statusId,
            sondersignal_allowed: signalAllowed ? 1 : 0
        }).then(null, function (xhr) {
            logRoutingError('alarm_vehicle', xhr, {
                statusId: statusId,
                incidentId: incidentId,
                vehicleId: vehicle && vehicle.fahrzeug_id ? vehicle.fahrzeug_id : ''
            }, false);
            return $.Deferred().reject(xhr).promise();
        });
    }

    function routeFailureMessage(xhr, fallback) {
        var info = routingErrorData(xhr, fallback || 'Route konnte nicht berechnet werden.');
        var parts = [info.message];
        if (info.detail) {
            parts.push('Grund: ' + info.detail);
        } else if (info.code) {
            parts.push('Code: ' + info.code);
        }
        if (info.stage) {
            parts.push('Schritt: ' + info.stage);
        }
        return parts.join(' ');
    }

    function resolveVehicleRoute(eventId) {
        if (!eventId) {
            return $.Deferred().resolve().promise();
        }
        return simPost({
            action: 'lsttraining_sim_resolve_vehicle_route',
            instanz_id: state.instanceId,
            event_id: eventId
        }).then(null, function (xhr) {
            logRoutingError('resolve_vehicle_route', xhr, { eventId: eventId }, true);
            return $.Deferred().reject(xhr).promise();
        });
    }

    function saveDispatch(incidentId, shouldAlarm) {
        if (mutationBlocked('Simulation ist pausiert. Einsatzbearbeitung und Alarmierung sind gesperrt.')) return;
        var payload = dispatchPayload(incidentId);
        var selected = selectedAlarmStatusIds();
        var signalAllowed = payload.signal_allowed === 1;
        var incident = findIncident(incidentId);
        var hasDispatch = incidentHasDispatch(incident);
        if (shouldAlarm && !selected.length && !hasDispatch) {
            showMessage('Keine alarmierbaren Fahrzeuge ausgewählt.', 'error');
            return;
        }
        if (shouldAlarm) {
            setDispatchBusy(true, selected.length ? 'Alarmierung läuft...' : 'Alarmierung wird bearbeitet...');
            showMessage(selected.length ? 'Einsatz wird gespeichert und Fahrzeuge werden alarmiert...' : 'Alarmierung wird bearbeitet...', 'success');
        }
        simPost(payload).done(function (response) {
            if (!response || !response.success) {
                setDispatchBusy(false);
                showMessage(response && response.data && response.data.message ? response.data.message : lsttrainingWorkspace.texts.saveError, 'error');
                return;
            }
            if (!shouldAlarm || !selected.length) {
                closeModal();
                loadSnapshot(true);
                return;
            }
            var alarmIncidentId = response && response.data && response.data.einsatz_id ? response.data.einsatz_id : incidentId;
            var chain = $.Deferred().resolve().promise();
            var routeEventIds = [];
            selected.forEach(function (statusId) {
                chain = chain.then(function () {
                    setDispatchBusy(true, 'Fahrzeug wird zugeordnet...');
                    return alarmVehicle(statusId, alarmIncidentId, signalAllowed).then(function (alarmResponse) {
                        var eventId = alarmResponse && alarmResponse.data && alarmResponse.data.event_id ? alarmResponse.data.event_id : '';
                        if (eventId) {
                            routeEventIds.push(eventId);
                        }
                        return alarmResponse;
                    });
                });
            });
            chain = chain.then(function () {
                var routeChain = $.Deferred().resolve().promise();
                var routeErrors = [];
                routeEventIds.forEach(function (eventId) {
                    routeChain = routeChain.then(function () {
                        setDispatchBusy(true, 'Route wird berechnet...');
                        return resolveVehicleRoute(eventId).then(null, function (xhr) {
                            routeErrors.push(routeFailureMessage(xhr, 'Route konnte nicht berechnet werden.'));
                            return $.Deferred().resolve().promise();
                        });
                    });
                });
                return routeChain.then(function () {
                    if (routeErrors.length) {
                        return $.Deferred().reject({ message: routeErrors.join(' ') }).promise();
                    }
                    return true;
                });
            });
            chain.done(function () {
                closeModal();
                loadSnapshot(true);
            }).fail(function (xhr) {
                setDispatchBusy(false);
                showMessage(routeFailureMessage(xhr, lsttrainingWorkspace.texts.alarmError), 'error');
                loadSnapshot(true);
            });
        }).fail(function (xhr) {
            setDispatchBusy(false);
            showMessage(ajaxErrorMessage(xhr, lsttrainingWorkspace.texts.saveError), 'error');
        });
    }

    function recallVehicle(eventId, reopenDispatch) {
        if (mutationBlocked('Simulation ist pausiert. Funkbefehle können erst nach Play gesendet werden.')) return;
        closeVehicleContextMenu();
        simPost({
            action: 'lsttraining_sim_recall_vehicle',
            instanz_id: state.instanceId,
            event_id: eventId
        }).done(function (response) {
            if (!response || !response.success) {
                showMessage(response && response.data && response.data.message ? response.data.message : 'Rückruf konnte nicht gefunkt werden.', 'error');
                return;
            }
            var data = response.data || {};
            if (data.route_fallback) {
                logRoutingError('recall_vehicle_fallback', { data: data.route_fallback }, {
                    eventId: eventId,
                    statusId: data.status_id || '',
                    incidentId: data.einsatz_id || ''
                }, true);
            }
            showMessage(data.message || 'Anfahrt wurde abgebrochen.', 'success');
            if (reopenDispatch && data.einsatz_id) {
                state.pendingDispatchIncidentId = String(data.einsatz_id);
            }
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Rückruf konnte nicht gefunkt werden.'), 'error');
        });
    }

    function sendVehicleRadioCommand(eventId, commandCode) {
        if (mutationBlocked('Simulation ist pausiert. Funksprüche können erst nach Play gesendet werden.')) return;
        closeVehicleContextMenu();
        simPost({
            action: 'lsttraining_sim_send_vehicle_radio_command',
            instanz_id: state.instanceId,
            event_id: eventId,
            command_code: commandCode
        }).done(function (response) {
            if (!response || !response.success) {
                showMessage(response && response.data && response.data.message ? response.data.message : 'Funkspruch konnte nicht gesendet werden.', 'error');
                return;
            }
            showMessage(response.data && response.data.message ? response.data.message : 'Funkspruch gesendet.', 'success');
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Funkspruch konnte nicht gesendet werden.'), 'error');
        });
    }

    function unassignUnit(eventId) {
        if (mutationBlocked('Simulation ist pausiert. Fahrzeugzuordnungen können erst nach Play geändert werden.')) return;
        if (recallableAssignment(assignmentByEventId(eventId))) {
            recallVehicle(eventId, true);
            return;
        }
        simPost({
            action: 'lsttraining_sim_unassign_vehicle',
            instanz_id: state.instanceId,
            event_id: eventId
        }).done(function (response) {
            if (!response || !response.success) {
                showMessage(response && response.data && response.data.message ? response.data.message : 'Zuordnung konnte nicht aufgehoben werden.', 'error');
                return;
            }
            var openIncidentId = response.data && response.data.einsatz_id ? String(response.data.einsatz_id) : state.selectedIncidentId;
            state.pendingDispatchIncidentId = openIncidentId;
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Zuordnung konnte nicht aufgehoben werden.'), 'error');
        });
    }

    function acceptCall(incidentId) {
        if (mutationBlocked('Simulation ist pausiert. Anrufe können erst nach Play angenommen werden.')) return;
        simPost({ action: 'lsttraining_sim_accept_call', einsatz_id: incidentId }).done(function () {
            state.selectedIncidentId = String(incidentId || '');
            state.activeCallModalIncidentId = '';
            state.pendingDetailsIncidentId = String(incidentId || '');
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Anruf konnte nicht angenommen werden.'), 'error');
        });
    }

    function closeIncident(incidentId, noVehiclesSent) {
        if (mutationBlocked('Simulation ist pausiert. Einsätze können erst nach Play abgeschlossen werden.')) return;
        simPost({
            action: 'lsttraining_sim_update_einsatz_state',
            einsatz_id: incidentId,
            state: 'closed',
            no_vehicles_sent: noVehiclesSent ? 1 : 0
        }).done(function () {
            state.selectedIncidentId = '';
            if (String(state.activeCallModalIncidentId || '') === String(incidentId || '')) {
                closeModal();
            }
            loadSnapshot(true);
            loadWorkspaceExtras();
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Einsatz konnte nicht abgeschlossen werden.'), 'error');
        });
    }

    function ackReport(eventId) {
        if (mutationBlocked()) return;
        simPost({ action: 'lsttraining_sim_ack_unit_report', instanz_id: state.instanceId, event_id: eventId }).done(function (response) {
            var incidentId = response && response.data && response.data.einsatz_id ? String(response.data.einsatz_id) : '';
            if (incidentId) {
                state.pendingRevealIncidentId = incidentId;
                focusIncidentInWorkspace(incidentId, true);
            }
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Lagemeldung konnte nicht bestätigt werden.'), 'error');
        });
    }

    function openUnitReport(eventId) {
        if (mutationBlocked()) return;
        simPost({ action: 'lsttraining_sim_open_unit_report', instanz_id: state.instanceId, event_id: eventId }).done(function (response) {
            if (!response || !response.success) {
                showMessage(response && response.data && response.data.message ? response.data.message : 'Sprechwunsch konnte nicht geöffnet werden.', 'error');
                return;
            }
            var incidentId = response && response.data && response.data.einsatz_id ? String(response.data.einsatz_id) : '';
            if (incidentId) {
                state.pendingRevealIncidentId = incidentId;
                focusIncidentInWorkspace(incidentId, true);
            }
            loadSnapshot(true);
        }).fail(function (xhr) {
            showMessage(ajaxErrorMessage(xhr, 'Sprechwunsch konnte nicht geöffnet werden.'), 'error');
        });
    }

    function layoutDefaults() {
        return {
            grid: {
                left: '270px',
                right: '360px',
                top: '54vh'
            },
            panels: Object.keys(defaultAreas).reduce(function (acc, id) {
                acc[id] = { area: defaultAreas[id], minimized: false, maximized: false, floating: false };
                return acc;
            }, {}),
            groups: {}
        };
    }

    function applyGridLayout(grid) {
        var config = $.extend({}, layoutDefaults().grid, grid || {});
        state.$root.find('[data-lstw-board]').css({
            '--lstw-left-width': config.left,
            '--lstw-right-width': config.right,
            '--lstw-top-height': config.top
        });
    }

    function readLayout() {
        if (state.layout) return state.layout;
        try {
            state.layout = $.extend(true, layoutDefaults(), JSON.parse(window.localStorage.getItem(state.layoutKey) || 'null') || {});
        } catch (error) {
            state.layout = layoutDefaults();
        }
        return state.layout;
    }

    function persistLayout(silent) {
        try {
            window.localStorage.setItem(state.layoutKey, JSON.stringify(readLayout()));
            if (silent !== true) {
                setMapStatus('Layout gespeichert.', false);
            }
        } catch (error) {
            setMapStatus('Layout konnte nicht gespeichert werden.', true);
        }
    }

    function applyLayout() {
        var layout = readLayout();
        applyGridLayout(layout.grid);
        state.$root.find('[data-lstw-panel]').each(function () {
            var $panel = $(this);
            var id = $panel.attr('data-lstw-panel');
            var config = layout.panels[id] || {};
            $panel.css({ gridArea: config.area || defaultAreas[id] || id });
            $panel.toggleClass('is-minimized', !!config.minimized);
            $panel.toggleClass('is-maximized', !!config.maximized);
            $panel.toggleClass('is-floating', !!config.floating);
            $panel.toggleClass('is-hidden-tab', !!config.hiddenTab);
            if (config.floating) {
                $panel.css({
                    left: config.left || '',
                    top: config.top || '',
                    width: config.width || '',
                    height: config.height || ''
                });
            } else {
                $panel.css({ left: '', top: '', width: '', height: '' });
            }
        });
        renderPanelTabs();
        renderMinimized();
        syncPopouts();
        scheduleMapResizeBurst();
    }

    function savePanelBox(id, $panel) {
        var layout = readLayout();
        var box = layout.panels[id] || {};
        box.left = $panel.css('left');
        box.top = $panel.css('top');
        box.width = $panel.css('width');
        box.height = $panel.css('height');
        layout.panels[id] = box;
    }

    function renderMinimized() {
        var layout = readLayout();
        var html = Object.keys(layout.panels).filter(function (id) {
            return layout.panels[id].minimized;
        }).map(function (id) {
            return '<button type="button" data-lstw-restore-panel="' + esc(id) + '">' + esc(panelTitles[id] || id) + '</button>';
        }).join('');
        state.$root.find('[data-lstw-minimized]').html(html);
    }

    function renderPanelTabs() {
        var layout = readLayout();
        var groups = {};
        Object.keys(layout.panels).forEach(function (id) {
            var group = layout.panels[id].group || '';
            if (!group) return;
            groups[group] = groups[group] || [];
            groups[group].push(id);
        });
        state.$root.find('[data-lstw-tabs]').prop('hidden', true).empty();
        Object.keys(groups).forEach(function (group) {
            var ids = groups[group];
            var active = ids.find(function (id) { return !layout.panels[id].hiddenTab; }) || ids[0];
            ids.forEach(function (id) {
                layout.panels[id].hiddenTab = id !== active;
                layout.panels[id].area = layout.panels[active].area;
            });
            var html = ids.map(function (id) {
                return '<button type="button" class="' + (id === active ? 'is-active' : '') + '" data-lstw-activate-tab="' + esc(id) + '">' + esc(panelTitles[id] || id) + '</button>';
            }).join('');
            state.$root.find('[data-lstw-panel="' + active + '"] [data-lstw-tabs]').html(html).prop('hidden', false);
        });
    }

    function groupPanels(sourceId, targetId) {
        if (!sourceId || !targetId || sourceId === targetId) return;
        var layout = readLayout();
        var targetGroup = layout.panels[targetId].group || ('group-' + targetId);
        layout.panels[targetId].group = targetGroup;
        layout.panels[sourceId].group = targetGroup;
        layout.panels[sourceId].area = layout.panels[targetId].area;
        layout.panels[sourceId].hiddenTab = true;
        layout.panels[sourceId].minimized = false;
        layout.panels[sourceId].floating = false;
        layout.panels[sourceId].maximized = false;
        layout.panels[targetId].hiddenTab = false;
        applyLayout();
    }

    function activatePanelTab(id) {
        var layout = readLayout();
        var group = layout.panels[id] && layout.panels[id].group;
        if (!group) return;
        Object.keys(layout.panels).forEach(function (panelId) {
            if (layout.panels[panelId].group === group) {
                layout.panels[panelId].hiddenTab = panelId !== id;
                layout.panels[panelId].area = layout.panels[id].area || layout.panels[panelId].area;
            }
        });
        applyLayout();
    }

    function dockPanel(id, zone) {
        var layout = readLayout();
        var area = { left: 'vehicles', center: 'map', right: 'incidents', bottom: 'radio' }[zone] || defaultAreas[id] || id;
        layout.panels[id] = $.extend({}, layout.panels[id], {
            area: area,
            minimized: false,
            maximized: false,
            floating: false,
            hiddenTab: false,
            group: ''
        });
        applyLayout();
    }

    function handlePanelAction(id, action) {
        if (id === 'details' && action === 'minimize') {
            closeDetailsPanel();
            return;
        }
        if (action === 'popout') {
            popoutPanel(id);
            return;
        }
        var layout = readLayout();
        var config = layout.panels[id] || {};
        if (action === 'minimize') {
            config.minimized = true;
            config.maximized = false;
            config.floating = false;
        } else if (action === 'maximize') {
            config.maximized = !config.maximized;
            config.minimized = false;
        } else if (action === 'float') {
            var size = panelWindowSize(id);
            config.floating = !config.floating;
            config.maximized = false;
            config.minimized = false;
            config.left = config.left || '96px';
            config.top = config.top || '96px';
            config.width = config.width || (size.width + 'px');
            config.height = config.height || (size.height + 'px');
        }
        layout.panels[id] = config;
        applyLayout();
    }

    function panelWindowSize(id) {
        var sizes = {
            map: { width: 1100, height: 760 },
            vehicles: { width: 520, height: 720 },
            incidents: { width: 520, height: 720 },
            details: { width: 760, height: 520 },
            radio: { width: 760, height: 420 }
        };
        return sizes[id] || { width: 760, height: 520 };
    }

    function stylesheetLinks() {
        return $('link[rel="stylesheet"]').map(function () {
            var href = this.href || '';
            if (href.indexOf('simulation-workspace.css') !== -1 || href.indexOf('ol.css') !== -1) {
                return '<link rel="stylesheet" href="' + esc(href) + '">';
            }
            return null;
        }).get().join('');
    }

    function popoutPanel(id) {
        var $panel = state.$root.find('[data-lstw-panel="' + id + '"]');
        if (!$panel.length) {
            return;
        }
        if (state.popouts[id] && state.popouts[id].win && !state.popouts[id].win.closed) {
            state.popouts[id].win.focus();
            return;
        }
        var size = panelWindowSize(id);
        var win = window.open('', 'lstw_panel_' + id, 'width=' + size.width + ',height=' + size.height + ',resizable=yes,scrollbars=yes');
        if (!win) {
            showMessage('Fenster konnte nicht geöffnet werden. Bitte Popups für diese Seite erlauben.', 'error');
            return;
        }
        win.document.open();
        win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(panelTitles[id] || id) + '</title>' + stylesheetLinks() + '</head><body class="lstw-popout-body"><div class="lstw-popout-toolbar"><strong>' + esc(panelTitles[id] || id) + '</strong><button type="button" data-lstw-popout-close>Zurück andocken</button></div><main data-lstw-popout-content></main></body></html>');
        win.document.close();
        state.popouts[id] = { win: win, map: null, sources: {} };
        $(win.document).on('click', '[data-lstw-popout-close]', function () {
            closePopout(id);
        });
        win.onbeforeunload = function () {
            delete state.popouts[id];
        };
        syncPopout(id);
    }

    function closePopout(id) {
        var popout = state.popouts[id];
        if (popout && popout.win && !popout.win.closed) {
            popout.win.onbeforeunload = null;
            popout.win.close();
        }
        delete state.popouts[id];
        updateMapSizes();
    }

    function syncPopouts() {
        Object.keys(state.popouts).forEach(syncPopout);
    }

    function syncPopout(id) {
        var popout = state.popouts[id];
        if (!popout || !popout.win || popout.win.closed) {
            delete state.popouts[id];
            return;
        }
        if (id === 'map') {
            syncMapPopout(popout);
            return;
        }
        var $panel = state.$root.find('[data-lstw-panel="' + id + '"]');
        var $clone = $panel.clone();
        $clone.find('[data-lstw-action]').remove();
        $(popout.win.document).find('[data-lstw-popout-content]').html($clone);
    }

    function syncMapPopout(popout) {
        var doc = popout.win.document;
        var content = doc.querySelector('[data-lstw-popout-content]');
        if (!content || typeof ol === 'undefined') {
            return;
        }
        if (!popout.map) {
            content.innerHTML = '<section class="lstw-panel lstw-panel--map lstw-popout-panel"><div class="lstw-map-panel"><div class="lstw-map" data-lstw-popout-map></div></div></section>';
            ['stations', 'vehicles', 'incidents', 'hospitals', 'pois'].forEach(function (name) {
                popout.sources[name] = new ol.source.Vector();
            });
            popout.map = new ol.Map({
                target: doc.querySelector('[data-lstw-popout-map]'),
                layers: [
                    new ol.layer.Tile({ source: new ol.source.OSM() }),
                    new ol.layer.Vector({ source: popout.sources.stations, style: function (feature) { return pointStyle('station', feature.get('data'), false); } }),
                    new ol.layer.Vector({ source: popout.sources.hospitals, style: function (feature) { return pointStyle('hospital', feature.get('data'), false); } }),
                    new ol.layer.Vector({ source: popout.sources.incidents, style: function (feature) { return pointStyle('incident', feature.get('data'), false); } }),
                    new ol.layer.Vector({ source: popout.sources.vehicles, style: function (feature) { return pointStyle('vehicle', feature.get('data'), false); } })
                ],
                view: new ol.View({
                    center: state.map.main ? state.map.main.getView().getCenter() : ol.proj.fromLonLat([13.0624, 52.4009]),
                    zoom: state.map.main ? state.map.main.getView().getZoom() : 11
                })
            });
            popout.win.addEventListener('resize', function () {
                if (popout.map) popout.map.updateSize();
            });
        }
        Object.keys(popout.sources).forEach(function (name) { popout.sources[name].clear(); });
        stations().forEach(function (station) {
            var coords = fromLonLat(station);
            if (coords) popout.sources.stations.addFeature(new ol.Feature({ geometry: new ol.geom.Point(coords), data: $.extend({ label: station.name }, station) }));
        });
        neighborDispatchCenters().forEach(function (center) {
            var coords = fromLonLat(center);
            if (!coords) return;
            popout.sources.stations.addFeature(new ol.Feature({
                geometry: new ol.geom.Point(coords),
                data: $.extend({
                    id: 'neighbor-center-' + center.id,
                    name: center.name,
                    typ: 'Nachbarleitstelle',
                    is_neighbor: true,
                    is_neighbor_center: true,
                    nebenleitstelle_id: center.id,
                    nebenleitstelle_name: center.name,
                    label: center.name
                }, center)
            }));
        });
        hospitals().forEach(function (hospital) {
            var coords = fromLonLat(hospital);
            if (coords) popout.sources.hospitals.addFeature(new ol.Feature({ geometry: new ol.geom.Point(coords), data: $.extend({ label: hospital.name }, hospital) }));
        });
        mapVehicles().forEach(function (vehicle) {
            var lonLatValue = animatedVehicleCoordinate(vehicle);
            var coords = lonLatValue ? ol.proj.fromLonLat(lonLatValue) : null;
            if (coords) popout.sources.vehicles.addFeature(new ol.Feature({ geometry: new ol.geom.Point(coords), data: $.extend({ label: vehicle.rufname }, vehicle) }));
        });
        currentIncidents().filter(incidentAccepted).forEach(function (incident) {
            var coords = fromLonLat(incident);
            if (coords) popout.sources.incidents.addFeature(new ol.Feature({ geometry: new ol.geom.Point(coords), data: $.extend({ label: incident.einsatztyp || incident.einsatzart }, incident) }));
        });
        if (state.map.main) {
            popout.map.getView().setCenter(state.map.main.getView().getCenter());
            popout.map.getView().setZoom(state.map.main.getView().getZoom());
        }
        window.setTimeout(function () {
            if (popout.map) popout.map.updateSize();
        }, 30);
    }

    function initDocking() {
        state.layoutKey = 'lsttraining_workspace_layout_v4_' + state.instanceId;
        readLayout();
        applyLayout();

        var drag = null;
        state.$root.on('pointerdown', '[data-lstw-drag-handle]', function (event) {
            var $panel = $(this).closest('[data-lstw-panel]');
            var id = $panel.attr('data-lstw-panel');
            var layout = readLayout();
            var config = layout.panels[id] || {};
            if ($(event.target).closest('button,a,input,select,textarea,summary,details,[data-lstw-pending-communications]').length) return;
            drag = {
                id: id,
                $panel: $panel,
                startX: event.clientX,
                startY: event.clientY,
                panelX: parseFloat($panel.css('left')) || 96,
                panelY: parseFloat($panel.css('top')) || 96,
                floating: !!config.floating,
                moved: false
            };
            $panel.addClass('is-dragging');
            state.$root.find('[data-lstw-board]').addClass('is-docking');
            event.preventDefault();
        });

        $(document).on('pointermove.lsttrainingWorkspaceDock', function (event) {
            if (!drag) return;
            var dx = event.clientX - drag.startX;
            var dy = event.clientY - drag.startY;
            if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
            if (drag.floating) {
                drag.$panel.css({ left: Math.max(8, drag.panelX + dx) + 'px', top: Math.max(76, drag.panelY + dy) + 'px' });
            }
            state.$root.find('[data-lstw-dock-zone]').removeClass('is-active');
            var zone = dockZoneAt(event.clientX, event.clientY);
            if (zone) $(zone).addClass('is-active');
        });

        $(document).on('pointerup.lsttrainingWorkspaceDock pointercancel.lsttrainingWorkspaceDock', function (event) {
            if (!drag) return;
            drag.$panel.removeClass('is-dragging');
            state.$root.find('[data-lstw-board]').removeClass('is-docking');
            state.$root.find('[data-lstw-dock-zone]').removeClass('is-active');

            var zone = dockZoneAt(event.clientX, event.clientY);
            var target = panelAt(event.clientX, event.clientY, drag.id);
            if (target) {
                groupPanels(drag.id, $(target).attr('data-lstw-panel'));
            } else if (zone) {
                dockPanel(drag.id, $(zone).attr('data-lstw-dock-zone'));
            } else if (drag.floating) {
                savePanelBox(drag.id, drag.$panel);
            }
            drag = null;
            updateMapSizes();
        });

        state.$root.on('click', '[data-lstw-panel] [data-lstw-action]', function () {
            handlePanelAction($(this).closest('[data-lstw-panel]').attr('data-lstw-panel'), $(this).attr('data-lstw-action'));
        });
        state.$root.on('click', '[data-lstw-restore-panel]', function () {
            var id = $(this).attr('data-lstw-restore-panel');
            readLayout().panels[id].minimized = false;
            applyLayout();
        });
        state.$root.on('click', '[data-lstw-activate-tab]', function () {
            activatePanelTab($(this).attr('data-lstw-activate-tab'));
        });
        state.$root.on('click', '[data-lstw-close-details]', closeDetailsPanel);
        state.$root.on('click', '[data-lstw-save-layout]', persistLayout);
        state.$root.on('click', '[data-lstw-load-layout]', function () {
            state.layout = null;
            readLayout();
            applyLayout();
            setMapStatus('Layout geladen.', false);
        });
    }

    function initGridResize() {
        var resize = null;

        state.$root.on('pointerdown', '[data-lstw-grid-resize]', function (event) {
            var board = state.$root.find('[data-lstw-board]').get(0);
            if (!board || window.matchMedia('(max-width: 1180px)').matches) {
                return;
            }
            resize = {
                type: $(this).attr('data-lstw-grid-resize'),
                $handle: $(this),
                rect: board.getBoundingClientRect(),
                grid: $.extend({}, layoutDefaults().grid, readLayout().grid || {})
            };
            resize.$handle.addClass('is-active');
            event.preventDefault();
        });

        $(document).on('pointermove.lsttrainingWorkspaceGridResize', function (event) {
            if (!resize) return;
            var grid = $.extend({}, resize.grid);
            if (resize.type === 'left') {
                grid.left = clamp(((event.clientX - resize.rect.left) / resize.rect.width) * 100, 14, 40).toFixed(1) + '%';
            } else if (resize.type === 'right') {
                grid.right = clamp(((resize.rect.right - event.clientX) / resize.rect.width) * 100, 16, 42).toFixed(1) + '%';
            } else {
                grid.top = clamp(((event.clientY - resize.rect.top) / resize.rect.height) * 100, 38, 76).toFixed(1) + '%';
            }
            readLayout().grid = grid;
            applyGridLayout(grid);
            scheduleMapResizeBurst();
        });

        $(document).on('pointerup.lsttrainingWorkspaceGridResize pointercancel.lsttrainingWorkspaceGridResize', function () {
            if (!resize) return;
            resize.$handle.removeClass('is-active');
            resize = null;
            persistLayout(true);
            scheduleMapResizeBurst();
        });
    }

    function dockZoneAt(x, y) {
        var found = null;
        state.$root.find('[data-lstw-dock-zone]').each(function () {
            var rect = this.getBoundingClientRect();
            if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                found = this;
                return false;
            }
            return true;
        });
        return found;
    }

    function panelAt(x, y, exceptId) {
        var found = null;
        state.$root.find('[data-lstw-panel]').not('.is-dragging,.is-hidden-tab,.is-minimized').each(function () {
            var id = $(this).attr('data-lstw-panel');
            var rect = this.getBoundingClientRect();
            if (id !== exceptId && x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                found = this;
                return false;
            }
            return true;
        });
        return found;
    }

    function bindEvents() {
        state.$root.on('input', '[data-lstw-vehicle-search]', function () {
            state.vehicleSearch = $(this).val() || '';
            renderVehicles();
        });
        state.$root.on('click', '[data-lstw-vehicle-filters] button', function () {
            state.$root.find('[data-lstw-vehicle-filters] button').removeClass('is-active');
            $(this).addClass('is-active');
            state.vehicleFilter = $(this).attr('data-filter') || 'all';
            renderVehicles();
            updateMapSizes();
        });
        state.$root.on('click', '[data-lstw-radio-filters] button', function () {
            state.$root.find('[data-lstw-radio-filters] button').removeClass('is-active');
            $(this).addClass('is-active');
            state.radioFilter = $(this).attr('data-filter') || 'all';
            renderRadio();
        });
        state.$root.on('click', '[data-lstw-focus-incident]', function (event) {
            if ($(event.target).closest('[data-lstw-show-incident-map], [data-lstw-select-vehicle]').length) {
                return;
            }
            var incidentId = $(this).attr('data-lstw-focus-incident');
            selectIncident(incidentId, true);
            openDispatchModal(incidentId);
        });
        state.$root.on('click', '[data-lstw-show-incident-map]', function (event) {
            event.stopPropagation();
            selectIncident($(this).attr('data-lstw-show-incident-map'), true);
        });
        state.$root.on('click', '[data-lstw-select-vehicle]', function (event) {
            event.stopPropagation();
            focusVehicle($(this).attr('data-lstw-select-vehicle'));
        });
        state.$root.on('click', '[data-lstw-show-vehicle-card]', function (event) {
            event.stopPropagation();
            selectVehicle($(this).attr('data-lstw-show-vehicle-card'), false, true);
        });
        state.$root.on('click', '[data-lstw-focus-vehicle]', function () {
            focusVehicle($(this).attr('data-lstw-focus-vehicle'));
        });
        state.$root.on('click', '[data-lstw-open-vehicle-radio]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var id = $(this).attr('data-lstw-open-vehicle-radio');
            var rect = this.getBoundingClientRect();
            selectVehicle(id, false);
            openVehicleContextMenu({ clientX: rect.right, clientY: rect.bottom }, findVehicle(id));
        });
        state.$root.on('contextmenu', '[data-lstw-focus-vehicle]', function (event) {
            event.preventDefault();
            var id = $(this).attr('data-lstw-focus-vehicle');
            selectVehicle(id, false);
            openVehicleContextMenu(event, findVehicle(id));
        });
        state.$root.on('click', '[data-lstw-recall-vehicle]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            recallVehicle($(this).attr('data-lstw-recall-vehicle'), false);
        });
        state.$root.on('click', '[data-lstw-vehicle-radio-command]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            sendVehicleRadioCommand($(this).attr('data-event-id'), $(this).attr('data-lstw-vehicle-radio-command'));
        });
        state.$root.on('click', '[data-lstw-new-incident]', openForceSpawnModal);
        state.$root.on('click', '[data-lstw-submit-force-spawn]', submitForceSpawn);
        state.$root.on('click', '[data-lstw-edit-incident]', function () {
            openDispatchModal($(this).attr('data-lstw-edit-incident'));
        });
        state.$root.on('click', '[data-lstw-open-neighbor-support]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openNeighborSupportModal($(this).attr('data-lstw-open-neighbor-support'));
        });
        state.$root.on('click', '[data-lstw-support-neighbor]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var incidentId = state.selectedIncidentId || (currentIncidents()[0] && currentIncidents()[0].id) || '';
            openNeighborSupportModal(incidentId, $(this).attr('data-lstw-support-neighbor'));
        });
        state.$root.on('change', '[data-lstw-neighbor-support-select]', refreshNeighborSupportModal);
        state.$root.on('click', '[data-lstw-request-neighbor-support]', function () {
            requestNeighborSupport($(this).attr('data-lstw-request-neighbor-support'));
        });
        state.$root.on('click', '[data-lstw-accept-neighbor-support]', function () {
            acceptNeighborSupport($(this).attr('data-lstw-accept-neighbor-support'));
        });
        state.$root.on('click', '[data-lstw-save-dispatch]', function () {
            saveDispatch($(this).attr('data-lstw-save-dispatch'), false);
        });
        state.$root.on('click', '[data-lstw-save-dispatch-alarm]', function () {
            saveDispatch($(this).attr('data-lstw-save-dispatch-alarm'), true);
        });
        state.$root.on('click', '[data-lstw-unassign-unit]', function () {
            unassignUnit($(this).attr('data-lstw-unassign-unit'));
        });
        state.$root.on('click', '[data-lstw-dispatch-filter]', function () {
            applyDispatchVehicleFilter($(this).attr('data-lstw-dispatch-filter') || 'all');
        });
        state.$root.on('change', '[data-lstw-signal-allowed]', function () {
            toggleDispatchSignalGlow($(this).is(':checked'));
        });
        state.$root.on('click', '[data-lstw-close-incident]', function () {
            closeIncident($(this).attr('data-lstw-close-incident'));
        });
        state.$root.on('click', '[data-lstw-accept-call]', function () {
            acceptCall($(this).attr('data-lstw-accept-call'));
        });
        state.$root.on('click', '[data-lstw-no-incident]', function () {
            closeIncident($(this).attr('data-lstw-no-incident'), true);
        });
        state.$root.on('click', '[data-lstw-ack-report]', function () {
            ackReport($(this).attr('data-lstw-ack-report'));
        });
        state.$root.on('click', '[data-lstw-open-report]', function () {
            openUnitReport($(this).attr('data-lstw-open-report'));
        });
        state.$root.on('click', '[data-lstw-close-modal]', closeModal);
        state.$root.on('click', '[data-lstw-map-card-close]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            clearMapStatus();
        });
        $(document).on('pointerdown.lsttrainingVehicleContextMenu', function (event) {
            if (!$(event.target).closest('[data-lstw-vehicle-context-menu]').length) {
                closeVehicleContextMenu();
            }
        });
        $(document).on('keydown.lsttrainingVehicleContextMenu', function (event) {
            if (event.key === 'Escape') {
                closeVehicleContextMenu();
            }
        });
        state.$root.on('pointerdown', '[data-lstw-modal-drag-handle]', function (event) {
            if ($(event.target).is('button, a, input, select, textarea')) {
                return;
            }
            var $dialog = $(this).closest('[data-lstw-modal-dialog]');
            var rect = $dialog[0].getBoundingClientRect();
            state.modalDrag = {
                $dialog: $dialog,
                offsetX: event.clientX - rect.left,
                offsetY: event.clientY - rect.top
            };
            $dialog.addClass('is-dragged').css({
                left: rect.left + 'px',
                top: rect.top + 'px'
            });
            event.preventDefault();
        });
        state.$root.on('click', '[data-lstw-layer]', function () {
            var name = $(this).attr('data-lstw-layer');
            state.map.visible[name] = !state.map.visible[name];
            $(this).toggleClass('is-active', !!state.map.visible[name]);
            renderMap();
        });
        state.$root.on('click', '[data-lstw-map-tool]', function () {
            var tool = $(this).attr('data-lstw-map-tool');
            if (tool === 'center') {
                state.map.hasFit = false;
                renderMap();
            }
        });
        state.$root.on('click', '[data-lstw-toggle-pause]', function () {
            setPaused(!isPaused(), true);
        });
        state.$root.on('click', '[data-lstw-speed]', function () {
            setSpeed(Number($(this).attr('data-lstw-speed')) || 1, true);
        });
        state.$root.on('click', '[data-lstw-action="reset-view"]', function () {
            state.map.hasFit = false;
            renderMap();
        });
        $(window).on('resize.lsttrainingWorkspace', scheduleMapResizeBurst);
        $(document).on('pointermove.lsttrainingWorkspaceModal', function (event) {
            if (!state.modalDrag) return;
            var width = state.modalDrag.$dialog.outerWidth();
            var height = state.modalDrag.$dialog.outerHeight();
            var left = clamp(event.clientX - state.modalDrag.offsetX, 8, Math.max(8, window.innerWidth - width - 8));
            var top = clamp(event.clientY - state.modalDrag.offsetY, 8, Math.max(8, window.innerHeight - height - 8));
            state.modalDrag.$dialog.css({ left: left + 'px', top: top + 'px' });
        });
        $(document).on('pointerup.lsttrainingWorkspaceModal pointercancel.lsttrainingWorkspaceModal', function () {
            state.modalDrag = null;
        });
        $(window).on('beforeunload.lsttrainingWorkspace', function () {
            Object.keys(state.timers).forEach(function (key) {
                window.clearTimeout(state.timers[key]);
                window.clearInterval(state.timers[key]);
            });
            if (state.resizeObserver) {
                state.resizeObserver.disconnect();
                state.resizeObserver = null;
            }
            if (state.animationFrame) {
                window.cancelAnimationFrame(state.animationFrame);
                state.animationFrame = null;
            }
            Object.keys(state.popouts).forEach(closePopout);
        });
    }

    $(function () {
        var $root = $('[data-lsttraining-workspace]').first();
        if (!$root.length) {
            return;
        }
        state.$root = $root;
        state.instanceId = $root.attr('data-instance-id') || '';
        state.layoutKey = 'lsttraining_workspace_layout_v4_' + state.instanceId;
        bindEvents();
        initDocking();
        initGridResize();
        ensureMaps();
        scheduleMapResizeBurst();
        updateClock();
        state.timers.clock = window.setInterval(updateClock, 250);
        loadBootstrap();
        scheduleLoops();
    });
})(jQuery);
