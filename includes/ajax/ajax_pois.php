<?php
/**
 * POIs pro Leitstelle (CRUD)
 *
 * Tabellenname: leitstellen_pois
 * Felder: poi_type, name, comment, genus, latitude, longitude
 */

if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/ajax_common.php';

/**
 * POI-Typen aus JSON laden.
 * Datei: /data/poi_types.json
 * Erwartetes Format:
 * {
 *   "version": 1,
 *   "types": [ {"tag":"AH","color":"#...","description":"..."}, ... ]
 * }
 */
function lsttraining_poi_types_from_json(): array {
    $json_path = plugin_dir_path(dirname(__DIR__)) . 'data/poi_types.json';

    // Fallback: harte Defaults (wie von dir vorgegeben)
    $fallback_tags = [
        'AH','AKIP','AKIP1','AKIP2','AmbKH','Arena','Bahnlinie','Bahnübergang','Bank','Bauernhof','Bereitschaft',
        'BHF','BW','Blut','Brücke','Dialyse','Disko','Feuerwehr','FKH','Flucht','Flughafen','FlugKH','Flugplatz',
        'Freibad','Friedhof','Fußball','FZPark','Gasthaus','Grundschule','GKH','Hafen','Hallenbad','HBF','Herz',
        'IKH','Industrie','Juhe','JVA','Kaufhaus','KH','KH1','KH2','KH3','KiKa','KiKli','Kirche','Kultur','Küste',
        'Lst','Lunge','Notfallpraxis','Ortho','Park','Polizei','Psychiatrie','Reha','Reiterhof','RW','SBF','Schleuse',
        'Schloss','Schule','See','Sporthalle','Strahlen','Tankstelle','Tropen','UBF','Tunnel','UKH','Wald','Zelt','Zoo'
    ];

    if (!is_readable($json_path)) {
        return array_map(function($tag){
            return ['tag' => $tag, 'color' => '#888888', 'description' => ''];
        }, $fallback_tags);
    }

    $raw = file_get_contents($json_path);
    if (!is_string($raw) || $raw === '') {
        return array_map(function($tag){
            return ['tag' => $tag, 'color' => '#888888', 'description' => ''];
        }, $fallback_tags);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array_map(function($tag){
            return ['tag' => $tag, 'color' => '#888888', 'description' => ''];
        }, $fallback_tags);
    }

    $types = isset($decoded['types']) && is_array($decoded['types']) ? $decoded['types'] : [];
    $out = [];

    foreach ($types as $t) {
        if (!is_array($t)) {
            continue;
        }
        $tag = isset($t['tag']) ? trim((string)$t['tag']) : '';
        if ($tag === '') {
            continue;
        }
        $color = isset($t['color']) ? trim((string)$t['color']) : '';
        $desc  = isset($t['description']) ? trim((string)$t['description']) : '';

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#888888';
        }

        $out[] = [
            'tag' => $tag,
            'color' => $color,
            'description' => $desc,
        ];
    }

    // Wenn JSON leer/kaputt: fallback
    if (!$out) {
        $out = array_map(function($tag){
            return ['tag' => $tag, 'color' => '#888888', 'description' => ''];
        }, $fallback_tags);
    }

    // stabil sortieren nach Tag
    usort($out, function($a, $b){
        return strnatcasecmp((string)$a['tag'], (string)$b['tag']);
    });

    return $out;
}

function lsttraining_pois_table_name($pdo): string {
    // externe DB ohne Prefix: Tabellen sind im Schema ohne wp_ (außer wp_users)
    return 'leitstellen_pois';
}

/**
 * GET: POIs + Typen für eine Leitstelle
 */
add_action('wp_ajax_get_leitstelle_pois', function () {
    $g = lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'ls_required'  => true,
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'GET',
    ]);

    $ls_id = (int) $g['ls_id'];
    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $tbl = lsttraining_pois_table_name($pdo);

    // Leitstellen-Koordinaten (für Map-View)
    $st = $pdo->prepare('SELECT latitude, longitude, geojson FROM leitstellen WHERE id = ?');
    $st->execute([$ls_id]);
    $ls = $st->fetch();

    $stmt = $pdo->prepare("SELECT id, poi_type, name, comment, genus, latitude, longitude, created_at FROM {$tbl} WHERE leitstelle_id = ? ORDER BY id DESC");
    $stmt->execute([$ls_id]);
    $pois = $stmt->fetchAll();

    wp_send_json_success([
        'leitstelle_id'  => $ls_id,
        'leitstelle_lat' => $ls ? (float)$ls['latitude'] : null,
        'leitstelle_lon' => $ls ? (float)$ls['longitude'] : null,
        'einsatzgebiet_geojson' => $ls ? (string)$ls['geojson'] : '',
        'poi_types'      => lsttraining_poi_types_from_json(),
        'pois'           => $pois,
    ]);
});

/**
 * POST: POI erstellen
 */
add_action('wp_ajax_create_leitstelle_poi', function () {
    $g = lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'ls_required'  => true,
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $ls_id = (int) $g['ls_id'];
    $poi_type  = isset($_POST['poi_type']) ? sanitize_text_field(wp_unslash($_POST['poi_type'])) : '';
    $name      = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $comment   = isset($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';
    $genus     = isset($_POST['genus']) ? sanitize_text_field(wp_unslash($_POST['genus'])) : '';
    $lat       = isset($_POST['latitude']) ? (float) $_POST['latitude'] : null;
    $lon       = isset($_POST['longitude']) ? (float) $_POST['longitude'] : null;

    if ($poi_type === '' || $lat === null || $lon === null) {
        wp_send_json_error(['message' => 'Typ und Koordinaten sind Pflicht.'], 400);
    }
    if (!in_array($genus, ['der','die','das'], true)) {
        $genus = 'der';
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $tbl = lsttraining_pois_table_name($pdo);
    $stmt = $pdo->prepare("INSERT INTO {$tbl} (leitstelle_id, poi_type, name, comment, genus, latitude, longitude) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$ls_id, $poi_type, $name, $comment, $genus, $lat, $lon]);

    $id = (int)$pdo->lastInsertId();
    wp_send_json_success(['id' => $id]);
});

/**
 * POST: POI aktualisieren
 */
add_action('wp_ajax_update_leitstelle_poi', function () {
    $g = lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'ls_required'  => true,
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $ls_id = (int) $g['ls_id'];
    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'POI-ID fehlt.'], 400);
    }

    $poi_type  = isset($_POST['poi_type']) ? sanitize_text_field(wp_unslash($_POST['poi_type'])) : '';
    $name      = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $comment   = isset($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';
    $genus     = isset($_POST['genus']) ? sanitize_text_field(wp_unslash($_POST['genus'])) : '';
    $lat       = isset($_POST['latitude']) ? (float) $_POST['latitude'] : null;
    $lon       = isset($_POST['longitude']) ? (float) $_POST['longitude'] : null;

    if ($poi_type === '' || $lat === null || $lon === null) {
        wp_send_json_error(['message' => 'Typ und Koordinaten sind Pflicht.'], 400);
    }
    if (!in_array($genus, ['der','die','das'], true)) {
        $genus = 'der';
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $tbl = lsttraining_pois_table_name($pdo);

    // Safety: nur POIs der Leitstelle
    $stmt = $pdo->prepare("UPDATE {$tbl} SET poi_type = ?, name = ?, comment = ?, genus = ?, latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ? AND leitstelle_id = ?");
    $stmt->execute([$poi_type, $name, $comment, $genus, $lat, $lon, $id, $ls_id]);

    wp_send_json_success(['id' => $id]);
});

/**
 * POST: POI löschen
 */
add_action('wp_ajax_delete_leitstelle_poi', function () {
    $g = lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'ls_required'  => true,
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $ls_id = (int) $g['ls_id'];
    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'POI-ID fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $tbl = lsttraining_pois_table_name($pdo);
    $stmt = $pdo->prepare("DELETE FROM {$tbl} WHERE id = ? AND leitstelle_id = ?");
    $stmt->execute([$id, $ls_id]);

    wp_send_json_success(['id' => $id]);
});
