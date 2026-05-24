<?php
/**
 * ajax_osm_layers.php
 *
 * Overpass-basierter OSM-Refresh pro Leitstelle + Layer.
 *
 * Logik:
 * - Scope der relevanten z13-Tiles für das Einsatzgebiet sicherstellen
 * - Änderungs-Scan hierarchisch über Supertiles per out count + changed:"..."
 * - Nur bei Treffer weiter herunterbrechen bis z13
 * - Nur dirty z13-Tiles vollständig neu laden
 * - Persistenter Zustand pro leitstelle_id + layer_key in leitstelle_layer_sync_state
 * - Parallele Läufe pro leitstelle_id + layer_key über Lock verhindern
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/geo.php';
require_once dirname(__DIR__) . '/activity.php';

add_action('wp_ajax_lsttraining_osm_refresh_layer_step', 'lsttraining_osm_refresh_layer_step');

function lsttraining_osm_refresh_layer_step(): void
{
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
    $requestCursor = 0;

    try {
        @set_time_limit(15);

        $leitstelleId   = absint($_POST['leitstelle_id'] ?? 0);
        $layerKey       = sanitize_key((string)($_POST['layer'] ?? ($_POST['layer_key'] ?? '')));
        $requestCursor  = max(0, (int)($_POST['cursor'] ?? ($_POST['offset'] ?? 0)));
        $force          = !empty($_POST['force']) && (string)$_POST['force'] === '1';
        $forceScope     = !empty($_POST['force_scope_rebuild']) && (string)$_POST['force_scope_rebuild'] === '1';
        $runToken       = sanitize_text_field((string)($_POST['run_token'] ?? wp_generate_password(20, false, false)));
        $scanBudget     = max(1, min(50, (int)($_POST['scan_budget'] ?? 5)));
        $downloadBudget = max(1, min(100, (int)($_POST['chunk'] ?? ($_POST['limit'] ?? 8))));
        $endpointOffset = max(0, (int)($_POST['endpoint_offset'] ?? 0));

        if ($leitstelleId <= 0) {
            wp_send_json_error(['message' => 'Leitstelle fehlt.'], 400);
        }

        $layerDef = lsttraining_osm_layer_definition($layerKey);
        if (!$layerDef) {
            wp_send_json_error(['message' => 'Layer ungültig.'], 400);
        }

        // Keep hosted admin-ajax requests below webserver execution limits.
        $scanBudget = min($scanBudget, $layerKey === 'roads_lines' ? 1 : 2);
        $downloadBudget = min($downloadBudget, $layerKey === 'roads_lines' ? 1 : 2);

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
                'success'        => false,
                'retry_after_ms' => 1500,
                'phase'          => 'locked',
                'tiles_total'    => 0,
                'tiles_checked'  => 0,
                'tiles_changed'  => 0,
                'tiles_unchanged'=> 0,
                'tiles_errors'   => 0,
                'done'           => false,
                'next_offset'    => $requestCursor,
                'cursor'         => $requestCursor,
                'progress'       => 0,
                'feature_count'  => 0,
                'message'        => 'Layer wird bereits aktualisiert.',
            ]);
        }

        $scopeInfo = lsttraining_osm_ensure_scope_for_leitstelle_layer($pdo, $leitstelleId, $layerKey, $forceScope);
        $tilesTotal = (int)($scopeInfo['tiles_total'] ?? 0);

        if ($tilesTotal <= 0) {
            lsttraining_osm_reset_sync_state($pdo, $leitstelleId, $layerKey, null);

            try {
                lsttraining_osm_release_lock($pdo, $leitstelleId, $layerKey, $lockToken);
            } catch (Throwable $releaseEx) {
                error_log('[LSTtraining] release_lock on empty scope failed: ' . $releaseEx->getMessage());
            }
            $lockAcquired = false;

            wp_send_json_success([
                'phase'         => 'idle',
                'tiles_total'   => 0,
                'tiles_checked' => 0,
                'tiles_changed' => 0,
                'tiles_unchanged' => 0,
                'tiles_errors'  => 0,
                'done'          => true,
                'next_offset'   => 0,
                'cursor'        => 0,
                'progress'      => 100,
                'feature_count' => 0,
                'final'         => [
                    'used_cache' => true,
                    'unchanged'  => true,
                ],
                'message'       => 'Keine relevanten Tiles im Einsatzgebiet gefunden.',
            ]);
        }

        if ($force) {
            lsttraining_osm_reset_sync_state($pdo, $leitstelleId, $layerKey, null);
            lsttraining_osm_clear_dirty_for_scope($pdo, $leitstelleId, $layerKey);
        }

        $state = lsttraining_osm_get_sync_state($pdo, $leitstelleId, $layerKey);
        if (!$state || $force || (($state['phase'] ?? 'idle') === 'idle')) {
            $state = lsttraining_osm_initialize_sync_state($pdo, $leitstelleId, $layerKey, $layerDef, $force);
        }

        $phase = (string)($state['phase'] ?? 'idle');

        if ($phase === 'scan') {
            $result = lsttraining_osm_process_scan_step(
                $pdo,
                $leitstelleId,
                $layerKey,
                $layerDef,
                $state,
                $scanBudget,
                $lockToken,
                $endpointOffset
            );
        } elseif ($phase === 'download') {
            $result = lsttraining_osm_process_download_step(
                $pdo,
                $leitstelleId,
                $layerKey,
                $layerDef,
                $state,
                $downloadBudget,
                $lockToken,
                $endpointOffset
            );
        } else {
            $result = [
                'phase'         => 'idle',
                'done'          => true,
                'tiles_total'   => $tilesTotal,
                'tiles_checked' => 0,
                'tiles_changed' => 0,
                'tiles_unchanged' => 0,
                'tiles_errors'  => 0,
                'next_offset'   => 0,
                'cursor'        => 0,
                'progress'      => 100,
                'feature_count' => 0,
                'message'       => 'Kein aktiver Sync-Lauf vorhanden.',
                'final'         => [
                    'used_cache' => true,
                    'unchanged'  => true,
                ],
            ];
        }

        $pdo = lsttraining_get_connection();
        if (!$pdo) {
            throw new RuntimeException('DB-Verbindung nach Verarbeitung nicht verfügbar.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            lsttraining_osm_release_lock($pdo, $leitstelleId, $layerKey, $lockToken);
        } catch (Throwable $releaseEx) {
            error_log('[LSTtraining] release_lock after success failed: ' . $releaseEx->getMessage());
        }
        $lockAcquired = false;

        wp_send_json_success($result);
    } catch (Throwable $e) {
        if ($lockAcquired && $leitstelleId > 0 && $layerKey !== '' && $lockToken !== '') {
            try {
                lsttraining_osm_release_lock($pdo, $leitstelleId, $layerKey, $lockToken);
            } catch (Throwable $releaseEx) {
                error_log('[LSTtraining] release_lock in catch failed: ' . $releaseEx->getMessage());
            }
        }

        if ($leitstelleId > 0 && $layerKey !== '') {
            try {
                $pdoErr = lsttraining_get_connection();
                if ($pdoErr instanceof PDO) {
                    lsttraining_osm_set_sync_error($pdoErr, $leitstelleId, $layerKey, $e->getMessage());
                }
            } catch (Throwable $syncErrEx) {
                error_log('[LSTtraining] set_sync_error failed: ' . $syncErrEx->getMessage());
            }
        }

        $msg = $e->getMessage();
        $retryAfterMs = lsttraining_osm_extract_retry_after_ms_from_message($msg);

        if (strpos($msg, 'HTTP 429') !== false) {
            wp_send_json_success([
                'success'        => false,
                'retry_after_ms' => $retryAfterMs,
                'phase'          => 'rate_limited',
                'tiles_total'    => 0,
                'tiles_checked'  => 0,
                'tiles_changed'  => 0,
                'tiles_unchanged'=> 0,
                'tiles_errors'   => 0,
                'done'           => false,
                'next_offset'    => $requestCursor,
                'cursor'         => $requestCursor,
                'progress'       => 0,
                'feature_count'  => 0,
                'message'        => 'Overpass Rate Limit erreicht. Bitte kurz warten.',
            ]);
        }

        wp_send_json_error([
            'message' => $msg,
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ], 500);
    }
}

function lsttraining_osm_process_scan_step(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    array $layerDef,
    array $state,
    int $scanBudget,
    string $lockToken,
    int $endpointOffset = 0
): array {
    $pdoRead = lsttraining_get_connection();
    if (!$pdoRead instanceof PDO) {
        throw new RuntimeException('process_scan_step: DB-Verbindung für Scope-Lesen fehlgeschlagen.');
    }
    $pdoRead->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $scopeTiles = lsttraining_osm_get_scope_tiles_all($pdoRead, $leitstelleId, $layerKey);
    $scopeTotal = count($scopeTiles);

    if ($scopeTotal <= 0) {
        $pdoWrite = lsttraining_get_connection();
        if ($pdoWrite instanceof PDO) {
            $pdoWrite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            lsttraining_osm_reset_sync_state($pdoWrite, $leitstelleId, $layerKey, null);
        }

        return [
            'phase'         => 'idle',
            'done'          => true,
            'tiles_total'   => 0,
            'tiles_checked' => 0,
            'tiles_changed' => 0,
            'tiles_unchanged' => 0,
            'tiles_errors'  => 0,
            'next_offset'   => 0,
            'cursor'        => 0,
            'progress'      => 100,
            'feature_count' => 0,
            'message'       => 'Keine Scope-Tiles vorhanden.',
            'final'         => [
                'used_cache' => true,
                'unchanged'  => true,
            ],
        ];
    }

    $startZ = (int)($state['scan_start_z'] ?? ($layerDef['precheck_start_z'] ?? 10));
    $minZ = max(0, min(13, $startZ));
    $queue = is_array($state['scan_cursor_json']) ? $state['scan_cursor_json'] : [];

    if (!$queue) {
        $parents = lsttraining_osm_group_scope_tiles_by_parent($scopeTiles, $minZ);
        foreach ($parents as $parent) {
            $queue[] = $parent;
        }
    }

    $tree = lsttraining_osm_build_scope_tree($scopeTiles, $minZ);

    $processed = 0;
    $hits = 0;
    $misses = 0;
    $errors = 0;
    $latestOsmBase = (string)($state['scan_osm_base'] ?? '');

    $startedAt = microtime(true);
    $maxWallSeconds = 8.0;
    $retryAfterMs = 0;
    $stopEarly = false;
    $stopReason = '';

    while ($processed < $scanBudget && !empty($queue)) {
        $node = array_shift($queue);
        $processed++;

        if ((microtime(true) - $startedAt) >= $maxWallSeconds) {
            $stopEarly = true;
            $stopReason = 'walltime';
            break;
        }

        if (($processed % 5) === 0) {
            try {
                $pdoLock = lsttraining_get_connection();
                if ($pdoLock instanceof PDO) {
                    $pdoLock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    lsttraining_osm_refresh_lock($pdoLock, $leitstelleId, $layerKey, $lockToken, 180);
                }
            } catch (Throwable $lockEx) {
                error_log('[LSTtraining] refresh_lock during scan failed: ' . $lockEx->getMessage());
            }
        }

        $bbox = lsttraining_tile_bbox((int)$node['z'], (int)$node['x'], (int)$node['y']);

        try {
            $check = lsttraining_osm_super_tile_has_changes(
                $layerKey,
                $bbox,
                (string)$state['scan_since'],
                (int)($layerDef['count_timeout'] ?? 20),
                (int)($layerDef['count_request_gap_ms'] ?? 1500),
                $endpointOffset
            );

            if (!empty($check['osm_base'])) {
                $latestOsmBase = (string)$check['osm_base'];
            }

            if ((int)$check['count'] <= 0) {
                $misses++;
                continue;
            }

            $hits++;

            if ((int)$node['z'] >= (int)$layerDef['tile_z']) {
                $pdoDirty = lsttraining_get_connection();
                if (!$pdoDirty instanceof PDO) {
                    throw new RuntimeException('process_scan_step: DB-Reconnect für dirty-Flag fehlgeschlagen.');
                }
                $pdoDirty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                lsttraining_osm_mark_tile_dirty(
                    $pdoDirty,
                    $layerKey,
                    (int)$node['z'],
                    (int)$node['x'],
                    (int)$node['y'],
                    $latestOsmBase !== '' ? $latestOsmBase : null,
                    'Änderung per hierarchischem Count-Scan erkannt.'
                );
                continue;
            }

            $children = lsttraining_osm_expand_parent_to_children($node, (int)$node['z'] + 1, $tree);
            foreach ($children as $child) {
                $queue[] = $child;
            }
        } catch (Throwable $scanError) {
            $errors++;
            $msg = (string)$scanError->getMessage();
            error_log('[LSTtraining] process_scan_step node error: ' . $msg);

            if (strpos($msg, 'HTTP 429') !== false) {
                $retryAfterMs = lsttraining_osm_extract_retry_after_ms_from_message($msg);
                $stopEarly = true;
                $stopReason = 'rate_limit';
                array_unshift($queue, $node);
                break;
            }

            if (lsttraining_osm_is_transient_overpass_error($msg)) {
				$retryAfterMs = max($retryAfterMs, 8000);
				$stopEarly = true;
				$stopReason = 'transport_error';
				array_unshift($queue, $node);
				break;
			}
        }
    }

    $queueRemaining = count($queue);

    if ($stopEarly) {
        $pdoState = lsttraining_get_connection();
        if (!$pdoState instanceof PDO) {
            throw new RuntimeException('process_scan_step: DB-Reconnect für State nach Stop fehlgeschlagen.');
        }
        $pdoState->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dirtyTotal = lsttraining_osm_count_dirty_tiles($pdoState, $leitstelleId, $layerKey);
        $state['scan_cursor_json'] = $queue;

        if ($latestOsmBase !== '') {
            $state['scan_osm_base'] = $latestOsmBase;
        }

        lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

        $totalScanNodes = max(1, $processed + $queueRemaining);
        $progress = (int)floor((($totalScanNodes - $queueRemaining) / $totalScanNodes) * 49);

        $message = 'Scan pausiert, wird im nächsten Schritt fortgesetzt.';
        if ($stopReason === 'rate_limit') {
            $message = 'Overpass-Limit erreicht, Scan wird später fortgesetzt.';
        } elseif ($stopReason === 'timeout') {
			$message = 'Overpass-Timeout, Scan wird später fortgesetzt.';
		} elseif ($stopReason === 'transport_error') {
			$message = 'Overpass-Verbindung unterbrochen, Scan wird später fortgesetzt.';
		} elseif ($stopReason === 'walltime') {
            $message = 'Scan-Step beendet, um Request-Laufzeit kurz zu halten.';
        }

        return [
            'phase'         => 'scan',
            'tiles_total'   => $scopeTotal,
            'tiles_checked' => $processed,
            'tiles_changed' => $hits,
            'tiles_unchanged' => $misses,
            'tiles_errors'  => $errors,
            'done'          => false,
            'next_offset'   => $queueRemaining,
            'cursor'        => $queueRemaining,
            'progress'      => max(1, min(49, $progress)),
            'feature_count' => 0,
            'dirty_total'   => $dirtyTotal,
            'dirty_done'    => (int)($state['dirty_done'] ?? 0),
            'retry_after_ms'=> $retryAfterMs > 0 ? $retryAfterMs : 4000,
            'message'       => $message,
        ];
    }

    $pdoState = lsttraining_get_connection();
    if (!$pdoState instanceof PDO) {
        throw new RuntimeException('process_scan_step: DB-Reconnect für State fehlgeschlagen.');
    }
    $pdoState->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dirtyTotal = lsttraining_osm_count_dirty_tiles($pdoState, $leitstelleId, $layerKey);
    $state['scan_cursor_json'] = $queue;

    if ($latestOsmBase !== '') {
        $state['scan_osm_base'] = $latestOsmBase;
    }

    if ($queueRemaining === 0) {
        $state['phase'] = 'download';
        $state['dirty_total'] = $dirtyTotal;
        $state['dirty_done'] = 0;
        lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

        if ($dirtyTotal <= 0) {
            $state['phase'] = 'idle';
            $state['last_success_at'] = gmdate('Y-m-d H:i:s');
            if (!empty($state['scan_osm_base'])) {
                $state['last_success_osm_base'] = $state['scan_osm_base'];
            }
            $state['dirty_total'] = 0;
            $state['dirty_done'] = 0;
            $state['scan_cursor_json'] = [];
            $state['last_error'] = null;

            lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

            return [
                'phase'         => 'idle',
                'tiles_total'   => $scopeTotal,
                'tiles_checked' => $processed,
                'tiles_changed' => 0,
                'tiles_unchanged' => $scopeTotal,
                'tiles_errors'  => $errors,
                'done'          => true,
                'next_offset'   => 0,
                'cursor'        => 0,
                'progress'      => 100,
                'feature_count' => 0,
                'final'         => [
                    'used_cache' => true,
                    'unchanged'  => true,
                ],
                'message'       => sprintf('Scan abgeschlossen, keine Änderungen gefunden. %d Knoten geprüft.', $processed),
            ];
        }

        return [
            'phase'         => 'download',
            'tiles_total'   => $scopeTotal,
            'tiles_checked' => $processed,
            'tiles_changed' => $dirtyTotal,
            'tiles_unchanged' => max(0, $scopeTotal - $dirtyTotal),
            'tiles_errors'  => $errors,
            'done'          => false,
            'next_offset'   => 0,
            'cursor'        => 0,
            'progress'      => 50,
            'feature_count' => 0,
            'dirty_total'   => $dirtyTotal,
            'dirty_done'    => 0,
            'message'       => sprintf('Scan abgeschlossen. %d dirty Tiles erkannt, Download startet.', $dirtyTotal),
        ];
    }

    lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

    $totalScanNodes = max(1, $processed + $queueRemaining);
    $progress = (int)floor((($totalScanNodes - $queueRemaining) / $totalScanNodes) * 49);

    return [
        'phase'         => 'scan',
        'tiles_total'   => $scopeTotal,
        'tiles_checked' => $processed,
        'tiles_changed' => $hits,
        'tiles_unchanged' => $misses,
        'tiles_errors'  => $errors,
        'done'          => false,
        'next_offset'   => $queueRemaining,
        'cursor'        => $queueRemaining,
        'progress'      => max(1, min(49, $progress)),
        'feature_count' => 0,
        'dirty_total'   => $dirtyTotal,
        'dirty_done'    => (int)($state['dirty_done'] ?? 0),
        'message'       => sprintf('Scan läuft: %d Knoten geprüft, %d verbleibend, %d Treffer.', $processed, $queueRemaining, $hits),
    ];
}

function lsttraining_osm_process_download_step(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    array $layerDef,
    array $state,
    int $downloadBudget,
    string $lockToken,
    int $endpointOffset = 0
): array {
    $initialDownload = !empty($state['scan_cursor_json']['initial_download_pending']);
    $pdoRead = lsttraining_get_connection();
    if (!$pdoRead instanceof PDO) {
        throw new RuntimeException('process_download_step: DB-Verbindung für dirty-Tiles fehlgeschlagen.');
    }
    $pdoRead->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dirtyTiles = lsttraining_osm_get_dirty_tiles($pdoRead, $leitstelleId, $layerKey, 0, $downloadBudget);
    $scopeTilesTotal = lsttraining_osm_count_scope_tiles($pdoRead, $leitstelleId, $layerKey);

    if (!$dirtyTiles) {
        $pdoDone = lsttraining_get_connection();
        if (!$pdoDone instanceof PDO) {
            throw new RuntimeException('process_download_step: DB-Reconnect für Abschluss fehlgeschlagen.');
        }
        $pdoDone->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($initialDownload) {
            $state['phase'] = 'scan';
            $state['dirty_total'] = 0;
            $state['dirty_done'] = 0;
            $state['scan_cursor_json'] = [];
            lsttraining_osm_upsert_sync_state($pdoDone, $leitstelleId, $layerKey, $state);

            return [
                'phase' => 'scan',
                'tiles_total' => $scopeTilesTotal,
                'tiles_checked' => 0,
                'tiles_changed' => 0,
                'tiles_unchanged' => 0,
                'tiles_errors' => 0,
                'done' => false,
                'next_offset' => 0,
                'cursor' => 0,
                'progress' => 0,
                'feature_count' => 0,
                'dirty_total' => 0,
                'dirty_done' => 0,
                'message' => 'Initialdownload abgeschlossen. Änderungsscan startet.',
            ];
        }

        $state['phase'] = 'idle';
        $state['last_success_at'] = gmdate('Y-m-d H:i:s');
        if (!empty($state['scan_osm_base'])) {
            $state['last_success_osm_base'] = $state['scan_osm_base'];
        }
        $state['dirty_total'] = 0;
        $state['dirty_done'] = 0;
        $state['scan_cursor_json'] = [];
        $state['last_error'] = null;

        lsttraining_osm_upsert_sync_state($pdoDone, $leitstelleId, $layerKey, $state);

        return [
            'phase' => 'idle',
            'tiles_total' => $scopeTilesTotal,
            'tiles_checked' => 0,
            'tiles_changed' => 0,
            'tiles_unchanged' => $scopeTilesTotal,
            'tiles_errors' => 0,
            'done' => true,
            'next_offset' => 0,
            'cursor' => 0,
            'progress' => 100,
            'feature_count' => 0,
            'dirty_total' => 0,
            'dirty_done' => 0,
            'final' => [
                'used_cache' => false,
                'unchanged' => false,
            ],
            'message' => 'Download abgeschlossen.',
        ];
    }

    $tilesChecked = 0;
    $tilesChanged = 0;
    $tilesUnchanged = 0;
    $tilesErrors = 0;
    $featureCount = 0;

    $startedAt = microtime(true);
    $maxWallSeconds = 8.0;
    $stopEarly = false;
    $stopReason = '';
    $retryAfterMs = 0;

    foreach ($dirtyTiles as $tileRow) {
        $tilesChecked++;

        if ((microtime(true) - $startedAt) >= $maxWallSeconds) {
            $stopEarly = true;
            $stopReason = 'walltime';
            break;
        }

        if (($tilesChecked % 2) === 0) {
            try {
                $pdoLock = lsttraining_get_connection();
                if ($pdoLock instanceof PDO) {
                    $pdoLock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    lsttraining_osm_refresh_lock($pdoLock, $leitstelleId, $layerKey, $lockToken, 180);
                }
            } catch (Throwable $lockEx) {
                error_log('[LSTtraining] refresh_lock during download failed: ' . $lockEx->getMessage());
            }
        }

        try {
            $download = lsttraining_osm_download_tile($tileRow, $layerKey, $endpointOffset);
            if (empty($download['ok'])) {
                throw new RuntimeException((string)($download['message'] ?? 'Tile-Download fehlgeschlagen.'));
            }

            if (($download['status'] ?? 'changed') === 'unchanged') {
                $tilesUnchanged++;
            } else {
                $tilesChanged++;
            }

            $featureCount += (int)($download['feature_count'] ?? 0);
            $state['dirty_done'] = (int)($state['dirty_done'] ?? 0) + 1;

            $pdoWrite = lsttraining_get_connection();
            if (!$pdoWrite instanceof PDO) {
                throw new RuntimeException('process_download_step: DB-Reconnect für Manifest-Update fehlgeschlagen.');
            }
            $pdoWrite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            lsttraining_osm_update_manifest_after_check($pdoWrite, $tileRow, [
                'status' => (string)($download['status'] ?? 'changed'),
                'sha1' => $download['sha1'] ?? null,
                'feature_count' => $download['feature_count'] ?? 0,
                'bytes_gz' => $download['bytes_gz'] ?? 0,
                'file_relpath' => $download['file_relpath'] ?? null,
                'osm_base' => $download['osm_base'] ?? ($state['scan_osm_base'] ?? null),
                'source' => 'overpass',
                'check_message' => null,
            ]);
        } catch (Throwable $tileError) {
            $msg = (string)$tileError->getMessage();
            error_log('[LSTtraining] process_download_step tile error: ' . $msg);

            if (
                strpos($msg, 'HTTP 429') !== false ||
                lsttraining_osm_is_transient_overpass_error($msg)
            ) {
                $stopEarly = true;
                $stopReason = 'transport_error';

                $retryAfterMs = max($retryAfterMs, 8000);
                if (strpos($msg, 'HTTP 429') !== false) {
                    $retryAfterMs = lsttraining_osm_extract_retry_after_ms_from_message($msg);
                }

                break;
            }

            $tilesErrors++;

            try {
                $pdoErr = lsttraining_get_connection();
                if ($pdoErr instanceof PDO) {
                    $pdoErr->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    lsttraining_osm_update_manifest_after_check($pdoErr, $tileRow, [
                        'status' => 'error',
                        'check_message' => $msg,
                    ]);
                }
            } catch (Throwable $manifestErr) {
                error_log('[LSTtraining] process_download_step error-state update failed: ' . $manifestErr->getMessage());
            }
        }
    }

    $pdoState = lsttraining_get_connection();
    if (!$pdoState instanceof PDO) {
        throw new RuntimeException('process_download_step: DB-Reconnect für State fehlgeschlagen.');
    }
    $pdoState->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $remaining = lsttraining_osm_count_dirty_tiles($pdoState, $leitstelleId, $layerKey);
    $dirtyTotal = max((int)($state['dirty_total'] ?? 0), (int)($state['dirty_done'] ?? 0) + $remaining);
    $state['dirty_total'] = $dirtyTotal;

    if ($stopEarly) {
        lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

        $progress = 50 + (int)floor((((int)$state['dirty_done']) / max(1, $dirtyTotal)) * 49);

        $message = $initialDownload
            ? 'Initialdownload-Step beendet, um Request-Laufzeit kurz zu halten.'
            : 'Download-Step beendet, um Request-Laufzeit kurz zu halten.';
        if ($stopReason === 'transport_error') {
            $message = $initialDownload
                ? 'Overpass-Verbindung unterbrochen, Initialdownload wird später fortgesetzt.'
                : 'Overpass-Verbindung unterbrochen, Download wird später fortgesetzt.';
        }

        return [
            'phase' => 'download',
            'tiles_total' => $scopeTilesTotal,
            'tiles_checked' => $tilesChecked,
            'tiles_changed' => $tilesChanged,
            'tiles_unchanged' => $tilesUnchanged,
            'tiles_errors' => $tilesErrors,
            'done' => false,
            'next_offset' => $remaining,
            'cursor' => $remaining,
            'progress' => max(50, min(99, $progress)),
            'feature_count' => $featureCount,
            'dirty_total' => $dirtyTotal,
            'dirty_done' => (int)$state['dirty_done'],
            'initial_download' => $initialDownload,
            'retry_after_ms' => $retryAfterMs > 0 ? $retryAfterMs : 4000,
            'message' => $message,
        ];
    }

    lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

    if ($remaining <= 0) {
        if ($initialDownload) {
            $state['phase'] = 'scan';
            $state['dirty_total'] = 0;
            $state['dirty_done'] = 0;
            $state['scan_cursor_json'] = [];
            $state['last_error'] = null;

            lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

            return [
                'phase' => 'scan',
                'tiles_total' => $scopeTilesTotal,
                'tiles_checked' => $tilesChecked,
                'tiles_changed' => $tilesChanged,
                'tiles_unchanged' => $tilesUnchanged,
                'tiles_errors' => $tilesErrors,
                'done' => false,
                'next_offset' => 0,
                'cursor' => 0,
                'progress' => 0,
                'feature_count' => $featureCount,
                'dirty_total' => 0,
                'dirty_done' => 0,
                'message' => sprintf(
                    'Initialdownload abgeschlossen. %d Tiles geändert, %d unverändert, %d Fehler. Änderungsscan startet.',
                    $tilesChanged,
                    $tilesUnchanged,
                    $tilesErrors
                ),
            ];
        }

        $state['phase'] = 'idle';
        $state['last_success_at'] = gmdate('Y-m-d H:i:s');
        if (!empty($state['scan_osm_base'])) {
            $state['last_success_osm_base'] = $state['scan_osm_base'];
        }
        $state['dirty_total'] = 0;
        $state['dirty_done'] = 0;
        $state['scan_cursor_json'] = [];
        $state['last_error'] = null;

        lsttraining_osm_upsert_sync_state($pdoState, $leitstelleId, $layerKey, $state);

        return [
            'phase' => 'idle',
            'tiles_total' => $scopeTilesTotal,
            'tiles_checked' => $tilesChecked,
            'tiles_changed' => $tilesChanged,
            'tiles_unchanged' => $tilesUnchanged,
            'tiles_errors' => $tilesErrors,
            'done' => true,
            'next_offset' => 0,
            'cursor' => 0,
            'progress' => 100,
            'feature_count' => $featureCount,
            'dirty_total' => 0,
            'dirty_done' => 0,
            'final' => [
                'used_cache' => false,
                'unchanged' => false,
            ],
            'message' => sprintf(
                'Download abgeschlossen. %d Tiles geändert, %d unverändert, %d Fehler.',
                $tilesChanged,
                $tilesUnchanged,
                $tilesErrors
            ),
        ];
    }

    $progress = 50 + (int)floor((((int)$state['dirty_done']) / max(1, $dirtyTotal)) * 49);

    return [
        'phase' => 'download',
        'tiles_total' => $scopeTilesTotal,
        'tiles_checked' => $tilesChecked,
        'tiles_changed' => $tilesChanged,
        'tiles_unchanged' => $tilesUnchanged,
        'tiles_errors' => $tilesErrors,
        'done' => false,
        'next_offset' => $remaining,
        'cursor' => $remaining,
        'progress' => max(50, min(99, $progress)),
        'feature_count' => $featureCount,
        'dirty_total' => $dirtyTotal,
        'dirty_done' => (int)$state['dirty_done'],
        'initial_download' => $initialDownload,
        'message' => $initialDownload
            ? sprintf(
                'Initialdownload läuft: %d von %d fehlenden Tiles verarbeitet, %d verbleibend.',
                (int)$state['dirty_done'],
                $dirtyTotal,
                $remaining
            )
            : sprintf(
                'Download läuft: %d von %d dirty Tiles verarbeitet, %d verbleibend.',
                (int)$state['dirty_done'],
                $dirtyTotal,
                $remaining
            ),
    ];
}

function lsttraining_osm_initialize_sync_state(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    array $layerDef,
    bool $force = false
): array {
    lsttraining_osm_clear_dirty_for_scope($pdo, $leitstelleId, $layerKey);
    $missingTiles = $layerKey === 'roads_lines'
        ? lsttraining_osm_mark_missing_scope_tiles_dirty($pdo, $leitstelleId, $layerKey)
        : 0;

    $existing = lsttraining_osm_get_sync_state($pdo, $leitstelleId, $layerKey);
    $scanSince = null;

    if (!$force && !empty($existing['last_success_osm_base'])) {
        $scanSince = (string)$existing['last_success_osm_base'];
    } elseif (!$force && !empty($existing['last_success_at'])) {
        $ts = strtotime((string)$existing['last_success_at']);
        if ($ts !== false) {
            $scanSince = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
    }

    if (!$scanSince) {
        $scanSince = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * DAY_IN_SECONDS);
    }

    $state = [
        'phase'                 => $missingTiles > 0 ? 'download' : 'scan',
        'scan_since'            => $scanSince,
        'scan_osm_base'         => null,
        'scan_start_z'          => (int)($layerDef['precheck_start_z'] ?? 10),
        'scan_cursor_json'      => $missingTiles > 0 ? ['initial_download_pending' => true] : [],
        'dirty_total'           => $missingTiles,
        'dirty_done'            => 0,
        'last_error'            => null,
        'last_success_at'       => $existing['last_success_at'] ?? null,
        'last_success_osm_base' => $existing['last_success_osm_base'] ?? null,
    ];

    lsttraining_osm_upsert_sync_state($pdo, $leitstelleId, $layerKey, $state);
    return lsttraining_osm_get_sync_state($pdo, $leitstelleId, $layerKey);
}

function lsttraining_osm_assert_required_tables(PDO $pdo): void
{
    $needed = [
        'leitstellen',
        'leitstellen_osm_layers',
        'leitstelle_tile_scope',
        'leitstelle_osm_update_lock',
        'leitstelle_layer_sync_state',
    ];

    foreach ($needed as $table) {
        if (!lsttraining_osm_table_exists($pdo, $table)) {
            throw new RuntimeException('DB-Fehler: Tabelle ' . $table . ' fehlt.');
        }
    }
}

function lsttraining_osm_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn() > 0);
}

function lsttraining_osm_layer_definition(string $layerKey): array
{
    if ($layerKey === 'roads_lines') {
        return [
            'layer_key'               => $layerKey,
            'tile_z'                  => 13,
            'geometry_type'           => 'line',
            'request_timeout'         => 30,
            'count_timeout'           => 12,
            'download_timeout'        => 30,
            'precheck_start_z'        => 11,
            'count_request_gap_ms'    => 1800,
            'download_request_gap_ms' => 3000,
            'out_mode'                => 'geom',
            'queries'                 => [
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
            'layer_key'               => $layerKey,
            'tile_z'                  => 13,
            'geometry_type'           => 'polygon',
            'request_timeout'         => 30,
            'count_timeout'           => 20,
            'download_timeout'        => 30,
            'precheck_start_z'        => 10,
            'count_request_gap_ms'    => 1500,
            'download_request_gap_ms' => 2800,
            'out_mode'                => 'geom',
            'queries'                 => [
                ['type' => 'way', 'filter' => '[landuse=' . $value . ']'],
                ['type' => 'relation', 'filter' => '[landuse=' . $value . ']'],
            ],
        ];
    }

    return [];
}

function lsttraining_osm_get_manifest_zoom(PDO $pdo, string $layerKey, int $fallback): int
{
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

function lsttraining_osm_ensure_scope_for_leitstelle_layer(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    bool $forceRebuild = false
): array {
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
                'tiles_total'         => $existing,
                'tile_z'              => lsttraining_osm_get_manifest_zoom($pdo, $layerKey, (int)$layerDef['tile_z']),
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
        return [
            'tiles_total'         => 0,
            'tile_z'              => $z,
            'used_existing_scope' => false,
        ];
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
        'tiles_total'         => count($tiles),
        'tile_z'              => $z,
        'used_existing_scope' => false,
    ];
}

function lsttraining_osm_get_scope_tiles(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    int $offset = 0,
    int $limit = 100
): array {
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

function lsttraining_osm_get_scope_tiles_all(PDO $pdo, int $leitstelleId, string $layerKey): array
{
    $count = lsttraining_osm_count_scope_tiles($pdo, $leitstelleId, $layerKey);
    if ($count <= 0) {
        return [];
    }
    return lsttraining_osm_get_scope_tiles($pdo, $leitstelleId, $layerKey, 0, $count);
}

function lsttraining_osm_count_scope_tiles(PDO $pdo, int $leitstelleId, string $layerKey): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM leitstelle_tile_scope
         WHERE leitstelle_id = ? AND layer_key = ?'
    );
    $stmt->execute([$leitstelleId, $layerKey]);
    return (int)$stmt->fetchColumn();
}

function lsttraining_osm_seed_manifest_tile_if_missing(PDO $pdo, array $tile): void
{
    $sql = "INSERT INTO leitstellen_osm_layers
            (layer_key, tile_z, tile_x, tile_y, source, source_version, check_status, is_dirty, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, 'scope_seed', NULL, 'seeded', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE updated_at = updated_at";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $tile['layer_key'],
        (int)$tile['tile_z'],
        (int)$tile['tile_x'],
        (int)$tile['tile_y'],
    ]);
}

function lsttraining_osm_get_sync_state(PDO $pdo, int $leitstelleId, string $layerKey): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM leitstelle_layer_sync_state
         WHERE leitstelle_id = ? AND layer_key = ?
         LIMIT 1'
    );
    $stmt->execute([$leitstelleId, $layerKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [];
    }

    $row['scan_cursor_json'] = json_decode((string)($row['scan_cursor_json'] ?? '[]'), true);
    if (!is_array($row['scan_cursor_json'])) {
        $row['scan_cursor_json'] = [];
    }

    return $row;
}

function lsttraining_osm_upsert_sync_state(PDO $pdo, int $leitstelleId, string $layerKey, array $state): void
{
    $sql = "INSERT INTO leitstelle_layer_sync_state
            (leitstelle_id, layer_key, phase, scan_since, scan_osm_base, scan_start_z, scan_cursor_json,
             dirty_total, dirty_done, last_success_at, last_success_osm_base, last_error, created_at, updated_at)
            VALUES
            (:leitstelle_id, :layer_key, :phase, :scan_since, :scan_osm_base, :scan_start_z, :scan_cursor_json,
             :dirty_total, :dirty_done, :last_success_at, :last_success_osm_base, :last_error, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
             phase = VALUES(phase),
             scan_since = VALUES(scan_since),
             scan_osm_base = VALUES(scan_osm_base),
             scan_start_z = VALUES(scan_start_z),
             scan_cursor_json = VALUES(scan_cursor_json),
             dirty_total = VALUES(dirty_total),
             dirty_done = VALUES(dirty_done),
             last_success_at = VALUES(last_success_at),
             last_success_osm_base = VALUES(last_success_osm_base),
             last_error = VALUES(last_error),
             updated_at = CURRENT_TIMESTAMP";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':leitstelle_id'       => $leitstelleId,
        ':layer_key'           => $layerKey,
        ':phase'               => (string)($state['phase'] ?? 'idle'),
        ':scan_since'          => $state['scan_since'] ?? null,
        ':scan_osm_base'       => $state['scan_osm_base'] ?? null,
        ':scan_start_z'        => isset($state['scan_start_z']) ? (int)$state['scan_start_z'] : null,
        ':scan_cursor_json'    => wp_json_encode($state['scan_cursor_json'] ?? []),
        ':dirty_total'         => (int)($state['dirty_total'] ?? 0),
        ':dirty_done'          => (int)($state['dirty_done'] ?? 0),
        ':last_success_at'     => $state['last_success_at'] ?? null,
        ':last_success_osm_base' => $state['last_success_osm_base'] ?? null,
        ':last_error'          => $state['last_error'] ?? null,
    ]);
}

function lsttraining_osm_reset_sync_state(PDO $pdo, int $leitstelleId, string $layerKey, ?string $error = null): void
{
    $existing = lsttraining_osm_get_sync_state($pdo, $leitstelleId, $layerKey);
    if (!$existing) {
        return;
    }

    $existing['phase'] = 'idle';
    $existing['scan_cursor_json'] = [];
    $existing['dirty_total'] = 0;
    $existing['dirty_done'] = 0;
    $existing['last_error'] = $error;

    lsttraining_osm_upsert_sync_state($pdo, $leitstelleId, $layerKey, $existing);
}

function lsttraining_osm_set_sync_error(PDO $pdo, int $leitstelleId, string $layerKey, string $error): void
{
    $run = static function ($pdoConn) use ($leitstelleId, $layerKey, $error): void {
        if (!$pdoConn instanceof PDO) {
            throw new RuntimeException('set_sync_error: keine gültige PDO-Verbindung');
        }

        $state = lsttraining_osm_get_sync_state($pdoConn, $leitstelleId, $layerKey);
        if (!$state) {
            return;
        }

        $state['last_error'] = function_exists('mb_substr')
            ? mb_substr($error, 0, 65000)
            : substr($error, 0, 65000);

        lsttraining_osm_upsert_sync_state($pdoConn, $leitstelleId, $layerKey, $state);
    };

    try {
        $run($pdo);
    } catch (Throwable $e) {
        error_log('[LSTtraining] set_sync_error first try failed: ' . $e->getMessage());

        $pdo2 = lsttraining_get_connection();
        if (!$pdo2 instanceof PDO) {
            throw new RuntimeException('set_sync_error: Reconnect fehlgeschlagen');
        }
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $run($pdo2);
    }
}

function lsttraining_osm_clear_dirty_for_scope(PDO $pdo, int $leitstelleId, string $layerKey): void
{
    $sql = "UPDATE leitstellen_osm_layers m
            INNER JOIN leitstelle_tile_scope s
                ON s.layer_key = m.layer_key
               AND s.tile_z = m.tile_z
               AND s.tile_x = m.tile_x
               AND s.tile_y = m.tile_y
            SET m.is_dirty = 0,
                m.updated_at = CURRENT_TIMESTAMP
            WHERE s.leitstelle_id = ?
              AND s.layer_key = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leitstelleId, $layerKey]);
}

function lsttraining_osm_mark_missing_scope_tiles_dirty(PDO $pdo, int $leitstelleId, string $layerKey): int
{
    $sql = "UPDATE leitstellen_osm_layers m
            INNER JOIN leitstelle_tile_scope s
                ON s.layer_key = m.layer_key
               AND s.tile_z = m.tile_z
               AND s.tile_x = m.tile_x
               AND s.tile_y = m.tile_y
            SET m.is_dirty = 1,
                m.check_status = 'missing_file',
                m.check_message = 'Initialer Tile-Download erforderlich.',
                m.updated_at = CURRENT_TIMESTAMP
            WHERE s.leitstelle_id = ?
              AND s.layer_key = ?
              AND (m.file_relpath IS NULL OR m.file_relpath = '')";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leitstelleId, $layerKey]);

    return lsttraining_osm_count_dirty_tiles($pdo, $leitstelleId, $layerKey);
}

function lsttraining_osm_mark_tile_dirty(
    PDO $pdo,
    string $layerKey,
    int $z,
    int $x,
    int $y,
    ?string $osmBase = null,
    ?string $msg = null
): void {
    $stmt = $pdo->prepare(
        "UPDATE leitstellen_osm_layers
         SET is_dirty = 1,
             check_status = 'dirty_detected',
             check_message = :msg,
             etag_or_signature = COALESCE(:sig, etag_or_signature),
             updated_at = CURRENT_TIMESTAMP
         WHERE layer_key = :layer_key
           AND tile_z = :z
           AND tile_x = :x
           AND tile_y = :y"
    );

    $stmt->execute([
        ':msg'       => $msg,
        ':sig'       => $osmBase,
        ':layer_key' => $layerKey,
        ':z'         => $z,
        ':x'         => $x,
        ':y'         => $y,
    ]);
}

function lsttraining_osm_count_dirty_tiles(PDO $pdo, int $leitstelleId, string $layerKey): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM leitstellen_osm_layers m
         INNER JOIN leitstelle_tile_scope s
             ON s.layer_key = m.layer_key
            AND s.tile_z = m.tile_z
            AND s.tile_x = m.tile_x
            AND s.tile_y = m.tile_y
         WHERE s.leitstelle_id = ?
           AND s.layer_key = ?
           AND m.is_dirty = 1"
    );
    $stmt->execute([$leitstelleId, $layerKey]);
    return (int)$stmt->fetchColumn();
}

function lsttraining_osm_get_dirty_tiles(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    int $offset = 0,
    int $limit = 50
): array {
    $sql = "SELECT m.*
            FROM leitstellen_osm_layers m
            INNER JOIN leitstelle_tile_scope s
                ON s.layer_key = m.layer_key
               AND s.tile_z = m.tile_z
               AND s.tile_x = m.tile_x
               AND s.tile_y = m.tile_y
            WHERE s.leitstelle_id = ?
              AND s.layer_key = ?
              AND m.is_dirty = 1
            ORDER BY m.tile_z, m.tile_x, m.tile_y
            LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $leitstelleId, PDO::PARAM_INT);
    $stmt->bindValue(2, $layerKey, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function lsttraining_osm_parent_tile(int $z, int $x, int $y, int $targetZ): array
{
    if ($targetZ >= $z) {
        return ['z' => $z, 'x' => $x, 'y' => $y];
    }

    $shift = $z - $targetZ;

    return [
        'z' => $targetZ,
        'x' => (int)floor($x / (2 ** $shift)),
        'y' => (int)floor($y / (2 ** $shift)),
    ];
}

function lsttraining_osm_tile_key(int $z, int $x, int $y): string
{
    return $z . '/' . $x . '/' . $y;
}

function lsttraining_osm_group_scope_tiles_by_parent(array $scopeTiles, int $targetZ): array
{
    $out = [];

    foreach ($scopeTiles as $tile) {
        $parent = lsttraining_osm_parent_tile(
            (int)$tile['tile_z'],
            (int)$tile['tile_x'],
            (int)$tile['tile_y'],
            $targetZ
        );
        $key = lsttraining_osm_tile_key((int)$parent['z'], (int)$parent['x'], (int)$parent['y']);
        $out[$key] = $parent;
    }

    return array_values($out);
}

function lsttraining_osm_build_scope_tree(array $scopeTiles, int $minZ): array
{
    $childrenMap = [];

    foreach ($scopeTiles as $tile) {
        $z = (int)$tile['tile_z'];
        $x = (int)$tile['tile_x'];
        $y = (int)$tile['tile_y'];

        for ($level = $minZ; $level < $z; $level++) {
            $parent = lsttraining_osm_parent_tile($z, $x, $y, $level);
            $child  = lsttraining_osm_parent_tile($z, $x, $y, $level + 1);

            $parentKey = lsttraining_osm_tile_key((int)$parent['z'], (int)$parent['x'], (int)$parent['y']);
            $childKey  = lsttraining_osm_tile_key((int)$child['z'], (int)$child['x'], (int)$child['y']);

            if (!isset($childrenMap[$parentKey])) {
                $childrenMap[$parentKey] = [];
            }
            $childrenMap[$parentKey][$childKey] = $child;
        }
    }

    return ['children' => $childrenMap];
}

function lsttraining_osm_expand_parent_to_children(array $parentTile, int $targetZ, array $scopeTree): array
{
    $parentKey = lsttraining_osm_tile_key((int)$parentTile['z'], (int)$parentTile['x'], (int)$parentTile['y']);

    if (!isset($scopeTree['children'][$parentKey]) || !is_array($scopeTree['children'][$parentKey])) {
        return [];
    }

    $out = [];
    foreach ($scopeTree['children'][$parentKey] as $child) {
        if ((int)$child['z'] === $targetZ) {
            $out[] = $child;
        }
    }

    return $out;
}

function lsttraining_osm_build_changed_count_query_for_bbox(
    string $layerKey,
    array $bbox,
    string $since,
    int $timeout = 20
): string {
    $layerDef = lsttraining_osm_layer_definition($layerKey);
    if (!$layerDef) {
        throw new RuntimeException('Layer-Konfiguration fehlt: ' . $layerKey);
    }

    $bboxStr = lsttraining_osm_bbox_string($bbox);
    $parts = [];

    foreach ($layerDef['queries'] as $q) {
        $parts[] = $q['type'] . $q['filter'] . '(' . $bboxStr . ')(changed:"' . $since . '")';
    }

    return "[out:json][timeout:" . (int)$timeout . "];\n(\n  "
        . implode(";\n  ", $parts)
        . ";\n);\nout count;";
}

function lsttraining_osm_super_tile_has_changes(
    string $layerKey,
    array $bbox,
    string $since,
    int $timeout = 20,
    int $minGapMs = 1500,
    int $endpointOffset = 0
): array {
    $timeout = max(3, min(8, $timeout));
    $query = lsttraining_osm_build_changed_count_query_for_bbox($layerKey, $bbox, $since, $timeout);
    $response = lsttraining_osm_run_overpass($query, $timeout, $endpointOffset, $minGapMs, 1);

    return [
        'count'    => lsttraining_osm_extract_count_from_response($response['body'] ?? []),
        'osm_base' => lsttraining_osm_extract_osm_base($response['body'] ?? []),
    ];
}

function lsttraining_osm_build_full_query(string $layerKey, array $bbox): string
{
    $layerDef = lsttraining_osm_layer_definition($layerKey);
    if (!$layerDef) {
        throw new RuntimeException('Layer-Konfiguration fehlt: ' . $layerKey);
    }

    $bboxStr = lsttraining_osm_bbox_string($bbox);
    $parts = [];

    foreach ($layerDef['queries'] as $q) {
        $parts[] = $q['type'] . $q['filter'] . '(' . $bboxStr . ')';
    }

    $timeout = max(3, min(8, (int)$layerDef['download_timeout']));

    return "[out:json][timeout:" . $timeout . "];\n(\n  "
        . implode(";\n  ", $parts)
        . ";\n);\nout body geom;";
}

function lsttraining_osm_run_overpass(
    string $query,
    int $timeout = 30,
    int $offset = 0,
    int $minGapMs = 1500,
    int $maxAttempts = 0
): array {
    $urls = lsttraining_osm_overpass_urls();
    if (!$urls) {
        throw new RuntimeException('Keine Overpass-URLs verfügbar.');
    }

    $timeout = max(3, min(8, $timeout));
    $count = count($urls);
    $start = $offset % $count;
    $attempts = $maxAttempts > 0 ? min($count, $maxAttempts) : $count;
    $lastError = 'Unbekannter Overpass-Fehler';

    for ($i = 0; $i < $attempts; $i++) {
        $url = $urls[($start + $i) % $count];

        lsttraining_overpass_rate_gate($minGapMs);

        $response = wp_remote_post($url, [
            'timeout'   => $timeout,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept'       => 'application/json',
                'User-Agent'   => 'LSTtraining-Plugin/1.0 (+https://lstsim.de/; WordPress)',
            ],
            'body'      => [
                'data' => $query,
            ],
        ]);

        if (is_wp_error($response)) {
            $lastError = $response->get_error_message();
            continue;
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $rawBody = (string)wp_remote_retrieve_body($response);
        $retryAfterHeader = wp_remote_retrieve_header($response, 'retry-after');
        $retryAfterMs = lsttraining_osm_retry_after_header_to_ms($retryAfterHeader);

        if ($status === 429) {
            throw new RuntimeException('HTTP 429 RETRY_AFTER_MS=' . $retryAfterMs . ' bei ' . $url);
        }

        if ($status === 403 || $status >= 500) {
            $lastError = 'HTTP ' . $status . ' bei ' . $url;
            continue;
        }

        if ($status !== 200) {
            throw new RuntimeException('Overpass antwortete mit HTTP ' . $status . ' bei ' . $url);
        }

        if ($rawBody === '') {
            $lastError = 'Leere Antwort von ' . $url;
            continue;
        }

        $json = json_decode($rawBody, true);
        if (!is_array($json)) {
            $lastError = 'Ungültige JSON-Antwort von ' . $url;
            continue;
        }

        return [
            'url'    => $url,
            'status' => $status,
            'body'   => $json,
        ];
    }

    throw new RuntimeException('Overpass fehlgeschlagen: ' . $lastError);
}

function lsttraining_osm_retry_after_header_to_ms($header): int
{
    if (is_array($header)) {
        $header = reset($header);
    }

    if (!is_string($header) || trim($header) === '') {
        return 16000;
    }

    $header = trim($header);

    if (ctype_digit($header)) {
        return max(1000, min(180000, ((int)$header) * 1000));
    }

    $ts = strtotime($header);
    if ($ts === false) {
        return 16000;
    }

    return max(1000, min(180000, ($ts - time()) * 1000));
}

function lsttraining_osm_extract_retry_after_ms_from_message(string $message): int
{
    if (preg_match('/RETRY_AFTER_MS=(\d+)/', $message, $m)) {
        return max(1000, min(180000, (int)$m[1]));
    }
    return 16000;
}

function lsttraining_osm_is_transient_overpass_error(string $message): bool
{
    return strpos($message, 'cURL error 28') !== false
        || strpos($message, 'cURL error 35') !== false
        || stripos($message, 'timed out') !== false
        || stripos($message, 'broken pipe') !== false
        || stripos($message, 'empty reply from server') !== false
        || stripos($message, 'connection reset by peer') !== false
        || stripos($message, 'ssl') !== false
        || strpos($message, 'Overpass fehlgeschlagen: HTTP 5') !== false
        || stripos($message, 'Leere Antwort von') !== false
        || stripos($message, 'Ungueltige JSON-Antwort von') !== false
        || stripos($message, 'Ungültige JSON-Antwort von') !== false;
}

function lsttraining_osm_download_tile(array $tileRow, string $layerKey, int $offset = 0): array
{
    $bbox = lsttraining_tile_bbox((int)$tileRow['tile_z'], (int)$tileRow['tile_x'], (int)$tileRow['tile_y']);
    $layerDef = lsttraining_osm_layer_definition($layerKey);

    $query = lsttraining_osm_build_full_query($layerKey, $bbox);
    $response = lsttraining_osm_run_overpass(
        $query,
        (int)$layerDef['download_timeout'],
        $offset,
        (int)$layerDef['download_request_gap_ms'],
        1
    );

    $features = lsttraining_osm_response_to_features($response['body'] ?? [], $layerKey);
    $write = lsttraining_osm_write_tile_file(
        $layerKey,
        (int)$tileRow['tile_z'],
        (int)$tileRow['tile_x'],
        (int)$tileRow['tile_y'],
        $features
    );

    $newSha1 = (string)$write['sha1'];
    $oldSha1 = isset($tileRow['sha1']) ? (string)$tileRow['sha1'] : '';
    $status  = ($oldSha1 !== '' && $oldSha1 === $newSha1) ? 'unchanged' : 'changed';

    return [
        'ok'           => true,
        'status'       => $status,
        'sha1'         => $write['sha1'],
        'feature_count'=> $write['feature_count'],
        'bytes_gz'     => $write['bytes_gz'],
        'file_relpath' => $write['file_relpath'],
        'osm_base'     => lsttraining_osm_extract_osm_base($response['body'] ?? []),
    ];
}

function lsttraining_osm_response_to_features(array $body, string $layerKey): array
{
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
                            'osm_id'   => (int)$el['id'],
                            'tags'     => $el['tags'] ?? new stdClass(),
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
                            'osm_id'   => (int)$el['id'],
                            'tags'     => $el['tags'] ?? new stdClass(),
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
                        'osm_id'   => (int)$el['id'],
                        'tags'     => $el['tags'] ?? new stdClass(),
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

function lsttraining_osm_relation_members_to_polygons(array $members): array
{
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
            $last  = $ring[count($ring) - 1];

            if ($first[0] === $last[0] && $first[1] === $last[1]) {
                $polygons[] = [$ring];
            }
        }
    }

    return $polygons;
}

function lsttraining_osm_write_tile_file(string $layerKey, int $z, int $x, int $y, array $features): array
{
    $baseDir = trailingslashit(dirname(dirname(__DIR__)));

    $relPath = 'data/osm_tiles/z' . $z . '/' . $layerKey . '/' . $x . '/' . $y . '.geojsonl.gz';
    $absPath = $baseDir . $relPath;
    $absDir  = dirname($absPath);

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
        'abs_path'     => $absPath,
        'sha1'         => lsttraining_osm_sha1_file($absPath),
        'feature_count'=> $featureCount,
        'bytes_gz'     => $bytes,
    ];
}

function lsttraining_osm_sha1_file(string $path): string
{
    $sha1 = @sha1_file($path);
    if (!is_string($sha1) || $sha1 === '') {
        throw new RuntimeException('SHA1 konnte nicht berechnet werden: ' . $path);
    }
    return $sha1;
}

function lsttraining_osm_update_manifest_after_check(PDO $pdo, array $tileRow, array $result): void
{
    $run = static function ($pdoConn) use ($tileRow, $result): void {
        if (!$pdoConn instanceof PDO) {
            throw new RuntimeException('update_manifest_after_check: keine gültige PDO-Verbindung');
        }

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

            $stmt = $pdoConn->prepare($sql);
            $stmt->execute([
                ':sig' => $result['osm_base'] ?? null,
                ':id'  => (int)$tileRow['id'],
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

            $stmt = $pdoConn->prepare($sql);
            $stmt->execute([
                ':source'       => $result['source'] ?? 'overpass',
                ':sha1'         => $result['sha1'] ?? null,
                ':feature_count'=> (int)($result['feature_count'] ?? 0),
                ':bytes_gz'     => (int)($result['bytes_gz'] ?? 0),
                ':file_relpath' => $result['file_relpath'] ?? null,
                ':sig'          => $result['osm_base'] ?? null,
                ':id'           => (int)$tileRow['id'],
            ]);
            return;
        }

        $stmt = $pdoConn->prepare(
            "UPDATE leitstellen_osm_layers
             SET last_checked_at = NOW(),
                 last_check_source = 'overpass',
                 check_status = 'error',
                 check_message = :msg,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );

        $stmt->execute([
            ':msg' => function_exists('mb_substr')
                ? mb_substr((string)($result['check_message'] ?? 'Unbekannter Fehler'), 0, 65000)
                : substr((string)($result['check_message'] ?? 'Unbekannter Fehler'), 0, 65000),
            ':id' => (int)$tileRow['id'],
        ]);
    };

    try {
        $run($pdo);
    } catch (Throwable $e) {
        error_log('[LSTtraining] update_manifest_after_check first try failed: ' . $e->getMessage());

        $pdo2 = lsttraining_get_connection();
        if (!$pdo2 instanceof PDO) {
            throw new RuntimeException('update_manifest_after_check: Reconnect fehlgeschlagen');
        }
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $run($pdo2);
    }
}

function lsttraining_osm_acquire_lock(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    string $lockToken,
    int $ttlSeconds = 180
): bool {
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

function lsttraining_osm_refresh_lock(
    PDO $pdo,
    int $leitstelleId,
    string $layerKey,
    string $lockToken,
    int $ttlSeconds = 180
): void {
    $sql = '
        UPDATE leitstelle_osm_update_lock
        SET lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND),
            updated_at = CURRENT_TIMESTAMP
        WHERE leitstelle_id = ?
          AND layer_key = ?
          AND lock_token = ?
    ';

    $runUpdate = static function ($pdoConn) use ($sql, $ttlSeconds, $leitstelleId, $layerKey, $lockToken): void {
        if (!$pdoConn instanceof PDO) {
            throw new RuntimeException('refresh_lock: keine gültige PDO-Verbindung');
        }

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute([$ttlSeconds, $leitstelleId, $layerKey, $lockToken]);
    };

    try {
        $runUpdate($pdo);
    } catch (Throwable $e) {
        error_log('[LSTtraining] refresh_lock first try failed: ' . $e->getMessage());

        $pdo2 = lsttraining_get_connection();
        if (!$pdo2 instanceof PDO) {
            throw new RuntimeException('refresh_lock: Reconnect fehlgeschlagen');
        }
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $runUpdate($pdo2);
    }
}

function lsttraining_osm_release_lock($pdo, int $leitstelleId, string $layerKey, string $lockToken = ''): void
{
    $sqlWithToken = "
        DELETE FROM leitstelle_osm_update_lock
        WHERE leitstelle_id = :leitstelle_id
          AND layer_key = :layer_key
          AND lock_token = :lock_token
    ";

    $sqlWithoutToken = "
        DELETE FROM leitstelle_osm_update_lock
        WHERE leitstelle_id = :leitstelle_id
          AND layer_key = :layer_key
    ";

    $runDelete = static function ($pdoConn) use ($sqlWithToken, $sqlWithoutToken, $leitstelleId, $layerKey, $lockToken): void {
        if (!$pdoConn instanceof PDO) {
            throw new RuntimeException('release_lock: keine gültige PDO-Verbindung');
        }

        if ($lockToken !== '') {
            $stmt = $pdoConn->prepare($sqlWithToken);
            $stmt->execute([
                ':leitstelle_id' => $leitstelleId,
                ':layer_key'     => $layerKey,
                ':lock_token'    => $lockToken,
            ]);
        } else {
            $stmt = $pdoConn->prepare($sqlWithoutToken);
            $stmt->execute([
                ':leitstelle_id' => $leitstelleId,
                ':layer_key'     => $layerKey,
            ]);
        }
    };

    try {
        if (!$pdo instanceof PDO) {
            $pdo = lsttraining_get_connection();
        }
        $runDelete($pdo);
    } catch (Throwable $e) {
        error_log('[LSTtraining] release_lock first try failed: ' . $e->getMessage());

        try {
            $pdo2 = lsttraining_get_connection();
            $runDelete($pdo2);
        } catch (Throwable $e2) {
            error_log('[LSTtraining] release_lock reconnect failed: ' . $e2->getMessage());
        }
    }
}

function lsttraining_osm_bbox_string(array $bbox): string
{
    return implode(',', [
        (float)$bbox['south'],
        (float)$bbox['west'],
        (float)$bbox['north'],
        (float)$bbox['east'],
    ]);
}

function lsttraining_osm_extract_count_from_response(array $body): int
{
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

function lsttraining_osm_extract_osm_base(array $body): ?string
{
    $osm3s = $body['osm3s'] ?? null;
    if (is_array($osm3s) && !empty($osm3s['timestamp_osm_base'])) {
        return (string)$osm3s['timestamp_osm_base'];
    }
    return null;
}

function lsttraining_osm_overpass_urls(): array
{
    $primary  = get_option('lsttraining_overpass_url');
    $fallback = get_option('lsttraining_overpass_url_fallback');

    $urls = [
        (is_string($primary) && trim($primary) !== '') ? trim($primary) : null,
        (is_string($fallback) && trim($fallback) !== '') ? trim($fallback) : null,
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    return array_values(array_unique(array_filter($urls)));
}

function lsttraining_overpass_rate_gate(int $minGapMs = 1500): void
{
    $key = 'lsttraining_overpass_rate_gate';

    $last = get_transient($key);
    $last = is_numeric($last) ? (float)$last : 0.0;

    $now = microtime(true);

    if ($last > 0) {
        $elapsed = ($now - $last) * 1000.0;
        if ($elapsed < $minGapMs) {
            usleep((int)(($minGapMs - $elapsed) * 1000));
        }
    }

    set_transient($key, microtime(true), 30);
}
