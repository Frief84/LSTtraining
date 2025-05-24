// hospitals.js

(function($) {

        /* -------------------------------------------------------------
           1) Translate-Interaction EINMAL definieren
        ------------------------------------------------------------- */
        // 1) Translate-Interaction einmal definieren
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
    const $modal     = $('#departments-edit-modal');
    const $container = $modal.find('.departments-edit-content');

    if (!$modal.length || !$container.length) {
        console.error('Modal oder Inhalt nicht gefunden');
        return;
    }

    $.getJSON(lstHospitalsAjax.ajax_url, {
        action:      'get_departments',
        hospital_id: hospitalId
    })
    .done(json => {
        if (!json.success) {
            alert('Fehler: ' + json.data);
            return;
        }
        const data = json.data;

        // 1) Template rendern (Checkbox-Liste + ORIGINAL-Header aus Deinem PHP-/Underscore-Template)
        const tpl = wp.template('departments-editor');
        $container.html(tpl({
            hospital_id:  data.hospital_id,
            hospital_lat: data.hospital_lat,
            hospital_lon: data.hospital_lon,
            existing:     data.existing,
            departments:  data.allowed
        }));

        // 2) RICHTIG: tbody komplett leeren – ab hier befüllt bindDepartmentForm
        const $tbody = $container.find('#departments-details-table tbody').empty();

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
                center: ol.proj.fromLonLat([ data.hospital_lon, data.hospital_lat ]),
                zoom: 14
            })
        });

        // 4) Translate-Interaction EINMAL initialisieren (Marker verschiebbar machen)
        if (typeof initDeptTranslateInteraction === 'function') {
            initDeptTranslateInteraction();
        }

        // 5) Krankenhaus-Marker (unabhängig von Departments)
        const hospitalMarker = new ol.Feature({
            geometry: new ol.geom.Point(
                ol.proj.fromLonLat([ data.hospital_lon, data.hospital_lat ])
            ),
            type: 'hospital'
        });
        hospitalMarker.setStyle(new ol.style.Style({
            image: new ol.style.Icon({
                src:   lstHospitalsAjax.plugin_url + 'img/hospital_icon.png',
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

        // 7) bindDepartmentForm + Vorbelegung bestehender Einträge
        bindDepartmentForm({
            hospital_lat: data.hospital_lat,
            hospital_lon: data.hospital_lon,
            existing:     data.existing
        });
        data.existing.forEach(dep => {
            const code = (typeof dep === 'object') ? dep.code : dep;
            $container
                .find(`.dept-toggle[value="${code}"]`)
                .prop('checked', true)
                .trigger('change');
        });

        // 8) Save-Handler
        $container.find('#departments-edit-form')
            .off('submit')
            .on('submit', e => {
                e.preventDefault();
                const fd = new FormData(e.target);
                fetch(`${lstHospitalsAjax.ajax_url}?action=save_departments`, {
                    method:      'POST',
                    credentials: 'same-origin',
                    body:        fd
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



                    })
                    .catch(err => {
                        console.error('Fehler beim Laden der Krankenhäuser:', err);
                        alert('Fehler beim Laden der Krankenhäuser');
                    });
            }


            (function($) {

                /* 1) Krankenhaus-Bearbeiten (Stift-Icon) ----------------------------- */
                $(document).on('click', '.edit-krankenhaus', function(e) {
                    e.preventDefault();
                    openEditForm($(this).data('id'));
                });

                /* 2) Fachbereiche-Button in der Tabelle ------------------------------ */
                $(document).on('click', '.edit-departments', function(e) {
                    e.preventDefault();
                    openDepartmentEditor($(this).data('id'));
                });

                /* 3) Fachbereiche-Button IM Pop-up (#h-departments-button) ----------- */
                //  – wird erst gerendert, daher ebenfalls delegiert:
                $(document).on('click', '#h-departments-button', function(e) {
                    e.preventDefault();
                    openDepartmentEditor($(this).data('id'));
                });

                /* 4) Krankenhaus löschen --------------------------------------------- */
                $(document).on('click', '.delete-krankenhaus', function(e) {
                    e.preventDefault();
                    const id = $(this).data('id');
                    if (!confirm('Krankenhaus wirklich löschen?')) return;

                    $.ajax({
                            url: lstHospitalsAjax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'delete_krankenhaus',
                                id
                            },
                            xhrFields: {
                                withCredentials: true
                            }
                        })
                        .done(resp => {
                            if (!resp.success)
                                return alert(resp.data || 'Löschen fehlgeschlagen');
                            fetchHospitals(); // Tabelle + Marker neu laden
                        })
                        .fail((_, __, err) => {
                            console.error('Löschen-Fehler:', err);
                            alert('Löschen fehlgeschlagen: ' + err);
                        });
                });

            })(jQuery); //  ← No-Conflict-Alias sicher übergeben




            // 5. Neuer Eintrag
            jQuery(function($) { // runs when DOM is ready
                $('#btn-new-krankenhaus') // safe even if the element
                    .on('click', () => openEditForm(null)); // isn’t present yet
            });
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

            });

        })(jQuery);