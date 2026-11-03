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
    <table class="widefat striped"><thead><tr><th>Code</th><th>Bezeichnung</th><th>Beschreibung</th><th>Bereich</th><th>Fahrzeuge</th><th>Status</th><th>Aktionen</th></tr></thead><tbody id="lst-stichwort-rows"><tr><td colspan="7">Wird geladen ...</td></tr></tbody></table>
    <div id="lst-stichwort-form-wrap" class="lst-stichwort-modal" hidden aria-hidden="true"><div class="lst-stichwort-modal__backdrop" data-stichwort-close></div><section class="lst-stichwort-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lst-stichwort-form-title">
        <header class="lst-stichwort-modal__header"><h2 id="lst-stichwort-form-title">Stichwort</h2><button type="button" class="button lst-stichwort-modal__close" data-stichwort-close aria-label="Schließen"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <form id="lst-stichwort-form">
            <input type="hidden" name="id" value="">
            <p><label>Code<br><input class="regular-text" name="code" required maxlength="100"></label></p>
            <p><label>Bezeichnung<br><input class="regular-text" name="bezeichnung" required maxlength="255"></label></p>
            <p><label>Beschreibung<br><textarea class="large-text" name="beschreibung" rows="3" required></textarea></label></p>
            <p><label>Bereich<br><select name="einsatzart"><option value="RD">Rettungsdienst</option><option value="FW">Feuerwehr / THL</option></select></label></p>
            <p><label>Hinweis<br><textarea class="large-text" name="hinweis" rows="3"></textarea></label></p>
            <p><label><input type="checkbox" name="aktiv" checked> Aktiv</label></p>
            <h3>Fahrzeugvorauswahl</h3><div id="lst-stichwort-resources"></div>
            <p><button type="button" class="button" id="lst-stichwort-add-resource">Fahrzeugklasse hinzufügen</button></p>
            <p><button class="button button-primary" type="submit">Speichern</button> <button class="button" type="button" id="lst-stichwort-cancel">Abbrechen</button></p>
        </form>
    </section></div>
</div>
<style>
.lst-stichwort-modal[hidden]{display:none}.lst-stichwort-modal{position:fixed;inset:0;z-index:100100;display:grid;place-items:center;padding:24px}.lst-stichwort-modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.62)}.lst-stichwort-modal__dialog{position:relative;z-index:1;background:#fff;width:min(760px,96vw);max-height:90vh;overflow:auto;padding:24px 28px;border-radius:8px;box-shadow:0 20px 70px rgba(0,0,0,.3)}.lst-stichwort-modal__header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}.lst-stichwort-modal__header h2{margin:0}.lst-stichwort-modal__close{min-width:36px}.lst-stichwort-row-actions{display:flex;gap:6px}.lst-stichwort-row-actions .button{min-width:34px;text-align:center}.lst-stichwort-resource{display:flex;gap:8px;margin:8px 0;align-items:center}
</style>
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
      $('#lst-stichwort-rows').innerHTML = items.map(row => '<tr><td>'+e(row.code)+'</td><td>'+e(row.bezeichnung)+'</td><td>'+e(row.beschreibung || '')+'</td><td>'+e(row.einsatzart)+'</td><td>'+e((row.fahrzeuge || []).map(r => r.count+'× '+(classes[r.type] || r.type)).join(', '))+'</td><td>'+(Number(row.aktiv) ? 'Aktiv':'Inaktiv')+'</td><td><span class="lst-stichwort-row-actions"><button type="button" class="button lst-stichwort-edit" data-id="'+e(row.id)+'" title="Bearbeiten" aria-label="Bearbeiten"><i class="fa-solid fa-pen" aria-hidden="true"></i></button> <button type="button" class="button button-link-delete lst-stichwort-delete" data-id="'+e(row.id)+'" title="Löschen" aria-label="Löschen"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></span></td></tr>').join('') || '<tr><td colspan="7">Keine Stichwörter vorhanden.</td></tr>';
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
    const modal = $('#lst-stichwort-form-wrap'); modal.hidden = false; modal.setAttribute('aria-hidden','false'); document.body.classList.add('lst-stichwort-modal-open'); f.elements.code.focus();
  }
  $('#lst-stichwort-leitstelle').addEventListener('change', () => {history.replaceState(null,'','?page=lsttraining_stichwoerter&ls_id='+ls()); load();});
  $('#lst-stichwort-new').addEventListener('click', () => edit());
  function closeModal() { const modal = $('#lst-stichwort-form-wrap'); modal.hidden = true; modal.setAttribute('aria-hidden','true'); document.body.classList.remove('lst-stichwort-modal-open'); }
  $('#lst-stichwort-cancel').addEventListener('click', closeModal);
  $('#lst-stichwort-form-wrap').addEventListener('click', event => { if (event.target.matches('[data-stichwort-close]')) closeModal(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !$('#lst-stichwort-form-wrap').hidden) closeModal(); });
  $('#lst-stichwort-add-resource').addEventListener('click', () => resource());
  $('#lst-stichwort-resources').addEventListener('click', event => { if (event.target.classList.contains('lst-stichwort-remove')) event.target.closest('.lst-stichwort-resource').remove(); });
  $('#lst-stichwort-rows').addEventListener('click', async event => {
    const button = event.target.closest('button[data-id]'); if (!button) return;
    const id = Number(button.dataset.id || 0); if (!id) return;
    if (button.classList.contains('lst-stichwort-edit')) edit(items.find(row => Number(row.id) === id));
    if (button.classList.contains('lst-stichwort-delete') && confirm('Stichwort wirklich löschen?')) {
      try { await request(url(id)+'?confirm=true','DELETE'); await load(); } catch (error) { message(error.message); }
    }
  });
  $('#lst-stichwort-form').addEventListener('submit', async event => {
    event.preventDefault(); const f = event.target; const id = Number(f.elements.id.value || 0);
    const fahrzeuge = [...document.querySelectorAll('.lst-stichwort-resource')].map(node => ({type:node.querySelector('.lst-stichwort-type').value,count:Number(node.querySelector('.lst-stichwort-count').value)}));
    try { await request(url(id),id ? 'PATCH':'POST',{code:f.elements.code.value.trim(),bezeichnung:f.elements.bezeichnung.value.trim(),einsatzart:f.elements.einsatzart.value,beschreibung:f.elements.beschreibung.value.trim(),hinweis:f.elements.hinweis.value.trim(),aktiv:f.elements.aktiv.checked,fahrzeuge}); closeModal(); await load(); }
    catch (error) { message(error.message); }
  });
  load();
})();
</script>
