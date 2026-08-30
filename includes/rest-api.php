<?php
if ( ! defined('ABSPATH') ) { exit; }

add_action('rest_api_init', function () {

    register_rest_route('lst/v1', '/wachen', [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'lst_rest_get_wachen',
        'permission_callback' => 'lst_rest_can_read_wachen',
        'args' => [
            'leitstelle_id' => [
                'required' => false,
                'validate_callback' => fn($p) => is_numeric($p),
            ],
            'nebenleitstelle_id' => [
                'required' => false,
                'validate_callback' => fn($p) => is_numeric($p),
            ],
        ],
    ]);

    register_rest_route('lst/v1', '/route', [
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'lst_rest_post_route',
        'permission_callback' => 'lst_rest_can_route',
    ]);

    register_rest_route('lst/v1', '/instanzen/(?P<instanz_id>\d+)/status', [
        [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => 'lst_rest_get_instance_status',
            'permission_callback' => 'lst_rest_can_read_instance_status',
            'args' => [
                'instanz_id' => [
                    'required' => true,
                    'validate_callback' => static fn($value): bool => is_numeric($value) && (int) $value > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ],
        [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => 'lst_rest_update_instance_status',
            'permission_callback' => 'lst_rest_can_write_instance_status',
        ],
    ]);

    register_rest_route('lst/v1', '/instanzen/(?P<instanz_id>\d+)/fahrzeuge', [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'lst_rest_get_instance_vehicles',
        'permission_callback' => 'lst_rest_can_read_instance_status',
        'args' => [
            'instanz_id' => [
                'required' => true,
                'validate_callback' => static fn($value): bool => is_numeric($value) && (int) $value > 0,
                'sanitize_callback' => 'absint',
            ],
            'wache_id' => [
                'required' => false,
                'validate_callback' => static fn($value): bool => $value === null || $value === '' || (is_numeric($value) && (int) $value > 0),
                'sanitize_callback' => 'absint',
            ],
            'fahrzeug_id' => [
                'required' => false,
                'validate_callback' => static fn($value): bool => $value === null || $value === '' || (is_numeric($value) && (int) $value > 0),
                'sanitize_callback' => 'absint',
            ],
            'fms_status' => [
                'required' => false,
                'validate_callback' => static fn($value): bool => $value === null || $value === '' || preg_match('/^[1-9]$/', (string) $value) === 1,
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
    ]);

    register_rest_route('lst/v1', '/instanzen/(?P<instanz_id>\d+)/fahrzeuge/(?P<status_id>\d+)', [
        'methods' => WP_REST_Server::EDITABLE,
        'callback' => 'lst_rest_update_instance_vehicle',
        'permission_callback' => 'lst_rest_can_write_instance_status',
        'args' => [
            'instanz_id' => [
                'required' => true,
                'validate_callback' => static fn($value): bool => is_numeric($value) && (int) $value > 0,
                'sanitize_callback' => 'absint',
            ],
            'status_id' => [
                'required' => true,
                'validate_callback' => static fn($value): bool => is_numeric($value) && (int) $value > 0,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

function lst_rest_validation_response(InvalidArgumentException $error): WP_REST_Response {
    $response = new WP_REST_Response([
        'ok' => false,
        'error' => 'validation_failed',
        'message' => $error->getMessage(),
    ], 400);
    $response->header('Cache-Control', 'no-store, private');
    return $response;
}

/**
 * Permission: Wachen lesen
 * Nutzt dein Rechtesystem plus optional Leitstellen-Scope.
 */
function lst_rest_can_read_wachen( WP_REST_Request $request ) : bool {
    if ( ! is_user_logged_in() ) { return false; }

    $ls_id = $request->get_param('leitstelle_id');
    $ls_id = ($ls_id !== null && $ls_id !== '') ? (int)$ls_id : null;

    // Zugriff erlauben, wenn der User in deinem System Wachen sehen darf.
    // Wenn leitstelle_id gesetzt ist, wird zusätzlich der Scope geprüft (leitstellen_ids).
    return lsttraining_user_can('wachen', $ls_id);
}

/**
 * Permission: Route anfragen
 * Ich binde es an "leitstellen" oder "wachen" (Dispatch-Kontext).
 */
function lst_rest_can_route( WP_REST_Request $request ) : bool {
    if ( ! is_user_logged_in() ) { return false; }

    // Optionaler Scope anhand leitstelle_id im Body, falls du das später mitschickst
    $body = $request->get_json_params();
    $ls_id = null;
    if ( is_array($body) && isset($body['leitstelle_id']) && is_numeric($body['leitstelle_id']) ) {
        $ls_id = (int)$body['leitstelle_id'];
    }

    if ( is_array($body) && isset($body['instanz_id']) && is_numeric($body['instanz_id']) ) {
        $instanz_id = (int) $body['instanz_id'];
        if ( $instanz_id > 0 ) {
            require_once plugin_dir_path(__FILE__) . 'db.php';

            $pdo = lsttraining_get_connection();
            if ( $pdo instanceof PDO ) {
                if ( current_user_can('manage_options') ) {
                    return true;
                }

                try {
                    $stmt = $pdo->prepare('
                        SELECT COUNT(*)
                        FROM instanz_user
                        WHERE instanz_id = ? AND user_id = ? AND connected = 1
                    ');
                    $stmt->execute([$instanz_id, get_current_user_id()]);
                    if ( (int) $stmt->fetchColumn() > 0 ) {
                        return true;
                    }
                } catch (Throwable $e) {
                    error_log('REST /route permission ERROR: ' . $e->getMessage());
                    return false;
                }
            }
        }
    }

    if ( lsttraining_user_can('leitstellen', $ls_id) ) { return true; }
    if ( lsttraining_user_can('wachen', $ls_id) ) { return true; }

    return false;
}

/**
 * Lesezugriff auf den Live-Zustand einer Spielinstanz.
 *
 * Administratoren duerfen jede Instanz lesen. Alle anderen Benutzer muessen
 * als verbundene Teilnehmer in instanz_user eingetragen sein.
 *
 * @return bool|WP_Error
 */
function lst_rest_can_read_instance_status( WP_REST_Request $request ) {
    if ( ! is_user_logged_in() ) {
        return new WP_Error(
            'lst_rest_not_logged_in',
            'Anmeldung erforderlich.',
            ['status' => 401]
        );
    }

    $instanz_id = absint($request->get_param('instanz_id'));
    if ( $instanz_id <= 0 ) {
        return new WP_Error(
            'lst_rest_invalid_instance',
            'Ungueltige Simulationsinstanz.',
            ['status' => 400]
        );
    }

    if ( current_user_can('manage_options') ) {
        return true;
    }

    require_once plugin_dir_path(__FILE__) . 'db.php';
    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_Error(
            'lst_rest_db_connection_failed',
            'Datenbankverbindung fehlgeschlagen.',
            ['status' => 500]
        );
    }

    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM instanz_user
            WHERE instanz_id = ? AND user_id = ? AND connected = 1
        ');
        $stmt->execute([$instanz_id, (int) get_current_user_id()]);
        if ( (int) $stmt->fetchColumn() > 0 ) {
            return true;
        }
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST instance permission] ' . $e->getMessage());
        return new WP_Error(
            'lst_rest_permission_check_failed',
            'Zugriff konnte nicht geprueft werden.',
            ['status' => 500]
        );
    }

    return new WP_Error(
        'lst_rest_forbidden',
        'Kein Zugriff auf diese Simulation.',
        ['status' => 403]
    );
}

/** @return bool|WP_Error */
function lst_rest_can_write_instance_status( WP_REST_Request $request ) {
    if ( ! is_user_logged_in() ) {
        return new WP_Error('lst_rest_not_logged_in', 'Anmeldung erforderlich.', ['status' => 401]);
    }
    $instanz_id = absint($request->get_param('instanz_id'));
    if ( $instanz_id <= 0 ) {
        return new WP_Error('lst_rest_invalid_instance', 'Ungueltige Simulationsinstanz.', ['status' => 400]);
    }
    if ( current_user_can('manage_options') ) {
        return true;
    }

    require_once plugin_dir_path(__FILE__) . 'db.php';
    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_Error('lst_rest_db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', ['status' => 500]);
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM instanz_user WHERE instanz_id = ? AND user_id = ? AND connected = 1 AND rolle = ?');
        $stmt->execute([$instanz_id, (int) get_current_user_id(), 'leiter']);
        if ( (int) $stmt->fetchColumn() > 0 ) {
            return true;
        }
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST instance write permission] ' . $e->getMessage());
        return new WP_Error('lst_rest_permission_check_failed', 'Zugriff konnte nicht geprueft werden.', ['status' => 500]);
    }
    return new WP_Error('lst_rest_forbidden', 'Nur Einsatzleiter oder Administratoren duerfen den Live-Zustand aendern.', ['status' => 403]);
}

/**
 * Liest den effektiven Fahrzeugzustand. Eine vorhandene Delta-Zeile ersetzt
 * dabei die unveraenderliche Instanz-Baseline vollstaendig.
 */
function lst_rest_fetch_instance_vehicles(PDO $pdo, int $instanz_id, array $filters = []): array {
    $where = ['fs.instanz_id = :instanz_id'];
    $params = [':instanz_id' => $instanz_id];

    foreach (['status_id', 'wache_id', 'fahrzeug_id'] as $field) {
        $value = isset($filters[$field]) ? (int) $filters[$field] : 0;
        if ( $value > 0 ) {
            $column = $field === 'status_id' ? 'id' : $field;
            $where[] = 'fs.' . $column . ' = :' . $field;
            $params[':' . $field] = $value;
        }
    }

    $fms_status = isset($filters['fms_status']) ? trim((string) $filters['fms_status']) : '';
    if ( $fms_status !== '' ) {
        $where[] = '(CASE WHEN ifs.id IS NULL THEN fs.fms_status ELSE ifs.fms_status END) = :fms_status';
        $params[':fms_status'] = $fms_status;
    }

    $sql = '
        SELECT
            fs.id AS status_id,
            fs.fahrzeug_id,
            fs.wache_id,
            f.rufname,
            f.fahrzeugtyp,
            w.name AS wache_name,
            CASE WHEN ifs.id IS NULL THEN fs.latitude ELSE ifs.latitude END AS latitude,
            CASE WHEN ifs.id IS NULL THEN fs.longitude ELSE ifs.longitude END AS longitude,
            CASE WHEN ifs.id IS NULL THEN fs.ziel_latitude ELSE ifs.ziel_latitude END AS ziel_latitude,
            CASE WHEN ifs.id IS NULL THEN fs.ziel_longitude ELSE ifs.ziel_longitude END AS ziel_longitude,
            CASE WHEN ifs.id IS NULL THEN fs.status ELSE ifs.status END AS status,
            CASE WHEN ifs.id IS NULL THEN fs.fms_status ELSE ifs.fms_status END AS fms_status,
            CASE WHEN ifs.id IS NULL THEN fs.sondersignal ELSE ifs.sondersignal END AS sondersignal,
            CASE WHEN ifs.id IS NULL THEN fs.bemerkung ELSE ifs.bemerkung END AS bemerkung,
            CASE WHEN ifs.id IS NULL THEN fs.letzte_aktualisierung ELSE ifs.letzte_aktualisierung END AS letzte_aktualisierung,
            CASE WHEN ifs.id IS NULL THEN 0 ELSE 1 END AS has_delta
        FROM fahrzeug_status fs
        LEFT JOIN instanz_fahrzeug_status ifs
          ON ifs.instanz_id = fs.instanz_id
         AND ifs.fahrzeug_status_id = fs.id
        LEFT JOIN fahrzeuge f ON f.id = fs.fahrzeug_id
        LEFT JOIN wachen w ON w.id = fs.wache_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY f.rufname ASC, fs.id ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ( $vehicles as &$vehicle ) {
        foreach (['status_id', 'fahrzeug_id', 'wache_id'] as $field) {
            $vehicle[$field] = (int) ($vehicle[$field] ?? 0);
        }
        foreach (['latitude', 'longitude', 'ziel_latitude', 'ziel_longitude'] as $field) {
            $vehicle[$field] = $vehicle[$field] !== null ? (float) $vehicle[$field] : null;
        }
        $vehicle['sondersignal'] = (bool) ($vehicle['sondersignal'] ?? false);
        $vehicle['has_delta'] = (bool) ($vehicle['has_delta'] ?? false);
    }
    unset($vehicle);

    return $vehicles;
}

function lst_rest_live_response(array $data): WP_REST_Response {
    $response = new WP_REST_Response(['ok' => true, 'data' => $data], 200);
    $response->header('Cache-Control', 'no-store, private');
    return $response;
}

function lst_rest_get_instance_vehicles( WP_REST_Request $request ) {
    require_once plugin_dir_path(__FILE__) . 'db.php';

    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'db_connection_failed'], 500);
    }

    $instanz_id = absint($request->get_param('instanz_id'));
    $filters = [
        'wache_id' => absint($request->get_param('wache_id')),
        'fahrzeug_id' => absint($request->get_param('fahrzeug_id')),
        'fms_status' => sanitize_key((string) ($request->get_param('fms_status') ?? '')),
    ];

    try {
        $vehicles = lst_rest_fetch_instance_vehicles($pdo, $instanz_id, $filters);
        return lst_rest_live_response([
            'instanz_id' => $instanz_id,
            'count' => count($vehicles),
            'vehicles' => $vehicles,
            'generated_at' => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST instance vehicles] ' . $e->getMessage());
        return new WP_REST_Response(['ok' => false, 'error' => 'db_query_failed'], 500);
    }
}

function lst_rest_get_instance_status( WP_REST_Request $request ) {
    require_once plugin_dir_path(__FILE__) . 'db.php';

    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'db_connection_failed'], 500);
    }

    $instanz_id = absint($request->get_param('instanz_id'));

    try {
        $stmt = $pdo->prepare('
            SELECT
                si.id AS instanz_id,
                si.name AS instanz_name,
                si.leitstelle_id,
                si.sim_state,
                si.ist_aktiv,
                si.started_at,
                si.last_activity_at,
                si.settings_json,
                l.name AS leitstelle_name,
                l.ort AS leitstelle_ort,
                l.bundesland AS leitstelle_bundesland,
                l.land AS leitstelle_land
            FROM spielinstanzen si
            INNER JOIN leitstellen l ON l.id = si.leitstelle_id
            WHERE si.id = ?
            LIMIT 1
        ');
        $stmt->execute([$instanz_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        if ( ! $instance ) {
            return new WP_REST_Response(['ok' => false, 'error' => 'instance_not_found'], 404);
        }

        $vehicles = lst_rest_fetch_instance_vehicles($pdo, $instanz_id);
        $by_status = [];
        $by_fms_status = [];
        $special_signal_count = 0;
        $last_vehicle_update = '';
        foreach ( $vehicles as $vehicle ) {
            $status = (string) ($vehicle['status'] ?? 'unbekannt');
            $fms = (string) ($vehicle['fms_status'] ?? 'unbekannt');
            $by_status[$status] = ($by_status[$status] ?? 0) + 1;
            $by_fms_status[$fms] = ($by_fms_status[$fms] ?? 0) + 1;
            $special_signal_count += !empty($vehicle['sondersignal']) ? 1 : 0;
            $updated_at = (string) ($vehicle['letzte_aktualisierung'] ?? '');
            if ( $updated_at > $last_vehicle_update ) {
                $last_vehicle_update = $updated_at;
            }
        }
        ksort($by_status);
        ksort($by_fms_status, SORT_NATURAL);

        $incident_stmt = $pdo->prepare("SELECT COUNT(*) FROM instanz_einsaetze WHERE instanz_id = ? AND state IN ('new', 'active')");
        $incident_stmt->execute([$instanz_id]);
        $open_incidents = (int) $incident_stmt->fetchColumn();

        $participant_stmt = $pdo->prepare('SELECT COUNT(*) FROM instanz_user WHERE instanz_id = ? AND connected = 1');
        $participant_stmt->execute([$instanz_id]);
        $connected_participants = (int) $participant_stmt->fetchColumn();

        $settings = json_decode((string) ($instance['settings_json'] ?? ''), true);
        $settings = is_array($settings) ? $settings : [];
        $sim_state = (string) ($instance['sim_state'] ?? 'created');

        return lst_rest_live_response([
            'instance' => [
                'id' => (int) $instance['instanz_id'],
                'name' => (string) ($instance['instanz_name'] ?? ''),
                'state' => $sim_state,
                'active' => (bool) ($instance['ist_aktiv'] ?? false),
                'paused' => $sim_state === 'paused' || !empty($settings['sim_paused']),
                'speed' => max(1, (int) ($settings['sim_speed_multiplier'] ?? 1)),
                'started_at' => $instance['started_at'] ?: null,
                'last_activity_at' => $instance['last_activity_at'] ?: null,
            ],
            'leitstelle' => [
                'id' => (int) $instance['leitstelle_id'],
                'name' => (string) ($instance['leitstelle_name'] ?? ''),
                'ort' => (string) ($instance['leitstelle_ort'] ?? ''),
                'bundesland' => (string) ($instance['leitstelle_bundesland'] ?? ''),
                'land' => (string) ($instance['leitstelle_land'] ?? ''),
            ],
            'vehicles' => [
                'total' => count($vehicles),
                'by_status' => $by_status,
                'by_fms_status' => $by_fms_status,
                'with_special_signal' => $special_signal_count,
                'last_updated_at' => $last_vehicle_update !== '' ? $last_vehicle_update : null,
            ],
            'incidents' => [
                'open' => $open_incidents,
            ],
            'participants' => [
                'connected' => $connected_participants,
            ],
            'generated_at' => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST instance status] ' . $e->getMessage());
        return new WP_REST_Response(['ok' => false, 'error' => 'db_query_failed'], 500);
    }
}

function lst_rest_update_instance_status( WP_REST_Request $request ) {
    $instanz_id = absint($request->get_param('instanz_id'));
    try {
        $body = lsttraining_rest_json_object($request, ['state', 'paused', 'speed'], 16384);
    } catch (InvalidArgumentException $e) {
        return lst_rest_validation_response($e);
    }

    $has_state = array_key_exists('state', $body) || array_key_exists('paused', $body);
    $has_speed = array_key_exists('speed', $body);
    if ( ! $has_state && ! $has_speed ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'no_changes'], 400);
    }

    require_once plugin_dir_path(__FILE__) . 'db.php';
    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'db_connection_failed'], 500);
    }

    try {
        $runtime = lsttraining_sim_fetch_runtime($pdo, $instanz_id);
        $state = array_key_exists('state', $body)
            ? lsttraining_rest_assert_safe_string('state', $body['state'], 16)
            : (string) ($runtime['sim_state'] ?? 'created');
        if ( ! in_array($state, ['created', 'running', 'paused'], true) ) {
            throw new InvalidArgumentException('state ist ungueltig.');
        }
        if ( array_key_exists('paused', $body) ) {
            $paused_value = lsttraining_rest_strict_boolean('paused', $body['paused']);
            if ( array_key_exists('state', $body) && (($paused_value && $state !== 'paused') || (!$paused_value && $state !== 'running')) ) {
                throw new InvalidArgumentException('state und paused widersprechen sich.');
            }
            $state = $paused_value ? 'paused' : 'running';
        }

        $speed = $has_speed ? lsttraining_rest_strict_integer('speed', $body['speed']) : null;
        if ( $has_speed && ! in_array($speed, [1, 2, 5], true) ) {
            throw new InvalidArgumentException('speed erlaubt nur 1, 2 oder 5.');
        }
        $paused = $state === 'paused';
        $settings = lsttraining_sim_materialize_runtime_settings(
            is_array($runtime['settings'] ?? null) ? $runtime['settings'] : [],
            $runtime,
            $speed,
            $paused
        );
        $stmt = $pdo->prepare('UPDATE spielinstanzen SET sim_state = ?, settings_json = ? WHERE id = ?');
        $stmt->execute([$state, wp_json_encode($settings), $instanz_id]);
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        if ( function_exists('lsttraining_log_activity') ) {
            lsttraining_log_activity([
                'entity_type' => 'spielinstanz',
                'action' => 'update',
                'entity_id' => $instanz_id,
                'meta' => ['source' => 'rest-status', 'state' => $state, 'speed' => $settings['sim_speed_multiplier'] ?? 1],
            ]);
        }
        return lst_rest_get_instance_status($request);
    } catch (InvalidArgumentException $e) {
        return lst_rest_validation_response($e);
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST update instance status] ' . $e->getMessage());
        return new WP_REST_Response(['ok' => false, 'error' => 'db_write_failed'], 500);
    }
}

function lst_rest_update_instance_vehicle( WP_REST_Request $request ) {
    $instanz_id = absint($request->get_param('instanz_id'));
    $status_id = absint($request->get_param('status_id'));
    try {
        $body = lsttraining_rest_json_object($request, [
            'latitude', 'longitude', 'ziel_latitude', 'ziel_longitude',
            'status', 'fms_status', 'sondersignal', 'bemerkung',
        ], 65536);
    } catch (InvalidArgumentException $e) {
        return lst_rest_validation_response($e);
    }

    require_once plugin_dir_path(__FILE__) . 'db.php';
    require_once plugin_dir_path(__FILE__) . 'simulation/vehicle-state.php';
    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'db_connection_failed'], 500);
    }

    $updates = [];
    try {
        foreach (['latitude', 'longitude', 'ziel_latitude', 'ziel_longitude'] as $field) {
            if ( ! array_key_exists($field, $body) ) { continue; }
            if ( $body[$field] === null || $body[$field] === '' ) {
                if ($body[$field] === '') {
                    throw new InvalidArgumentException($field . ' muss eine Zahl oder null sein.');
                }
                $updates[$field] = null;
                continue;
            }
            $is_latitude = str_contains($field, 'latitude');
            $updates[$field] = lsttraining_rest_strict_number($field, $body[$field], $is_latitude ? -90 : -180, $is_latitude ? 90 : 180);
        }
        if ( array_key_exists('status', $body) ) {
            $status = lsttraining_rest_assert_safe_string('status', $body['status'], 32);
            if ( ! in_array($status, ['frei', 'besetzt', 'einsatzbereit', 'nicht einsatzbereit'], true) ) {
                throw new InvalidArgumentException('status ist ungueltig.');
            }
            $updates['status'] = $status;
        }
        if ( array_key_exists('fms_status', $body) ) {
            $fms = lsttraining_rest_assert_safe_string('fms_status', $body['fms_status'], 1);
            if ( ! in_array($fms, ['1', '2', '3', '4', '5', '6'], true) ) {
                throw new InvalidArgumentException('fms_status ist ungueltig.');
            }
            $updates['fms_status'] = $fms;
        }
        if ( array_key_exists('sondersignal', $body) ) {
            $signal = lsttraining_rest_strict_boolean('sondersignal', $body['sondersignal']);
            $updates['sondersignal'] = $signal ? 1 : 0;
        }
        if ( array_key_exists('bemerkung', $body) ) {
            $updates['bemerkung'] = $body['bemerkung'] === null
                ? null
                : lsttraining_rest_assert_safe_string('bemerkung', $body['bemerkung'], 2000, true);
        }
        if ( ! $updates ) {
            return new WP_REST_Response(['ok' => false, 'error' => 'no_changes'], 400);
        }

        $runtime = lsttraining_sim_fetch_runtime($pdo, $instanz_id);
        if ( lsttraining_sim_instance_is_paused($runtime) ) {
            return new WP_REST_Response(['ok' => false, 'error' => 'simulation_paused'], 409);
        }
        if ( ! lsttraining_sim_fetch_effective_vehicle_state($pdo, $instanz_id, $status_id) ) {
            return new WP_REST_Response(['ok' => false, 'error' => 'vehicle_not_found'], 404);
        }
        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, $updates);
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        if ( function_exists('lsttraining_log_activity') ) {
            lsttraining_log_activity([
                'entity_type' => 'fahrzeug_status',
                'action' => 'update',
                'entity_id' => $status_id,
                'meta' => ['source' => 'rest-status', 'instanz_id' => $instanz_id, 'fields' => array_keys($updates)],
            ]);
        }
        $vehicles = lst_rest_fetch_instance_vehicles($pdo, $instanz_id, ['status_id' => $status_id]);
        return lst_rest_live_response($vehicles[0] ?? ['status_id' => $status_id]);
    } catch (InvalidArgumentException $e) {
        return lst_rest_validation_response($e);
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST update instance vehicle] ' . $e->getMessage());
        $legacy_model = strpos($e->getMessage(), 'altes Fahrzeugstatusmodell') !== false;
        return new WP_REST_Response(['ok' => false, 'error' => $legacy_model ? 'legacy_vehicle_state_model' : 'db_write_failed', 'message' => $legacy_model ? $e->getMessage() : 'Fahrzeugstatus konnte nicht gespeichert werden.'], $legacy_model ? 409 : 500);
    }
}

function lst_rest_get_wachen( WP_REST_Request $request ) {
    require_once plugin_dir_path(__FILE__) . 'db.php';

    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'db_connection_failed'], 500);
    }

    $leitId   = $request->get_param('leitstelle_id');
    $nebenlId = $request->get_param('nebenleitstelle_id');

    $leitId   = ($leitId !== null && $leitId !== '') ? (int)$leitId : null;
    $nebenlId = ($nebenlId !== null && $nebenlId !== '') ? (int)$nebenlId : null;

    try {
        if ( $leitId ) {
            $sql = "
                SELECT w.id, w.name, w.typ, w.latitude, w.longitude, w.bild_datei
                FROM wachen AS w
                LEFT JOIN wache_leitstellen AS wl ON w.id = wl.wache_id
                WHERE wl.leitstelle_id = :lid
                  AND w.latitude  IS NOT NULL
                  AND w.longitude IS NOT NULL
                ORDER BY w.name
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':lid' => $leitId]);

        } elseif ( $nebenlId ) {
            $sql = "
                SELECT w.id, w.name, w.typ, w.latitude, w.longitude, w.bild_datei
                FROM wachen AS w
                LEFT JOIN wache_nebenleitstellen AS wn ON w.id = wn.wache_id
                WHERE wn.nebenleitstelle_id = :nlid
                  AND w.latitude  IS NOT NULL
                  AND w.longitude IS NOT NULL
                ORDER BY w.name
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':nlid' => $nebenlId]);

        } else {
            $stmt = $pdo->query("
                SELECT id, name, typ, latitude, longitude, bild_datei
                FROM wachen
                WHERE latitude  IS NOT NULL
                  AND longitude IS NOT NULL
                ORDER BY name
            ");
        }

        $wachen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return new WP_REST_Response(['ok' => true, 'data' => $wachen], 200);

    } catch (Throwable $e) {
        error_log('REST /wachen ERROR: ' . $e->getMessage());
        return new WP_REST_Response(['ok' => false, 'error' => 'db_query_failed'], 500);
    }
}

function lst_rest_post_route( WP_REST_Request $request ) {
    try {
        $body = lsttraining_rest_json_object($request, ['coordinates', 'preference', 'leitstelle_id', 'instanz_id'], 16384);
        $coordinates = $body['coordinates'] ?? null;
        if (!is_array($coordinates) || !lsttraining_rest_is_list($coordinates) || count($coordinates) !== 2) {
            throw new InvalidArgumentException('coordinates muss genau Start und Ziel enthalten.');
        }
        $normalized_coordinates = [];
        foreach ($coordinates as $index => $point) {
            if (!is_array($point) || !lsttraining_rest_is_list($point) || count($point) !== 2) {
                throw new InvalidArgumentException('coordinates[' . $index . '] muss [Laengengrad, Breitengrad] enthalten.');
            }
            $normalized_coordinates[] = [
                lsttraining_rest_strict_number('coordinates[' . $index . '][0]', $point[0], -180, 180),
                lsttraining_rest_strict_number('coordinates[' . $index . '][1]', $point[1], -90, 90),
            ];
        }
        $coordinates = $normalized_coordinates;
        foreach (['leitstelle_id', 'instanz_id'] as $id_field) {
            if (array_key_exists($id_field, $body)) {
                lsttraining_rest_strict_integer($id_field, $body[$id_field], 1);
            }
        }
        $preference = array_key_exists('preference', $body)
            ? lsttraining_rest_assert_safe_string('preference', $body['preference'], 16)
            : 'fastest';
        if (!in_array($preference, ['fastest', 'recommended', 'shortest'], true)) {
            throw new InvalidArgumentException('preference ist ungueltig.');
        }
    } catch (InvalidArgumentException $e) {
        return lst_rest_validation_response($e);
    }

    $apiKey = get_option('lsttraining_ors_key', '');
    $apiKey = is_string($apiKey) ? trim($apiKey) : '';
    if ( $apiKey === '' ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'ors_key_missing'], 500);
    }

    // Rate-Limit: 60 / 10 Minuten pro User (wie in deiner Plugin-Logik üblich)
    $user_id = get_current_user_id();
    $rl_key = 'lst_rl_route_' . $user_id;
    $count = (int) get_transient($rl_key);
    if ( $count >= 60 ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
    }
    set_transient($rl_key, $count + 1, 10 * MINUTE_IN_SECONDS);

    // Cache: identische Anfrage kurz cachen
    $cache_key = 'lst_route_' . md5(wp_json_encode([
        'coordinates' => $coordinates,
        'preference' => $preference,
    ]));
    $cached = get_transient($cache_key);
    if ( $cached ) {
        return new WP_REST_Response(['ok' => true, 'cached' => true, 'data' => $cached], 200);
    }

    $url = 'https://api.openrouteservice.org/v2/directions/driving-car/geojson';

    $res = wp_remote_post($url, [
        'timeout' => 15,
        'headers' => [
            'Authorization' => $apiKey,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'coordinates' => $coordinates,
            'preference' => $preference,
        ]),
    ]);

    if ( is_wp_error($res) ) {
        error_log('ORS request failed: ' . $res->get_error_message());
        return new WP_REST_Response(['ok' => false, 'error' => 'ors_request_failed'], 502);
    }

    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);

    if ( $code < 200 || $code >= 300 || ! is_array($json) ) {
        error_log('ORS bad response code=' . $code);
        return new WP_REST_Response(['ok' => false, 'error' => 'ors_bad_response', 'status' => $code], 502);
    }

    set_transient($cache_key, $json, 120);
    return new WP_REST_Response(['ok' => true, 'cached' => false, 'data' => $json], 200);
}
