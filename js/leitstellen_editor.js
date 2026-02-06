// ---------------------------------------------------------------------------
// includes/js/leitstellen_editor.js
// ---------------------------------------------------------------------------

(function(window, document, ol) {
    console.log('[leitstellen_editor.js] loaded');

    // — GeoJSON-Polygon-Editor —

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


    // — “+ Neue Leitstelle” Popup —

    function openLeitstellePopupForCreate() {
        const heading = document.querySelector('#edit-leitstelle-formular h2');
        if (heading) heading.textContent = 'Leitstelle erstellen';
        ['lst_update_id', 'lst_update_name', 'lst_update_ort', 'lst_update_bl', 'lst_update_land', 'lst_update_lat', 'lst_update_lon']
        .forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const mode = document.getElementById('lst_form_mode');
        if (mode) mode.value = 'create';
        if (typeof resetEditMaps === 'function') resetEditMaps();
        if (typeof ensureEditMap === 'function') ensureEditMap();
        const overlay = document.getElementById('popup-overlay');
        if (overlay) overlay.style.display = 'block';
        const popup = document.getElementById('edit-leitstelle-formular');
        if (popup) popup.style.display = 'block';
    }
    window.openLeitstellePopupForCreate = openLeitstellePopupForCreate;

    function ensureEditMap() {
        /* no-op */ }
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
        const btn = document.getElementById('btn-new-leitstelle');
        if (btn) {
            btn.addEventListener('click', e => {
                e.preventDefault();
                openLeitstellePopupForCreate();
            });
        }
    });

})(window, document, ol);



(function($){
  window.openLeitstelleHospitalsEditor = openLeitstelleHospitalsEditor;

  function openLeitstelleHospitalsEditor(id) {
    console.log('[leitstellen_editor] Button geklickt, ID=', id);
    $.getJSON(window.lstLeitstellenAjax.ajax_url, {
      action:        'get_leitstelle_hospitals',
      leitstelle_id: id
    })
    .done(json => {
      if (!json.success) {
        return alert('Fehler beim Laden: ' + json.data);
      }
      const data   = json.data;
      const tpl    = wp.template('leitstellen-hospitals-editor');
      const $modal = $('#leitstellen-hospitals-modal');

      // 1) Template rendern
      $modal.find('.modal-body').html(tpl({
        leitstelle_id:  data.leitstelle_id,
        hospitals:      data.hospitals,
        existing:       data.existing,
        geojson:        data.geojson,
        leitstelle_lat: data.leitstelle_lat,
        leitstelle_lon: data.leitstelle_lon
      }));

      // Flag: gibt es gespeicherte Hospitals?
      const hasExisting = Array.isArray(data.existing) && data.existing.length > 0;
      const existingArr = data.existing.map(n => String(n)); // IDs als Strings normalisieren

      // 2) Checkbox-Vorbelegung
      data.hospitals.forEach(h => {
        const sid = String(h.id);
        if (hasExisting) {
          // nur die in existing
          if (existingArr.includes(sid)) {
            $modal.find(`.hos-toggle[value="${sid}"]`).prop('checked', true);
          }
        }
      });
      // wenn keine existing: spätere Polygon-Selektion weiter unten

      // 3) Filter-Input binden
      $modal.find('#leitstellen-hospitals-filter').off('input').on('input', function(){
        const term = this.value.toLowerCase();
        $modal.find('#leitstellen-hospitals-selector label').each((_, lbl) => {
          const $lbl = $(lbl);
          const txt  = $lbl.text().toLowerCase();
          const idv  = $lbl.find('input').val();
          $lbl.toggle(txt.includes(term) || idv.includes(term));
        });
      });

      // 4) Karte initialisieren
      const mapDiv = $modal.find('#leitstellen-hospitals-map')[0];
      const format = new ol.format.GeoJSON();
      let geojsonObj;
      try {
        geojsonObj = typeof data.geojson === 'string'
          ? JSON.parse(data.geojson)
          : data.geojson;
      } catch {
        return alert('Ungültiges Einsatzgebiet');
      }
      // Polygon-Features
      const polyFeats = format.readFeatures(geojsonObj, {
        dataProjection:    'EPSG:4326',
        featureProjection: 'EPSG:3857'
      });
      const vectorSource = new ol.source.Vector({ features: polyFeats });
      const vectorLayer = new ol.layer.Vector({
        source: vectorSource,
        style: new ol.style.Style({
          stroke: new ol.style.Stroke({ color: '#0074D9', width: 2 }),
          fill:   new ol.style.Fill({ color: 'rgba(0,116,217,0.1)' })
        })
      });

      // Tooltip-Overlay
      const tooltipEl = document.createElement('div');
      tooltipEl.className = 'hospital-tooltip';
      document.body.appendChild(tooltipEl);
      const tooltipOverlay = new ol.Overlay({
        element: tooltipEl,
        offset:  [0, -10],
        positioning: 'bottom-center'
      });

      // 5) Hospitals-Layer aufbauen
      const hospSource = new ol.source.Vector();
      data.hospitals.forEach(h => {
        const coord = ol.proj.fromLonLat([ h.longitude, h.latitude ]);
        const feat  = new ol.Feature({
          geometry: new ol.geom.Point(coord),
          id:       String(h.id),
          name:     h.name
        });

        // prüfen, ob im Polygon
        const inPoly = vectorSource.getFeatures().some(pf =>
          pf.getGeometry().intersectsCoordinate(coord)
        );

        // Aktiv-Logic:
        // – wenn existing definiert: nur existing rot
        // – sonst (empty existing): alle im Polygon rot
        const isActive = hasExisting
          ? existingArr.includes(String(h.id))
          : inPoly;

        // Style setzen
        feat.setStyle(new ol.style.Style({
          image: new ol.style.Circle({
            radius: 7,
            fill:   new ol.style.Fill({ color: isActive ? 'red' : 'lightblue' }),
            stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
          })
        }));

        hospSource.addFeature(feat);

        // Checkbox nur im Polygon-Fall voreinstellen
        if (!hasExisting && inPoly) {
          $modal.find(`.hos-toggle[value="${h.id}"]`).prop('checked', true);
        }
      });
      const hospLayer = new ol.layer.Vector({ source: hospSource });

      // 6) Map anlegen
      const map = new ol.Map({
        target:   mapDiv,
        layers:   [
          new ol.layer.Tile({ source: new ol.source.OSM() }),
          vectorLayer,
          hospLayer
        ],
        overlays: [ tooltipOverlay ],
        view:     new ol.View({
          center: ol.proj.fromLonLat([data.leitstelle_lon, data.leitstelle_lat]),
          zoom:   10
        })
      });

      // 7) Tooltip-Handling
      map.on('pointermove', e => {
        const f = map.forEachFeatureAtPixel(e.pixel, feat => feat);
        if (f && f.get('name')) {
          tooltipEl.innerHTML = f.get('name');
          tooltipOverlay.setPosition(e.coordinate);
          tooltipEl.style.display = '';
        } else {
          tooltipEl.style.display = 'none';
        }
      });

      // 8) Klick auf Marker (Select-Interaction)
      const selectHosp = new ol.interaction.Select({
        layers:       [hospLayer],
        hitTolerance: 6,
        style:        null,
        condition:    ol.events.condition.singleClick
      });
      // Pan kurz deaktivieren, damit es nicht als DragPan interpretiert wird
      const dragPan = map.getInteractions().getArray()
                        .find(i => i instanceof ol.interaction.DragPan);
      selectHosp.on('select', () => {
        if (dragPan) dragPan.setActive(false);
        setTimeout(() => dragPan && dragPan.setActive(true), 0);
      });
      map.addInteraction(selectHosp);
      selectHosp.on('select', evt => {
        evt.selected.forEach(feat => {
          const hid  = feat.get('id');
          const $chk = $modal.find(`.hos-toggle[value="${hid}"]`);
          const now  = !$chk.prop('checked');
          $chk.prop('checked', now);
          // Marker nachkollorieren
          feat.setStyle(new ol.style.Style({
            image: new ol.style.Circle({
              radius: 7,
              fill:   new ol.style.Fill({ color: now ? 'red' : 'lightblue' }),
              stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
            })
          }));
        });
        selectHosp.getFeatures().clear();
      });

      // 9) Abbrechen
      $modal.find('#leitstellen-hospitals-cancel, .modal-close')
            .off('click')
            .on('click', ()=> $modal.addClass('hidden'));

      // 10) Speichern
      $modal.find('#leitstellen-hospitals-form')
            .off('submit')
            .on('submit', e => {
        e.preventDefault();
        const selected = $modal.find('.hos-toggle:checked')
                                .map((_,el)=>el.value).get();
        $.ajax({
          url:      window.lstLeitstellenAjax.ajax_url,
          method:   'POST',
          dataType: 'json',
          data: {
            action:         'save_leitstelle_hospitals',
            leitstelle_id:  id,
            hospitals:      JSON.stringify(selected)
          },
          success(resp) {
            if (!resp.success) {
              return alert('Fehler beim Speichern: ' + resp.data);
            }
            alert('Gespeichert');
            $modal.addClass('hidden');
          },
          error(jq, status, err) {
            console.error('Save-Error:', status, err);
            alert('Fehler beim Speichern: ' + status);
          }
        });
      });

      // 11) Modal anzeigen
      $modal.removeClass('hidden');
    })
    .fail((_,status,err) => {

      console.error('[leitstellen_editor] AJAX-Fehler', status, err);
      alert('AJAX-Fehler: ' + status);
    });
  }

  // Klick-Handler auf unseren Button
  $(document).on('click', '.open-leitstelle-hospitals-editor', function(e){
    e.preventDefault();
    const id = $('#lst_update_id').val();
    openLeitstelleHospitalsEditor(id);
  });

document.addEventListener('DOMContentLoaded', function () {
  if (typeof window.wireZuordnungButtonCommon !== 'function') {
    console.warn('[lsttraining] wireZuordnungButtonCommon fehlt');
    return;
  }

  window.wireZuordnungButtonCommon({
    buttonId  : 'w_zuord_button_l',
    entityType: 'leitstelle',
    getEntityId: function () {
      var v = (document.getElementById('lst_update_id') || {}).value || '';
      if (!v) v = new URLSearchParams(location.search).get('ls_id') || '';
      return v;
    },
    watchIds: ['lst_update_id']
  });
});

document.addEventListener('click', function (e) {
  const btn = e.target.closest('#w_zuord_button_l');
  if (!btn) return;

  e.preventDefault();
  e.stopPropagation();

  const idEl = document.getElementById('lst_update_id');
  const id = parseInt(idEl?.value || '0', 10);
  if (!(id > 0)) return;

  if (typeof window.openZuordnungPopup === 'function') {
    window.openZuordnungPopup({
      entityType: 'leitstelle',
      entityId: id
    });
  }
});

function syncZuordnungButton() {
  const idEl = document.getElementById('lst_update_id');
  const btn  = document.getElementById('w_zuord_button_l');
  if (!btn || !idEl) return;

  const id = parseInt(idEl.value || '0', 10);
  btn.disabled = !(id > 0);
  btn.title = id > 0 ? '' : 'Bitte zuerst speichern';
}

document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('w_zuord_button_l');
  if (!btn) return;

  function getId(){
    return (document.getElementById('lst_update_id') || {}).value
        || new URLSearchParams(location.search).get('ls_id') || '';
  }
  function valid(v){ return /^\d+$/.test(v) && v !== '0'; }

  function sync(){
    var id = getId();
    btn.disabled = !valid(id);
    btn.title    = btn.disabled ? 'Bitte zuerst speichern' : '';
  }

  if (!btn._bound){
    btn._bound = true;
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var id = getId(); if (!valid(id)) return;
      window.openZuordnungPopup({ entityType:'leitstelle', entityId:id });
    });
  }

  sync();
  var idEl = document.getElementById('lst_update_id');
  if (idEl && !idEl._obs){
    idEl._obs = true;
    idEl.addEventListener('input',  sync);
    idEl.addEventListener('change', sync);
  }
});



})(jQuery);
