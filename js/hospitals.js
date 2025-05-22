// hospitals.js

/**
 * Öffnet die Fachbereichs-Seite im Admin
 */
function openDepartmentEditor(hospitalId) {
  const modal = document.getElementById('departments-edit-modal');
  const content = modal.querySelector('.departments-edit-content');

  if (!modal || !content) {
    console.error('Modal oder Inhalt nicht gefunden');
    return;
  }

  fetch(`${lstHospitalsAjax.ajax_url}?action=get_departments&hospital_id=${hospitalId}`, {
    credentials: 'same-origin'
  })
    .then(res => {
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      return res.json();
    })
    .then(json => {
      if (!json.success) throw new Error(json.data);
      return json.data;
    })
    .then(data => {
      const { existing, allowed, hospital_lat, hospital_lon } = data;

      // 1. Template rendern
      const tpl = wp.template('departments-editor');
      content.innerHTML = tpl({
        hospital_id: hospitalId,
        hospital_lat,
        hospital_lon,
        departments: existing,
        allowed
      });

      // 2. Karte initialisieren VOR bindDepartmentForm!
      const mapDiv = content.querySelector('#dept-map');
      mapDiv.innerHTML = '';

      window.deptSource = new ol.source.Vector();
      window.deptMap = new ol.Map({
        target: mapDiv,
        layers: [
          new ol.layer.Tile({ source: new ol.source.OSM() }),
          new ol.layer.Vector({ source: window.deptSource })
        ],
        view: new ol.View({
          center: ol.proj.fromLonLat([hospital_lon, hospital_lat]),
          zoom: 14
        })
      });

      // 3. Marker für das Krankenhaus
      const hospitalMarker = new ol.Feature({
        geometry: new ol.geom.Point(ol.proj.fromLonLat([hospital_lon, hospital_lat]))
      });
      hospitalMarker.setStyle(new ol.style.Style({
        image: new ol.style.Icon({
          src: lstHospitalsAjax.plugin_url + 'img/hospital_icon.png',
          scale: 1.2,
          anchor: [0.5, 1]
        })
      }));
      window.deptSource.addFeature(hospitalMarker);

      // 4. Modal anzeigen
      modal.classList.remove('hidden');

      // 5. Cancel-Button
      const cancelBtn = modal.querySelector('#departments-edit-cancel');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', () => modal.classList.add('hidden'));
      }

      // 6. Nach DOM-Render Events und Checkboxen aktivieren
      requestAnimationFrame(() => {
        bindDepartmentForm({
          hospital_lat,
          hospital_lon,
          departments: existing,
          allowed
        });

        // Bestehende Departments aktivieren
        existing.forEach(dep => {
          const checkbox = content.querySelector(`input[type="checkbox"][value="${dep.code}"]`);
          if (checkbox) {
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));
          } else {
            console.warn('Fachbereich nicht gefunden im DOM:', dep.code);
          }
        });
      });

      existing.forEach(dep => {
        if (!dep.latitude || !dep.longitude) return;
        const feat = new ol.Feature({
          geometry: new ol.geom.Point(
            ol.proj.fromLonLat([parseFloat(dep.longitude), parseFloat(dep.latitude)])
          ),
          code: dep.code
        });
        feat.setStyle(new ol.style.Style({
          image: new ol.style.Circle({
            radius: 7,
            fill: new ol.style.Fill({ color: getColor(dep.code) }),
            stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
          })
        }));
        window.deptSource.addFeature(feat);
      });

      // 8. Speichern
      content.querySelector('#departments-edit-form')
        .addEventListener('submit', e => {
          e.preventDefault();
          const fd = new FormData(e.target);

          fetch(`${lstHospitalsAjax.ajax_url}?action=lsttraining_save_departments`, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          })
            .then(r => r.json())
            .then(res => {
              if (!res.success) throw new Error(res.data || 'Speichern fehlgeschlagen');
              modal.classList.add('hidden');
              fetchHospitals();
            })
            .catch(err => {
              console.error('Save-Error:', err);
              alert('Fehler beim Speichern: ' + err.message);
            });
        });
    })
    .catch(err => {
      console.error('Department-Editor laden fehlgeschlagen', err);
      alert('Editor konnte nicht geladen werden: ' + err.message);
    });
}


// 1. OpenLayers-Map initialisieren
const map = new ol.Map({
    target: 'krankenhaus-map',
    layers: [
        new ol.layer.Tile({
            source: new ol.source.OSM()
        })
    ],
    view: new ol.View({
        center: ol.proj.fromLonLat([10.5, 51.2]),
        zoom: 6
    })
});

// 2. Vector-Layer für Krankenhäuser
const vectorSource = new ol.source.Vector();
map.addLayer(new ol.layer.Vector({
    source: vectorSource
}));

// --- Tooltip-Overlay für Marker ---
const tooltipContainer = document.createElement('div');
tooltipContainer.className = 'hospital-tooltip hidden';
document.body.appendChild(tooltipContainer);

const tooltipOverlay = new ol.Overlay({
    element: tooltipContainer,
    positioning: 'bottom-center',
    offset: [0, -10],
    stopEvent: false
});
map.addOverlay(tooltipOverlay);

// Klick auf die Karte abfangen
map.on('singleclick', evt => {
    // Prüfen, ob wir auf ein Feature geklickt haben
    const feature = map.forEachFeatureAtPixel(evt.pixel, f => f);
    if (feature) {
        const coord = feature.getGeometry().getCoordinates();
        const props = feature.getProperties();
        const id = props.id; // musst Du beim Erzeugen setzen
        const name = props.name;

        // Overlay an die Klick-Koordinate setzen
        tooltipOverlay.setPosition(coord);

        // Inhalt des Tooltips
        tooltipContainer.innerHTML = `
      <div class="hospital-tooltip-content">
        <strong>${name}</strong>
        <button class="hospital-tooltip-edit button" data-id="${id}" title="Bearbeiten">
          ✎
        </button>
      </div>
    `;
        tooltipContainer.classList.remove('hidden');
    } else {
        // Klick außerhalb → ausblenden
        tooltipContainer.classList.add('hidden');
    }
});

// Klick auf den Stift führt ins Edit-Formular
tooltipContainer.addEventListener('click', e => {
    const btn = e.target.closest('.hospital-tooltip-edit');
    if (!btn) return;
    const id = btn.dataset.id;
    openEditForm(id);
});


let sortField = null; // aktuell nach welchem Feld sortiert wird
let sortAsc = true; // auf- oder absteigend

// Header-Handler binden
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.widefat.fixed th[data-field]').forEach(th => {
        th.addEventListener('click', () => {
            const field = th.dataset.field;
            if (sortField === field) {
                sortAsc = !sortAsc; // Richtung umkehren
            } else {
                sortField = field;
                sortAsc = true; // neu nach oben sortieren
            }
            // Markierung im Header aktualisieren (Pfeil einfügen)
            updateSortIndicators();
            // Tabelle neu laden und sortieren
            fetchHospitals();
        });
    });
});

// fügt Pfeile ▲ ▼ im Header hinzu
function updateSortIndicators() {
    document.querySelectorAll('th[data-field]').forEach(th => {
        const f = th.dataset.field;
        th.textContent = th.textContent.replace(/[\u25B2\u25BC]/g, '').trim();
        if (f === sortField) {
            th.textContent += sortAsc ? ' \u25B2' : ' \u25BC';
        }
    });
}

function fetchHospitals() {
    fetch(`${lstHospitalsAjax.ajax_url}?action=get_krankenhaeuser`, {
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            // --- 0) Daten sortieren, falls gewünscht ---
            if (sortField) {
                data.sort((a, b) => {
                    let va = a[sortField],
                        vb = b[sortField];
                    if (!isNaN(va) && !isNaN(vb)) {
                        va = parseFloat(va);
                        vb = parseFloat(vb);
                    } else {
                        va = va.toString().toLowerCase();
                        vb = vb.toString().toLowerCase();
                    }
                    if (va < vb) return sortAsc ? -1 : 1;
                    if (va > vb) return sortAsc ? 1 : -1;
                    return 0;
                });
            }


            // 1. Tabelle & Layout vorbereiten
            const wrap = document.querySelector('#krankenhaus-map').closest('.wrap');
            const table = wrap.querySelector('table.widefat.fixed');
            if (table) {
                table.style.tableLayout = 'auto';
                table.querySelectorAll('th[width]').forEach(th => th.style.width = 'auto');
            }
            const tbody = wrap.querySelector('tbody');
            tbody.innerHTML = '';

            // 2. Vector-Layer für Krankenhäuser
            vectorSource.clear();

            // 3. Zeilen und Marker anlegen
            data.forEach(kh => {
                // Tabelle
                const tr = document.createElement('tr');
                tr.innerHTML = `
        <td>${kh.id}</td>
        <td>${kh.name}</td>
        <td>${kh.versorgungsstufe}</td>
        <td>${kh.trauma_level}</td>
        <td>${kh.latitude}, ${kh.longitude}</td>
        <td style="white-space: nowrap;">
          <button class="button edit-krankenhaus" data-id="${kh.id}">Bearbeiten</button>
          <button class="button button-secondary edit-departments" data-id="${kh.id}">Fachbereiche</button>
          <button class="button button-link-delete delete-krankenhaus" data-id="${kh.id}">Löschen</button>
        </td>`;
                tbody.appendChild(tr);

                // Karte
                const lat = parseFloat(kh.latitude),
                    lon = parseFloat(kh.longitude);
                if (!isNaN(lat) && !isNaN(lon)) {
                    const feat = new ol.Feature({
                        geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
                        id: kh.id,
                        name: kh.name
                    });
                    vectorSource.addFeature(feat);
                }
            });

            // 4. Zoom auf alle Features
            if (vectorSource.getFeatures().length) {
                map.getView().fit(vectorSource.getExtent(), {
                    padding: [20, 20, 20, 20],
                    maxZoom: 12
                });
            }

            // 5. Listener neu binden
            setupEditButtons();
        })
        .catch(err => {
            console.error('Fehler beim Laden der Krankenhäuser:', err);
            alert('Fehler beim Laden der Krankenhäuser');
        });
}


// 4. Edit-Buttons binden
function setupEditButtons() {
    // Krankenhaus bearbeiten
    document.querySelectorAll('.edit-krankenhaus').forEach(btn => {
        btn.onclick = e => {
            e.preventDefault();
            openEditForm(btn.dataset.id);
        };
    });



    // 2) Button im Hospital-Edit-Popup
    const deptBtn = document.getElementById('h-departments-button');
    if (deptBtn) {
        deptBtn.addEventListener('click', e => {
            e.preventDefault();
            openDepartmentEditor(deptBtn.dataset.id);
        });
    }

    // Löschen per AJAX
    document.querySelectorAll('.delete-krankenhaus').forEach(btn => {
        btn.onclick = e => {
            e.preventDefault();
            if (!confirm('Krankenhaus wirklich löschen?')) return;
            const idToDelete = btn.dataset.id;
            fetch(`${lstHospitalsAjax.ajax_url}?action=delete_krankenhaus`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        id: idToDelete
                    })
                })
                .then(r => r.json())
                .then(json => {
                    if (!json.success) throw new Error(json.data || 'Löschen fehlgeschlagen');
                    fetchHospitals();
                })
                .catch(err => {
                    console.error('Löschen-Fehler:', err);
                    alert('Löschen fehlgeschlagen: ' + err.message);
                });
        };
    });
}




// 5. Neuer Eintrag
document.getElementById('btn-new-krankenhaus')
    .addEventListener('click', () => openEditForm(null));

// 6. Modal öffnen und befüllen
function openEditForm(id) {
    const modal = document.getElementById('hospital-edit-modal');
    const content = modal.querySelector('.hospital-edit-content');
    if (!modal || !content) {
        console.error('Modal oder Inhalt nicht gefunden');
        return;
    }

    // Daten laden (bestehendes Krankenhaus oder Default)
    const loadData = id ?
        fetch(`${lstHospitalsAjax.ajax_url}?action=get_krankenhaus&id=${id}`, {
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(r => {
            if (!r.success) throw new Error(r.data || 'Unknown');
            return r.data;
        }) :
        Promise.resolve({
            id: '',
            name: '',
            versorgungsstufe: 'Grundversorgung',
            trauma_level: 0,
            latitude: 0,
            longitude: 0,
            departments: '',
            helipad: 0
        });

    loadData
        .then(data => {
            // 1) Formular-Template rendern
            const tpl = wp.template('hospital-edit-form');
            content.innerHTML = tpl(data);

            // 2) Karte initialisieren
            const mapDiv = document.getElementById('hospital-map-edit');
            if (mapDiv) {
                mapDiv.innerHTML = '';
                const editMap = new ol.Map({
                    target: 'hospital-map-edit',
                    layers: [new ol.layer.Tile({
                        source: new ol.source.OSM()
                    })],
                    view: new ol.View({
                        center: ol.proj.fromLonLat([+data.longitude, +data.latitude]),
                        zoom: 12
                    })
                });
                const editSource = new ol.source.Vector();
                editMap.addLayer(new ol.layer.Vector({
                    source: editSource
                }));
                const marker = new ol.Feature({
                    geometry: new ol.geom.Point(
                        ol.proj.fromLonLat([+data.longitude, +data.latitude])
                    )
                });
                marker.setStyle(new ol.style.Style({
                    image: new ol.style.Icon({
                        src: lstHospitalsAjax.plugin_url + 'img/hospital_icon.png',
                        anchor: [0.5, 1]
                    })
                }));
                editSource.addFeature(marker);
                const dragInteraction = new ol.interaction.Modify({
                    source: editSource,
                    style: null
                });
                editMap.addInteraction(dragInteraction);
                dragInteraction.on('modifyend', () => {
                    const [lon, lat] = ol.proj.toLonLat(marker.getGeometry().getCoordinates());
                    document.getElementById('h-lat').value = lat.toFixed(6);
                    document.getElementById('h-lon').value = lon.toFixed(6);
                    document.getElementById('h-coords').value = `${lat.toFixed(6)}, ${lon.toFixed(6)}`;
                });
            } else {
                console.warn('Map-Div nicht gefunden: #hospital-map-edit');
            }

            // 3) Modal anzeigen
            modal.classList.remove('hidden');

            // 4) Abbrechen
            document.getElementById('hospital-edit-cancel')
                .addEventListener('click', () => modal.classList.add('hidden'));

            // 5) Speichern
            document.getElementById('hospital-edit-form').addEventListener('submit', e => {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);

  // Optional: alle dept-* Felder einsammeln und in JSON wandeln
  const departments = {};

  form.querySelectorAll('input[name^="departments["]').forEach(input => {
    const match = input.name.match(/^departments\[(.+?)\]\[(.+?)\]$/);
    if (match) {
      const code = match[1];
      const key = match[2];
      departments[code] = departments[code] || {};
      departments[code][key] = input.type === 'checkbox' ? input.checked : input.value;
    }
  });

  fd.set('departments', JSON.stringify(departments));

  fetch(`${lstHospitalsAjax.ajax_url}?action=save_krankenhaus`, {
    method: 'POST',
    credentials: 'same-origin',
    body: fd
  })
    .then(r => r.json())
    .then(json => {
      if (!json.success) throw new Error(json.data || 'Speichern fehlgeschlagen');
      document.getElementById('hospital-edit-modal').classList.add('hidden');
      fetchHospitals();
    })
    .catch(err => {
      console.error('Speichern-Fehler:', err);
      alert('Speichern fehlgeschlagen: ' + err.message);
    });
});


            // 6) Löschen
            const deleteBtn = document.getElementById('hospital-delete-button');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', () => {
                    if (!confirm('Krankenhaus wirklich löschen?')) return;
                    const idToDelete = deleteBtn.dataset.id;
                    console.log('Lösche Krankenhaus mit ID=', idToDelete);
                    const params = new URLSearchParams({
                        id: idToDelete
                    });
                    fetch(`${lstHospitalsAjax.ajax_url}?action=delete_krankenhaus`, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: params
                        })
                        .then(r => r.json())
                        .then(json => {
                            if (!json.success) throw new Error(json.data || 'Löschen fehlgeschlagen');
                            modal.classList.add('hidden');
                            fetchHospitals();
                        })
                        .catch(err => {
                            console.error('Löschen-Fehler:', err);
                            alert('Löschen fehlgeschlagen: ' + err.message);
                        });
                });
            } else {
                console.warn('Delete-Button nicht gefunden: #hospital-delete-button');
            }

        }) // Ende then(data => { … })
        .catch(err => {
            console.error('Daten konnten nicht geladen werden:', err);
            alert('Daten konnten nicht geladen werden: ' + err.message);
        });
} // Ende function openEditForm(id)


// Initialer Aufruf (nur EINMAL nach Laden des DOM)
document.addEventListener('DOMContentLoaded', () => {
    fetchHospitals();
    setupEditButtons();
});

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