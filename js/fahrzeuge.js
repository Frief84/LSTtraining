// js/fahrzeuge.js – Modal-Editor für Fahrzeuge
// Version 1.9.1 (Feldgruppen fix, Select2 im Modal, Wache erst nach Bundesland aktiv)

(function ($) {

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
        '<div style="background:#fff;max-width:860px;margin:60px auto;padding:16px;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.2)">' +
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
      $bl.trigger('change.select2');
    }

    $land.on('change', function(){
      fillBL($land.val() || '');
      $('#fz-wache').val(null).trigger('change').prop('disabled', true).trigger('change.select2');
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
    $('#fz-bundesland').on('change', function () {
      var enabled = !!$('#fz-bundesland').val();
      $w.val(null).trigger('change');
      $w.prop('disabled', !enabled).trigger('change.select2');
    });
  }

  function ensureModal() {
    if (!$('#fahrzeug-modal').length) {
      $('body').append(modalTpl());

      initLandBundesland();
      initFahrzeugtyp();
      initWacheSelect2();

      $('#fz-cancel').on('click', function (e) { e.preventDefault(); $('#fahrzeug-modal').hide(); });
      $('#fz-save').on('click', onSave);
      $('#fz-delete').on('click', onDelete);

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
            } else {
              var msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'Upload fehlgeschlagen.';
              alert(msg);
            }
          })
          .fail(function (xhr) {
            try {
              var j = JSON.parse(xhr.responseText);
              if (j && j.data && j.data.msg) { alert(j.data.msg); return; }
            } catch (e) {}
            var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg) ? xhr.responseJSON.data.msg : ('HTTP ' + xhr.status);
            alert('Upload-Fehler: ' + msg);
          });
      });
    }
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
    $('#fz-land').val('').trigger('change');
    $('#fz-bundesland').val('').trigger('change');
    $('#fz-wache').empty().val(null).trigger('change').prop('disabled', true).trigger('change.select2');
    $('#fz-delete').hide();
    $('#fahrzeug-modal').show();
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
    }).done(function (resp) {
      if (!resp || !resp.success || !resp.data || !resp.data.fahrzeug) {
        alert('Fehler beim Laden.');
        return;
      }
      var fz = resp.data.fahrzeug;

      var map = lstFahrzeugeAjax.bundeslaender || {};
      var wbl = fz.wache_bundesland || '';
      var chosenLand = '';
      if (wbl) {
        Object.keys(map).some(function (land) {
          if (Array.isArray(map[land]) && map[land].indexOf(wbl) !== -1) { chosenLand = land; return true; }
          return false;
        });
      }
      $('#fz-land').val(chosenLand).trigger('change');
      if (chosenLand) { $('#fz-bundesland').val(wbl).trigger('change'); }

      var $w = $('#fz-wache');
      var wacheId = fz.wache_id || '';
      var wacheName = fz.wache_name || (wacheId ? ('#' + wacheId) : '');
      $w.empty();
      if (wacheId) {
        var opt = new Option(wacheName, String(wacheId), true, true);
        $w.append(opt).trigger('change');
        $w.prop('disabled', false).trigger('change.select2');
      } else {
        $w.val(null).trigger('change').prop('disabled', !$('#fz-bundesland').val()).trigger('change.select2');
      }

      var $t = $('#fz-fahrzeugtyp');
      var typ = fz.fahrzeugtyp || '';
      if (typ && !$t.find('option[value="' + typ.replace(/"/g,'&quot;') + '"]').length) {
        $t.append($('<option/>', { value: typ, text: typ }));
      }
      $t.val(typ);

      $('#fz-rufname').val(fz.rufname || '');
      $('#fz-source').val(fz.source_note || '');
      $('#fz-fms').val((fz.fms_status === '6') ? '6' : '2');
      $('#fz-dienst').val(fz.dienstzeiten || '');
      $('#fz-bild').val(fz.bild_datei || '');
      $('#fz-fr').prop('checked', !!(+fz.is_first_responder));

      var url = fz.bild_datei || '';
      if (url) { $('#fz-preview').attr('src', url).show(); } else { $('#fz-preview').hide().attr('src', ''); }

      $('#fahrzeug-modal').show();
    }).fail(function (xhr) {
      alert('Fehler beim Laden: ' + (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg ? xhr.responseJSON.data.msg : xhr.status));
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
      rufname: $('#fz-rufname').val().trim(),
      fahrzeugtyp: $('#fz-fahrzeugtyp').val(),
      source_note: $('#fz-source').val().trim(),
      fms_status: $('#fz-fms').val(),
      dienstzeiten: $('#fz-dienst').val().trim(),
      bild_datei: $('#fz-bild').val().trim(),
      is_first_responder: $('#fz-fr').is(':checked') ? 1 : 0
    };

    if (!data.wache_id || !data.rufname || !data.fahrzeugtyp) {
      alert('Land/Bundesland, Wache, Rufname und Fahrzeugtyp sind Pflichtfelder.');
      return;
    }

    $.ajax({
      url: lstFahrzeugeAjax.ajax_url,
      method: 'POST',
      data: data,
      dataType: 'json'
    }).done(function (resp) {
      if (resp && resp.success) {
        location.reload();
      } else {
        alert('Fehler beim Speichern.');
      }
    }).fail(function (xhr) {
      alert('Fehler beim Speichern: ' + (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg ? xhr.responseJSON.data.msg : xhr.status));
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
    }).done(function (resp) {
      if (resp && resp.success) {
        location.reload();
      } else {
        alert('Löschen fehlgeschlagen.');
      }
    }).fail(function (xhr) {
      alert('Fehler beim Löschen: ' + (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg ? xhr.responseJSON.data.msg : xhr.status));
    });
  }

  $(function () {
    $('#fahrzeug-new').on('click', function (ev) {
      ev.preventDefault();
      openForNew();
    });
    $('#fahrzeuge-table').on('click', '.btn-edit-fahrzeug', function () {
      var id = parseInt($(this).data('id'), 10) || 0;
      if (id > 0) openForEdit(id);
    });
    $('#fahrzeuge-table tbody').on('dblclick', 'tr', function () {
      var id = parseInt($(this).attr('data-id'), 10) || 0;
      if (id > 0) openForEdit(id);
    });
  });
	
// Auto-submit bei Filteränderung (setzt auf Seite 1 zurück)
var $filter = $('#fahrzeuge-filter');
$filter.find('select[name="bundesland"], select[name="leitstelle_id"], select[name="neben_id"]').on('change', function () {
  var $p = $filter.find('input[name="paged"]');
  if (!$p.length) { $p = $('<input type="hidden" name="paged" value="1">').appendTo($filter); }
  else { $p.val('1'); }
  $filter.trigger('submit');
});

})(jQuery);
