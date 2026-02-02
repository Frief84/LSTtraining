(function($, window, document, ol){
  'use strict';

  function toLonLat3857(lon, lat){
    return ol.proj.fromLonLat([Number(lon), Number(lat)]);
  }

  function readNumber(v){
    if (v === null || v === undefined || v === '') return null;
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function buildMarkerStyle(colorHex, isSelected){
    var fillColor = (typeof colorHex === 'string' && /^#[0-9a-fA-F]{6}$/.test(colorHex)) ? colorHex : '#888888';
    return new ol.style.Style({
      image: new ol.style.Circle({
        radius: isSelected ? 8 : 6,
        fill: new ol.style.Fill({ color: fillColor }),
        stroke: new ol.style.Stroke({ color: '#ffffff', width: isSelected ? 3 : 2 })
      })
    });
  }

  function normalizePoiTypes(poiTypes){
    var out = [];
    (poiTypes || []).forEach(function(t){
      if (typeof t === 'string') {
        out.push({ tag: t, color: '#888888', description: '' });
        return;
      }
      if (t && typeof t === 'object') {
        out.push({
          tag: String(t.tag || ''),
          color: String(t.color || '#888888'),
          description: String(t.description || '')
        });
      }
    });
    var seen = {};
    out = out.filter(function(x){
      if (!x.tag) return false;
      if (seen[x.tag]) return false;
      seen[x.tag] = true;
      return true;
    });
    out.sort(function(a,b){
      return String(a.tag).localeCompare(String(b.tag), undefined, { numeric: true, sensitivity: 'base' });
    });
    return out;
  }

  function genusNormalize(v){
    if (!v) return 'der';
    var s = String(v).trim().toLowerCase();
    if (s === 'f' || s === 'w' || s === 'die') return 'die';
    if (s === 'm' || s === 'der') return 'der';
    if (s === 'n' || s === 'das') return 'das';
    // fallback
    return 'der';
  }

  function parseCoord(str){
    // erwartet "lat,lon" oder "lat lon"
    if (!str) return null;
    var s = String(str).trim();
    var parts = s.split(/[,\s]+/).filter(Boolean);
    if (parts.length < 2) return null;
    var lat = Number(parts[0]);
    var lon = Number(parts[1]);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    return { lat: lat, lon: lon };
  }

  function escapeHtml(s){
    return $('<div>').text(String(s || '')).html();
  }

  // opts: { viewState: { center:[x,y], zoom:number, rotation?:number } }
  function openLeitstellePoisEditor(leitstelleId, opts){
    opts = opts || {};
    var passedViewState = opts.viewState || null;
    $.getJSON(window.lstLeitstellenAjax.ajax_url, {
      action: 'get_leitstelle_pois',
      leitstelle_id: leitstelleId,
      nonce: window.lstLeitstellenAjax.nonce
    })
    .done(function(resp){
      if (!resp || !resp.success) {
        var msg = resp && resp.data && resp.data.message ? resp.data.message : (resp && resp.data ? resp.data : 'Unbekannter Fehler');
        alert('Fehler beim Laden: ' + msg);
        return;
      }

      var data = resp.data;
      var tpl = wp.template('leitstellen-pois-editor');
      var $modal = $('#leitstellen-pois-modal');

      $modal.find('.modal-body').html(tpl({
        leitstelle_id: data.leitstelle_id,
        leitstelle_lat: data.leitstelle_lat,
        leitstelle_lon: data.leitstelle_lon,
        poi_types: data.poi_types || [],
        pois: data.pois || []
      }));

      // Elemente nach neuem Template/CSS
      var $listOverlay = $modal.find('#lst-poi-list');
      var $editorOverlay = $modal.find('#lst-poi-editor');
      var $table = $modal.find('#leitstellen-pois-table');
      var $filter = $modal.find('#leitstellen-pois-filter');

      var $btnToggleList = $modal.find('#lst-poi-toggle-list');
      var $btnCloseList = $modal.find('#lst-poi-close-list');
      var $btnOpenEditor = $modal.find('#lst-poi-open-editor');
      var $btnEditorClose = $modal.find('#lst-poi-editor-close');

      var $btnNew = $modal.find('#leitstellen-pois-new');
      var $btnDelete = $modal.find('#leitstellen-pois-delete');
      var $btnCancel = $modal.find('#leitstellen-pois-cancel');

      // Import UI
      var $importPanel = $modal.find('#lst-poi-import-panel');
      var $btnImport = $modal.find('#lst-poi-import');
      var $btnImportParse = $modal.find('#lst-poi-import-parse');
      var $btnImportRun = $modal.find('#lst-poi-import-run');
      var $btnImportClose = $modal.find('#lst-poi-import-close');
      var $importText = $modal.find('#lst-poi-import-text');
      var $importPreview = $modal.find('#lst-poi-import-preview');

      var $form = $modal.find('#leitstellen-pois-form');
      var $id = $modal.find('#poi_id');
      var $type = $modal.find('#poi_type');
      var $typeDesc = $modal.find('#poi_type_desc');
      var $name = $modal.find('#poi_name');
      var $comment = $modal.find('#poi_comment');
      var $genus = $modal.find('#poi_genus');
      var $lat = $modal.find('#poi_lat');
      var $lon = $modal.find('#poi_lon');

      var selectedId = null;

      var poiTypes = normalizePoiTypes(data.poi_types || []);
      var typeMeta = {};
      poiTypes.forEach(function(t){
        typeMeta[t.tag] = { color: t.color, description: t.description };
      });

      function updateTypeDescription(){
        var tag = $type.val();
        var meta = typeMeta[tag] || {};
        var html = '';
        if (meta.color) {
          html += '<span class="lst-poi-color-dot" style="background:' + meta.color + ';"></span>';
        }
        if (meta.description) {
          html += escapeHtml(meta.description);
        }
        $typeDesc.html(html);
      }

      function openList(open){
        if (open) $listOverlay.addClass('is-open');
        else $listOverlay.removeClass('is-open');
      }

      function openEditor(open){
        if (open) $editorOverlay.addClass('is-open');
        else $editorOverlay.removeClass('is-open');
      }
      // Backward-compat alias
      function setEditorOpen(open){
        openEditor(!!open);
      }


      function highlightRow(idStr){
        $table.find('tbody tr').removeClass('is-selected');
        if (idStr) {
          $table.find('tbody tr.poi-row[data-id="' + idStr + '"]').addClass('is-selected');
        }
      }

      function applyFilter(term){
        term = (term || '').toLowerCase();
        $table.find('tbody tr.poi-row').each(function(){
          var $tr = $(this);
          var txt = $tr.text().toLowerCase();
          $tr.toggle(txt.indexOf(term) !== -1);
        });
      }

      $filter.off('input').on('input', function(){
        applyFilter(this.value);
      });

      function setForm(p){
        if (!p) {
          selectedId = null;
          $id.val('');
          $name.val('');
          $comment.val('');
          $genus.val('der');
          $btnDelete.prop('disabled', true);
          updateTypeDescription();
          return;
        }
        selectedId = String(p.id);
        $id.val(p.id);
        if (p.poi_type) $type.val(p.poi_type);
        updateTypeDescription();
        $genus.val(genusNormalize(p.genus || 'der'));
        $name.val(p.name || '');
        $comment.val(p.comment || '');
        $lat.val(p.latitude);
        $lon.val(p.longitude);
        $btnDelete.prop('disabled', false);
      }

      $type.off('change').on('change', function(){
        updateTypeDescription();
        renderFeatures();
      });

      // Map
      var mapTarget = $modal.find('#leitstellen-pois-map')[0];
      var poiSource = new ol.source.Vector();
      var poiLayer = new ol.layer.Vector({ source: poiSource });

      // Einsatzgebiet (nur Umriss, blau)
      var einsatzgebietSource = new ol.source.Vector();
      var einsatzgebietLayer = new ol.layer.Vector({
        source: einsatzgebietSource,
        style: new ol.style.Style({
          stroke: new ol.style.Stroke({ color: '#007cba', width: 3 })
        })
      });

      var base = new ol.layer.Tile({ source: new ol.source.OSM() });
      // Initial view
      var viewCenter;
      var viewZoom = 11;
      var viewRotation = 0;
      if (passedViewState && Array.isArray(passedViewState.center) && passedViewState.center.length === 2) {
        viewCenter = passedViewState.center;
        if (typeof passedViewState.zoom === 'number') viewZoom = passedViewState.zoom;
        if (typeof passedViewState.rotation === 'number') viewRotation = passedViewState.rotation;
      } else if (data.leitstelle_lon !== null && data.leitstelle_lat !== null) {
        viewCenter = toLonLat3857(data.leitstelle_lon, data.leitstelle_lat);
      } else if ((data.pois || []).length) {
        viewCenter = toLonLat3857(data.pois[0].longitude, data.pois[0].latitude);
      } else {
        viewCenter = toLonLat3857(9.0, 51.0);
      }

      var map = new ol.Map({
        target: mapTarget,
        layers: [base, einsatzgebietLayer, poiLayer],
        view: new ol.View({ center: viewCenter, zoom: viewZoom, rotation: viewRotation })
      });

      function getCurrentViewState(){
        var v = map.getView();
        var c = v.getCenter();
        return {
          center: (c && c.slice) ? c.slice() : c,
          zoom: v.getZoom(),
          rotation: v.getRotation()
        };
      }

      // Einsatzgebiet zeichnen (GeoJSON aus leitstellen.geojson)
      (function(){
        var gj = (data && data.einsatzgebiet_geojson) ? String(data.einsatzgebiet_geojson).trim() : '';
        if (!gj) return;
        try {
          var fmt = new ol.format.GeoJSON();
          var feats = fmt.readFeatures(gj, { dataProjection: 'EPSG:4326', featureProjection: 'EPSG:3857' });
          if (feats && feats.length) {
            einsatzgebietSource.clear();
            einsatzgebietSource.addFeatures(feats);

            // Wenn keine POIs vorhanden sind, auf Einsatzgebiet zoomen.
            // Falls wir einen View-State mitgegeben haben (z.B. nach Save/Delete),
            // bleibt der Ausschnitt unverändert.
            if (!passedViewState && !(data.pois || []).length) {
              var ext = einsatzgebietSource.getExtent();
              if (ext && ext[0] !== Infinity) {
                map.getView().fit(ext, { padding: [40, 40, 40, 40], maxZoom: 14, duration: 150 });
              }
            }
          }
        } catch(e) {
          // ignore parse errors, keep POI editor usable
        }
      })();


      function renderFeatures(){
        poiSource.clear();
        (data.pois || []).forEach(function(p){
          var meta = typeMeta[p.poi_type] || {};
          var f = new ol.Feature({
            geometry: new ol.geom.Point(toLonLat3857(p.longitude, p.latitude)),
            poi_id: String(p.id)
          });
          f.setStyle(buildMarkerStyle(meta.color, selectedId && String(p.id) === selectedId));
          poiSource.addFeature(f);
        });
      }

      function selectById(idStr){
        var p = (data.pois || []).find(function(x){ return String(x.id) === String(idStr); });
        if (!p) return;
        setForm(p);
        setEditorOpen(true);
        highlightRow(String(p.id));
        renderFeatures();
        openEditor(true);
        map.getView().animate({ center: toLonLat3857(p.longitude, p.latitude), duration: 150 });
      }

      renderFeatures();
      updateTypeDescription();

      // Klick Tabelle
      $table.on('click', 'tbody tr.poi-row', function(){
        selectById($(this).data('id'));
      });

      // Klick Marker
      var select = new ol.interaction.Select({ layers: [poiLayer], hitTolerance: 6 });
      map.addInteraction(select);
      select.on('select', function(evt){
        var feat = (evt.selected || [])[0];
        if (feat) selectById(feat.get('poi_id'));
        select.getFeatures().clear();
      });

      // Map-Klick: Koordinaten setzen
      map.on('singleclick', function(e){
        var ll = ol.proj.toLonLat(e.coordinate);
        $lon.val(ll[0].toFixed(6));
        $lat.val(ll[1].toFixed(6));
        // wenn Editor offen und "neu", bleibt das sinnvoll
      });

      // Buttons: Liste / Editor
      $btnToggleList.off('click').on('click', function(){
        $listOverlay.toggleClass('is-open');
      });
      $btnCloseList.off('click').on('click', function(){
        openList(false);
      });

      $btnOpenEditor.off('click').on('click', function(){
        openEditor(true);
      });
      $btnEditorClose.off('click').on('click', function(){
        openEditor(false);
      });

      // Neu
      $btnNew.off('click').on('click', function(){
        setEditorOpen(true);
        setForm(null);
        highlightRow(null);
        selectedId = null;
        renderFeatures();

        openEditor(true);

        var ll = ol.proj.toLonLat(map.getView().getCenter());
        $lon.val(ll[0].toFixed(6));
        $lat.val(ll[1].toFixed(6));
      });

      // Speichern
      $form.off('submit').on('submit', function(e){
        e.preventDefault();

        var payload = {
          nonce: window.lstLeitstellenAjax.nonce,
          leitstelle_id: leitstelleId,
          id: $id.val(),
          poi_type: $type.val(),
          name: $name.val(),
          comment: $comment.val(),
          genus: $genus.val(),
          latitude: readNumber($lat.val()),
          longitude: readNumber($lon.val())
        };

        if (!payload.poi_type || payload.latitude === null || payload.longitude === null) {
          alert('Typ und Koordinaten sind Pflicht.');
          return;
        }

        var isUpdate = payload.id && String(payload.id) !== '';
        payload.action = isUpdate ? 'update_leitstelle_poi' : 'create_leitstelle_poi';

        $.ajax({
          url: window.lstLeitstellenAjax.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: payload
        })
        .done(function(r){
          if (!r || !r.success) {
            var msg = r && r.data && r.data.message ? r.data.message : (r && r.data ? r.data : 'Unbekannter Fehler');
            alert('Fehler beim Speichern: ' + msg);
            return;
          }
          openLeitstellePoisEditor(leitstelleId, { viewState: getCurrentViewState() });
        })
        .fail(function(_, status){
          alert('AJAX-Fehler: ' + status);
        });
      });

      // Löschen
      $btnDelete.off('click').on('click', function(){
        var idv = $id.val();
        if (!idv) return;
        if (!window.confirm('POI wirklich löschen?')) return;

        $.ajax({
          url: window.lstLeitstellenAjax.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: { action: 'delete_leitstelle_poi', nonce: window.lstLeitstellenAjax.nonce, leitstelle_id: leitstelleId, id: idv }
        })
        .done(function(r){
          if (!r || !r.success) {
            var msg = r && r.data && r.data.message ? r.data.message : (r && r.data ? r.data : 'Unbekannter Fehler');
            alert('Fehler beim Löschen: ' + msg);
            return;
          }
          openLeitstellePoisEditor(leitstelleId, { viewState: getCurrentViewState() });
        })
        .fail(function(_, status){
          alert('AJAX-Fehler: ' + status);
        });
      });

      // Modal schließen
      function closeModal(){
        $modal.addClass('hidden');
      }
      $btnCancel.off('click').on('click', closeModal);
      $modal.find('.modal-close').off('click').on('click', closeModal);
      $modal.find('.modal-overlay').off('click').on('click', closeModal);

      // Standard: Liste offen, Editor zu
      openList(true);
      openEditor(false);

      // -----------------------------
      // Import: Paste -> Preview -> Import
      // -----------------------------
      var importRows = [];

      function renderImportPreview(rows){
        if (!rows.length) {
          $importPreview.html('<p class="description">Keine gültigen Zeilen erkannt.</p>');
          $btnImportRun.prop('disabled', true);
          return;
        }

        var html = '';
        html += '<table class="widefat fixed striped">';
        html += '<thead><tr>';
        html += '<th style="width:70px;">Typ</th>';
        html += '<th style="width:70px;">Genus</th>';
        html += '<th>Name</th>';
        html += '<th style="width:160px;">Koordinaten</th>';
        html += '<th>Kommentar</th>';
        html += '<th style="width:80px;">Aktion</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function(r, idx){
          html += '<tr data-idx="' + idx + '">';
          html += '<td><input type="text" class="imp-type" value="' + escapeHtml(r.poi_type) + '" style="width:100%;"></td>';
          html += '<td><select class="imp-genus" style="width:100%;">'
               + '<option value="der"' + (r.genus==='der'?' selected':'') + '>der</option>'
               + '<option value="die"' + (r.genus==='die'?' selected':'') + '>die</option>'
               + '<option value="das"' + (r.genus==='das'?' selected':'') + '>das</option>'
               + '</select></td>';
          html += '<td><input type="text" class="imp-name" value="' + escapeHtml(r.name) + '" style="width:100%;"></td>';
          html += '<td>'
               + '<input type="number" step="0.000001" class="imp-lat" value="' + escapeHtml(r.latitude) + '" style="width:49%;"> '
               + '<input type="number" step="0.000001" class="imp-lon" value="' + escapeHtml(r.longitude) + '" style="width:49%;">'
               + '</td>';
          html += '<td><input type="text" class="imp-comment" value="' + escapeHtml(r.comment||'') + '" style="width:100%;"></td>';
          html += '<td><button type="button" class="button imp-del">Entfernen</button></td>';
          html += '</tr>';
        });

        html += '</tbody></table>';
        $importPreview.html(html);
        $btnImportRun.prop('disabled', false);
      }

      function parseImportText(raw){
        var lines = String(raw || '').split(/\r?\n/).map(function(l){ return l.trim(); }).filter(Boolean);
        var rows = [];

        lines.forEach(function(line){
          // Tabs oder viele Spaces
          var cols = line.split(/\t+/);
          if (cols.length < 3) cols = line.split(/\s{2,}/);

          // Erwartung: [ID?] [Koordinaten] [Genus] [Name] [Tags?] [Kommentar?]
          // ID optional: wenn erste Spalte nur Zahl und zweite wie coords aussieht, dann skip ID
          var c0 = cols[0] || '';
          var c1 = cols[1] || '';
          var hasId = /^\d+$/.test(c0) && parseCoord(c1);
          var idx = hasId ? 1 : 0;

          var coord = parseCoord(cols[idx] || '');
          if (!coord) return;

          var genus = genusNormalize(cols[idx+1] || '');
          var name = String(cols[idx+2] || '').trim();
          if (!name) return;

          var tags = String(cols[idx+3] || '').trim();      // optional
          var comment = String(cols[idx+4] || '').trim();   // optional

          // Heuristik: poi_type aus Tags-Spalte (wenn vorhanden), sonst leer -> Nutzer muss füllen
          // Du kannst hier auch Default setzen, z.B. "Sonstiges"
          var poi_type = tags ? tags.split(',')[0].trim() : '';

          rows.push({
            poi_type: poi_type,
            genus: genus,
            name: name,
            latitude: coord.lat,
            longitude: coord.lon,
            comment: comment
          });
        });

        return rows;
      }

      $btnImport.off('click').on('click', function(){
        $importPanel.removeClass('hidden');
        $importPreview.empty();
        $btnImportRun.prop('disabled', true);
      });

      $btnImportClose.off('click').on('click', function(){
        $importPanel.addClass('hidden');
      });

      $btnImportParse.off('click').on('click', function(){
        importRows = parseImportText($importText.val());
        renderImportPreview(importRows);
      });

      // Preview: Entfernen + Live-Edit in importRows spiegeln
      $importPreview.off('click').on('click', '.imp-del', function(){
        var $tr = $(this).closest('tr');
        var idx = Number($tr.data('idx'));
        if (!Number.isFinite(idx)) return;
        importRows.splice(idx, 1);
        renderImportPreview(importRows);
      });

      function syncImportRowsFromDom(){
        var rows = [];
        $importPreview.find('tbody tr').each(function(){
          var $tr = $(this);
          rows.push({
            poi_type: String($tr.find('.imp-type').val() || '').trim(),
            genus: String($tr.find('.imp-genus').val() || 'der'),
            name: String($tr.find('.imp-name').val() || '').trim(),
            latitude: readNumber($tr.find('.imp-lat').val()),
            longitude: readNumber($tr.find('.imp-lon').val()),
            comment: String($tr.find('.imp-comment').val() || '').trim()
          });
        });
        importRows = rows;
      }

      $btnImportRun.off('click').on('click', function(){
        syncImportRowsFromDom();

        // Minimalvalidierung
        var invalid = importRows.filter(function(r){
          return !r.name || !r.poi_type || r.latitude===null || r.longitude===null;
        });
        if (invalid.length) {
          alert('Im Preview fehlen bei mindestens einer Zeile Typ/Name/Koordinaten.');
          return;
        }

        // Sequenziell anlegen über create_leitstelle_poi (IDs werden ignoriert)
        var i = 0;
        $btnImportRun.prop('disabled', true).text('Import läuft…');

        function next(){
          if (i >= importRows.length) {
            openLeitstellePoisEditor(leitstelleId, { viewState: getCurrentViewState() });
            return;
          }

          var r = importRows[i++];
          $.ajax({
            url: window.lstLeitstellenAjax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
              action: 'create_leitstelle_poi',
              nonce: window.lstLeitstellenAjax.nonce,
              leitstelle_id: leitstelleId,
              poi_type: r.poi_type,
              genus: r.genus,
              name: r.name,
              comment: r.comment,
              latitude: r.latitude,
              longitude: r.longitude
            }
          })
          .done(function(res){
            if (!res || !res.success) {
              var msg = res && res.data && res.data.message ? res.data.message : (res && res.data ? res.data : 'Unbekannter Fehler');
              alert('Import abgebrochen bei Zeile ' + i + ': ' + msg);
              $btnImportRun.prop('disabled', false).text('Importieren');
              return;
            }
            next();
          })
          .fail(function(_, status){
            alert('AJAX-Fehler beim Import: ' + status);
            $btnImportRun.prop('disabled', false).text('Importieren');
          });
        }

        next();
      });

      // Show modal
      $modal.removeClass('hidden');
      // Standard: Editor offen, Liste zu
      try { setEditorOpen(true); setListOpen(false); hideImportPanel(); } catch(e) {}
    })
    .fail(function(_, status){
      alert('AJAX-Fehler: ' + status);
    });
  }

  // Button in Leitstellen-Edit-Form
  $(document).on('click', '.open-leitstelle-pois-editor', function(e){
    e.preventDefault();
    var id = $('#lst_update_id').val();
    if (!id || String(id) === '0') {
      alert('Bitte zuerst speichern.');
      return;
    }
    openLeitstellePoisEditor(id);
  });

})(jQuery, window, document, ol);
