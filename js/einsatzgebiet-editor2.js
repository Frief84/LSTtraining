/**
 * Lightweight namespace for the Einsatzgebiet editor.
 * Exposes:
 *  - Einsatzgebiet.init(container: HTMLElement): void
 *  - Einsatzgebiet.open(): void
 */
(function () {
  /** @namespace */
  const NS = (window.Einsatzgebiet = window.Einsatzgebiet || {});

  /**
   * Map instances keyed by mapId (from data-map-id).
   * @type {Record<string, ol.Map>}
   */
  NS.maps = NS.maps || {};

  /**
   * Vector sources keyed by mapId (from data-map-id).
   * @type {Record<string, ol.source.Vector>}
   */
  NS.sources = NS.sources || {};

  /**
   * Derives the initial view center for the map from container.dataset.center.
   * Expects "lat,lon" (WGS84). Falls back to [13.4, 52.5] (Berlin area).
   * @param {HTMLElement} container
   * @returns {ol.Coordinate} A coordinate in map projection (WebMercator).
   */
  function readCenter(container) {
    const def = [13.4, 52.5];
    const s = container.dataset.center || "";
    if (!s.includes(",")) return ol.proj.fromLonLat(def);
    const [lat, lon] = s.split(",").map(parseFloat);
    if (isNaN(lat) || isNaN(lon)) return ol.proj.fromLonLat(def);
    return ol.proj.fromLonLat([lon, lat]);
  }

  /**
   * Parses a GeoJSON string into a normalized FeatureCollection.
   * Accepts: FeatureCollection | Feature | Feature[].
   * Removes legacy "crs" if present.
   * Returns null on invalid/empty input.
   * @param {string} raw
   * @returns {import("geojson").FeatureCollection|null}
   */
  function parseGeo(raw) {
    if (!raw) return null;
    const txt = raw.trim();
    if (!txt || txt === "[]") return null;
    try {
      let json = JSON.parse(txt);
      if (Array.isArray(json)) {
        json = { type: "FeatureCollection", features: json };
      } else if (json.type === "Feature") {
        json = { type: "FeatureCollection", features: [json] };
      }
      if (json?.type === "FeatureCollection" && json.crs) delete json.crs;
      return json;
    } catch {
      return null;
    }
  }

  /**
   * Beautifies a JSON string if possible. Returns the original string on failure.
   * @param {string} json
   * @returns {string}
   */
  function pretty(json) {
    try {
      return JSON.stringify(JSON.parse(json), null, 2);
    } catch {
      return json;
    }
  }

  /**
   * Adds a simple red circle marker at the dataset.center position (if valid).
   * @param {ol.Map} map
   * @param {HTMLElement} container
   * @returns {void}
   */
  function addCenterMarker(map, container) {
    const s = container.dataset.center || "";
    if (!s.includes(",")) return;
    const [lat, lon] = s.split(",").map(parseFloat);
    if (isNaN(lat) || isNaN(lon)) return;
    const pt = ol.proj.fromLonLat([lon, lat]);
    const f = new ol.Feature({ geometry: new ol.geom.Point(pt) });
    f.setStyle(
      new ol.style.Style({
        image: new ol.style.Circle({
          radius: 6,
          fill: new ol.style.Fill({ color: "red" }),
          stroke: new ol.style.Stroke({ color: "#fff", width: 2 }),
        }),
      })
    );
    map.addLayer(
      new ol.layer.Vector({ source: new ol.source.Vector({ features: [f] }) })
    );
  }

  /**
   * Initializes the editor UI and OpenLayers map inside the given container.
   * Reuses an existing map if already initialized for the same mapId.
   *
   * Required data attributes on the container element:
   * - data-map-id:       string (DOM id of the map target)
   * - data-geojson-id:   string (DOM id of the hidden/textarea field)
   * - data-leitstelle-id:string (numeric string; "0" indicates create-flow for Nebenstelle)
   * - data-center:       optional "lat,lon" (WGS84)
   * - data-context:      "leitstelle" | "neben"
   *
   * Expected child controls inside the container:
   * - .btn-einsatzgebiet-delete
   * - .btn-einsatzgebiet-save
   * - .btn-einsatzgebiet-close
   * - #manual_geojson     (optional pretty-printed mirror)
   *
   * Side effects / integrations:
   * - Uses global `ajaxurl` (WordPress admin-ajax endpoint) for saving.
   * - Calls optional `window.updateNebenstellenMapFromGeo(rawGeoJson)` after save.
   *
   * @param {HTMLElement} container
   * @returns {void}
   */
  NS.init = function (container) {
    const mapId = container.dataset.mapId;
    const geojsonId = container.dataset.geojsonId;
    const leitstelleId = container.dataset.leitstelleId;
    const geojsonTextarea = document.getElementById(geojsonId);
    const manualTextarea = container.querySelector("#manual_geojson");
    const btnDelete = container.querySelector(".btn-einsatzgebiet-delete");
    const btnSave = container.querySelector(".btn-einsatzgebiet-save");
    const btnClose = container.querySelector(".btn-einsatzgebiet-close");

    if (NS.maps[mapId]) {
      requestAnimationFrame(() => {
        container.style.display = "block";
        NS.maps[mapId].updateSize();
      });
      return;
    }

    const format = new ol.format.GeoJSON();
    const vectorSource = new ol.source.Vector();
    const vectorLayer = new ol.layer.Vector({ source: vectorSource });

    NS.sources[mapId] = vectorSource;

    const map = new ol.Map({
      target: mapId,
      layers: [new ol.layer.Tile({ source: new ol.source.OSM() }), vectorLayer],
      view: new ol.View({ center: readCenter(container), zoom: 10 }),
    });

    addCenterMarker(map, container);
    NS.maps[mapId] = map;

    map.addInteraction(new ol.interaction.Draw({ source: vectorSource, type: "Polygon" }));
    map.addInteraction(new ol.interaction.Modify({ source: vectorSource }));

    /**
     * Serializes current features into GeoJSON and syncs UI fields.
     * Writes compact JSON into the hidden field and pretty JSON into the manual textarea (if present).
     * @returns {void}
     */
    function syncFields() {
      const feats = vectorSource.getFeatures();
      const geo = format.writeFeatures(feats, {
        dataProjection: "EPSG:4326",
        featureProjection: map.getView().getProjection(),
      });
      geojsonTextarea.value = geo;
      if (manualTextarea) manualTextarea.value = pretty(geo);
    }

    map.on("drawend", syncFields);
    map.on("modifyend", syncFields);

    const existing = parseGeo(geojsonTextarea?.value || "");
    if (existing?.type === "FeatureCollection" && existing.features.length) {
      const feats = format.readFeatures(existing, {
        dataProjection: "EPSG:4326",
        featureProjection: map.getView().getProjection(),
      });
      vectorSource.clear();
      vectorSource.addFeatures(feats);
      if (btnDelete) btnDelete.style.display = "inline-block";
      requestAnimationFrame(() => {
        const ext = vectorSource.getExtent();
        if (!ol.extent.isEmpty(ext)) {
          map.getView().fit(ext, { padding: [50, 50, 50, 50], duration: 200, maxZoom: 8 });
        }
      });
    }

    /**
     * Right-click context action:
     * - If polygon has > 3 vertices: remove the last user vertex (keeps closing vertex intact).
     * - Else: clear the geometry.
     */
    map.getViewport().addEventListener("contextmenu", (e) => {
      e.preventDefault();
      const feats = vectorSource.getFeatures();
      if (!feats.length) return;
      const geom = feats[0].getGeometry();
      if (!(geom instanceof ol.geom.Polygon)) return;
      const ring = geom.getCoordinates()[0];
      if (ring.length <= 4) {
        vectorSource.clear();
        if (btnDelete) btnDelete.style.display = "none";
      } else {
        ring.splice(-2, 1);
        geom.setCoordinates([ring]);
      }
      syncFields();
    });

    btnSave?.addEventListener("click", () => {
      syncFields();
      const raw = geojsonTextarea.value;

      if (typeof window.updateNebenstellenMapFromGeo === "function") {
        window.updateNebenstellenMapFromGeo(raw);
      }

      const ctx = container.dataset.context === "neben" ? "neben" : "leitstelle";

      if (ctx === "neben" && container.dataset.leitstelleId === "0") {
        container.style.display = "none";
        return;
      }

      const action =
        ctx === "neben" ? "lsttraining_save_neben_einsatzgebiet" : "lsttraining_save_einsatzgebiet";

      const body = new URLSearchParams({
        action,
        geojson: raw,
        [ctx === "neben" ? "neben_id" : "leitstelle_id"]: leitstelleId,
      });

      fetch(ajaxurl, { method: "POST", body })
        .then((r) => r.json())
        .then((res) => {
          if (!res?.success) {
            alert("Fehler: " + (res?.data || "Unbekannt"));
            return;
          }
          container.style.display = "none";
          alert("Einsatzgebiet gespeichert");
          if (typeof window.updateNebenstellenMapFromGeo === "function") {
            window.updateNebenstellenMapFromGeo(raw);
          }
        })
        .catch(() => alert("Netzwerkfehler beim Speichern."));
    });

    btnDelete?.addEventListener("click", () => {
      vectorSource.clear();
      syncFields();
      btnDelete.style.display = "none";
    });

    btnClose?.addEventListener("click", () => {
      container.style.display = "none";
    });

    container.style.display = "block";
  };

  /**
   * Opens the popup editor.
   * Looks for `.einsatzgebiet-popup`, initializes if needed, shows it, and resizes the map.
   * @returns {void}
   */
  NS.open = function () {
    const container = document.querySelector(".einsatzgebiet-popup");
    if (!container) {
      alert("Einsatzgebiet-Editor nicht gefunden.");
      return;
    }
    NS.init(container);
    container.style.display = "block";
    const mapId = container.dataset.mapId;
    const map = NS.maps[mapId];
    if (map) requestAnimationFrame(() => map.updateSize());
  };
})();
