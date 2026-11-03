<?php
// wird via lsttraining_render_zuordnung_modal_page() inkludiert
?>
<div class="wrap" style="padding:16px;">
  <h1>Zuordnung der Wachen bearbeiten</h1>
  <p>
    Entity: <strong><?php echo esc_html( strtoupper(LSTZUO['entityType']) ); ?></strong> ·
    ID: <strong><?php echo intval(LSTZUO['entityId']); ?></strong>
  </p>

  <div id="zuo-map" style="width:100%; height:460px; border:1px solid #ddd; margin-bottom:12px;"></div>

  <div style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
    <button type="button" class="button button-primary" id="btn-assign-all">Wachen im Einsatzgebiet zuordnen</button>
    <button type="button" class="button" id="btn-unassign-all">Alle Wachen aus dem Einsatzgebiet löschen</button>
    <span id="zuo-status" style="margin-left:auto;"></span>
  </div>

  <table class="widefat fixed">
    <thead>
      <tr>
        <th style="width:60px;">ID</th>
        <th>Name</th>
        <th style="width:160px;">Typ</th>
        <th style="width:220px;">Koordinaten</th>
        <th style="width:120px;">Status</th>
      </tr>
    </thead>
    <tbody id="zuo-tbody">
      <tr><td colspan="5"><em>Lade Einsatzgebiet…</em></td></tr>
    </tbody>
  </table>
</div>
