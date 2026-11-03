<?php
if (!defined('ABSPATH')) { exit; }
$pdo = lsttraining_get_connection();
$leitstellen = $pdo ? $pdo->query('SELECT id,name FROM leitstellen ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];
$leitstellen = array_values(array_filter($leitstellen, static fn($row) => lsttraining_user_can('leitstellen', (int) $row['id'])));
$selected = absint($_GET['ls_id'] ?? ($leitstellen[0]['id'] ?? 0));
if ($selected && !lsttraining_user_can('leitstellen', $selected)) { wp_die('Keine Berechtigung.', 403); }
$classes = function_exists('lsttraining_sim_resource_class_labels') ? lsttraining_sim_resource_class_labels() : [];
?>
<div class="wrap lst-stichwoerter-page">
    <h1>Einsatzstichwörter</h1>
    <p>Jede Leitstelle hat eine eigene bearbeitbare Liste. Die historischen Brandenburg-Stichwörter wurden als Startliste übernommen.</p>
    <label for="lst-stichwort-leitstelle"><strong>Leitstelle</strong></label>
    <select id="lst-stichwort-leitstelle">
        <?php foreach ($leitstellen as $leitstelle): ?>
            <option value="<?php echo esc_attr($leitstelle['id']); ?>" <?php selected($selected, (int) $leitstelle['id']); ?>><?php echo esc_html($leitstelle['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="button button-primary" id="lst-stichwort-new">Stichwort hinzufügen</button>
    <p id="lst-stichwort-message" role="status"></p>
    <table class="widefat striped"><thead><tr><th>Code</th><th>Bezeichnung</th><th>Bereich</th><th>Fahrzeuge</th><th>Status</th><th>Aktionen</th></tr></thead><tbody id="lst-stichwort-rows"><tr><td colspan="6">Wird geladen ...</td></tr></tbody></table>
    <div id="lst-stichwort-form-wrap" hidden>
        <h2 id="lst-stichwort-form-title">Stichwort</h2>
        <form id="lst-stichwort-form">
            <input type="hidden" name="id" value="">
            <p><label>Code<br><input class="regular-text" name="code" required maxlength="100"></label></p>
            <p><label>Bezeichnung<br><input class="regular-text" name="bezeichnung" required maxlength="255"></label></p>
            <p><label>Beschreibung<br><textarea class="large-text" name="beschreibung" rows="3"></textarea></label></p>
            <p><label>Bereich<br><select name="einsatzart"><option value="RD">Rettungsdienst</option><option value="FW">Feuerwehr / THL</option></select></label></p>
            <p><label>Hinweis<br><textarea class="large-text" name="hinweis" rows="3"></textarea></label></p>
            <p><label><input type="checkbox" name="aktiv" checked> Aktiv</label></p>
            <h3>Fahrzeugvorauswahl</h3><div id="lst-stichwort-resources"></div>
            <p><button type="button" class="button" id="lst-stichwort-add-resource">Fahrzeugklasse hinzufügen</button></p>
            <p><button class="button button-primary" type="submit">Speichern</button> <button class="button" type="button" id="lst-stichwort-cancel">Abbrechen</button></p>
        </form>
    </div>
</div>
<script>
(function () {
  const base = <?php echo wp_json_encode(rest_url('lst/v1/verwaltung/leitstellen/')); ?>;
  const nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
  const classes = <?php echo wp_json_encode($classes); ?>;
  const $ = (selector) => document.querySelector(selector);
  const e = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  let items = [];
  const ls = () => Number($('#lst-stichwort-leitstelle').value || 0);
  const url = (id = '') => base + ls() + '/einsatzstichwoerter' + (id ? '/' + id : '');
  function message(value) { $('#lst-stichwort-message').textContent = value; }
  async function request(path, method = 'GET', body) {
    const response = await fetch(path, {method, credentials:'same-origin', headers:{'X-WP-Nonce':nonce, ...(body ? {'Content-Type':'application/json'} : {})}, body:body ? JSON.stringify(body) : undefined});
    const result = await response.json();
    if (!response.ok || !result.ok) throw Error(result.message || 'Anfrage fehlgeschlagen.');
    return result.data;
  }
  async function load() {
    if (!ls()) return;
    try {
      items = await request(url());
      $('#lst-stichwort-rows').innerHTML = items.map(row => '<tr><td>'+e(row.code)+'</td><td>'+e(row.bezeichnung)+'</td><td>'+e(row.einsatzart)+'</td><td>'+e((row.fahrzeuge || []).map(r => r.count+'× '+(classes[r.type] || r.type)).join(', '))+'</td><td>'+(Number(row.aktiv) ? 'Aktiv':'Inaktiv')+'</td><td><button type="button" class="button lst-stichwort-edit" data-id="'+e(row.id)+'">Bearbeiten</button> <button type="button" class="button-link-delete lst-stichwort-delete" data-id="'+e(row.id)+'">Löschen</button></td></tr>').join('') || '<tr><td colspan="6">Keine Stichwörter vorhanden.</td></tr>';
      message(items.length + ' Stichwörter geladen.');
    } catch (error) { message(error.message); }
  }
  function resource(row = {}) {
    const options = Object.keys(classes).map(type => '<option value="'+e(type)+'"'+(type === row.type ? ' selected':'')+'>'+e(classes[type])+'</option>').join('');
    $('#lst-stichwort-resources').insertAdjacentHTML('beforeend','<div class="lst-stichwort-resource"><select class="lst-stichwort-type">'+options+'</select> <input class="small-text lst-stichwort-count" type="number" min="1" max="100" value="'+e(row.count || 1)+'"> <button type="button" class="button-link-delete lst-stichwort-remove">Entfernen</button></div>');
  }
  function edit(row = {}) {
    const f = $('#lst-stichwort-form'); f.reset(); f.elements.id.value = row.id || '';
    for (const key of ['code','bezeichnung','beschreibung','einsatzart','hinweis']) if (row[key] !== undefined) f.elements[key].value = row[key] || '';
    f.elements.aktiv.checked = row.aktiv === undefined || Number(row.aktiv) === 1;
    $('#lst-stichwort-resources').innerHTML = ''; (row.fahrzeuge || []).forEach(resource);
    $('#lst-stichwort-form-title').textContent = row.id ? 'Stichwort bearbeiten' : 'Stichwort hinzufügen';
    $('#lst-stichwort-form-wrap').hidden = false; $('#lst-stichwort-form-wrap').scrollIntoView({behavior:'smooth'});
  }
  $('#lst-stichwort-leitstelle').addEventListener('change', () => {history.replaceState(null,'','?page=lsttraining_stichwoerter&ls_id='+ls()); load();});
  $('#lst-stichwort-new').addEventListener('click', () => edit());
  $('#lst-stichwort-cancel').addEventListener('click', () => $('#lst-stichwort-form-wrap').hidden = true);
  $('#lst-stichwort-add-resource').addEventListener('click', () => resource());
  $('#lst-stichwort-resources').addEventListener('click', event => { if (event.target.classList.contains('lst-stichwort-remove')) event.target.closest('.lst-stichwort-resource').remove(); });
  $('#lst-stichwort-rows').addEventListener('click', async event => {
    const id = Number(event.target.dataset.id || 0); if (!id) return;
    if (event.target.classList.contains('lst-stichwort-edit')) edit(items.find(row => Number(row.id) === id));
    if (event.target.classList.contains('lst-stichwort-delete') && confirm('Stichwort wirklich löschen?')) {
      try { await request(url(id)+'?confirm=true','DELETE'); await load(); } catch (error) { message(error.message); }
    }
  });
  $('#lst-stichwort-form').addEventListener('submit', async event => {
    event.preventDefault(); const f = event.target; const id = Number(f.elements.id.value || 0);
    const fahrzeuge = [...document.querySelectorAll('.lst-stichwort-resource')].map(node => ({type:node.querySelector('.lst-stichwort-type').value,count:Number(node.querySelector('.lst-stichwort-count').value)}));
    try { await request(url(id),id ? 'PATCH':'POST',{code:f.elements.code.value.trim(),bezeichnung:f.elements.bezeichnung.value.trim(),einsatzart:f.elements.einsatzart.value,beschreibung:f.elements.beschreibung.value.trim(),hinweis:f.elements.hinweis.value.trim(),aktiv:f.elements.aktiv.checked,fahrzeuge}); $('#lst-stichwort-form-wrap').hidden = true; await load(); }
    catch (error) { message(error.message); }
  });
  load();
})();
</script>
