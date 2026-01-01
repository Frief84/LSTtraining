<?php
// Leitstellen Utilities (Copy Leitstelle → Nebenstelle)
/* -------------------------------------------------------------------------
 * 8. COPY LEITSTELLE -> NEBENSTELLE (Pivot + Geo)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_lsttraining_copy_leitstelle', 'lsttraining_ajax_copy_leitstelle');
function lsttraining_ajax_copy_leitstelle() {
    // Guard
    lsttraining_ajax_guard([
        'area' => 'nebenstellen',
        'nonce_action' => 'lsttraining_copy_leitstelle',
        'nonce_field' => '_wpnonce',
    ]);
$neben_id = filter_input(INPUT_POST, 'neben_id', FILTER_VALIDATE_INT);
    $leit_id  = filter_input(INPUT_POST, 'leit_id', FILTER_VALIDATE_INT);
    if (!$neben_id || !$leit_id) {
        wp_send_json_error('Ungültige IDs', 400);
    }

    try {
        $pdo = lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            throw new Exception('DB-Verbindung fehlgeschlagen');
        }

        $insert = $pdo->prepare('
            INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id)
            SELECT wl.wache_id, :nid
              FROM wache_leitstellen AS wl
             WHERE wl.leitstelle_id = :lid
               AND wl.wache_id NOT IN (
                   SELECT wache_id
                     FROM wache_nebenleitstellen
                    WHERE nebenleitstelle_id = :nid
               )
        ');
        $insert->execute([':nid' => (int)$neben_id, ':lid' => (int)$leit_id]);

        $stmt = $pdo->prepare('
            SELECT latitude, longitude, geojson
              FROM leitstellen
             WHERE id = :lid
             LIMIT 1
        ');
        $stmt->execute([':lid' => (int)$leit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Leitstelle nicht gefunden (ID ' . $leit_id . ')');
        }

        $stmtChk = $pdo->prepare('SELECT 1 FROM nebenleitstellen WHERE id = :nid LIMIT 1');
        $stmtChk->execute([':nid' => (int)$neben_id]);
        if (!$stmtChk->fetchColumn()) {
            throw new Exception('Nebenleitstelle nicht gefunden (ID ' . $neben_id . ')');
        }

        $gps = $row['latitude'] . ', ' . $row['longitude'];
        $upd = $pdo->prepare('
            UPDATE nebenleitstellen
               SET gps     = :gps,
                   geojson = :geo
             WHERE id      = :nid
        ');
        $upd->execute([':gps' => $gps, ':geo' => $row['geojson'], ':nid' => (int)$neben_id]);

        lsttraining_log_activity([
            'entity_type' => 'nebenstelle',
            'action'      => 'update',
            'entity_id'   => (int)$neben_id,
            'meta'        => ['page' => 'ajax:copy_leitstelle', 'from_leitstelle_id' => (int)$leit_id],
        ]);

        wp_send_json_success('Nebenstelle erfolgreich kopiert');
    } catch (Throwable $e) {
        error_log('lsttraining_copy_leitstelle ERROR: ' . $e->getMessage());
        wp_send_json_error('Server-Fehler beim Übernehmen', 500);
    }
}

