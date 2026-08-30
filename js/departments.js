/* js/departments.js */
(function($) {
  // ==== Helper (im IIFE-Scope für alle Funktionen sichtbar) ====

  function getLabel(code) {
    try {
      const m = window.lstHospitalsAjax && window.lstHospitalsAjax.departments;
      return (m && m[code] && m[code].label) ? m[code].label : code;
    } catch(e){ return code; }
  }
  function getColor(code) {
    try {
      const m = window.lstHospitalsAjax && window.lstHospitalsAjax.departments;
      return (m && m[code] && m[code].color) ? m[code].color : '#1976d2';
    } catch(e){ return '#1976d2'; }
  }

  // ==== Hauptformular-Bindings ====

  function bindDepartmentForm({ hospital_lat, hospital_lon, existing }) {
    const $modal        = $('#departments-edit-modal');
    const $container    = $modal.find('.departments-edit-content');
    const $form         = $container.find('#departments-edit-form');
    const $cancelBtn    = $container.find('#departments-edit-cancel');
    const $selector     = $container.find('#departments-selector');
    const $detailsTable = $container.find('#departments-details-table tbody');

    if (!$modal.length || !$form.length || !$selector.length || !$detailsTable.length) return;

    // Abbrechen
    $cancelBtn.off('click').on('click', () => $modal.addClass('hidden'));

    // Speichern
    $form.off('submit').on('submit', e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      fd.set('action', 'lsttraining_save_departments');
      fd.set('nonce', lstHospitalsAjax.nonce);
      fetch(`${lstHospitalsAjax.ajax_url}?action=lsttraining_save_departments`, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      })
      .then(res => res.json())
      .then(json => {
        if (!json.success) throw new Error(json.data || 'Fehler');
        alert('Gespeichert');
        $modal.addClass('hidden');
      })
      .catch(err => {
        console.error('Fehler beim Speichern', err);
        alert('Fehler: ' + err.message);
      });
    });

    // 0) Selector ggf. befüllen: aus globalem depMap (code -> {label,color})
    const depMap = (window.lstHospitalsAjax && window.lstHospitalsAjax.departments) || {};
    if ($selector.find('.dept-toggle').length === 0) {
      const items = Object.keys(depMap).map(code => ({
        code: String(code).toUpperCase(),
        label: depMap[code]?.label || code
      }));
      items.sort((a, b) => a.label.localeCompare(b.label, 'de'));
      const frag = document.createDocumentFragment();
      items.forEach(({code, label}) => {
        const lab = document.createElement('label');
        lab.style.cssText = 'display:flex;align-items:center;';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'dept-toggle';
        cb.name = 'departments[]';
        cb.value = code;
        cb.setAttribute('data-code', code);
        const span = document.createElement('span');
        span.style.marginLeft = '4px';
        span.textContent = label;
        lab.appendChild(cb);
        lab.appendChild(span);
        frag.appendChild(lab);
      });
      $selector.empty()[0].appendChild(frag);
    }

    // Hilfsfunktion: Checkbox für CODE finden
    function findCheckboxForCode(rawCode) {
      const code = String(rawCode).toUpperCase().trim();
      let $cb = $selector.find(`.dept-toggle[value="${code}"]`);
      if ($cb.length) return $cb;
      $cb = $selector.find(`.dept-toggle[data-code="${code}"]`);
      return $cb.length ? $cb : $();
    }

    // 1) Change-Delegation für Checkboxen
    $selector.off('change').on('change', '.dept-toggle', function() {
      let code = this.getAttribute('data-code') || this.value || '';
      code = String(code).toUpperCase().trim();

      if (this.checked) {
        const depInfo = existing.find(d => {
          const dcode = String(
            (d && d.code) ? d.code :
            (d && typeof d === 'object' ? Object.keys(d)[0] : '')
          ).toUpperCase().trim();
          return dcode === code;
        }) || {};
        addDepartmentRow(code, depInfo, hospital_lat, hospital_lon, $detailsTable);
      } else {
        $detailsTable.find(`tr[data-code="${code}"]`).remove();
        // Marker-Removal erfolgt in addDepartmentRow-Handler
      }
    });

    // 2) Vorbelegung: bestehende Departments anhaken und Zeilen erzeugen
    existing.forEach(dep => {
      const raw = (dep && dep.code) ? dep.code
                : (dep && typeof dep === 'object' ? Object.keys(dep)[0] : '');
      if (!raw) return;
      const code = String(raw).toUpperCase().trim();

      const $cb = findCheckboxForCode(code);
      if ($cb.length) {
        $cb.prop('checked', true).trigger('change');
      } else {
        addDepartmentRow(code, dep, hospital_lat, hospital_lon, $detailsTable);
      }
    });
  }

  // ==== Zeilenaufbau + Marker ====

  function addDepartmentRow(code, dep, hospital_lat, hospital_lon, $detailsTable) {
    if ($detailsTable.find(`tr[data-code="${code}"]`).length) return;

    const label = getLabel(code);
    const lat   = Number(dep?.latitude  ?? dep?.[code]?.Lat  ?? hospital_lat);
    const lon   = Number(dep?.longitude ?? dep?.[code]?.Long ?? hospital_lon);

    const latVal = Number.isFinite(lat) ? lat : Number(hospital_lat) || 0;
    const lonVal = Number.isFinite(lon) ? lon : Number(hospital_lon) || 0;

    const $tr = $(
      `<tr data-code="${code}">` +
        `<td>` +
          `<input type="checkbox" checked>` +
          `<span class="dept-label">${label}</span>` +
        `</td>` +
        `<td>` +
          `<input class="lat-input" name="departments[${code}][Lat]"  value="${latVal.toFixed(6)}"  style="width:70px">` +
          `, ` +
          `<input class="lon-input" name="departments[${code}][Long]" value="${lonVal.toFixed(6)}" style="width:70px">` +
        `</td>` +
      `</tr>`
    );
    $detailsTable.append($tr);

    // Marker
    if (window.ol && window.deptSource) {
      const feature = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([lonVal, latVal])),
        code, type: 'department'
      });
      const haloStyle = new ol.style.Style({
        image: new ol.style.Circle({ radius: 14, fill: new ol.style.Fill({ color: 'rgba(0,0,0,0)' }) })
      });
      const markerStyle = new ol.style.Style({
        image: new ol.style.Circle({
          radius: 6,
          fill:   new ol.style.Fill({ color: getColor(code) }),
          stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
        })
      });
      feature.setStyle([ haloStyle, markerStyle ]);
      window.deptSource.addFeature(feature);

      // Inputs ↔ Marker sync
      $tr.find('.lat-input, .lon-input').on('change', () => {
        const nlat = parseFloat($tr.find('.lat-input').val());
        const nlon = parseFloat($tr.find('.lon-input').val());
        if (!isNaN(nlat) && !isNaN(nlon)) {
          feature.getGeometry().setCoordinates(ol.proj.fromLonLat([nlon, nlat]));
        }
      });

      // Deaktivieren = entfernen
      $tr.find('input[type="checkbox"]').on('change', function() {
        if (!this.checked) {
          $tr.remove();
          try { window.deptSource.removeFeature(feature); } catch(e){}
          if (window.deptDragCollection && typeof window.deptDragCollection.clear === 'function') {
            window.deptDragCollection.clear();
          }
        }
      });
    }
  }

  // ==== Drag/Translate-Interaction ====

  function initDeptTranslateInteraction() {
    if (!window.ol || !window.deptMap) return;

    const dragCollection = new ol.Collection();
    window.deptTranslate = new ol.interaction.Translate({
      features:     dragCollection,
      hitTolerance: 10
    });
    window.deptTranslate.setActive(false);
    window.deptMap.getInteractions().insertAt(0, window.deptTranslate);

    const origHandle = window.deptTranslate.handleEvent;
    window.deptTranslate.handleEvent = function(evt) {
      const handled = origHandle.call(this, evt);
      if (handled && evt && typeof evt.stopPropagation === 'function') {
        evt.stopPropagation();
      }
      return handled;
    };

    window.deptTranslate.on('translateend', e => {
      e.features.forEach(f => {
        const [lon, lat] = ol.proj.toLonLat(f.getGeometry().getCoordinates());
        const row = document.querySelector(`tr[data-code="${f.get('code')}"]`);
        if (row) {
          const latInput = row.querySelector('.lat-input');
          const lonInput = row.querySelector('.lon-input');
          if (latInput) latInput.value = lat.toFixed(6);
          if (lonInput) lonInput.value = lon.toFixed(6);
        }
      });
      window.deptTranslate.setActive(false);
    });

    window.deptDragCollection = dragCollection;
  }

  // Zeilen-Klick: Feature auswählen und Translate aktivieren
  $(document).on('click', '#departments-details-table tbody tr', function() {
    const code = $(this).data('code');
    $(this).siblings().removeClass('selected');
    $(this).addClass('selected');
    if (!window.deptSource || !window.deptDragCollection || !window.deptTranslate) return;
    window.deptDragCollection.clear();
    const feat = window.deptSource.getFeatures().find(f => f.get('code') === code);
    if (feat) {
      window.deptDragCollection.push(feat);
      window.deptTranslate.setActive(true);
    }
  });

  // ==== Exports ====
  window.getLabel                     = getLabel;
  window.getColor                     = getColor;
  window.bindDepartmentForm           = bindDepartmentForm;
  window.addDepartmentRow             = addDepartmentRow;
  window.initDeptTranslateInteraction = initDeptTranslateInteraction;

})(jQuery);
