/* global lstBoundaryAssistant */
(() => {
  const LEVELS = {
    de: [
      { value: 'gemeinde', label: 'Gemeinde' },
      { value: 'kreis', label: 'Kreis' },
      { value: 'bundesland', label: 'Bundesland' }
    ],
    at: [
      { value: 'gemeinde', label: 'Gemeinde' },
      { value: 'bezirk', label: 'Bezirk' },
      { value: 'bundesland', label: 'Bundesland' }
    ],
    ch: [
      { value: 'gemeinde', label: 'Gemeinde' },
      { value: 'bezirk', label: 'Bezirk' },
      { value: 'kanton', label: 'Kanton' }
    ]
  };

  const SOURCE_LINKS = {
    de: [
      {
        label: 'BKG VG250',
        url: 'https://gdz.bkg.bund.de/index.php/default/open-data/wfs-verwaltungsgebiete-1-250-000-stand-01-01-wfs-vg250.html'
      }
    ],
    at: [
      {
        label: 'Statistik Austria',
        url: 'https://data.statistik.gv.at/web/meta.jsp?dataset=OGDEXT_GEM_1'
      }
    ],
    ch: [
      {
        label: 'swisstopo swissBOUNDARIES3D',
        url: 'https://www.swisstopo.admin.ch/de/kantons-bezirks-gemeindegrenzen'
      }
    ],
    osm: [
      {
        label: 'OpenStreetMap',
        url: 'https://www.openstreetmap.org/copyright'
      }
    ]
  };

  function cfg() {
    return window.lstBoundaryAssistant || {};
  }

  function getPopup(el) {
    return el ? el.closest('.einsatzgebiet-popup') : null;
  }

  function getEls(root) {
    return {
      root,
      country: root.querySelector('[data-boundary-country]'),
      level: root.querySelector('[data-boundary-level]'),
      query: root.querySelector('[data-boundary-query]'),
      search: root.querySelector('[data-boundary-search]'),
      status: root.querySelector('[data-boundary-status]'),
      results: root.querySelector('[data-boundary-results]'),
      selected: root.querySelector('[data-boundary-selected]'),
      apply: root.querySelector('[data-boundary-apply]'),
      attribution: root.querySelector('[data-boundary-attribution]')
    };
  }

  function setStatus(root, text, isError) {
    const { status } = getEls(root);
    if (!status) return;
    status.textContent = text || '';
    status.classList.toggle('is-error', !!isError);
  }

  function setBusy(root, busy) {
    const { search, apply } = getEls(root);
    if (search) search.disabled = busy;
    if (apply) apply.disabled = busy || selectedIds(root).length === 0;
    root.classList.toggle('is-loading', !!busy);
  }

  function setSourceLinks(root, countryValue, fallback, attributionText) {
    const { attribution } = getEls(root);
    if (!attribution) return;

    const links = [...(SOURCE_LINKS[countryValue] || [])];
    if (fallback || (attributionText && attributionText.includes('OpenStreetMap'))) {
      links.push(...SOURCE_LINKS.osm);
    }

    attribution.textContent = 'Quelle: ';
    if (!links.length && attributionText) {
      attribution.appendChild(document.createTextNode(attributionText));
      return;
    }

    links.forEach((link, index) => {
      if (index > 0) attribution.appendChild(document.createTextNode(', '));
      const anchor = document.createElement('a');
      anchor.href = link.url;
      anchor.target = '_blank';
      anchor.rel = 'noopener noreferrer';
      anchor.textContent = link.label;
      attribution.appendChild(anchor);
    });

    if (attributionText) {
      attribution.appendChild(document.createTextNode(` (${attributionText})`));
    }
  }

  function getMap(root) {
    const popup = getPopup(root);
    const mapId = popup ? popup.dataset.mapId : '';
    return mapId && window._openlayersMaps ? window._openlayersMaps[mapId] : null;
  }

  function clearPreview(root) {
    const popup = getPopup(root);
    if (!popup || !popup._boundaryPreviewLayer) return;
    popup._boundaryPreviewLayer.getSource().clear();
  }

  function ensurePreviewLayer(root) {
    const popup = getPopup(root);
    const map = getMap(root);
    if (!popup || !map || typeof ol === 'undefined') return null;

    if (!popup._boundaryPreviewLayer) {
      popup._boundaryPreviewLayer = new ol.layer.Vector({
        source: new ol.source.Vector(),
        style: new ol.style.Style({
          stroke: new ol.style.Stroke({
            color: 'rgba(0, 92, 204, 0.95)',
            width: 3
          }),
          fill: new ol.style.Fill({
            color: 'rgba(0, 92, 204, 0.18)'
          })
        })
      });
      map.addLayer(popup._boundaryPreviewLayer);
    }

    return popup._boundaryPreviewLayer;
  }

  function showPreview(root, geojson) {
    const map = getMap(root);
    const layer = ensurePreviewLayer(root);
    if (!map || !layer || !geojson) return;

    const source = layer.getSource();
    const format = new ol.format.GeoJSON();
    let features = [];
    try {
      features = format.readFeatures(geojson, {
        dataProjection: 'EPSG:4326',
        featureProjection: map.getView().getProjection()
      });
    } catch (err) {
      setStatus(root, 'Vorschau konnte nicht gezeichnet werden.', true);
      return;
    }

    source.clear();
    source.addFeatures(features);
    if (features.length) {
      map.getView().fit(source.getExtent(), {
        padding: [40, 40, 40, 40],
        maxZoom: 12,
        duration: 180
      });
    }
  }

  function updateLevels(root) {
    const { country, level } = getEls(root);
    if (!country || !level) return;
    const options = LEVELS[country.value] || LEVELS.de;
    level.innerHTML = '';
    options.forEach((item) => {
      const opt = document.createElement('option');
      opt.value = item.value;
      opt.textContent = item.label;
      level.appendChild(opt);
    });
    setSourceLinks(root, country.value, false, '');
  }

  function selectedMap(root) {
    if (!root._boundarySelectedItems) {
      root._boundarySelectedItems = new Map();
    }
    return root._boundarySelectedItems;
  }

  function selectedIds(root) {
    return Array.from(selectedMap(root).keys());
  }

  function updateApply(root) {
    const { apply } = getEls(root);
    if (apply) apply.disabled = selectedIds(root).length === 0;
  }

  function renderSelected(root) {
    const { selected } = getEls(root);
    if (!selected) return;
    const items = Array.from(selectedMap(root).values());
    selected.innerHTML = '';

    if (!items.length) {
      selected.hidden = true;
      updateApply(root);
      return;
    }

    selected.hidden = false;
    const title = document.createElement('strong');
    title.textContent = `Ausgewählt (${items.length})`;
    selected.appendChild(title);

    const list = document.createElement('div');
    list.className = 'einsatzgebiet-boundary-selected__list';
    items.forEach((item) => {
      const chip = document.createElement('span');
      chip.className = 'einsatzgebiet-boundary-selected__item';

      const label = document.createElement('span');
      label.textContent = item.name || item.id;

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'button-link';
      remove.textContent = 'Entfernen';
      remove.setAttribute('data-boundary-remove', item.id);

      chip.appendChild(label);
      chip.appendChild(remove);
      list.appendChild(chip);
    });
    selected.appendChild(list);
    updateApply(root);
  }

  function syncResultChecks(root) {
    const selected = selectedMap(root);
    root.querySelectorAll('[data-boundary-item]').forEach((input) => {
      input.checked = selected.has(input.value);
    });
  }

  function renderResults(root, items, meta) {
    const { results } = getEls(root);
    if (!results) return;
    results.innerHTML = '';

    if (!items.length) {
      results.hidden = true;
      setStatus(root, 'Keine passenden Verwaltungsgrenzen gefunden. Bitte Schreibweise prüfen, z. B. "Innsbruck".', true);
      updateApply(root);
      return;
    }

    const list = document.createElement('div');
    list.className = 'einsatzgebiet-boundary-results__list';

    items.forEach((item, index) => {
      const id = `lst-boundary-${Date.now()}-${index}`;
      const row = document.createElement('label');
      row.className = 'einsatzgebiet-boundary-result';
      row.setAttribute('for', id);

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.id = id;
      checkbox.value = item.id;
      checkbox.dataset.boundaryName = item.name || 'Unbenannte Grenze';
      checkbox.dataset.boundarySubtitle = item.subtitle || item.attribution || '';
      checkbox.setAttribute('data-boundary-item', '');
      checkbox.checked = selectedMap(root).has(item.id);

      const text = document.createElement('span');
      text.className = 'einsatzgebiet-boundary-result__text';

      const name = document.createElement('strong');
      name.textContent = item.name || 'Unbenannte Grenze';

      const subtitle = document.createElement('span');
      subtitle.textContent = item.subtitle || item.attribution || '';

      text.appendChild(name);
      if (subtitle.textContent) text.appendChild(subtitle);
      row.appendChild(checkbox);
      row.appendChild(text);
      list.appendChild(row);
    });

    results.appendChild(list);
    results.hidden = false;

    const sourceText = meta && meta.fallback
      ? 'Treffer kommen aus dem OSM-Fallback. Bitte Geometrie kurz prüfen.'
      : 'Treffer kommen aus der offiziellen Quelle.';
    setStatus(root, `${items.length} Treffer. ${sourceText}`, !!(meta && meta.fallback));

    const country = root.querySelector('[data-boundary-country]');
    setSourceLinks(root, country ? country.value : 'de', !!(meta && meta.fallback), meta && meta.attribution ? meta.attribution : '');

    renderSelected(root);
    updateApply(root);
  }

  async function search(root) {
    const { country, level, query } = getEls(root);
    const q = query ? query.value.trim() : '';
    if (q.length < 2) {
      setStatus(root, 'Bitte mindestens zwei Zeichen eingeben.', true);
      return;
    }

    const popup = getPopup(root);
    const params = new URLSearchParams({
      action: 'lsttraining_boundary_search',
      country: country ? country.value : 'de',
      level: level ? level.value : 'gemeinde',
      q,
      context: popup && popup.dataset.context === 'neben' ? 'neben' : 'leitstelle'
    });

    setBusy(root, true);
    setStatus(root, 'Suche läuft ...', false);

    try {
      const response = await fetch(`${cfg().ajax_url}?${params.toString()}`, { credentials: 'same-origin' });
      const json = await response.json();
      if (!json || !json.success) {
        const message = json && json.data && json.data.message ? json.data.message : 'Suche fehlgeschlagen.';
        throw new Error(message);
      }
      renderResults(root, json.data.items || [], json.data);
    } catch (err) {
      renderResults(root, [], null);
      setStatus(root, err.message || 'Suche fehlgeschlagen.', true);
    } finally {
      setBusy(root, false);
    }
  }

  function writeToManualImport(root, geojson) {
    const popup = getPopup(root);
    if (!popup) return false;

    const manual = popup.querySelector('[data-eg-manual]');
    const file = popup.querySelector('[data-eg-file]');
    const process = popup.querySelector('[data-eg-process]');
    if (!manual || !process) {
      setStatus(root, 'GeoJSON-Importfeld im Popup nicht gefunden.', true);
      return false;
    }

    if (file) file.value = '';
    manual.value = JSON.stringify(geojson);
    manual.dispatchEvent(new Event('input', { bubbles: true }));
    process.click();
    return true;
  }

  async function applySelection(root) {
    const ids = selectedIds(root);
    if (!ids.length) {
      updateApply(root);
      return;
    }

    const popup = getPopup(root);
    const fd = new FormData();
    fd.append('action', 'lsttraining_boundary_fetch');
    fd.append('context', popup && popup.dataset.context === 'neben' ? 'neben' : 'leitstelle');
    ids.forEach((id) => fd.append('ids[]', id));

    setBusy(root, true);
    setStatus(root, 'Geometrien werden geladen ...', false);

    try {
      const response = await fetch(cfg().ajax_url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      const json = await response.json();
      if (!json || !json.success) {
        const message = json && json.data && json.data.message ? json.data.message : 'Geometrien konnten nicht geladen werden.';
        throw new Error(message);
      }
      if (!json.data.geojson || !Array.isArray(json.data.geojson.features) || !json.data.geojson.features.length) {
        throw new Error('Die Quelle hat keine Polygon-Geometrien geliefert.');
      }
      if (writeToManualImport(root, json.data.geojson)) {
        setStatus(root, `Auswahl übernommen. ${json.data.attribution || ''}`.trim(), false);
      }
    } catch (err) {
      setStatus(root, err.message || 'Übernahme fehlgeschlagen.', true);
    } finally {
      setBusy(root, false);
    }
  }

  async function previewSelection(root) {
    const ids = selectedIds(root);
    if (!ids.length) {
      clearPreview(root);
      updateApply(root);
      return;
    }

    const popup = getPopup(root);
    const fd = new FormData();
    fd.append('action', 'lsttraining_boundary_fetch');
    fd.append('context', popup && popup.dataset.context === 'neben' ? 'neben' : 'leitstelle');
    ids.forEach((id) => fd.append('ids[]', id));

    setStatus(root, 'Vorschau wird geladen ...', false);

    try {
      const response = await fetch(cfg().ajax_url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      const json = await response.json();
      if (!json || !json.success || !json.data || !json.data.geojson) {
        throw new Error('Vorschau konnte nicht geladen werden.');
      }
      showPreview(root, json.data.geojson);
      setStatus(root, `${ids.length} Gebiet(e) ausgewählt. Vorschau in Blau.`, false);
    } catch (err) {
      clearPreview(root);
      setStatus(root, err.message || 'Vorschau konnte nicht geladen werden.', true);
    }
  }

  function bind(root) {
    if (!root || root._lstBoundaryAssistantBound) return;
    root._lstBoundaryAssistantBound = true;
    updateLevels(root);

    const { country, query } = getEls(root);
    if (country) {
      country.addEventListener('change', () => {
        updateLevels(root);
        renderResults(root, [], null);
        setStatus(root, '', false);
      });
    }
    if (query) {
      query.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          search(root);
        }
      });
    }
    root.addEventListener('change', (event) => {
      if (event.target && event.target.matches('[data-boundary-item]')) {
        const selections = selectedMap(root);
        if (event.target.checked) {
          selections.set(event.target.value, {
            id: event.target.value,
            name: event.target.dataset.boundaryName || event.target.value,
            subtitle: event.target.dataset.boundarySubtitle || ''
          });
        } else {
          selections.delete(event.target.value);
        }
        renderSelected(root);
        updateApply(root);
        previewSelection(root);
      }
    });
    root.addEventListener('click', (event) => {
      const removeBtn = event.target.closest('[data-boundary-remove]');
      if (!removeBtn) return;
      selectedMap(root).delete(removeBtn.getAttribute('data-boundary-remove'));
      syncResultChecks(root);
      renderSelected(root);
      previewSelection(root);
    });
  }

  function initAll() {
    document.querySelectorAll('[data-boundary-assistant]').forEach(bind);
  }

  window.initBoundaryAssistant = initAll;
  window.lstBoundaryAssistantRun = {
    init: initAll,
    searchFrom: (el) => {
      const root = el ? el.closest('[data-boundary-assistant]') : null;
      if (!root) return;
      bind(root);
      search(root);
    },
    applyFrom: (el) => {
      const root = el ? el.closest('[data-boundary-assistant]') : null;
      if (!root) return;
      bind(root);
      applySelection(root);
    }
  };

  document.addEventListener('DOMContentLoaded', initAll);
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    const query = event.target && event.target.closest ? event.target.closest('[data-boundary-query]') : null;
    if (!query) return;
    const root = query.closest('[data-boundary-assistant]');
    if (!root) return;
    event.preventDefault();
    event.stopPropagation();
    bind(root);
    search(root);
  }, true);

  document.addEventListener('click', (event) => {
    if (event.target.closest('.open-einsatzgebiet-editor')) {
      window.setTimeout(initAll, 0);
    }
    const searchBtn = event.target.closest('[data-boundary-search]');
    if (searchBtn) {
      const root = searchBtn.closest('[data-boundary-assistant]');
      if (root) {
        bind(root);
        search(root);
      }
    }
    const applyBtn = event.target.closest('[data-boundary-apply]');
    if (applyBtn) {
      const root = applyBtn.closest('[data-boundary-assistant]');
      if (root) {
        bind(root);
        applySelection(root);
      }
    }
  });
})();
