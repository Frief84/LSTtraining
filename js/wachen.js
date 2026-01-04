// js/wachen.js
(function () {
  function escapeHtml(input) {
    if (input == null) return '';
    return String(input).replace(/[&<>"'`=\/]/g, function (c) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
        '/': '&#x2F;',
        '`': '&#x60;',
        '=': '&#x3D;',
      }[c] || c);
    });
  }

  // Alles erst starten, wenn jQuery ready ist
  jQuery(function ($) {
    // --------------------------------------------------------
    // Globals
    // --------------------------------------------------------
    const AJAX_URL = (window.ajaxurl || (window.lstWachenAjax && window.lstWachenAjax.ajax_url) || '');
    const hasOL = () => (typeof window.ol !== 'undefined' && window.ol && typeof window.ol.Map === 'function');
    const hasSelect2 = () => !!(window.jQuery && $.fn && $.fn.select2);

    let view = null;
    let map = null;
    let tooltipEl = null;
    let tooltipOverlay = null;
    let markerLayer = null;
    let currentSearchTerm = '';

    // Fahrzeuge Cache (pro Wache)
    const fahrzeugeCache = Object.create(null); // { [wacheId]: {count, fahrzeuge} }
    const fahrzeugeLoading = Object.create(null); // { [wacheId]: true|false }

    // --------------------------------------------------------
    // Main Map
    // --------------------------------------------------------
    function ensureMainMapContainerHasSize(targetEl) {
      if (!targetEl) return;
      const rect = targetEl.getBoundingClientRect();
      if (!rect.height || rect.height < 50) targetEl.style.minHeight = '520px';
    }

    function initMainMapIfPresent() {
      const el = document.getElementById('wachen-map');
      if (!el) return false; // Seite ohne Map, ok

      if (!hasOL()) {
        console.error('wachen.js: OpenLayers (ol) fehlt. Prüfe enqueue von ol.js.');
        return false;
      }

      ensureMainMapContainerHasSize(el);

      view = new ol.View({
        center: ol.proj.fromLonLat([13.0, 52.5]),
        zoom: 8
      });

      map = new ol.Map({
        target: el,
        layers: [new ol.layer.Tile({ source: new ol.source.OSM() })],
        view: view
      });

      tooltipEl = document.createElement('div');
      tooltipEl.className = 'ol-tooltip ol-tooltip-hidden';
      tooltipEl.style.pointerEvents = 'auto';
      document.body.appendChild(tooltipEl);

      tooltipOverlay = new ol.Overlay({
        element: tooltipEl,
        offset: [0, -15],
        positioning: 'bottom-center',
        stopEvent: false
      });
      map.addOverlay(tooltipOverlay);

      map.on('singleclick', evt => {
        const feature = map.forEachFeatureAtPixel(evt.pixel, f => f);
        if (!feature) {
          tooltipOverlay.setPosition(undefined);
          tooltipEl.classList.add('ol-tooltip-hidden');
          return;
        }

        tooltipEl.innerHTML = `
          <span class="wache-name">${escapeHtml(feature.get('name') || '')}</span>
          <button class="edit-wache-tooltip" data-id="${feature.get('id')}" title="Bearbeiten">
            <span class="dashicons dashicons-edit"></span>
          </button>
        `;
        tooltipEl.classList.remove('ol-tooltip-hidden');
        tooltipOverlay.setPosition(evt.coordinate);
      });

      requestAnimationFrame(() => map && map.updateSize());
      return true;
    }

    // --------------------------------------------------------
    // Helpers: Template + Select2 + Länder
    // --------------------------------------------------------
    function renderTemplate(tpl, data) {
      return tpl.replace(/\{\{(\w+)\}\}/g, function (_, key) {
        return (data[key] !== undefined) ? data[key] : '';
      });
    }

    function buildBundeslandOptionsForModal(land, selected, mapJson) {
      const list = (mapJson && mapJson[land]) || [];
      const opts = [];
      opts.push('<option value="">— Bitte wählen —</option>');
      opts.push('<option value="">Ohne Bundesland</option>');
      for (const bl of list) {
        const sel = (selected === bl) ? ' selected' : '';
        opts.push('<option value="' + bl.replace(/"/g, '&quot;') + '"' + sel + '>' + escapeHtml(bl) + '</option>');
      }
      return opts.join('');
    }

    function stripPrefixes(s) {
      return String(s || '').replace(
        /^\s*(?:leitstelle|irls|feuerwehreinsatzzentrale|feuerwehr(-|\s)?einsatzzentrale|fez|lsz)\s+/i,
        ''
      );
    }

    function norm(s) {
      return String(s || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');
    }

    function select2PrefixAwareMatcher(params, data) {
      const term = norm(params && params.term ? params.term : '');
      if (!term) return data;

      if (data.children && data.children.length) {
        const matches = $.extend(true, {}, data);
        matches.children = data.children.filter(c => select2PrefixAwareMatcher(params, c));
        return matches.children.length ? matches : null;
      }

      const text = data.text || '';
      const alt = (data.element && data.element.getAttribute('data-alt')) || '';
      const hay = norm(text);
      const hayStripped = norm(stripPrefixes(text));
      const hayAlt = norm(alt);

      if (hay.includes(term) || hayStripped.includes(term) || (alt && hayAlt.includes(term))) return data;
      return null;
    }

    function select2PrefixAwareSorter(results) {
      return results.sort((a, b) => {
        const aS = norm(stripPrefixes(a.text));
        const bS = norm(stripPrefixes(b.text));
        return aS.localeCompare(bS, undefined, { sensitivity: 'base' });
      });
    }

    function enhanceMultiSelectsInModal() {
      if (!hasSelect2()) return;

      const $modal = $('#wache-edit-modal');
      const opts = {
        width: '100%',
        placeholder: 'Auswählen …',
        matcher: select2PrefixAwareMatcher,
        sorter: select2PrefixAwareSorter,
        minimumResultsForSearch: 0,
        closeOnSelect: false,
        dropdownParent: $modal
      };
      $('#mw-leitstellen').select2(opts);
      $('#mw-nebenleitstellen').select2(opts);
    }

    function waitForOptions(selector, min) {
      min = (typeof min === 'number' && min >= 0) ? min : 1;
      return new Promise(resolve => {
        const el = document.querySelector(selector);
        if (!el) return resolve();
        const ready = () => (el.querySelectorAll('option').length >= min);
        if (ready()) return resolve();

        const obs = new MutationObserver(() => {
          if (ready()) { obs.disconnect(); resolve(); }
        });
        obs.observe(el, { childList: true, subtree: true });
      });
    }

    function setMultiAfterOptions(selector, values) {
      values = (values || []).map(String);
      return waitForOptions(selector, 1).then(() => {
        const $sel = $(selector);
        $sel.val(values);
        $sel.trigger('change');
      });
    }

    // --------------------------------------------------------
    // Render Markers + Table
    // --------------------------------------------------------
    function renderMarkers(wachen) {
      if (!map || !view || !hasOL()) return;

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
        f.set('idStr', String(w.id || ''));
        f.set('nameLC', (w.name || '').toLowerCase());
        feats.push(f);
      }

      const vectorSource = new ol.source.Vector({ features: feats });

      const styleFn = function (feature) {
        const term = (currentSearchTerm || '').toLowerCase().trim();
        if (term) {
          const idTxt = feature.get('idStr') || '';
          const nameTxt = feature.get('nameLC') || '';
          if (!(idTxt.includes(term) || nameTxt.includes(term))) return null;
        }

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
            fill: new ol.style.Fill({ color: fillColor }),
            stroke: new ol.style.Stroke({ color: '#000', width: 1 })
          })
        });
      };

      markerLayer = new ol.layer.Vector({ source: vectorSource, style: styleFn });
      map.addLayer(markerLayer);

      const ext = vectorSource.getExtent();
      if (!ol.extent.isEmpty(ext)) {
        view.fit(ext, { padding: [50, 50, 50, 50], maxZoom: 12 });
      }

      requestAnimationFrame(() => map && map.updateSize());
    }

    function initWachenTableEnhancements() {
      const table = document.getElementById('wachen-table');
      const tbody = document.getElementById('wachen-tbody') || (table && table.tBodies[0]);
      const search = document.getElementById('wachen-search');
      if (!tbody) return;

      function cellText(tr, idx) {
        const td = tr.cells[idx];
        return td ? (td.textContent || '').trim() : '';
      }

      function sortRows(colIdx, type, dir) {
        const rows = Array.prototype.slice.call(tbody.rows);
        const mul = (dir === 'desc') ? -1 : 1;

        rows.sort(function (a, b) {
          const av = cellText(a, colIdx);
          const bv = cellText(b, colIdx);
          if (type === 'num') {
            const an = parseFloat(av.replace(',', '.')) || 0;
            const bn = parseFloat(bv.replace(',', '.')) || 0;
            return (an < bn ? -1 : an > bn ? 1 : 0) * mul;
          }
          return av.toLowerCase().localeCompare(bv.toLowerCase()) * mul;
        });

        const frag = document.createDocumentFragment();
        rows.forEach(tr => frag.appendChild(tr));
        tbody.appendChild(frag);
      }

      if (table && !table.__sortBound) {
        const ths = table.querySelectorAll('thead th[data-sort]');
        ths.forEach(th => {
          th.addEventListener('click', function () {
            const key = th.getAttribute('data-sort');
            const mapIdx = { id: 0, name: 1, typ: 2, fahrzeuge: 4 };
            const idx = mapIdx[key];
            if (idx == null) return;

            const current = th.getAttribute('data-dir') || 'asc';
            const next = (current === 'asc') ? 'desc' : 'asc';

            ths.forEach(other => other.removeAttribute('data-dir'));
            th.setAttribute('data-dir', next);

            const type = (key === 'id' || key === 'fahrzeuge') ? 'num' : 'text';
            sortRows(idx, type, next);
          });
        });
        table.__sortBound = true;
      }

      if (search && !search.__filterBound) {
        function applyFilter(q) {
          const qLower = (q || '').toLowerCase();

          const rows = tbody.rows;
          for (let i = 0; i < rows.length; i++) {
            const r = rows[i];
            const idTxt = cellText(r, 0).toLowerCase();
            const nameTxt = cellText(r, 1).toLowerCase();
            const match = (qLower === '') || idTxt.includes(qLower) || nameTxt.includes(qLower);
            r.style.display = match ? '' : 'none';
          }

          currentSearchTerm = q || '';
          if (markerLayer) markerLayer.changed();
        }

        applyFilter(search.value);
        search.addEventListener('input', function () { applyFilter(this.value); });
        search.__filterBound = true;
      }
    }

    function renderTable(wachen) {
      const tbody = document.getElementById('wachen-tbody') || document.querySelector('#wachen-table tbody');
      if (!tbody) return;

      if (!wachen || wachen.length === 0) {
        tbody.innerHTML = '<tr><td colspan="99"><em>Keine Wachen gefunden.</em></td></tr>';
        return;
      }

      initWachenTableEnhancements();

      const baseAdmin = new URL(window.location.origin + '/wp-admin/admin.php');

      tbody.innerHTML = wachen.map(w => {
        const id = w.id ?? '';
        const name = w.name ?? '';
        const typ = w.typ ?? '';
        const lat = w.latitude ?? '';
        const lon = w.longitude ?? '';
        const fcnt = parseInt((w.fahrzeuge_count ?? w.cnt ?? w.vehicles ?? 0), 10) || 0;

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
            <td><button class="button edit-wache" data-id="${id}">Bearbeiten</button></td>
          </tr>
        `;
      }).join('');
    }

    function loadWachen(ls, nls, bl) {
      const hasFilter =
        (parseInt(ls, 10) || 0) ||
        (parseInt(nls, 10) || 0) ||
        ((bl || '') !== '');

      if (!hasFilter) {
        renderMarkers([]);
        renderTable([]);
        return Promise.resolve({ count: 0, wachen: [] });
      }

      if (!AJAX_URL) {
        console.error('wachen.js: AJAX_URL fehlt (lstWachenAjax.ajax_url oder window.ajaxurl).');
        renderMarkers([]);
        renderTable([]);
        return Promise.resolve({ count: 0, wachen: [] });
      }

      const params = new URLSearchParams({ action: 'lsttraining_get_wachen' });
      if (ls) params.set('ls_id', String(ls));
      if (nls) params.set('nls_id', String(nls));
      if (bl) params.set('bundesland', bl);

      return fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
        .then(res => res.json())
        .then(json => {
          if (!json || json.success !== true) {
            const msg = (json && json.data && (json.data.msg || json.data)) || 'Unbekannter Fehler';
            throw new Error(msg);
          }
          const data = json.data || { wachen: [] };
          renderMarkers(data.wachen);
          renderTable(data.wachen);
          return data;
        })
        .catch(err => {
          console.error('Fehler beim Laden der Wachen:', err);
          renderMarkers([]);
        });
    }

    // --------------------------------------------------------
    // Fahrzeuge (Count sofort, Liste erst beim Aufklappen)
    // --------------------------------------------------------
    function getFahrzeugeNonce() {
      return (window.lstFahrzeugeAjax && window.lstFahrzeugeAjax.nonce)
        || (window.lstWachenAjax && window.lstWachenAjax.fahrzeuge_nonce)
        || '';
    }

    function fetchFahrzeugeForWache(wacheId) {
      if (!AJAX_URL) return Promise.reject(new Error('AJAX_URL fehlt'));
      const nonce = getFahrzeugeNonce();

      const params = new URLSearchParams({
        action: 'lsttraining_list_fahrzeuge_by_wache',
        wache_id: String(wacheId),
        nonce: nonce
      });

      return fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
          if (!res || !res.success) {
            const msg = (res && res.data && res.data.msg) ? res.data.msg : 'Fehler beim Laden';
            throw new Error(msg);
          }
          return res.data || { count: 0, fahrzeuge: [] };
        });
    }

    function setFahrzeugeCount(count) {
      const el = document.getElementById('wache-fahrzeuge-count');
      if (el) el.textContent = String(count || 0);
    }

    function renderFahrzeugeTableFromData(data) {
      const tbody = document.querySelector('#wache-fahrzeuge-table tbody');
      if (!tbody) return;

      const fahrzeuge = (data && data.fahrzeuge) ? data.fahrzeuge : [];
      if (!fahrzeuge.length) {
        tbody.innerHTML = '<tr><td colspan="5"><em>Keine Fahrzeuge zugeordnet.</em></td></tr>';
        return;
      }

      tbody.innerHTML = fahrzeuge.map(f => `
        <tr data-fid="${f.id}">
          <td>${escapeHtml(f.rufname || '')}</td>
          <td>${escapeHtml(f.fahrzeugtyp || '')}</td>
          <td>${escapeHtml(f.fms_status || '')}</td>
          <td>${f.is_first_responder ? 'Ja' : 'Nein'}</td>
          <td>
            <button type="button" class="button button-small wache-fahrzeug-edit" data-id="${f.id}">Bearbeiten</button>
            <button type="button" class="button button-small wache-fahrzeug-del" data-id="${f.id}">Löschen</button>
          </td>
        </tr>
      `).join('');
    }

    function ensureFahrzeugeLoaded(wacheId) {
      const wid = parseInt(wacheId, 10) || 0;
      if (!wid) return Promise.resolve({ count: 0, fahrzeuge: [] });

      if (fahrzeugeCache[wid]) return Promise.resolve(fahrzeugeCache[wid]);
      if (fahrzeugeLoading[wid]) {
        return new Promise(resolve => {
          const t = setInterval(() => {
            if (!fahrzeugeLoading[wid] && fahrzeugeCache[wid]) {
              clearInterval(t);
              resolve(fahrzeugeCache[wid]);
            }
          }, 50);
        });
      }

      fahrzeugeLoading[wid] = true;
      return fetchFahrzeugeForWache(wid)
        .then(data => {
          fahrzeugeCache[wid] = data;
          return data;
        })
        .finally(() => { fahrzeugeLoading[wid] = false; });
    }

    function initFahrzeugeUI(wacheId) {
      const wid = parseInt(wacheId, 10) || 0;
      if (!wid) return;

      // Count sofort laden, Tabelle noch nicht rendern
      ensureFahrzeugeLoaded(wid)
        .then(data => setFahrzeugeCount(data.count || (data.fahrzeuge ? data.fahrzeuge.length : 0)))
        .catch(err => {
          setFahrzeugeCount(0);
          const tbody = document.querySelector('#wache-fahrzeuge-table tbody');
          if (tbody) tbody.innerHTML = '<tr><td colspan="5"><em>' + escapeHtml(err.message || 'Fehler beim Laden') + '</em></td></tr>';
        });
    }

    function openFahrzeugePanel(wacheId) {
      const panel = document.getElementById('wache-fahrzeuge-panel');
      const tbody = document.querySelector('#wache-fahrzeuge-table tbody');
      if (!panel || !tbody) return;

      panel.style.display = '';
      tbody.innerHTML = '<tr><td colspan="5"><em>Lade Fahrzeuge…</em></td></tr>';

      ensureFahrzeugeLoaded(wacheId)
        .then(data => {
          setFahrzeugeCount(data.count || (data.fahrzeuge ? data.fahrzeuge.length : 0));
          renderFahrzeugeTableFromData(data);
        })
        .catch(err => {
          setFahrzeugeCount(0);
          tbody.innerHTML = '<tr><td colspan="5"><em>' + escapeHtml(err.message || 'Fehler beim Laden') + '</em></td></tr>';
        });
    }

    function closeFahrzeugePanel() {
      const panel = document.getElementById('wache-fahrzeuge-panel');
      if (!panel) return;
      panel.style.display = 'none';
    }

    // Toggle Button
    $(document).on('click', '#wache-fahrzeuge-toggle', function () {
      const panel = document.getElementById('wache-fahrzeuge-panel');
      const wid = parseInt($(this).attr('data-wid') || '0', 10) || 0;

      if (!panel || !wid) return;

      const isOpen = (panel.style.display !== 'none' && panel.style.display !== '');
      // Hinweis: initial ist style="display:none", also panel.style.display === "none"
      if (panel.style.display === 'none') {
        openFahrzeugePanel(wid);
      } else {
        closeFahrzeugePanel();
      }
    });

    // --------------------------------------------------------
    // Modal Map (Edit) minimal, nur wenn ol da ist
    // --------------------------------------------------------
    function strToLonLat(str) {
      const p = String(str || '').split(',');
      return (p.length === 2) ? [parseFloat(p[1]), parseFloat(p[0])] : null;
    }

    function lonLatToField(selector, lonLat) {
      $(selector).val(`${lonLat[1].toFixed(6)}, ${lonLat[0].toFixed(6)}`);
    }

    function ensureWacheEditMap(lat, lon) {
      if (!hasOL()) return;
      const targetEl = document.getElementById('map_wache_edit');
      if (!targetEl) return;

      const styleMain = new ol.style.Style({ image: new ol.style.Circle({ radius: 6, fill: new ol.style.Fill({ color: '#e31b23' }) }) });
      const styleArr  = new ol.style.Style({ image: new ol.style.Circle({ radius: 5, fill: new ol.style.Fill({ color: '#009b3a' }) }) });
      const styleDep  = new ol.style.Style({ image: new ol.style.Circle({ radius: 5, fill: new ol.style.Fill({ color: '#1f51ff' }) }) });

      const mainLL = [lon, lat];
      const arrLL = strToLonLat($('#w-arr').val());
      const depLL = strToLonLat($('#w-dep').val());

      const mainFt = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat(mainLL)) });
      let arrFt = arrLL ? new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat(arrLL)) }) : null;
      let depFt = depLL ? new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat(depLL)) }) : null;

      mainFt.setStyle(styleMain);
      if (arrFt) arrFt.setStyle(styleArr);
      if (depFt) depFt.setStyle(styleDep);

      const vSrc = new ol.source.Vector({ features: [mainFt].concat(arrFt || [], depFt || []) });

      window.mapWEdit = new ol.Map({
        target: targetEl,
        layers: [
          new ol.layer.Tile({ source: new ol.source.OSM() }),
          new ol.layer.Vector({ source: vSrc })
        ],
        view: new ol.View({ center: ol.proj.fromLonLat(mainLL), zoom: 14 })
      });

      window.mapWEdit.addInteraction(new ol.interaction.Modify({ source: vSrc }));

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

      window.mapWEdit.on('singleclick', evt => {
        const lonLat = ol.proj.toLonLat(evt.coordinate);

        if (evt.originalEvent.shiftKey) {
          if (!arrFt) {
            arrFt = new ol.Feature({ geometry: new ol.geom.Point(evt.coordinate) });
            arrFt.setStyle(styleArr);
            vSrc.addFeature(arrFt);
          } else {
            arrFt.getGeometry().setCoordinates(evt.coordinate);
          }
          lonLatToField('#w-arr', lonLat);
        }

        if (evt.originalEvent.ctrlKey) {
          if (!depFt) {
            depFt = new ol.Feature({ geometry: new ol.geom.Point(evt.coordinate) });
            depFt.setStyle(styleDep);
            vSrc.addFeature(depFt);
          } else {
            depFt.getGeometry().setCoordinates(evt.coordinate);
          }
          lonLatToField('#w-dep', lonLat);
        }
      });

      requestAnimationFrame(() => window.mapWEdit && window.mapWEdit.updateSize());
    }

    // --------------------------------------------------------
    // Modal open/create
    // --------------------------------------------------------
    function openWacheModal(wache) {
      const id = (wache && (wache.id ?? wache.wache_id)) ?? '';
      const name = (wache && wache.name) ?? '';
      const typ = (wache && wache.typ) ?? '';

      let lat = parseFloat((wache && (wache.latitude ?? wache.lat)) ?? 51.0);
      let lon = parseFloat((wache && (wache.longitude ?? wache.lon)) ?? 9.0);
      if (!isFinite(lat)) lat = 51.0;
      if (!isFinite(lon)) lon = 9.0;

      const arrival = (wache && (wache.arrival_pos ?? wache.arrival)) ?? '';
      const depart = (wache && (wache.departure_pos ?? wache.departure)) ?? '';

      const tpl = $('#tmpl-wache-edit-form').html();
      if (!tpl) {
        alert('Template (#tmpl-wache-edit-form) nicht gefunden.');
        return;
      }

      const html = renderTemplate(tpl, {
        id: id,
        name: name,
        typ: typ,
        latitude: lat,
        longitude: lon,
        arrival_pos: arrival,
        departure_pos: depart
      });

      const $modal = $('#wache-edit-modal');
      $modal.find('.wache-edit-content').html(html);
      $('#w-form-mode').val('update');
      $modal.removeClass('hidden');

      enhanceMultiSelectsInModal();

      // Fahrzeuge Toggle mit Wache-ID markieren
      $('#wache-fahrzeuge-toggle').attr('data-wid', String(id));
      // Panel initial geschlossen
      closeFahrzeugePanel();
      // Count sofort laden
      initFahrzeugeUI(id);

      // Felder setzen
      $('#w-typ').val(typ);
      $('#w-pos').val(lat.toFixed(6) + ', ' + lon.toFixed(6));
      $('#w-lat').val(lat.toFixed(6));
      $('#w-lon').val(lon.toFixed(6));
      $('#w-arr').val(arrival || '');
      $('#w-dep').val(depart || '');

      // Multi selects vorbelegen
      const lsIds = Array.isArray(wache.leitstellen) ? wache.leitstellen.map(x => String((x && x.id) != null ? x.id : x)) : [];
      const nlsIds = Array.isArray(wache.nebenleitstellen) ? wache.nebenleitstellen.map(x => String((x && x.id) != null ? x.id : x)) : [];
      Promise.all([
        setMultiAfterOptions('#mw-leitstellen', lsIds),
        setMultiAfterOptions('#mw-nebenleitstellen', nlsIds)
      ]).then(() => {});

      // Land/BL
      const $landM = $('#mw-land');
      const $blM = $('#mw-bundesland');
      const mapJson = (() => { try { return JSON.parse($landM.attr('data-map') || '{}'); } catch (e) { return {}; } })();

      const land = (wache && (wache.land || wache.country)) || 'Deutschland';
      const bl = (wache && wache.bundesland) || '';
      $landM.val(land);
      $blM.html(buildBundeslandOptionsForModal(land, bl, mapJson));

      $landM.off('change.mw').on('change.mw', function () {
        const newLand = $(this).val() || 'Deutschland';
        $blM.html(buildBundeslandOptionsForModal(newLand, '', mapJson));
      });

      requestAnimationFrame(() => ensureWacheEditMap(lat, lon));
    }

    function openNewWacheModal() {
      const lat = 51.0, lon = 9.0;

      const tpl = $('#tmpl-wache-edit-form').html();
      if (!tpl) {
        alert('Template (#tmpl-wache-edit-form) nicht gefunden.');
        return;
      }

      const html = renderTemplate(tpl, {
        id: '',
        name: '',
        typ: '',
        latitude: lat,
        longitude: lon,
        arrival_pos: '',
        departure_pos: ''
      });

      const $modal = $('#wache-edit-modal');
      $modal.find('.wache-edit-content').html(html);
      $('#w-form-mode').val('create');
      $modal.removeClass('hidden');

      enhanceMultiSelectsInModal();

      // Fahrzeuge bei CREATE: ausblenden, kein Toggle nötig
      $('#wache-fahrzeuge-toggle').prop('disabled', true);
      setFahrzeugeCount(0);
      closeFahrzeugePanel();

      requestAnimationFrame(() => ensureWacheEditMap(lat, lon));
    }

    // --------------------------------------------------------
    // Handlers
    // --------------------------------------------------------
    $(document).on('click', '#btn-new-wache', function (e) {
      e.preventDefault();
      openNewWacheModal();
    });

    $('body').on('click', '.edit-wache', function (e) {
      e.preventDefault();
      const id = $(this).data('id');
      $.get((window.lstWachenAjax && window.lstWachenAjax.ajax_url) || AJAX_URL, {
        action: 'lsttraining_get_wache',
        wache_id: id
      }).done(res => {
        if (!res || !res.success) return alert('Fehler: ' + (res && res.data ? res.data : 'Unbekannt'));
        openWacheModal(res.data);
      });
    });

    $(document).on('click', '.edit-wache-tooltip', function (e) {
      e.stopPropagation();
      const id = $(this).data('id');

      if (tooltipOverlay) tooltipOverlay.setPosition(undefined);
      if (tooltipEl) tooltipEl.classList.add('ol-tooltip-hidden');

      $.get((window.lstWachenAjax && window.lstWachenAjax.ajax_url) || AJAX_URL, {
        action: 'lsttraining_get_wache',
        wache_id: id
      }).done(res => {
        if (!res || !res.success) return alert('Fehler: ' + (res && res.data ? res.data : 'Unbekannt'));
        openWacheModal(res.data);
      });
    });

    $('body').on('click', '#wache-edit-cancel, #wache-edit-modal .wache-edit-overlay', function () {
      if (window.mapWEdit) {
        window.mapWEdit.setTarget(null);
        window.mapWEdit = null;
      }
      $('#wache-edit-modal').addClass('hidden').find('.wache-edit-content').empty();
    });

    $('body').on('submit', '#wache-edit-form', function (e) {
      e.preventDefault();

      const raw = String($('#w-pos').val() || '').split(',');
      if (raw.length === 2) {
        const lat = parseFloat((raw[0] || '').trim());
        const lon = parseFloat((raw[1] || '').trim());
        if (isFinite(lat) && isFinite(lon)) {
          $('#w-lat').val(lat);
          $('#w-lon').val(lon);
        }
      }

      const mode = $('#w-form-mode').val();
      let payload = $(this).serialize();

      if (!$('#mw-leitstellen').val()) payload += '&leitstellen=';
      if (!$('#mw-nebenleitstellen').val()) payload += '&nebenleitstellen=';

      payload += '&action=' + encodeURIComponent(mode === 'create' ? 'lsttraining_create_wache' : 'lsttraining_save_wache');

      $.post((window.lstWachenAjax && window.lstWachenAjax.ajax_url) || AJAX_URL, payload)
        .done(res => {
          if (res && res.success) {
            if (window.mapWEdit) {
              window.mapWEdit.setTarget(null);
              window.mapWEdit = null;
            }
            $('#wache-edit-modal').addClass('hidden').find('.wache-edit-content').empty();

            const ls = $('#ls_id').val();
            const nls = $('#nls_id').val();
            const bl = $('#bundesland').val();
            loadWachen(ls, nls, bl);
          } else {
            alert('Fehler: ' + ((res && (res.data || res.message)) || 'Unbekannter Fehler beim Speichern'));
          }
        })
        .fail(xhr => {
          alert('Netzwerk-/Serverfehler: ' + (xhr && xhr.status ? ('HTTP ' + xhr.status) : 'unbekannt'));
        });
    });

    // --------------------------------------------------------
    // Filter Init (nur wenn Elemente existieren)
    // --------------------------------------------------------
    function updateDisabled() {
      const hasLS = parseInt($('#ls_id').val(), 10) || 0;
      const hasNLS = parseInt($('#nls_id').val(), 10) || 0;
      const hasBL = (String($('#bundesland').val() || '').trim() !== '');

      $('#ls_id').prop('disabled', !!hasNLS || !!hasBL);
      $('#nls_id').prop('disabled', !!hasLS || !!hasBL);
      $('#bundesland').prop('disabled', !!hasLS || !!hasNLS);
    }

    const $ls = $('#ls_id');
    const $nls = $('#nls_id');
    const $bl = $('#bundesland');
    const $land = $('#land');

    const blMap = (() => { try { return JSON.parse($bl.attr('data-map') || '{}'); } catch (e) { return {}; } })();

    function fillBundeslaender(land, selected) {
      const arr = blMap[land] || [];
      const opts = [];
      opts.push('<option value="">— Bitte wählen —</option>');
      opts.push('<option value="__none__"' + (selected === '__none__' ? ' selected' : '') + '>Ohne Bundesland</option>');
      for (let i = 0; i < arr.length; i++) {
        const blOpt = arr[i];
        const sel = (selected === blOpt) ? ' selected' : '';
        opts.push('<option value="' + blOpt.replace(/"/g, '&quot;') + '"' + sel + '>' + escapeHtml(blOpt) + '</option>');
      }
      $bl.html(opts.join(''));
    }

    function loadCurrent() {
      const ls = parseInt($ls.val(), 10) || 0;
      const nls = parseInt($nls.val(), 10) || 0;
      const bl = String($bl.val() || '').trim();
      loadWachen(ls, nls, bl);
    }

    // Start
    const mapOk = initMainMapIfPresent();

    if ($land.length && $bl.length) {
      fillBundeslaender($land.val() || 'Deutschland', $bl.val() || '');
      if (!$land.val()) $land.val('Deutschland');
      fillBundeslaender($land.val(), $bl.val() || '');

      $land.off('change.filters').on('change.filters', function () {
        fillBundeslaender($land.val() || 'Deutschland', '');
        if ($ls.length) $ls.val('0');
        if ($nls.length) $nls.val('0');
        updateDisabled();
      });

      $bl.off('change.filters').on('change.filters', function () {
        if ($ls.length) $ls.val('0');
        if ($nls.length) $nls.val('0');
        updateDisabled();
        loadCurrent();
      });
    }

    updateDisabled();

    $ls.on('change', function () {
      $nls.val('0');
      $bl.val('');
      updateDisabled();
      loadCurrent();
    });

    $nls.on('change', function () {
      $ls.val('0');
      $bl.val('');
      updateDisabled();
      loadCurrent();
    });

    // initial load nur wenn Filter gesetzt
    const hasInitFilter =
      (parseInt($ls.val(), 10) || 0) ||
      (parseInt($nls.val(), 10) || 0) ||
      (String($bl.val() || '').trim() !== '');

    if (hasInitFilter) loadCurrent();
    else {
      renderMarkers([]);
      renderTable([]);
      if (mapOk) requestAnimationFrame(() => map && map.updateSize());
    }
  });
})();
