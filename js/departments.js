// js/departments.js
(function($) {

(function() {
    // aktuell ausgewähltes Department (für Drag/Translate)
    window.activeDeptCode = null;

    // Departments-Daten aus wp_localize_script
    const departments = window.lstHospitalsAjax?.departments || {};

    // Helfer: Label und Farbe aus departments.json
    function getLabel(code) {
        return departments[code]?.label || code;
    }

    function getColor(code) {
        return departments[code]?.color || '#1976d2';
    }

    /**
     * bindDepartmentForm
     * @param {{hospital_lat:number, hospital_lon:number, existing:Array}} args
     */
   function bindDepartmentForm({ hospital_lat, hospital_lon, existing }) {
  const $modal        = $('#departments-edit-modal');
  const $container    = $modal.find('.departments-edit-content');
  const $form         = $container.find('#departments-edit-form');
  const $cancelBtn    = $container.find('#departments-edit-cancel');
  const $selector     = $container.find('#departments-selector');
  const $detailsTable = $container.find('#departments-details-table tbody');

  if (!$modal.length || !$form.length || !$selector.length || !$detailsTable.length) {
    console.warn('Ein benötigtes Element fehlt');
    return;
  }

  // Abbrechen
  $cancelBtn.off('click').on('click', () => $modal.addClass('hidden'));

  // Speichern
  $form.off('submit').on('submit', e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetch(`${lstHospitalsAjax.ajax_url}?action=save_departments`, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    })
    .then(r => r.json())
    .then(json => {
      if (!json.success) throw new Error(json.data || 'Fehler');
      alert('Gespeichert');
      $modal.addClass('hidden');
    })
    .catch(err => {
      console.error('Save failed', err);
      alert('Fehler beim Speichern: ' + err.message);
    });
  });

  // Beim (De-)aktivieren einer Checkbox: Zeile hinzufügen/entfernen
  $selector.off('change').on('change', '.dept-toggle', function() {
    const code = this.value;
    if (this.checked) {
      const depInfo = existing.find(d => d.code === code) || {};
      addDepartmentRow(code, depInfo, hospital_lat, hospital_lon, $detailsTable);
    } else {
      $detailsTable.find(`tr[data-code="${code}"]`).remove();
    }
  });

  // Vorbefüllen bereits bestehender Departments
  existing.forEach(dep => {
    $selector
      .find(`.dept-toggle[value="${dep.code}"]`)
      .prop('checked', true)
      .trigger('change');
  });
}
    /**
     * addDepartmentRow
     */
 /**
 * addDepartmentRow – jQuery-Version mit Auto-Escape
 */
function addDepartmentRow(code, dep, hospital_lat, hospital_lon, $detailsTable) {
  if ($detailsTable.find(`tr[data-code="${code}"]`).length) return;

  const label    = getLabel(code);
  const priority = dep.priority || 1;
  const lat      = dep.latitude  != null ? dep.latitude  : hospital_lat;
  const lon      = dep.longitude != null ? dep.longitude : hospital_lon;

  // 1) Row per jQuery
  const $tr = $(`
    <tr data-code="${code}">
      <td><input type="checkbox" checked></td>
      <td>${label}</td>
      <td>
        <select name="departments[${code}][priority]" style="width:60px">
          <option value="1"${priority==1?' selected':''}>1</option>
          <option value="2"${priority==2?' selected':''}>2</option>
          <option value="3"${priority==3?' selected':''}>3</option>
        </select>
      </td>
      <td>
        <input class="lat-input"  name="departments[${code}][latitude]"
               value="${lat.toFixed(6)}" style="width:70px">
        ,
        <input class="lon-input" name="departments[${code}][longitude]"
               value="${lon.toFixed(6)}" style="width:70px">
      </td>
    </tr>
  `);
  $detailsTable.append($tr);

  // 2) Marker anlegen (OpenLayers bleibt gleich)
  const feature = new ol.Feature({
    geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
    code, type: 'department'
  });
  feature.setStyle(new ol.style.Style({
    image: new ol.style.Circle({
      radius: 6,
      fill:   new ol.style.Fill({ color: getColor(code) }),
      stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
    }),
    hitDetection: new ol.style.Circle({ radius: 14 })
  }));
  window.deptSource.addFeature(feature);

  // 3) Inputs ↔ Marker synchronisieren
  $tr.find('.lat-input, .lon-input').on('change', function() {
    const nlat = parseFloat($tr.find('.lat-input').val());
    const nlon = parseFloat($tr.find('.lon-input').val());
    if (!isNaN(nlat) && !isNaN(nlon)) {
      feature.getGeometry().setCoordinates(ol.proj.fromLonLat([nlon, nlat]));
    }
  });

  // 4) Zeile entfernen
  $tr.find('input[type=checkbox]').on('change', function() {
    if (!this.checked) {
      $tr.remove();
      window.deptSource.removeFeature(feature);
      window.deptDragCollection.clear();
    }
  });
}


    /**
     * initDeptTranslateInteraction
     */
    function initDeptTranslateInteraction() {
        const dragCollection = new ol.Collection();
        window.deptTranslate = new ol.interaction.Translate({
            features: dragCollection
        });
        window.deptTranslate.on('translateend', e => {
            e.features.forEach(f => {
                const [lon, lat] = ol.proj.toLonLat(f.getGeometry().getCoordinates());
                const row = document.querySelector(`tr[data-code="${f.get('code')}"]`);
                if (row) {
                    row.querySelector('.lat-input').value = lat.toFixed(6);
                    row.querySelector('.lon-input').value = lon.toFixed(6);
                }
            });
        });
        window.deptMap.addInteraction(window.deptTranslate);
        window.deptDragCollection = dragCollection;
    }

    // row click → make that one draggable
    jQuery(document).on('click', '#departments-details-table tbody tr', function() {
        const code = jQuery(this).data('code');
        jQuery(this).siblings().removeClass('selected');
        jQuery(this).addClass('selected');
        window.deptDragCollection.clear();
        const feat = window.deptSource.getFeatures().find(f => f.get('code') === code);
        if (feat) window.deptDragCollection.push(feat);
    });

    // exports
    window.bindDepartmentForm = bindDepartmentForm;
    window.addDepartmentRow = addDepartmentRow;
    window.initDeptTranslateInteraction = initDeptTranslateInteraction;
})();
})(jQuery);