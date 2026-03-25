<?php
/**
 * ajax_osm_layers.php
 *
 * Inkremeteller OSM-Refresh pro Leitstelle + Layer.
 *
 * Ablauf:
 * - Scope der relevanten Tiles für das Einsatzgebiet sicherstellen
 * - Tiles chargenweise laden
 * - Pro Tile zuerst leichter Overpass-Precheck per changed/newer + out count
 * - Nur bei Änderung vollständigen Tile-Download durchführen
 * - Manifest / Metadaten in leitstellen_osm_layers aktualisieren
 * - Parallele Läufe pro leitstelle_id + layer_key über Lock verhindern
 *
 * Annahme:
 * - Es gibt fachlich nur eine aktuelle Manifest-Zeile pro Tile
 *   (layer_key + tile_z + tile_x + tile_y)
 */

if (!defined('ABSPATH')) { exit(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/geo.php';
require_once dirname(__DIR__) . '/activity.php';

add_action('wp_ajax_lsttraining_osm_refresh_layer_step', 'lsttraining_osm_refresh_layer_step');

function lsttraining_osm_refresh_layer_step(): void {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'ls_param'     => ['leitstelle_id', 'ls_id', 'lst_update_id'],
        'ls_required'  => true,
        'nonce_action' => 'lsttraining_osm_layers',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $lockAcquired = false;
    $lockToken = '';
    $pdo = null;
    $leitstelleId = 0;
    $layerKey = '';

    try {
        @set_time_limit(25);

        $leitstelleId = absint($_POST['leitstelle_id'] ?? 0);
        $layerKey     = sanitize_key((string)($_POST['layer'] ?? ($_POST['layer_key'] ?? '')));
        $offset       = max(0, (int)($_POST['cursor'] ?? ($_POST['offset'] ?? 0)));
        $limit        = max(1, min(100, (int)($_POST['chunk'] ?? ($_POST['limit'] ?? 50))));
        $force        = !empty($_POST['force']) && (string)$_POST['force'] === '1';
        $forceScope   = !empty($_POST['force_scope_rebuild']) && (string)$_POST['force_scope_rebuild'] === '1';
        $runToken     = sanitize_text_field((string)($_POST['run_token'] ?? wp_generate_password(20, false, false)));

        if ($leitstelleId <= 0) {
            wp_send_json_error(['message' => 'Leitstelle fehlt.'], 400);
        }

        $layerDef = lsttraining_osm_layer_definition($layerKey);
        if (!$layerDef) {
            wp_send_json_error(['message' => 'Layer ungültig.'], 400);
        }

        $pdo = lsttraining_get_connection();
        if (!$pdo) {
            wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        lsttraining_osm_assert_required_tables($pdo);

        $lockToken = 'osm_' . $runToken . '_' . substr(sha1(uniqid('', true)), 0, 16);
        $lockAcquired = lsttraining_osm_acquire_lock($pdo, $leitstelleId, $layerKey, $lockToken, 180);
        if (!$lockAcquired) {
            wp_send_json_success([
                'success' => false,
                'tiles_total' => 0,
                'tiles_checked' => 0,
                'tiles_changed' => 0,
                'tiles_unchanged' => 0,
                'tiles_errors' => 0,
                'done' => false,
                'next_offset' => $offset,
                'cursor' => $offset,
                'progress' => 0,
                'feature_count' => 0,
                'message' => 'Layer wird bereits aktualisiert.',
            ]);
        }

        $scopeInfo = lsttraining_osm_ensure_scope_for_leitstelle_layer($pdo, $leitstelleId, $layerKey, $forceScope);
        $tilesTotal = (int)($scopeInfo['tiles_total'] ?? 0);

        if ($tilesTotal <= 0) {
            lsttraining_osm_release_lock($pdo, $leitstelleId, $layerKey, $lockToken);
            $lockAcquired = false;

            wp_send_json_success([
                'tiles_total' => 0,
                'tiles_checked' => 0,
                'tiles_changed' => 0,
                'tiles_unchanged' => 0,
                'tiles_errors' => 0,
                'done' => true,
                'next_offset' => 0,
                'cursor' => 0,
                'progress' => 100,
                'feature_count' => 0,
                'final' => [
                    'used_cache' => true,
                    'unchanged' => true,
                ],
                'message' => 'Keine relevanten Tiles im Einsatzgebiet gefunden.',
            ]);
        }

        $tileRows = lsttraining_osm_get_scope_tiles($pdo, $leitstelleId, $layerKey, $offset, $limit);

        $tilesChecked = 0;
        $tilesChanged = 0;
        $tilesUnchanged = 0;
        $tilesErrors = 0;
        $changedFeatureCount = 0;

        foreach ($tileRows as $tileRow) {
            $tilesChecked++;
            lsttraining_osm_refresh_lock($pdo, $leitstelleId, $layerKey, $lockToken, 180);

            try {
                $precheck = lsttraining_osm_tile_needs_download($tileRow, $layerKey, $force);

                if (empty($precheck['needs_download'])) {
                    $tilesUnchanged++;
                    lsttraining_osm_update_manifest_after_check($pdo, $tileRow, [
                        'status' => 'unchanged',
                        'osm_base' => $precheck['osm_base'] ?? null,
                        'check_message' => $precheck['check_message'] ?? null,
                    ]);
                    continue;
                }

                $download = lsttraining_osm_download_tile($tileRow, $layerKey);
                if (empty($download['ok'])) {
                    throw new RuntimeException((string)($download['message'] ?? 'Tile-Download fehlgeschlagen.'));
                }

                $tilesChanged++;
                $changedFeatureCount += (int)($download['feature_count'] ?? 0);

                lsttraining_osm_update_manifest_after_check($pdo, $tileRow, [
                    'status' => 'changed',
                    'sha1' => $download['sha1'] ?? null,
                    'feature_count' => $download['feature_count'] ?? 0,
                    'bytes_gz' => $download['bytes_gz'] ?? 0,
                    'file_relpath' => $download['file_relpath'] ?? null,
                    'osm_base' => $download['osm_base'] ?? ($precheck['osm_base'] ?? null),
                    'source' => 'overpass',
                    'check_message' => null,
                ]);
            } catch (Throwable $tileError) {
                $tilesErrors++;
                lsttraining_osm_update_manifest_after_check($pdo, $tileRow, [
                    'status' => 'error',
                    'check_message' => $tileError->getMessage(),
                ]);
            }
        }

        $nextOffset = $offset + count($tileRows);
        $done = ($nextOffset >= $tilesTotal) || empty($tileRows);
        $progress = $done
            ? 100
            : (int)floor((min($nextOffset, $tilesTotal) / max(1, $tilesTotal)) * 100);

        lsttraining_osm_release_lock($pdo, $leitstelleId, $layerKey, $lockToken);
        $lockAcquired = false;

        wp_send_json_success([
            'tiles_total' => $tilesTotal,
            'tiles_checked' => $tilesChecked,
            'tiles_changed' => $tilesChanged,
            'tiles_unchanged' => $tilesUnchanged,
            'tiles_errors' => $tilesErrors,
            'done' => $done,
            'next_offset' => $done ? 0 : $nextOffset,
            'cursor' => $done ? $tilesTotal : $nextOffset,
            'progress' => $progress,
            'feature_count' => $changedFeatureCount,
            'final' => $done ? [
                'used_cache' => ($tilesChanged === 0 && $tilesErrors === 0),
                'unchanged' => ($tilesChanged === 0 && $tilesErrors === 0),
            ] : null,
            'message' => sprintf(
                '%d von %d Tiles geprüft, %d geändert, %d unverändert, %d Fehler.',
                min($nextOffset, $tilesTotal),
                $tilesTotal,
                $tilesChanged,
                $tilesUnchanged,
                $tilesErrors
            ),
        ]);
    } catch (Throwable $e) {
        if ($lockAcquired && $pdo instanceof PDO && $leitstelleId > 0 && $layerKey !== '' && $lockToken !== '') {
            lsttraining_osm_release_lock($pdo, $leitstelleId, $layerKey, $lockToken);
        }

        wp_send_json_error([
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
}

function lsttraining_osm_assert_required_tables(PDO $pdo): void {
    $needed = [
        'leitstellen',
        'leitstellen_osm_layers',
        'leitstelle_tile_scope',
        'leitstelle_osm_update_lock',
    ];

    foreach ($needed as $table) {
        if (!lsttraining_osm_table_exists($pdo, $table)) {
            throw new RuntimeException('DB-Fehler: Tabelle ' . $table . ' fehlt.');
        }
    }
}

function lsttraining_osm_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn() > 0);
}

function lsttraining_osm_layer_definition(string $layerKey): array {
   if ($layerKey === 'roads_lines') {
    return [
        'layer_key' => $layerKey,
        'tile_z' => 13,
        'geometry_type' => 'line',
        'precheck_prefer_changed' => false,
        'request_timeout' => 25,
        'out_mode' => 'geom',
        'queries' => [
            ['type' => 'way', 'filter' => '[highway]'],
        ],
    ];
}

    if (strpos($layerKey, 'landuse_') === 0) {
        $value = substr($layerKey, 8);
        $allowed = [
            'residential', 'industrial', 'commercial', 'retail', 'allotments',
            'farmland', 'animal_keeping', 'forest', 'logging', 'meadow',
            'railway', 'cemetery', 'landfill', 'quarry', 'recreation_ground', 'religious',
        ];

        if (!in_array($value, $allowed, true)) {
            return [];
        }

        return [
            'layer_key' => $layerKey,
            'tile_z' => 13,
            'geometry_type' => 'polygon',
            'precheck_prefer_changed' => true,
            'request_timeout' => 25,
            'out_mode' => 'geom',
            'queries' => [
                ['type' => 'way', 'filter' => '[landuse=' . $value . ']'],
                ['type' => 'relation', 'filter' => '[landuse=' . $value . ']'],
            ],
        ];
    }

    return [];
}

function lsttraining_osm_get_manifest_zoom(PDO $pdo, string $layerKey, int $fallback): int {
    $stmt = $pdo->prepare(
        "SELECT tile_z
         FROM leitstellen_osm_layers
         WHERE layer_key = ? AND tile_z IS NOT NULL
         GROUP BY tile_z
         ORDER BY COUNT(*) DESC, tile_z DESC
         LIMIT 1"
    );
    $stmt->execute([$layerKey]);
    $z = $stmt->fetchColumn();
    return ($z !== false) ? (int)$z : $fallback;
}

function lsttraining_osm_ensure_scope_for_leitstelle_layer(PDO $pdo, int $leitstelleId, string $layerKey, bool $forceRebuild = false): array {
    $layerDef = lsttraining_osm_layer_definition($layerKey);
    if (!$layerDef) {
        throw new RuntimeException('Layer-Konfiguration fehlt: ' . $layerKey);
    }

    if (!$forceRebuild) {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM leitstelle_tile_scope
             WHERE leitstelle_id = ? AND layer_key = ?'
        );
        $countStmt->execute([$leitstelleId, $layerKey]);
        $existing = (int)$countStmt->fetchColumn();

        if ($existing > 0) {
            return [
                'tiles_total' => $existing,
                'tile_z' => lsttraining_osm_get_manifest_zoom($pdo, $layerKey, (int)$layerDef['tile_z']),
                'used_existing_scope' => true,
            ];
        }
    }

    $stmt = $pdo->prepare('SELECT geojson FROM leitstellen WHERE id = ? LIMIT 1');
    $stmt->execute([$leitstelleId]);
    $geojson = (string)$stmt->fetchColumn();
    if ($geojson === '') {
        throw new RuntimeException('Leitstelle hat kein Einsatzgebiet (geojson).');
    }

    $z = lsttraining_osm_get_manifest_zoom($pdo, $layerKey, (int)$layerDef['tile_z']);
    $tiles = lsttraining_tiles_from_geojson($geojson, $z, $layerKey);

    $pdo->prepare('DELETE FROM leitstelle_tile_scope WHERE leitstelle_id = ? AND layer_key = ?')
        ->execute([$leitstelleId, $layerKey]);

    if (!$tiles) {
        return ['tiles_total' => 0, 'tile_z' => $z, 'used_existing_scope' => false];
    }

    $sql = 'INSERT INTO leitstelle_tile_scope (leitstelle_id, layer_key, tile_z, tile_x, tile_y, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())';
    $ins = $pdo->prepare($sql);

    foreach ($tiles as $tile) {
        $ins->execute([
            $leitstelleId,
            $layerKey,
            (int)$tile['z'],
            (int)$tile['x'],
            (int)$tile['y'],
        ]);
    }

    return [
        'tiles_total' => count($tiles),
        'tile_z' => $z,
        'used_existing_scope' => false,
    ];
}

function lsttraining_osm_get_scope_tiles(PDO $pdo, int $leitstelleId, string $layerKey, int $offset = 0, int $limit = 100): array {
    $scopeSql = "SELECT s.layer_key, s.tile_z, s.tile_x, s.tile_y
                 FROM leitstelle_tile_scope s
                 WHERE s.leitstelle_id = ?
                   AND s.layer_key = ?
                 ORDER BY s.tile_z, s.tile_x, s.tile_y
                 LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($scopeSql);
    $stmt->bindValue(1, $leitstelleId, PDO::PARAM_INT);
    $stmt->bindValue(2, $layerKey, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $scopeTiles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$scopeTiles) {
        return [];
    }

    foreach ($scopeTiles as $tile) {
        lsttraining_osm_seed_manifest_tile_if_missing($pdo, $tile);
    }

    $out = [];
    $pickSql = "SELECT *
                FROM leitstellen_osm_layers
                WHERE layer_key = ?
                  AND tile_z = ?
                  AND tile_x = ?
                  AND tile_y = ?
                LIMIT 1";
    $pick = $pdo->prepare($pickSql);

    foreach ($scopeTiles as $tile) {
        $pick->execute([
            $tile['layer_key'],
            (int)$tile['tile_z'],
            (int)$tile['tile_x'],
            (int)$tile['tile_y'],
        ]);
        $row = $pick->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $out[] = $row;
        }
    }

    return $out;
}

function lsttraining_osm_seed_manifest_tile_if_missing(PDO $pdo, array $tile): void {
    $sql = "INSERT INTO leitstellen_osm_layers
            (layer_key, tile_z, tile_x, tile_y, source, source_version, check_status, is_dirty, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, 'scope_seed', NULL, 'seeded', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE updated_at = updated_at";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $tile['layer_key'],
        (int)$tile['tile_z'],
        (int)$tile['tile_x'],
        (int)$tile['tile_y'],
    ]);
}

function lsttraining_osm_tile_needs_download(array $tileRow, string $layerKey, bool $force = false): array {
    if ($force) {
        return [
            'needs_download' => true,
            'check_message' => 'Force-Refresh aktiviert.',
            'osm_base' => null,
        ];
    }

    $since = null;
    if (!empty($tileRow['last_checked_at'])) {
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$tileRow['last_checked_at']));
    } elseif (!empty($tileRow['last_changed_at'])) {
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$tileRow['last_changed_at']));
    }

    if (!$since) {
        return [
            'needs_download' => true,
            'check_message' => 'Kein letzter Prüfzeitpunkt vorhanden.',
            'osm_base' => null,
        ];
    }

    $bbox = lsttraining_tile_bbox((int)$tileRow['tile_z'], (int)$tileRow['tile_x'], (int)$tileRow['tile_y']);
    $layerDef = lsttraining_osm_layer_definition($layerKey);

    $query = lsttraining_osm_build_precheck_query($layerKey, $bbox, $since);
    $response = lsttraining_osm_run_overpass($query, (int)$layerDef['request_timeout']);

    $count = lsttraining_osm_extract_count_from_response($response['body'] ?? []);
    $osmBase = lsttraining_osm_extract_osm_base($response['body'] ?? []);

    return [
        'needs_download' => ($count > 0),
        'check_message' => ($count > 0)
            ? ('Änderungen gefunden: ' . $count)
            : 'Keine Änderungen seit ' . $since,
        'osm_base' => $osmBase,
    ];
}

function lsttraining_osm_build_precheck_query(string $layerKey, array $bbox, string $since): string {
    $layerDef = lsttraining_osm_layer_definition($layerKey);
    if (!$layerDef) {
        throw new RuntimeException('Layer-Konfiguration fehlt: ' . $layerKey);
    }

    $bboxStr = lsttraining_osm_bbox_string($bbox);
    $preferChanged = !empty($layerDef['precheck_prefer_changed']);

    $parts = [];
    foreach ($layerDef['queries'] as $q) {
        $selector = $q['type'] . $q['filter'] . '(' . $bboxStr . ')';
        if ($preferChanged) {
            $parts[] = $selector . '(changed:"' . $since . '")';
        } else {
            $parts[] = $selector . '(newer:"' . $since . '")';
        }
    }

    $query = "[out:json][timeout:" . (int)$layerDef['request_timeout'] . "];\n(\n  "
        . implode(";\n  ", $parts)
        . ";\n);\nout count;";

    return $query;
}

function lsttraining_osm_build_full_query(string $layerKey, array $bbox): string {
    $layerDef = lsttraining_osm_layer_definition($layerKey);
    if (!$layerDef) {
        throw new RuntimeException('Layer-Konfiguration fehlt: ' . $layerKey);
    }

    $bboxStr = lsttraining_osm_bbox_string($bbox);
    $parts = [];
    foreach ($layerDef['queries'] as $q) {
        $parts[] = $q['type'] . $q['filter'] . '(' . $bboxStr . ')';
    }

    return "[out:json][timeout:" . (int)$layerDef['request_timeout'] . "];\n(\n  "
        . implode(";\n  ", $parts)
        . ";\n);\nout body geom;";
}

function lsttraining_osm_run_overpass(string $query, int $timeout = 25): array {
    $urls = lsttraining_osm_overpass_urls();
    $lastError = 'Kein Overpass-Endpunkt verfügbar.';

    foreach ($urls as $url) {
        lsttraining_overpass_rate_gate();

        $res = wp_remote_post($url, [
            'timeout' => max(10, $timeout + 5),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
            'body' => ['data' => $query],
        ]);

        if (is_wp_error($res)) {
            $lastError = $res->get_error_message();
            continue;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $bodyRaw = (string)wp_remote_retrieve_body($res);

        if ($code >= 200 && $code < 300) {
            $json = json_decode($bodyRaw, true);
            if (!is_array($json)) {
                throw new RuntimeException('Overpass-Antwort ist kein gültiges JSON.');
            }
            return [
                'url' => $url,
                'status' => $code,
                'body' => $json,
            ];
        }

        $lastError = 'Overpass HTTP Fehler: ' . $code;
    }

    throw new RuntimeException($lastError);
}

function lsttraining_osm_download_tile(array $tileRow, string $layerKey): array {
    $bbox = lsttraining_tile_bbox((int)$tileRow['tile_z'], (int)$tileRow['tile_x'], (int)$tileRow['tile_y']);
    $layerDef = lsttraining_osm_layer_definition($layerKey);

    $query = lsttraining_osm_build_full_query($layerKey, $bbox);
    $response = lsttraining_osm_run_overpass($query, (int)$layerDef['request_timeout']);

    $features = lsttraining_osm_response_to_features($response['body'] ?? [], $layerKey);
    $write = lsttraining_osm_write_tile_file(
        $layerKey,
        (int)$tileRow['tile_z'],
        (int)$tileRow['tile_x'],
        (int)$tileRow['tile_y'],
        $features
    );

    return [
        'ok' => true,
        'sha1' => $write['sha1'],
        'feature_count' => $write['feature_count'],
        'bytes_gz' => $write['bytes_gz'],
        'file_relpath' => $write['file_relpath'],
        'osm_base' => lsttraining_osm_extract_osm_base($response['body'] ?? []),
    ];
}

function lsttraining_osm_response_to_features(array $body, string $layerKey): array {
    $elements = $body['elements'] ?? [];
    if (!is_array($elements)) {
        return [];
    }

    $features = [];
    foreach ($elements as $el) {
        if (!is_array($el) || empty($el['type'])) {
            continue;
        }

        $feature = null;

        if ($el['type'] === 'way' && !empty($el['geometry']) && is_array($el['geometry'])) {
            $coords = [];
            foreach ($el['geometry'] as $pt) {
                if (!is_array($pt) || !isset($pt['lon'], $pt['lat'])) {
                    continue;
                }
                $coords[] = [(float)$pt['lon'], (float)$pt['lat']];
            }

            if (count($coords) >= 2) {
                $isClosed = (count($coords) >= 4)
                    && ($coords[0][0] === $coords[count($coords) - 1][0])
                    && ($coords[0][1] === $coords[count($coords) - 1][1]);

                if (strpos($layerKey, 'landuse_') === 0 && $isClosed) {
                    $feature = [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Polygon',
                            'coordinates' => [$coords],
                        ],
                        'properties' => [
                            'osm_type' => 'way',
                            'osm_id' => (int)$el['id'],
                            'tags' => $el['tags'] ?? new stdClass(),
                        ],
                    ];
                } else {
                    $feature = [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => $coords,
                        ],
                        'properties' => [
                            'osm_type' => 'way',
                            'osm_id' => (int)$el['id'],
                            'tags' => $el['tags'] ?? new stdClass(),
                        ],
                    ];
                }
            }
        }

        if ($el['type'] === 'relation' && !empty($el['members']) && empty($feature)) {
            $polygons = lsttraining_osm_relation_members_to_polygons($el['members']);
            if ($polygons) {
                $feature = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'MultiPolygon',
                        'coordinates' => $polygons,
                    ],
                    'properties' => [
                        'osm_type' => 'relation',
                        'osm_id' => (int)$el['id'],
                        'tags' => $el['tags'] ?? new stdClass(),
                    ],
                ];
            }
        }

        if ($feature) {
            $features[] = $feature;
        }
    }

    return $features;
}

function lsttraining_osm_relation_members_to_polygons(array $members): array {
    $polygons = [];
    foreach ($members as $member) {
        if (!is_array($member) || ($member['type'] ?? '') !== 'way' || empty($member['geometry'])) {
            continue;
        }

        $ring = [];
        foreach ($member['geometry'] as $pt) {
            if (!is_array($pt) || !isset($pt['lon'], $pt['lat'])) {
                continue;
            }
            $ring[] = [(float)$pt['lon'], (float)$pt['lat']];
        }

        if (count($ring) >= 4) {
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ($first[0] === $last[0] && $first[1] === $last[1]) {
                $polygons[] = [$ring];
            }
        }
    }
    return $polygons;
}

function lsttraining_osm_write_tile_file(string $layerKey, int $z, int $x, int $y, array $features): array {
    $baseDir = trailingslashit(dirname(dirname(__DIR__)));

    $relPath = 'data/osm_tiles/z' . $z . '/' . $layerKey . '/' . $x . '/' . $y . '.geojsonl.gz';
    $absPath = $baseDir . $relPath;
    $absDir = dirname($absPath);

    if (!is_dir($absDir) && !wp_mkdir_p($absDir)) {
        throw new RuntimeException('Tile-Verzeichnis konnte nicht erstellt werden: ' . $absDir);
    }

    $gz = gzopen($absPath, 'wb9');
    if (!$gz) {
        throw new RuntimeException('Tile-Datei konnte nicht geschrieben werden: ' . $absPath);
    }

    $featureCount = 0;
    foreach ($features as $feature) {
        $json = json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            continue;
        }
        gzwrite($gz, $json . "\n");
        $featureCount++;
    }
    gzclose($gz);

    clearstatcache(true, $absPath);
    $bytes = (int)filesize($absPath);

    return [
        'file_relpath' => $relPath,
        'abs_path' => $absPath,
        'sha1' => lsttraining_osm_sha1_file($absPath),
        'feature_count' => $featureCount,
        'bytes_gz' => $bytes,
    ];
}

function lsttraining_osm_sha1_file(string $path): string {
    $sha1 = @sha1_file($path);
    if (!is_string($sha1) || $sha1 === '') {
        throw new RuntimeException('SHA1 konnte nicht berechnet werden: ' . $path);
    }
    return $sha1;
}

function lsttraining_osm_update_manifest_after_check(PDO $pdo, array $tileRow, array $result): void {
    $status = (string)($result['status'] ?? 'error');

    if ($status === 'unchanged') {
        $sql = "UPDATE leitstellen_osm_layers
                SET last_checked_at = NOW(),
                    last_check_source = 'overpass',
                    check_status = 'unchanged',
                    check_message = NULL,
                    etag_or_signature = :sig,
                    is_dirty = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':sig' => $result['osm_base'] ?? null,
            ':id' => (int)$tileRow['id'],
        ]);
        return;
    }

    if ($status === 'changed') {
        $sql = "UPDATE leitstellen_osm_layers
                SET source = :source,
                    sha1 = :sha1,
                    feature_count = :feature_count,
                    bytes_gz = :bytes_gz,
                    file_relpath = :file_relpath,
                    last_checked_at = NOW(),
                    last_changed_at = NOW(),
                    last_check_source = 'overpass',
                    check_status = 'changed',
                    check_message = NULL,
                    etag_or_signature = :sig,
                    is_dirty = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':source' => $result['source'] ?? 'overpass',
            ':sha1' => $result['sha1'] ?? null,
            ':feature_count' => (int)($result['feature_count'] ?? 0),
            ':bytes_gz' => (int)($result['bytes_gz'] ?? 0),
            ':file_relpath' => $result['file_relpath'] ?? null,
            ':sig' => $result['osm_base'] ?? null,
            ':id' => (int)$tileRow['id'],
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE leitstellen_osm_layers
         SET last_checked_at = NOW(),
             last_check_source = 'overpass',
             check_status = 'error',
             check_message = :msg,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $stmt->execute([
        ':msg' => mb_substr((string)($result['check_message'] ?? 'Unbekannter Fehler'), 0, 65000),
        ':id' => (int)$tileRow['id'],
    ]);
}

function lsttraining_osm_acquire_lock(PDO $pdo, int $leitstelleId, string $layerKey, string $lockToken, int $ttlSeconds = 180): bool {
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT lock_token, lock_until
             FROM leitstelle_osm_update_lock
             WHERE leitstelle_id = ? AND layer_key = ?
             FOR UPDATE'
        );
        $stmt->execute([$leitstelleId, $layerKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $lockUntilTs = strtotime((string)$row['lock_until']);
            if ($lockUntilTs !== false && $lockUntilTs > time()) {
                $pdo->rollBack();
                return false;
            }

            $upd = $pdo->prepare(
                'UPDATE leitstelle_osm_update_lock
                 SET lock_token = ?, locked_by = ?, lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = CURRENT_TIMESTAMP
                 WHERE leitstelle_id = ? AND layer_key = ?'
            );
            $upd->execute([$lockToken, (string)get_current_user_id(), $ttlSeconds, $leitstelleId, $layerKey]);
            $pdo->commit();
            return true;
        }

        $ins = $pdo->prepare(
            'INSERT INTO leitstelle_osm_update_lock
             (leitstelle_id, layer_key, lock_token, locked_by, lock_until, created_at, updated_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $ins->execute([$leitstelleId, $layerKey, $lockToken, (string)get_current_user_id(), $ttlSeconds]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lsttraining_osm_refresh_lock(PDO $pdo, int $leitstelleId, string $layerKey, string $lockToken, int $ttlSeconds = 180): void {
    $stmt = $pdo->prepare(
        'UPDATE leitstelle_osm_update_lock
         SET lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = CURRENT_TIMESTAMP
         WHERE leitstelle_id = ? AND layer_key = ? AND lock_token = ?'
    );
    $stmt->execute([$ttlSeconds, $leitstelleId, $layerKey, $lockToken]);
}

function lsttraining_osm_release_lock(PDO $pdo, int $leitstelleId, string $layerKey, string $lockToken): void {
    $stmt = $pdo->prepare(
        'DELETE FROM leitstelle_osm_update_lock
         WHERE leitstelle_id = ? AND layer_key = ? AND lock_token = ?'
    );
    $stmt->execute([$leitstelleId, $layerKey, $lockToken]);
}

function lsttraining_osm_bbox_string(array $bbox): string {
    return implode(',', [
        (float)$bbox['south'],
        (float)$bbox['west'],
        (float)$bbox['north'],
        (float)$bbox['east'],
    ]);
}

function lsttraining_osm_extract_count_from_response(array $body): int {
    $elements = $body['elements'] ?? [];
    if (!is_array($elements)) {
        return 0;
    }

    foreach ($elements as $el) {
        if (($el['type'] ?? '') === 'count') {
            $tags = $el['tags'] ?? [];
            if (isset($tags['total'])) {
                return (int)$tags['total'];
            }
            return (int)(($tags['ways'] ?? 0) + ($tags['relations'] ?? 0) + ($tags['nodes'] ?? 0));
        }
    }

    return 0;
}

function lsttraining_osm_extract_osm_base(array $body): ?string {
    $osm3s = $body['osm3s'] ?? null;
    if (is_array($osm3s) && !empty($osm3s['timestamp_osm_base'])) {
        return (string)$osm3s['timestamp_osm_base'];
    }
    return null;
}

function lsttraining_osm_overpass_urls(): array {
    $primary = get_option('lsttraining_overpass_url');
    $fallback = get_option('lsttraining_overpass_url_fallback');

    $urls = [
        (is_string($primary) && trim($primary) !== '') ? trim($primary) : 'https://overpass-api.de/api/interpreter',
        (is_string($fallback) && trim($fallback) !== '') ? trim($fallback) : 'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.openstreetmap.ru/api/interpreter',
        'https://overpass.nchc.org.tw/api/interpreter',
    ];

    return array_values(array_unique(array_filter($urls, 'strlen')));
}

function lsttraining_overpass_rate_gate(): void {
    $key = 'lsttraining_overpass_rate_gate';
    $last = (float)get_transient($key);
    $now = microtime(true);
    $minGapMs = 750;

    if ($last > 0) {
        $elapsed = ($now - $last) * 1000.0;
        if ($elapsed < $minGapMs) {
            usleep((int)(($minGapMs - $elapsed) * 1000));
        }
    }

    set_transient($key, microtime(true), 30);
}
