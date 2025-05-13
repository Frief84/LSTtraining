// js/wachen.js

(function($){

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
  function loadWachen(lsId, nlsId) {
    const params = new URLSearchParams();
    if (lsId)  params.set('ls_id', lsId);
    if (nlsId) params.set('nls_id', nlsId);

    fetch(lstWachenAjax.ajax_url + '?action=lsttraining_get_wachen&' + params.toString())
      .then(res => {
        if (!res.ok) throw new Error('Server antwortete mit ' + res.status);
        return res.json();
      })
      .then(json => {
        if (!json.success) throw new Error('Server-Fehler: ' + json.data);
        const data = json.data;

        // alte „wachen“-Layer entfernen
        map.getLayers().getArray()
          .filter(l => l.get('title') === 'wachen')
          .forEach(l => map.removeLayer(l));

        // Features erzeugen
        const features = data.map(w => new ol.Feature({
          geometry: new ol.geom.Point(
            ol.proj.fromLonLat([+w.longitude, +w.latitude])
          ),
          id:   w.id,
          name: w.name,
          typ:  w.typ
        }));

		// Style-Funktion: Kreis-Markierung, Farbe abhängig vom Typ-Code
		const styleFn = feature => {
		  const typ = feature.get('typ') || '';
		  const isFW  = (typ === 'FW'   || typ === 'FRRD');
		  const isRD  = (typ === 'RD'   || typ === 'FRRD');
		  const isFFW = (typ === 'FFW');
		  const isSEG = (typ === 'SEG');

		  let fillColor = '#999'; // Default-Grau
		  if (isFW && !isRD && !isFFW && !isSEG)      fillColor = 'red';      // Nur Feuerwehr
		  else if (!isFW && isRD && !isFFW && !isSEG) fillColor = 'blue';     // Nur Rettungsdienst
		  else if (isFW && isRD)                      fillColor = '#b700ff';     // FRRD: Rettungsdienst + Feuerwehr
		  else if (isFFW)                             fillColor = '#ff9999';  // Freiwillige Feuerwehr
		  else if (isSEG)                             fillColor = '#00800f';  // Sondereinsatzgruppe

		  return new ol.style.Style({
			image: new ol.style.Circle({
			  radius: 7,
			  fill:   new ol.style.Fill({ color: fillColor }),
			  stroke: new ol.style.Stroke({ color: '#000', width: 1 })
			})
		  });
		};


        const vectorSource = new ol.source.Vector({ features });
        const vectorLayer  = new ol.layer.Vector({
          source: vectorSource,
          title:  'wachen',
          style:  styleFn
        });
        map.addLayer(vectorLayer);

        // auf Marker zoomen
        const ext = vectorSource.getExtent();
        if (!ol.extent.isEmpty(ext)) {
          view.fit(ext, { padding: [50,50,50,50], maxZoom: 12 });
        }
      })
      .catch(err => console.error('Fehler beim Laden der Wachen:', err));
  }
/**
 * Rendert das Bearbeitungs-Formular ins Modal, blendet es ein
 * und initialisiert die Map erst, wenn der Container sichtbar ist.
 *
 * @param {Object} data   Wachen-Datensatz (AJAX-Antwort)
 */
function openWacheModal(data) {

  /* Template → HTML */
  const tpl  = $('#tmpl-wache-edit-form').html();
  const html = renderTemplate(tpl, data);

  $('#wache-edit-modal .wache-edit-content').html(html);

  /* Modal zuerst sichtbar machen */
  $('#wache-edit-modal').removeClass('hidden');

  /* im nächsten Frame: Karte + Dropdown setzen */
  requestAnimationFrame(() => {
    ensureWacheEditMap(+data.latitude, +data.longitude);
    $('#w-typ').val(data.typ);
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
  const data = $(this).serializeArray()
                      .reduce((o, kv) => { o[kv.name] = kv.value; return o; },
                              { action: 'lsttraining_save_wache' });

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
      const ls  = $('select[name="ls_id"]').val();
      const nls = $('select[name="nls_id"]').val();
      loadWachen(ls, nls);

    } else {
      alert('Fehler: ' + res.data);
    }
  });
});


// --------------------------------------------------------
// 7) Initial, Live-Filter und gegenseitiges Zurücksetzen (+ optionales Submit)
// --------------------------------------------------------
$(function(){
  const lsSelect  = $('#ls_id');
  const nlsSelect = $('#nls_id');
  const lsSearch  = $('#ls_search');
  const nlsSearch = $('#nls_search');
  const $form     = lsSelect.closest('form');

  // 7.1) Erst laden
  loadWachen(lsSelect.val(), nlsSelect.val());

  // 7.2) Live-Filter
  lsSearch.on('keyup', function(){
    const term = this.value.toLowerCase();
    lsSelect.find('option').each(function(){
      $(this).toggle( $(this).text().toLowerCase().includes(term) );
    });
  });
  nlsSearch.on('keyup', function(){
    const term = this.value.toLowerCase();
    nlsSelect.find('option').each(function(){
      $(this).toggle( $(this).text().toLowerCase().includes(term) );
    });
  });

  // 7.3) Wenn Leitstelle gewechselt …
  lsSelect.on('change', function(){
    // Nebenleitstelle zurücksetzen
    nlsSelect.val('0');
    // Karte neu laden
    loadWachen(lsSelect.val(), 0);
    // Tabelle neu laden (Seiten-Reload)
    $form.submit();
  });

  // 7.4) Wenn Nebenleitstelle gewechselt …
  nlsSelect.on('change', function(){
    // Leitstelle zurücksetzen
    lsSelect.val('0');
    // Karte neu laden
    loadWachen(0, nlsSelect.val());
    // Tabelle neu laden (Seiten-Reload)
    $form.submit();
  });
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

// ------------------- Edit-Karte --------------------
function ensureWacheEditMap(lat, lon) {

  const center  = ol.proj.fromLonLat([lon, lat]);
  const marker  = new ol.Feature({ geometry: new ol.geom.Point(center) });
  const vSource = new ol.source.Vector({ features: [marker] });

  window.mapWEdit = new ol.Map({
    target: 'map_wache_edit',
    layers: [
      new ol.layer.Tile({ source: new ol.source.OSM() }),
      new ol.layer.Vector({ source: vSource })
    ],
    view: new ol.View({ center, zoom: 14 })
  });

  mapWEdit.addInteraction(new ol.interaction.Modify({ source: vSource }));

  /* Hidden inputs synchronisieren */
  const sync = () => {
    const [x, y] = ol.proj.toLonLat(marker.getGeometry().getCoordinates());
    $('#w-pos').val(`${y.toFixed(6)}, ${x.toFixed(6)}`);
    $('#w-lat').val(y.toFixed(6));
    $('#w-lon').val(x.toFixed(6));
  };
  sync();
  marker.getGeometry().on('change', sync);
}

	// ------------------- Edit-Karte --------------------

	// Eingabefeld ändert Marker
$(document).on('change', '#w-pos', function(){
  const match = this.value.split(',');
  if (match.length === 2) {
    const lat = parseFloat(match[0]); const lon = parseFloat(match[1]);
    if (!isNaN(lat) && !isNaN(lon)) {
      $('#w-lat').val(lat); $('#w-lon').val(lon);
      ensureWacheEditMap(lat, lon);    // Marker springt zur neuen Pos.
    }
  }
});
	
})(jQuery);
