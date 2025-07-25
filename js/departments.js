// js/departments.js
(function($) {
  // Departments-Daten aus wp_localize_script
  // Helfer: Label und Farbe direkt zur Laufzeit aus lstHospitalsAjax holen
function getLabel(code) {
  return window.lstHospitalsAjax?.departments?.[code]?.label || code;
}
function getColor(code) {
  return window.lstHospitalsAjax?.departments?.[code]?.color || '#1976d2';
}
  /**
   * bindDepartmentForm – bindet Checkbox-Logik und Vorbelegung
   */
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
      fetch(`${lstHospitalsAjax.ajax_url}?action=lsttraining_save_departments`, {
        method:      'POST',
        credentials: 'same-origin',
        body:        fd
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

    // Checkbox-Change: Zeile anlegen oder entfernen
    $selector.off('change').on('change', '.dept-toggle', function() {
      const code = this.value;
      if (this.checked) {
        const depInfo = existing.find(d =>
  (d.code && d.code === code) ||            // altes Format
  (Object.keys(d)[0] === code)              // neues Format
) || {};
        addDepartmentRow(code, depInfo, hospital_lat, hospital_lon, $detailsTable);
      } else {
        $detailsTable.find(`tr[data-code="${code}"]`).remove();
      }
    });

    // Vorbelegung bestehender Departments
existing.forEach(dep => {
  const code = dep.code ?? Object.keys(dep)[0];
  if (!code) return;
  $selector
    .find(`.dept-toggle[value="${code}"]`)
    .prop('checked', true)
    .trigger('change');
});
  }

  /**
   * addDepartmentRow – Zeile mit Checkbox+Label und Koordinaten hinzufügen
   */
  function addDepartmentRow(code, dep, hospital_lat, hospital_lon, $detailsTable) {
    if ($detailsTable.find(`tr[data-code="${code}"]`).length) return;

    const label = getLabel(code);
   const lat = dep.latitude  ?? dep[code]?.Lat  ?? hospital_lat;
const lon = dep.longitude ?? dep[code]?.Long ?? hospital_lon;

  // innerhalb addDepartmentRow, statt des bisherigen `$('<tr>…')`-Strings
const $tr = $(
  `<tr data-code="${code}">` +

    /* 1. Spalte: Checkbox + Label */
    `<td>` +
      `<input type="checkbox" checked>` +
      `<span class="dept-label">${label}</span>` +
    `</td>` +

    /* 2. Spalte: Koordinaten */
    `<td>` +
      `<input class="lat-input"
             name="departments[${code}][Lat]"
             value="${lat.toFixed(6)}"
             style="width:70px">` +
      `, ` +
      `<input class="lon-input"
             name="departments[${code}][Long]"
             value="${lon.toFixed(6)}"
             style="width:70px">` +
    `</td>` +

  `</tr>`
);

$detailsTable.append($tr);

    // Marker anlegen
    const feature = new ol.Feature({
      geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
      code, type: 'department'
    });

    // unsichtbarer Halo für besseren Trefferbereich
    const haloStyle = new ol.style.Style({
      image: new ol.style.Circle({
        radius: 14,
        fill:   new ol.style.Fill({ color: 'rgba(0,0,0,0)' })
      })
    });
    // sichtbarer Marker mit Farbe
    const markerStyle = new ol.style.Style({
      image: new ol.style.Circle({
        radius: 6,
        fill:   new ol.style.Fill({ color: getColor(code) }),
        stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
      })
    });
    feature.setStyle([ haloStyle, markerStyle ]);
    window.deptSource.addFeature(feature);

    // Koordinaten-Inputs ↔ Marker synchronisieren
    $tr.find('.lat-input, .lon-input').on('change', () => {
      const nlat = parseFloat($tr.find('.lat-input').val());
      const nlon = parseFloat($tr.find('.lon-input').val());
      if (!isNaN(nlat) && !isNaN(nlon)) {
        feature.getGeometry().setCoordinates(ol.proj.fromLonLat([nlon, nlat]));
      }
    });

    // Zeile entfernen per Checkbox
    $tr.find('input[type="checkbox"]').on('change', function() {
      if (!this.checked) {
        $tr.remove();
        window.deptSource.removeFeature(feature);
        window.deptDragCollection.clear();
      }
    });
  }

  /**
   * initDeptTranslateInteraction – Marker verschiebbar machen
   */
  function initDeptTranslateInteraction() {
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
      if (handled) evt.stopPropagation();
      return handled;
    };

    window.deptTranslate.on('translateend', e => {
      e.features.forEach(f => {
        const [lon, lat] = ol.proj.toLonLat(f.getGeometry().getCoordinates());
        const row = document.querySelector(`tr[data-code="${f.get('code')}"]`);
        if (row) {
          row.querySelector('.lat-input').value = lat.toFixed(6);
          row.querySelector('.lon-input').value = lon.toFixed(6);
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
    window.deptDragCollection.clear();
    const feat = window.deptSource.getFeatures().find(f => f.get('code') === code);
    if (feat) {
      window.deptDragCollection.push(feat);
      window.deptTranslate.setActive(true);
    }
  });

  // Exports
  window.getLabel                     = getLabel;
  window.getColor                     = getColor;
  window.bindDepartmentForm           = bindDepartmentForm;
  window.addDepartmentRow             = addDepartmentRow;
  window.initDeptTranslateInteraction = initDeptTranslateInteraction;

})(jQuery);
