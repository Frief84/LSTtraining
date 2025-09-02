// js/wachen.js

(function($) {
	
if (typeof window.escapeHtml !== 'function') {
  window.escapeHtml = function (input) {
    if (input == null) return '';
    return String(input).replace(/[&<>"'`=\/]/g, function (c) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
        '/': '&#x2F;',
        '`': '&#x60;',
        '=': '&#x3D;'
      }[c] || c;
    });
  };
}
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('btn-new-wache');
        if (btn) btn.addEventListener('click', e => {
            e.preventDefault();
            openNewWacheModal();
        });
    });

    /**
     * Erzeugt die Optionsliste für das Bundesland-Select im Wachen-Modal.
     * Erwartet ein Mapping-Objekt { [land: string]: string[] }.
     *
     * @param {string} land - Länderkey (z. B. "Deutschland").
     * @param {string} selected - Vorbelegung (exakter Match in der Liste).
     * @param {Object.<string, string[]>} mapJson - Mapping Land → Bundesländer.
     * @returns {string} HTML-String mit <option>-Elementen.
     */
    function buildBundeslandOptionsForModal(land, selected, mapJson) {
        const list = (mapJson && mapJson[land]) || [];
        const opts = [];
        opts.push('<option value="">— Bitte wählen —</option>');
        opts.push('<option value="">' + 'Ohne Bundesland' + '</option>'); // leer = ohne
        for (const bl of list) {
            const sel = (selected === bl) ? ' selected' : '';
            opts.push('<option value="' + bl.replace(/"/g, '&quot;') + '"' + sel + '>' + bl + '</option>');
        }
        return opts.join('');
    }

    /**
     * Öffnet das Bearbeiten-Modal (Update) mit bestehenden Stationsdaten.
     * Nutzt #tmpl-wache-edit-form, fällt bei Bedarf auf bestehendes Formular #wache-edit-form zurück.
     * Initialisiert im Modal die Edit-Karte.
     *
     * Erwartete Felder in `wache`: id|wache_id, name, typ, latitude|lat, longitude|lon, arrival_pos|arrival, departure_pos|departure
     *
     * @param {Object} wache - Datensatz der Wache.
     */
    function openWacheModal(wache) {
        // 1) Daten normalisieren
        var id = (wache && (wache.id ?? wache.wache_id)) ?? '';
        var name = (wache && wache.name) ?? '';
        var typ = (wache && wache.typ) ?? '';

        var lat = parseFloat((wache && (wache.latitude ?? wache.lat)) ?? 51.0);
        var lon = parseFloat((wache && (wache.longitude ?? wache.lon)) ?? 9.0);
        if (!isFinite(lat)) lat = 51.0;
        if (!isFinite(lon)) lon = 9.0;

        var arrival = (wache && (wache.arrival_pos ?? wache.arrival)) ?? '';
        var depart = (wache && (wache.departure_pos ?? wache.departure)) ?? '';

        // 2) Template sicher holen (jQuery); wenn leer → Fallback auf vorhandenes Formular
        var tpl = $('#tmpl-wache-edit-form').html();
        if (!tpl) {
            // Fallback: existierendes Formular ins Modal verschieben
            var $form = $('#wache-edit-form');
            if (!$form.length) {
                alert('Weder Template (#tmpl-wache-edit-form) noch Formular (#wache-edit-form) gefunden.');
                return;
            }
            $('#wache-edit-modal .wache-edit-content').empty().append($form);
            $('#w-form-mode').val('update');
            $('#w-name').val(name);
            $('#w-typ').val(typ);
            $('#w-pos').val(lat.toFixed(6) + ', ' + lon.toFixed(6));
            $('#w-lat').val(lat.toFixed(6));
            $('#w-lon').val(lon.toFixed(6));
            $('#w-arr').val(arrival);
            $('#w-dep').val(depart);
            $('#wache-edit-modal').removeClass('hidden');
            requestAnimationFrame(function() {
                ensureWacheEditMap(lat, lon);
            });
            return;
        }

        // 3) Template rendern (unser einfacher Renderer kann nur {{key}})
        var html = renderTemplate(tpl, {
            id: id,
            name: name,
            typ: typ, // "selected" machen wir gleich via JS
            latitude: lat,
            longitude: lon,
            arrival_pos: arrival,
            departure_pos: depart
        });

        // 4) Modal befüllen/anzeigen
        var $modal = $('#wache-edit-modal');
        $modal.find('.wache-edit-content').html(html);
        $('#w-form-mode').val('update');
        $modal.removeClass('hidden');

        // 5) Nach dem Einfügen: Auswahllisten/Felder korrekt setzen
        $('#w-typ').val(typ); // ersetzt die {{typ==="FW"?"selected":""}}-Logik
        $('#w-pos').val(lat.toFixed(6) + ', ' + lon.toFixed(6));
        $('#w-lat').val(lat.toFixed(6));
        $('#w-lon').val(lon.toFixed(6));
        if (arrival) $('#w-arr').val(arrival);
        else $('#w-arr').val('');
        if (depart) $('#w-dep').val(depart);
        else $('#w-dep').val('');

        // 6) Karte im sichtbaren Modal initialisieren
        requestAnimationFrame(function() {
            ensureWacheEditMap(lat, lon);
        });

        // Map aus data-map am Land-Select ziehen
        const $landM = $('#mw-land');
        const $blM = $('#mw-bundesland');

        const mapJson = (function() {
            try {
                return JSON.parse($landM.attr('data-map') || '{}');
            } catch (e) {
                return {};
            }
        })();
        $landM.val('Deutschland');
        $blM.html(buildBundeslandOptionsForModal('Deutschland', '', mapJson));
        $landM.off('change.mw').on('change.mw', function() {
            $blM.html(buildBundeslandOptionsForModal($(this).val() || 'Deutschland', '', mapJson));
        });

        // Werte aus Datensatz
        var land = (wache && (wache.land || wache.country)) || 'Deutschland';
        var bl = (wache && wache.bundesland) || '';

        // Land setzen + BL-Liste aufbauen
        $landM.val(land);
        $blM.html(buildBundeslandOptionsForModal(land, bl, mapJson));

        // Änderung Land → BL neu füllen + Auswahl leeren
        $landM.off('change.mw').on('change.mw', function() {
            const newLand = $(this).val() || 'Deutschland';
            $blM.html(buildBundeslandOptionsForModal(newLand, '', mapJson));
        });
    }

    /** Markerstil für Hauptposition (rot). @type {ol.style.Style} */
    const styleMain = new ol.style.Style({
        image: new ol.style.Circle({
            radius: 6,
            fill: new ol.style.Fill({
                color: '#e31b23'
            })
        })
    });

    /** Markerstil für Anfahrt (grün). @type {ol.style.Style} */
    const styleArr = new ol.style.Style({
        image: new ol.style.Circle({
            radius: 5,
            fill: new ol.style.Fill({
                color: '#009b3a'
            })
        })
    });

    /** Markerstil für Abfahrt (blau). @type {ol.style.Style} */
    const styleDep = new ol.style.Style({
        image: new ol.style.Circle({
            radius: 5,
            fill: new ol.style.Fill({
                color: '#1f51ff'
            })
        })
    });

    /**
     * Wandelt einen "lat,lon"-String in ein LonLat-Tupel um.
     *
     * @param {string} str - Eingabe "lat,lon".
     * @returns {[number, number] | null} [lon, lat] oder null bei ungültig.
     */
    function strToLonLat(str) {
        const p = str.split(',');
        return p.length === 2 ? [parseFloat(p[1]), parseFloat(p[0])] : null;
    }

    /**
     * Schreibt ein LonLat (WGS84) als "lat, lon" in ein Input-Feld.
     *
     * @param {string} selectorLatLon - jQuery-Selector des Input-Feldes.
     * @param {[number, number]} lonLat - [lon, lat].
     */
    function lonLatToField(selectorLatLon, lonLat) {
        $(selectorLatLon).val(`${lonLat[1].toFixed(6)}, ${lonLat[0].toFixed(6)}`);
    }

    // --------------------------------------------------------
    // 1) Hilfsfunktion: Template mit Daten füllen
    // --------------------------------------------------------

    /**
     * Minimaler Template-Renderer für {{key}}-Platzhalter.
     *
     * @param {string} tpl - Template-HTML.
     * @param {Record<string, any>} data - Schlüsseldaten.
     * @returns {string} Gerendertes HTML.
     */
    function renderTemplate(tpl, data) {
        return tpl.replace(/\{\{(\w+)\}\}/g, function(_, key) {
            return data[key] !== undefined ? data[key] : '';
        });
    }

    // --------------------------------------------------------
    // 2) Karten- und Modal-Setup
    // --------------------------------------------------------

    /** @type {ol.View} */
    const view = new ol.View({
        center: ol.proj.fromLonLat([13.0, 52.5]),
        zoom: 8
    });

    /** Hauptkarte für die Wachen-Liste. @type {ol.Map} */
    const map = new ol.Map({
        target: 'wachen-map',
        layers: [new ol.layer.Tile({
            source: new ol.source.OSM()
        })],
        view: view
    });

    // Tooltip-Element (Klicks erlauben)
    const tooltipEl = document.createElement('div');
    tooltipEl.className = 'ol-tooltip ol-tooltip-hidden';
    tooltipEl.style.pointerEvents = 'auto';
    document.body.appendChild(tooltipEl);

    /** Overlay für Tooltip auf der Hauptkarte. @type {ol.Overlay} */
    const tooltipOverlay = new ol.Overlay({
        element: tooltipEl,
        offset: [0, -15],
        positioning: 'bottom-center',
        stopEvent: false
    });
    map.addOverlay(tooltipOverlay);

    // --------------------------------------------------------
    // 3) AJAX: Wachen laden und als Marker rendern
    // --------------------------------------------------------

    /**
     * Originale Farblogik für Wachen-Marker.
     * Hinweis: Diese Variante wird NUR in älteren Pfaden verwendet;
     * im aktuellen Code nutzen wir die identische Logik innerhalb
     * der styleFn in renderMarkers() (damit der Filter dort greift).
     *
     * @param {ol.Feature} feature
     * @returns {ol.style.Style}
     */
    function styleFn(feature) {
        const typ = feature.get('typ') || '';
        const isFW = (typ === 'FW' || typ === 'FRRD');
        const isRD = (typ === 'RD' || typ === 'FRRD');
        const isFFW = (typ === 'FFW');
        const isSEG = (typ === 'SEG');

        let fillColor = '#999';
        if (isFW && !isRD && !isFFW && !isSEG) fillColor = 'red';
        else if (!isFW && isRD && !isFFW && !isSEG) fillColor = 'blue';
        else if (isFW && isRD) fillColor = '#b700ff';
        else if (isFFW) fillColor = '#ff9999';
        else if (isSEG) fillColor = '#00800f';

        return new ol.style.Style({
            image: new ol.style.Circle({
                radius: 7,
                fill: new ol.style.Fill({
                    color: fillColor
                }),
                stroke: new ol.style.Stroke({
                    color: '#000',
                    width: 1
                })
            })
        });
    }

    /** Layer mit den Wachen-Markern. @type {ol.layer.Vector|null} */
    let markerLayer = null;

    /**
     * Aktueller Suchbegriff aus #wachen-search (für Tabellensuche UND Markerfilter).
     * @type {string}
     */
    let currentSearchTerm = '';

    /** AJAX-URL aus WP-Context. @type {string} */
    const AJAX_URL = (window.ajaxurl || (window.lstWachenAjax && window.lstWachenAjax.ajax_url));

    /**
     * Ersetzt alle Marker auf der Hauptkarte basierend auf einem Wachen-Array.
     * Berücksichtigt currentSearchTerm (Marker werden via StyleFn unsichtbar).
     *
     * @param {Array<{id:number,name:string,typ:string,latitude:number,longitude:number}>} wachen
     */
    function renderMarkers(wachen) {
        if (!map) return;

        if (markerLayer) {
            map.removeLayer(markerLayer);
            markerLayer = null;
        }

        const feats = [];
        for (const w of (wachen || [])) {
            const lat = parseFloat(w.latitude);
            const lon = parseFloat(w.longitude);
            if (!isFinite(lat) || !isFinite(lon)) continue;

            const f = new ol.Feature({
                geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
                id: w.id,
                name: w.name || '',
                typ: w.typ || ''
            });

            // Suchbare Felder vormerken (String + lowercase Name)
            f.set('idStr', String(w.id || ''));
            f.set('nameLC', (w.name || '').toLowerCase());

            feats.push(f);
        }

        const vectorSource = new ol.source.Vector({
            features: feats
        });

        /**
         * Karten-Style mit eingebautem Textfilter.
         * Gibt bei Nicht-Match `null` zurück → Feature wird nicht gerendert.
         *
         * @param {ol.Feature} feature
         * @returns {ol.style.Style|null}
         */
        const styleFn = function(feature) {
            // Filter: Marker ausblenden, wenn Suchbegriff nicht passt
            const term = (currentSearchTerm || '').toLowerCase().trim();
            if (term) {
                const idTxt = feature.get('idStr') || '';
                const nameTxt = feature.get('nameLC') || '';
                if (!(idTxt.includes(term) || nameTxt.includes(term))) {
                    return null; // nicht rendern
                }
            }

            // ORIGINAL-FARBLOGIK UNVERÄNDERT ÜBERNEHMEN
            const typ = feature.get('typ') || '';
            const isFW = (typ === 'FW' || typ === 'FRRD');
            const isRD = (typ === 'RD' || typ === 'FRRD');
            const isFFW = (typ === 'FFW');
            const isSEG = (typ === 'SEG');

            let fillColor = '#999';
            if (isFW && !isRD && !isFFW && !isSEG) fillColor = 'red';
            else if (!isFW && isRD && !isFFW && !isSEG) fillColor = 'blue';
            else if (isFW && isRD) fillColor = '#b700ff';
            else if (isFFW) fillColor = '#ff9999';
            else if (isSEG) fillColor = '#00800f';

            return new ol.style.Style({
                image: new ol.style.Circle({
                    radius: 7,
                    fill: new ol.style.Fill({
                        color: fillColor
                    }),
                    stroke: new ol.style.Stroke({
                        color: '#000',
                        width: 1
                    })
                })
            });
        };

        markerLayer = new ol.layer.Vector({
            source: vectorSource,
            style: styleFn
        });
        map.addLayer(markerLayer);

        const ext = vectorSource.getExtent();
        if (!ol.extent.isEmpty(ext)) {
            view.fit(ext, {
                padding: [50, 50, 50, 50],
                maxZoom: 12
            });
        }
    }

    /**
     * Rendert die Tabelle (#wachen-table tbody) basierend auf dem Wachen-Array.
     * Initialisiert die Tabellen-Enhancements (Sortierung, Suche) einmalig.
     *
     * @param {Array<{id:number,name:string,typ:string,latitude:number,longitude:number}>} wachen
     */
function renderTable(wachen) {
  const tbody = document.getElementById('wachen-tbody') || document.querySelector('#wachen-table tbody');
  if (!tbody) return;

  if (!wachen || wachen.length === 0) {
    tbody.innerHTML = '<tr><td colspan="99"><em>Keine Wachen gefunden.</em></td></tr>';
    return;
  }

  // Tabellen-Enhancements nur einmal initialisieren
  initWachenTableEnhancements();

  // Admin-URL für Link zur Fahrzeug-Seite
  const baseAdmin = new URL(window.location.origin + '/wp-admin/admin.php');

  tbody.innerHTML = wachen.map(w => {
    const id   = w.id ?? '';
    const name = w.name ?? '';
    const typ  = w.typ ?? '';
    const lat  = w.latitude ?? '';
    const lon  = w.longitude ?? '';

    // Zahl der Fahrzeuge (API-Feldnamen tolerant)
	// neu – Strings werden korrekt zu Zahlen
	const fcnt = parseInt(
	  (w.fahrzeuge_count ?? w.cnt ?? w.vehicles ?? 0),
	  10
	) || 0;

    // Link auf Fahrzeuge-Ansicht mit Filter auf diese Wache
    const fahrzeugUrl = new URL(baseAdmin);
    fahrzeugUrl.searchParams.set('page', 'lsttraining_fahrzeuge');
    fahrzeugUrl.searchParams.set('wache_id', String(id));

    return `
      <tr data-id="${id}" data-fcount="${fcnt}">
        <td>${id}</td>
        <td>${escapeHtml(name)}</td>
        <td>${escapeHtml(typ)}</td>
        <td style="white-space:nowrap;">${lat}, ${lon}</td>
        <td class="col-fahrzeuge" style="text-align:center;">
          <a href="${fahrzeugUrl.toString()}" title="Fahrzeuge dieser Wache anzeigen">${fcnt}</a>
        </td>
        <td>
          <button class="button edit-wache" data-id="${id}">Bearbeiten</button>
        </td>
      </tr>
    `;
  }).join('');
}


    /**
     * Lädt Wachen gefiltert nach Leitstelle, Nebenleitstelle oder Bundesland.
     * Ergebnis wird in Karte und Tabelle gerendert.
     *
     * @param {number} ls - Leitstellen-ID (oder 0).
     * @param {number} nls - Nebenleitstellen-ID (oder 0).
     * @param {string} bl - Bundesland ('' = kein BL-Filter).
     * @returns {Promise<{count:number,wachen:Array}>}
     */
    function loadWachen(ls, nls, bl) {
        const hasFilter =
            (parseInt(ls, 10) || 0) ||
            (parseInt(nls, 10) || 0) ||
            ((bl || '') !== '');

        if (!hasFilter) {
            renderMarkers([]);
            renderTable([]);
            return Promise.resolve({
                count: 0,
                wachen: []
            });
        }

        const params = new URLSearchParams({
            action: 'lsttraining_get_wachen'
        });
        if (ls) params.set('ls_id', String(ls));
        if (nls) params.set('nls_id', String(nls));
        if (bl) params.set('bundesland', bl);

        return fetch(AJAX_URL + '?' + params.toString(), {
                credentials: 'same-origin'
            })
            .then(res => {
                const ct = res.headers.get('content-type') || '';
                if (!res.ok) return res.text().then(t => {
                    throw new Error('HTTP ' + res.status + ': ' + t.slice(0, 200));
                });
                if (!ct.includes('application/json')) return res.text().then(t => {
                    throw new Error('Antwort kein JSON: ' + t.slice(0, 200));
                });
                return res.json();
            })
            .then(json => {
                if (!json || json.success !== true) {
                    const msg = (json && json.data && (json.data.msg || json.data)) || 'Unbekannter Fehler';
                    throw new Error(msg);
                }
                const data = json.data || {
                    wachen: []
                };
                renderMarkers(data.wachen);
                renderTable(data.wachen);
                return data;
            })
            .catch(err => {
                console.error('Fehler beim Laden der Wachen:', err);
                // Marker leeren; Tabelle so belassen, damit man den alten Stand noch sieht
                renderMarkers([]);
            });
    }

    // --------------------------------------------------------
    // 4) Tabellen-Button: Modal öffnen
    // --------------------------------------------------------

    $( 'body' ).on('click', '.edit-wache', function(e) {
        e.preventDefault();
        const id = $(this).data('id');

        $.get(lstWachenAjax.ajax_url, {
            action: 'lsttraining_get_wache',
            wache_id: id
        }).done(res => {
            if (!res.success) {
                alert('Fehler: ' + res.data);
                return;
            }
            /* einheitlicher Öffner – zeigt Modal & initialisiert Karte */
            openWacheModal(res.data);
        });
    });

    /**
     * Initialisiert Sortierung & Suche der Tabelle.
     * Die Suche filtert zusätzlich die Marker auf der Karte (via currentSearchTerm + markerLayer.changed()).
     */
    function initWachenTableEnhancements() {
        var table = document.getElementById('wachen-table');
        var tbody = document.getElementById('wachen-tbody') || (table && table.tBodies[0]);
        var search = document.getElementById('wachen-search');
        if (!tbody) return;

        /**
         * Liefert den Textinhalt einer Tabellenzelle.
         *
         * @param {HTMLTableRowElement} tr
         * @param {number} idx - Spaltenindex.
         * @returns {string}
         */
        function cellText(tr, idx) {
            var td = tr.cells[idx];
            return td ? (td.textContent || '').trim() : '';
        }

        /**
         * Sortiert die Rows im tbody nach einer Spalte.
         *
         * @param {number} colIdx - Spaltenindex.
         * @param {'num'|'text'} type - Sorttyp.
         * @param {'asc'|'desc'} dir - Richtung.
         */
        function sortRows(colIdx, type, dir) {
            var rows = Array.prototype.slice.call(tbody.rows);
            var mul = (dir === 'desc') ? -1 : 1;

            rows.sort(function(a, b) {
                var av = cellText(a, colIdx);
                var bv = cellText(b, colIdx);
                if (type === 'num') {
                    var an = parseFloat(av.replace(',', '.')) || 0;
                    var bn = parseFloat(bv.replace(',', '.')) || 0;
                    return (an < bn ? -1 : an > bn ? 1 : 0) * mul;
                } else {
                    return av.toLowerCase().localeCompare(bv.toLowerCase()) * mul;
                }
            });

            var frag = document.createDocumentFragment();
            rows.forEach(function(tr) {
                frag.appendChild(tr);
            });
            tbody.appendChild(frag);
        }

        // Sortier-Header nur einmal binden
if (table && !table.__sortBound) {
  var ths = table.querySelectorAll('thead th[data-sort]');
  ths.forEach(function(th) {
    th.addEventListener('click', function() {
      var key = th.getAttribute('data-sort'); // id | name | typ | fahrzeuge
      var map = { id: 0, name: 1, typ: 2, fahrzeuge: 4 };
      var idx = map[key];
      if (idx == null) return;

      var current = th.getAttribute('data-dir') || 'asc';
      var next = current === 'asc' ? 'desc' : 'asc';

      // Pfeilzustand zurücksetzen und auf aktuellem TH setzen
      ths.forEach(function(other) { other.removeAttribute('data-dir'); });
      th.setAttribute('data-dir', next);

      // Typ bestimmen: id und fahrzeuge sind Zahlen
      var type = (key === 'id' || key === 'fahrzeuge') ? 'num' : 'text';
      sortRows(idx, type, next);
    });
  });
  table.__sortBound = true;
}
        // Suche: immer auf aktuelle Rows zugreifen, nicht cachen
        if (search && !search.__filterBound) {
            /**
             * Filtert Tabelle und triggert das Redraw der Marker (Style-Abgleich).
             *
             * @param {string} q - Suchstring.
             */
            function applyFilter(q) {
                var qLower = (q || '').toLowerCase();

                // 1) Tabelle filtern
                var rows = tbody.rows;
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    var idTxt = cellText(r, 0).toLowerCase();
                    var nameTxt = cellText(r, 1).toLowerCase();
                    var match = (qLower === '') || idTxt.indexOf(qLower) !== -1 || nameTxt.indexOf(qLower) !== -1;
                    r.style.display = match ? '' : 'none';
                }

                // 2) Karte filtern (Style-Funktion nutzt currentSearchTerm)
                currentSearchTerm = q || '';
                if (markerLayer) {
                    markerLayer.changed(); // StyleFn erneut ausführen lassen
                }
            }

            // initial anwenden (falls bereits vorbefüllt)
            applyFilter(search.value);

            search.addEventListener('input', function() {
                applyFilter(this.value);
            });

            search.__filterBound = true;
        }
    }

    // --------------------------------------------------------
    // 5) Tooltip: marker click & pencil button
    // --------------------------------------------------------

    map.on('singleclick', evt => {
        const feature = map.forEachFeatureAtPixel(evt.pixel, f => f);
        if (feature) {
            tooltipEl.innerHTML = `
      <span class="wache-name">${feature.get('name')}</span>
      <button class="edit-wache-tooltip"
              data-id="${feature.get('id')}"
              title="Bearbeiten">
        <span class="dashicons dashicons-edit"></span>
      </button>
    `;
            tooltipEl.classList.remove('ol-tooltip-hidden');
            tooltipOverlay.setPosition(evt.coordinate);
        } else {
            tooltipOverlay.setPosition(undefined);
            tooltipEl.classList.add('ol-tooltip-hidden');
        }
    });

    /* delegated listener so the canvas stays clickable */
    $(document).on('click', '.edit-wache-tooltip', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');

        /* hide tooltip immediately – it would otherwise cover the modal */
        tooltipOverlay.setPosition(undefined);
        tooltipEl.classList.add('ol-tooltip-hidden');

        $.get(lstWachenAjax.ajax_url, {
            action: 'lsttraining_get_wache',
            wache_id: id
        }).done(res => {
            if (!res.success) {
                alert('Fehler: ' + res.data);
                return;
            }
            /* unified opener: renders HTML, shows modal, builds map */
            openWacheModal(res.data);
        });
    });

    // --------------------------------------------------------
    // 6) Cancel & Submit im Modal
    // --------------------------------------------------------

    $('body').on(
        'click',
        '#wache-edit-cancel, #wache-edit-modal .wache-edit-overlay',
        () => {

            /* --- destroy OpenLayers instance so next open gets a fresh map --- */
            if (window.mapWEdit) {
                window.mapWEdit.setTarget(null); // detach canvas / release size cache
                window.mapWEdit = null; // allow garbage collection
            }

            /* hide modal + clear its content */
            $('#wache-edit-modal')
                .addClass('hidden')
                .find('.wache-edit-content').empty();
        }
    );

    $('body').on('submit', '#wache-edit-form', function(e) {
        e.preventDefault();

        /* ---------- convert "lat, lon" → hidden fields ---------- */
        const pos = $('#w-pos').val().split(',');
        if (pos.length === 2) {
            $('#w-lat').val(parseFloat(pos[0]));
            $('#w-lon').val(parseFloat(pos[1]));
        }

        /* ---------- collect form data & send via AJAX ---------- */

        const mode = $('#w-form-mode').val(); // "create" oder "update"
        const data = $(this).serializeArray()
            .reduce((o, kv) => {
                o[kv.name] = kv.value;
                return o;
            }, {
                action: mode === 'create'
                    ? 'lsttraining_create_wache' // INSERT-Hook
                    : 'lsttraining_save_wache'   // UPDATE-Hook
            });

        $.post(lstWachenAjax.ajax_url, data).done(res => {
            if (res.success) {

                /* close modal + clean map */
                if (window.mapWEdit) {
                    window.mapWEdit.setTarget(null);
                    window.mapWEdit = null;
                }
                $('#wache-edit-modal')
                    .addClass('hidden')
                    .find('.wache-edit-content').empty();

                /* reload list without losing filters */
                const ls = $('#ls_id').val();
                const nls = $('#nls_id').val();
                const bl = $('#bundesland').val();
                loadWachen(ls, nls, bl);

            } else {
                alert('Fehler: ' + res.data);
            }
        });
    });

    // --------------------------------------------------------
    // 7) Initial, Live-Filter und gegenseitiges Zurücksetzen
    // --------------------------------------------------------

    /**
     * Schaltet die Filter-Selects gegeneinander exklusiv.
     * Deaktiviert je nach Auswahl die anderen Selects.
     */
    function updateDisabled() {
        const hasLS = parseInt($ls.val(), 10) || 0;
        const hasNLS = parseInt($nls.val(), 10) || 0;
        const hasBL = ($bl.val() || '').trim() !== '';

        $ls.prop('disabled', !!hasNLS || !!hasBL);
        $nls.prop('disabled', !!hasLS || !!hasBL);
        $bl.prop('disabled', !!hasLS || !!hasNLS);
    }

    /** @type {jQuery} */
    const $ls = $('#ls_id');
    /** @type {jQuery} */
    const $nls = $('#nls_id');
    /** @type {jQuery} */
    const $bl = $('#bundesland');
    /** @type {jQuery} */
    const $land = $('#land');

    // Länder→Bundesländer-Mapping aus data-map lesen (und NICHT "map" nennen!)
    /**
     * Mapping Land → Bundesländer (aus data-map des BL-Selects).
     * @type {Object.<string, string[]>}
     */
    const blMap = (function() {
        try {
            return JSON.parse($bl.attr('data-map') || '{}');
        } catch (e) {
            return {};
        }
    })();

    /**
     * Füllt das Bundesland-Select basierend auf dem Land.
     *
     * @param {string} land - Länderkey (z. B. "Deutschland").
     * @param {string} selected - Vorbelegung.
     */
    function fillBundeslaender(land, selected) {
        const arr = blMap[land] || [];
        const opts = [];
        opts.push('<option value="">— Bitte wählen —</option>');
        opts.push('<option value="__none__"' + (selected === '__none__' ? ' selected' : '') + '>Ohne Bundesland</option>');
        for (var i = 0; i < arr.length; i++) {
            var bl = arr[i];
            var sel = (selected === bl) ? ' selected' : '';
            opts.push('<option value="' + bl.replace(/"/g, '&quot;') + '"' + sel + '>' + bl + '</option>');
        }
        $bl.html(opts.join(''));
    }

    // Initial befüllen (falls Land aus GET kommt)
    if ($land.length && $bl.length) {
        fillBundeslaender($land.val() || 'Deutschland', $bl.val() || '');
        if (!$land.val()) {
            $land.val('Deutschland');
        }
        fillBundeslaender($land.val(), $bl.val() || '');
        // Land-Wechsel: BL-Liste neu aufbauen, LS/NLS leeren
        $land.on('change', function() {
            fillBundeslaender($land.val() || 'Deutschland', '');
            if ($ls.length) $ls.val('0');
            if ($nls.length) $nls.val('0');
            updateDisabled();
            // Kein sofortiges loadCurrent hier – erst bei BL-Änderung laden
        });

		// Land-Wechsel
		$land.off('change.filters').on('change.filters', function () {
		  fillBundeslaender($land.val() || 'Deutschland', '');
		  if ($ls.length) $ls.val('0');
		  if ($nls.length) $nls.val('0');
		  updateDisabled();
		  // kein loadCurrent hier – erst wenn BL gewählt/geleert wird
		});

		// Bundesland-Wechsel (nur dieser eine Handler!)
		$bl.off('change.filters').on('change.filters', function () {
		  if ($ls.length) $ls.val('0');
		  if ($nls.length) $nls.val('0');
		  updateDisabled();
		  loadCurrent();
		});
    }

    /**
     * Liest die aktuellen Filterwerte und lädt die Wachen.
     */
    function loadCurrent() {
        const ls = parseInt($ls.val(), 10) || 0;
        const nls = parseInt($nls.val(), 10) || 0;
        const bl = ($bl.val() || '').trim();
        loadWachen(ls, nls, bl);
    }

    // nur laden, wenn initial ein Filter gesetzt ist
    if ((parseInt($ls.val(), 10) || 0) || (parseInt($nls.val(), 10) || 0) || (($bl.val() || '').trim() !== '')) {
        loadCurrent();
    }
    updateDisabled();

    $ls.on('change', function() {
        $nls.val('0');
        $bl.val('');
        updateDisabled();
        loadCurrent();
    });
    $nls.on('change', function() {
        $ls.val('0');
        $bl.val('');
        updateDisabled();
        loadCurrent();
    });


    // --------------------------------------------------------
    // 8) Delete im Modal
    // --------------------------------------------------------

    $( 'body' ).on('click', '.button-delete-wache', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        if (!confirm('Wirklich löschen? Dieser Vorgang ist unwiderruflich.')) {
            return;
        }
        $.post(lstWachenAjax.ajax_url, {
            action: 'lsttraining_delete_wache',
            wache_id: id
        }).done(res => {
            if (!res.success) {
                return alert('Fehler beim Löschen: ' + res.data);
            }
            // Modal schließen und Liste/Karte neu laden
            $('#wache-edit-modal').addClass('hidden')
                .find('.wache-edit-content').empty();
            $('select[name="ls_id"], select[name="nls_id"]').trigger('change');
        }).fail(() => {
            alert('Netzwerkfehler beim Löschen.');
        });
    });

    /**
     * Erzeugt / aktualisiert die Karte im Wachen-Modal.
     * Stellt drei (drag-bare) Marker dar:
     *  - main: Station (rot)
     *  - arr : Arrival (grün, optional)
     *  - dep : Departure (blau, optional)
     *
     * Interaktionen:
     *  - Shift + Klick → Anfahrtsmarker setzen/verschieben
     *  - Ctrl  + Klick → Abfahrtsmarker setzen/verschieben
     *
     * Änderungen werden live zurück in die Felder geschrieben.
     *
     * @param {number} lat - Latitude der Station.
     * @param {number} lon - Longitude der Station.
     */
    function ensureWacheEditMap(lat, lon) {

        /* -------------------------------------------------- */
        /* 1) features & source                               */
        /* -------------------------------------------------- */
        const mainLL = [lon, lat];
        const arrLL = strToLonLat($('#w-arr').val());
        const depLL = strToLonLat($('#w-dep').val());

        const mainFt = new ol.Feature({
            geometry: new ol.geom.Point(ol.proj.fromLonLat(mainLL))
        });
        let arrFt = arrLL ? new ol.Feature({
            geometry: new ol.geom.Point(ol.proj.fromLonLat(arrLL))
        }) : null;
        let depFt = depLL ? new ol.Feature({
            geometry: new ol.geom.Point(ol.proj.fromLonLat(depLL))
        }) : null;

        mainFt.setStyle(styleMain);
        if (arrFt) arrFt.setStyle(styleArr);
        if (depFt) depFt.setStyle(styleDep);

        const vSrc = new ol.source.Vector({
            features: [mainFt].concat(arrFt || [], depFt || [])
        });

        /* -------------------------------------------------- */
        /* 2) map                                             */
        /* -------------------------------------------------- */
        window.mapWEdit = new ol.Map({
            target: 'map_wache_edit',
            layers: [
                new ol.layer.Tile({
                    source: new ol.source.OSM()
                }),
                new ol.layer.Vector({
                    source: vSrc
                })
            ],
            view: new ol.View({
                center: ol.proj.fromLonLat(mainLL),
                zoom: 14
            })
        });

        /* -------------------------------------------------- */
        /* 3) drag-modify interaction                         */
        /* -------------------------------------------------- */
        mapWEdit.addInteraction(new ol.interaction.Modify({
            source: vSrc
        }));

        /* on drag → update corresponding field */
        vSrc.getFeatures().forEach(ft => {
            ft.getGeometry().on('change', () => {
                const [x, y] = ol.proj.toLonLat(ft.getGeometry().getCoordinates());
                if (ft === mainFt) {
                    $('#w-pos').val(`${y.toFixed(6)}, ${x.toFixed(6)}`);
                    $('#w-lat').val(y.toFixed(6));
                    $('#w-lon').val(x.toFixed(6));
                } else if (ft === arrFt) {
                    lonLatToField('#w-arr', [x, y]);
                } else if (ft === depFt) {
                    lonLatToField('#w-dep', [x, y]);
                }
            });
        });

        /* -------------------------------------------------- */
        /* 4) hot-clicks: Shift / Ctrl                        */
        /* -------------------------------------------------- */
        mapWEdit.on('singleclick', evt => {
            const lonLat = ol.proj.toLonLat(evt.coordinate);

            if (evt.originalEvent.shiftKey) { // Arrival
                if (!arrFt) {
                    arrFt = new ol.Feature({
                        geometry: new ol.geom.Point(evt.coordinate)
                    });
                    arrFt.setStyle(styleArr);
                    vSrc.addFeature(arrFt);
                } else {
                    arrFt.getGeometry().setCoordinates(evt.coordinate);
                }
                lonLatToField('#w-arr', lonLat);
            }

            if (evt.originalEvent.ctrlKey) { // Departure
                if (!depFt) {
                    depFt = new ol.Feature({
                        geometry: new ol.geom.Point(evt.coordinate)
                    });
                    depFt.setStyle(styleDep);
                    vSrc.addFeature(depFt);
                } else {
                    depFt.getGeometry().setCoordinates(evt.coordinate);
                }
                lonLatToField('#w-dep', lonLat);
            }
        });

		/* -------------------------------------------------- */
/* 4b) Eingaben -> Marker & View aktualisieren        */
/* -------------------------------------------------- */

/** Robust "lat, lon" Parser -> {lat, lon} | null */
function parseLatLon(str) {
  if (!str) return null;
  var m = String(str).trim().match(/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/);
  if (!m) return null;
  var lat = parseFloat(m[1]), lon = parseFloat(m[2]);
  if (!isFinite(lat) || !isFinite(lon)) return null;
  return { lat: lat, lon: lon };
}

/** Marker bewegen + Karte zentrieren */
function moveAndCenter(feature, lat, lon, doCenter) {
  var coord3857 = ol.proj.fromLonLat([lon, lat]);
  feature.getGeometry().setCoordinates(coord3857);
  if (doCenter) {
    mapWEdit.getView().animate({ center: coord3857, duration: 250 });
  }
}

/* 4b.1) Main-Position: Eingabe in #w-pos */
$('#w-pos')
  .off('change.wpos blur.wpos input.wpos')
  .on('change.wpos blur.wpos', function () {
    var p = parseLatLon(this.value);
    if (!p) return;           // ungültig -> nichts tun
    moveAndCenter(mainFt, p.lat, p.lon, true);
    // Hidden-Felder angleichen (Geometrie-Listener macht das zwar auch, hier explizit):
    $('#w-lat').val(p.lat.toFixed(6));
    $('#w-lon').val(p.lon.toFixed(6));
  })
  // leichte Live-Reaktion beim Tippen (debounced)
  .on('input.wpos', (function () {
    var t = null;
    return function () {
      clearTimeout(t);
      var el = this;
      t = setTimeout(function () {
        var p = parseLatLon(el.value);
        if (!p) return;
        moveAndCenter(mainFt, p.lat, p.lon, false); // beim Tippen ohne Animation
      }, 300);
    };
  })());

/* 4b.2) Optional: Arrival/Departure aus Feldern setzen/verschieben */
$('#w-arr')
  .off('change.warr blur.warr input.warr')
  .on('change.warr blur.warr', function () {
    var p = parseLatLon(this.value);
    if (!p) return;
    if (!arrFt) {
      arrFt = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat([p.lon, p.lat])) });
      arrFt.setStyle(styleArr);
      vSrc.addFeature(arrFt);
    } else {
      moveAndCenter(arrFt, p.lat, p.lon, false);
    }
  });

$('#w-dep')
  .off('change.wdep blur.wdep input.wdep')
  .on('change.wdep blur.wdep', function () {
    var p = parseLatLon(this.value);
    if (!p) return;
    if (!depFt) {
      depFt = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat([p.lon, p.lat])) });
      depFt.setStyle(styleDep);
      vSrc.addFeature(depFt);
    } else {
      moveAndCenter(depFt, p.lat, p.lon, false);
    }
  });


        /* -------------------------------------------------- */
        /* 5) empty input  → marker removal                   */
        /* -------------------------------------------------- */
        $('#w-arr').on('input', function() {
            if (this.value.trim() === '' && arrFt) {
                vSrc.removeFeature(arrFt);
            }
        });
        $('#w-dep').on('input', function() {
            if (this.value.trim() === '' && depFt) {
                vSrc.removeFeature(depFt);
            }
        });
    }

    /**
     * Öffnet das Modal leer zum Anlegen einer neuen Wache.
     * Setzt den Formularmodus auf "create" und initialisiert die Edit-Karte.
     */
    function openNewWacheModal() {

        const tpl = $('#tmpl-wache-edit-form').html();
        const html = renderTemplate(tpl, {
            id: '',
            name: '',
            typ: '',
            latitude: 51.0,
            longitude: 9.0,
            arrival_pos: '',
            departure_pos: ''
        });

        $('#wache-edit-modal .wache-edit-content').html(html);

        /* Modus → create */
        $('#w-form-mode').val('create');
		
		// Land/Bundesland-Selects im CREATE-Modal initialisieren
		const $landM = $('#mw-land');
		const $blM   = $('#mw-bundesland');

		// Mapping aus data-map lesen
		const mapJson = (function(){
		  try { return JSON.parse($landM.attr('data-map') || '{}'); }
		  catch(e){ return {}; }
		})();

		// Default-Land setzen, falls leer
		if (!$landM.val()) {
		  $landM.val('Deutschland');
		}

		// Bundesländer-Liste zum Start befüllen
		$blM.html(
		  buildBundeslandOptionsForModal($landM.val() || 'Deutschland', '', mapJson)
		);

		// Wechsel des Landes → BL neu aufbauen
		$landM.off('change.mw').on('change.mw', function(){
		  const newLand = $(this).val() || 'Deutschland';
		  $blM.html(buildBundeslandOptionsForModal(newLand, '', mapJson));
		});


        /* Modal zuerst einblenden, dann Karte initialisieren */
        $('#wache-edit-modal').removeClass('hidden');
        requestAnimationFrame(() => {
            ensureWacheEditMap(51.0, 9.0); // Mitte DE
        });
    }

    // Mini-Filter für Nebenstellen-Select (separater UI-Block)
    (function() {
        const input = document.getElementById('nls_search');
        const select = document.getElementById('nls_id');
        if (!input || !select) return;

        // Presave alle Optionen
        const allOptions = Array.from(select.options);

        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();

            // immer die Default-Option behalten
            select.innerHTML = '';
            allOptions
                .filter(opt => opt.value === '0' || opt.text.toLowerCase().includes(term))
                .forEach(opt => select.appendChild(opt.cloneNode(true)));
        });
    })();

})(jQuery);
