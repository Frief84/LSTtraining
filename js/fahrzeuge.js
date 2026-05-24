// js/fahrzeuge.js – Modal-Editor für Fahrzeuge
// Fix: AJAX get_fahrzeug liefert resp.data direkt (nicht resp.data.fahrzeug)

(function ($) {
  var signalLights = [];
  var dragLightIndex = null;
  var failedSignalSprites = {};

  function pluginBaseUrl() {
    var script = document.querySelector('script[src*="/js/fahrzeuge.js"]');
    if (!script || !script.src) return '';
    return script.src.split('/js/fahrzeuge.js')[0].replace(/\/?$/, '/');
  }

  function signalSpriteMap() {
    var configured = window.lstFahrzeugeAjax && lstFahrzeugeAjax.signal_sprite_urls ? lstFahrzeugeAjax.signal_sprite_urls : {};
    var base = pluginBaseUrl();
    return {
      beacon: configured.beacon || (base ? base + 'img/signal/beacon.png' : ''),
      strobe: configured.strobe || (base ? base + 'img/signal/strobe.png' : ''),
      bar: configured.bar || (base ? base + 'img/signal/lightbar.png' : ''),
      glow: configured.glow || (base ? base + 'img/signal/glow.png' : ''),
      editor_point: configured.editor_point || (base ? base + 'img/signal/editor-point.png' : '')
    };
  }

  function signalSpriteUrl(type) {
    var map = signalSpriteMap();
    var key = ['beacon', 'strobe', 'bar', 'glow'].indexOf(String(type || '')) !== -1 ? String(type) : 'beacon';
    if (map[key] && !failedSignalSprites[key]) return map[key];
    if (map.editor_point && !failedSignalSprites.editor_point) return map.editor_point;
    return '';
  }

  function row(labelFor, labelText, inputHtml, extraStyle) {
    return '' +
      '<div class="form-row" style="display:flex;flex-direction:column;gap:4px;' + (extraStyle||'') + '">' +
        '<label for="' + labelFor + '">' + labelText + '</label>' +
        inputHtml +
      '</div>';
  }

  function modalTpl() {
    return '' +
      '<div id="fahrzeug-modal" style="position:fixed;inset:0;display:none;z-index:10000;background:rgba(0,0,0,0.4);">' +
        '<div style="background:#fff;max-width:1040px;margin:32px auto;padding:16px;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.2);max-height:calc(100vh - 64px);overflow:auto">' +
          '<h2 id="fz-title" style="margin-top:0">Fahrzeug bearbeiten</h2>' +
          '<div class="fz-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start">' +

            row('fz-rufname', 'Rufname',
                '<input type="text" id="fz-rufname" class="regular-text" style="width:100%">') +

            row('fz-fahrzeugtyp', 'Fahrzeugtyp',
                '<select id="fz-fahrzeugtyp" style="width:100%"></select>') +

            row('fz-land', 'Land',
                '<select id="fz-land" style="width:100%"></select>') +

            row('fz-bundesland', 'Bundesland',
                '<select id="fz-bundesland" style="width:100%"></select>') +

            row('fz-wache', 'Wache',
                '<select id="fz-wache" style="width:100%"></select>') +

            row('fz-source', 'Quelle (Hinweis)',
                '<input type="text" id="fz-source" class="regular-text" style="width:100%">') +

            row('fz-fms', 'FMS-Status (Default)',
                '<select id="fz-fms" style="width:100%">' +
                  '<option value="2">2 — frei ab Wache (einsatzbereit)</option>' +
                  '<option value="6">6 — außer Dienst</option>' +
                '</select>') +

            row('fz-dienst', 'Dienstzeiten',
                '<input type="text" id="fz-dienst" class="regular-text" style="width:100%" ' +
                'placeholder="z. B. Mo–Fr 08:00–20:00; Sa 09:00–14:00; So –">') +

            row('fz-bild', 'Bild-Datei',
                '<input type="text" id="fz-bild" class="regular-text" style="width:100%" readonly>' +
                '<input type="file" id="fz-bildupload" name="file" accept="image/*">') +

            '<div class="form-row" style="display:flex;align-items:center;gap:8px">' +
              '<input type="checkbox" id="fz-fr"> <label for="fz-fr" style="margin:0">First Responder</label>' +
            '</div>' +

            '<div id="fz-preview-wrap" style="grid-column:1 / -1;margin-top:6px">' +
              '<img id="fz-preview" style="max-width:400px;max-height:300px;display:none" />' +
            '</div>' +

            '<section class="lst-signal-editor" style="grid-column:1 / -1">' +
              '<div class="lst-signal-editor__head">' +
                '<strong>Blaulichter</strong>' +
                '<div class="lst-signal-editor__presets" aria-label="Blaulicht-Vorlagen">' +
                  '<button type="button" class="button" data-fz-signal-preset="rd">RTW/KTW</button>' +
                  '<button type="button" class="button" data-fz-signal-preset="nef">NEF</button>' +
                  '<button type="button" class="button" data-fz-signal-preset="fw">Feuerwehr</button>' +
                  '<button type="button" class="button" data-fz-signal-preset="pol">Polizei</button>' +
                  '<button type="button" class="button" data-fz-signal-preset="clear">Leer</button>' +
                '</div>' +
              '</div>' +
              '<div class="lst-signal-editor__body">' +
                '<div class="lst-signal-stage" data-fz-signal-stage>' +
                  '<img id="fz-signal-preview" alt="Fahrzeugvorschau">' +
                  '<div class="lst-signal-layer" data-fz-signal-layer></div>' +
                  '<p data-fz-signal-empty>Bitte erst ein Fahrzeugbild wählen.</p>' +
                '</div>' +
                '<div class="lst-signal-panel">' +
                  '<label>Typ<select id="fz-signal-type">' +
                    '<option value="beacon">Rundumleuchte</option>' +
                    '<option value="strobe">Frontblitzer</option>' +
                    '<option value="bar">Lichtbalken</option>' +
                    '<option value="glow">Glow</option>' +
                  '</select></label>' +
                  '<label>Intervall <input type="number" id="fz-signal-interval" value="420" min="120" max="2000" step="20"></label>' +
                  '<label>Phase <input type="number" id="fz-signal-phase" value="0" min="0" max="5000" step="20"></label>' +
                  '<label>Größe <input type="number" id="fz-signal-size" value="1" min="0.4" max="2.5" step="0.1"></label>' +
                  '<button type="button" class="button" id="fz-signal-delete" disabled>Ausgewähltes Licht löschen</button>' +
                  '<small>Klick auf das Bild setzt ein Licht. Ziehen verschiebt es.</small>' +
                '</div>' +
              '</div>' +
              '<input type="hidden" id="fz-signal-lights" value="">' +
            '</section>' +

          '</div>' +

          '<div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end">' +
            '<button class="button" id="fz-cancel">Abbrechen</button>' +
            '<button class="button button-primary" id="fz-save">Speichern</button>' +
            '<span style="flex:1"></span>' +
            '<button class="button button-link-delete" id="fz-delete" style="display:none;border-color:#b32d2e;color:#b32d2e">Löschen</button>' +
          '</div>' +

          '<input type="hidden" id="fz-id" value="0">' +
        '</div>' +
      '</div>';
  }

  function hasSelect2() {
    return !!($.fn && $.fn.select2);
  }

  function initLandBundesland() {
    var data = (window.lstFahrzeugeAjax && lstFahrzeugeAjax.bundeslaender) ? lstFahrzeugeAjax.bundeslaender : {};
    var $land = $('#fz-land').empty();
    var $bl   = $('#fz-bundesland').empty();

    $land.append($('<option/>', { value: '', text: '– Land wählen –' }));
    Object.keys(data).forEach(function (country) {
      $land.append($('<option/>', { value: country, text: country }));
    });

    function fillBL(country) {
      $bl.empty();
      $bl.append($('<option/>', { value: '', text: '– Bundesland wählen –' }));
      var arr = data[country] || [];
      arr.forEach(function (name) {
        $bl.append($('<option/>', { value: name, text: name }));
      });
      if (hasSelect2()) $bl.trigger('change.select2');
    }

    $land.off('change.fzland').on('change.fzland', function(){
      fillBL($land.val() || '');
      $('#fz-wache').val(null).trigger('change').prop('disabled', true);
      if (hasSelect2()) $('#fz-wache').trigger('change.select2');
    });

    fillBL('');
  }

  function initFahrzeugtyp() {
    var $t = $('#fz-fahrzeugtyp').empty();
    var types = (window.lstFahrzeugeAjax && Array.isArray(lstFahrzeugeAjax.fahrzeugtypen))
      ? lstFahrzeugeAjax.fahrzeugtypen : [];
    $t.append($('<option/>', { value: '', text: '– Fahrzeugtyp wählen –' }));
    types.forEach(function (name) {
      if (name && typeof name === 'string') {
        $t.append($('<option/>', { value: name, text: name }));
      }
    });
  }

  function initWacheSelect2() {
    var $w = $('#fz-wache').prop('disabled', true);

    if (!hasSelect2()) {
      // Fallback: ohne Select2 bleibt das Select disabled, weil Suche serverseitig gedacht ist.
      return;
    }

    $w.select2({
      width: '100%',
      placeholder: 'Wache suchen (Name oder ID)…',
      minimumInputLength: 1,
      dropdownParent: $('#fahrzeug-modal'),
      ajax: {
        url: lstFahrzeugeAjax.ajax_url,
        dataType: 'json',
        delay: 250,
        data: function (params) {
          return {
            action: 'lsttraining_search_wachen',
            nonce: lstFahrzeugeAjax.nonce,
            q: params.term || '',
            land: $('#fz-land').val() || '',
            bundesland_name: $('#fz-bundesland').val() || ''
          };
        },
        processResults: function (resp) {
          var results = [];
          if (resp && resp.success && resp.data && Array.isArray(resp.data.items)) {
            results = resp.data.items.map(function (it) {
              return { id: it.id, text: it.text };
            });
          }
          return { results: results };
        },
        cache: true
      },
      language: {
        inputTooShort: function () { return 'Mind. 1 Zeichen eingeben…'; },
        searching: function () { return 'Suche…'; },
        noResults: function () { return 'Keine Treffer'; }
      }
    });

    // Bundesland steuert Aktivierung
    $('#fz-bundesland').off('change.fzbl').on('change.fzbl', function () {
      var enabled = !!$('#fz-bundesland').val();
      $w.val(null).trigger('change');
      $w.prop('disabled', !enabled).trigger('change.select2');
    });
  }

  function clamp(value, min, max) {
    value = Number(value);
    if (!Number.isFinite(value)) return min;
    return Math.max(min, Math.min(max, value));
  }

  function normalizeSignalLights(raw) {
    var decoded = raw;
    if (typeof raw === 'string' && raw.trim()) {
      try { decoded = JSON.parse(raw); } catch (e) { decoded = {}; }
    }
    var lights = Array.isArray(decoded) ? decoded : (decoded && Array.isArray(decoded.lights) ? decoded.lights : []);
    return lights.map(function (light) {
      return {
        x: clamp(light && light.x, 0, 1),
        y: clamp(light && light.y, 0, 1),
        type: ['beacon', 'strobe', 'bar', 'glow'].indexOf(String(light && light.type || '')) !== -1 ? String(light.type) : 'beacon',
        interval: Math.round(clamp(light && light.interval || 420, 120, 2000)),
        phase: Math.round(clamp(light && light.phase || 0, 0, 5000)),
        size: clamp(light && light.size || 1, 0.4, 2.5)
      };
    });
  }

  function signalLightsJson() {
    return signalLights.length ? JSON.stringify({ version: 1, lights: signalLights }) : '';
  }

  function selectedLightIndex() {
    var value = $('#fz-signal-delete').attr('data-index');
    var index = parseInt(value, 10);
    return Number.isFinite(index) && index >= 0 ? index : -1;
  }

  function setSelectedLight(index) {
    index = Number.isFinite(index) ? index : -1;
    $('#fz-signal-delete')
      .prop('disabled', index < 0 || !signalLights[index])
      .attr('data-index', index >= 0 ? String(index) : '');
    if (signalLights[index]) {
      $('#fz-signal-type').val(signalLights[index].type);
      $('#fz-signal-interval').val(signalLights[index].interval);
      $('#fz-signal-phase').val(signalLights[index].phase);
      $('#fz-signal-size').val(signalLights[index].size);
    }
    renderSignalLights();
  }

  function signalStageRect() {
    var stage = $('[data-fz-signal-stage]').get(0);
    var img = $('#fz-signal-preview').get(0);
    if (!stage || !img || !img.complete || !img.naturalWidth) return null;
    var rect = img.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return null;
    return rect;
  }

  function pointFromEvent(event) {
    var rect = signalStageRect();
    if (!rect) return null;
    return {
      x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
      y: clamp((event.clientY - rect.top) / rect.height, 0, 1)
    };
  }

  function renderSignalLights() {
    var $layer = $('[data-fz-signal-layer]');
    var $img = $('#fz-signal-preview');
    var src = ($('#fz-bild').val() || '').trim();
    $('#fz-signal-lights').val(signalLightsJson());
    if (src && $img.attr('src') !== src) {
      $img.attr('src', src);
    }
    $('[data-fz-signal-empty]').toggle(!src);
    $img.toggle(!!src);
    $layer.empty().toggle(!!src);
    if (!src) return;

    var selected = selectedLightIndex();
    signalLights.forEach(function (light, index) {
      var spriteUrl = signalSpriteUrl(light.type);
      var $point = $('<button type="button" class="lst-signal-point"></button>');
      $point
        .attr('data-index', String(index))
        .attr('data-signal-type', light.type || 'beacon')
        .attr('title', light.type + ' ' + Math.round(light.x * 100) + '/' + Math.round(light.y * 100))
        .toggleClass('is-selected', index === selected)
        .toggleClass('is-bar', light.type === 'bar')
        .toggleClass('has-sprite', !!spriteUrl)
        .css({
          left: (light.x * 100) + '%',
          top: (light.y * 100) + '%',
          transform: 'translate(-50%, -50%) scale(' + light.size + ')',
          animationDuration: Math.max(120, light.interval) + 'ms',
          animationDelay: '-' + Math.max(0, light.phase) + 'ms',
          backgroundImage: spriteUrl ? 'url("' + spriteUrl.replace(/"/g, '%22') + '")' : ''
        });
      if (spriteUrl) {
        $('<img alt="">')
          .attr('src', spriteUrl)
          .on('error', function () {
            var key = String(light.type || 'beacon');
            if (spriteUrl === signalSpriteMap().editor_point) key = 'editor_point';
            failedSignalSprites[key] = true;
            renderSignalLights();
          })
          .appendTo($point);
      }
      $layer.append($point);
    });
  }

  function applySignalPreset(name) {
    var presets = {
      rd: [
        { x: 0.38, y: 0.18, type: 'beacon', interval: 420, phase: 0, size: 1 },
        { x: 0.62, y: 0.18, type: 'beacon', interval: 420, phase: 210, size: 1 }
      ],
      nef: [
        { x: 0.38, y: 0.20, type: 'strobe', interval: 360, phase: 0, size: 0.85 },
        { x: 0.62, y: 0.20, type: 'strobe', interval: 360, phase: 180, size: 0.85 }
      ],
      fw: [
        { x: 0.34, y: 0.18, type: 'beacon', interval: 440, phase: 0, size: 1 },
        { x: 0.50, y: 0.16, type: 'beacon', interval: 520, phase: 170, size: 0.9 },
        { x: 0.66, y: 0.18, type: 'beacon', interval: 440, phase: 260, size: 1 }
      ],
      pol: [
        { x: 0.42, y: 0.18, type: 'bar', interval: 360, phase: 0, size: 1 },
        { x: 0.58, y: 0.18, type: 'bar', interval: 360, phase: 180, size: 1 }
      ],
      clear: []
    };
    signalLights = normalizeSignalLights(presets[name] || []);
    setSelectedLight(signalLights.length ? 0 : -1);
  }

  function updateSelectedSignalLightFromControls() {
    var index = selectedLightIndex();
    if (index < 0 || !signalLights[index]) return;
    signalLights[index].type = $('#fz-signal-type').val() || 'beacon';
    signalLights[index].interval = Math.round(clamp($('#fz-signal-interval').val(), 120, 2000));
    signalLights[index].phase = Math.round(clamp($('#fz-signal-phase').val(), 0, 5000));
    signalLights[index].size = clamp($('#fz-signal-size').val(), 0.4, 2.5);
    renderSignalLights();
  }

  function resetSignalEditor(raw) {
    signalLights = normalizeSignalLights(raw || '');
    $('#fz-signal-type').val('beacon');
    $('#fz-signal-interval').val('420');
    $('#fz-signal-phase').val('0');
    $('#fz-signal-size').val('1');
    setSelectedLight(signalLights.length ? 0 : -1);
    renderSignalLights();
  }

  function ensureModal() {
    if ($('#fahrzeug-modal').length) return;

    $('body').append(modalTpl());

    initLandBundesland();
    initFahrzeugtyp();
    initWacheSelect2();

    $('#fz-cancel').on('click', function (e) { e.preventDefault(); $('#fahrzeug-modal').hide(); });
    $('#fz-save').on('click', onSave);
    $('#fz-delete').on('click', onDelete);
    $('[data-fz-signal-stage]').on('click', function (event) {
      if ($(event.target).closest('.lst-signal-point').length) return;
      var point = pointFromEvent(event);
      if (!point || !($('#fz-bild').val() || '').trim()) return;
      signalLights.push({
        x: point.x,
        y: point.y,
        type: $('#fz-signal-type').val() || 'beacon',
        interval: Math.round(clamp($('#fz-signal-interval').val() || 420, 120, 2000)),
        phase: Math.round(clamp($('#fz-signal-phase').val() || 0, 0, 5000)),
        size: clamp($('#fz-signal-size').val() || 1, 0.4, 2.5)
      });
      setSelectedLight(signalLights.length - 1);
    });
    $('[data-fz-signal-layer]').on('pointerdown', '.lst-signal-point', function (event) {
      event.preventDefault();
      event.stopPropagation();
      dragLightIndex = parseInt($(this).attr('data-index'), 10);
      setSelectedLight(dragLightIndex);
      this.setPointerCapture && this.setPointerCapture(event.originalEvent.pointerId);
    });
    $(document).on('pointermove.lstFahrzeugSignals', function (event) {
      if (dragLightIndex === null || !signalLights[dragLightIndex]) return;
      var point = pointFromEvent(event);
      if (!point) return;
      signalLights[dragLightIndex].x = point.x;
      signalLights[dragLightIndex].y = point.y;
      renderSignalLights();
    });
    $(document).on('pointerup.lstFahrzeugSignals pointercancel.lstFahrzeugSignals', function () {
      dragLightIndex = null;
    });
    $('[data-fz-signal-preset]').on('click', function () {
      applySignalPreset($(this).attr('data-fz-signal-preset') || 'clear');
    });
    $('#fz-signal-type, #fz-signal-interval, #fz-signal-phase, #fz-signal-size').on('input change', updateSelectedSignalLightFromControls);
    $('#fz-signal-delete').on('click', function () {
      var index = selectedLightIndex();
      if (index < 0 || !signalLights[index]) return;
      signalLights.splice(index, 1);
      setSelectedLight(signalLights.length ? Math.min(index, signalLights.length - 1) : -1);
    });

    $('#fahrzeug-modal').on('click', function (ev) {
      if (ev.target === this) { $('#fahrzeug-modal').hide(); }
    });

    $('#fz-bildupload').on('change', function () {
      var f = this.files && this.files[0];
      if (!f) return;

      var fd = new FormData();
      fd.append('action', 'lsttraining_upload_fahrzeug_bild');
      fd.append('nonce', lstFahrzeugeAjax.nonce);
      fd.append('file', f, f.name);

      $.ajax({
        url: lstFahrzeugeAjax.ajax_url,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json'
      })
      .done(function (resp) {
        if (resp && resp.success && resp.data && resp.data.url) {
          $('#fz-bild').val(resp.data.url);
          $('#fz-preview').attr('src', resp.data.url).css('display', 'block');
          renderSignalLights();
        } else {
          var msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'Upload fehlgeschlagen.';
          alert(msg);
        }
      })
      .fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg)
          ? xhr.responseJSON.data.msg
          : ('HTTP ' + (xhr.status || ''));
        alert('Upload-Fehler: ' + msg);
      });
    });
  }

  function openForNew() {
    ensureModal();
    $('#fz-title').text('Neues Fahrzeug');
    $('#fz-id').val('0');
    $('#fz-rufname').val('');
    $('#fz-fahrzeugtyp').val('');
    $('#fz-source').val('');
    $('#fz-fms').val('2');
    $('#fz-dienst').val('');
    $('#fz-bild').val('');
    $('#fz-bildupload').val('');
    $('#fz-preview').hide().attr('src', '');
    $('#fz-fr').prop('checked', false);
    resetSignalEditor('');
    $('#fz-land').val('').trigger('change');
    $('#fz-bundesland').val('').trigger('change');

    var $w = $('#fz-wache');
    $w.empty().val(null).trigger('change').prop('disabled', true);
    if (hasSelect2()) $w.trigger('change.select2');

    $('#fz-delete').hide();
    $('#fahrzeug-modal').show();
  }

  function resolveLandFromBundesland(bundeslandName) {
    var map = (window.lstFahrzeugeAjax && lstFahrzeugeAjax.bundeslaender) ? lstFahrzeugeAjax.bundeslaender : {};
    if (!bundeslandName) return '';
    var found = '';
    Object.keys(map).some(function (land) {
      if (Array.isArray(map[land]) && map[land].indexOf(bundeslandName) !== -1) {
        found = land;
        return true;
      }
      return false;
    });
    return found;
  }

  function openForEdit(id) {
    ensureModal();
    $('#fz-title').text('Fahrzeug bearbeiten');
    $('#fz-id').val(String(id));
    $('#fz-delete').show();

    $.ajax({
      url: lstFahrzeugeAjax.ajax_url,
      method: 'GET',
      data: { action: 'lsttraining_get_fahrzeug', nonce: lstFahrzeugeAjax.nonce, id: id },
      dataType: 'json'
    })
    .done(function (resp) {
      // FIX: Endpoint liefert {success:true, data:{...}} (oder alternativ data.fahrzeug)
      var fz = null;
      if (resp && resp.success) {
        if (resp.data && resp.data.fahrzeug) fz = resp.data.fahrzeug;
        else if (resp.data && typeof resp.data === 'object') fz = resp.data;
      }
      if (!fz || !fz.id) {
        alert('Fehler beim Laden.');
        return;
      }

      // Land/Bundesland: wenn du das im Endpoint nicht lieferst, bleiben die Felder leer
      var wbl = fz.wache_bundesland || '';
		var chosenLand = fz.wache_land || resolveLandFromBundesland(wbl);

      $('#fz-land').val(chosenLand).trigger('change');
      if (chosenLand && wbl) $('#fz-bundesland').val(wbl).trigger('change');
      else $('#fz-bundesland').val('').trigger('change');

      // Wache Select: wir setzen direkt die Option, damit Select2 nicht erst suchen muss
      var $w = $('#fz-wache');
      var wacheId = fz.wache_id || '';
      var wacheName = fz.wache_name || (wacheId ? ('#' + wacheId) : '');
      $w.empty();
      if (wacheId) {
        var opt = new Option(wacheName, String(wacheId), true, true);
        $w.append(opt).trigger('change');
        $w.prop('disabled', false);
        if (hasSelect2()) $w.trigger('change.select2');
      } else {
        $w.val(null).trigger('change').prop('disabled', !$('#fz-bundesland').val());
        if (hasSelect2()) $w.trigger('change.select2');
      }

      // Fahrzeugtyp: falls Typ nicht in der Liste ist, hinzufügen
      var $t = $('#fz-fahrzeugtyp');
      var typ = fz.fahrzeugtyp || '';
      if (typ && !$t.find('option[value="' + String(typ).replace(/"/g,'&quot;') + '"]').length) {
        $t.append($('<option/>', { value: typ, text: typ }));
      }
      $t.val(typ);

      $('#fz-rufname').val(fz.rufname || '');
      $('#fz-source').val(fz.source_note || '');
      $('#fz-fms').val((String(fz.fms_status) === '6') ? '6' : '2');
      $('#fz-dienst').val(fz.dienstzeiten || '');
      $('#fz-bild').val(fz.bild_datei || '');
      $('#fz-fr').prop('checked', !!(+fz.is_first_responder));

      var url = fz.bild_datei || '';
      if (url) $('#fz-preview').attr('src', url).show();
      else $('#fz-preview').hide().attr('src', '');
      resetSignalEditor(fz.signal_lights_json || '');

      $('#fahrzeug-modal').show();
    })
    .fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg)
        ? xhr.responseJSON.data.msg
        : ('HTTP ' + (xhr.status || ''));
      alert('Fehler beim Laden: ' + msg);
    });
  }

  function onSave(e) {
    e.preventDefault();

    var id = parseInt($('#fz-id').val(), 10) || 0;

    var data = {
      action: 'lsttraining_save_fahrzeug',
      nonce: lstFahrzeugeAjax.nonce,
      id: id,
      wache_id: parseInt($('#fz-wache').val(), 10) || 0,
      rufname: ($('#fz-rufname').val() || '').trim(),
      fahrzeugtyp: $('#fz-fahrzeugtyp').val() || '',
      source_note: ($('#fz-source').val() || '').trim(),
      fms_status: $('#fz-fms').val() || '2',
      dienstzeiten: ($('#fz-dienst').val() || '').trim(),
      bild_datei: ($('#fz-bild').val() || '').trim(),
      is_first_responder: $('#fz-fr').is(':checked') ? 1 : 0,
      signal_lights_json: signalLightsJson()
    };

    if (!data.wache_id || !data.rufname) {
      alert('Wache und Rufname sind Pflichtfelder.');
      return;
    }

    $.ajax({
      url: lstFahrzeugeAjax.ajax_url,
      method: 'POST',
      data: data,
      dataType: 'json'
    })
    .done(function (resp) {
      if (resp && resp.success) {
        location.reload();
      } else {
        var msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'Fehler beim Speichern.';
        alert(msg);
      }
    })
    .fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg)
        ? xhr.responseJSON.data.msg
        : ('HTTP ' + (xhr.status || ''));
      alert('Fehler beim Speichern: ' + msg);
    });
  }

  function onDelete(e) {
    e.preventDefault();

    var id = parseInt($('#fz-id').val(), 10) || 0;
    if (id <= 0) return;
    if (!confirm('Fahrzeug wirklich löschen?')) return;

    $.ajax({
      url: lstFahrzeugeAjax.ajax_url,
      method: 'POST',
      data: { action: 'lsttraining_delete_fahrzeug', nonce: lstFahrzeugeAjax.nonce, id: id },
      dataType: 'json'
    })
    .done(function (resp) {
      if (resp && resp.success) {
        location.reload();
      } else {
        var msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'Löschen fehlgeschlagen.';
        alert(msg);
      }
    })
    .fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg)
        ? xhr.responseJSON.data.msg
        : ('HTTP ' + (xhr.status || ''));
      alert('Fehler beim Löschen: ' + msg);
    });
  }

  $(function () {
    $('#fahrzeug-new').on('click', function (ev) {
      ev.preventDefault();
      openForNew();
    });

    $('#fahrzeuge-table').on('click', '.btn-edit-fahrzeug', function (ev) {
      ev.preventDefault();
      var id = parseInt($(this).data('id'), 10) || 0;
      if (id > 0) openForEdit(id);
    });

    $('#fahrzeuge-table tbody').on('dblclick', 'tr', function () {
      var id = parseInt($(this).attr('data-id'), 10) || 0;
      if (id > 0) openForEdit(id);
    });

    // Auto-submit bei Filteränderung (setzt auf Seite 1 zurück)
    var $filter = $('#fahrzeuge-filter');
    $filter.find('select[name="bundesland"], select[name="leitstelle_id"], select[name="neben_id"]').on('change', function () {
      var $p = $filter.find('input[name="paged"]');
      if (!$p.length) $p = $('<input type="hidden" name="paged" value="1">').appendTo($filter);
      else $p.val('1');
      $filter.trigger('submit');
    });
  });

})(jQuery);
