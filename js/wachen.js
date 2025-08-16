// js/wachen.js

(function($){
	
	document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btn-new-wache');
  if (btn) btn.addEventListener('click', e => {
    e.preventDefault();
    openNewWacheModal();
  });
});
	
	/**
 * Öffnet das Bearbeiten-Modal mit bestehenden Daten.
 * Erwartet Felder: id, name, typ, latitude, longitude, arrival_pos, departure_pos
 * (Fallbacks auf wache_id / lat / lon sind eingebaut)
 */
function openWacheModal(wache) {
  // 1) Daten normalisieren
  var id   = (wache && (wache.id ?? wache.wache_id)) ?? '';
  var name = (wache && wache.name) ?? '';
  var typ  = (wache && wache.typ)  ?? '';

  var lat = parseFloat((wache && (wache.latitude ?? wache.lat)) ?? 51.0);
  var lon = parseFloat((wache && (wache.longitude ?? wache.lon)) ?? 9.0);
  if (!isFinite(lat)) lat = 51.0;
  if (!isFinite(lon)) lon = 9.0;

  var arrival = (wache && (wache.arrival_pos ?? wache.arrival)) ?? '';
  var depart  = (wache && (wache.departure_pos ?? wache.departure)) ?? '';

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
    requestAnimationFrame(function(){ ensureWacheEditMap(lat, lon); });
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
  if (arrival) $('#w-arr').val(arrival); else $('#w-arr').val('');
  if (depart)  $('#w-dep').val(depart);  else $('#w-dep').val('');

  // 6) Karte im sichtbaren Modal initialisieren
  requestAnimationFrame(function(){ ensureWacheEditMap(lat, lon); });
}

	
	
	const styleMain = new ol.style.Style({
  image: new ol.style.Circle({ radius: 6, fill: new ol.style.Fill({ color: '#e31b23' }) })
});
const styleArr  = new ol.style.Style({
  image: new ol.style.Circle({ radius: 5, fill: new ol.style.Fill({ color: '#009b3a' }) })
});
const styleDep  = new ol.style.Style({
  image: new ol.style.Circle({ radius: 5, fill: new ol.style.Fill({ color: '#1f51ff' }) })
});

/* Helper – lat/lon-String → [lon,lat] oder null */
function strToLonLat(str) {
  const p = str.split(',');
  return p.length === 2 ? [parseFloat(p[1]), parseFloat(p[0])] : null;
}

/* Helper – Feature-Koordinate → Feld aktualisieren */
function lonLatToField(selectorLatLon, lonLat) {
  $(selectorLatLon).val(`${lonLat[1].toFixed(6)}, ${lonLat[0].toFixed(6)}`);
}

	
  // --------------------------------------------------------
  // 1) Hilfsfunktion: Template mit Daten füllen
  // --------------------------------------------------------
  function renderTemplate(tpl, data) {
    return tpl.replace(/\{\{(\w+)\}\}/g, function(_, key){
      return data[key] !== undefined ? data[key] : '';
    });
  }

  // --------------------------------------------------------
  // 2) Karten- und Modal-Setup
  // --------------------------------------------------------
  const view = new ol.View({
    center: ol.proj.fromLonLat([13.0, 52.5]),
    zoom:   8
  });
  const map = new ol.Map({
    target: 'wachen-map',
    layers: [ new ol.layer.Tile({ source: new ol.source.OSM() }) ],
    view:  view
  });

  // Tooltip-Element (Klicks erlauben)
  const tooltipEl = document.createElement('div');
  tooltipEl.className = 'ol-tooltip ol-tooltip-hidden';
  tooltipEl.style.pointerEvents = 'auto';
  document.body.appendChild(tooltipEl);

  const tooltipOverlay = new ol.Overlay({
    element:     tooltipEl,
    offset:      [0, -15],
    positioning: 'bottom-center',
    stopEvent:   false
  });
  map.addOverlay(tooltipOverlay);

  // --------------------------------------------------------
  // 3) AJAX: Wachen laden und als Marker rendern
  // --------------------------------------------------------
// Style der Marker – VOR Benutzung definieren
function styleFn(feature) {
  const typ = feature.get('typ') || '';
  const isFW  = (typ === 'FW'   || typ === 'FRRD');
  const isRD  = (typ === 'RD'   || typ === 'FRRD');
  const isFFW = (typ === 'FFW');
  const isSEG = (typ === 'SEG');

  let fillColor = '#999';
  if (isFW && !isRD && !isFFW && !isSEG)      fillColor = 'red';
  else if (!isFW && isRD && !isFFW && !isSEG) fillColor = 'blue';
  else if (isFW && isRD)                      fillColor = '#b700ff';
  else if (isFFW)                             fillColor = '#ff9999';
  else if (isSEG)                             fillColor = '#00800f';

  return new ol.style.Style({
    image: new ol.style.Circle({
      radius: 7,
      fill:   new ol.style.Fill({ color: fillColor }),
      stroke: new ol.style.Stroke({ color: '#000', width: 1 })
    })
  });
}

let markerLayer = null;
const AJAX_URL = (window.ajaxurl || (window.lstWachenAjax && window.lstWachenAjax.ajax_url));

// Marker auf der Karte ersetzen
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

    const ft = new ol.Feature({
      geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
      id: w.id,
      name: w.name || '',
      typ: w.typ || ''
    });
    feats.push(ft);
  }

  const vectorSource = new ol.source.Vector({ features: feats });
  markerLayer = new ol.layer.Vector({ source: vectorSource, title: 'wachen', style: styleFn });
  map.addLayer(markerLayer);

  const ext = vectorSource.getExtent();
  if (!ol.extent.isEmpty(ext)) {
    view.fit(ext, { padding: [50, 50, 50, 50], maxZoom: 12 });
  }
}

// Tabelle <tbody> neu rendern
function renderTable(wachen) {
  const tbody = document.querySelector('.widefat.fixed tbody');
  if (!tbody) return;

  if (!wachen || wachen.length === 0) {
    tbody.innerHTML = '<tr><td colspan="99"><em>Keine Wachen gefunden.</em></td></tr>';
    return;
  }

  tbody.innerHTML = wachen.map(w => `
    <tr data-id="${w.id}">
      <td>${w.id ?? ''}</td>
      <td>${w.name ?? ''}</td>
      <td>${w.typ ?? ''}</td>
      <td>${w.latitude ?? ''}, ${w.longitude ?? ''}</td>
      <td><button class="button edit-wache" data-id="${w.id}">Bearbeiten</button></td>
    </tr>
  `).join('');
}

// Daten laden (einziger gültiger Loader)
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

  const params = new URLSearchParams({ action: 'lsttraining_get_wachen' });
  if (ls)  params.set('ls_id', String(ls));
  if (nls) params.set('nls_id', String(nls));
  if (bl)  params.set('bundesland', bl);

  return fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
    .then(res => {
      const ct = res.headers.get('content-type') || '';
      if (!res.ok) return res.text().then(t => { throw new Error('HTTP ' + res.status + ': ' + t.slice(0, 200)); });
      if (!ct.includes('application/json')) return res.text().then(t => { throw new Error('Antwort kein JSON: ' + t.slice(0, 200)); });
      return res.json();
    })
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
      // Marker leeren; Tabelle so belassen, damit man den alten Stand noch sieht
      renderMarkers([]);
    });
}
	
// --------------------------------------------------------
// 4) Tabellen-Button: Modal öffnen
// --------------------------------------------------------
$('body').on('click', '.edit-wache', function (e) {
  e.preventDefault();
  const id = $(this).data('id');

  $.get(lstWachenAjax.ajax_url, {
    action:   'lsttraining_get_wache',
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
$(document).on('click', '.edit-wache-tooltip', function (e) {
  e.stopPropagation();
  const id = $(this).data('id');

  /* hide tooltip immediately – it would otherwise cover the modal */
  tooltipOverlay.setPosition(undefined);
  tooltipEl.classList.add('ol-tooltip-hidden');

  $.get(lstWachenAjax.ajax_url, {
    action:   'lsttraining_get_wache',
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
      window.mapWEdit.setTarget(null);   // detach canvas / release size cache
      window.mapWEdit = null;            // allow garbage collection
    }

    /* hide modal + clear its content */
    $('#wache-edit-modal')
      .addClass('hidden')
      .find('.wache-edit-content').empty();
  }
);

$('body').on('submit', '#wache-edit-form', function (e) {
  e.preventDefault();

  /* ---------- convert "lat, lon" → hidden fields ---------- */
  const pos = $('#w-pos').val().split(',');
  if (pos.length === 2) {
    $('#w-lat').val(parseFloat(pos[0]));
    $('#w-lon').val(parseFloat(pos[1]));
  }

  /* ---------- collect form data & send via AJAX ---------- */

	 const mode = $('#w-form-mode').val();           // "create" oder "update"
 const data = $(this).serializeArray()
   .reduce((o, kv) => { o[kv.name] = kv.value; return o; },
           { action: mode === 'create'
                       ? 'lsttraining_create_wache'  // INSERT-Hook
                       : 'lsttraining_save_wache'    // UPDATE-Hook
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
 const ls  = $('#ls_id').val();
 const nls = $('#nls_id').val();
 const bl  = $('#bundesland').val();
 loadWachen(ls, nls, bl);

    } else {
      alert('Fehler: ' + res.data);
    }
  });
});


// --------------------------------------------------------
// 7) Initial, Live-Filter und gegenseitiges Zurücksetzen (+ optionales Submit)
// --------------------------------------------------------

  function updateDisabled() {
  const hasLS  = parseInt($ls.val(), 10) || 0;
  const hasNLS = parseInt($nls.val(), 10) || 0;
  const hasBL  = ($bl.val() || '').trim() !== '';

  $ls.prop('disabled',  !!hasNLS || !!hasBL);
  $nls.prop('disabled', !!hasLS  || !!hasBL);
  $bl.prop('disabled',  !!hasLS  || !!hasNLS);
}

const $ls  = $('#ls_id');
const $nls = $('#nls_id');
const $bl  = $('#bundesland');

function loadCurrent() {
  const ls  = parseInt($ls.val(), 10) || 0;
  const nls = parseInt($nls.val(), 10) || 0;
  const bl  = ($bl.val() || '').trim();
  loadWachen(ls, nls, bl);
}

// nur laden, wenn initial ein Filter gesetzt ist
if ((parseInt($ls.val(),10)||0) || (parseInt($nls.val(),10)||0) || (($bl.val()||'').trim() !== '')) {
  loadCurrent();
}
updateDisabled();

$ls.on('change', function() {
  $nls.val('0'); $bl.val('');
  updateDisabled();
  loadCurrent();
});
$nls.on('change', function() {
  $ls.val('0'); $bl.val('');
  updateDisabled();
  loadCurrent();
});
$bl.on('change', function() {
  $ls.val('0'); $nls.val('0');
  updateDisabled();
  loadCurrent();
});
	
	
	// --------------------------------------------------------
// 8) Delete im Modal
// --------------------------------------------------------
$('body').on('click', '.button-delete-wache', function(e){
  e.preventDefault();
  const id = $(this).data('id');
  if (!confirm('Wirklich löschen? Dieser Vorgang ist unwiderruflich.')) {
    return;
  }
  $.post(lstWachenAjax.ajax_url, {
    action:    'lsttraining_delete_wache',
    wache_id:  id
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
 * (Re)builds the edit-map every time the modal opens.
 * Creates three drag-enabled markers:
 *   main  = station position   (red)
 *   arr   = arrival position   (green)   – optional
 *   dep   = departure position (blue)    – optional
 *
 * Shift + click  → add / move arrival marker
 * Ctrl  + click  → add / move departure marker
 *
 * @param {number} lat  Station latitude
 * @param {number} lon  Station longitude
 */
function ensureWacheEditMap(lat, lon) {

  /* -------------------------------------------------- */
  /* 1) features & source                               */
  /* -------------------------------------------------- */
  const mainLL = [lon, lat];
  const arrLL  = strToLonLat($('#w-arr').val());
  const depLL  = strToLonLat($('#w-dep').val());

  const mainFt = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat(mainLL)) });
  let arrFt  = arrLL ? new ol.Feature({           
                geometry: new ol.geom.Point(ol.proj.fromLonLat(arrLL))
              }) : null;
let depFt  = depLL ? new ol.Feature({           
                geometry: new ol.geom.Point(ol.proj.fromLonLat(depLL))
              }) : null;

  mainFt.setStyle(styleMain);
  if (arrFt) arrFt.setStyle(styleArr);
  if (depFt) depFt.setStyle(styleDep);

  const vSrc = new ol.source.Vector({ features: [mainFt].concat(arrFt || [], depFt || []) });

  /* -------------------------------------------------- */
  /* 2) map                                             */
  /* -------------------------------------------------- */
  window.mapWEdit = new ol.Map({
    target: 'map_wache_edit',
    layers: [
      new ol.layer.Tile({ source: new ol.source.OSM() }),
      new ol.layer.Vector({ source: vSrc })
    ],
    view: new ol.View({ center: ol.proj.fromLonLat(mainLL), zoom: 14 })
  });

  /* -------------------------------------------------- */
  /* 3) drag-modify interaction                         */
  /* -------------------------------------------------- */
  mapWEdit.addInteraction(new ol.interaction.Modify({ source: vSrc }));

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

   if (evt.originalEvent.shiftKey) {          // Arrival
  if (!arrFt) {
    arrFt = new ol.Feature({ geometry: new ol.geom.Point(evt.coordinate) }); // ← arrFt ohne window.
    arrFt.setStyle(styleArr);
    vSrc.addFeature(arrFt);
  } else {
    arrFt.getGeometry().setCoordinates(evt.coordinate);
  }
  lonLatToField('#w-arr', lonLat);
}

if (evt.originalEvent.ctrlKey) {           // Departure
  if (!depFt) {
    depFt = new ol.Feature({ geometry: new ol.geom.Point(evt.coordinate) }); // ← depFt ohne window.
    depFt.setStyle(styleDep);
    vSrc.addFeature(depFt);
  } else {
    depFt.getGeometry().setCoordinates(evt.coordinate);
  }
  lonLatToField('#w-dep', lonLat);
}
  });

  /* -------------------------------------------------- */
  /* 5) empty input  → marker removal                   */
  /* -------------------------------------------------- */
  $('#w-arr').on('input', function () {
    if (this.value.trim() === '' && arrFt) {
      vSrc.removeFeature(arrFt);
    }
  });
  $('#w-dep').on('input', function () {
    if (this.value.trim() === '' && depFt) {
      vSrc.removeFeature(depFt);
    }
  });
}

	/**
 * Öffnet das Modal leer zum Anlegen einer neuen Wache.
 */
function openNewWacheModal() {

  const tpl  = $('#tmpl-wache-edit-form').html();
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

  /* Modal zuerst einblenden, dann Karte initialisieren */
  $('#wache-edit-modal').removeClass('hidden');
  requestAnimationFrame(() => {
    ensureWacheEditMap(51.0, 9.0);          // Mitte DE
  });
}
	
;(function(){
  const input  = document.getElementById('nls_search');
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
