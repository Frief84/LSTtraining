(function($, window, document, ol){
  'use strict';

  var lstPoiLastView = null;

  function to3857(lon, lat){
    return ol.proj.fromLonLat([Number(lon), Number(lat)]);
  }

  function from3857(coord){
    var ll = ol.proj.toLonLat(coord);
    return {
      lon: ll[0],
      lat: ll[1]
    };
  }

  function readNumber(v){
    if (v === null || v === undefined || v === '') return null;
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function escapeHtml(s){
    return $('<div>').text(String(s || '')).html();
  }

  function genusNormalize(v){
    var s = String(v || 'der').trim().toLowerCase();
    if (s === 'die' || s === 'f' || s === 'w') return 'die';
    if (s === 'das' || s === 'n') return 'das';
    return 'der';
  }

  function parseCoord(str){
    if (!str) return null;

    var parts = String(str).trim().split(/[,\s]+/).filter(Boolean);
    if (parts.length < 2) return null;

    var lat = Number(parts[0]);
    var lon = Number(parts[1]);

    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;

    return { lat: lat, lon: lon };
  }

  function normalizePoiTypes(poiTypes){
    var out = [];

    (poiTypes || []).forEach(function(t){
      if (!t || !t.tag) return;

      out.push({
        tag: String(t.tag),
        color: String(t.color || '#888888'),
        description: String(t.description || '')
      });
    });

    return out;
  }

  function markerStyle(color, selected){
    return new ol.style.Style({
      image: new ol.style.Circle({
        radius: selected ? 8 : 6,
        fill: new ol.style.Fill({
          color: color || '#888888'
        }),
        stroke: new ol.style.Stroke({
          color: selected ? '#111111' : '#ffffff',
          width: selected ? 3 : 2
        })
      })
    });
  }

  function popupPixelPosition(map, coordinate, $popup){
    var px = map.getPixelFromCoordinate(coordinate);
    if (!px) return null;

    var mapEl = map.getTargetElement();
    var mapRect = mapEl.getBoundingClientRect();
    var popupWidth = $popup.outerWidth() || 380;
    var popupHeight = $popup.outerHeight() || 260;

    var left = px[0] + 16;
    var top = px[1] - 10;

    if (left + popupWidth > mapRect.width - 12) {
      left = px[0] - popupWidth - 16;
    }
    if (left < 12) left = 12;

    if (top + popupHeight > mapRect.height - 12) {
      top = mapRect.height - popupHeight - 12;
    }
    if (top < 12) top = 12;

    return { left: left, top: top };
  }

  function openLeitstellePoisEditor(leitstelleId){
    $.getJSON(window.lstLeitstellenAjax.ajax_url, {
      action: 'get_leitstelle_pois',
      leitstelle_id: leitstelleId,
      nonce: window.lstLeitstellenAjax.nonce
    })
    .done(function(resp){
      if (!resp || !resp.success) {
        alert('Fehler beim Laden der POIs.');
        return;
      }

      var data = resp.data || {};
      var poiTypes = normalizePoiTypes(data.poi_types || []);
      var poiTypeMap = {};

      poiTypes.forEach(function(t){
        poiTypeMap[t.tag] = t;
      });

      var tpl = wp.template('leitstellen-pois-editor');
      var $modal = $('#leitstellen-pois-modal');

      $modal.find('.modal-body').html(tpl({
        poi_types: poiTypes,
        pois: data.pois || []
      }));

      $modal.removeClass('hidden');

      var $mapEl = $modal.find('#leitstellen-pois-map');
      var $legend = $modal.find('#lst-poi-legend-overlay');
      var $editPopup = $modal.find('#lst-poi-edit-popup');
      var $createPopup = $modal.find('#lst-poi-create-popup');
      var $importModal = $modal.find('#lst-poi-import-modal');

      var $editId = $modal.find('#lst-poi-edit-id');
      var $editType = $modal.find('#lst-poi-edit-type');
      var $editTypeDesc = $modal.find('#lst-poi-edit-type-desc');
      var $editName = $modal.find('#lst-poi-edit-name');
      var $editComment = $modal.find('#lst-poi-edit-comment');
      var $editGenus = $modal.find('#lst-poi-edit-genus');
      var $editLat = $modal.find('#lst-poi-edit-lat');
      var $editLon = $modal.find('#lst-poi-edit-lon');
      var $editForm = $modal.find('#lst-poi-edit-form');

      var $createType = $modal.find('#lst-poi-create-type');
      var $createTypeDesc = $modal.find('#lst-poi-create-type-desc');
      var $createName = $modal.find('#lst-poi-create-name');
      var $createComment = $modal.find('#lst-poi-create-comment');
      var $createGenus = $modal.find('#lst-poi-create-genus');
      var $createLat = $modal.find('#lst-poi-create-lat');
      var $createLon = $modal.find('#lst-poi-create-lon');
      var $createForm = $modal.find('#lst-poi-create-form');

      var $importText = $modal.find('#lst-poi-import-text');
      var $importPreview = $modal.find('#lst-poi-import-preview');
      var $importRun = $modal.find('#lst-poi-import-run');

      var importRows = [];
      var selectedPoiId = null;
      var editPreviewFeature = null;

      function storeCurrentView(){
        if (!map) return;
        lstPoiLastView = {
          center: map.getView().getCenter(),
          zoom: map.getView().getZoom()
        };
      }

      function renderLegend(){
        var html = '<div class="lst-poi-legend-list">';

        poiTypes.forEach(function(t){
          html += '<div class="lst-poi-legend-item">';
          html +=   '<span class="lst-poi-legend-swatch" style="background:' + escapeHtml(t.color) + ';"></span>';
          html +=   '<div>';
          html +=     '<div class="lst-poi-legend-tag">' + escapeHtml(t.tag) + '</div>';
          html +=     '<div class="lst-poi-legend-desc">' + escapeHtml(t.description) + '</div>';
          html +=   '</div>';
          html += '</div>';
        });

        html += '</div>';
        $legend.html(html);
      }

      function hideAllPopups(){
        $editPopup.addClass('hidden');
        $createPopup.addClass('hidden');
      }

      function updateTypeDesc($select, $target){
        var type = String($select.val() || '');
        var meta = poiTypeMap[type];

        if (!meta) {
          $target.text('');
          return;
        }

        $target.html(
          '<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:' +
          escapeHtml(meta.color) +
          ';margin-right:6px;"></span><strong>' +
          escapeHtml(meta.tag) +
          '</strong> ' +
          escapeHtml(meta.description || '')
        );
      }

      function findPoiById(id){
        return (data.pois || []).find(function(p){
          return String(p.id) === String(id);
        }) || null;
      }

      function getPoiColor(type){
        var meta = poiTypeMap[String(type || '')];
        return meta ? meta.color : '#888888';
      }

      var poiSource = new ol.source.Vector();
      var poiLayer = new ol.layer.Vector({
        source: poiSource
      });

      var einsatzgebietSource = new ol.source.Vector();
      var einsatzgebietLayer = new ol.layer.Vector({
        source: einsatzgebietSource,
        style: new ol.style.Style({
          stroke: new ol.style.Stroke({
            color: '#007cba',
            width: 3
          })
        })
      });

      var baseLayer = new ol.layer.Tile({
        source: new ol.source.OSM()
      });

      var initialCenter = to3857(
        data.leitstelle_lon || 9.0,
        data.leitstelle_lat || 51.0
      );

      var view = new ol.View({
        center: initialCenter,
        zoom: 11
      });

      if (lstPoiLastView && lstPoiLastView.center) {
        view.setCenter(lstPoiLastView.center);
        view.setZoom(lstPoiLastView.zoom || 11);
      }

      var map = new ol.Map({
        target: $mapEl[0],
        layers: [baseLayer, einsatzgebietLayer, poiLayer],
        view: view
      });

      if (data.einsatzgebiet_geojson) {
        try {
          var fmt = new ol.format.GeoJSON();
          var features = fmt.readFeatures(data.einsatzgebiet_geojson, {
            dataProjection: 'EPSG:4326',
            featureProjection: 'EPSG:3857'
          });

          if (features.length) {
            einsatzgebietSource.addFeatures(features);

            if (!lstPoiLastView) {
              map.getView().fit(einsatzgebietSource.getExtent(), {
                padding: [40, 40, 40, 40],
                maxZoom: 13,
                duration: 150
              });
            }
          }
        } catch (e) {
        }
      }

      function renderPois(){
        poiSource.clear();

        (data.pois || []).forEach(function(p){
          var feat = new ol.Feature({
            geometry: new ol.geom.Point(to3857(p.longitude, p.latitude)),
            poi_id: String(p.id),
            is_edit_preview: false
          });

          feat.setStyle(
            markerStyle(getPoiColor(p.poi_type), String(p.id) === String(selectedPoiId))
          );

          poiSource.addFeature(feat);
        });

        if (editPreviewFeature) {
          poiSource.addFeature(editPreviewFeature);
        }
      }

      function refreshPoiStyles(){
        poiSource.getFeatures().forEach(function(feat){
          if (feat.get('is_edit_preview')) return;

          var poiId = feat.get('poi_id');
          var poi = findPoiById(poiId);
          if (!poi) return;

          feat.setStyle(
            markerStyle(getPoiColor(poi.poi_type), String(poi.id) === String(selectedPoiId))
          );
        });

        if (editPreviewFeature) {
          editPreviewFeature.setStyle(
            markerStyle(getPoiColor($editType.val()), true)
          );
        }
      }

      function forceMapResize(){
        requestAnimationFrame(function(){
          requestAnimationFrame(function(){
            map.updateSize();
            map.renderSync();
          });
        });
      }

      function removeEditPreviewFeature(){
        if (editPreviewFeature && poiSource.hasFeature(editPreviewFeature)) {
          poiSource.removeFeature(editPreviewFeature);
        }
        editPreviewFeature = null;
      }

      function ensureEditPreviewFeature(){
        var id = String($editId.val() || '');
        var lat = readNumber($editLat.val());
        var lon = readNumber($editLon.val());

        if (!id || lat === null || lon === null) return null;

        var coord = to3857(lon, lat);

        if (!editPreviewFeature) {
          editPreviewFeature = new ol.Feature({
            geometry: new ol.geom.Point(coord),
            poi_id: id,
            is_edit_preview: true
          });
          editPreviewFeature.setStyle(markerStyle(getPoiColor($editType.val()), true));
          poiSource.addFeature(editPreviewFeature);
        } else {
          editPreviewFeature.set('poi_id', id);
          editPreviewFeature.getGeometry().setCoordinates(coord);
          editPreviewFeature.setStyle(markerStyle(getPoiColor($editType.val()), true));

          if (!poiSource.hasFeature(editPreviewFeature)) {
            poiSource.addFeature(editPreviewFeature);
          }
        }

        return editPreviewFeature;
      }

      function updateEditPreviewMarker(centerMap){
        var id = String($editId.val() || '');
        var lat = readNumber($editLat.val());
        var lon = readNumber($editLon.val());

        if (!id || lat === null || lon === null) {
          removeEditPreviewFeature();
          renderPois();
          return;
        }

        selectedPoiId = id;

        var coord = to3857(lon, lat);

        ensureEditPreviewFeature();

        poiSource.getFeatures().forEach(function(feat){
          if (feat.get('is_edit_preview')) return;

          if (String(feat.get('poi_id')) === id) {
            feat.getGeometry().setCoordinates(coord);
            feat.setStyle(markerStyle(getPoiColor($editType.val()), true));
          }
        });

        refreshPoiStyles();

        if (centerMap !== false) {
          map.getView().animate({
            center: coord,
            duration: 150
          });
        }
      }

      function positionPopupAtCoordinate($popup, coordinate){
        var pos = popupPixelPosition(map, coordinate, $popup);
        if (!pos) return;

        $popup.css({
          left: pos.left + 'px',
          top: pos.top + 'px'
        });
      }

      function syncEditLatLonFromCoordinate(coordinate, centerMap){
        var ll = from3857(coordinate);

        $editLat.val(ll.lat.toFixed(6));
        $editLon.val(ll.lon.toFixed(6));

        updateEditPreviewMarker(centerMap);
        positionPopupAtCoordinate($editPopup, coordinate);
      }

      function showEditPopup(poi, coordinate){
        hideAllPopups();

        selectedPoiId = String(poi.id || '');

        $editId.val(poi.id || '');
        $editType.val(poi.poi_type || '');
        $editName.val(poi.name || '');
        $editComment.val(poi.comment || '');
        $editGenus.val(genusNormalize(poi.genus || 'der'));
        $editLat.val(poi.latitude);
        $editLon.val(poi.longitude);

        updateTypeDesc($editType, $editTypeDesc);

        $editPopup.removeClass('hidden');
        positionPopupAtCoordinate($editPopup, coordinate);

        updateEditPreviewMarker(false);

        translate.setActive(true);
      }

      function showCreatePopup(coordinate){
        hideAllPopups();

        selectedPoiId = null;
        removeEditPreviewFeature();
        refreshPoiStyles();
        translate.setActive(false);

        var lonLat = ol.proj.toLonLat(coordinate);

        $createType.val('');
        $createName.val('');
        $createComment.val('');
        $createGenus.val('der');
        $createLat.val(lonLat[1].toFixed(6));
        $createLon.val(lonLat[0].toFixed(6));
        $createTypeDesc.text('');

        $createPopup.removeClass('hidden');
        positionPopupAtCoordinate($createPopup, coordinate);
      }

      function parseImportRows(raw){
        var lines = String(raw || '')
          .split(/\r?\n/)
          .map(function(l){ return l.trim(); })
          .filter(Boolean);

        var rows = [];

        lines.forEach(function(line){
          var cols = line.split(/\t+/);
          if (cols.length < 3) cols = line.split(/\s{2,}/);

          var c0 = cols[0] || '';
          var c1 = cols[1] || '';
          var hasId = /^\d+$/.test(c0) && parseCoord(c1);
          var idx = hasId ? 1 : 0;

          var coord = parseCoord(cols[idx] || '');
          if (!coord) return;

          var genus = genusNormalize(cols[idx + 1] || '');
          var name = String(cols[idx + 2] || '').trim();
          if (!name) return;

          var tags = String(cols[idx + 3] || '').trim();
          var comment = String(cols[idx + 4] || '').trim();
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

      function renderImportPreview(rows){
        if (!rows.length) {
          $importPreview.html('<p class="description">Keine gültigen Zeilen erkannt.</p>');
          $importRun.prop('disabled', true);
          return;
        }

        var html = '<table class="widefat fixed striped">';
        html += '<thead><tr>';
        html += '<th>Typ</th><th>Genus</th><th>Name</th><th>Koordinaten</th><th>Kommentar</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function(r){
          html += '<tr>';
          html += '<td>' + escapeHtml(r.poi_type) + '</td>';
          html += '<td>' + escapeHtml(r.genus) + '</td>';
          html += '<td>' + escapeHtml(r.name) + '</td>';
          html += '<td>' + escapeHtml(String(r.latitude) + ', ' + String(r.longitude)) + '</td>';
          html += '<td>' + escapeHtml(r.comment || '') + '</td>';
          html += '</tr>';
        });

        html += '</tbody></table>';

        $importPreview.html(html);
        $importRun.prop('disabled', false);
      }

      var translate = new ol.interaction.Translate({
        layers: [poiLayer],
        hitTolerance: 10
      });

      translate.setActive(false);
      map.addInteraction(translate);

      translate.on('translatestart', function(evt){
        var feat = evt.features.item(0);
        if (!feat) return;

        if (!feat.get('is_edit_preview')) {
          evt.features.clear();
        }
      });

      translate.on('translateend', function(evt){
        var feat = evt.features.item(0);
        if (!feat) return;
        if (!feat.get('is_edit_preview')) return;

        var coord = feat.getGeometry().getCoordinates();
        syncEditLatLonFromCoordinate(coord, false);
      });

      renderLegend();
      renderPois();
      forceMapResize();

      if (window.ResizeObserver) {
        var ro = new ResizeObserver(function(){
          forceMapResize();
        });
        ro.observe($mapEl[0]);
      }

      map.on('singleclick', function(evt){
        var hit = false;

        map.forEachFeatureAtPixel(evt.pixel, function(feature){
          if (feature.get('is_edit_preview')) {
            var previewPoi = findPoiById(feature.get('poi_id'));
            if (previewPoi) {
              hit = true;
              showEditPopup(previewPoi, evt.coordinate);
              return true;
            }
            return false;
          }

          hit = true;
          var poi = findPoiById(feature.get('poi_id'));
          if (poi) {
            showEditPopup(poi, evt.coordinate);
          }
          return true;
        });

        if (!hit) {
          showCreatePopup(evt.coordinate);
        }
      });

      $modal.find('#lst-poi-close, .modal-close, .modal-overlay').off('click').on('click', function(){
        removeEditPreviewFeature();
        selectedPoiId = null;
        translate.setActive(false);
        $modal.addClass('hidden');
      });

      $modal.find('#lst-poi-toggle-legend').off('click').on('click', function(){
        $legend.toggleClass('hidden');
      });

      $modal.find('#lst-poi-edit-popup-close').off('click').on('click', function(){
        removeEditPreviewFeature();
        selectedPoiId = null;
        refreshPoiStyles();
        translate.setActive(false);
        $editPopup.addClass('hidden');
      });

      $modal.find('#lst-poi-create-popup-close').off('click').on('click', function(){
        $createPopup.addClass('hidden');
      });

      $editType.off('change.poi').on('change.poi', function(){
        updateTypeDesc($editType, $editTypeDesc);
        updateEditPreviewMarker(false);
      });

      $createType.off('change.poi').on('change.poi', function(){
        updateTypeDesc($createType, $createTypeDesc);
      });

      $editLat.off('input.poi change.poi').on('input.poi change.poi', function(){
        updateEditPreviewMarker(true);
      });

      $editLon.off('input.poi change.poi').on('input.poi change.poi', function(){
        updateEditPreviewMarker(true);
      });

      $editForm.off('submit').on('submit', function(e){
        e.preventDefault();

        storeCurrentView();

        $.ajax({
          url: window.lstLeitstellenAjax.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
            action: 'update_leitstelle_poi',
            nonce: window.lstLeitstellenAjax.nonce,
            leitstelle_id: leitstelleId,
            id: $editId.val(),
            poi_type: $editType.val(),
            name: $editName.val(),
            comment: $editComment.val(),
            genus: genusNormalize($editGenus.val()),
            latitude: readNumber($editLat.val()),
            longitude: readNumber($editLon.val())
          }
        })
        .done(function(r){
          if (!r || !r.success) {
            alert('Speichern fehlgeschlagen.');
            return;

          }

          openLeitstellePoisEditor(leitstelleId);
        })
        .fail(function(){
          alert('AJAX-Fehler beim Speichern.');
        });
      });

      $modal.find('#lst-poi-delete-btn').off('click').on('click', function(){
        var id = $editId.val();
        if (!id) return;

        if (!window.confirm('Diesen Marker wirklich löschen?')) {
          return;
        }

        storeCurrentView();

        $.ajax({
          url: window.lstLeitstellenAjax.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
            action: 'delete_leitstelle_poi',
            nonce: window.lstLeitstellenAjax.nonce,
            leitstelle_id: leitstelleId,
            id: id
          }
        })
        .done(function(r){
          if (!r || !r.success) {
            alert('Löschen fehlgeschlagen.');
            return;
          }

          openLeitstellePoisEditor(leitstelleId);
        })
        .fail(function(){
          alert('AJAX-Fehler beim Löschen.');
        });
      });

      $createForm.off('submit').on('submit', function(e){
        e.preventDefault();

        storeCurrentView();

        $.ajax({
          url: window.lstLeitstellenAjax.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
            action: 'create_leitstelle_poi',
            nonce: window.lstLeitstellenAjax.nonce,
            leitstelle_id: leitstelleId,
            poi_type: $createType.val(),
            name: $createName.val(),
            comment: $createComment.val(),
            genus: genusNormalize($createGenus.val()),
            latitude: readNumber($createLat.val()),
            longitude: readNumber($createLon.val())
          }
        })
        .done(function(r){
          if (!r || !r.success) {
            alert('Anlegen fehlgeschlagen.');
            return;
          }

          openLeitstellePoisEditor(leitstelleId);
        })
        .fail(function(){
          alert('AJAX-Fehler beim Anlegen.');
        });
      });

      $modal.find('#lst-poi-import-open').off('click').on('click', function(){
        $importModal.removeClass('hidden');
      });

      $modal.find('#lst-poi-import-close').off('click').on('click', function(){
        $importModal.addClass('hidden');
      });

      $modal.find('#lst-poi-import-parse').off('click').on('click', function(){
        importRows = parseImportRows($importText.val());
        renderImportPreview(importRows);
      });

      $importRun.off('click').on('click', function(){
        if (!importRows.length) return;

        storeCurrentView();

        var i = 0;
        $importRun.prop('disabled', true).text('Import läuft...');

        function next(){
          if (i >= importRows.length) {
            openLeitstellePoisEditor(leitstelleId);
            return;
          }

          var row = importRows[i++];

          $.ajax({
            url: window.lstLeitstellenAjax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
              action: 'create_leitstelle_poi',
              nonce: window.lstLeitstellenAjax.nonce,
              leitstelle_id: leitstelleId,
              poi_type: row.poi_type,
              name: row.name,
              comment: row.comment,
              genus: row.genus,
              latitude: row.latitude,
              longitude: row.longitude
            }
          })
          .done(function(r){
            if (!r || !r.success) {
              alert('Import abgebrochen.');
              $importRun.prop('disabled', false).text('Importieren');
              return;
            }
            next();
          })
          .fail(function(){
            alert('AJAX-Fehler beim Import.');
            $importRun.prop('disabled', false).text('Importieren');
          });
        }

        next();
      });
    })
    .fail(function(){
      alert('AJAX-Fehler beim Laden.');
    });
  }


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