<?php
/**
 * ajax_osm_layers.php
 *
 * Tile-Step Download pro Layer, damit große Gebiete nicht in Timeouts/OOM laufen.
 *
 * Layer:
 * - roads_lines
 * - landuse_<value>
 *
 * Speicherung:
 * - GeoJSON wird NICHT als Array im RAM gebaut.
 * - Es wird als FeatureCollection in eine Temp-Datei gestreamt und dann als LOB in leitstellen_osm_layers geschrieben.
 *
 * Filter roads_lines:
 * - nur befahrbare highway-Typen
 * - keine Segmente < 20 m
 * - keine isolierten Segmente (Heuristik pro Tile: beide Endpunkte degree 1)
 */

if (!defined('ABSPATH')) { exit(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/geo.php';
require_once dirname(__DIR__) . '/activity.php';

add_action('wp_ajax_lsttraining_osm_refresh_layer_step', function () {

    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'ls_param'      => ['leitstelle_id', 'ls_id', 'lst_update_id'],
        'ls_required'   => true,
        'nonce_action'  => 'lsttraining_osm_layers',
        'nonce_field'   => 'nonce',
        'method'        => 'POST',
    ]);

    // Wenn Apache den Prozess hart killt, kommt das trotzdem nicht immer durch,
    // aber bei PHP-Fatals hilft es.
    register_shutdown_function(function () {
        if (!defined('DOING_AJAX') || !DOING_AJAX) return;

        $e = error_get_last();
        if (!$e) return;

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($e['type'], $fatalTypes, true)) return;

        if (!headers_sent()) {
            status_header(500);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'success' => false,
            'data' => [
                'message' => $e['message'],
                'file' => $e['file'],
                'line' => $e['line'],
            ],
        ]);
        exit;
    });

    try {
        // ------------------------------------------------------------
        // HARTES ZEITBUDGET PRO REQUEST
        // ------------------------------------------------------------
        $t0 = microtime(true);
        $timeBudgetSec = 18.0; // muss unter deinem Serverlimit liegen
        @set_time_limit(25);

        $ls_id    = absint($_POST['leitstelle_id'] ?? 0);
        $layerKey = sanitize_key((string)($_POST['layer'] ?? ''));
        $cursor   = max(0, (int)($_POST['cursor'] ?? 0));
        $chunkReq = (int)($_POST['chunk'] ?? 1);
        $chunkReq = max(1, min(6, $chunkReq));
        $reset    = !empty($_POST['reset']) && (string)$_POST['reset'] === '1';
        $force    = !empty($_POST['force']) && (string)$_POST['force'] === '1';

        $runToken = sanitize_text_field((string)($_POST['run_token'] ?? ''));
        if ($runToken === '') wp_send_json_error(['message' => 'run_token fehlt.'], 400);

        if (!$ls_id) wp_send_json_error(['message' => 'Leitstelle fehlt.'], 400);
        if (!$layerKey) wp_send_json_error(['message' => 'Layer fehlt.'], 400);

        if ($layerKey !== 'roads_lines' && strpos($layerKey, 'landuse_') !== 0) {
            wp_send_json_error(['message' => 'Layer ungültig.'], 400);
        }

        if (strpos($layerKey, 'landuse_') === 0) {
            $v = substr($layerKey, strlen('landuse_'));
            $allowed = [
                'residential',
                'industrial',
                'commercial',
                'retail',
                'allotments',
                'farmland',
                'animal_keeping',
                'forest',
                'logging',
                'meadow',
                'railway',
                'cemetery',
                'landfill',
                'quarry',
                'recreation_ground',
                'religious',
            ];
            if (!in_array($v, $allowed, true)) {
                wp_send_json_error(['message' => 'Landuse-Key ungültig.'], 400);
            }
        }

        if (lsttraining_osm_is_cancelled($runToken)) {
            wp_send_json_success([
                'done' => true,
                'cancelled' => true,
                'cursor' => $cursor,
                'progress' => 100,
                'feature_count' => 0,
            ]);
        }

        $pdo = lsttraining_get_connection();
        if (!$pdo) wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);

        if (!lsttraining_osm_table_exists($pdo, 'leitstellen_osm_layers')) {
            wp_send_json_error(['message' => 'DB-Fehler: Tabelle leitstellen_osm_layers fehlt.'], 500);
        }

        // Leitstellen-GeoJSON laden
        $stmt = $pdo->prepare('SELECT geojson FROM leitstellen WHERE id = ?');
        $stmt->execute([$ls_id]);
        $geojson = $stmt->fetchColumn();
        if (!$geojson) wp_send_json_error(['message' => 'Leitstelle hat kein Einsatzgebiet (geojson).'], 400);

        $g = json_decode((string)$geojson, true);
        if (!is_array($g)) wp_send_json_error(['message' => 'Leitstelle: geojson ist ungültig.'], 400);

        $mp = lst_geo_to_multipolygon($g);
        if (function_exists('lst_normalize_mpoly_to_wgs84')) {
            $mp = lst_normalize_mpoly_to_wgs84($mp);
        }

        $bbox = lst_mpoly_bbox($mp);
        if ($bbox === [0,0,0,0]) wp_send_json_error(['message' => 'Leitstelle: Einsatzgebiet ist leer.'], 400);

        // Cache nutzen?
        if (!$force && !$reset && $cursor === 0) {
            $row = lsttraining_osm_get_layer_row($pdo, $ls_id, $layerKey);
            if ($row && lsttraining_osm_is_row_fresh($row, 7)) {
                $cnt = lsttraining_osm_count_features_in_json_string((string)$row['geojson']);
                if ($cnt > 0) {
                    wp_send_json_success([
                        'done' => true,
                        'cursor' => 0,
                        'progress' => 100,
                        'feature_count' => $cnt,
                        'final' => [
                            'used_cache' => true,
                            'unchanged' => true,
                        ],
                    ]);
                }
            }
        }

        $stateKey = lsttraining_osm_state_transient_key($ls_id, $layerKey, $runToken);

        // Chunking: roads_lines minimal halten, Landuse 1-3
        $chunk = $chunkReq;
        if ($layerKey === 'roads_lines') $chunk = 1;
        if (strpos($layerKey, 'landuse_') === 0) $chunk = max(1, min(3, $chunkReq));

        if ($reset || $cursor === 0) {
            $state = lsttraining_osm_init_state($bbox, $layerKey);
            set_transient($stateKey, $state, 2 * HOUR_IN_SECONDS);
        } else {
            $state = get_transient($stateKey);
            if (!is_array($state)) {
                $state = lsttraining_osm_init_state($bbox, $layerKey);
                set_transient($stateKey, $state, 2 * HOUR_IN_SECONDS);
            }
        }

        if (empty($state['json_opened'])) {
            lsttraining_osm_init_feature_file($state);
            set_transient($stateKey, $state, 2 * HOUR_IN_SECONDS);
        }

        $tiles = $state['tiles'] ?? [];
        $total = (int)($state['total'] ?? 0);
        if ($total <= 0 || !is_array($tiles)) throw new RuntimeException('Interner Fehler: tile state ungültig.');

        $from = (int)($state['cursor'] ?? 0);
        $to   = min($total, $from + $chunk);

        $featuresWritten = 0;

        for ($i = $from; $i < $to; $i++) {

            // --------------------------------------------------------
            // ZEITBUDGET: bevor Apache killt, sauber JSON zurückgeben
            // --------------------------------------------------------
            if ((microtime(true) - $t0) > $timeBudgetSec) {
                $state['cursor'] = $i;
                set_transient($stateKey, $state, 2 * HOUR_IN_SECONDS);

                $progress = (int)floor(($i / max(1, $total)) * 100);

                wp_send_json_success([
                    'done' => false,
                    'cursor' => $i,
                    'progress' => $progress,
                    'feature_count' => (int)($state['feature_count'] ?? 0),
                    'message' => 'Time budget reached, continue next step',
                ]);
            }

            if (lsttraining_osm_is_cancelled($runToken)) {
                $state['cursor'] = $i;
                set_transient($stateKey, $state, 2 * HOUR_IN_SECONDS);
                wp_send_json_success([
                    'done' => true,
                    'cancelled' => true,
                    'cursor' => $i,
                    'progress' => 100,
                    'feature_count' => (int)($state['feature_count'] ?? 0),
                ]);
            }

            $tb = $tiles[$i];
            $tileFeatures = [];

            if ($layerKey === 'roads_lines') {
                $tileFeatures = lsttraining_osm_build_roads_features_for_tile($tb, $mp);
            } else {
                $val = substr($layerKey, strlen('landuse_'));
                $tileFeatures = lsttraining_osm_build_landuse_features_for_tile($tb, $mp, $val);
            }

            if ($tileFeatures) {
                $featuresWritten += lsttraining_osm_append_features_to_file($state, $tileFeatures);
            }
        }

        $state['cursor'] = $to;
        $state['feature_count'] = (int)($state['feature_count'] ?? 0) + $featuresWritten;
        set_transient($stateKey, $state, 2 * HOUR_IN_SECONDS);

        $progress = (int)floor(($to / max(1, $total)) * 100);

        if ($to >= $total) {
            $finalPath = lsttraining_osm_finalize_feature_file($state);

            $storeRes = lsttraining_osm_store_layer_from_file($pdo, $ls_id, $layerKey, $finalPath);

            lsttraining_osm_cleanup_state_files($state);
            delete_transient($stateKey);

            wp_send_json_success([
                'done' => true,
                'cursor' => $total,
                'progress' => 100,
                'feature_count' => (int)($storeRes['feature_count'] ?? (int)($state['feature_count'] ?? 0)),
                'final' => [
                    'used_cache' => false,
                    'unchanged' => false,
                ],
            ]);
        }

        wp_send_json_success([
            'done' => false,
            'cursor' => $to,
            'progress' => $progress,
            'feature_count' => (int)($state['feature_count'] ?? 0),
        ]);

    } catch (Throwable $e) {

        $msg = (string)$e->getMessage();

        // Wenn wirklich alle Overpass-Endpunkte dicht sind, darf das Frontend warten.
        if (strpos($msg, 'Overpass HTTP Fehler: 429') !== false) {
            $retryAfter = 20;

            if (preg_match('/retry_after=(\d+)s/', $msg, $m)) $retryAfter = (int)$m[1];
            if (preg_match('/cooldown=(\d+)s/', $msg, $m)) $retryAfter = (int)$m[1];

            $retryAfter = max(10, min(180, $retryAfter));

            wp_send_json_success([
                'done' => false,
                'cursor' => (int)($_POST['cursor'] ?? 0),
                'progress' => (int)($_POST['progress'] ?? 0),
                'feature_count' => (int)($_POST['feature_count'] ?? 0),
                'retry_after_ms' => $retryAfter * 1000,
                'message' => 'Overpass 429: retry in ' . $retryAfter . 's',
            ]);
        }

        wp_send_json_error([
            'message' => $msg,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
});

/* -------------------------------------------------------------------------
 * State + Cancel
 * ---------------------------------------------------------------------- */

function lsttraining_osm_state_transient_key(int $ls_id, string $layerKey, string $runToken): string {
    $uid = (int)get_current_user_id();
    return 'lst_osm_state_' . $uid . '_' . $ls_id . '_' . $layerKey . '_' . md5($runToken);
}

function lsttraining_osm_cancel_transient_key(string $runToken): string {
    $uid = (int)get_current_user_id();
    return 'lst_osm_cancel_' . $uid . '_' . md5($runToken);
}

function lsttraining_osm_is_cancelled(string $runToken): bool {
    return (string)get_transient(lsttraining_osm_cancel_transient_key($runToken)) === '1';
}

/* -------------------------------------------------------------------------
 * FeatureCollection streaming
 * ---------------------------------------------------------------------- */

function lsttraining_osm_init_state(array $bbox, string $layerKey): array {

    [$nx, $ny] = lsttraining_osm_choose_tile_grid($bbox, $layerKey);
    $tiles = lsttraining_osm_bbox_tiles($bbox, $nx, $ny);

    $tmpJson = wp_tempnam('lst_osm_fc_');
    if (!$tmpJson) throw new RuntimeException('Tempfile konnte nicht erstellt werden.');

    return [
        'layer' => $layerKey,
        'bbox' => $bbox,
        'tiles' => $tiles,
        'total' => count($tiles),
        'cursor' => 0,
        'feature_count' => 0,
        'tmp_json' => $tmpJson,
        'json_opened' => false,
        'json_first' => true,
    ];
}

function lsttraining_osm_init_feature_file(array &$state): void {
    $p = $state['tmp_json'] ?? '';
    if (!$p || !is_string($p)) throw new RuntimeException('State tmp_json fehlt.');
    file_put_contents($p, "{\"type\":\"FeatureCollection\",\"features\":[\n");
    $state['json_opened'] = true;
    $state['json_first'] = true;
}

function lsttraining_osm_append_features_to_file(array &$state, array $features): int {
    $p = $state['tmp_json'] ?? '';
    if (!$p || !is_string($p) || empty($state['json_opened'])) {
        throw new RuntimeException('State tmp_json nicht initialisiert.');
    }

    $fh = fopen($p, 'ab');
    if (!$fh) throw new RuntimeException('Tempfile konnte nicht geöffnet werden.');

    $first = !empty($state['json_first']);
    $written = 0;

    foreach ($features as $f) {
        $json = json_encode($f, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') continue;

        if (!$first) fwrite($fh, ",\n");
        else $first = false;

        fwrite($fh, $json);
        $written++;
    }

    fclose($fh);
    $state['json_first'] = $first;
    return $written;
}

function lsttraining_osm_finalize_feature_file(array $state): string {
    $p = $state['tmp_json'] ?? '';
    if (!$p || !is_string($p)) throw new RuntimeException('State tmp_json fehlt.');

    $fh = fopen($p, 'ab');
    if (!$fh) throw new RuntimeException('Tempfile konnte nicht geöffnet werden.');

    fwrite($fh, "\n]}\n");
    fclose($fh);

    return $p;
}

function lsttraining_osm_cleanup_state_files(array $state): void {
    $p = $state['tmp_json'] ?? '';
    if ($p && is_string($p) && file_exists($p)) @unlink($p);
}

/* -------------------------------------------------------------------------
 * DB helpers
 * ---------------------------------------------------------------------- */

function lsttraining_osm_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $stmt->execute([$table]);
        return ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function lsttraining_osm_get_layer_row(PDO $pdo, int $ls_id, string $layerKey): ?array {
    $stmt = $pdo->prepare('SELECT geojson, source, updated_at FROM leitstellen_osm_layers WHERE leitstelle_id = ? AND layer_key = ? LIMIT 1');
    $stmt->execute([$ls_id, $layerKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function lsttraining_osm_age_days($updatedAt): int {
    $t = is_string($updatedAt) ? strtotime($updatedAt) : false;
    if (!$t) return 999999;
    $age = time() - $t;
    return (int)floor($age / 86400);
}

function lsttraining_osm_is_row_fresh(array $row, int $maxDays): bool {
    $ageDays = lsttraining_osm_age_days($row['updated_at'] ?? null);
    return ($ageDays <= $maxDays);
}

function lsttraining_osm_count_features_in_json_string(string $json): int {
    return substr_count($json, '"type":"Feature"');
}

function lsttraining_osm_store_layer_from_file(PDO $pdo, int $ls_id, string $layerKey, string $filePath): array {
    if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
        throw new RuntimeException('Finales GeoJSON-File fehlt.');
    }

    $fp = fopen($filePath, 'rb');
    if (!$fp) throw new RuntimeException('Finales GeoJSON-File konnte nicht geöffnet werden.');

    $featureCount = 0;

    $sql = "INSERT INTO leitstellen_osm_layers (leitstelle_id, layer_key, source, geojson, updated_at, created_at)
            VALUES (:ls, :k, :src, :geo, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              source = VALUES(source),
              geojson = VALUES(geojson),
              updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $src = 'json';

    $stmt->bindValue(':ls', $ls_id, PDO::PARAM_INT);
    $stmt->bindValue(':k',  $layerKey, PDO::PARAM_STR);
    $stmt->bindValue(':src', $src, PDO::PARAM_STR);
    $stmt->bindParam(':geo', $fp, PDO::PARAM_LOB);

    $stmt->execute();
    fclose($fp);

    return [
        'ok' => true,
        'feature_count' => $featureCount,
        'source' => $src,
    ];
}

/* -------------------------------------------------------------------------
 * Tiling helpers
 * ---------------------------------------------------------------------- */

function lsttraining_osm_choose_tile_grid(array $bbox, string $layerKey): array {
    $minLon = (float)$bbox[0];
    $minLat = (float)$bbox[1];
    $maxLon = (float)$bbox[2];
    $maxLat = (float)$bbox[3];

    $spanLon = max(0.000001, $maxLon - $minLon);
    $spanLat = max(0.000001, $maxLat - $minLat);
    $span = max($spanLon, $spanLat);

    $n = 1;
    if ($span <= 0.10) $n = 1;
    elseif ($span <= 0.18) $n = 2;
    elseif ($span <= 0.28) $n = 3;
    elseif ($span <= 0.40) $n = 4;
    elseif ($span <= 0.55) $n = 5;
    else $n = 6;

    // roads_lines: deutlich kleinere Tiles, damit Steps unter Serverlimit bleiben
    if ($layerKey === 'roads_lines') {
        $n = (int)max(4, min(18, ceil($n * 2.4)));
    } else {
        $n = (int)max(1, min(10, $n));
    }

    return [$n, $n];
}

function lsttraining_osm_bbox_tiles(array $bbox, int $nx, int $ny): array {
    $minLon = (float)$bbox[0];
    $minLat = (float)$bbox[1];
    $maxLon = (float)$bbox[2];
    $maxLat = (float)$bbox[3];

    $dx = ($maxLon - $minLon) / max(1, $nx);
    $dy = ($maxLat - $minLat) / max(1, $ny);

    $tiles = [];
    for ($ix = 0; $ix < $nx; $ix++) {
        for ($iy = 0; $iy < $ny; $iy++) {
            $a = $minLon + $ix * $dx;
            $b = $minLat + $iy * $dy;
            $c = $minLon + ($ix + 1) * $dx;
            $d = $minLat + ($iy + 1) * $dy;
            $tiles[] = [$a, $b, $c, $d];
        }
    }
    return $tiles;
}

/* -------------------------------------------------------------------------
 * Overpass HTTP (Rotation + 429 endpoint cooldown + rate gate)
 * ---------------------------------------------------------------------- */

function lsttraining_osm_overpass_urls(): array {
    $primary  = get_option('lsttraining_overpass_url');
    $fallback = get_option('lsttraining_overpass_url_fallback');

    $primary  = is_string($primary)  && trim($primary)  ? trim($primary)  : 'https://overpass-api.de/api/interpreter';
    $fallback = is_string($fallback) && trim($fallback) ? trim($fallback) : 'https://overpass.kumi.systems/api/interpreter';

    $urls = [
        $primary,
        $fallback,
        'https://overpass.nchc.org.tw/api/interpreter',
        'https://overpass.openstreetmap.ru/api/interpreter',
    ];

    $out = [];
    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        if (in_array($u, $out, true)) continue;
        $out[] = $u;
    }
    return $out;
}

function lsttraining_overpass_rate_gate(string $layerKey = ''): void {
    $key = 'lst_overpass_last_ts';
    $last = (float)get_transient($key);
    $now  = microtime(true);

    $minGapMs = 650;
    if ($layerKey === 'roads_lines') $minGapMs = 950;
    if (strpos($layerKey, 'landuse_') === 0) $minGapMs = 700;

    if ($last > 0) {
        $elapsedMs = ($now - $last) * 1000.0;
        if ($elapsedMs < $minGapMs) {
            $sleepMs = (int)($minGapMs - $elapsedMs);
            if ($sleepMs > 0) usleep($sleepMs * 1000);
        }
    }

    set_transient($key, microtime(true), 120);
}

function lsttraining_overpass_endpoint_cooldown_key(string $url): string {
    return 'lst_overpass_ep_cd_' . md5($url);
}

function lsttraining_overpass_is_endpoint_cooled_down(string $url): int {
    $until = (int)get_transient(lsttraining_overpass_endpoint_cooldown_key($url));
    if ($until <= time()) return 0;
    return $until - time();
}

function lsttraining_overpass_set_endpoint_cooldown(string $url, int $seconds): void {
    $seconds = max(5, min(180, (int)$seconds));
    set_transient(lsttraining_overpass_endpoint_cooldown_key($url), time() + $seconds, $seconds);
}

function lsttraining_overpass_parse_retry_after_seconds($res): int {
    $retryAfter = 20;
    $ra = wp_remote_retrieve_header($res, 'retry-after');

    if (is_string($ra)) {
        $ra = trim($ra);
        if (ctype_digit($ra)) {
            $retryAfter = (int)$ra;
        } else {
            $ts = strtotime($ra);
            if ($ts) $retryAfter = max(5, $ts - time());
        }
    }

    return max(10, min(180, $retryAfter));
}

function lsttraining_osm_overpass_query(string $query, int $timeoutSec, string $layerKey): array {

    lsttraining_overpass_rate_gate($layerKey);

    $urls = lsttraining_osm_overpass_urls();
    if (!$urls) throw new RuntimeException('Overpass URL-Liste ist leer.');

    $minCooldownSeen = null;

    // Zwei Runden über alle Endpoints, damit 429 sofort Failover hat
    for ($round = 1; $round <= 2; $round++) {

        $allBlocked = true;

        foreach ($urls as $url) {

            $cool = lsttraining_overpass_is_endpoint_cooled_down($url);
            if ($cool > 0) {
                $minCooldownSeen = ($minCooldownSeen === null) ? $cool : min($minCooldownSeen, $cool);
                continue;
            }

            $allBlocked = false;

            $res = wp_remote_post($url, [
                // kurz halten, damit Steps nicht in Apache-500 laufen
                'timeout' => max(15, min(25, (int)$timeoutSec)),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'User-Agent' => 'LSTtraining/1.0 (wordpress; osm layer fetch)',
                ],
                'body' => 'data=' . urlencode($query),
            ]);

            if (is_wp_error($res)) {
                // nächster Endpoint
                continue;
            }

            $code = (int)wp_remote_retrieve_response_code($res);
            $body = (string)wp_remote_retrieve_body($res);

            if ($code === 429) {
                $raSec = lsttraining_overpass_parse_retry_after_seconds($res);
                lsttraining_overpass_set_endpoint_cooldown($url, $raSec);
                $minCooldownSeen = ($minCooldownSeen === null) ? $raSec : min($minCooldownSeen, $raSec);
                // direkt nächster Endpoint
                continue;
            }

            if ($code !== 200 || $body === '') {
                // nächster Endpoint
                continue;
            }

            $json = json_decode($body, true);
            if (!is_array($json)) continue;

            $elements = $json['elements'] ?? null;
            return is_array($elements) ? $elements : [];
        }

        if ($allBlocked) {
            // kurz warten, dann zweite Runde
            usleep(300 * 1000);
        }
    }

    if ($minCooldownSeen !== null) {
        throw new RuntimeException('Overpass HTTP Fehler: 429 (retry_after=' . (int)$minCooldownSeen . 's)');
    }

    throw new RuntimeException('Overpass fehlgeschlagen.');
}

/* -------------------------------------------------------------------------
 * Overpass: Roads + Landuse Queries
 * ---------------------------------------------------------------------- */

function lsttraining_osm_bbox_str(array $bbox): string {
    // Overpass: (south,west,north,east)
    $minLon = (float)$bbox[0];
    $minLat = (float)$bbox[1];
    $maxLon = (float)$bbox[2];
    $maxLat = (float)$bbox[3];
    return $minLat . ',' . $minLon . ',' . $maxLat . ',' . $maxLon;
}

function lsttraining_osm_fetch_roads_ways_geom(array $bbox): array {
    $bb = lsttraining_osm_bbox_str($bbox);

    $highways = [
        'motorway','trunk','primary','secondary','tertiary',
        'motorway_link','trunk_link','primary_link','secondary_link','tertiary_link',
        'residential','living_street','unclassified','service',
        'track','road'
    ];
    $hwRegex = implode('|', array_map('preg_quote', $highways));

    $q =
        "[out:json][timeout:25];\n" .
        "(\n" .
        "  way[\"highway\"~\"^(" . $hwRegex . ")$\"](" . $bb . ");\n" .
        ");\n" .
        "out geom tags qt;";

    return lsttraining_osm_overpass_query($q, 25, 'roads_lines');
}

function lsttraining_osm_fetch_landuse_geom(array $bbox, string $landuseValue): array {
    $bb = lsttraining_osm_bbox_str($bbox);
    $v = preg_replace('/[^a-z0-9_\\-]/i', '', $landuseValue);

    $q =
        "[out:json][timeout:25];\n" .
        "(\n" .
        "  way[\"landuse\"=\"" . $v . "\"](" . $bb . ");\n" .
        "  relation[\"type\"=\"multipolygon\"][\"landuse\"=\"" . $v . "\"](" . $bb . ");\n" .
        ");\n" .
        "out geom tags qt;";

    return lsttraining_osm_overpass_query($q, 25, 'landuse_' . $v);
}

/* -------------------------------------------------------------------------
 * Tile builders
 * ---------------------------------------------------------------------- */

function lsttraining_osm_build_roads_features_for_tile(array $tileBbox, array $mp): array {
    $elements = lsttraining_osm_fetch_roads_ways_geom($tileBbox);
    if (!$elements) return [];

    $features = lsttraining_osm_elements_to_linestring_features_geom_filtered($elements);

    // Clip kann teuer sein. Wenn du weiterhin OOM/500 siehst, hier zuerst testweise auskommentieren.
    if (function_exists('lst_clip_features_to_mpoly')) {
        $features = lst_clip_features_to_mpoly($features, $mp);
    }

    return $features;
}

function lsttraining_osm_build_landuse_features_for_tile(array $tileBbox, array $mp, string $landuseValue): array {
    $elements = lsttraining_osm_fetch_landuse_geom($tileBbox, $landuseValue);
    if (!$elements) return [];

    $features = lsttraining_osm_elements_to_polygon_features_geom($elements);

    if (function_exists('lst_clip_features_to_mpoly')) {
        $features = lst_clip_features_to_mpoly($features, $mp);
    }

    return $features;
}

/* -------------------------------------------------------------------------
 * Geo helpers: Length + endpoint degree
 * ---------------------------------------------------------------------- */

function lsttraining_haversine_m(float $lon1, float $lat1, float $lon2, float $lat2): float {
    $R = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlambda = deg2rad($lon2 - $lon1);

    $a = sin($dphi/2) * sin($dphi/2) + cos($phi1) * cos($phi2) * sin($dlambda/2) * sin($dlambda/2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function lsttraining_linestring_length_m(array $coords): float {
    $n = count($coords);
    if ($n < 2) return 0.0;
    $sum = 0.0;
    for ($i = 1; $i < $n; $i++) {
        $a = $coords[$i - 1];
        $b = $coords[$i];
        if (!is_array($a) || !is_array($b) || count($a) < 2 || count($b) < 2) continue;
        $sum += lsttraining_haversine_m((float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]);
    }
    return $sum;
}

function lsttraining_endpoint_key(array $pt): string {
    $lon = round((float)$pt[0], 5);
    $lat = round((float)$pt[1], 5);
    return $lon . ',' . $lat;
}

/* -------------------------------------------------------------------------
 * OSM elements -> GeoJSON Features
 * ---------------------------------------------------------------------- */

function lsttraining_osm_elements_to_linestring_features_geom_filtered(array $elements): array {

    $minLenM = 20.0;

    $raw = [];
    foreach ($elements as $el) {
        if (!is_array($el)) continue;
        if (($el['type'] ?? '') !== 'way') continue;

        $geom = $el['geometry'] ?? null;
        if (!is_array($geom) || count($geom) < 2) continue;

        $coords = [];
        foreach ($geom as $pt) {
            if (!is_array($pt)) continue;
            $lat = $pt['lat'] ?? null;
            $lon = $pt['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) continue;
            $coords[] = [(float)$lon, (float)$lat];
        }
        if (count($coords) < 2) continue;

        $len = lsttraining_linestring_length_m($coords);
        if ($len < $minLenM) continue;

        $tags = $el['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        $raw[] = [
            'id' => (string)($el['id'] ?? ''),
            'coords' => $coords,
            'tags' => $tags,
            'highway' => (string)($tags['highway'] ?? ''),
            'name' => (string)($tags['name'] ?? ''),
        ];
    }

    if (!$raw) return [];

    $deg = [];
    foreach ($raw as $r) {
        $c = $r['coords'];
        $a = $c[0];
        $b = $c[count($c) - 1];
        $ka = lsttraining_endpoint_key($a);
        $kb = lsttraining_endpoint_key($b);
        $deg[$ka] = ($deg[$ka] ?? 0) + 1;
        $deg[$kb] = ($deg[$kb] ?? 0) + 1;
    }

    $features = [];
    foreach ($raw as $r) {
        $c = $r['coords'];
        $a = $c[0];
        $b = $c[count($c) - 1];
        $ka = lsttraining_endpoint_key($a);
        $kb = lsttraining_endpoint_key($b);

        $da = (int)($deg[$ka] ?? 0);
        $db = (int)($deg[$kb] ?? 0);

        // isoliert (tile-lokal)
        if ($da <= 1 && $db <= 1) continue;

        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'osm_id' => $r['id'],
                'highway' => $r['highway'],
                'name' => $r['name'],
                'tags' => $r['tags'],
            ],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $c,
            ],
        ];
    }

    return $features;
}

function lsttraining_osm_elements_to_polygon_features_geom(array $elements): array {
    $features = [];

    foreach ($elements as $el) {
        if (!is_array($el)) continue;

        $type = (string)($el['type'] ?? '');
        if ($type !== 'way' && $type !== 'relation') continue;

        $geom = $el['geometry'] ?? null;
        if (!is_array($geom) || count($geom) < 4) continue;

        $coords = [];
        foreach ($geom as $pt) {
            if (!is_array($pt)) continue;
            $lat = $pt['lat'] ?? null;
            $lon = $pt['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) continue;
            $coords[] = [(float)$lon, (float)$lat];
        }
        if (count($coords) < 4) continue;

        $first = $coords[0];
        $last  = $coords[count($coords)-1];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) $coords[] = $first;

        $tags = $el['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'osm_id' => (string)($el['id'] ?? ''),
                'tags' => $tags,
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$coords],
            ],
        ];
    }

    return $features;
}
