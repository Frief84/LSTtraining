// js/hospitals.js

(function($) {
	

    /* -------------------------------------------------------------
       1) Translate-Interaction EINMAL definieren
    ------------------------------------------------------------- */
    function initDeptTranslateInteraction() {
        const dragCollection = new ol.Collection();
        window.deptTranslate = new ol.interaction.Translate({
            features: dragCollection
        });
        window.deptTranslate.on('translateend', e => {
            e.features.forEach(f => {
                const code = f.get('code');
                const [lon, lat] = ol.proj.toLonLat(f.getGeometry().getCoordinates());
                const $row = $(`#departments-details-table tbody tr[data-code="${code}"]`);
                $row.find('.lat-input').val(lat.toFixed(6));
                $row.find('.lon-input').val(lon.toFixed(6));
            });
        });
        window.deptMap.addInteraction(window.deptTranslate);
        window.deptDragCollection = dragCollection; // global für Zeilen-Click
    }
    window.initDeptTranslateInteraction = initDeptTranslateInteraction;


    /**
     * Öffnet die Fachbereichs-Seite im Admin
     */
    function openDepartmentEditor(hospitalId) {
        const $modal = $('#departments-edit-modal');
        const $container = $modal.find('.departments-edit-content');

        if (!$modal.length || !$container.length) {
            console.error('Modal oder Inhalt nicht gefunden');
            return;
        }

        $.getJSON(lstHospitalsAjax.ajax_url, {
                action: 'get_departments',
                hospital_id: hospitalId,
                nonce: lstHospitalsAjax.nonce
            })
            .done(json => {
                if (!json.success) {
                    alert('Fehler: ' + json.data);
                    return;
                }
                const data = json.data;

                // 1) Template rendern
                const tpl = wp.template('departments-editor');
                $container.html(tpl({
                    hospital_id: hospitalId,
                    hospital_lat: data.hospital_lat,
                    hospital_lon: data.hospital_lon,
                    existing: data.existing,
                    departments: data.allowed
                }));

                // 2) tbody komplett leeren – ab hier befüllt bindDepartmentForm
                const $tbody = $container.find('#departments-details-table tbody').empty();
			
			
				// === Filter-Funktion für die Checkbox-Liste ==================
				const $filter = $container.find('#dept-filter');
				$filter.on('input', () => {
					const q = $filter.val().trim().toLowerCase();

					$container.find('#departments-selector label').each(function () {
						const txt = $(this).text().toLowerCase();
						$(this).toggle(txt.includes(q));
					});
				});

                // 3) Karte initialisieren
                const $mapDiv = $container.find('#dept-map').empty();
                window.deptSource = new ol.source.Vector();
                window.deptMap = new ol.Map({
                    target: $mapDiv[0],
                    layers: [
                        new ol.layer.Tile({ source: new ol.source.OSM() }),
                        new ol.layer.Vector({ source: window.deptSource })
                    ],
                    view: new ol.View({
                        center: ol.proj.fromLonLat([data.hospital_lon, data.hospital_lat]),
                        zoom: 14
                    })
                });

                // 4) Translate-Interaction initialisieren
                if (typeof initDeptTranslateInteraction === 'function') {
                    initDeptTranslateInteraction();
                }

                // 5) Krankenhaus-Marker (unabhängig von Departments)
                const hospitalMarker = new ol.Feature({
                    geometry: new ol.geom.Point(
                        ol.proj.fromLonLat([data.hospital_lon, data.hospital_lat])
                    ),
                    type: 'hospital'
                });
                hospitalMarker.setStyle(new ol.style.Style({
                    image: new ol.style.Icon({
                        src: lstHospitalsAjax.plugin_url + 'img/hospital_icon.png',
                        scale: 1.2,
                        anchor: [0.5, 1]
                    })
                }));
                window.deptSource.addFeature(hospitalMarker);

                // 6) Modal anzeigen & Abbrechen-Handler
                $modal.removeClass('hidden');
                $container.find('#departments-edit-cancel')
                    .off('click')
                    .on('click', () => $modal.addClass('hidden'));

                // 7) bindDepartmentForm + Vorbelegung
                bindDepartmentForm({
                    hospital_lat: data.hospital_lat,
                    hospital_lon: data.hospital_lon,
                    existing: data.existing
                });
				// Checkboxen anhand von existing-Codes setzen
				const active = data.existing.map(d => {
  if (d.code) return d.code.toUpperCase();               // erwartetes Format
  const k = Object.keys(d)[0];                           // { "CODE": {…} }
  return k ? k.toUpperCase() : '';
});
				$container.find('.dept-toggle').each(function () {
				const code = $(this).val().toUpperCase();
				if (active.includes(code)) {
						$(this).prop('checked', true).trigger('change');
					 }
				 });

                // 8) Save-Handler
                $container.find('#departments-edit-form')
                    .off('submit')
                    .on('submit', e => {
                        e.preventDefault();
                        const fd = new FormData(e.target);
						fd.set('action', 'lsttraining_save_departments');
                        fd.set('nonce', lstHospitalsAjax.nonce);
                        fetch(`${lstHospitalsAjax.ajax_url}?action=lsttraining_save_departments`, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: fd
                            })
                            .then(r => r.json())
                            .then(res => {
                                if (!res.success) throw new Error(res.data || 'Speichern fehlgeschlagen');
                                $modal.addClass('hidden');
                                fetchHospitals();
                            })
                            .catch(err => {
                                console.error('Save-Error:', err);
                                alert('Fehler beim Speichern: ' + err.message);
                            });
                    });

            })
            .fail((jqXHR, textStatus, err) => {
                console.error('Department-Editor laden fehlgeschlagen', err);
                alert('Editor konnte nicht geladen werden: ' + err);
            });
    }
    window.openDepartmentEditor = openDepartmentEditor;

    // Delegierte Buttons auf der Krankenhäuser-Seite
    $(document).on('click', '.edit-departments, #h-departments-button', function(e) {
        e.preventDefault();
        openDepartmentEditor($(this).data('id'));
    });


    // 1. OpenLayers-Map initialisieren
    const map = new ol.Map({
        target: 'krankenhaus-map',
        layers: [
            new ol.layer.Tile({ source: new ol.source.OSM() })
        ],
        view: new ol.View({
            center: ol.proj.fromLonLat([10.5, 51.2]),
            zoom: 6
        })
    });

    // 2. Vector-Layer für Krankenhäuser
    const vectorSource = new ol.source.Vector();
    const hospLayer = new ol.layer.Vector({ source: vectorSource });
    map.addLayer(hospLayer);

    // --- Tooltip-Overlay für Marker ---
    const tooltipContainer = document.createElement('div');
    tooltipContainer.className = 'hospital-tooltip hidden';
    document.body.appendChild(tooltipContainer);

    // stopEvent: true sorgt dafür, dass Klicks auf den Tooltip nicht an die Karte weitergegeben werden
    const tooltipOverlay = new ol.Overlay({
        element: tooltipContainer,
        positioning: 'bottom-center',
        offset: [0, -10],
        stopEvent: true
    });
    map.addOverlay(tooltipOverlay);

    // Klick auf die Karte abfangen
    map.on('singleclick', evt => {
        // Nur Features aus dem hospLayer prüfen
        const clickedFeat = map.forEachFeatureAtPixel(
            evt.pixel,
            (feature, layer) => (layer === hospLayer ? feature : null),
            { hitTolerance: 6 }
        );

        if (clickedFeat) {
            const coord = clickedFeat.getGeometry().getCoordinates();
            const props = clickedFeat.getProperties();
            const id = props.id;
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
            // Klick außerhalb → Tooltip ausblenden
            tooltipContainer.classList.add('hidden');
        }
    });

    let sortField = null;
    let sortAsc = true;
	 tooltipContainer.addEventListener('click', e => {
		const btn = e.target.closest('.hospital-tooltip-edit');
		if (!btn) return;
		e.stopPropagation();
		const id = btn.dataset.id;
		openEditForm(id);
	});
	
    // Header-Handler binden
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('table.widefat.fixed th[data-field]').forEach(th => {
            th.addEventListener('click', () => {
                const field = th.dataset.field;
                if (sortField === field) {
                    sortAsc = !sortAsc;
                } else {
                    sortField = field;
                    sortAsc = true;
                }
                updateSortIndicators();
                fetchHospitals();
            });
        });
    });

    function updateSortIndicators() {
        document.querySelectorAll('th[data-field]').forEach(th => {
            const f = th.dataset.field;
            th.textContent = th.textContent.replace(/[\u25B2\u25BC]/g, '').trim();
            if (f === sortField) {
                th.textContent += sortAsc ? ' \u25B2' : ' \u25BC';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const wrap = document.querySelector('#krankenhaus-map').closest('.wrap');
        if (wrap && !wrap.querySelector('#hospital-search')) {
            const input = document.createElement('input');
            input.id = 'hospital-search';
            input.type = 'search';
            input.placeholder = 'Suche nach ID oder Name…';
            input.style.cssText = 'margin-bottom:1em; width:100%; padding:4px;';
            wrap.querySelector('table.widefat.fixed').before(input);
            input.addEventListener('input', () => fetchHospitals());
        }
    });

    function fetchHospitals() {
        const term = document.getElementById('hospital-search')?.value.trim().toLowerCase() || '';
        fetch(`${lstHospitalsAjax.ajax_url}?action=get_krankenhaeuser&nonce=${encodeURIComponent(lstHospitalsAjax.nonce)}`, { credentials: 'same-origin' })
            .then(res => res.json())
            .then(data => {
                const filtered = term
                    ? data.filter(kh =>
                        kh.id.toString().includes(term) ||
                        kh.name.toLowerCase().includes(term)
                      )
                    : data;

                const wrap = document.querySelector('#krankenhaus-map').closest('.wrap');
                const table = wrap.querySelector('table.widefat.fixed');
                if (table) {
                    table.style.tableLayout = 'auto';
                    table.querySelectorAll('th[width]').forEach(th => th.style.width = 'auto');
                }
                const tbody = wrap.querySelector('tbody');
                tbody.innerHTML = '';

                vectorSource.clear();

                filtered.forEach(kh => {
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

                    const lat = parseFloat(kh.latitude), lon = parseFloat(kh.longitude);
                    if (!isNaN(lat) && !isNaN(lon)) {
                        const feat = new ol.Feature({
                            geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])),
                            id: kh.id,
                            name: kh.name
                        });
                        vectorSource.addFeature(feat);
                    }
                });

                if (vectorSource.getFeatures().length) {
                    map.getView().fit(vectorSource.getExtent(), {
                        padding: [20,20,20,20],
                        maxZoom: 12
                    });
                }
            })
            .catch(err => {
                console.error('Fehler beim Laden der Krankenhäuser:', err);
                alert('Fehler beim Laden der Krankenhäuser');
            });
    }

    (function($) {
        /* 1) Krankenhaus-Bearbeiten (Stift-Icon) */
        $(document).on('click', '.edit-krankenhaus', function(e) {
            e.preventDefault();
            openEditForm($(this).data('id'));
        });

        /* 2) Fachbereiche-Button in der Tabelle */
        $(document).on('click', '.edit-departments', function(e) {
            e.preventDefault();
            openDepartmentEditor($(this).data('id'));
        });

        /* 3) Fachbereiche-Button IM Pop-up */
        $(document).on('click', '#h-departments-button', function(e) {
            e.preventDefault();
            openDepartmentEditor($(this).data('id'));
        });

        /* 4) Krankenhaus löschen */
        $(document).on('click', '.delete-krankenhaus', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            if (!confirm('Krankenhaus wirklich löschen?')) return;

            $.ajax({
                url: lstHospitalsAjax.ajax_url,
                type: 'POST',
                data: { action: 'delete_krankenhaus', id, nonce: lstHospitalsAjax.nonce },
                xhrFields: { withCredentials: true }
            })
            .done(resp => {
                if (!resp.success) return alert(resp.data || 'Löschen fehlgeschlagen');
                fetchHospitals();
            })
            .fail((_, __, err) => {
                console.error('Löschen-Fehler:', err);
                alert('Löschen fehlgeschlagen: ' + err);
            });
        });
    })(jQuery);

    // 5) Neuer Eintrag
    jQuery(function($) {
        $('#btn-new-krankenhaus').on('click', () => openEditForm(null));
    });

    // 6) Modal öffnen und befüllen
    function openEditForm(id) {
		console.log("openEditForm")
        const modal = document.getElementById('hospital-edit-modal');
        const content = modal.querySelector('.hospital-edit-content');
        if (!modal || !content) {
            console.error('Modal oder Inhalt nicht gefunden');
            return;
        }

        const loadData = id
            ? fetch(`${lstHospitalsAjax.ajax_url}?action=get_krankenhaus&id=${id}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.data || 'Unknown');
                    return r.data;
                })
            : Promise.resolve({
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
                const tpl = wp.template('hospital-edit-form');
                content.innerHTML = tpl(data);

                const mapDiv = document.getElementById('hospital-map-edit');
                if (mapDiv) {
                    mapDiv.innerHTML = '';
                    const editMap = new ol.Map({
                        target: 'hospital-map-edit',
                        layers: [new ol.layer.Tile({ source: new ol.source.OSM() })],
                        view: new ol.View({
                            center: ol.proj.fromLonLat([+data.longitude, +data.latitude]),
                            zoom: 12
                        })
                    });
                    const editSource = new ol.source.Vector();
                    editMap.addLayer(new ol.layer.Vector({ source: editSource }));
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
					
					  const coordsInput = document.getElementById('h-coords');
                if (!coordsInput) {
                    console.error('Element #h-coords nicht gefunden');
                } else {
                    coordsInput.addEventListener('change', () => {
                        const raw = coordsInput.value.trim();
                        const parts = raw.split(',');
                        if (parts.length !== 2) {
                            console.warn('Ungültiges Koordinaten-Format:', raw);
                            return;
                        }
                        const lat = parseFloat(parts[0].trim().replace(',', '.'));
                        const lon = parseFloat(parts[1].trim().replace(',', '.'));
                        if (isNaN(lat) || isNaN(lon)) {
                            console.warn('Koordinaten konnten nicht als Zahlen geparst werden:', raw);
                            return;
                        }

                        // NEU: aus lat/lon WebMercator‐Koordinaten erzeugen
                        const newCoords = ol.proj.fromLonLat([lon, lat]);
                        // NEU: direkt das GEOMETRIE‐Objekt von 'marker' verschieben
                        marker.getGeometry().setCoordinates(newCoords);
                        // Optional: View zentrieren
                        editMap.getView().animate({ center: newCoords, duration: 300 });

                        console.log(`Marker verschoben auf: Lat=${lat}, Lon=${lon}`);
                    });
                }
					
					
                } else {
                    console.warn('Map-Div nicht gefunden: #hospital-map-edit');
                }

                modal.classList.remove('hidden');
                document.getElementById('hospital-edit-cancel')
                    .addEventListener('click', () => modal.classList.add('hidden'));

				document.getElementById('hospital-edit-form').addEventListener('submit', e => {
					e.preventDefault();
					const form = e.target;
					const mode = form.dataset.mode;                // 'create' | 'edit'

					
					/* ---------------- 1) Payload bauen -------------------- */
					const fd = new FormData(form);

					// ► Nur im CREATE-Modus Departments mitsenden
					if (mode === 'create') {
						const departments = {};
						form.querySelectorAll('input[name^="departments["]').forEach(input => {
							const m = input.name.match(/^departments\[(.+?)\]\[(.+?)\]$/);
							if (m) {
								const [ , code, key ] = m;
								departments[code] = departments[code] || {};
								departments[code][key] = input.type === 'checkbox' ? input.checked : input.value;
							}
						});
						if (Object.keys(departments).length) {
							fd.set('departments', JSON.stringify(departments));
						}
					}

					/* ---------------- 2) Ziel-Action wählen --------------- */
					let action;
					if (mode === 'create') {
						action = 'lsttraining_create_krankenhaus';
						fd.delete('id');                     // sicherstellen, dass kein leeres id-Feld gesendet wird
					} else {
						action = 'save_krankenhaus';
						// id bleibt im FormData
					}
					fd.append('action', action);
					fd.set('nonce', lstHospitalsAjax.nonce);

					/* ---------------- 3) Ajax-Aufruf ----------------------- */
					fetch(lstHospitalsAjax.ajax_url, {
						method: 'POST',
						credentials: 'same-origin',
						body: fd
					})
					.then(r => r.json())
					.then(json => {
						if (!json.success) throw new Error(json.data || 'Speichern fehlgeschlagen');
						document.getElementById('hospital-edit-modal').classList.add('hidden');
						fetchHospitals();                   // Tabelle + Marker neu laden
					})
					.catch(err => {
						console.error('Speichern-Fehler:', err);
						alert('Speichern fehlgeschlagen: ' + err.message);
					});
				});

			
                const deleteBtn = document.getElementById('hospital-delete-button');
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', () => {
                        if (!confirm('Krankenhaus wirklich löschen?')) return;
                        const idToDelete = deleteBtn.dataset.id;
                        const params = new URLSearchParams({ id: idToDelete, nonce: lstHospitalsAjax.nonce });
                        fetch(`${lstHospitalsAjax.ajax_url}?action=delete_krankenhaus`, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
            })
            .catch(err => {
                console.error('Daten konnten nicht geladen werden:', err);
                alert('Daten konnten nicht geladen werden: ' + err.message);
            });
    }


    // Initialer Aufruf
    document.addEventListener('DOMContentLoaded', () => {
        fetchHospitals();
    });

})(jQuery);
