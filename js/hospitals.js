
let map = new ol.Map({
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

let vectorSource = new ol.source.Vector();
let vectorLayer = new ol.layer.Vector({ source: vectorSource });
map.addLayer(vectorLayer);

fetchHospitals();

function fetchHospitals() {
  fetch(lstHospitalsAjax.ajax_url + '?action=get_krankenhaeuser')
    .then(res => res.json())
    .then(data => {
      console.log("[krankenhaeuser]", data);
      vectorSource.clear();
      const tbody = document.querySelector("#krankenhaus-map").closest(".wrap").querySelector("tbody");
      tbody.innerHTML = "";

      data.forEach(kh => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${kh.id}</td>
          <td>${kh.name}</td>
          <td>${kh.versorgungsstufe}</td>
          <td>${kh.trauma_level}</td>
          <td>${kh.latitude}, ${kh.longitude}</td>
          <td>
            <button class="button edit-krankenhaus" data-id="${kh.id}">Bearbeiten</button>
            <a href="admin.php?page=lsttraining_krankenhaeuser&delete_id=${kh.id}"
               class="button button-link-delete"
               onclick="return confirm('Krankenhaus wirklich löschen?');">Löschen</a>
          </td>`;
        tbody.appendChild(tr);

        const lat = parseFloat(kh.latitude);
        const lon = parseFloat(kh.longitude);

        if (!isNaN(lat) && !isNaN(lon)) {
          const point = new ol.geom.Point(ol.proj.fromLonLat([lon, lat]));
          const feature = new ol.Feature({ geometry: point, name: kh.name });
          vectorSource.addFeature(feature);
          
        } else {
          console.warn("Ungültige Koordinaten:", kh.name, kh.latitude, kh.longitude);
        }
      });

      if (vectorSource.getFeatures().length > 0) {
        map.getView().fit(vectorSource.getExtent(), { padding: [20, 20, 20, 20], maxZoom: 12 });
      }
    });
}



function setupEditButtons() {
  document.querySelectorAll('.edit-krankenhaus').forEach(button => {
    button.addEventListener('click', event => {
      const id = event.target.getAttribute('data-id');
      openEditForm(id);
    });
  });
}

document.getElementById('btn-new-krankenhaus')?.addEventListener('click', () => {
  openEditForm(null);
});

function openEditForm(id) {
  const modal = document.getElementById("krankenhaus-edit-modal");
  const content = modal.querySelector(".edit-content");

  if (id) {
    fetch("ajax-handlers.php?action=get_krankenhaus&id=" + id)
      .then(res => res.json())
      .then(data => renderForm(data));
  } else {
    renderForm({
      id: "",
      name: "",
      versorgungsstufe: "Grundversorgung",
      trauma_level: 0,
      latitude: 51.2,
      longitude: 10.5
    });
  }

  function renderForm(data) {
    content.innerHTML = `
      <form id="krankenhaus-edit-form">
        <input type="hidden" name="id" value="${data.id}">
        <p><label>Name:<br><input name="name" value="${data.name}" required></label></p>
        <p><label>Versorgungsstufe:<br>
          <select name="versorgungsstufe">
            <option value="Grundversorgung" ${data.versorgungsstufe === "Grundversorgung" ? "selected" : ""}>Grundversorgung</option>
            <option value="Schwerpunktversorger" ${data.versorgungsstufe === "Schwerpunktversorger" ? "selected" : ""}>Schwerpunktversorger</option>
            <option value="Maximalversorger" ${data.versorgungsstufe === "Maximalversorger" ? "selected" : ""}>Maximalversorger</option>
          </select></label></p>
        <p><label>Trauma-Level:<br><input name="trauma_level" type="number" min="0" max="3" value="${data.trauma_level}"></label></p>
        <p><label>Latitude:<br><input name="latitude" value="${data.latitude}" required></label></p>
        <p><label>Longitude:<br><input name="longitude" value="${data.longitude}" required></label></p>
        <p>
          <button type="submit" class="button button-primary">Speichern</button>
          <button type="button" class="button" id="cancel-edit">Abbrechen</button>
        </p>
      </form>
    `;
    modal.classList.remove("hidden");

    content.querySelector("#cancel-edit").addEventListener("click", () => modal.classList.add("hidden"));

    content.querySelector("#krankenhaus-edit-form").addEventListener("submit", e => {
      e.preventDefault();
      const formData = new FormData(e.target);
      fetch("ajax-handlers.php?action=save_krankenhaus", {
        method: "POST",
        body: formData
      }).then(() => {
        modal.classList.add("hidden");
        fetchHospitals();
      });
    });
  }
}
