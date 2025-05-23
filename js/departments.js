(function () {
	
	window.activeDeptCode = null; 
  // Erlaubte Fachbereiche (statisch vorgegeben)
  const allowed = {
    NOTF: 'Innere Notaufnahme',
    KINA: 'Kinder-Notaufnahme',
    CHIR: 'Chirurgie',
    ISTX: 'Chirurgische Intensivstation',
    CT: 'Computertomographie',
    DERM: 'Dermatologie',
    DRAM: 'Druckkammer',
    VASG: 'Gefäßchirurgie',
    GYNO: 'Gynäkologie',
    HNOK: 'HNO-Heilkunde',
    INTX: 'Innere Intensivstation',
    CARD: 'Kardiologie',
    KESS: 'Kreißsaal',
    MRT: 'Magnetresonanztomographie',
    MKGC: 'MKG-Chirurgie',
    NECH: 'Neurochirurgie',
    NEUR: 'Neurologie',
    NOTO: 'Notoperation',
    NUKL: 'Nuklearmedizin',
    ONKO: 'Onkologie',
    PSYC: 'Psychiatrie',
    PED: 'Pädiatrie',
    KKH: 'Kinderkrankenhaus',
    STRK: 'Stroke Unit',
    UROL: 'Urologie',
    BURN: 'Brandverletzten-Station',
    CAT: 'Herzkatheteruntersuchung'
  };

 function bindDepartmentForm(data) {
	 console.log('bindDepartmentForm aufgerufen mit:', data);
  const modal = document.getElementById('departments-edit-modal');
  const container = modal.querySelector('.departments-edit-content');
  const form = container.querySelector('#departments-edit-form');
  const cancelBtn = container.querySelector('#departments-edit-cancel');
  const selector = container.querySelector('#departments-selector');
  const detailsTable = container.querySelector('#departments-details-table tbody');

  if (!modal || !form || !selector || !detailsTable) {
    console.warn('Ein benötigtes Element fehlt.');
    return;
  }

  // Cancel-Button
  if (cancelBtn) {
    cancelBtn.addEventListener('click', () => modal.classList.add('hidden'));
  }

  // Submit
  form.addEventListener('submit', e => {
    e.preventDefault();
    const fd = new FormData(form);
    fetch(`${lstHospitalsAjax.ajax_url}?action=save_departments`, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    })
      .then(r => r.json())
      .then(json => {
        if (!json.success) throw new Error(json.data || 'Fehler');
        alert('Gespeichert');
        modal.classList.add('hidden');
      })
      .catch(err => {
        console.error('Speichern fehlgeschlagen:', err);
        alert('Fehler beim Speichern: ' + err.message);
      });
  });

  // Checkbox-Änderung
  selector.addEventListener('change', e => {
    if (!e.target.matches('.dept-toggle')) return;
    const code = e.target.value;
    if (e.target.checked) {
      const dep = data.departments.find(d => d.code === code) || {};
      addDepartmentRow(code, dep, data.allowed, data.hospital_lat, data.hospital_lon, detailsTable);
    } else {
      const row = detailsTable.querySelector(`tr[data-code="${code}"]`);
      if (row) row.remove();
    }
  });

  // Vorbelegung
  data.departments.forEach(dep => {
    const box = selector.querySelector(`input[type="checkbox"][value="${dep.code}"]`);
    if (box) {
      box.checked = true;
      addDepartmentRow(dep.code, dep, data.allowed, data.hospital_lat, data.hospital_lon, detailsTable);
    }
  });
}


  window.openDepartmentEditor = function (hospitalId) {
    const modal = document.getElementById('departments-edit-modal');
    const container = modal.querySelector('.edit-content');
    modal.classList.add('loading');

    fetch(`${lstHospitalsAjax.ajax_url}?action=get_krankenhaus&id=${hospitalId}`, {
      credentials: 'same-origin'
    })
      .then(r => r.json())
      .then(json => {
        if (!json.success) throw new Error(json.data || 'Error');
        const d = json.data;


const tpl = wp.template('departments-editor');
  container.innerHTML = tpl({
    hospital_id: d.hospital_id,
    hospital_lat: d.hospital_lat,
    hospital_lon: d.hospital_lon,
    departments: d.existing, // wichtig: bestehende!
    allowed: d.allowed
  });

  requestAnimationFrame(() => bindDepartmentForm({
    hospital_lat: d.hospital_lat,
    hospital_lon: d.hospital_lon,
    departments: d.existing,   // konsistent!
    allowed: d.allowed
  }));

        modal.classList.remove('hidden', 'loading');
      })
      .catch(err => {
        console.error(err);
        alert('Fachbereiche konnten nicht geladen werden');
        modal.classList.remove('loading');
      });
  };

  function initDepartmentSelector(data) {
	  console.log('initDepartmentSelector');
  const $ = jQuery;
  const $selector = $('#departments-selector');
  const $details = $('#departments-details-table tbody');
  const existing = data.departments || [];
  const allowed = data.allowed || {};
  const hospital_lat = data.hospital_lat;
  const hospital_lon = data.hospital_lon;

  // Checkbox-Änderung ? Zeile einfügen oder löschen
  $selector.on('change', '.dept-toggle', function () {
    const code = this.value;
    if (this.checked) {
      const dep = existing.find(d => d.code === code) || {};
      window.addDepartmentRow(code, dep, allowed, hospital_lat, hospital_lon);
    } else {
      $details.find(`tr[data-code="${code}"]`).remove();
      if (window.deptSource) {
        const feat = window.deptSource.getFeatures().find(f => f.get('code') === code);
        if (feat) window.deptSource.removeFeature(feat);
      }
    }
  });

// Bestehende Departments initial aktivieren + Zeilen erzeugen
existing.forEach(dep => {
  const code = dep.code;
  const checkbox = $selector.find(`input[value="${code}"]`);
  if (checkbox.length) {
    checkbox.prop('checked', true);
    
    // Direkt Zeile hinzufügen, ohne "change"-Event
    window.addDepartmentRow(code, dep, allowed, hospital_lat, hospital_lon);
  } else {
    console.warn('Checkbox nicht gefunden für:', code);
  }
});
}


  // Listener für alle Fachbereichs-Buttons
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.edit-departments, #h-departments-button');
    if (!btn) return;
    e.preventDefault();
    openDepartmentEditor(btn.dataset.id);
  });

  function initDepartmentRowListeners(code) {
    const row = document.querySelector(`tr[data-code="${code}"]`);
    if (!row) return;

    const latInput = row.querySelector(`input[name="departments[${code}][latitude]"]`);
    const lonInput = row.querySelector(`input[name="departments[${code}][longitude]"]`);

    const onCoordChange = () => {
      const lat = parseFloat(latInput.value);
      const lon = parseFloat(lonInput.value);

      if (!isNaN(lat) && !isNaN(lon) && window.deptSource) {
        const feat = window.deptSource.getFeatures().find(f => f.get('code') === code);
        if (feat) {
          feat.getGeometry().setCoordinates(ol.proj.fromLonLat([lon, lat]));
          console.log(`?? Marker für ${code} aktualisiert`);
        }
      }
    };

    latInput.addEventListener('change', onCoordChange);
    lonInput.addEventListener('change', onCoordChange);
  }
	
	// 7. Zusätzliche Marker für bestehende Departments
      function getColor(code) {
        const palette = {
          NOTF: '#e41a1c', KINA: '#377eb8', CHIR: '#4daf4a', ISTX: '#984ea3', CT: '#ff7f00',
          DERM: '#ffff33', DRAM: '#a65628', VASG: '#f781bf', GYNO: '#999999', HNOK: '#66c2a5',
          INTX: '#a6cee3', CARD: '#1f78b4', KESS: '#b2df8a', MRT: '#33a02c', MKGC: '#fb9a99',
          NECH: '#e31a1c', NEUR: '#fdbf6f', NOTO: '#ff7f00', NUKL: '#cab2d6', ONKO: '#6a3d9a',
          PSYC: '#b15928', PED: '#8dd3c7', KKH: '#ffffb3', STRK: '#bebada', UROL: '#fb8072',
          BURN: '#80b1d3', CAT: '#fdb462'
        };
        return palette[code] || '#000';
      }
	
window.addDepartmentRow = function (code, dep, allowed, hospital_lat, hospital_lon) {
    const $details = jQuery('#departments-details-table tbody');
    if ($details.find(`tr[data-code="${code}"]`).length) return;

    /* ------------------------------------------------------------------
       1) Tabellenzeile anlegen
    ------------------------------------------------------------------ */
    const label    = allowed[code];
    const priority = dep.priority || 1;
    const lat      = dep.latitude  || hospital_lat;
    const lon      = dep.longitude || hospital_lon;

    const tr = document.createElement('tr');
    tr.dataset.code = code;
    tr.innerHTML = `
      <td><input type="checkbox" name="departments[${code}][enabled]" checked></td>
      <td>${label}</td>
      <td>
        <select name="departments[${code}][priority]" style="width: 60px;">
          <option value="1" ${priority == 1 ? 'selected' : ''}>1</option>
          <option value="2" ${priority == 2 ? 'selected' : ''}>2</option>
          <option value="3" ${priority == 3 ? 'selected' : ''}>3</option>
        </select>
      </td>
      <td>
        <input name="departments[${code}][latitude]"  value="${lat}" style="width:70px" class="lat-input"> ,
        <input name="departments[${code}][longitude]" value="${lon}" style="width:70px" class="lon-input">
      </td>`;
    $details.append(tr);

    /* ------------------------------------------------------------------
       2) OpenLayers-Feature ERST ANLEGEN …
    ------------------------------------------------------------------ */
    const feature = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
        code: code,
        type: 'department'
    });

    /* ------------------------------------------------------------------
       … dann Farbe bestimmen und Style setzen
    ------------------------------------------------------------------ */
    const color = (typeof getColor === 'function') ? getColor(code) : '#1976d2';

    feature.setStyle(new ol.style.Style({
        image: new ol.style.Circle({
            radius: 6,
            fill:   new ol.style.Fill({ color }),
            stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
        }),
        hitDetection: new ol.style.Circle({ radius: 14 })
    }));

    window.deptSource.addFeature(feature);

    /* ------------------------------------------------------------------
       3) Inputs ? Marker synchronisieren …
    ------------------------------------------------------------------ */
    const latInput = tr.querySelector('.lat-input');
    const lonInput = tr.querySelector('.lon-input');

    const updateMarker = () => {
        const newLat = parseFloat(latInput.value);
        const newLon = parseFloat(lonInput.value);
        if (!isNaN(newLat) && !isNaN(newLon)) {
            feature.getGeometry().setCoordinates(
                ol.proj.fromLonLat([newLon, newLat])
            );
        }
    };
    latInput.addEventListener('change', updateMarker);
    lonInput.addEventListener('change', updateMarker);

    /* ------------------------------------------------------------------
       4) Zeilen-Checkbox wieder entfernt? ? Feature löschen / Drag sperren
    ------------------------------------------------------------------ */
    tr.querySelector('input[type="checkbox"]').addEventListener('change', function () {
        if (!this.checked) {
            tr.remove();
            window.deptSource.removeFeature(feature);

            if (window.activeDeptCode === code) {
                window.activeDeptCode = null;
                window.deptDragCollection.clear();
            }

            const selBox = document.querySelector(
                `#departments-selector input[value="${code}"]`
            );
            if (selBox) selBox.checked = false;
        }
    });
};


/* ------------------------------------------------------------------
   Ein-Zeilen-Selektion in #departments-details-table  (jQuery, delegiert)
------------------------------------------------------------------ */
jQuery(function ($) {

    $(document).on('click', '#departments-details-table tbody tr', function () {
        const $row = $(this);
        const code = $row.data('code');

        // visuelle Auswahl
        $row.closest('tbody').find('tr.selected').removeClass('selected');
        $row.addClass('selected');

        // nur das passende Feature in die Collection legen
        const feat = window.deptSource.getFeatures()
                       .find(f => f.get('code') === code);   // department-Marker

        window.deptDragCollection.clear();
        if (feat) window.deptDragCollection.push(feat);      // jetzt ziehbar
    });
});

	function initDeptTranslateInteraction() {

    // Collection, in die wir immer genau 1 Feature legen
    const dragCollection = new ol.Collection();

    window.deptTranslate = new ol.interaction.Translate({
        features: dragCollection          // ? nur was hier drin liegt ist ziehbar
    });

    // Nach dem Verschieben Koordinaten zurück in die Inputs
    window.deptTranslate.on('translateend', e => {
        e.features.forEach(f => {
            const code        = f.get('code');
            const [lon, lat ] = ol.proj.toLonLat(f.getGeometry().getCoordinates());
            const row         = document.querySelector(`tr[data-code="${code}"]`);
            if (row) {
                row.querySelector('.lat-input').value =  lat.toFixed(6);
                row.querySelector('.lon-input').value =  lon.toFixed(6);
            }
        });
    });

    window.deptMap.addInteraction(window.deptTranslate);

    // global, damit wir aus dem Row-Click darauf zugreifen können
    window.deptDragCollection = dragCollection;
}

	
window.bindDepartmentForm = bindDepartmentForm;
})(); // <- Abschluss des IIFE

