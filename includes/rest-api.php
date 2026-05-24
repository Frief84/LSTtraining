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
});

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

    $apiKey = get_option('lsttraining_ors_key', '');
    $apiKey = is_string($apiKey) ? trim($apiKey) : '';
    if ( $apiKey === '' ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'ors_key_missing'], 500);
    }

    $body = $request->get_json_params();
    if ( ! is_array($body) ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
    }

    $coordinates = $body['coordinates'] ?? null;
    if ( ! is_array($coordinates) || count($coordinates) !== 2 ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'invalid_coordinates'], 400);
    }

    // Rate-Limit: 60 / 10 Minuten pro User (wie in deiner Plugin-Logik üblich)
    $user_id = get_current_user_id();
    $rl_key = 'lst_rl_route_' . $user_id;
    $count = (int) get_transient($rl_key);
    if ( $count >= 60 ) {
        return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
    }
    set_transient($rl_key, $count + 1, 10 * MINUTE_IN_SECONDS);

    $preference = isset($body['preference']) ? sanitize_key((string) $body['preference']) : 'fastest';
    if ( ! in_array($preference, ['fastest', 'recommended', 'shortest'], true) ) {
        $preference = 'fastest';
    }

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
