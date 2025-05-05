(function($) {
  // 1) Öffnen des Bearbeitungs‐Modals
  function openEditModal(wache) {
    // Template holen
    var tpl = $('#tmpl-wache-edit-form').html();
    // Daten einsetzen
    var html = tpl.replace(/\{\{(\w+)\}\}/g, function(_, key){
      return wache[key] !== undefined ? wache[key] : '';
    });
    // Modal füllen und anzeigen
    $('#wache-edit-modal .wache-edit-content').html(html);
    $('#wache-edit-modal').removeClass('hidden');
  }

  // 1a) Klick auf Tabelle-Button
  $(document).on('click', '.edit-wache', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    $.get(lstWachenAjax.ajax_url, {
      action: 'lsttraining_get_wache',
      wache_id: id
    }).done(function(res){
      if(!res.success){
        alert('Fehler: '+res.data);
        return;
      }
      openEditModal(res.data);
    });
  });

  // 1b) Klick auf Stift im Tooltip
  $(document).on('click', '.edit-wache-tooltip', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    $.get(lstWachenAjax.ajax_url, {
      action: 'lsttraining_get_wache',
      wache_id: id
    }).done(function(res){
      if(!res.success){
        alert('Fehler: '+res.data);
        return;
      }
      openEditModal(res.data);
    });
  });

  // 2) Modal abbrechen
  $(document).on('click', '#wache-edit-cancel, #wache-edit-modal .wache-edit-overlay', function(){
    $('#wache-edit-modal').addClass('hidden').find('.wache-edit-content').empty();
  });

  // 3) Modal‐Form abschicken
  $(document).on('submit', '#wache-edit-form', function(e){
    e.preventDefault();
    var data = $(this).serializeArray().reduce(function(o, kv){
      o[kv.name] = kv.value;
      return o;
    }, { action:'lsttraining_save_wache' });
    $.post(lstWachenAjax.ajax_url, data, function(res){
      if(res.success){
        alert('Gespeichert');
        $('#wache-edit-modal').addClass('hidden');
        // Tabelle & Karte neu laden
        $('select[name="ls_id"], select[name="nls_id"]').trigger('change');
      } else {
        alert('Fehler: '+res.data);
      }
    });
  });

})(jQuery);


(function() {
  // Karte initialisieren
  const view = new ol.View({
    center: ol.proj.fromLonLat([13.0, 52.5]),
    zoom: 8
  });
  const map = new ol.Map({
    target: 'wachen-map',
    layers: [ new ol.layer.Tile({ source: new ol.source.OSM() }) ],
    view
  });

  // Tooltip‐Element und Overlay
  const tooltipEl = document.createElement('div');
  tooltipEl.className = 'ol-tooltip ol-tooltip-hidden';
  document.body.appendChild(tooltipEl);
  const tooltipOverlay = new ol.Overlay({
    element: tooltipEl, offset: [0,-15], positioning: 'bottom-center'
  });
  map.addOverlay(tooltipOverlay);

  // Lade Wachen per AJAX und zeichne
  function loadWachen(lsId, nlsId) {
    const params = new URLSearchParams();
    if (lsId)  params.set('ls_id', lsId);
    if (nlsId) params.set('nls_id', nlsId);

    fetch(`${lstWachenAjax.ajax_url}?action=lsttraining_get_wachen&${params}`)
      .then(r => r.json())
      .then(json => {
        if (!json.success) throw new Error(json.data || 'Server-Fehler');
        const data = json.data;

        // alte Layer entfernen
        map.getLayers().getArray()
          .filter(l => l.get('title')==='wachen')
          .forEach(l=>map.removeLayer(l));

        // Features bauen
        const features = data.map(w => {
          const f = new ol.Feature({
            geometry: new ol.geom.Point(
              ol.proj.fromLonLat([+w.longitude, +w.latitude])
            ),
            id: w.id,
            name: w.name,
            typ: w.typ
          });
          return f;
        });

        // Style nach typ
        const styleFn = feat => {
          const t = feat.get('typ') || '';
          const fr = /\bFR\b/.test(t)||/\bELW\b/.test(t)||/\bKAT\b/.test(t);
          const rd = /\bRTW\b/.test(t)||/\bNEF\b/.test(t)||/\bKTW\b/.test(t);
          let col = '#999';
          if(fr&&!rd) col='red';
          else if(!fr&&rd) col='blue';
          else if(fr&&rd) col='purple';
          return new ol.style.Style({
            image: new ol.style.Circle({
              radius:7,
              fill: new ol.style.Fill({color:col}),
              stroke: new ol.style.Stroke({color:'#000',width:1})
            })
          });
        };

        const src = new ol.source.Vector({features});
        const layer = new ol.layer.Vector({
          source: src, title:'wachen', style: styleFn
        });
        map.addLayer(layer);

        // Zoom auf alle
        const ext = src.getExtent();
        if(!ol.extent.isEmpty(ext)){
          view.fit(ext,{padding:[50,50,50,50],maxZoom:12});
        }
      })
      .catch(err => console.error('Wachen laden:',err));
  }

  // Cursor-Change
  map.on('pointermove', e => {
    if(e.dragging) return;
    map.getTargetElement().style.cursor =
      map.hasFeatureAtPixel(e.pixel) ? 'pointer' : '';
  });

  // Klick auf Karte: Tooltip anzeigen oder verstecken
  map.on('singleclick', e => {
    const feat = map.forEachFeatureAtPixel(e.pixel, f=>f);
    if (feat) {
      const id   = feat.get('id'),
            name = feat.get('name')||'–';
      tooltipEl.innerHTML = `
        <span class="wache-name">${name}</span>
        <button class="edit-wache-tooltip" data-id="${id}" title="Bearbeiten">
          <span class="dashicons dashicons-edit"></span>
        </button>
      `;
      tooltipEl.classList.remove('ol-tooltip-hidden');
      tooltipOverlay.setPosition(e.coordinate);
    } else {
      tooltipOverlay.setPosition(undefined);
      tooltipEl.classList.add('ol-tooltip-hidden');
    }
  });

  // Initial und bei Filter-Änderung
  document.addEventListener('DOMContentLoaded', () => {
    const ls = document.querySelector('select[name="ls_id"]'),
          nls = document.querySelector('select[name="nls_id"]');
    loadWachen(ls.value, nls.value);
    [ls,nls].forEach(s=>s.addEventListener('change',()=>{
      loadWachen(ls.value, nls.value);
    }));
  });
})();
