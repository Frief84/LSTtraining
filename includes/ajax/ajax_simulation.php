<?php
if (!defined('ABSPATH')) { exit(); }

require_once dirname(__DIR__) . '/simulation/spawn.php';
require_once dirname(__DIR__) . '/simulation/vehicle-state.php';
require_once dirname(__DIR__) . '/simulation/transport.php';

function lsttraining_sim_check_nonce(): void {
    if (!check_ajax_referer('lsttraining_frontend_start', 'nonce', false)) {
        wp_send_json_error(['message' => 'Ungültiger Sicherheits-Token.'], 403);
    }
}

add_action('wp_ajax_lsttraining_sim_refresh_nonce', function () {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    // Nur fuer lange laufende Simulationsseiten: keine DB-Aktion, keine Simulation-Mutation.
    wp_send_json_success([
        'nonce' => wp_create_nonce('lsttraining_frontend_start'),
        'rest_nonce' => wp_create_nonce('wp_rest'),
    ]);
});

function lsttraining_sim_user_can_access_instance(PDO $pdo, int $instanz_id, int $user_id): bool {
    if ($instanz_id <= 0 || $user_id <= 0) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM instanz_user
        WHERE instanz_id = ? AND user_id = ? AND connected = 1
    ');
    $stmt->execute([$instanz_id, $user_id]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_sim_user_can_force_spawn(PDO $pdo, int $instanz_id, int $user_id): bool {
    if ($instanz_id <= 0 || $user_id <= 0) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM instanz_user
        WHERE instanz_id = ? AND user_id = ? AND connected = 1 AND rolle = ?
    ');
    $stmt->execute([$instanz_id, $user_id, 'leiter']);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_sim_fetch_runtime(PDO $pdo, int $instanz_id): array {
    $stmt = $pdo->prepare('
        SELECT sim_state, settings_json, started_at
        FROM spielinstanzen
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$instanz_id]);
    $runtime = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$runtime) {
        return ['sim_state' => 'created', 'settings' => []];
    }

    $settings = json_decode((string) ($runtime['settings_json'] ?? ''), true);
    return [
        'sim_state' => (string) ($runtime['sim_state'] ?? 'created'),
        'settings' => is_array($settings) ? $settings : [],
        'started_at' => (string) ($runtime['started_at'] ?? ''),
    ];
}

function lsttraining_sim_instance_is_paused(array $runtime): bool {
    $settings = is_array($runtime['settings'] ?? null) ? $runtime['settings'] : [];
    return (string) ($runtime['sim_state'] ?? '') === 'paused' || !empty($settings['sim_paused']);
}

function lsttraining_sim_guard_not_paused(PDO $pdo, int $instanz_id): void {
    lsttraining_sim_require_vehicle_delta_model($pdo, $instanz_id);
    if (lsttraining_sim_instance_is_paused(lsttraining_sim_fetch_runtime($pdo, $instanz_id))) {
        wp_send_json_error(['message' => 'Simulation ist pausiert.'], 409);
    }
}

function lsttraining_sim_reset_runtime_speed(PDO $pdo, int $instanz_id): void {
    $runtime = lsttraining_sim_fetch_runtime($pdo, $instanz_id);
    $settings = lsttraining_sim_materialize_runtime_settings(
        is_array($runtime['settings'] ?? null) ? $runtime['settings'] : [],
        $runtime,
        1,
        null
    );
    $stmt = $pdo->prepare('UPDATE spielinstanzen SET settings_json = ? WHERE id = ?');
    $stmt->execute([lsttraining_sim_encode_meta($settings), $instanz_id]);
}

function lsttraining_sim_current_game_runtime(PDO $pdo, int $instanz_id): array {
    $runtime = lsttraining_sim_fetch_runtime($pdo, $instanz_id);
    return lsttraining_sim_runtime_state(is_array($runtime['settings'] ?? null) ? $runtime['settings'] : [], $runtime);
}

add_action('wp_ajax_lsttraining_sim_set_runtime', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $paused = !empty($_POST['paused']);
    $speed = isset($_POST['speed']) ? (int) $_POST['speed'] : 1;
    if (!in_array($speed, [1, 2, 5], true)) {
        $speed = 1;
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $runtime = lsttraining_sim_fetch_runtime($pdo, $instanz_id);
        $settings = lsttraining_sim_materialize_runtime_settings(
            is_array($runtime['settings'] ?? null) ? $runtime['settings'] : [],
            $runtime,
            $speed,
            $paused
        );
        $state = $paused ? 'paused' : 'running';
        $runtime_for_state = $runtime;
        $runtime_for_state['sim_state'] = $state;
        $runtime_state = lsttraining_sim_runtime_state($settings, $runtime_for_state);
        $stmt = $pdo->prepare('
            UPDATE spielinstanzen
            SET sim_state = ?, settings_json = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $state,
            lsttraining_sim_encode_meta($settings),
            $instanz_id,
        ]);
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        wp_send_json_success([
            'sim_state' => $state,
            'speed' => $runtime_state['speed'],
            'paused' => $paused,
            'sim_now' => $runtime_state['sim_now'],
            'sim_timestamp' => $runtime_state['game_now_ts'],
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_set_runtime] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Simulationszustand konnte nicht gespeichert werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_tick', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $result = lsttraining_sim_spawn_one($pdo, $instanz_id);
        wp_send_json_success($result);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_tick] ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_force_spawn', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_force_spawn($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Nur Admins oder Einsatzleiter können Testeinsätze erzeugen.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $options = ['force' => true];
        if ($einsatz_id > 0) {
            $options['einsatz_id'] = $einsatz_id;
        }

        $result = lsttraining_sim_spawn_one($pdo, $instanz_id, $options);
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success($result);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_force_spawn] ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_force_spawn_options', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_force_spawn($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Nur Admins oder Einsatzleiter können Testeinsätze erzeugen.'], 403);
        }

        $stmt = $pdo->query('
            SELECT id, title, einsatzart, einsatztyp, scope_type
            FROM einsaetze
            WHERE enabled = 1
            ORDER BY title ASC, id ASC
            LIMIT 500
        ');
        if (!$stmt) {
            throw new RuntimeException('Einsatzvorlagen konnten nicht geladen werden.');
        }
        $items = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) ($row['title'] ?? ''),
                'einsatzart' => (string) ($row['einsatzart'] ?? ''),
                'einsatztyp' => (string) ($row['einsatztyp'] ?? ''),
                'scope_type' => (string) ($row['scope_type'] ?? ''),
            ];
        }

        wp_send_json_success(['items' => $items]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_force_spawn_options] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Einsatzvorlagen konnten nicht geladen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_get_updates', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $since_id = isset($_POST['since_id']) ? absint($_POST['since_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        wp_send_json_success([
            'items' => lsttraining_sim_fetch_updates($pdo, $instanz_id, $since_id),
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_updates] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Einsätze konnten nicht geladen werden.'], 500);
    }
});

function lsttraining_sim_public_vehicle_image_url(?string $image, string $fallback = ''): string {
    $image = trim((string) $image);
    if ($image === '') {
        $image = trim($fallback);
    }
    if ($image === '') {
        return '';
    }

    $site_scheme = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME);
    $target_scheme = (is_ssl() || $site_scheme === 'https') ? 'https' : null;

    if (preg_match('#^https?://#i', $image)) {
        return $target_scheme ? set_url_scheme($image, $target_scheme) : $image;
    }

    if (strpos($image, '//') === 0) {
        return is_ssl() ? 'https:' . $image : 'http:' . $image;
    }

    if ($image[0] === '/') {
        $url = site_url($image);
        return $target_scheme ? set_url_scheme($url, $target_scheme) : $url;
    }

    $url = LSTTRAINING_URL . ltrim($image, '/');
    return $target_scheme ? set_url_scheme($url, $target_scheme) : $url;
}

function lsttraining_sim_vehicle_marker_mode(int $user_id): string {
    $mode = get_user_meta($user_id, 'lsttraining_vehicle_marker_mode', true);
    return in_array($mode, ['marker', 'image', 'tactical'], true) ? (string) $mode : 'marker';
}

function lsttraining_sim_signal_lights_for_vehicle($raw, string $vehicle_type = '', string $rufname = ''): array {
    $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
    $lights = is_array($decoded['lights'] ?? null) ? $decoded['lights'] : [];
    $normalized = [];
    foreach ($lights as $light) {
        if (!is_array($light)) {
            continue;
        }
        $x = isset($light['x']) ? (float) $light['x'] : null;
        $y = isset($light['y']) ? (float) $light['y'] : null;
        if ($x === null || $y === null || !is_finite($x) || !is_finite($y)) {
            continue;
        }
        $type = sanitize_key((string) ($light['type'] ?? 'beacon'));
        if (!in_array($type, ['beacon', 'strobe', 'bar', 'glow'], true)) {
            $type = 'beacon';
        }
        $normalized[] = [
            'x' => max(0.0, min(1.0, $x)),
            'y' => max(0.0, min(1.0, $y)),
            'type' => $type,
            'interval' => max(120, min(2000, (int) ($light['interval'] ?? 420))),
            'phase' => max(0, min(5000, (int) ($light['phase'] ?? 0))),
            'size' => max(0.4, min(2.5, (float) ($light['size'] ?? 1))),
        ];
    }
    if ($normalized) {
        return $normalized;
    }

    $haystack = strtoupper($vehicle_type . ' ' . $rufname);
    if (preg_match('/\b(POL|POLIZEI|STREIFEN)\b/', $haystack)) {
        return [
            ['x' => 0.42, 'y' => 0.18, 'type' => 'bar', 'interval' => 360, 'phase' => 0, 'size' => 1.0],
            ['x' => 0.58, 'y' => 0.18, 'type' => 'bar', 'interval' => 360, 'phase' => 180, 'size' => 1.0],
        ];
    }
    if (preg_match('/\b(HLF|LF|TLF|DLK|ELW|RW|GW|MTW|FEUERWEHR)\b/', $haystack)) {
        return [
            ['x' => 0.34, 'y' => 0.18, 'type' => 'beacon', 'interval' => 440, 'phase' => 0, 'size' => 1.0],
            ['x' => 0.50, 'y' => 0.16, 'type' => 'beacon', 'interval' => 520, 'phase' => 170, 'size' => 0.9],
            ['x' => 0.66, 'y' => 0.18, 'type' => 'beacon', 'interval' => 440, 'phase' => 260, 'size' => 1.0],
        ];
    }
    if (preg_match('/\b(NEF|NAW)\b|NOTARZT/', $haystack)) {
        return [
            ['x' => 0.38, 'y' => 0.20, 'type' => 'strobe', 'interval' => 360, 'phase' => 0, 'size' => 0.85],
            ['x' => 0.62, 'y' => 0.20, 'type' => 'strobe', 'interval' => 360, 'phase' => 180, 'size' => 0.85],
        ];
    }
    if (preg_match('/\b(RTW|KTW|NAW)\b|RETTUNG|KRANKENTRANSPORT/', $haystack)) {
        return [
            ['x' => 0.38, 'y' => 0.18, 'type' => 'beacon', 'interval' => 420, 'phase' => 0, 'size' => 1.0],
            ['x' => 0.62, 'y' => 0.18, 'type' => 'beacon', 'interval' => 420, 'phase' => 210, 'size' => 1.0],
        ];
    }
    return [
        ['x' => 0.42, 'y' => 0.18, 'type' => 'beacon', 'interval' => 420, 'phase' => 0, 'size' => 0.9],
        ['x' => 0.58, 'y' => 0.18, 'type' => 'beacon', 'interval' => 420, 'phase' => 210, 'size' => 0.9],
    ];
}

function lsttraining_sim_signal_lights_raw_has_lights($raw): bool {
    if (is_array($raw)) {
        $decoded = $raw;
    } else {
        $text = trim((string) $raw);
        if ($text === '') {
            return false;
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return false;
        }
    }

    $is_list = $decoded === [] || array_keys($decoded) === range(0, count($decoded) - 1);
    $lights = is_array($decoded['lights'] ?? null) ? $decoded['lights'] : ($is_list ? $decoded : []);
    foreach ($lights as $light) {
        if (!is_array($light)) {
            continue;
        }
        if (isset($light['x'], $light['y']) && is_numeric($light['x']) && is_numeric($light['y'])) {
            return true;
        }
    }
    return false;
}

function lsttraining_sim_station_kind(string $type): string {
    $normalized = strtolower(trim($type));

    if (strpos($normalized, 'thw') !== false || strpos($normalized, 'technisches hilfswerk') !== false) {
        return 'thw';
    }

    if (strpos($normalized, 'feuerwehr') !== false || preg_match('/(^|[^a-z])fw([^a-z]|$)/', $normalized)) {
        return 'fw';
    }

    if (
        strpos($normalized, 'rettung') !== false ||
        strpos($normalized, 'rettungsdienst') !== false ||
        strpos($normalized, 'rettungswache') !== false ||
        preg_match('/(^|[^a-z])rd([^a-z]|$)/', $normalized)
    ) {
        return 'rd';
    }

    return 'other';
}

function lsttraining_sim_decode_meta(?string $json): array {
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function lsttraining_sim_encode_meta(array $meta): string {
    return (string) wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function lsttraining_sim_parse_wp_time(?string $value): int {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, wp_timezone());
    if ($date instanceof DateTimeImmutable) {
        return $date->getTimestamp();
    }

    $timestamp = strtotime($value);
    return $timestamp > 0 ? $timestamp : 0;
}

function lsttraining_sim_radio_delay_seconds(): int {
    try {
        return random_int(1, 5);
    } catch (Throwable $e) {
        return mt_rand(1, 5);
    }
}

function lsttraining_sim_meta_with_radio_delay(array $meta, $base_time = null): array {
    if (!empty($meta['radio_delay_disabled']) || !empty($meta['radio_visible_at'])) {
        unset($meta['radio_base_at']);
        return $meta;
    }

    $delay = lsttraining_sim_radio_delay_seconds();
    $base_ts = 0;
    if (is_int($base_time) || is_float($base_time)) {
        $base_ts = (int) $base_time;
    } elseif (is_string($base_time) && trim($base_time) !== '') {
        $base_ts = lsttraining_sim_parse_wp_time($base_time);
    }
    if ($base_ts <= 0 && !empty($meta['radio_base_at'])) {
        $base_ts = lsttraining_sim_parse_wp_time((string) $meta['radio_base_at']);
    }
    if ($base_ts <= 0 && !empty($meta['opened_at'])) {
        $base_ts = lsttraining_sim_parse_wp_time((string) $meta['opened_at']);
    }
    if ($base_ts <= 0 && !empty($meta['alarmiert_at'])) {
        $base_ts = lsttraining_sim_parse_wp_time((string) $meta['alarmiert_at']);
    }
    if ($base_ts <= 0) {
        $base_ts = time();
    }

    unset($meta['radio_base_at']);
    $meta['radio_delay_sec'] = $delay;
    $meta['radio_visible_at'] = lsttraining_sim_time_string($base_ts + $delay);
    return $meta;
}

function lsttraining_sim_event_radio_visible(array $event, int $now): bool {
    $visible_at = lsttraining_sim_event_radio_visible_timestamp($event);
    return $visible_at <= 0 || $visible_at <= $now;
}

function lsttraining_sim_event_radio_visible_timestamp(array $event): int {
    $meta = is_array($event['meta'] ?? null) ? $event['meta'] : lsttraining_sim_decode_meta((string) ($event['meta_json'] ?? ''));
    return lsttraining_sim_parse_wp_time((string) ($meta['radio_visible_at'] ?? ''));
}

function lsttraining_sim_event_radio_time(array $event): string {
    $meta = is_array($event['meta'] ?? null) ? $event['meta'] : lsttraining_sim_decode_meta((string) ($event['meta_json'] ?? ''));
    $visible_at = trim((string) ($meta['radio_visible_at'] ?? ''));
    return $visible_at !== '' ? $visible_at : (string) ($event['ts'] ?? '');
}

function lsttraining_sim_distance_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth = 6371000.0;
    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);
    $delta_lat = $lat2 - $lat1;
    $delta_lon = deg2rad($lon2 - $lon1);

    $a = sin($delta_lat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($delta_lon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

function lsttraining_sim_route_length_m(array $coordinates): float {
    $length = 0.0;
    $previous = null;

    foreach ($coordinates as $coord) {
        if (!is_array($coord) || count($coord) < 2) {
            continue;
        }

        $current = [(float) $coord[0], (float) $coord[1]];
        if ($previous) {
            $length += lsttraining_sim_distance_m($previous[1], $previous[0], $current[1], $current[0]);
        }
        $previous = $current;
    }

    return $length;
}

function lsttraining_sim_route_bearing_deg(array $from, array $to): float {
    $lat1 = deg2rad((float) $from[1]);
    $lat2 = deg2rad((float) $to[1]);
    $delta_lon = deg2rad((float) $to[0] - (float) $from[0]);

    $y = sin($delta_lon) * cos($lat2);
    $x = (cos($lat1) * sin($lat2)) - (sin($lat1) * cos($lat2) * cos($delta_lon));
    $bearing = rad2deg(atan2($y, $x));
    return fmod($bearing + 360.0, 360.0);
}

function lsttraining_sim_turn_penalty(array $previous, array $current, array $next): float {
    $in_len = lsttraining_sim_distance_m($previous[1], $previous[0], $current[1], $current[0]);
    $out_len = lsttraining_sim_distance_m($current[1], $current[0], $next[1], $next[0]);
    if ($in_len < 8 || $out_len < 8) {
        return 0.0;
    }

    $a = lsttraining_sim_route_bearing_deg($previous, $current);
    $b = lsttraining_sim_route_bearing_deg($current, $next);
    $delta = abs($a - $b);
    $turn = min($delta, 360.0 - $delta);
    if ($turn < 25) {
        return 0.0;
    }

    return min(0.45, max(0.0, ($turn - 25.0) / 155.0) * 0.45);
}

function lsttraining_sim_interpolate_route_position(array $coordinates, float $progress): ?array {
    $points = [];
    foreach ($coordinates as $coord) {
        if (!is_array($coord) || count($coord) < 2) {
            continue;
        }
        $lon = (float) $coord[0];
        $lat = (float) $coord[1];
        if (is_finite($lon) && is_finite($lat)) {
            $points[] = [$lon, $lat];
        }
    }

    if (!$points) {
        return null;
    }
    if (count($points) === 1 || $progress <= 0) {
        return ['longitude' => $points[0][0], 'latitude' => $points[0][1]];
    }
    if ($progress >= 1) {
        $last = $points[count($points) - 1];
        return ['longitude' => $last[0], 'latitude' => $last[1]];
    }

    $segments = [];
    $total = 0.0;
    for ($i = 1; $i < count($points); $i++) {
        $a = $points[$i - 1];
        $b = $points[$i];
        $length = lsttraining_sim_distance_m($a[1], $a[0], $b[1], $b[0]);
        if ($length <= 0) {
            continue;
        }

        $segments[] = [
            'from' => $a,
            'to' => $b,
            'length' => $length,
        ];
        $total += $length;
    }

    if ($total <= 0) {
        $last = $points[count($points) - 1];
        return ['longitude' => $last[0], 'latitude' => $last[1]];
    }

    $target = $total * $progress;
    $walked = 0.0;
    foreach ($segments as $segment) {
        if ($walked + $segment['length'] >= $target) {
            $a = $segment['from'];
            $b = $segment['to'];
            $ratio = ($target - $walked) / $segment['length'];
            return [
                'longitude' => $a[0] + (($b[0] - $a[0]) * $ratio),
                'latitude' => $a[1] + (($b[1] - $a[1]) * $ratio),
            ];
        }
        $walked += $segment['length'];
    }

    $last = $points[count($points) - 1];
    return ['longitude' => $last[0], 'latitude' => $last[1]];
}

function lsttraining_sim_normalize_route_geojson(array $route_geojson): array {
    $route = $route_geojson;
    for ($i = 0; $i < 4; $i++) {
        if (isset($route['features']) || isset($route['geometry'])) {
            return $route;
        }
        if (isset($route['data']) && is_array($route['data'])) {
            $route = $route['data'];
            continue;
        }
        if (isset($route['route_geojson']) && is_array($route['route_geojson'])) {
            $route = $route['route_geojson'];
            continue;
        }
        break;
    }
    return $route;
}

function lsttraining_sim_extract_route_coordinates(array $route_geojson): array {
    $features = $route_geojson['features'] ?? [];
    $geometry = null;
    if (is_array($features) && isset($features[0]['geometry']) && is_array($features[0]['geometry'])) {
        $geometry = $features[0]['geometry'];
    } elseif (isset($route_geojson['geometry']) && is_array($route_geojson['geometry'])) {
        $geometry = $route_geojson['geometry'];
    }

    if (!$geometry || ($geometry['type'] ?? '') !== 'LineString' || !is_array($geometry['coordinates'] ?? null)) {
        return [];
    }

    $coordinates = [];
    foreach ($geometry['coordinates'] as $coord) {
        if (!is_array($coord) || count($coord) < 2) {
            continue;
        }
        $lon = round((float) $coord[0], 6);
        $lat = round((float) $coord[1], 6);
        if (is_finite($lon) && is_finite($lat)) {
            $coordinates[] = [$lon, $lat];
        }
    }

    $count = count($coordinates);
    if ($count <= 600) {
        return $coordinates;
    }

    $reduced = [];
    $step = max(1, (int) floor($count / 580));
    foreach ($coordinates as $index => $coord) {
        if ($index === 0 || $index === $count - 1 || $index % $step === 0) {
            $reduced[] = $coord;
        }
    }

    return $reduced;
}

function lsttraining_sim_route_summary(array $route_geojson, array $coordinates): array {
    $properties = $route_geojson['features'][0]['properties'] ?? [];
    $summary = is_array($properties) ? ($properties['summary'] ?? []) : [];
    $distance = is_array($summary) ? (float) ($summary['distance'] ?? 0) : 0.0;
    $duration = is_array($summary) ? (float) ($summary['duration'] ?? 0) : 0.0;

    if ($distance <= 0) {
        $distance = lsttraining_sim_route_length_m($coordinates);
    }
    if ($duration <= 0 && $distance > 0) {
        // Fallback: rund 50 km/h Durchschnittsgeschwindigkeit.
        $duration = max(60.0, $distance / 13.9);
    }

    return [
        'distance_m' => (int) round($distance),
        'duration_sec' => (int) max(60, round($duration)),
    ];
}

function lsttraining_sim_route_result_success(array $route, string $stage = 'server_route'): array {
    return [
        'ok' => true,
        'route' => $route,
        'stage' => $stage,
    ];
}

function lsttraining_sim_route_result_error(string $code, string $message, string $detail = '', string $stage = 'server_route', int $http_status = 0): array {
    return [
        'ok' => false,
        'error_code' => $code,
        'message' => $message,
        'technical_detail' => $detail,
        'stage' => $stage,
        'http_status' => $http_status,
    ];
}

function lsttraining_sim_ors_error_detail($json, string $body, int $http_status): string {
    if (is_array($json)) {
        foreach ([
            ['error', 'message'],
            ['error'],
            ['message'],
            ['detail'],
        ] as $path) {
            $value = $json;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
    }

    $body = trim(wp_strip_all_tags($body));
    if ($body !== '') {
        return substr($body, 0, 300);
    }

    return 'HTTP ' . $http_status;
}

function lsttraining_sim_server_route_result(float $from_lat, float $from_lon, float $to_lat, float $to_lon, string $preference = 'fastest'): array {
    static $routing_unavailable = false;
    if ($routing_unavailable || !empty($GLOBALS['lsttraining_sim_server_route_unavailable'])) {
        return lsttraining_sim_route_result_error(
            'ors_request_failed',
            'Routingdienst ist aktuell nicht verfügbar.',
            'Eine vorherige ORS-Anfrage ist in diesem Request fehlgeschlagen.',
            'server_route'
        );
    }

    $api_key = get_option('lsttraining_ors_key', '');
    $api_key = is_string($api_key) ? trim($api_key) : '';
    if ($api_key === '') {
        $routing_unavailable = true;
        $GLOBALS['lsttraining_sim_server_route_unavailable'] = true;
        return lsttraining_sim_route_result_error(
            'ors_key_missing',
            'Routing-API-Key fehlt.',
            'WordPress-Option lsttraining_ors_key ist leer.',
            'server_route'
        );
    }

    $preference = in_array($preference, ['fastest', 'recommended', 'shortest'], true) ? $preference : 'fastest';
    $coordinates = [
        [round($from_lon, 6), round($from_lat, 6)],
        [round($to_lon, 6), round($to_lat, 6)],
    ];
    $cache_key = 'lst_sim_srv_route_' . md5(wp_json_encode([
        'coordinates' => $coordinates,
        'preference' => $preference,
    ]));
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return lsttraining_sim_route_result_success($cached, 'server_route_cache');
    }

    $response = wp_remote_post('https://api.openrouteservice.org/v2/directions/driving-car/geojson', [
        'timeout' => 8,
        'headers' => [
            'Authorization' => $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'coordinates' => $coordinates,
            'preference' => $preference,
        ]),
    ]);

    if (is_wp_error($response)) {
        $detail = $response->get_error_message();
        error_log('[LSTtraining][server_route] ' . $detail);
        $routing_unavailable = true;
        $GLOBALS['lsttraining_sim_server_route_unavailable'] = true;
        return lsttraining_sim_route_result_error(
            'ors_request_failed',
            'Routingdienst konnte nicht erreicht werden.',
            $detail,
            'server_route'
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json)) {
        $detail = lsttraining_sim_ors_error_detail($json, $body, $code);
        error_log('[LSTtraining][server_route] ORS response code=' . $code . ' detail=' . $detail);
        if ($code >= 500 || $code === 401 || $code === 403 || $code === 429) {
            $routing_unavailable = true;
            $GLOBALS['lsttraining_sim_server_route_unavailable'] = true;
        }
        return lsttraining_sim_route_result_error(
            'ors_bad_response',
            'Routingdienst hat die Route abgelehnt.',
            $detail,
            'server_route',
            $code
        );
    }

    $route_geojson = lsttraining_sim_normalize_route_geojson($json);
    $route_coordinates = lsttraining_sim_extract_route_coordinates($route_geojson);
    if (count($route_coordinates) < 2) {
        return lsttraining_sim_route_result_error(
            'ors_no_geometry',
            'ORS lieferte keine verwertbare Linienführung.',
            'Die Antwort enthielt weniger als zwei Routenkoordinaten.',
            'server_route',
            $code
        );
    }

    $summary = lsttraining_sim_route_summary($route_geojson, $route_coordinates);
    $route = [
        'coordinates' => $route_coordinates,
        'distance_m' => (int) ($summary['distance_m'] ?? 0),
        'duration_sec' => (int) ($summary['duration_sec'] ?? 0),
        'route_geojson' => $route_geojson,
    ];
    set_transient($cache_key, $route, 5 * MINUTE_IN_SECONDS);
    return lsttraining_sim_route_result_success($route, 'server_route');
}

function lsttraining_sim_server_route(float $from_lat, float $from_lon, float $to_lat, float $to_lon, string $preference = 'fastest'): ?array {
    $result = lsttraining_sim_server_route_result($from_lat, $from_lon, $to_lat, $to_lon, $preference);
    if (!empty($result['ok']) && is_array($result['route'] ?? null)) {
        return $result['route'];
    }
    return null;
}

function lsttraining_sim_route_duration_for_signal(int $duration_sec, bool $sondersignal_allowed): int {
    $duration_sec = max(30, $duration_sec);
    if (!$sondersignal_allowed) {
        return $duration_sec;
    }

    // Signalfahrt: 10 Prozent höhere Geschwindigkeit, also entsprechend kürzere Dauer.
    return max(20, (int) round($duration_sec / 1.10));
}

function lsttraining_sim_route_segment(string $type, array $coordinates, int $duration_sec, int $distance_m = 0): array {
    $clean = [];
    foreach ($coordinates as $coord) {
        if (!is_array($coord) || count($coord) < 2) {
            continue;
        }
        $lon = round((float) $coord[0], 6);
        $lat = round((float) $coord[1], 6);
        if (is_finite($lon) && is_finite($lat)) {
            $clean[] = [$lon, $lat];
        }
    }

    return [
        'type' => in_array($type, ['road', 'connector', 'air'], true) ? $type : 'road',
        'coordinates' => $clean,
        'duration_sec' => max(1, $duration_sec),
        'distance_m' => max(0, $distance_m),
    ];
}

function lsttraining_sim_route_segments_total_duration(array $segments): int {
    $duration = 0;
    foreach ($segments as $segment) {
        if (!is_array($segment)) {
            continue;
        }
        $duration += max(0, (int) ($segment['duration_sec'] ?? 0));
    }
    return max(0, $duration);
}

function lsttraining_sim_route_segments_for_signal(array $segments, bool $sondersignal_allowed): array {
    if (!$sondersignal_allowed) {
        return $segments;
    }
    foreach ($segments as &$segment) {
        if (!is_array($segment)) {
            continue;
        }
        $segment['duration_sec'] = lsttraining_sim_route_duration_for_signal((int) ($segment['duration_sec'] ?? 0), true);
    }
    unset($segment);
    return $segments;
}

function lsttraining_sim_route_segments_scaled_to_duration(array $segments, int $target_duration): array {
    $segments = lsttraining_sim_normalize_route_segments($segments);
    if (!$segments) {
        return [];
    }
    $current_duration = lsttraining_sim_route_segments_total_duration($segments);
    $target_duration = max(count($segments), $target_duration);
    if ($current_duration <= 0 || $target_duration <= 0 || $current_duration === $target_duration) {
        return $segments;
    }

    $scaled = [];
    $assigned = 0;
    foreach ($segments as $index => $segment) {
        $duration = max(1, (int) ($segment['duration_sec'] ?? 1));
        if ($index === count($segments) - 1) {
            $next_duration = max(1, $target_duration - $assigned);
        } else {
            $next_duration = max(1, (int) round(($duration / $current_duration) * $target_duration));
            $assigned += $next_duration;
        }
        $segment['duration_sec'] = $next_duration;
        $scaled[] = $segment;
    }

    return $scaled;
}

function lsttraining_sim_flatten_route_segments(array $segments): array {
    $route = [];
    foreach ($segments as $segment) {
        if (!is_array($segment)) {
            continue;
        }
        foreach (($segment['coordinates'] ?? []) as $coord) {
            if (!is_array($coord) || count($coord) < 2) {
                continue;
            }
            $point = [round((float) $coord[0], 6), round((float) $coord[1], 6)];
            $last = $route ? $route[count($route) - 1] : null;
            if ($last && abs($last[0] - $point[0]) < 0.000001 && abs($last[1] - $point[1]) < 0.000001) {
                continue;
            }
            $route[] = $point;
        }
    }
    return $route;
}

function lsttraining_sim_normalize_route_segments($raw): array {
    $segments = is_array($raw) ? $raw : [];
    $normalized = [];
    foreach ($segments as $segment) {
        if (!is_array($segment)) {
            continue;
        }
        $normalized[] = lsttraining_sim_route_segment(
            (string) ($segment['type'] ?? 'road'),
            is_array($segment['coordinates'] ?? null) ? $segment['coordinates'] : [],
            (int) ($segment['duration_sec'] ?? 0),
            (int) ($segment['distance_m'] ?? 0)
        );
    }
    return array_values(array_filter($normalized, static function (array $segment): bool {
        return count($segment['coordinates'] ?? []) >= 2;
    }));
}

function lsttraining_sim_route_movement_state(array $meta, int $elapsed_sec): ?array {
    $segments = lsttraining_sim_normalize_route_segments($meta['route_segments'] ?? []);
    if (!$segments) {
        $coordinates = is_array($meta['route_coordinates'] ?? null) ? $meta['route_coordinates'] : [];
        if (count($coordinates) < 2) {
            return null;
        }
        $duration = max(30, (int) ($meta['route_duration_sec'] ?? 120));
        $progress = min(1.0, max(0.0, $elapsed_sec / $duration));
        $position = lsttraining_sim_interpolate_route_position($coordinates, $progress);
        if (!$position) {
            return null;
        }
        return [
            'position' => $position,
            'progress' => $progress,
            'segment_index' => 0,
            'segment_progress' => $progress,
        ];
    }

    $total_duration = max(1, lsttraining_sim_route_segments_total_duration($segments));
    $elapsed_sec = max(0, $elapsed_sec);
    $total_progress = min(1.0, $elapsed_sec / $total_duration);
    $walked = 0;
    foreach ($segments as $index => $segment) {
        $duration = max(1, (int) ($segment['duration_sec'] ?? 1));
        if ($elapsed_sec <= $walked + $duration || $index === count($segments) - 1) {
            $segment_progress = min(1.0, max(0.0, ($elapsed_sec - $walked) / $duration));
            $position = lsttraining_sim_interpolate_route_position((array) ($segment['coordinates'] ?? []), $segment_progress);
            if (!$position) {
                return null;
            }
            return [
                'position' => $position,
                'progress' => $total_progress,
                'segment_index' => $index,
                'segment_progress' => $segment_progress,
            ];
        }
        $walked += $duration;
    }

    return null;
}

function lsttraining_sim_route_geojson_provider(array $route_geojson): string {
    $properties = $route_geojson['features'][0]['properties'] ?? ($route_geojson['properties'] ?? []);
    return is_array($properties) ? (string) ($properties['provider'] ?? '') : '';
}

function lsttraining_sim_route_start_candidates(float $from_lat, float $from_lon, int $limit = 8, float $max_radius_m = 1500.0, ?array $road_paths = null): array {
    if (!empty($GLOBALS['lsttraining_sim_server_route_unavailable']) || !function_exists('lsttraining_sim_open_gzip_lines')) {
        return [];
    }
    if ($road_paths === null) {
        $path = function_exists('lsttraining_sim_layer_path') ? lsttraining_sim_layer_path('roads_lines') : '';
        $road_paths = $path !== '' && is_readable($path) ? [$path] : [];
    }
    $road_paths = array_values(array_filter($road_paths, 'is_readable'));
    if (!$road_paths) {
        return [];
    }

    $delta = $max_radius_m / 111320.0;
    $items = [];
    $seen = [];
    foreach ($road_paths as $path) {
        foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
            if (strpos($line, 'LineString') === false) {
                continue;
            }
            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }
            $properties = function_exists('lsttraining_sim_osm_feature_properties')
                ? lsttraining_sim_osm_feature_properties($feature)
                : (array) ($feature['properties'] ?? []);
            if (function_exists('lsttraining_sim_road_is_dispatchable') && !lsttraining_sim_road_is_dispatchable($properties)) {
                continue;
            }
            foreach (lsttraining_sim_line_segments_from_geometry($feature['geometry'] ?? []) as $segment) {
                if (count($segment) < 2) {
                    continue;
                }
                $a = $segment[0];
                $b = $segment[1];
                if (
                    !is_array($a) || !is_array($b) ||
                    abs((float) $a[0] - $from_lon) > $delta && abs((float) $b[0] - $from_lon) > $delta ||
                    abs((float) $a[1] - $from_lat) > $delta && abs((float) $b[1] - $from_lat) > $delta
                ) {
                    continue;
                }
                $point = lsttraining_sim_police_support_closest_point_on_segment($from_lon, $from_lat, $a, $b);
                $distance = lsttraining_sim_distance_m($from_lat, $from_lon, (float) $point['latitude'], (float) $point['longitude']);
                if ($distance <= 15.0 || $distance > $max_radius_m) {
                    continue;
                }
                $key = round((float) $point['latitude'], 5) . ':' . round((float) $point['longitude'], 5);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = [
                    'latitude' => (float) $point['latitude'],
                    'longitude' => (float) $point['longitude'],
                    'distance_m' => $distance,
                ];
                if (count($items) >= $limit * 4) {
                    break 3;
                }
            }
        }
    }

    usort($items, static function (array $a, array $b): int {
        return ($a['distance_m'] <=> $b['distance_m']);
    });
    return array_slice($items, 0, $limit);
}

function lsttraining_sim_ground_route_with_connector_result(float $from_lat, float $from_lon, float $to_lat, float $to_lon, ?array $road_paths = null): array {
    $direct_result = lsttraining_sim_server_route_result($from_lat, $from_lon, $to_lat, $to_lon, 'fastest');
    $direct = !empty($direct_result['ok']) && is_array($direct_result['route'] ?? null) ? $direct_result['route'] : null;
    if (is_array($direct) && count($direct['coordinates'] ?? []) >= 2) {
        $road_coordinates = (array) $direct['coordinates'];
        $first = $road_coordinates[0] ?? null;
        $start_snap_distance = is_array($first) && count($first) >= 2
            ? lsttraining_sim_distance_m($from_lat, $from_lon, (float) $first[1], (float) $first[0])
            : 0.0;
        $segment = lsttraining_sim_route_segment('road', $road_coordinates, (int) ($direct['duration_sec'] ?? 0), (int) ($direct['distance_m'] ?? 0));
        if ($start_snap_distance > 15.0) {
            $connector = lsttraining_sim_route_segment('connector', [
                [round($from_lon, 6), round($from_lat, 6)],
                [round((float) $first[0], 6), round((float) $first[1], 6)],
            ], max(8, (int) round($start_snap_distance / 8.3)), (int) round($start_snap_distance));
            $segments = [$connector, $segment];
            return lsttraining_sim_route_result_success([
                'coordinates' => lsttraining_sim_flatten_route_segments($segments),
                'distance_m' => (int) round($start_snap_distance) + (int) ($direct['distance_m'] ?? lsttraining_sim_route_length_m($road_coordinates)),
                'duration_sec' => lsttraining_sim_route_segments_total_duration($segments),
                'route_source' => 'routing_connector',
                'route_segments' => $segments,
                'route_geojson' => $direct['route_geojson'] ?? null,
            ], 'direct_route_with_connector');
        }
        return lsttraining_sim_route_result_success([
            'coordinates' => $segment['coordinates'],
            'distance_m' => (int) ($direct['distance_m'] ?? lsttraining_sim_route_length_m($segment['coordinates'])),
            'duration_sec' => (int) ($direct['duration_sec'] ?? 0),
            'route_source' => 'routing',
            'route_segments' => [$segment],
            'route_geojson' => $direct['route_geojson'] ?? null,
        ], 'direct_route');
    }

    if (in_array((string) ($direct_result['error_code'] ?? ''), ['ors_key_missing', 'ors_request_failed', 'ors_bad_response'], true)) {
        return $direct_result;
    }

    $best = null;
    $last_error = $direct_result;
    $candidates = lsttraining_sim_route_start_candidates($from_lat, $from_lon, 8, 1500.0, $road_paths);
    if (!$candidates) {
        return lsttraining_sim_route_result_error(
            'no_start_candidates',
            'Kein routbarer Straßenpunkt in Fahrzeugnähe gefunden.',
            'Direkte Route: ' . (string) ($direct_result['message'] ?? 'nicht verfügbar'),
            'connector_candidates'
        );
    }
    foreach ($candidates as $candidate) {
        $road_result = lsttraining_sim_server_route_result((float) $candidate['latitude'], (float) $candidate['longitude'], $to_lat, $to_lon, 'fastest');
        $road = !empty($road_result['ok']) && is_array($road_result['route'] ?? null) ? $road_result['route'] : null;
        if (!is_array($road) || count($road['coordinates'] ?? []) < 2) {
            $last_error = $road_result;
            continue;
        }
        $connector_distance = lsttraining_sim_distance_m($from_lat, $from_lon, (float) $candidate['latitude'], (float) $candidate['longitude']);
        $connector_duration = max(8, (int) round($connector_distance / 8.3));
        $connector = lsttraining_sim_route_segment('connector', [
            [round($from_lon, 6), round($from_lat, 6)],
            [round((float) $candidate['longitude'], 6), round((float) $candidate['latitude'], 6)],
        ], $connector_duration, (int) round($connector_distance));
        $road_segment = lsttraining_sim_route_segment('road', $road['coordinates'], (int) ($road['duration_sec'] ?? 0), (int) ($road['distance_m'] ?? 0));
        $segments = [$connector, $road_segment];
        $duration = lsttraining_sim_route_segments_total_duration($segments);
        $distance = (int) round($connector_distance) + (int) ($road['distance_m'] ?? 0);
        $candidate_route = [
            'coordinates' => lsttraining_sim_flatten_route_segments($segments),
            'distance_m' => $distance,
            'duration_sec' => $duration,
            'route_source' => 'routing_connector',
            'route_segments' => $segments,
            'route_geojson' => $road['route_geojson'] ?? null,
        ];
        if ($best === null || $duration < (int) ($best['duration_sec'] ?? PHP_INT_MAX)) {
            $best = $candidate_route;
            if ($connector_distance <= 80.0) {
                break;
            }
        }
    }

    if ($best !== null) {
        return lsttraining_sim_route_result_success($best, 'connector_route');
    }

    return lsttraining_sim_route_result_error(
        'route_not_found',
        'Keine Route zwischen Fahrzeug und Einsatzort gefunden.',
        'Direkte Route und ' . count($candidates) . ' Straßenkandidaten ohne verwertbares Ergebnis. Letzter Fehler: ' . (string) ($last_error['message'] ?? ''),
        'connector_route',
        (int) ($last_error['http_status'] ?? 0)
    );
}

function lsttraining_sim_ground_route_with_connector(float $from_lat, float $from_lon, float $to_lat, float $to_lon, ?array $road_paths = null): ?array {
    $result = lsttraining_sim_ground_route_with_connector_result($from_lat, $from_lon, $to_lat, $to_lon, $road_paths);
    if (!empty($result['ok']) && is_array($result['route'] ?? null)) {
        return $result['route'];
    }
    return null;
}

function lsttraining_sim_insert_unit_event(PDO $pdo, int $einsatz_id, string $text, array $meta): int {
    $meta = lsttraining_sim_meta_with_radio_delay($meta);
    $stmt = $pdo->prepare('
        INSERT INTO instanz_einsatz_events (instanz_einsatz_id, kind, text, meta_json)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([
        $einsatz_id,
        'unit_report',
        $text,
        lsttraining_sim_encode_meta($meta),
    ]);
    return (int) $pdo->lastInsertId();
}

function lsttraining_sim_insert_dispatch_event(PDO $pdo, int $einsatz_id, string $text, array $meta): int {
    $stmt = $pdo->prepare('
        INSERT INTO instanz_einsatz_events (instanz_einsatz_id, kind, text, meta_json)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([
        $einsatz_id,
        'dispatcher_question',
        $text,
        lsttraining_sim_encode_meta($meta),
    ]);
    return (int) $pdo->lastInsertId();
}

function lsttraining_sim_handover_window(PDO $pdo, int $instanz_id, array $meta, int $now): array {
    $triage = strtoupper((string) ($meta['transport_triage_category'] ?? 'III'));
    $ranges = [
        'I' => [10, 20],
        'II' => [15, 30],
        'III' => [20, 45],
        'IV' => [30, 60],
    ];
    $range = $ranges[$triage] ?? $ranges['III'];
    $base_minutes = mt_rand($range[0], $range[1]);
    $hospital_id = (int) ($meta['transport_hospital_id'] ?? 0);
    $occupied = 0;

    if ($hospital_id > 0) {
        $stmt = $pdo->prepare('
            SELECT ev.meta_json
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ie.instanz_id = ? AND ev.kind = ?
            ORDER BY ev.id DESC
            LIMIT 500
        ');
        $stmt->execute([$instanz_id, 'unit_report']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $raw_meta) {
            $other = lsttraining_sim_decode_meta($raw_meta);
            if (($other['event_type'] ?? '') !== 'vehicle_alarm' || !empty($other['cancelled_at'])) {
                continue;
            }
            if ((string) ($other['mission_phase'] ?? '') === 'handover' && (int) ($other['transport_hospital_id'] ?? 0) === $hospital_id) {
                $occupied++;
            }
        }
    }

    $occupancy_minutes = min(30, $occupied * 5);
    $fallback_minutes = !empty($meta['transport_department_fallback']) ? 10 : 0;
    $local_time = (new DateTimeImmutable('@' . $now))->setTimezone(wp_timezone());
    $hour = (int) $local_time->format('G');
    $night_minutes = ($hour >= 22 || $hour < 6) ? -2 : 0;
    $duration_minutes = max(5, $base_minutes + $occupancy_minutes + $fallback_minutes + $night_minutes);

    return [
        'triage_category' => $triage,
        'base_minutes' => $base_minutes,
        'occupancy_minutes' => $occupancy_minutes,
        'department_fallback_minutes' => $fallback_minutes,
        'night_minutes' => $night_minutes,
        'duration_sec' => $duration_minutes * 60,
        'release_at' => lsttraining_sim_time_string($now + ($duration_minutes * 60)),
    ];
}

function lsttraining_sim_incident_close_blockers(PDO $pdo, int $einsatz_id): array {
    $blockers = [];
    $stmt = $pdo->prepare('SELECT meta_json FROM instanz_einsaetze WHERE id = ? LIMIT 1');
    $stmt->execute([$einsatz_id]);
    $incident_meta = lsttraining_sim_decode_meta($stmt->fetchColumn() ?: '');
    foreach ((array) ($incident_meta['patients'] ?? []) as $patient) {
        if (!is_array($patient)) {
            continue;
        }
        if (in_array((string) ($patient['transport_status'] ?? ''), ['ready', 'to_hospital', 'handover'], true)) {
            $blockers['patient_transport'] = 'Patiententransport ist noch nicht abgeschlossen.';
        }
    }
    if (!empty($incident_meta['pending_resource_request'])) {
        $required = is_array($incident_meta['required_resources'] ?? null) ? $incident_meta['required_resources'] : [];
        if (lsttraining_sim_missing_resources_for_incident($pdo, $einsatz_id, $required)) {
            $blockers['resources'] = 'Eine Nachforderung ist noch offen.';
        }
    }

    $events = $pdo->prepare('SELECT meta_json FROM instanz_einsatz_events WHERE instanz_einsatz_id = ? AND kind = ?');
    $events->execute([$einsatz_id, 'unit_report']);
    foreach (($events->fetchAll(PDO::FETCH_COLUMN) ?: []) as $raw_meta) {
        $meta = lsttraining_sim_decode_meta($raw_meta);
        if (($meta['event_type'] ?? '') === 'vehicle_alarm' && empty($meta['cancelled_at']) && (string) ($meta['mission_phase'] ?? '') !== 'available') {
            $blockers['vehicle'] = 'Dem Einsatz sind noch Fahrzeuge zugeordnet.';
        }
        if (($meta['event_type'] ?? '') === 'situation_report' && !empty($meta['requires_ack']) && empty($meta['acknowledged_at'])) {
            $blockers['radio'] = 'Es gibt noch einen offenen Sprechwunsch.';
        }
    }

    return array_values($blockers);
}

function lsttraining_sim_fire_phase_followups(PDO $pdo, int $einsatz_id, array &$assignment_meta, int $now, string $trigger_mode, string $command_code = '', int $reply_to_event_id = 0): bool {
    $emitted = is_array($assignment_meta['phase_followups_emitted'] ?? null) ? $assignment_meta['phase_followups_emitted'] : [];
    $emitted_key = $command_code !== '' ? $trigger_mode . ':' . $command_code : $trigger_mode;
    if ($trigger_mode !== 'on_dispatcher_question' && !empty($emitted[$emitted_key])) {
        return false;
    }

    $incident_stmt = $pdo->prepare('SELECT source_id FROM instanz_einsaetze WHERE id = ? LIMIT 1');
    $incident_stmt->execute([$einsatz_id]);
    $source_id = (int) $incident_stmt->fetchColumn();
    if ($source_id <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('
        SELECT id, text, effect_json, speaker_type
        FROM einsatz_followups
        WHERE einsatz_id = ? AND kind = ? AND trigger_mode = ?
        ORDER BY step_no ASC, id ASC
    ');
    $stmt->execute([$source_id, 'unit_report', $trigger_mode]);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $did_emit = false;
    foreach ($followups as $followup) {
        $effect = lsttraining_sim_decode_meta($followup['effect_json'] ?? '');
        $configured_command = (string) ($effect['command_code'] ?? '');
        if ($command_code !== '' && $configured_command !== '' && $configured_command !== $command_code) {
            continue;
        }
        $text = trim((string) ($followup['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $rufname = (string) ($assignment_meta['rufname'] ?? 'Fahrzeug');
        $event_meta = [
            'event_type' => 'phase_followup',
            'radio_message_type' => $trigger_mode,
            'sender_type' => 'vehicle',
            'recipient_type' => 'dispatch',
            'followup_id' => (int) ($followup['id'] ?? 0),
            'trigger_mode' => $trigger_mode,
            'command_code' => $command_code,
            'status_id' => (int) ($assignment_meta['status_id'] ?? 0),
            'fahrzeug_id' => (int) ($assignment_meta['fahrzeug_id'] ?? 0),
            'rufname' => $rufname,
            'requires_ack' => false,
            'radio_base_at' => lsttraining_sim_time_string($now),
        ];
        if ($reply_to_event_id > 0) {
            $event_meta['reply_to_event_id'] = $reply_to_event_id;
        }
        lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': ' . $text . ', Ende.', $event_meta);
        $did_emit = true;
        if ($command_code !== '') {
            break;
        }
    }

    if ($did_emit && $trigger_mode !== 'on_dispatcher_question') {
        $emitted[$emitted_key] = lsttraining_sim_time_string($now);
        $assignment_meta['phase_followups_emitted'] = $emitted;
    }
    return $did_emit;
}

function lsttraining_sim_police_vehicle_image_path(PDO $pdo, int $leitstelle_id): string {
    $fallback = 'img/fahrzeug/default_pol.png';
    if ($leitstelle_id <= 0) {
        return $fallback;
    }

    $columns = function_exists('lsttraining_sim_workspace_table_columns')
        ? lsttraining_sim_workspace_table_columns($pdo, 'leitstellen')
        : [];
    if (empty($columns['police_vehicle_image'])) {
        return $fallback;
    }

    try {
        $stmt = $pdo->prepare('SELECT police_vehicle_image FROM leitstellen WHERE id = ? LIMIT 1');
        $stmt->execute([$leitstelle_id]);
        $image = trim((string) $stmt->fetchColumn());
        return $image !== '' ? $image : $fallback;
    } catch (Throwable $e) {
        error_log('[LSTtraining][police_vehicle_image] ' . $e->getMessage());
        return $fallback;
    }
}

function lsttraining_sim_police_vehicle_image_url(PDO $pdo, int $leitstelle_id): string {
    return lsttraining_sim_public_vehicle_image_url(
        lsttraining_sim_police_vehicle_image_path($pdo, $leitstelle_id),
        'img/fahrzeug/default_pol.png'
    );
}

function lsttraining_sim_leitstelle_vehicle_defaults(PDO $pdo, int $leitstelle_id): array {
    static $cache = [];
    $leitstelle_id = max(0, $leitstelle_id);
    if (isset($cache[$leitstelle_id])) {
        return $cache[$leitstelle_id];
    }

    $defaults = [
        'police_vehicle_image' => 'img/fahrzeug/default_pol.png',
        'police_signal_lights_json' => '',
        'rescue_vehicle_image' => 'img/fahrzeug/default.png',
        'rescue_signal_lights_json' => '',
    ];
    if ($leitstelle_id <= 0) {
        $cache[$leitstelle_id] = $defaults;
        return $defaults;
    }

    $columns = function_exists('lsttraining_sim_workspace_table_columns')
        ? lsttraining_sim_workspace_table_columns($pdo, 'leitstellen')
        : [];
    $selects = [];
    foreach (array_keys($defaults) as $column) {
        if (!empty($columns[$column])) {
            $selects[] = $column;
        }
    }
    if (!$selects) {
        $cache[$leitstelle_id] = $defaults;
        return $defaults;
    }

    try {
        $stmt = $pdo->prepare('SELECT ' . implode(',', $selects) . ' FROM leitstellen WHERE id = ? LIMIT 1');
        $stmt->execute([$leitstelle_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($defaults as $key => $fallback) {
            if (array_key_exists($key, $row)) {
                $value = trim((string) $row[$key]);
                $defaults[$key] = $value !== '' ? $value : $fallback;
            }
        }
    } catch (Throwable $e) {
        error_log('[LSTtraining][leitstelle_vehicle_defaults] ' . $e->getMessage());
    }

    $cache[$leitstelle_id] = $defaults;
    return $defaults;
}

function lsttraining_sim_police_signal_lights_json(PDO $pdo, int $leitstelle_id): string {
    $defaults = lsttraining_sim_leitstelle_vehicle_defaults($pdo, $leitstelle_id);
    return (string) ($defaults['police_signal_lights_json'] ?? '');
}

function lsttraining_sim_rescue_vehicle_image_path(PDO $pdo, int $leitstelle_id): string {
    $defaults = lsttraining_sim_leitstelle_vehicle_defaults($pdo, $leitstelle_id);
    $image = trim((string) ($defaults['rescue_vehicle_image'] ?? ''));
    return $image !== '' ? $image : 'img/fahrzeug/default.png';
}

function lsttraining_sim_rescue_signal_lights_json(PDO $pdo, int $leitstelle_id): string {
    $defaults = lsttraining_sim_leitstelle_vehicle_defaults($pdo, $leitstelle_id);
    return (string) ($defaults['rescue_signal_lights_json'] ?? '');
}

function lsttraining_sim_vehicle_uses_rescue_default(array $vehicle): bool {
    $type = (string) ($vehicle['fahrzeugtyp'] ?? '');
    $class = lsttraining_sim_resource_class_from_type($type);
    if (!in_array($class, ['rettungswagen', 'krankentransport', 'notarzt'], true)) {
        return false;
    }
    return !preg_match('/\b(RTH|ITH|CHR|HUBSCHRAUBER|HELI)\b/i', $type . ' ' . (string) ($vehicle['rufname'] ?? ''));
}

function lsttraining_sim_apply_vehicle_visual_defaults(PDO $pdo, int $leitstelle_id, array &$vehicle): void {
    $use_rescue_default = lsttraining_sim_vehicle_uses_rescue_default($vehicle);
    $image_fallback = $use_rescue_default
        ? lsttraining_sim_rescue_vehicle_image_path($pdo, $leitstelle_id)
        : 'img/fahrzeug/default.png';
    $vehicle['image_url'] = lsttraining_sim_public_vehicle_image_url($vehicle['bild_datei'] ?? '', $image_fallback);

    $signal_raw = (string) ($vehicle['signal_lights_json'] ?? '');
    if ($use_rescue_default && !lsttraining_sim_signal_lights_raw_has_lights($signal_raw)) {
        $signal_raw = lsttraining_sim_rescue_signal_lights_json($pdo, $leitstelle_id);
    }
    $vehicle['signal_lights'] = lsttraining_sim_signal_lights_for_vehicle($signal_raw, (string) ($vehicle['fahrzeugtyp'] ?? ''), (string) ($vehicle['rufname'] ?? ''));
}

function lsttraining_sim_police_support_existing_event_id(PDO $pdo, int $einsatz_id): int {
    $stmt = $pdo->prepare('
        SELECT id, meta_json
        FROM instanz_einsatz_events
        WHERE instanz_einsatz_id = ? AND kind = ?
        ORDER BY id DESC
        LIMIT 80
    ');
    $stmt->execute([$einsatz_id, 'unit_report']);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $meta = lsttraining_sim_decode_meta($row['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'support_vehicle_alarm' || ($meta['support_type'] ?? '') !== 'police') {
            continue;
        }
        if (!empty($meta['cancelled_at'])) {
            continue;
        }
        return (int) ($row['id'] ?? 0);
    }
    return 0;
}

function lsttraining_sim_police_support_unavailable_event_exists(PDO $pdo, int $einsatz_id): bool {
    $stmt = $pdo->prepare('
        SELECT meta_json
        FROM instanz_einsatz_events
        WHERE instanz_einsatz_id = ? AND kind = ?
        ORDER BY id DESC
        LIMIT 80
    ');
    $stmt->execute([$einsatz_id, 'unit_report']);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $meta = lsttraining_sim_decode_meta($row['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') === 'support_vehicle_unavailable' && ($meta['support_type'] ?? '') === 'police') {
            return true;
        }
    }
    return false;
}

function lsttraining_sim_police_support_road_paths(PDO $pdo, int $leitstelle_id): array {
    if ($leitstelle_id <= 0 || !function_exists('lsttraining_sim_road_tile_state')) {
        return [];
    }

    $state = lsttraining_sim_road_tile_state($pdo, $leitstelle_id);
    return array_values(array_filter((array) ($state['paths'] ?? []), 'is_readable'));
}

function lsttraining_sim_police_support_route_from_candidate(array $candidate, float $incident_lat, float $incident_lon, array $road_paths = []): ?array {
    $lat = is_numeric($candidate['latitude'] ?? null) ? (float) $candidate['latitude'] : null;
    $lon = is_numeric($candidate['longitude'] ?? null) ? (float) $candidate['longitude'] : null;
    if ($lat === null || $lon === null) {
        return null;
    }

    $route = lsttraining_sim_ground_route_with_connector($lat, $lon, $incident_lat, $incident_lon, $road_paths);
    if (!$route || count($route['coordinates'] ?? []) < 2) {
        return null;
    }

    $route = lsttraining_sim_police_support_route_from_road_start($route);
    if (!$route || count($route['coordinates'] ?? []) < 2) {
        return null;
    }

    $start = $route['coordinates'][0];
    $candidate['latitude'] = round((float) ($start[1] ?? $lat), 6);
    $candidate['longitude'] = round((float) ($start[0] ?? $lon), 6);
    $candidate['route'] = $route;
    return $candidate;
}

function lsttraining_sim_police_support_route_from_road_start(array $route): ?array {
    $segments = lsttraining_sim_normalize_route_segments($route['route_segments'] ?? []);
    if (!$segments) {
        return count($route['coordinates'] ?? []) >= 2 ? $route : null;
    }

    while ($segments && (string) ($segments[0]['type'] ?? '') === 'connector') {
        array_shift($segments);
    }
    if (!$segments) {
        return null;
    }

    $coordinates = lsttraining_sim_flatten_route_segments($segments);
    if (count($coordinates) < 2) {
        return null;
    }

    $route['route_segments'] = $segments;
    $route['coordinates'] = $coordinates;
    $route['duration_sec'] = lsttraining_sim_route_segments_total_duration($segments);
    $route['distance_m'] = array_reduce($segments, static function (int $carry, array $segment): int {
        return $carry + max(0, (int) ($segment['distance_m'] ?? 0));
    }, 0);
    if ($route['distance_m'] <= 0) {
        $route['distance_m'] = lsttraining_sim_route_length_m($coordinates);
    }
    if ((string) ($route['route_source'] ?? '') === 'routing_connector') {
        $route['route_source'] = 'routing';
    }

    return $route;
}

function lsttraining_sim_police_support_poi_spawn(PDO $pdo, int $leitstelle_id, float $incident_lat, float $incident_lon, array $road_paths = []): ?array {
    if (!empty($GLOBALS['lsttraining_sim_server_route_unavailable'])) {
        return null;
    }
    if ($leitstelle_id <= 0 || !lsttraining_sim_table_exists($pdo, 'leitstellen_pois')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, poi_type, name, latitude, longitude
            FROM leitstellen_pois
            WHERE leitstelle_id = ?
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND (poi_type LIKE '%Polizei%' OR name LIKE '%Polizei%')
            LIMIT 200
        ");
        $stmt->execute([$leitstelle_id]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][police_support_poi_spawn] ' . $e->getMessage());
        return null;
    }

    $candidates = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $lat = is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null;
        $lon = is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null;
        if ($lat === null || $lon === null) {
            continue;
        }
        $distance = lsttraining_sim_distance_m($incident_lat, $incident_lon, $lat, $lon);
        if ($distance > 10000) {
            continue;
        }
        $candidates[] = [
            'latitude' => $lat,
            'longitude' => $lon,
            'distance_m' => $distance,
            'source' => 'poi',
            'source_id' => (int) ($row['id'] ?? 0),
            'source_name' => (string) ($row['name'] ?? 'Polizei'),
        ];
    }

    usort($candidates, static function (array $a, array $b): int {
        return ((float) ($a['distance_m'] ?? INF)) <=> ((float) ($b['distance_m'] ?? INF));
    });

    $best = null;
    foreach (array_slice($candidates, 0, 10) as $candidate) {
        $routed = lsttraining_sim_police_support_route_from_candidate($candidate, $incident_lat, $incident_lon, $road_paths);
        if (!$routed) {
            continue;
        }
        if ($best === null || (int) ($routed['route']['duration_sec'] ?? PHP_INT_MAX) < (int) ($best['route']['duration_sec'] ?? PHP_INT_MAX)) {
            $best = $routed;
        }
    }

    return $best;
}

function lsttraining_sim_police_support_closest_point_on_segment(float $lon, float $lat, array $a, array $b): array {
    $scale = max(0.1, cos(deg2rad($lat)));
    $px = $lon * $scale;
    $py = $lat;
    $ax = ((float) $a[0]) * $scale;
    $ay = (float) $a[1];
    $bx = ((float) $b[0]) * $scale;
    $by = (float) $b[1];
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $len_sq = ($dx * $dx) + ($dy * $dy);
    $t = $len_sq > 0 ? max(0.0, min(1.0, ((($px - $ax) * $dx) + (($py - $ay) * $dy)) / $len_sq)) : 0.0;

    $closest_lon = ($ax + ($dx * $t)) / $scale;
    $closest_lat = $ay + ($dy * $t);

    return [
        $closest_lon,
        $closest_lat,
        'longitude' => $closest_lon,
        'latitude' => $closest_lat,
    ];
}

function lsttraining_sim_police_support_street_spawn(PDO $pdo, int $leitstelle_id, float $incident_lat, float $incident_lon, array $road_paths = []): ?array {
    if (!empty($GLOBALS['lsttraining_sim_server_route_unavailable'])) {
        return null;
    }

    $road_paths = array_values(array_filter($road_paths, 'is_readable'));
    if (!$road_paths) {
        return null;
    }

    $area = null;
    try {
        $area = $leitstelle_id > 0 ? lsttraining_sim_load_area($pdo, $leitstelle_id) : null;
    } catch (Throwable $e) {
        $area = null;
    }

    $incident_lon_f = (float) $incident_lon;
    $incident_lat_f = (float) $incident_lat;
    $delta = 0.22;
    $candidates = [];
    $checked = 0;

    foreach ($road_paths as $path) {
        foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
            if (count($candidates) >= 40 || ++$checked > 140000) {
                break 2;
            }
            if (strpos($line, 'LineString') === false) {
                continue;
            }
            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }
            $properties = lsttraining_sim_osm_feature_properties($feature);
            if (!lsttraining_sim_road_is_dispatchable($properties)) {
                continue;
            }
            $highway = (string) ($properties['highway'] ?? '');

            foreach (lsttraining_sim_line_segments_from_geometry($feature['geometry'] ?? []) as $segment) {
                $min_lon = min((float) $segment[0][0], (float) $segment[1][0]);
                $max_lon = max((float) $segment[0][0], (float) $segment[1][0]);
                $min_lat = min((float) $segment[0][1], (float) $segment[1][1]);
                $max_lat = max((float) $segment[0][1], (float) $segment[1][1]);
                if ($max_lon < ($incident_lon_f - $delta) || $min_lon > ($incident_lon_f + $delta) || $max_lat < ($incident_lat_f - $delta) || $min_lat > ($incident_lat_f + $delta)) {
                    continue;
                }

                $point = lsttraining_sim_police_support_closest_point_on_segment($incident_lon_f, $incident_lat_f, $segment[0], $segment[1]);
                if ($area && !lsttraining_sim_point_inside_area($point, $area)) {
                    continue;
                }

                $distance = lsttraining_sim_distance_m($incident_lat_f, $incident_lon_f, (float) $point[1], (float) $point[0]);
                if ($distance < 10000 || $distance > 20000) {
                    continue;
                }

                $candidates[] = [
                    'latitude' => round((float) $point[1], 6),
                    'longitude' => round((float) $point[0], 6),
                    'distance_m' => $distance,
                    'source' => 'road',
                    'source_id' => 0,
                    'source_name' => trim((string) (($properties['ref'] ?? '') ?: ($properties['name'] ?? 'Polizeistreife'))),
                    'road_highway' => $highway,
                ];
                break;
            }
        }
    }

    usort($candidates, static function (array $a, array $b): int {
        return ((float) ($a['distance_m'] ?? INF)) <=> ((float) ($b['distance_m'] ?? INF));
    });

    $best = null;
    foreach (array_slice($candidates, 0, 12) as $candidate) {
        $routed = lsttraining_sim_police_support_route_from_candidate($candidate, $incident_lat, $incident_lon, $road_paths);
        if (!$routed) {
            continue;
        }
        if ($best === null || (int) ($routed['route']['duration_sec'] ?? PHP_INT_MAX) < (int) ($best['route']['duration_sec'] ?? PHP_INT_MAX)) {
            $best = $routed;
        }
    }

    return $best;
}

function lsttraining_sim_police_support_routed_radius_spawn(PDO $pdo, int $leitstelle_id, int $einsatz_id, float $incident_lat, float $incident_lon, array $road_paths = []): ?array {
    if (!empty($GLOBALS['lsttraining_sim_server_route_unavailable'])) {
        return null;
    }

    $seed = abs(crc32('police-support-radius:' . $einsatz_id));
    $bearings = [];
    for ($i = 0; $i < 8; $i++) {
        $bearings[] = (float) (($seed + ($i * 45)) % 360);
    }
    $distances = [10500.0, 13000.0, 16000.0, 19000.0];
    $earth = 6371000.0;
    $lat1 = deg2rad($incident_lat);
    $lon1 = deg2rad($incident_lon);
    $area = null;
    try {
        $area = $leitstelle_id > 0 ? lsttraining_sim_load_area($pdo, $leitstelle_id) : null;
    } catch (Throwable $e) {
        $area = null;
    }

    $best = null;
    foreach ($distances as $distance) {
        foreach ($bearings as $bearing_deg) {
            $bearing = deg2rad($bearing_deg);
            $lat2 = asin((sin($lat1) * cos($distance / $earth)) + (cos($lat1) * sin($distance / $earth) * cos($bearing)));
            $lon2 = $lon1 + atan2(
                sin($bearing) * sin($distance / $earth) * cos($lat1),
                cos($distance / $earth) - (sin($lat1) * sin($lat2))
            );
            $candidate = lsttraining_sim_police_support_road_point_near(
                rad2deg($lat2),
                rad2deg($lon2),
                $incident_lat,
                $incident_lon,
                $road_paths,
                $area
            );
            if (!$candidate) {
                continue;
            }
            $candidate['source'] = 'routed_radius_road';
            $candidate['source_id'] = 0;
            $candidate['source_name'] = $candidate['source_name'] ?: 'Polizeistreife';
            $routed = lsttraining_sim_police_support_route_from_candidate($candidate, $incident_lat, $incident_lon, $road_paths);
            if (!$routed) {
                continue;
            }
            $route_start_distance = lsttraining_sim_distance_m($incident_lat, $incident_lon, (float) $routed['latitude'], (float) $routed['longitude']);
            if ($route_start_distance < 10000 || $route_start_distance > 20000) {
                continue;
            }
            $routed['distance_m'] = $route_start_distance;
            if ($best === null || (int) ($routed['route']['duration_sec'] ?? PHP_INT_MAX) < (int) ($best['route']['duration_sec'] ?? PHP_INT_MAX)) {
                $best = $routed;
            }
        }
    }

    return $best;
}

function lsttraining_sim_police_support_road_point_near(float $target_lat, float $target_lon, float $incident_lat, float $incident_lon, array $road_paths = [], ?array $area = null): ?array {
    $road_paths = array_values(array_filter($road_paths, 'is_readable'));
    if (!$road_paths) {
        return null;
    }

    $target_delta = 1200.0 / 111320.0;
    $best = null;
    $checked = 0;

    foreach ($road_paths as $path) {
        foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
            if (++$checked > 160000) {
                break 2;
            }
            if (strpos($line, 'LineString') === false) {
                continue;
            }
            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }
            $properties = lsttraining_sim_osm_feature_properties($feature);
            if (!lsttraining_sim_road_is_dispatchable($properties)) {
                continue;
            }
            $highway = (string) ($properties['highway'] ?? '');

            foreach (lsttraining_sim_line_segments_from_geometry($feature['geometry'] ?? []) as $segment) {
                if (count($segment) < 2) {
                    continue;
                }
                $min_lon = min((float) $segment[0][0], (float) $segment[1][0]);
                $max_lon = max((float) $segment[0][0], (float) $segment[1][0]);
                $min_lat = min((float) $segment[0][1], (float) $segment[1][1]);
                $max_lat = max((float) $segment[0][1], (float) $segment[1][1]);
                if ($max_lon < ($target_lon - $target_delta) || $min_lon > ($target_lon + $target_delta) || $max_lat < ($target_lat - $target_delta) || $min_lat > ($target_lat + $target_delta)) {
                    continue;
                }

                $point = lsttraining_sim_police_support_closest_point_on_segment($target_lon, $target_lat, $segment[0], $segment[1]);
                if ($area && !lsttraining_sim_point_inside_area($point, $area)) {
                    continue;
                }
                $incident_distance = lsttraining_sim_distance_m($incident_lat, $incident_lon, (float) $point['latitude'], (float) $point['longitude']);
                if ($incident_distance < 10000.0 || $incident_distance > 20000.0) {
                    continue;
                }
                $target_distance = lsttraining_sim_distance_m($target_lat, $target_lon, (float) $point['latitude'], (float) $point['longitude']);
                if ($target_distance > 1200.0) {
                    continue;
                }

                $candidate = [
                    'latitude' => round((float) $point['latitude'], 6),
                    'longitude' => round((float) $point['longitude'], 6),
                    'distance_m' => $incident_distance,
                    'target_distance_m' => $target_distance,
                    'source' => 'road',
                    'source_id' => 0,
                    'source_name' => trim((string) (($properties['ref'] ?? '') ?: ($properties['name'] ?? 'Polizeistreife'))),
                    'road_highway' => $highway,
                ];
                if ($best === null || (float) $candidate['target_distance_m'] < (float) ($best['target_distance_m'] ?? INF)) {
                    $best = $candidate;
                    if ($target_distance <= 80.0) {
                        return $best;
                    }
                }
            }
        }
    }

    return $best;
}

function lsttraining_sim_ensure_police_support(PDO $pdo, int $instanz_id, int $einsatz_id, string $game_now): void {
    if ($instanz_id <= 0 || $einsatz_id <= 0 || lsttraining_sim_police_support_existing_event_id($pdo, $einsatz_id) > 0) {
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id, instanz_id, leitstelle_id, latitude, longitude, meta_json, state
        FROM instanz_einsaetze
        WHERE id = ? AND instanz_id = ?
        LIMIT 1
    ');
    $stmt->execute([$einsatz_id, $instanz_id]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident) {
        return;
    }

    $meta = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
    if (empty($meta['polizei_verstaendigen'])) {
        return;
    }

    $incident_lat = is_numeric($incident['latitude'] ?? null) ? (float) $incident['latitude'] : null;
    $incident_lon = is_numeric($incident['longitude'] ?? null) ? (float) $incident['longitude'] : null;
    if ($incident_lat === null || $incident_lon === null) {
        return;
    }

    $api_key = get_option('lsttraining_ors_key', '');
    if (!is_string($api_key) || trim($api_key) === '') {
        $GLOBALS['lsttraining_sim_server_route_unavailable'] = true;
    }

    $leitstelle_id = (int) ($incident['leitstelle_id'] ?? 0);
    $road_paths = lsttraining_sim_police_support_road_paths($pdo, $leitstelle_id);
    $spawn = lsttraining_sim_police_support_poi_spawn($pdo, $leitstelle_id, $incident_lat, $incident_lon, $road_paths)
        ?: lsttraining_sim_police_support_street_spawn($pdo, $leitstelle_id, $incident_lat, $incident_lon, $road_paths)
        ?: lsttraining_sim_police_support_routed_radius_spawn($pdo, $leitstelle_id, $einsatz_id, $incident_lat, $incident_lon, $road_paths);
    if (!$spawn || empty($spawn['route']) || count($spawn['route']['coordinates'] ?? []) < 2) {
        $unavailable_reason = 'keine Route auf einer geeigneten Straße im Umkreis von 10 bis 20 km gefunden.';
        $unavailable_code = 'no_route';
        if (!$road_paths) {
            $unavailable_reason = 'keine lokalen Straßen-Tiles für die Leitstelle verfügbar.';
            $unavailable_code = 'road_tiles_unavailable';
        } elseif (!is_string($api_key) || trim($api_key) === '') {
            $unavailable_reason = 'Routing-API-Key fehlt.';
            $unavailable_code = 'routing_key_missing';
        } elseif (!empty($GLOBALS['lsttraining_sim_server_route_unavailable'])) {
            $unavailable_reason = 'Routingdienst ist derzeit nicht erreichbar.';
            $unavailable_code = 'routing_unavailable';
        }
        if (!lsttraining_sim_police_support_unavailable_event_exists($pdo, $einsatz_id)) {
            lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Polizei konnte aktuell nicht disponiert werden: ' . $unavailable_reason, [
                'event_type' => 'support_vehicle_unavailable',
                'support_type' => 'police',
                'einsatz_id' => $einsatz_id,
                'rufname' => 'Polizei',
                'resource_class' => 'police',
                'resource_class_label' => 'Polizei',
                'alarmiert_at' => $game_now,
                'reason' => $unavailable_code,
            ]);
        }
        return;
    }

    $route = $spawn['route'];
    $incident_meta = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
    $signal_allowed = !empty($incident_meta['signal_allowed']);
    $normal_segments = lsttraining_sim_normalize_route_segments($route['route_segments'] ?? []);
    if (!$normal_segments) {
        $normal_segments = [lsttraining_sim_route_segment('road', (array) ($route['coordinates'] ?? []), (int) ($route['duration_sec'] ?? 120), (int) ($route['distance_m'] ?? 0))];
    }
    $route_segments = lsttraining_sim_route_segments_for_signal($normal_segments, $signal_allowed);
    $duration = max(60, lsttraining_sim_route_segments_total_duration($route_segments));
    $normal_duration = max(60, lsttraining_sim_route_segments_total_duration($normal_segments));
    $rufname = 'Polizei';
    $support_meta = [
        'event_type' => 'support_vehicle_alarm',
        'support_type' => 'police',
        'support_id' => 'police-' . $einsatz_id,
        'einsatz_id' => $einsatz_id,
        'rufname' => $rufname,
        'resource_class' => 'police',
        'resource_class_label' => 'Polizei',
        'alarmiert_at' => $game_now,
        'movement_started_at' => $game_now,
        'mission_phase' => 'to_scene',
        'fms_status' => '3',
        'sondersignal_allowed' => $signal_allowed,
        'sondersignal' => $signal_allowed ? 1 : 0,
        'route_coordinates' => lsttraining_sim_flatten_route_segments($route_segments),
        'route_segments' => $route_segments,
        'route_segments_normal' => $normal_segments,
        'route_distance_m' => (int) ($route['distance_m'] ?? 0),
        'route_duration_sec' => $duration,
        'route_duration_normal_sec' => $normal_duration,
        'route_source' => (string) ($route['route_source'] ?? ''),
        'current_progress' => 0,
        'current_segment_index' => 0,
        'current_segment_progress' => 0,
        'last_position' => [
            'latitude' => round((float) $spawn['latitude'], 6),
            'longitude' => round((float) $spawn['longitude'], 6),
        ],
        'spawn_source' => (string) ($spawn['source'] ?? 'generated'),
        'spawn_source_id' => (int) ($spawn['source_id'] ?? 0),
        'spawn_source_name' => (string) ($spawn['source_name'] ?? ''),
        'image_url' => lsttraining_sim_police_vehicle_image_url($pdo, $leitstelle_id),
        'signal_lights' => lsttraining_sim_signal_lights_for_vehicle(lsttraining_sim_police_signal_lights_json($pdo, $leitstelle_id), 'Streifenwagen', $rufname),
    ];

    lsttraining_sim_insert_unit_event($pdo, $einsatz_id, $rufname . ' verständigt.', $support_meta);
}

function lsttraining_sim_merge_required_resources(array $base, array $additional): array {
    $merged = [];
    foreach (array_merge(
        lsttraining_sim_normalize_required_resources($base),
        lsttraining_sim_normalize_required_resources($additional)
    ) as $row) {
        $type = (string) ($row['type'] ?? '');
        if ($type === '') {
            continue;
        }
        $merged[$type] = ($merged[$type] ?? 0) + max(1, (int) ($row['count'] ?? 1));
    }

    $out = [];
    foreach ($merged as $type => $count) {
        $out[] = [
            'type' => $type,
            'label' => lsttraining_sim_resource_class_label($type),
            'count' => $count,
        ];
    }
    return $out;
}

function lsttraining_sim_resource_status_with_substitution(array $required_resources, array $fulfilled): array {
    $required = lsttraining_sim_normalize_required_resources($required_resources);
    if (!$required) {
        return [];
    }

    $required_by_type = [];
    $labels = [];
    foreach ($required as $row) {
        $type = (string) ($row['type'] ?? '');
        if ($type === '') {
            continue;
        }
        $required_by_type[$type] = ($required_by_type[$type] ?? 0) + max(1, (int) ($row['count'] ?? 1));
        $labels[$type] = (string) ($row['label'] ?? lsttraining_sim_resource_class_label($type));
    }

    $remaining_fulfilled = [];
    foreach ($fulfilled as $type => $count) {
        $type = (string) $type;
        if ($type === '') {
            continue;
        }
        $remaining_fulfilled[$type] = max(0, (int) $count);
    }

    $status = [];
    if (isset($required_by_type['rettungswagen'])) {
        $needed = $required_by_type['rettungswagen'];
        $assigned = min((int) ($remaining_fulfilled['rettungswagen'] ?? 0), $needed);
        $remaining_fulfilled['rettungswagen'] = max(0, (int) ($remaining_fulfilled['rettungswagen'] ?? 0) - $assigned);
        $status['rettungswagen'] = [
            'type' => 'rettungswagen',
            'label' => $labels['rettungswagen'] ?? lsttraining_sim_resource_class_label('rettungswagen'),
            'needed' => $needed,
            'assigned' => $assigned,
            'missing' => max(0, $needed - $assigned),
        ];
        unset($required_by_type['rettungswagen']);
    }

    if (isset($required_by_type['krankentransport'])) {
        $needed = $required_by_type['krankentransport'];
        $ktw = (int) ($remaining_fulfilled['krankentransport'] ?? 0);
        $rtw_substitute = (int) ($remaining_fulfilled['rettungswagen'] ?? 0);
        $assigned = min($needed, $ktw + $rtw_substitute);
        $use_ktw = min($ktw, $assigned);
        $remaining_fulfilled['krankentransport'] = max(0, $ktw - $use_ktw);
        $remaining_fulfilled['rettungswagen'] = max(0, $rtw_substitute - max(0, $assigned - $use_ktw));
        $status['krankentransport'] = [
            'type' => 'krankentransport',
            'label' => $labels['krankentransport'] ?? lsttraining_sim_resource_class_label('krankentransport'),
            'needed' => $needed,
            'assigned' => $assigned,
            'missing' => max(0, $needed - $assigned),
        ];
        unset($required_by_type['krankentransport']);
    }

    foreach ($required_by_type as $type => $needed) {
        $assigned = min((int) ($remaining_fulfilled[$type] ?? 0), $needed);
        $remaining_fulfilled[$type] = max(0, (int) ($remaining_fulfilled[$type] ?? 0) - $assigned);
        $status[$type] = [
            'type' => $type,
            'label' => $labels[$type] ?? lsttraining_sim_resource_class_label($type),
            'needed' => $needed,
            'assigned' => $assigned,
            'missing' => max(0, $needed - $assigned),
        ];
    }

    $ordered = [];
    foreach ($required as $row) {
        $type = (string) ($row['type'] ?? '');
        if ($type !== '' && isset($status[$type])) {
            $ordered[$type] = $status[$type];
        }
    }
    return array_values($ordered);
}

function lsttraining_sim_report_text_from_followup(array $followup, array $incident, array $assignment_meta): string {
    $effect = lsttraining_sim_decode_meta((string) ($followup['effect_json'] ?? ''));
    $structured = is_array($effect['situation_report'] ?? null) ? $effect['situation_report'] : [];
    $rufname = trim((string) ($assignment_meta['rufname'] ?? 'Fahrzeug'));
    $title = trim((string) ($incident['template_title'] ?? ($incident['einsatztyp'] ?? 'Einsatz')));
    $address = lsttraining_sim_display_address_for_incident($incident);
    $lagemeldung = trim((string) ($incident['lagemeldung'] ?? ''));
    $text = trim((string) ($followup['text'] ?? ''));

    if ($text === '') {
        $parts = [$rufname . ', eingetroffen.'];
        foreach (['environment', 'damage_event', 'people', 'patients', 'hazards', 'summary'] as $key) {
            $part = trim((string) ($structured[$key] ?? ''));
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if (count($parts) === 1 && $lagemeldung !== '') {
            $parts[] = $lagemeldung;
        }
        $text = implode("\n", $parts);
    }

    $replacements = [
        '{rufname}' => $rufname,
        '{unit}' => $rufname,
        '{title}' => $title,
        '{einsatztitle}' => $title,
        '{einsatztyp}' => (string) ($incident['einsatztyp'] ?? ''),
        '{address}' => $address,
        '{location}' => $address,
        '{lagemeldung}' => $lagemeldung,
    ];
    $text = trim(strtr($text, $replacements));
    if ($text !== '' && stripos($text, $rufname) === false) {
        $text = $rufname . ', eingetroffen.' . "\n" . $text;
    }

    return $text !== '' ? $text : $rufname . ', eingetroffen.';
}

function lsttraining_sim_situation_summary_from_followup(array $followup, array $incident, array $assignment_meta): string {
    $effect = lsttraining_sim_decode_meta((string) ($followup['effect_json'] ?? ''));
    $structured = is_array($effect['situation_report'] ?? null) ? $effect['situation_report'] : [];
    $parts = [];
    foreach (['environment', 'damage_event', 'people', 'patients', 'hazards', 'summary'] as $key) {
        $part = trim((string) ($structured[$key] ?? ''));
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    if ($parts) {
        return trim(implode(' ', $parts));
    }

    $rufname = trim((string) ($assignment_meta['rufname'] ?? 'Fahrzeug'));
    $title = trim((string) ($incident['template_title'] ?? ($incident['einsatztyp'] ?? 'Einsatz')));
    $address = lsttraining_sim_display_address_for_incident($incident);
    $lagemeldung = trim((string) ($incident['lagemeldung'] ?? ''));
    $text = trim((string) ($followup['text'] ?? ''));
    if ($text === '') {
        return '';
    }
    $text = trim(strtr($text, [
        '{rufname}' => $rufname,
        '{unit}' => $rufname,
        '{title}' => $title,
        '{einsatztitle}' => $title,
        '{einsatztyp}' => (string) ($incident['einsatztyp'] ?? ''),
        '{address}' => $address,
        '{location}' => $address,
        '{lagemeldung}' => $lagemeldung,
    ]));
    $lines = preg_split('/\R+/', $text) ?: [];
    if ($lines && stripos((string) $lines[0], 'eingetroffen') !== false) {
        array_shift($lines);
    }
    return trim(implode(' ', array_map('trim', $lines)));
}

function lsttraining_sim_missing_resources_for_incident(PDO $pdo, int $einsatz_id, array $required_resources): array {
    $required = lsttraining_sim_normalize_required_resources($required_resources);
    if (!$required) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT meta_json
        FROM instanz_einsatz_events
        WHERE instanz_einsatz_id = ? AND kind = ?
        ORDER BY id DESC
        LIMIT 500
    ');
    $stmt->execute([$einsatz_id, 'unit_report']);
    $fulfilled = [];
    $seen_status = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $meta = lsttraining_sim_decode_meta($row['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'vehicle_alarm') {
            continue;
        }
        $status_id = (int) ($meta['status_id'] ?? 0);
        if ($status_id > 0) {
            if (!empty($seen_status[$status_id])) {
                continue;
            }
            $seen_status[$status_id] = true;
        }
        if (!empty($meta['cancelled_at'])) {
            continue;
        }
        $mission_phase = (string) ($meta['mission_phase'] ?? '');
        if (in_array($mission_phase, ['available', 'returning'], true)) {
            continue;
        }
        $class = trim((string) ($meta['resource_class'] ?? ''));
        if ($class === '') {
            continue;
        }
        $fulfilled[$class] = ($fulfilled[$class] ?? 0) + 1;
    }

    $missing = [];
    foreach (lsttraining_sim_resource_status_with_substitution($required, $fulfilled) as $row) {
        if ((int) ($row['missing'] ?? 0) <= 0) {
            continue;
        }
        $missing[] = [
            'type' => (string) ($row['type'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'required' => (int) ($row['needed'] ?? 0),
            'fulfilled' => (int) ($row['assigned'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
        ];
    }
    return $missing;
}

function lsttraining_sim_missing_resources_text(array $missing_resources): string {
    if (!$missing_resources) {
        return '';
    }
    $parts = [];
    foreach ($missing_resources as $row) {
        $count = max(1, (int) ($row['missing'] ?? 1));
        $label = trim((string) ($row['label'] ?? ($row['type'] ?? 'Fahrzeug')));
        $parts[] = $count . 'x ' . ($label !== '' ? $label : 'Fahrzeug');
    }
    return 'Benötigte Kräfte fehlen: ' . implode(', ', $parts) . '. Nachforderung erforderlich.';
}

function lsttraining_sim_append_resource_request_text(string $report_text, string $resource_text): string {
    $report_text = rtrim($report_text);
    $resource_text = trim($resource_text);
    if ($resource_text === '') {
        return $report_text;
    }
    if ($report_text !== '' && stripos($report_text, $resource_text) !== false) {
        return $report_text;
    }
    return trim($report_text . "\n" . $resource_text);
}

function lsttraining_sim_merge_patient_updates(array $current, array $updates): array {
    $patients = [];
    foreach (lsttraining_sim_normalize_patients($current) as $patient) {
        $patients[(string) ($patient['patient_id'] ?? ('p' . (count($patients) + 1)))] = $patient;
    }

    foreach (lsttraining_sim_normalize_patients($updates) as $update) {
        $id = (string) ($update['patient_id'] ?? '');
        if ($id === '') {
            $id = 'p' . (count($patients) + 1);
            $update['patient_id'] = $id;
        }
        $patients[$id] = array_merge($patients[$id] ?? [], $update);
    }

    return array_values(lsttraining_sim_normalize_patients(array_values($patients)));
}

function lsttraining_sim_patient_waiting_resources(array $patient, array $resource_status): array {
    if (($patient['patient_status'] ?? '') === 'deceased') {
        return [];
    }
    $missing = [];
    $by_type = [];
    foreach ($resource_status as $row) {
        $by_type[(string) ($row['type'] ?? '')] = (int) ($row['missing'] ?? 0);
    }
    if (!empty($patient['requires_notarzt']) && ($by_type['notarzt'] ?? 0) > 0) {
        $missing[] = 'Notarztmittel';
    }
    if (!empty($patient['requires_rtw']) && ($by_type['rettungswagen'] ?? 0) > 0) {
        $missing[] = 'RTW';
    }
    if (!empty($patient['requires_ktw']) && ($by_type['krankentransport'] ?? 0) > 0) {
        $missing[] = 'KTW/RTW';
    }
    return array_values(array_unique($missing));
}

function lsttraining_sim_patients_for_snapshot(array $incident, array $resource_status, ?array $arrived_resource_status = null): array {
    $meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    $meta_requirements = is_array($meta['patient_requirements'] ?? null) ? $meta['patient_requirements'] : [];
    $fallback = lsttraining_sim_patient_requirements_from_resources(
        is_array($meta['required_resources'] ?? null) ? $meta['required_resources'] : [],
        (int) ($meta_requirements['total'] ?? 0)
    );
    foreach (['total', 'ktw', 'rtw', 'notarzt'] as $key) {
        if (isset($meta_requirements[$key])) {
            $fallback[$key] = max((int) ($fallback[$key] ?? 0), (int) $meta_requirements[$key]);
        }
    }
    $patients = lsttraining_sim_normalize_patients($meta['patients'] ?? [], $fallback);
    foreach ($patients as &$patient) {
        $waiting = lsttraining_sim_patient_waiting_resources($patient, $resource_status);
        $waiting_arrival = lsttraining_sim_patient_waiting_resources($patient, $arrived_resource_status ?? $resource_status);
        $patient['waiting_for_resources'] = $waiting;
        $patient['waiting_for_arrival'] = $waiting_arrival;
        if (($patient['patient_status'] ?? '') === 'deceased') {
            $patient['status_label'] = 'Verstorben';
        } elseif (($patient['transport_status'] ?? '') === 'completed') {
            $patient['status_label'] = 'In Klinik übergeben';
        } elseif (($patient['transport_status'] ?? '') === 'handover') {
            $patient['status_label'] = 'Klinikübergabe';
        } elseif (($patient['transport_status'] ?? '') === 'to_hospital') {
            $target = trim((string) ($patient['transport_hospital_name'] ?? ''));
            $patient['status_label'] = $target !== '' ? 'Transport nach ' . $target : 'Auf Klinikfahrt';
        } elseif (!empty($waiting)) {
            $patient['status_label'] = 'Wartet auf ' . implode(', ', $waiting);
            $patient['transport_ready'] = false;
        } elseif (!empty($waiting_arrival)) {
            $patient['status_label'] = 'Fahrzeuge auf Anfahrt: ' . implode(', ', $waiting_arrival);
            $patient['transport_ready'] = false;
        } elseif (!empty($patient['transport_ready'])) {
            $note = trim((string) ($patient['transport_note'] ?? ''));
            $patient['status_label'] = $note !== '' ? $note : 'Transportbereit';
        } else {
            $patient['status_label'] = 'In Versorgung';
        }
    }
    unset($patient);
    return $patients;
}

function lsttraining_sim_existing_situation_followups(PDO $pdo, int $einsatz_id): array {
    $stmt = $pdo->prepare('
        SELECT meta_json
        FROM instanz_einsatz_events
        WHERE instanz_einsatz_id = ? AND kind = ?
        ORDER BY id DESC
        LIMIT 500
    ');
    $stmt->execute([$einsatz_id, 'unit_report']);
    $existing = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $meta = lsttraining_sim_decode_meta($row['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'situation_report') {
            continue;
        }
        if (!empty($meta['default_arrival_report'])) {
            $existing[0] = true;
        }
        if (isset($meta['followup_id'])) {
            $existing[(int) $meta['followup_id']] = true;
        }
    }
    return $existing;
}

function lsttraining_sim_fire_arrival_followups(PDO $pdo, int $instanz_id, array $event, array &$meta, int $now): void {
    if (empty($meta['arrived_at'])) {
        return;
    }
    if (!empty($meta['arrival_followups_complete'])) {
        return;
    }

    $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
    $status_id = (int) ($meta['status_id'] ?? 0);
    if ($einsatz_id <= 0 || $status_id <= 0) {
        return;
    }

    $incident_stmt = $pdo->prepare('
        SELECT
            ie.id,
            ie.instanz_id,
            ie.source,
            ie.source_id,
            ie.einsatztyp,
            ie.lagemeldung,
            ie.poi_name_snapshot,
            ie.meta_json,
            ie.latitude,
            ie.longitude,
            l.name AS leitstelle_name,
            e.title AS template_title,
            e.description AS template_description
        FROM instanz_einsaetze ie
        LEFT JOIN leitstellen l ON l.id = ie.leitstelle_id
        LEFT JOIN einsaetze e ON ie.source = ? AND e.id = ie.source_id
        WHERE ie.id = ? AND ie.instanz_id = ? AND ie.state IN (?, ?)
        LIMIT 1
    ');
    $incident_stmt->execute(['template', $einsatz_id, $instanz_id, 'new', 'active']);
    $incident = $incident_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident || (string) ($incident['source'] ?? '') !== 'template' || (int) ($incident['source_id'] ?? 0) <= 0) {
        $meta['arrival_followups_complete'] = true;
        return;
    }
    $incident['meta'] = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
    unset($incident['meta_json']);
    $incident_meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    $decisions = is_array($incident_meta['arrival_followup_decisions'] ?? null) ? $incident_meta['arrival_followup_decisions'] : [];
    $incident_meta_dirty = false;
    $incident_lagemeldung_dirty = false;
    $first_status_id = (int) ($incident_meta['arrival_report_status_id'] ?? 0);
    if ($first_status_id > 0 && $first_status_id !== $status_id) {
        $meta['arrival_followups_complete'] = true;
        return;
    }
    if ($first_status_id <= 0) {
        $incident_meta['arrival_report_status_id'] = $status_id;
        $incident_meta['arrival_report_fahrzeug_id'] = (int) ($meta['fahrzeug_id'] ?? 0);
        $incident_meta['arrival_report_rufname'] = (string) ($meta['rufname'] ?? 'Fahrzeug');
        $incident_meta['arrival_report_arrived_at'] = (string) ($meta['arrived_at'] ?? lsttraining_sim_time_string($now));
        $incident_meta_dirty = true;
    }

    $followup_stmt = $pdo->prepare('
        SELECT id, label, step_no, kind, text, min_after_sec, max_after_sec, condition_json,
               probability_percent, speaker_type, trigger_mode, required_resources_json, effect_json
        FROM einsatz_followups
        WHERE einsatz_id = ? AND kind = ? AND trigger_mode = ?
        ORDER BY step_no ASC, id ASC
        LIMIT 50
    ');
    $followup_stmt->execute([(int) $incident['source_id'], 'unit_report', 'on_unit_arrival']);
    $followups = $followup_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$followups) {
        $followups = [[
            'id' => 0,
            'label' => 'Eintreffende Lagemeldung',
            'step_no' => 0,
            'kind' => 'unit_report',
            'text' => '',
            'min_after_sec' => 0,
            'max_after_sec' => 0,
            'condition_json' => '',
            'probability_percent' => 100,
            'speaker_type' => 'fire_unit',
            'trigger_mode' => 'on_unit_arrival',
            'required_resources_json' => '',
            'effect_json' => '',
            '_default_arrival_report' => true,
        ]];
    }

    $existing = lsttraining_sim_existing_situation_followups($pdo, $einsatz_id);
    $scheduled = is_array($meta['arrival_followups'] ?? null) ? $meta['arrival_followups'] : [];
    $arrival_ts = lsttraining_sim_parse_wp_time($meta['arrived_at'] ?? '');
    if ($arrival_ts <= 0) {
        $arrival_ts = $now;
    }

    foreach ($followups as $followup) {
        $followup_id = (int) ($followup['id'] ?? 0);
        $is_default_arrival = !empty($followup['_default_arrival_report']);
        $existing_key = $is_default_arrival ? 0 : $followup_id;
        if ((!$is_default_arrival && $followup_id <= 0) || !empty($existing[$existing_key])) {
            continue;
        }

        $key = $is_default_arrival ? 'default_arrival' : (string) $followup_id;
        if (!isset($decisions[$key])) {
            $probability = max(0, min(100, (int) ($followup['probability_percent'] ?? 100)));
            $min = array_key_exists('min_after_sec', $followup) && $followup['min_after_sec'] !== null ? max(0, (int) $followup['min_after_sec']) : 5;
            $max = array_key_exists('max_after_sec', $followup) && $followup['max_after_sec'] !== null ? max(0, (int) $followup['max_after_sec']) : 12;
            if ($max < $min) {
                $max = $min;
            }
            $decisions[$key] = [
                'followup_id' => $followup_id,
                'default_arrival_report' => $is_default_arrival,
                'selected' => $probability >= 100 || ($probability > 0 && mt_rand(1, 100) <= $probability),
                'due_at' => lsttraining_sim_time_string($arrival_ts + mt_rand($min, $max)),
                'min_after_sec' => $min,
                'max_after_sec' => $max,
                'emitted' => false,
            ];
            $incident_meta['arrival_followup_decisions'] = $decisions;
            $incident_meta_dirty = true;
        }
        $scheduled[$key] = $decisions[$key];

        if (empty($decisions[$key]['selected']) || !empty($decisions[$key]['emitted'])) {
            continue;
        }
        $due_ts = lsttraining_sim_parse_wp_time($decisions[$key]['due_at'] ?? '');
        if ($due_ts > $now) {
            continue;
        }

        $report_text = lsttraining_sim_report_text_from_followup($followup, $incident, $meta);
        $effect_meta = lsttraining_sim_decode_meta((string) ($followup['effect_json'] ?? ''));
        if (is_array($effect_meta['patients'] ?? null) && $effect_meta['patients']) {
            $incident_meta['patients'] = lsttraining_sim_merge_patient_updates(
                is_array($incident_meta['patients'] ?? null) ? $incident_meta['patients'] : [],
                $effect_meta['patients']
            );
            $incident_meta_dirty = true;
        }
        $additional_resources = lsttraining_sim_normalize_required_resources($followup['required_resources_json'] ?? '');
        if ($additional_resources) {
            $incident_meta['required_resources'] = lsttraining_sim_merge_required_resources(
                is_array($incident_meta['required_resources'] ?? null) ? $incident_meta['required_resources'] : [],
                $additional_resources
            );
            $incident_meta['resource_variants'] = is_array($incident_meta['resource_variants'] ?? null) ? $incident_meta['resource_variants'] : [];
            $incident_meta['resource_variants'][] = [
                'followup_id' => $followup_id,
                'label' => (string) ($followup['label'] ?? ''),
                'triggered_at' => lsttraining_sim_time_string($now),
                'resources' => $additional_resources,
            ];
            $incident_meta_dirty = true;
        }
        $required_resources = is_array($incident_meta['required_resources'] ?? null) ? $incident_meta['required_resources'] : [];
        $missing_resources = lsttraining_sim_missing_resources_for_incident($pdo, $einsatz_id, $required_resources);
        $missing_text = lsttraining_sim_missing_resources_text($missing_resources);
        $resource_request_text = $missing_text !== '' ? $missing_text : 'Keine weiteren Kräfte erforderlich.';
        $report_text = lsttraining_sim_append_resource_request_text($report_text, $resource_request_text);
        if ($missing_text !== '') {
            $incident_meta['pending_resource_request'] = [
                'followup_id' => $followup_id,
                'default_arrival_report' => $is_default_arrival,
                'status_id' => $status_id,
                'created_at' => lsttraining_sim_time_string($now),
                'missing_resources' => $missing_resources,
                'text' => $resource_request_text,
            ];
            $incident_meta_dirty = true;
        }
        $incident_meta['last_arrival_resource_request_text'] = $resource_request_text;
        $incident_meta['last_arrival_resource_request_required'] = !empty($missing_resources);
        $incident_meta['last_arrival_report_text'] = $report_text;
        $incident_meta_dirty = true;

        $replaced_lagemeldung = false;
        $should_replace_lagemeldung = !$is_default_arrival;
        if ($should_replace_lagemeldung) {
            $new_lagemeldung = lsttraining_sim_situation_summary_from_followup($followup, $incident, $meta);
            if ($new_lagemeldung !== '' && $new_lagemeldung !== (string) ($incident['lagemeldung'] ?? '')) {
                $new_lagemeldung = lsttraining_sim_append_resource_request_text($new_lagemeldung, $resource_request_text);
                $incident['lagemeldung'] = $new_lagemeldung;
                $incident_meta['current_lagemeldung'] = $new_lagemeldung;
                $incident_meta['current_lagemeldung_source'] = 'arrival_followup';
                $incident_meta['current_lagemeldung_followup_id'] = $followup_id;
                $incident_meta['current_lagemeldung_updated_at'] = lsttraining_sim_time_string($now);
                $incident_meta_dirty = true;
                $incident_lagemeldung_dirty = true;
                $replaced_lagemeldung = true;
            }
        }

        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
            'fms_status' => '5',
            'status' => 'besetzt',
            'sondersignal' => 0,
            'bemerkung' => 'Lagemeldung wartet auf Bestaetigung.',
        ]);

        lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Sprechwunsch', [
            'event_type' => 'situation_report',
            'radio_message_type' => 'speak_request',
            'sender_type' => 'vehicle',
            'recipient_type' => 'dispatch',
            'status_transition' => '5',
            'followup_id' => $followup_id,
            'followup_step_no' => (int) ($followup['step_no'] ?? 0),
            'followup_label' => (string) ($followup['label'] ?? ''),
            'report_text' => $report_text,
            'visible_text_pending' => true,
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => (string) ($meta['rufname'] ?? 'Fahrzeug'),
            'fms_status' => '5',
            'previous_fms_status' => '4',
            'requires_ack' => true,
            'acknowledged_at' => null,
            'direction' => 'down',
            'speaker_type' => (string) ($followup['speaker_type'] ?? 'fire_unit'),
            'additional_resources' => $additional_resources,
            'missing_resources' => $missing_resources,
            'resource_request_required' => !empty($missing_resources),
            'resource_request_text' => $resource_request_text,
            'default_arrival_report' => $is_default_arrival,
            'replaced_lagemeldung' => $replaced_lagemeldung,
            'radio_base_at' => lsttraining_sim_time_string($now),
        ]);
        lsttraining_sim_reset_runtime_speed($pdo, $instanz_id);
        $decisions[$key]['emitted'] = true;
        $decisions[$key]['emitted_at'] = lsttraining_sim_time_string($now);
        $incident_meta['arrival_followup_decisions'] = $decisions;
        $incident_meta_dirty = true;
        $scheduled[$key] = $decisions[$key];
        $existing[$existing_key] = true;
    }

    $meta['arrival_followups'] = $scheduled;
    if ($scheduled) {
        $has_pending_followup = false;
        foreach ($scheduled as $decision) {
            if (!empty($decision['selected']) && empty($decision['emitted'])) {
                $has_pending_followup = true;
                break;
            }
        }
        if (!$has_pending_followup) {
            $meta['arrival_followups_complete'] = true;
        }
    }
    if ($incident_meta_dirty || $incident_lagemeldung_dirty) {
        $update_incident = $pdo->prepare('UPDATE instanz_einsaetze SET lagemeldung = ?, meta_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update_incident->execute([(string) ($incident['lagemeldung'] ?? ''), lsttraining_sim_encode_meta($incident_meta), $einsatz_id]);
    }
}

function lsttraining_sim_update_patient_transport(PDO $pdo, int $instanz_id, int $einsatz_id, string $patient_id, array $updates): bool {
    if ($einsatz_id <= 0 || $patient_id === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT meta_json FROM instanz_einsaetze WHERE id = ? AND instanz_id = ? LIMIT 1');
    $stmt->execute([$einsatz_id, $instanz_id]);
    $meta = lsttraining_sim_decode_meta($stmt->fetchColumn() ?: '');
    $patients = is_array($meta['patients'] ?? null) ? $meta['patients'] : [];
    $dirty = false;

    foreach ($patients as &$patient) {
        if (!is_array($patient) || (string) ($patient['patient_id'] ?? '') !== $patient_id) {
            continue;
        }
        foreach ($updates as $key => $value) {
            $patient[$key] = $value;
        }
        $dirty = true;
        break;
    }
    unset($patient);

    if (!$dirty) {
        return false;
    }

    $meta['patients'] = $patients;
    $update = $pdo->prepare('UPDATE instanz_einsaetze SET meta_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND instanz_id = ?');
    $update->execute([lsttraining_sim_encode_meta($meta), $einsatz_id, $instanz_id]);
    return true;
}

function lsttraining_sim_vehicle_base_position(PDO $pdo, int $instanz_id, int $status_id): ?array {
    $stmt = $pdo->prepare('
        SELECT
            COALESCE(fs.latitude, f.latitude, w.latitude) AS latitude,
            COALESCE(fs.longitude, f.longitude, w.longitude) AS longitude
        FROM fahrzeug_status fs
        LEFT JOIN fahrzeuge f ON f.id = fs.fahrzeug_id
        LEFT JOIN wachen w ON w.id = fs.wache_id
        WHERE fs.id = ? AND fs.instanz_id = ?
        LIMIT 1
    ');
    $stmt->execute([$status_id, $instanz_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) {
        return null;
    }

    return [
        'latitude' => (float) $row['latitude'],
        'longitude' => (float) $row['longitude'],
    ];
}

function lsttraining_sim_advance_vehicle_movements(PDO $pdo, int $instanz_id, int $now): void {
    $stmt = $pdo->prepare('
        SELECT
            ev.id,
            ev.instanz_einsatz_id,
            ev.meta_json,
            ie.instanz_id
        FROM instanz_einsatz_events ev
        INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
        WHERE ie.instanz_id = ? AND ev.kind = ?
        ORDER BY ev.id ASC
        LIMIT 300
    ');
    $stmt->execute([$instanz_id, 'unit_report']);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $event_update = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');

    foreach ($events as $event) {
        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') === 'support_vehicle_alarm' && in_array((string) ($meta['support_type'] ?? ''), ['police', 'neighbor'], true)) {
            if (!empty($meta['cancelled_at'])) {
                continue;
            }

            $support_type = (string) ($meta['support_type'] ?? '');
            $phase = (string) ($meta['mission_phase'] ?? 'to_scene');
            if ($phase === 'at_scene' || ($phase !== 'returning' && !empty($meta['arrived_at']))) {
                continue;
            }

            $route = is_array($meta['route_coordinates'] ?? null) ? $meta['route_coordinates'] : [];
            $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
            if ($einsatz_id <= 0 || count($route) < 2) {
                continue;
            }

            $started_at = $phase === 'returning'
                ? lsttraining_sim_parse_wp_time($meta['return_started_at'] ?? ($meta['phase_started_at'] ?? ''))
                : lsttraining_sim_parse_wp_time($meta['movement_started_at'] ?? ($meta['alarmiert_at'] ?? ''));
            if ($started_at <= 0) {
                $started_at = $now;
                if ($phase === 'returning') {
                    $meta['return_started_at'] = lsttraining_sim_time_string($now);
                    $meta['phase_started_at'] = lsttraining_sim_time_string($now);
                } else {
                    $meta['movement_started_at'] = lsttraining_sim_time_string($now);
                }
            }

            $motion = lsttraining_sim_route_movement_state($meta, $now - $started_at);
            if (!$motion) {
                continue;
            }
            $progress = (float) ($motion['progress'] ?? 0);
            $position = $motion['position'];

            $meta['current_progress'] = round($progress, 4);
            $meta['current_segment_index'] = (int) ($motion['segment_index'] ?? 0);
            $meta['current_segment_progress'] = round((float) ($motion['segment_progress'] ?? 0), 4);
            $meta['last_position'] = [
                'latitude' => round((float) $position['latitude'], 6),
                'longitude' => round((float) $position['longitude'], 6),
            ];
            $meta['fms_status'] = $phase === 'returning'
                ? ($progress >= 1.0 ? '2' : '1')
                : ($progress >= 1.0 ? '4' : '3');
            $meta['sondersignal'] = ($progress >= 1.0 || empty($meta['sondersignal_allowed'])) ? 0 : 1;

            if (
                $support_type === 'neighbor'
                && $phase === 'to_scene'
                && empty($meta['entry_speak_request_event_id'])
                && lsttraining_sim_neighbor_inside_instance_area($pdo, $instanz_id, $meta['last_position'], $progress)
            ) {
                $rufname = (string) ($meta['rufname'] ?? 'Fremdfahrzeug');
                $home = trim((string) ($meta['home_nebenleitstelle_name'] ?? 'Nachbarleitstelle'));
                $report = 'Leitstelle von ' . $rufname . ': Fremdfahrzeug aus ' . $home . ' im Bereich, Sprechwunsch, Ende.';
                $speak_event_id = lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Sprechwunsch', [
                    'event_type' => 'situation_report',
                    'radio_message_type' => 'foreign_unit_entry',
                    'sender_type' => 'vehicle',
                    'recipient_type' => 'dispatch',
                    'support_type' => 'neighbor',
                    'foreign_unit' => true,
                    'source_event_id' => (int) ($event['id'] ?? 0),
                    'status_transition' => '5',
                    'status_id' => 0,
                    'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                    'rufname' => $rufname,
                    'fms_status' => '5',
                    'previous_fms_status' => '3',
                    'requires_ack' => true,
                    'acknowledged_at' => null,
                    'direction' => 'down',
                    'report_text' => $report,
                    'visible_text_pending' => true,
                    'home_nebenleitstelle_id' => (int) ($meta['home_nebenleitstelle_id'] ?? 0),
                    'home_nebenleitstelle_name' => $home,
                    'radio_base_at' => lsttraining_sim_time_string($now),
                ]);
                $meta['entry_speak_request_event_id'] = $speak_event_id;
                $meta['contact_established_at'] = lsttraining_sim_time_string($now);
            }

            if ($progress >= 1.0 && (($phase === 'returning' && empty($meta['return_completed_at'])) || empty($meta['arrived_event_logged']))) {
                if ($phase === 'returning') {
                    $meta['mission_phase'] = 'available';
                    $meta['return_completed_at'] = lsttraining_sim_time_string($now);
                    $meta['sondersignal'] = 0;
                    lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . (string) ($meta['rufname'] ?? 'Fremdfahrzeug') . ': Zurück an der Heimatleitstelle, abgemeldet, Ende.', [
                        'event_type' => 'support_vehicle_return_completed',
                        'support_type' => $support_type,
                        'support_id' => (string) ($meta['support_id'] ?? ''),
                        'foreign_unit' => $support_type === 'neighbor',
                        'rufname' => (string) ($meta['rufname'] ?? 'Fremdfahrzeug'),
                        'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                        'fms_status' => '2',
                        'direction' => 'down',
                    ]);
                } else {
                    $meta['arrived_at'] = lsttraining_sim_time_string($now);
                    $meta['arrived_event_logged'] = true;
                    $meta['mission_phase'] = 'at_scene';
                    lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . (string) ($meta['rufname'] ?? 'Unterstützung') . ': An Einsatzstelle, Status 4, Ende.', [
                        'event_type' => 'support_vehicle_status',
                        'support_type' => $support_type,
                        'support_id' => (string) ($meta['support_id'] ?? ''),
                        'foreign_unit' => $support_type === 'neighbor',
                        'rufname' => (string) ($meta['rufname'] ?? 'Unterstützung'),
                        'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                        'fms_status' => '4',
                        'direction' => 'down',
                    ]);
                }
            }

            $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
            continue;
        }

        if (($meta['event_type'] ?? '') !== 'vehicle_alarm') {
            continue;
        }
        if (!empty($meta['cancelled_at'])) {
            continue;
        }

        $phase = (string) ($meta['mission_phase'] ?? '');
        if ($phase === '') {
            $phase = !empty($meta['arrived_at']) ? 'at_scene' : 'to_scene';
        }

        if ($phase === 'handover') {
            $arrived_ts = lsttraining_sim_parse_wp_time($meta['hospital_arrived_at'] ?? '');
            $release_ts = lsttraining_sim_parse_wp_time($meta['handover_release_at'] ?? '');
            if ($release_ts <= 0 && $arrived_ts > 0) {
                $release_ts = $arrived_ts + max(300, (int) ($meta['handover_duration_sec'] ?? 120));
            }
            if ($release_ts > 0 && $now >= $release_ts && empty($meta['handover_completed_at'])) {
                $status_id = (int) ($meta['status_id'] ?? 0);
                $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
                $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
                $completed_at = lsttraining_sim_time_string($now);
                $meta['handover_completed_at'] = $completed_at;
                $meta['transport_status'] = 'completed';

                lsttraining_sim_update_patient_transport($pdo, $instanz_id, $einsatz_id, (string) ($meta['transport_patient_id'] ?? ''), [
                    'transport_status' => 'completed',
                    'handover_completed_at' => $completed_at,
                    'transport_note' => 'In der Klinik übergeben.',
                ]);

                $base = lsttraining_sim_vehicle_base_position($pdo, $instanz_id, $status_id);
                $last = is_array($meta['last_position'] ?? null) ? $meta['last_position'] : null;
                if ($base && $last) {
                    $route = lsttraining_sim_transport_route(
                        (float) ($last['latitude'] ?? 0),
                        (float) ($last['longitude'] ?? 0),
                        (float) $base['latitude'],
                        (float) $base['longitude'],
                        lsttraining_sim_transport_is_air_unit($meta)
                    );
                    if (count($route['coordinates'] ?? []) < 2) {
                        $meta['mission_phase'] = 'handover';
                        $meta['transport_status'] = 'handover';
                        $meta['handover_completed_at'] = '';
                        $meta['transport_note'] = 'Wartet auf Straßenroute zur Wache.';
                        lsttraining_sim_update_patient_transport($pdo, $instanz_id, $einsatz_id, (string) ($meta['transport_patient_id'] ?? ''), [
                            'transport_status' => 'handover',
                            'transport_note' => 'Übergabe abgeschlossen, Rückfahrt wartet auf Straßenroute.',
                        ]);
                        $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
                        if (empty($meta['return_route_failed_logged'])) {
                            $meta['return_route_failed_logged'] = true;
                            lsttraining_sim_insert_unit_event($pdo, $einsatz_id, $rufname . ': Rückfahrt zur Wache nicht möglich, keine Straßenroute gefunden.', [
                                'event_type' => 'vehicle_return_route_failed',
                                'status_id' => $status_id,
                                'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                                'rufname' => $rufname,
                            ]);
                            $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
                        }
                        continue;
                    }
                    $meta['mission_phase'] = 'returning';
                    $meta['return_started_at'] = $completed_at;
                    $meta['phase_started_at'] = $completed_at;
                    $meta['route_coordinates'] = $route['coordinates'];
                    $meta['route_distance_m'] = $route['distance_m'];
                    $meta['route_duration_sec'] = $route['duration_sec'];
                    $meta['route_duration_normal_sec'] = $route['duration_sec'];
                    $meta['route_source'] = (string) ($route['route_source'] ?? '');
                    $meta['route_segments'] = is_array($route['route_segments'] ?? null) ? $route['route_segments'] : [];
                    $meta['current_progress'] = 0;
                    $meta['current_segment_index'] = 0;
                    $meta['current_segment_progress'] = 0;
                    $meta['base_position'] = $base;
                    lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                        'latitude' => (float) ($last['latitude'] ?? $base['latitude']),
                        'longitude' => (float) ($last['longitude'] ?? $base['longitude']),
                        'ziel_latitude' => (float) $base['latitude'],
                        'ziel_longitude' => (float) $base['longitude'],
                        'status' => 'besetzt',
                        'fms_status' => '1',
                        'sondersignal' => 0,
                        'bemerkung' => 'Rückfahrt zur Wache.',
                    ]);
                    lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': Übergabe abgeschlossen, frei auf Funk, Rückfahrt zur Wache, Status 1, Ende.', [
                        'event_type' => 'patient_handover_completed',
                        'radio_message_type' => 'handover_completed',
                        'sender_type' => 'vehicle',
                        'recipient_type' => 'dispatch',
                        'status_transition' => '1',
                        'fms_status' => '1',
                        'status_id' => $status_id,
                        'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                        'rufname' => $rufname,
                        'patient_id' => (string) ($meta['transport_patient_id'] ?? ''),
                    ]);
                } else {
                    $meta['mission_phase'] = 'available';
                    $meta['return_completed_at'] = $completed_at;
                    if ($last) {
                        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                            'latitude' => (float) ($last['latitude'] ?? 0),
                            'longitude' => (float) ($last['longitude'] ?? 0),
                            'ziel_latitude' => null,
                            'ziel_longitude' => null,
                            'status' => 'frei',
                            'fms_status' => '2',
                            'sondersignal' => 0,
                            'bemerkung' => 'Frei nach Klinikübergabe.',
                        ]);
                    } else {
                        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                            'ziel_latitude' => null,
                            'ziel_longitude' => null,
                            'status' => 'frei',
                            'fms_status' => '2',
                            'sondersignal' => 0,
                            'bemerkung' => 'Frei nach Klinikübergabe.',
                        ]);
                    }
                }
                $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
            }
            continue;
        }

        if (in_array($phase, ['to_hospital', 'returning'], true)) {
            $status_id = (int) ($meta['status_id'] ?? 0);
            $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
            $route = is_array($meta['route_coordinates'] ?? null) ? $meta['route_coordinates'] : [];
            if ($status_id <= 0 || $einsatz_id <= 0 || count($route) < 2) {
                continue;
            }

            $phase_started_at = lsttraining_sim_parse_wp_time($meta['phase_started_at'] ?? ($meta['transport_started_at'] ?? ($meta['return_started_at'] ?? '')));
            if ($phase_started_at <= 0) {
                $phase_started_at = $now;
                $meta['phase_started_at'] = lsttraining_sim_time_string($now);
            }

            $motion = lsttraining_sim_route_movement_state($meta, $now - $phase_started_at);
            if (!$motion) {
                continue;
            }
            $progress = (float) ($motion['progress'] ?? 0);
            $position = $motion['position'];

            $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
            $fms_status = $progress >= 1.0 ? ($phase === 'returning' ? '2' : '8') : ($phase === 'returning' ? '1' : '7');
            $bemerkung = $phase === 'returning'
                ? ($progress >= 1.0 ? 'Frei an der Wache.' : 'Rückfahrt zur Wache.')
                : ($progress >= 1.0 ? 'Am Krankenhaus.' : 'Transport zum Krankenhaus.');
            $vehicle_state = $progress >= 1.0 && $phase === 'returning' ? 'frei' : 'besetzt';

            lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                'latitude' => $position['latitude'],
                'longitude' => $position['longitude'],
                'status' => $vehicle_state,
                'fms_status' => $fms_status,
                'sondersignal' => 0,
                'bemerkung' => $bemerkung,
            ]);

            $meta['current_progress'] = round($progress, 4);
            $meta['current_segment_index'] = (int) ($motion['segment_index'] ?? 0);
            $meta['current_segment_progress'] = round((float) ($motion['segment_progress'] ?? 0), 4);
            $meta['last_position'] = [
                'latitude' => round((float) $position['latitude'], 6),
                'longitude' => round((float) $position['longitude'], 6),
            ];

            if ($progress >= 1.0 && $phase === 'to_hospital' && empty($meta['hospital_arrived_at'])) {
                $arrived_at = lsttraining_sim_time_string($now);
                $handover = lsttraining_sim_handover_window($pdo, $instanz_id, $meta, $now);
                $meta['mission_phase'] = 'handover';
                $meta['transport_status'] = 'handover';
                $meta['hospital_arrived_at'] = $arrived_at;
                $meta['handover_duration_sec'] = (int) $handover['duration_sec'];
                $meta['handover_release_at'] = (string) $handover['release_at'];
                $meta['handover_calculation'] = $handover;
                lsttraining_sim_update_patient_transport($pdo, $instanz_id, $einsatz_id, (string) ($meta['transport_patient_id'] ?? ''), [
                    'transport_status' => 'handover',
                    'transport_arrived_at' => $arrived_at,
                    'handover_duration_sec' => (int) $handover['duration_sec'],
                    'handover_release_at' => (string) $handover['release_at'],
                    'transport_note' => 'Ankunft im Krankenhaus, Übergabe läuft.',
                ]);
                lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': Am Krankenhaus ' . (string) ($meta['transport_hospital_name'] ?? 'Krankenhaus') . ', Status 8, Ende.', [
                    'event_type' => 'patient_transport_arrived',
                    'radio_message_type' => 'hospital_arrival',
                    'sender_type' => 'vehicle',
                    'recipient_type' => 'dispatch',
                    'status_transition' => '8',
                    'status_id' => $status_id,
                    'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                    'rufname' => $rufname,
                    'patient_id' => (string) ($meta['transport_patient_id'] ?? ''),
                    'hospital_id' => (int) ($meta['transport_hospital_id'] ?? 0),
                    'hospital_name' => (string) ($meta['transport_hospital_name'] ?? ''),
                    'handover_duration_sec' => (int) $handover['duration_sec'],
                    'handover_release_at' => (string) $handover['release_at'],
                    'fms_status' => '8',
                ]);
                lsttraining_sim_fire_phase_followups($pdo, $einsatz_id, $meta, $now, 'on_hospital_arrival');
            } elseif ($progress >= 1.0 && $phase === 'returning' && empty($meta['return_completed_at'])) {
                $completed_at = lsttraining_sim_time_string($now);
                $meta['mission_phase'] = 'available';
                $meta['return_completed_at'] = $completed_at;
                $meta['transport_status'] = 'completed';
                $meta['ziel_latitude'] = null;
                $meta['ziel_longitude'] = null;
                lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                    'ziel_latitude' => null,
                    'ziel_longitude' => null,
                ]);
                lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': Einsatzbereit auf Wache, Status 2, Ende.', [
                    'event_type' => 'fms_update',
                    'radio_message_type' => 'available_at_station',
                    'sender_type' => 'vehicle',
                    'recipient_type' => 'dispatch',
                    'status_transition' => '2',
                    'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                    'status_id' => $status_id,
                    'rufname' => $rufname,
                    'fms_status' => '2',
                    'direction' => 'down',
                ]);
                lsttraining_sim_fire_phase_followups($pdo, $einsatz_id, $meta, $now, 'on_vehicle_available');
            }

            $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
            continue;
        }

        if ($phase === 'available') {
            continue;
        }

        if (!empty($meta['arrived_at'])) {
            $meta['mission_phase'] = 'at_scene';
            lsttraining_sim_fire_arrival_followups($pdo, $instanz_id, $event, $meta, $now);
            $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
            continue;
        }

        $status_id = (int) ($meta['status_id'] ?? 0);
        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        $route = is_array($meta['route_coordinates'] ?? null) ? $meta['route_coordinates'] : [];
        if ($status_id <= 0 || $einsatz_id <= 0 || count($route) < 2) {
            continue;
        }

        $alarmiert_at = lsttraining_sim_parse_wp_time($meta['alarmiert_at'] ?? '');
        $ausrueckzeit = max(0, (int) ($meta['ausrueckzeit_sec'] ?? 60));
        $start_at = $alarmiert_at > 0 ? $alarmiert_at + $ausrueckzeit : $now;
        if ($now < $start_at) {
            continue;
        }

        if (empty($meta['movement_started_at'])) {
            $meta['movement_started_at'] = lsttraining_sim_time_string($start_at);
        }

        $motion = lsttraining_sim_route_movement_state($meta, $now - $start_at);
        if (!$motion) {
            continue;
        }
        $progress = (float) ($motion['progress'] ?? 0);
        $position = $motion['position'];

        $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
        $fms_status = $progress >= 1.0 ? '4' : '3';
        $sondersignal = ($progress >= 1.0 || empty($meta['sondersignal_allowed'])) ? 0 : 1;
        $bemerkung = $progress >= 1.0
            ? 'Am Einsatzort.'
            : 'Auf Anfahrt zum Einsatz.';

        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
            'latitude' => $position['latitude'],
            'longitude' => $position['longitude'],
            'status' => 'besetzt',
            'fms_status' => $fms_status,
            'sondersignal' => $sondersignal,
            'bemerkung' => $bemerkung,
        ]);

        if (empty($meta['started_event_logged'])) {
            $meta['started_event_logged'] = true;
            lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': Ausgerückt, Status 3, Ende.', [
                'event_type' => 'fms_update',
                'radio_message_type' => 'turnout',
                'sender_type' => 'vehicle',
                'recipient_type' => 'dispatch',
                'status_transition' => '3',
                'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                'status_id' => $status_id,
                'rufname' => $rufname,
                'fms_status' => '3',
                'direction' => 'down',
            ]);
        }

        if ($progress >= 1.0 && empty($meta['arrived_event_logged'])) {
            $meta['arrived_at'] = lsttraining_sim_time_string($now);
            $meta['mission_phase'] = 'at_scene';
            $meta['arrived_event_logged'] = true;
            lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': An Einsatzstelle, Status 4, Ende.', [
                'event_type' => 'fms_update',
                'radio_message_type' => 'scene_arrival',
                'sender_type' => 'vehicle',
                'recipient_type' => 'dispatch',
                'status_transition' => '4',
                'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                'status_id' => $status_id,
                'rufname' => $rufname,
                'fms_status' => '4',
                'direction' => 'down',
            ]);
        }

        if (!empty($meta['arrived_at'])) {
            lsttraining_sim_fire_arrival_followups($pdo, $instanz_id, $event, $meta, $now);
        }

        $meta['current_progress'] = round($progress, 4);
        $meta['current_segment_index'] = (int) ($motion['segment_index'] ?? 0);
        $meta['current_segment_progress'] = round((float) ($motion['segment_progress'] ?? 0), 4);
        $meta['last_position'] = [
            'latitude' => round((float) $position['latitude'], 6),
            'longitude' => round((float) $position['longitude'], 6),
        ];
        $event_update->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);
    }
}

function lsttraining_sim_incident_meta_value(array $meta, string $key): string {
    return trim((string) ($meta[$key] ?? ($meta['caller'][$key] ?? '')));
}

function lsttraining_sim_motorway_pending_label(): string {
    return 'Autobahn-Ortsangabe wird ermittelt';
}

function lsttraining_sim_motorway_label_for_incident(array $incident): string {
    $meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    $stored_label = lsttraining_sim_incident_meta_value($meta, 'motorway_location_label');
    if (
        $stored_label !== ''
        && lsttraining_sim_spawn_motorway_label_is_complete($stored_label)
    ) {
        return $stored_label;
    }

    $road_ref = lsttraining_sim_incident_meta_value($meta, 'road_ref');
    $road_name = lsttraining_sim_incident_meta_value($meta, 'road_name');
    $highway = strtolower(lsttraining_sim_incident_meta_value($meta, 'road_highway'));
    $city = lsttraining_sim_incident_meta_value($meta, 'address_suburb');
    if ($city === '') {
        $city = lsttraining_sim_incident_meta_value($meta, 'address_city');
    }
    $ref_normalized = lsttraining_sim_spawn_motorway_ref($road_ref, implode(' ', [
        $road_name,
        lsttraining_sim_incident_meta_value($meta, 'road_destination'),
        lsttraining_sim_incident_meta_value($meta, 'road_destination_forward'),
        lsttraining_sim_incident_meta_value($meta, 'road_destination_backward'),
        lsttraining_sim_incident_meta_value($meta, 'motorway_ref'),
    ]));
    $is_motorway = lsttraining_sim_spawn_is_motorway_road($highway, $road_ref, $road_name)
        || preg_match('/^[AB]\s*\d+/', $ref_normalized) === 1;
    if (!$is_motorway && $stored_label !== '' && (stripos($stored_label, 'Autobahn') !== false || stripos($stored_label, 'Schnellstra') !== false)) {
        $is_motorway = true;
    }
    if (!$is_motorway) {
        return '';
    }

    $direction = lsttraining_sim_incident_meta_value($meta, 'motorway_direction');
    if ($direction === '') {
        $bearing = $meta['motorway_bearing'] ?? ($meta['caller']['motorway_bearing'] ?? ($meta['road_bearing_deg'] ?? null));
        $direction = lsttraining_sim_spawn_motorway_direction_label($bearing);
    }
    if ($direction === '' && preg_match('/\b(?:Fahrt)?richtung\s+(Norden|Nordosten|Osten|Südosten|Sueden|Süden|Suedwesten|Südwesten|Westen|Nordwesten)\b/iu', $stored_label, $match) === 1) {
        $direction = strtr($match[1], ['Sueden' => 'Süden', 'Suedwesten' => 'Südwesten']);
    }

    $section = lsttraining_sim_incident_meta_value($meta, 'motorway_section');
    $place = lsttraining_sim_incident_meta_value($meta, 'motorway_place');
    if ($section === '' || lsttraining_sim_spawn_is_generic_motorway_label($section)) {
        $section = $city;
    }
    if (($section === '' || lsttraining_sim_spawn_is_generic_motorway_label($section)) && $place !== '') {
        $section = $place;
    }
    if ($place === '' || lsttraining_sim_spawn_is_generic_motorway_label($place)) {
        $place = $city;
    }
    if (($section === '' || lsttraining_sim_spawn_is_generic_motorway_label($section)) && $road_name !== '' && strcasecmp($road_name, $ref_normalized) !== 0 && strcasecmp($road_name, 'Autobahn') !== 0) {
        $section = $road_name;
    }
    $is_trunk = $highway === 'trunk' || $highway === 'trunk_link' || stripos($ref_normalized, 'B ') === 0;
    return lsttraining_sim_spawn_motorway_label($ref_normalized, $direction, $section, $place, $is_trunk);
}

function lsttraining_sim_incident_needs_motorway_repair(array $incident): bool {
    if (lsttraining_sim_motorway_label_for_incident($incident) !== '') {
        return false;
    }

    $meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    $stored_label = lsttraining_sim_incident_meta_value($meta, 'motorway_location_label');
    $generated = lsttraining_sim_incident_meta_value($meta, 'generated_address');
    $road_ref = lsttraining_sim_incident_meta_value($meta, 'road_ref');
    $road_name = lsttraining_sim_incident_meta_value($meta, 'road_name');
    $highway = lsttraining_sim_incident_meta_value($meta, 'road_highway');

    return lsttraining_sim_spawn_is_motorway_road($highway, $road_ref, $road_name)
        || stripos($stored_label, 'Autobahn') !== false
        || stripos($stored_label, 'Schnellstra') !== false
        || stripos($generated, 'Autobahn') !== false
        || preg_match('/\bbei\s+(?:der\s+)?Autobahn\b/i', (string) ($incident['caller_text'] ?? '')) === 1;
}

function lsttraining_sim_enrich_incident_motorway_meta(PDO $pdo, array $incident): array {
    unset($pdo);
    return $incident;
}

function lsttraining_sim_display_address_for_incident(array $incident): string {
    $meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    $motorway = lsttraining_sim_motorway_label_for_incident($incident);
    if ($motorway !== '') {
        return $motorway;
    }
    if (lsttraining_sim_incident_needs_motorway_repair($incident)) {
        return lsttraining_sim_motorway_pending_label();
    }
    $address = trim((string) ($meta['generated_address'] ?? ($meta['caller']['generated_address'] ?? '')));
    if ($address !== '' && !lsttraining_sim_is_visible_location_fallback($address)) {
        return $address;
    }

    $poi = trim((string) ($incident['poi_name_snapshot'] ?? ''));
    if ($poi !== '') {
        return $poi;
    }

    $road = trim((string) ($meta['road_name'] ?? ($meta['caller']['road_name'] ?? '')));
    if ($road !== '' && !lsttraining_sim_is_visible_location_fallback($road)) {
        return $road;
    }

    $leitstelle = trim((string) ($incident['leitstelle_name'] ?? ''));
    return $leitstelle !== '' ? 'im Einsatzgebiet ' . $leitstelle : 'im Einsatzgebiet';
}

function lsttraining_sim_alarm_location_text(array $incident): string {
    $location = trim(lsttraining_sim_display_address_for_incident($incident));
    if ($location !== '' && $location !== 'im Einsatzgebiet' && strpos($location, 'im Einsatzgebiet ') !== 0) {
        return $location;
    }

    $meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    foreach ([
        $meta['generated_address'] ?? '',
        $meta['caller']['generated_address'] ?? '',
        $incident['poi_name_snapshot'] ?? '',
    ] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && !lsttraining_sim_is_visible_location_fallback($candidate)) {
            return $candidate;
        }
    }

    $lat = is_numeric($incident['latitude'] ?? null) ? (float) $incident['latitude'] : null;
    $lon = is_numeric($incident['longitude'] ?? null) ? (float) $incident['longitude'] : null;
    if ($lat !== null && $lon !== null) {
        return 'Einsatzort laut Karte ' . number_format($lat, 5, ',', '') . ', ' . number_format($lon, 5, ',', '');
    }

    return $location !== '' ? $location : 'Einsatzort laut Karte';
}

function lsttraining_sim_contains_raw_gps_text(string $text): bool {
    return preg_match('/\bGPS\s+-?\d+(?:[.,]\d+)?\s*,\s*-?\d+(?:[.,]\d+)?\b/i', $text) === 1;
}

function lsttraining_sim_is_visible_location_fallback(string $text): bool {
    $value = trim($text);
    if ($value === '') {
        return false;
    }

    return stripos($value, 'einsatzkoordinate') !== false
        || stripos($value, 'ortbereich') !== false
        || lsttraining_sim_spawn_is_generic_motorway_label($value)
        || preg_match('/\bbei\s+(?:der\s+)?Autobahn\b/i', $value) === 1
        || lsttraining_sim_contains_raw_gps_text($value);
}

function lsttraining_sim_clean_caller_text_for_display(string $text, array $incident): string {
    if ($text === '' || !lsttraining_sim_is_visible_location_fallback($text)) {
        return $text;
    }

    $address = lsttraining_sim_display_address_for_incident($incident);
    if ($address === lsttraining_sim_motorway_pending_label()) {
        return (string) preg_replace('/\bbei\s+(?:der\s+)?Autobahn\b/i', 'auf einer Autobahn im Einsatzgebiet', $text);
    }
    $motorway = lsttraining_sim_motorway_label_for_incident($incident);
    $addressAfterBei = $motorway !== ''
        ? 'auf der ' . $motorway
        : (preg_match('/^(im|am|an|auf)\b/i', $address) ? $address : 'bei ' . $address);

    $cleaned = str_ireplace(
        [
            'bei nahe der Einsatzkoordinate',
            'nahe der Einsatzkoordinate',
            'Einsatzkoordinate',
        ],
        [
            $addressAfterBei,
            $address,
            $address,
        ],
        $text
    );

    $cleaned = (string) preg_replace(
        '/\bbei\s+GPS\s+-?\d+(?:[.,]\d+)?\s*,\s*-?\d+(?:[.,]\d+)?\b/i',
        $addressAfterBei,
        $cleaned
    );
    $cleaned = (string) preg_replace(
        '/\bGPS\s+-?\d+(?:[.,]\d+)?\s*,\s*-?\d+(?:[.,]\d+)?\b/i',
        $address,
        $cleaned
    );
    $cleaned = (string) preg_replace(
        '/\bbei\s+Ortsbereich\s+[^,.!?]+/i',
        $addressAfterBei,
        $cleaned
    );
    $cleaned = (string) preg_replace('/\bbei\s+(?:der\s+)?Autobahn\b/i', $addressAfterBei, $cleaned);
    return (string) preg_replace('/\bOrtsbereich\s+[^,.!?]+/i', $address, $cleaned);
}

function lsttraining_sim_fetch_instance_context(PDO $pdo, int $instanz_id, int $user_id): array {
    $stmt = $pdo->prepare('
        SELECT
            si.id,
            si.leitstelle_id,
            si.name,
            si.started_at,
            si.settings_json,
            si.sim_state,
            l.name AS leitstelle_name,
            l.ort AS leitstelle_ort,
            l.bundesland AS leitstelle_bundesland,
            l.latitude AS leitstelle_latitude,
            l.longitude AS leitstelle_longitude,
            l.geojson AS leitstelle_geojson,
            iu.rolle AS user_rolle
        FROM spielinstanzen si
        INNER JOIN leitstellen l ON l.id = si.leitstelle_id
        LEFT JOIN instanz_user iu ON iu.instanz_id = si.id AND iu.user_id = ?
        WHERE si.id = ?
        LIMIT 1
    ');
    $stmt->execute([$user_id, $instanz_id]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$instance) {
        throw new RuntimeException('Simulation nicht gefunden.');
    }

    $settings = json_decode((string) ($instance['settings_json'] ?? ''), true);
    $instance['settings'] = is_array($settings) ? $settings : [];
    if ((string) ($instance['settings']['vehicle_state_model'] ?? '') !== lsttraining_sim_vehicle_state_model()) {
        throw new RuntimeException('Diese Simulation nutzt ein altes Fahrzeugstatusmodell. Bitte starten Sie eine neue Simulation.');
    }
    unset($instance['settings_json']);
    $runtime = lsttraining_sim_runtime_state($instance['settings'], $instance);
    $instance['settings']['sim_speed_multiplier'] = $runtime['speed'];
    $instance['settings']['sim_paused'] = $runtime['paused'];
    $instance['sim_now'] = $runtime['sim_now'];
    $instance['sim_timestamp'] = $runtime['game_now_ts'];
    $instance['speed'] = $runtime['speed'];
    $instance['paused'] = $runtime['paused'];
    $instance['weather_current'] = lsttraining_sim_weather_point_for_timestamp($instance['settings'], (int) $runtime['game_now_ts']);
    $instance['weather_forecast_summary'] = lsttraining_sim_weather_forecast_summary($instance['settings'], (int) $runtime['game_now_ts']);
    $instance['police_vehicle_image_url'] = lsttraining_sim_police_vehicle_image_url($pdo, (int) ($instance['leitstelle_id'] ?? 0));
    $instance['rescue_vehicle_image_url'] = lsttraining_sim_public_vehicle_image_url(
        lsttraining_sim_rescue_vehicle_image_path($pdo, (int) ($instance['leitstelle_id'] ?? 0)),
        'img/fahrzeug/default.png'
    );

    return $instance;
}

function lsttraining_sim_fetch_bootstrap_stations(PDO $pdo, int $leitstelle_id): array {
    $station_stmt = $pdo->prepare('
        SELECT
            w.id,
            w.name,
            w.typ,
            w.latitude,
            w.longitude,
            w.bild_datei
        FROM wache_leitstellen wl
        INNER JOIN wachen w ON w.id = wl.wache_id
        WHERE wl.leitstelle_id = ?
        ORDER BY w.name, w.id
    ');
    $station_stmt->execute([$leitstelle_id]);
    $stations = $station_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stations as &$station) {
        $station['latitude'] = $station['latitude'] !== null ? (float) $station['latitude'] : null;
        $station['longitude'] = $station['longitude'] !== null ? (float) $station['longitude'] : null;
        $station['kind'] = lsttraining_sim_station_kind((string) ($station['typ'] ?? ''));
        $station['image_url'] = lsttraining_sim_public_vehicle_image_url($station['bild_datei'] ?? '');
        unset($station['bild_datei']);
    }
    unset($station);

    return $stations;
}

function lsttraining_sim_fetch_bootstrap_vehicles(PDO $pdo, int $instanz_id, int $leitstelle_id): array {
    $fahrzeuge_columns = lsttraining_sim_workspace_table_columns($pdo, 'fahrzeuge');
    $signal_lights_select = !empty($fahrzeuge_columns['signal_lights_json'])
        ? 'f.signal_lights_json'
        : 'NULL AS signal_lights_json';
    $vehicle_stmt = $pdo->prepare('
        SELECT
            fs.id AS status_id,
            fs.fahrzeug_id,
            fs.wache_id,
            fs.status,
            fs.fms_status,
            fs.sondersignal,
            fs.bemerkung,
            fs.letzte_aktualisierung,
            COALESCE(fs.latitude, f.latitude, w.latitude) AS latitude,
            COALESCE(fs.longitude, f.longitude, w.longitude) AS longitude,
            f.rufname,
            f.fahrzeugtyp,
            f.bild_datei,
            ' . $signal_lights_select . ',
            w.name AS wache_name
        FROM fahrzeug_status fs
        LEFT JOIN fahrzeuge f ON f.id = fs.fahrzeug_id
        LEFT JOIN wachen w ON w.id = fs.wache_id
        WHERE fs.instanz_id = ?
        ORDER BY f.rufname, fs.id
    ');
    $vehicle_stmt->execute([$instanz_id]);
    $vehicles = $vehicle_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vehicles as &$vehicle) {
        $vehicle['status_id'] = (int) ($vehicle['status_id'] ?? 0);
        $vehicle['fahrzeug_id'] = (int) ($vehicle['fahrzeug_id'] ?? 0);
        $vehicle['wache_id'] = (int) ($vehicle['wache_id'] ?? 0);
        $vehicle['sondersignal'] = (int) ($vehicle['sondersignal'] ?? 0);
        $vehicle['latitude'] = $vehicle['latitude'] !== null ? (float) $vehicle['latitude'] : null;
        $vehicle['longitude'] = $vehicle['longitude'] !== null ? (float) $vehicle['longitude'] : null;
        $vehicle['resource_class'] = lsttraining_sim_resource_class_from_type((string) ($vehicle['fahrzeugtyp'] ?? ''));
        $vehicle['resource_class_label'] = lsttraining_sim_resource_class_label((string) ($vehicle['resource_class'] ?? ''));
        lsttraining_sim_apply_vehicle_visual_defaults($pdo, $leitstelle_id, $vehicle);
        unset($vehicle['signal_lights_json']);
        unset($vehicle['bild_datei']);
    }
    unset($vehicle);

    return $vehicles;
}

function lsttraining_sim_ensure_neighbor_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    if (!lsttraining_sim_table_exists($pdo, 'leitstelle_nebenleitstellen')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `leitstelle_nebenleitstellen` (
              `leitstelle_id` INT NOT NULL,
              `nebenleitstelle_id` INT NOT NULL,
              PRIMARY KEY (`leitstelle_id`, `nebenleitstelle_id`),
              KEY `idx_ln_nebenleitstelle` (`nebenleitstelle_id`),
              CONSTRAINT `fk_ln_leitstelle`
                FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_ln_nebenleitstelle`
                FOREIGN KEY (`nebenleitstelle_id`) REFERENCES `nebenleitstellen`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
    $ready = true;
}

function lsttraining_sim_fetch_neighbor_dispatch_centers(PDO $pdo, int $leitstelle_id): array {
    if ($leitstelle_id <= 0) {
        return [];
    }
    lsttraining_sim_ensure_neighbor_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT
            n.id,
            n.name,
            n.zustandigkeit,
            n.gps,
            n.geojson
        FROM leitstelle_nebenleitstellen ln
        INNER JOIN leitstellen l ON l.id = ln.leitstelle_id
        INNER JOIN nebenleitstellen n ON n.id = ln.nebenleitstelle_id
        WHERE ln.leitstelle_id = ?
          AND ln.nebenleitstelle_id <> ln.leitstelle_id
          AND LOWER(CONVERT(TRIM(n.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci <> LOWER(CONVERT(TRIM(l.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci
        ORDER BY n.name ASC, n.id ASC
    ');
    $stmt->execute([$leitstelle_id]);
    $items = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $lat = null;
        $lon = null;
        $gps = trim((string) ($row['gps'] ?? ''));
        if ($gps !== '' && preg_match('/(-?\d+(?:[.,]\d+)?)\s*[,;]\s*(-?\d+(?:[.,]\d+)?)/', $gps, $match)) {
            $lat = (float) str_replace(',', '.', $match[1]);
            $lon = (float) str_replace(',', '.', $match[2]);
        }
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'zustandigkeit' => (string) ($row['zustandigkeit'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lon,
        ];
    }
    return $items;
}

function lsttraining_sim_fetch_neighbor_stations(PDO $pdo, int $leitstelle_id): array {
    if ($leitstelle_id <= 0) {
        return [];
    }
    lsttraining_sim_ensure_neighbor_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT
            w.id,
            w.name,
            w.typ,
            w.latitude,
            w.longitude,
            w.bild_datei,
            n.id AS nebenleitstelle_id,
            n.name AS nebenleitstelle_name
        FROM leitstelle_nebenleitstellen ln
        INNER JOIN leitstellen l ON l.id = ln.leitstelle_id
        INNER JOIN nebenleitstellen n ON n.id = ln.nebenleitstelle_id
        INNER JOIN wache_nebenleitstellen wn ON wn.nebenleitstelle_id = n.id
        INNER JOIN wachen w ON w.id = wn.wache_id
        WHERE ln.leitstelle_id = ?
          AND ln.nebenleitstelle_id <> ln.leitstelle_id
          AND LOWER(CONVERT(TRIM(n.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci <> LOWER(CONVERT(TRIM(l.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci
        ORDER BY n.name ASC, w.name ASC, w.id ASC
    ');
    $stmt->execute([$leitstelle_id]);
    $stations = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $station) {
        $stations[] = [
            'id' => (int) ($station['id'] ?? 0),
            'name' => (string) ($station['name'] ?? ''),
            'typ' => (string) ($station['typ'] ?? ''),
            'latitude' => $station['latitude'] !== null ? (float) $station['latitude'] : null,
            'longitude' => $station['longitude'] !== null ? (float) $station['longitude'] : null,
            'kind' => lsttraining_sim_station_kind((string) ($station['typ'] ?? '')),
            'image_url' => lsttraining_sim_public_vehicle_image_url($station['bild_datei'] ?? ''),
            'is_neighbor' => true,
            'nebenleitstelle_id' => (int) ($station['nebenleitstelle_id'] ?? 0),
            'nebenleitstelle_name' => (string) ($station['nebenleitstelle_name'] ?? ''),
        ];
    }
    return $stations;
}

function lsttraining_sim_neighbor_support_states(PDO $pdo, int $instanz_id): array {
    $stmt = $pdo->prepare('
        SELECT ev.meta_json
        FROM instanz_einsatz_events ev
        INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
        WHERE ie.instanz_id = ? AND ev.kind = ?
        ORDER BY ev.id DESC
        LIMIT 800
    ');
    $stmt->execute([$instanz_id, 'unit_report']);
    $states = [];
    foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $raw_meta) {
        $meta = lsttraining_sim_decode_meta($raw_meta);
        if (($meta['event_type'] ?? '') !== 'support_vehicle_alarm' || ($meta['support_type'] ?? '') !== 'neighbor') {
            continue;
        }
        if (!empty($meta['cancelled_at'])) {
            continue;
        }
        $fahrzeug_id = (int) ($meta['fahrzeug_id'] ?? 0);
        if ($fahrzeug_id <= 0 || isset($states[$fahrzeug_id])) {
            continue;
        }
        $phase = (string) ($meta['mission_phase'] ?? 'to_scene');
        if ($phase === 'available') {
            continue;
        }
        $states[$fahrzeug_id] = [
            'state' => $phase === 'returning'
                ? 'Rückfahrt zur Heimatleitstelle'
                : ($phase === 'at_scene' ? 'im Fremdeinsatz' : 'unterwegs zur spielbaren Leitstelle'),
            'phase' => $phase,
            'einsatz_id' => (int) ($meta['einsatz_id'] ?? 0),
        ];
    }
    return $states;
}

function lsttraining_sim_neighbor_vehicle_roll(int $instanz_id, int $fahrzeug_id, int $sim_now_ts): int {
    $hour_bucket = wp_date('Y-m-d-H', $sim_now_ts);
    $hash = crc32($instanz_id . ':' . $fahrzeug_id . ':' . $hour_bucket . ':neighbor-availability');
    return (int) ($hash % 100);
}

function lsttraining_sim_load_bundesland_stats(): array {
    static $stats = null;
    if (is_array($stats)) {
        return $stats;
    }
    $stats = [];
    $path = dirname(__DIR__, 2) . '/data/einsatzbelastung-bundeslaender.json';
    if (is_readable($path)) {
        $rows = json_decode((string) file_get_contents($path), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && !empty($row['bundesland'])) {
                    $stats[(string) $row['bundesland']] = $row;
                }
            }
        }
    }
    return $stats;
}

function lsttraining_sim_neighbor_time_curve(int $hour, string $domain): float {
    if ($domain === 'fw') {
        if ($hour < 6) return 0.55;
        if ($hour < 12) return 0.95;
        if ($hour < 18) return 1.25;
        return 1.15;
    }
    if ($hour < 6) return 0.62;
    if ($hour < 12) return 1.10;
    if ($hour < 18) return 1.30;
    return 0.96;
}

function lsttraining_sim_neighbor_load_profile(array $vehicle, int $instanz_id, int $sim_now_ts, array $weather): array {
    $population = max(10000, (int) ($vehicle['neben_einwohner'] ?? 0));
    $area = max(50.0, (float) ($vehicle['neben_flaeche_km2'] ?? 0));
    $bundesland = trim((string) (($vehicle['wache_bundesland'] ?? '') ?: ($vehicle['leitstelle_bundesland'] ?? '')));
    $stats = lsttraining_sim_load_bundesland_stats();
    $row = $stats[$bundesland] ?? [];
    $rd_year = max(120000, (int) ($row['rettungsdienst_einsaetze_gesamt'] ?? 650000));
    $fw_year = max(12000, (int) ($row['feuerwehr_einsaetze_gesamt'] ?? 80000));
    $state_pop = [
        'Baden-Württemberg' => 11280000, 'Bayern' => 13430000, 'Berlin' => 3878000, 'Brandenburg' => 2573000,
        'Bremen' => 684000, 'Hamburg' => 1960000, 'Hessen' => 6390000, 'Mecklenburg-Vorpommern' => 1630000,
        'Niedersachsen' => 8140000, 'Nordrhein-Westfalen' => 18190000, 'Rheinland-Pfalz' => 4160000, 'Saarland' => 1000000,
        'Sachsen' => 4090000, 'Sachsen-Anhalt' => 2190000, 'Schleswig-Holstein' => 2960000, 'Thüringen' => 2130000,
    ][$bundesland] ?? 5000000;
    $rd_per_person_day = ($rd_year / max(1, $state_pop)) / 365.0;
    $fw_per_person_day = ($fw_year / max(1, $state_pop)) / 365.0;
    $hour = (int) wp_date('G', $sim_now_ts);
    $season = (string) wp_date('n', $sim_now_ts);
    $season_rd = in_array((int) $season, [12, 1, 2], true) ? 1.08 : (in_array((int) $season, [6, 7, 8], true) ? 0.98 : 1.02);
    $season_fw = in_array((int) $season, [6, 7, 8, 9, 10], true) ? 1.10 : (in_array((int) $season, [12, 1, 2], true) ? 1.06 : 1.0);
    $area_duration_factor = max(0.85, min(1.35, 0.95 + sqrt($area) / 90.0));
    $rd_parallel = $population * $rd_per_person_day * lsttraining_sim_neighbor_time_curve($hour, 'rd') * $season_rd * $area_duration_factor * lsttraining_sim_weather_factor($weather, 'rd');
    $fw_parallel = $population * $fw_per_person_day * lsttraining_sim_neighbor_time_curve($hour, 'fw') * $season_fw * $area_duration_factor * lsttraining_sim_weather_factor($weather, 'fw');
    return [
        'bundesland' => $bundesland,
        'population' => $population,
        'area_km2' => round($area, 2),
        'rd_parallel_estimate' => round($rd_parallel, 2),
        'fw_parallel_estimate' => round($fw_parallel, 2),
        'weather_primary' => (string) ($weather['primary'] ?? 'clear'),
        'weather_tags' => is_array($weather['tags'] ?? null) ? $weather['tags'] : [],
        'source_status' => (string) ($row['quelle_status'] ?? 'fallback'),
    ];
}

function lsttraining_sim_weight_neighbor_vehicle_binding(array $vehicle, array $load_profile): float {
    $class = (string) ($vehicle['resource_class'] ?? lsttraining_sim_resource_class_from_type((string) ($vehicle['fahrzeugtyp'] ?? '')));
    $rd = (float) ($load_profile['rd_parallel_estimate'] ?? 0);
    $fw = (float) ($load_profile['fw_parallel_estimate'] ?? 0);
    $tags = is_array($load_profile['weather_tags'] ?? null) ? $load_profile['weather_tags'] : [];
    $vehicle_pool_hint = max(3.0, min(90.0, sqrt((float) ($load_profile['population'] ?? 100000)) / 45.0));
    $prob = 6.0;
    if (in_array($class, ['rettungswagen', 'krankentransport', 'notarzt'], true)) {
        $prob += min(42.0, ($rd / $vehicle_pool_hint) * 26.0);
        if ($class === 'krankentransport') $prob += 5.0;
        if ($class === 'notarzt') $prob += 6.0;
    } elseif (in_array($class, ['loeschfahrzeug', 'ruestung', 'hubrettung', 'fuehrung'], true)) {
        $prob += min(34.0, ($fw / $vehicle_pool_hint) * 34.0);
        if (array_intersect($tags, ['storm', 'windy', 'rain', 'snow'])) $prob += 8.0;
        if (in_array($class, ['ruestung', 'hubrettung', 'fuehrung'], true)) $prob += 4.0;
    } else {
        $prob += min(22.0, (($rd + $fw) / $vehicle_pool_hint) * 14.0);
        if (array_intersect($tags, ['storm', 'windy'])) $prob += 6.0;
    }
    return max(3.0, min(68.0, $prob));
}

function lsttraining_sim_neighbor_vehicle_availability(array $vehicle, int $instanz_id, int $sim_now_ts, array $support_states, array $weather, array $load_profile): array {
    $fahrzeug_id = (int) ($vehicle['fahrzeug_id'] ?? ($vehicle['id'] ?? 0));
    if ($fahrzeug_id > 0 && isset($support_states[$fahrzeug_id])) {
        return [
            'availability_state' => (string) ($support_states[$fahrzeug_id]['state'] ?? 'bereits angefordert'),
            'available' => false,
            'load_profile' => $load_profile,
        ];
    }

    $status = (string) ($vehicle['status'] ?? 'frei');
    $fms = (string) ($vehicle['fms_status'] ?? '2');
    if ($status === 'nicht einsatzbereit' || $fms === '6') {
        return ['availability_state' => 'nicht dienstbereit', 'available' => false, 'load_profile' => $load_profile];
    }
    if (!in_array($status, ['frei', 'einsatzbereit', ''], true) || !in_array($fms, ['1', '2', ''], true)) {
        return ['availability_state' => 'im Einsatz der Heimatleitstelle', 'available' => false, 'load_profile' => $load_profile];
    }

    $out_of_service_threshold = 5;
    $hour = (int) wp_date('G', $sim_now_ts);
    if ($hour >= 22 || $hour < 6) {
        $out_of_service_threshold += 3;
    }

    $roll = lsttraining_sim_neighbor_vehicle_roll($instanz_id, $fahrzeug_id, $sim_now_ts);
    if ($roll < $out_of_service_threshold) {
        return ['availability_state' => 'nicht dienstbereit', 'available' => false, 'load_profile' => $load_profile];
    }
    $busy_probability = lsttraining_sim_weight_neighbor_vehicle_binding($vehicle, $load_profile);
    if ($roll < $out_of_service_threshold + $busy_probability) {
        return ['availability_state' => 'im Einsatz der Heimatleitstelle', 'available' => false, 'load_profile' => $load_profile];
    }
    return ['availability_state' => 'verfügbar', 'available' => true, 'load_profile' => $load_profile];
}

function lsttraining_sim_fetch_neighbor_vehicle_availability(PDO $pdo, int $instanz_id, int $leitstelle_id, int $sim_now_ts): array {
    if ($instanz_id <= 0 || $leitstelle_id <= 0) {
        return [];
    }
    lsttraining_sim_ensure_neighbor_schema($pdo);
    $settings = [];
    try {
        $settings_stmt = $pdo->prepare('SELECT settings_json FROM spielinstanzen WHERE id = ? LIMIT 1');
        $settings_stmt->execute([$instanz_id]);
        $settings = lsttraining_sim_decode_meta((string) $settings_stmt->fetchColumn());
    } catch (Throwable $e) {
        $settings = [];
    }
    $weather_current = lsttraining_sim_weather_point_for_timestamp($settings, $sim_now_ts);

    $fahrzeuge_columns = lsttraining_sim_workspace_table_columns($pdo, 'fahrzeuge');
    $signal_lights_select = !empty($fahrzeuge_columns['signal_lights_json'])
        ? 'f.signal_lights_json'
        : 'NULL AS signal_lights_json';
    $stmt = $pdo->prepare('
        SELECT
            f.id AS fahrzeug_id,
            f.wache_id,
            f.rufname,
            f.fahrzeugtyp,
            f.status,
            f.fms_status,
            f.sondersignal,
            f.dienstzeiten,
            f.bild_datei,
            ' . $signal_lights_select . ',
            w.name AS wache_name,
            w.bundesland AS wache_bundesland,
            w.latitude AS wache_latitude,
            w.longitude AS wache_longitude,
            COALESCE(f.latitude, w.latitude) AS latitude,
            COALESCE(f.longitude, w.longitude) AS longitude,
            n.id AS nebenleitstelle_id,
            n.name AS nebenleitstelle_name,
            n.einwohner AS neben_einwohner,
            n.flaeche_km2 AS neben_flaeche_km2,
            l.bundesland AS leitstelle_bundesland
        FROM leitstelle_nebenleitstellen ln
        INNER JOIN leitstellen l ON l.id = ln.leitstelle_id
        INNER JOIN nebenleitstellen n ON n.id = ln.nebenleitstelle_id
        INNER JOIN wache_nebenleitstellen wn ON wn.nebenleitstelle_id = n.id
        INNER JOIN wachen w ON w.id = wn.wache_id
        INNER JOIN fahrzeuge f ON f.wache_id = w.id
        WHERE ln.leitstelle_id = ?
          AND ln.nebenleitstelle_id <> ln.leitstelle_id
          AND LOWER(CONVERT(TRIM(n.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci <> LOWER(CONVERT(TRIM(l.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci
        ORDER BY n.name ASC, w.name ASC, f.rufname ASC, f.id ASC
    ');
    $stmt->execute([$leitstelle_id]);
    $support_states = lsttraining_sim_neighbor_support_states($pdo, $instanz_id);
    $vehicles = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $vehicle) {
        $vehicle['status_id'] = 0;
        $vehicle['fahrzeug_id'] = (int) ($vehicle['fahrzeug_id'] ?? 0);
        $vehicle['wache_id'] = (int) ($vehicle['wache_id'] ?? 0);
        $vehicle['nebenleitstelle_id'] = (int) ($vehicle['nebenleitstelle_id'] ?? 0);
        $vehicle['sondersignal'] = (int) ($vehicle['sondersignal'] ?? 0);
        $vehicle['latitude'] = $vehicle['latitude'] !== null ? (float) $vehicle['latitude'] : null;
        $vehicle['longitude'] = $vehicle['longitude'] !== null ? (float) $vehicle['longitude'] : null;
        $vehicle['wache_latitude'] = $vehicle['wache_latitude'] !== null ? (float) $vehicle['wache_latitude'] : null;
        $vehicle['wache_longitude'] = $vehicle['wache_longitude'] !== null ? (float) $vehicle['wache_longitude'] : null;
        $vehicle['resource_class'] = lsttraining_sim_resource_class_from_type((string) ($vehicle['fahrzeugtyp'] ?? ''));
        $vehicle['resource_class_label'] = lsttraining_sim_resource_class_label((string) ($vehicle['resource_class'] ?? ''));
        $load_profile = lsttraining_sim_neighbor_load_profile($vehicle, $instanz_id, $sim_now_ts, $weather_current);
        $availability = lsttraining_sim_neighbor_vehicle_availability($vehicle, $instanz_id, $sim_now_ts, $support_states, $weather_current, $load_profile);
        $vehicle['image_url'] = lsttraining_sim_public_vehicle_image_url($vehicle['bild_datei'] ?? '', 'img/fahrzeug/default.png');
        $vehicle['signal_lights'] = lsttraining_sim_signal_lights_for_vehicle(
            (string) ($vehicle['signal_lights_json'] ?? ''),
            (string) ($vehicle['fahrzeugtyp'] ?? ''),
            (string) ($vehicle['rufname'] ?? '')
        );
        $vehicle['availability_state'] = $availability['availability_state'];
        $vehicle['available'] = !empty($availability['available']);
        $vehicle['load_profile'] = is_array($availability['load_profile'] ?? null) ? $availability['load_profile'] : $load_profile;
        $vehicle['weather_primary'] = (string) ($weather_current['primary'] ?? 'clear');
        $vehicle['weather_tags'] = is_array($weather_current['tags'] ?? null) ? $weather_current['tags'] : [];
        $vehicle['dispatch_available'] = false;
        $vehicle['is_neighbor'] = true;
        unset($vehicle['signal_lights_json'], $vehicle['bild_datei'], $vehicle['neben_einwohner'], $vehicle['neben_flaeche_km2'], $vehicle['leitstelle_bundesland']);
        $vehicles[] = $vehicle;
    }
    return $vehicles;
}

function lsttraining_sim_neighbor_offer_from_vehicle(array $vehicle): array {
    return [
        'fahrzeug_id' => (int) ($vehicle['fahrzeug_id'] ?? 0),
        'wache_id' => (int) ($vehicle['wache_id'] ?? 0),
        'home_wache_id' => (int) ($vehicle['wache_id'] ?? 0),
        'home_wache_name' => (string) ($vehicle['wache_name'] ?? ''),
        'nebenleitstelle_id' => (int) ($vehicle['nebenleitstelle_id'] ?? 0),
        'nebenleitstelle_name' => (string) ($vehicle['nebenleitstelle_name'] ?? ''),
        'rufname' => (string) ($vehicle['rufname'] ?? ''),
        'fahrzeugtyp' => (string) ($vehicle['fahrzeugtyp'] ?? ''),
        'resource_class' => (string) ($vehicle['resource_class'] ?? ''),
        'resource_class_label' => (string) ($vehicle['resource_class_label'] ?? ''),
        'latitude' => $vehicle['latitude'],
        'longitude' => $vehicle['longitude'],
        'home_latitude' => $vehicle['wache_latitude'] ?? $vehicle['latitude'],
        'home_longitude' => $vehicle['wache_longitude'] ?? $vehicle['longitude'],
        'image_url' => (string) ($vehicle['image_url'] ?? ''),
        'signal_lights' => is_array($vehicle['signal_lights'] ?? null) ? $vehicle['signal_lights'] : [],
        'available' => !empty($vehicle['available']),
        'availability_state' => (string) ($vehicle['availability_state'] ?? 'nicht dienstbereit'),
        'load_profile' => is_array($vehicle['load_profile'] ?? null) ? $vehicle['load_profile'] : [],
        'weather_primary' => (string) ($vehicle['weather_primary'] ?? ''),
        'weather_tags' => is_array($vehicle['weather_tags'] ?? null) ? $vehicle['weather_tags'] : [],
    ];
}

function lsttraining_sim_neighbor_inside_instance_area(PDO $pdo, int $instanz_id, array $position, float $progress): bool {
    if ($progress >= 0.35) {
        return true;
    }
    if (!is_numeric($position['latitude'] ?? null) || !is_numeric($position['longitude'] ?? null)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT leitstelle_id FROM spielinstanzen WHERE id = ? LIMIT 1');
        $stmt->execute([$instanz_id]);
        $leitstelle_id = (int) $stmt->fetchColumn();
        if ($leitstelle_id <= 0) {
            return false;
        }
        $area = lsttraining_sim_load_area($pdo, $leitstelle_id);
        return lsttraining_sim_point_inside_area([
            (float) $position['longitude'],
            (float) $position['latitude'],
        ], $area);
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_sim_fetch_bootstrap(PDO $pdo, int $instanz_id, int $user_id): array {
    $instance = lsttraining_sim_fetch_instance_context($pdo, $instanz_id, $user_id);

    return [
        'schema_version' => 2,
        'instance' => $instance,
        'preferences' => [
            'vehicle_marker_mode' => lsttraining_sim_vehicle_marker_mode($user_id),
        ],
        'stations' => lsttraining_sim_fetch_bootstrap_stations($pdo, (int) $instance['leitstelle_id']),
        'neighbor_dispatch_centers' => lsttraining_sim_fetch_neighbor_dispatch_centers($pdo, (int) $instance['leitstelle_id']),
        'neighbor_stations' => lsttraining_sim_fetch_neighbor_stations($pdo, (int) $instance['leitstelle_id']),
        'base_vehicles' => lsttraining_sim_fetch_bootstrap_vehicles($pdo, $instanz_id, (int) $instance['leitstelle_id']),
        'weather_current' => $instance['weather_current'],
        'weather_forecast_summary' => $instance['weather_forecast_summary'],
    ];
}

function lsttraining_sim_fetch_snapshot(PDO $pdo, int $instanz_id, int $user_id): array {
    // Live-Snapshot: nur Simulations-Deltas und berechnete Anzeigezustände, keine stationären Stammdaten.
    $instance = lsttraining_sim_fetch_instance_context($pdo, $instanz_id, $user_id);
    $simulation_paused = lsttraining_sim_instance_is_paused($instance);
    $sim_now_ts = (int) ($instance['sim_timestamp'] ?? time());
    $sim_now = (string) ($instance['sim_now'] ?? wp_date('Y-m-d H:i:s', $sim_now_ts));

    if (!$simulation_paused) {
        lsttraining_sim_advance_vehicle_movements($pdo, $instanz_id, $sim_now_ts);
    }

    $fahrzeuge_columns = lsttraining_sim_workspace_table_columns($pdo, 'fahrzeuge');
    $signal_lights_select = !empty($fahrzeuge_columns['signal_lights_json'])
        ? 'f.signal_lights_json'
        : 'NULL AS signal_lights_json';
    $vehicle_stmt = $pdo->prepare('
        SELECT
            fs.id AS status_id,
            fs.instanz_id,
            fs.fahrzeug_id,
            fs.wache_id,
            CASE WHEN ifs.id IS NULL THEN fs.latitude ELSE ifs.latitude END AS latitude,
            CASE WHEN ifs.id IS NULL THEN fs.longitude ELSE ifs.longitude END AS longitude,
            CASE WHEN ifs.id IS NULL THEN fs.ziel_latitude ELSE ifs.ziel_latitude END AS ziel_latitude,
            CASE WHEN ifs.id IS NULL THEN fs.ziel_longitude ELSE ifs.ziel_longitude END AS ziel_longitude,
            CASE WHEN ifs.id IS NULL THEN fs.status ELSE ifs.status END AS status,
            CASE WHEN ifs.id IS NULL THEN fs.fms_status ELSE ifs.fms_status END AS fms_status,
            CASE WHEN ifs.id IS NULL THEN fs.sondersignal ELSE ifs.sondersignal END AS sondersignal,
            CASE WHEN ifs.id IS NULL THEN fs.bemerkung ELSE ifs.bemerkung END AS bemerkung,
            CASE WHEN ifs.id IS NULL THEN fs.letzte_aktualisierung ELSE ifs.letzte_aktualisierung END AS letzte_aktualisierung,
            ifs.id AS delta_id,
            f.rufname,
            f.fahrzeugtyp,
            f.bild_datei,
            ' . $signal_lights_select . ',
            COALESCE(fs.latitude, f.latitude, w.latitude) AS base_latitude,
            COALESCE(fs.longitude, f.longitude, w.longitude) AS base_longitude,
            w.name AS wache_name,
            w.latitude AS wache_latitude,
            w.longitude AS wache_longitude
        FROM fahrzeug_status fs
        LEFT JOIN instanz_fahrzeug_status ifs
          ON ifs.instanz_id = fs.instanz_id
         AND ifs.fahrzeug_status_id = fs.id
        LEFT JOIN fahrzeuge f ON f.id = fs.fahrzeug_id
        LEFT JOIN wachen w ON w.id = fs.wache_id
        WHERE fs.instanz_id = ?
        ORDER BY f.rufname, fs.id
    ');
    $vehicle_stmt->execute([$instanz_id]);
    $vehicles = $vehicle_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vehicles as &$vehicle) {
        $vehicle['latitude'] = $vehicle['latitude'] !== null ? (float) $vehicle['latitude'] : null;
        $vehicle['longitude'] = $vehicle['longitude'] !== null ? (float) $vehicle['longitude'] : null;
        $vehicle['ziel_latitude'] = $vehicle['ziel_latitude'] !== null ? (float) $vehicle['ziel_latitude'] : null;
        $vehicle['ziel_longitude'] = $vehicle['ziel_longitude'] !== null ? (float) $vehicle['ziel_longitude'] : null;
        $vehicle['base_latitude'] = $vehicle['base_latitude'] !== null ? (float) $vehicle['base_latitude'] : null;
        $vehicle['base_longitude'] = $vehicle['base_longitude'] !== null ? (float) $vehicle['base_longitude'] : null;
        $vehicle['wache_latitude'] = $vehicle['wache_latitude'] !== null ? (float) $vehicle['wache_latitude'] : null;
        $vehicle['wache_longitude'] = $vehicle['wache_longitude'] !== null ? (float) $vehicle['wache_longitude'] : null;
        $vehicle['sondersignal'] = (int) ($vehicle['sondersignal'] ?? 0);
        $vehicle['resource_class'] = lsttraining_sim_resource_class_from_type((string) ($vehicle['fahrzeugtyp'] ?? ''));
        $vehicle['resource_class_label'] = lsttraining_sim_resource_class_label((string) ($vehicle['resource_class'] ?? ''));
        lsttraining_sim_apply_vehicle_visual_defaults($pdo, (int) ($instance['leitstelle_id'] ?? 0), $vehicle);
        unset($vehicle['signal_lights_json']);
    }
    unset($vehicle);
    $vehicles_by_status = [];
    $vehicles_by_fahrzeug = [];
    foreach ($vehicles as $vehicle) {
        $vehicles_by_status[(int) ($vehicle['status_id'] ?? 0)] = $vehicle;
        $vehicles_by_fahrzeug[(int) ($vehicle['fahrzeug_id'] ?? 0)] = $vehicle;
    }

    $incident_stmt = $pdo->prepare("
        SELECT
            ie.id,
            ie.instanz_id,
            ie.leitstelle_id,
            ie.source,
            ie.source_id,
            ie.einsatzart,
            ie.einsatztyp,
            ie.weather,
            ie.uhrzeit_fenster,
            ie.latitude,
            ie.longitude,
            ie.poi_type,
            ie.poi_name_snapshot,
            ie.caller_text,
            ie.lagemeldung,
            ie.state,
            ie.meta_json,
            ie.created_at,
            ie.updated_at,
            l.name AS leitstelle_name,
            e.title AS template_title
        FROM instanz_einsaetze ie
        LEFT JOIN leitstellen l ON l.id = ie.leitstelle_id
        LEFT JOIN einsaetze e ON ie.source = 'template' AND e.id = ie.source_id
        WHERE ie.instanz_id = ? AND ie.state IN ('new', 'active')
        ORDER BY ie.created_at DESC, ie.id DESC
        LIMIT 80
    ");
    $incident_stmt->execute([$instanz_id]);
    $incidents = $incident_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($incidents as &$incident) {
        $meta = json_decode((string) ($incident['meta_json'] ?? ''), true);
        $incident['meta'] = is_array($meta) ? $meta : [];
        unset($incident['meta_json']);
        $incident['latitude'] = (float) $incident['latitude'];
        $incident['longitude'] = (float) $incident['longitude'];
        $incident['sim_created_at'] = (string) ($incident['meta']['sim_time'] ?? ($incident['created_at'] ?? ''));
        $incident['title'] = trim((string) ($incident['template_title'] ?? '')) !== ''
            ? (string) $incident['template_title']
            : trim((string) (($incident['einsatzart'] ?? '') . ' - ' . ($incident['einsatztyp'] ?? 'Einsatz')));
        $incident['description'] = (string) ($incident['template_description'] ?? '');
        unset($incident['template_title']);
        unset($incident['template_description']);
        $incident = lsttraining_sim_enrich_incident_motorway_meta($pdo, $incident);
        $incident['display_address'] = lsttraining_sim_display_address_for_incident($incident);
        $incident['motorway_location_needs_repair'] = lsttraining_sim_incident_needs_motorway_repair($incident);
        $incident['call_status'] = (string) ($incident['meta']['call_status'] ?? ($incident['state'] === 'active' ? 'accepted' : 'ringing'));
        $incident['disposition_status'] = (string) ($incident['meta']['disposition_status'] ?? '');
        $incident['polizei_verstaendigen'] = !empty($incident['meta']['polizei_verstaendigen']);
        $incident['caller_text'] = lsttraining_sim_clean_caller_text_for_display((string) ($incident['caller_text'] ?? ''), $incident);
    }
    unset($incident);
    $incidents_by_id = [];
    foreach ($incidents as $incident) {
        $incidents_by_id[(int) ($incident['id'] ?? 0)] = $incident;
    }

    $event_stmt = $pdo->prepare('
        SELECT
            ev.id,
            ev.instanz_einsatz_id,
            ev.ts,
            ev.kind,
            ev.text,
            ev.meta_json,
            ie.einsatztyp,
            ie.poi_name_snapshot,
            ie.caller_text,
            ie.meta_json AS einsatz_meta_json,
            l.name AS leitstelle_name
        FROM instanz_einsatz_events ev
        INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
        LEFT JOIN leitstellen l ON l.id = ie.leitstelle_id
        WHERE ie.instanz_id = ?
        ORDER BY ev.ts DESC, ev.id DESC
        LIMIT 160
    ');
    $event_stmt->execute([$instanz_id]);
    $events = $event_stmt->fetchAll(PDO::FETCH_ASSOC);
    $next_radio_refresh_ts = 0;
    foreach ($events as &$event) {
        $meta = json_decode((string) ($event['meta_json'] ?? ''), true);
        $event['meta'] = is_array($meta) ? $meta : [];
        $einsatz_meta = json_decode((string) ($event['einsatz_meta_json'] ?? ''), true);
        $event['einsatz_meta'] = is_array($einsatz_meta) ? $einsatz_meta : [];
        unset($event['meta_json']);
        unset($event['einsatz_meta_json']);
        $visible_ts = lsttraining_sim_event_radio_visible_timestamp($event);
        if ($visible_ts > $sim_now_ts && ($next_radio_refresh_ts <= 0 || $visible_ts < $next_radio_refresh_ts)) {
            $next_radio_refresh_ts = $visible_ts;
        }
    }
    unset($event);
    $events = array_values(array_filter($events, static function (array $event) use ($sim_now_ts): bool {
        return lsttraining_sim_event_radio_visible($event, $sim_now_ts);
    }));
    foreach ($events as &$event) {
        $event['ts'] = lsttraining_sim_event_radio_time($event);
    }
    unset($event);
    usort($events, static function (array $a, array $b): int {
        $time_compare = strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
        if ($time_compare !== 0) {
            return $time_compare;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    $events = array_slice($events, 0, 80);

    $pending_stmt = $pdo->prepare('
        SELECT ev.id, ev.instanz_einsatz_id, ev.ts, ev.text, ev.meta_json
        FROM instanz_einsatz_events ev
        INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
        WHERE ie.instanz_id = ? AND ev.kind = ?
        ORDER BY ev.ts ASC, ev.id ASC
        LIMIT 500
    ');
    $pending_stmt->execute([$instanz_id, 'unit_report']);
    $pending_reports_by_incident = [];
    $radio_requests = [];
    foreach (($pending_stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $event) {
        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (!lsttraining_sim_event_radio_visible([
            'ts' => (string) ($event['ts'] ?? ''),
            'meta' => $meta,
        ], $sim_now_ts)) {
            continue;
        }
        if (($meta['event_type'] ?? '') !== 'situation_report' || empty($meta['requires_ack']) || !empty($meta['acknowledged_at'])) {
            continue;
        }
        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        if ($einsatz_id <= 0) {
            continue;
        }
        $opened_at = (string) ($meta['opened_at'] ?? '');
        $event_ts = lsttraining_sim_event_radio_time([
            'ts' => (string) ($event['ts'] ?? ''),
            'meta' => $meta,
        ]);
        $visible_text = (string) ($event['text'] ?? '');
        if ($opened_at !== '' && trim($visible_text) === 'Sprechwunsch' && trim((string) ($meta['report_text'] ?? '')) !== '') {
            $visible_text = (string) $meta['report_text'];
        }
        $radio_request = [
            'event_id' => (int) ($event['id'] ?? 0),
            'einsatz_id' => $einsatz_id,
            'ts' => $event_ts,
            'text' => $visible_text,
            'status_id' => (int) ($meta['status_id'] ?? 0),
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => (string) ($meta['rufname'] ?? ''),
            'fms_status' => (string) ($meta['fms_status'] ?? '5'),
            'followup_id' => (int) ($meta['followup_id'] ?? 0),
            'followup_label' => (string) ($meta['followup_label'] ?? ''),
            'opened_at' => $opened_at,
            'requires_ack' => true,
        ];
        $radio_requests[] = $radio_request;
        $pending_reports_by_incident[$einsatz_id] = $pending_reports_by_incident[$einsatz_id] ?? [];
        $pending_reports_by_incident[$einsatz_id][] = $radio_request;
        if (empty($radio_request['opened_at'])) {
            continue;
        }
    }

    $assignment_stmt = $pdo->prepare('
        SELECT
            ev.id,
            ev.instanz_einsatz_id,
            ev.ts,
            ev.text,
            ev.meta_json
        FROM instanz_einsatz_events ev
        INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
        WHERE ie.instanz_id = ? AND ev.kind = ?
        ORDER BY ev.id DESC
        LIMIT 800
    ');
    $assignment_stmt->execute([$instanz_id, 'unit_report']);
    $assignment_events = $assignment_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $first_arrival_report_opened_by_incident = [];
    foreach ($assignment_events as $event) {
        $meta = json_decode((string) ($event['meta_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        if (($meta['event_type'] ?? '') !== 'situation_report' || empty($meta['opened_at'])) {
            continue;
        }
        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        if ($einsatz_id <= 0 || !isset($incidents_by_id[$einsatz_id])) {
            continue;
        }
        $incident_meta = is_array($incidents_by_id[$einsatz_id]['meta'] ?? null) ? $incidents_by_id[$einsatz_id]['meta'] : [];
        $first_status_id = (int) ($incident_meta['arrival_report_status_id'] ?? 0);
        if ($first_status_id > 0 && (int) ($meta['status_id'] ?? 0) !== $first_status_id) {
            continue;
        }
        $first_arrival_report_opened_by_incident[$einsatz_id] = true;
    }

    $assignments = [];
    $now = $sim_now_ts;
    foreach ($assignment_events as $event) {
        $meta = json_decode((string) ($event['meta_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $event_type = (string) ($meta['event_type'] ?? '');
        $is_support = $event_type === 'support_vehicle_alarm';
        if ($event_type !== 'vehicle_alarm' && !$is_support) {
            continue;
        }
        if (!empty($meta['cancelled_at'])) {
            continue;
        }
        $mission_phase = (string) ($meta['mission_phase'] ?? '');
        if ($mission_phase === 'available') {
            continue;
        }

        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        if ($einsatz_id <= 0 || !isset($incidents_by_id[$einsatz_id])) {
            continue;
        }

        $alarmiert_at = (string) ($meta['alarmiert_at'] ?? '');
        $ausrueckzeit = (int) ($meta['ausrueckzeit_sec'] ?? ($is_support ? 0 : 60));
        $started_at = (string) ($meta['movement_started_at'] ?? '');
        $arrived_at = (string) ($meta['arrived_at'] ?? '');
        $progress = (float) ($meta['current_progress'] ?? 0);
        $assignment_status = 'alarmiert';
        if ($mission_phase === 'to_hospital') {
            $assignment_status = 'transport';
        } elseif ($mission_phase === 'handover') {
            $assignment_status = 'uebergabe';
        } elseif ($mission_phase === 'returning') {
            $assignment_status = 'rueckfahrt';
        } elseif ($arrived_at !== '') {
            $assignment_status = 'vor_ort';
        } elseif ($started_at !== '' || $progress > 0) {
            $assignment_status = 'anfahrt';
        } elseif (($alarm_ts = lsttraining_sim_parse_wp_time($alarmiert_at)) > 0 && $now < ($alarm_ts + max(0, $ausrueckzeit))) {
            $assignment_status = 'ausrueckend';
        }

        $support_type = $is_support ? (string) ($meta['support_type'] ?? '') : '';
        $support_key = $is_support ? ('support-' . ($support_type !== '' ? $support_type : 'unit') . '-' . (int) ($event['id'] ?? 0)) : '';
        $assignments[] = [
            'event_id' => (int) ($event['id'] ?? 0),
            'einsatz_id' => $einsatz_id,
            'status_id' => $is_support ? 0 : (int) ($meta['status_id'] ?? 0),
            'fahrzeug_id' => $is_support ? (int) ($meta['fahrzeug_id'] ?? 0) : (int) ($meta['fahrzeug_id'] ?? 0),
            'unit_key' => $support_key,
            'support_type' => $support_type,
            'is_support' => $is_support,
            'foreign_unit' => !empty($meta['foreign_unit']),
            'home_nebenleitstelle_id' => (int) ($meta['home_nebenleitstelle_id'] ?? 0),
            'home_nebenleitstelle_name' => (string) ($meta['home_nebenleitstelle_name'] ?? ''),
            'home_wache_id' => (int) ($meta['home_wache_id'] ?? 0),
            'home_wache_name' => (string) ($meta['home_wache_name'] ?? ''),
            'contact_established' => !empty($meta['contact_established_at']) || !empty($meta['entry_speak_request_event_id']),
            'rufname' => (string) ($meta['rufname'] ?? ($is_support ? 'Polizei' : '')),
            'fahrzeugtyp' => (string) ($meta['fahrzeugtyp'] ?? ''),
            'alarmiert_at' => $alarmiert_at,
            'ausrueckzeit_sec' => $ausrueckzeit,
            'movement_started_at' => $started_at,
            'arrived_at' => $arrived_at,
            'current_progress' => $progress,
            'mission_phase' => $mission_phase !== '' ? $mission_phase : ($arrived_at !== '' ? 'at_scene' : 'to_scene'),
            'transport_patient_id' => (string) ($meta['transport_patient_id'] ?? ''),
            'transport_status' => (string) ($meta['transport_status'] ?? ''),
            'transport_hospital_id' => (int) ($meta['transport_hospital_id'] ?? 0),
            'transport_hospital_name' => (string) ($meta['transport_hospital_name'] ?? ''),
            'transport_department' => (string) ($meta['transport_department'] ?? ''),
            'transport_triage_category' => (string) ($meta['transport_triage_category'] ?? ''),
            'handover_duration_sec' => (int) ($meta['handover_duration_sec'] ?? 0),
            'handover_release_at' => (string) ($meta['handover_release_at'] ?? ''),
            'assignment_status' => $assignment_status,
            'route_distance_m' => (int) ($meta['route_distance_m'] ?? 0),
            'route_duration_sec' => (int) ($meta['route_duration_sec'] ?? 0),
            'route_status' => (string) ($meta['route_status'] ?? (is_array($meta['route_coordinates'] ?? null) && count($meta['route_coordinates']) >= 2 ? 'ready' : '')),
            'route_error_code' => (string) ($meta['route_error_code'] ?? ''),
            'route_error_message' => (string) ($meta['route_error_message'] ?? ''),
            'route_error_detail' => (string) ($meta['route_error_detail'] ?? ''),
            'route_coordinates' => is_array($meta['route_coordinates'] ?? null) ? $meta['route_coordinates'] : [],
            'route_segments' => lsttraining_sim_normalize_route_segments($meta['route_segments'] ?? []),
            'last_position' => is_array($meta['last_position'] ?? null) ? $meta['last_position'] : null,
            'sondersignal_allowed' => !empty($meta['sondersignal_allowed']),
            'sondersignal' => !empty($meta['sondersignal']),
            'current_segment_index' => (int) ($meta['current_segment_index'] ?? 0),
            'current_segment_progress' => (float) ($meta['current_segment_progress'] ?? 0),
            'resource_class' => (string) ($meta['resource_class'] ?? ''),
            'resource_class_label' => (string) ($meta['resource_class_label'] ?? ''),
            'image_url' => (string) ($meta['image_url'] ?? ''),
            'signal_lights' => is_array($meta['signal_lights'] ?? null) ? $meta['signal_lights'] : [],
            'fms_status' => (string) ($meta['fms_status'] ?? ($is_support ? ($assignment_status === 'vor_ort' ? '4' : '3') : '')),
        ];
    }

    foreach ($assignments as &$assignment) {
        if (!empty($assignment['is_support'])) {
            if ((string) ($assignment['resource_class_label'] ?? '') === '') {
                $assignment['resource_class_label'] = (string) ($assignment['support_type'] ?? '') === 'police' ? 'Polizei' : 'Unterstützung';
            }
            if ((string) ($assignment['support_type'] ?? '') === 'police') {
                $support_leitstelle_id = (int) ($incidents_by_id[(int) ($assignment['einsatz_id'] ?? 0)]['leitstelle_id'] ?? 0);
                if ((string) ($assignment['image_url'] ?? '') === '') {
                    $assignment['image_url'] = lsttraining_sim_police_vehicle_image_url($pdo, $support_leitstelle_id);
                }
                $police_signal_raw = lsttraining_sim_police_signal_lights_json($pdo, $support_leitstelle_id);
                if (lsttraining_sim_signal_lights_raw_has_lights($police_signal_raw)) {
                    $assignment['signal_lights'] = lsttraining_sim_signal_lights_for_vehicle($police_signal_raw, 'Streifenwagen', (string) ($assignment['rufname'] ?? 'Polizei'));
                }
            }
            if (!is_array($assignment['signal_lights'] ?? null) || !$assignment['signal_lights']) {
                $assignment['signal_lights'] = lsttraining_sim_signal_lights_for_vehicle(
                    lsttraining_sim_police_signal_lights_json($pdo, (int) ($incidents_by_id[(int) ($assignment['einsatz_id'] ?? 0)]['leitstelle_id'] ?? 0)),
                    'Streifenwagen',
                    (string) ($assignment['rufname'] ?? 'Polizei')
                );
            }
            continue;
        }
        $vehicle = $vehicles_by_status[(int) ($assignment['status_id'] ?? 0)]
            ?? ($vehicles_by_fahrzeug[(int) ($assignment['fahrzeug_id'] ?? 0)] ?? null);
        $assignment['resource_class'] = is_array($vehicle) ? (string) ($vehicle['resource_class'] ?? '') : (string) ($assignment['resource_class'] ?? '');
        $assignment['resource_class_label'] = $assignment['resource_class'] !== ''
            ? lsttraining_sim_resource_class_label((string) $assignment['resource_class'])
            : '';
        if (is_array($vehicle)) {
            $assignment['fahrzeugtyp'] = (string) ($vehicle['fahrzeugtyp'] ?? ($assignment['fahrzeugtyp'] ?? ''));
            $assignment['fms_status'] = (string) ($vehicle['fms_status'] ?? '');
            if (!is_array($assignment['last_position'] ?? null) && $vehicle['latitude'] !== null && $vehicle['longitude'] !== null) {
                $assignment['last_position'] = [
                    'latitude' => (float) $vehicle['latitude'],
                    'longitude' => (float) $vehicle['longitude'],
                ];
            }
        }
    }
    unset($assignment);

    $active_assignment_status_ids = [];
    foreach ($assignments as $assignment) {
        $status_id = (int) ($assignment['status_id'] ?? 0);
        if ($status_id > 0) {
            $active_assignment_status_ids[$status_id] = true;
        }
    }

    $vehicle_statuses = [];
    $live_vehicles = [];
    foreach ($vehicles as $vehicle) {
        $status_id = (int) ($vehicle['status_id'] ?? 0);
        $fms = (string) ($vehicle['fms_status'] ?? '');
        $status = (string) ($vehicle['status'] ?? '');
        $assigned = $status_id > 0 && !empty($active_assignment_status_ids[$status_id]);
        $special = !in_array($fms, ['1', '2', ''], true) || !empty($vehicle['sondersignal']);
        $has_target = $vehicle['ziel_latitude'] !== null || $vehicle['ziel_longitude'] !== null;
        $status_delta = !in_array($status, ['frei', 'einsatzbereit', ''], true);
        $dispatch_block_reason = '';
        if ($assigned) {
            $dispatch_block_reason = 'bereits zugeordnet';
        } elseif ($fms === '8') {
            $dispatch_block_reason = 'im Krankenhaus';
        } elseif ($fms === '7') {
            $dispatch_block_reason = 'Patiententransport';
        } elseif ($fms === '6') {
            $dispatch_block_reason = 'nicht verfügbar';
        } elseif ($fms === '5') {
            $dispatch_block_reason = 'Sprechwunsch';
        } elseif ($fms === '4') {
            $dispatch_block_reason = 'vor Ort';
        } elseif ($fms === '3') {
            $dispatch_block_reason = 'auf Anfahrt';
        } elseif ($status_delta) {
            $dispatch_block_reason = 'nicht frei';
        }
        $vehicle['dispatch_available'] = $dispatch_block_reason === '';
        $vehicle['dispatch_block_reason'] = $dispatch_block_reason;
        $outside_base = false;
        if (
            $vehicle['latitude'] !== null &&
            $vehicle['longitude'] !== null &&
            $vehicle['base_latitude'] !== null &&
            $vehicle['base_longitude'] !== null
        ) {
            $outside_base = lsttraining_sim_distance_m(
                (float) $vehicle['latitude'],
                (float) $vehicle['longitude'],
                (float) $vehicle['base_latitude'],
                (float) $vehicle['base_longitude']
            ) > 50;
        }
        $has_delta = !empty($vehicle['delta_id']);

        if ($has_delta) {
            $light = $vehicle;
            unset(
                $light['delta_id'],
                $light['latitude'],
                $light['longitude'],
                $light['base_latitude'],
                $light['base_longitude'],
                $light['wache_latitude'],
                $light['wache_longitude'],
                $light['bild_datei']
            );
            $vehicle_statuses[] = $light;
        }

        if ($has_delta && ($assigned || $special || $outside_base || $has_target)) {
            unset($vehicle['delta_id'], $vehicle['base_latitude'], $vehicle['base_longitude'], $vehicle['wache_latitude'], $vehicle['wache_longitude'], $vehicle['bild_datei']);
            $live_vehicles[] = $vehicle;
        }
    }

    foreach ($assignments as $assignment) {
        if (empty($assignment['is_support']) || !in_array((string) ($assignment['support_type'] ?? ''), ['police', 'neighbor'], true)) {
            continue;
        }
        if (
            (string) ($assignment['support_type'] ?? '') === 'police' &&
            (
                (string) ($assignment['mission_phase'] ?? '') === 'at_scene' ||
                (string) ($assignment['assignment_status'] ?? '') === 'vor_ort'
            )
        ) {
            continue;
        }
        $last_position = is_array($assignment['last_position'] ?? null) ? $assignment['last_position'] : null;
        if (!$last_position || !is_numeric($last_position['latitude'] ?? null) || !is_numeric($last_position['longitude'] ?? null)) {
            continue;
        }
        $is_neighbor_support = (string) ($assignment['support_type'] ?? '') === 'neighbor';
        $fahrzeugtyp = $is_neighbor_support ? (string) ($assignment['fahrzeugtyp'] ?? 'Fremdfahrzeug') : 'Streifenwagen';
        $resource_class = $is_neighbor_support ? (string) ($assignment['resource_class'] ?? '') : 'police';
        $resource_label = $is_neighbor_support
            ? ((string) ($assignment['resource_class_label'] ?? '') ?: 'Fremdfahrzeug')
            : 'Polizei';
        $support_vehicle = [
            'unit_key' => (string) ($assignment['unit_key'] ?? ''),
            'support_type' => (string) ($assignment['support_type'] ?? ''),
            'foreign_unit' => !empty($assignment['foreign_unit']),
            'home_nebenleitstelle_id' => (int) ($assignment['home_nebenleitstelle_id'] ?? 0),
            'home_nebenleitstelle_name' => (string) ($assignment['home_nebenleitstelle_name'] ?? ''),
            'home_wache_id' => (int) ($assignment['home_wache_id'] ?? 0),
            'home_wache_name' => (string) ($assignment['home_wache_name'] ?? ''),
            'status_id' => 0,
            'fahrzeug_id' => (int) ($assignment['fahrzeug_id'] ?? 0),
            'rufname' => (string) ($assignment['rufname'] ?? 'Polizei'),
            'fahrzeugtyp' => $fahrzeugtyp,
            'resource_class' => $resource_class,
            'resource_class_label' => $resource_label,
            'latitude' => (float) $last_position['latitude'],
            'longitude' => (float) $last_position['longitude'],
            'status' => (string) ($assignment['assignment_status'] ?? ''),
            'fms_status' => (string) ($assignment['fms_status'] ?? '3'),
            'sondersignal' => !empty($assignment['sondersignal']) ? 1 : 0,
            'bemerkung' => '',
            'image_url' => (string) (($assignment['image_url'] ?? '') ?: lsttraining_sim_police_vehicle_image_url($pdo, (int) ($incidents_by_id[(int) ($assignment['einsatz_id'] ?? 0)]['leitstelle_id'] ?? 0))),
            'signal_lights' => is_array($assignment['signal_lights'] ?? null) ? $assignment['signal_lights'] : [],
            'einsatz_id' => (int) ($assignment['einsatz_id'] ?? 0),
        ];
        $status_label = (string) ($assignment['assignment_status'] ?? 'alarmiert');
        if ($is_neighbor_support) {
            $home = trim((string) ($assignment['home_nebenleitstelle_name'] ?? ''));
            $support_vehicle['bemerkung'] = $status_label === 'rueckfahrt'
                ? 'Rückfahrt zur Heimatleitstelle' . ($home !== '' ? ' ' . $home : '') . '.'
                : ($status_label === 'vor_ort' ? 'Fremdfahrzeug vor Ort.' : 'Fremdfahrzeug auf Anfahrt.');
        } else {
            $support_vehicle['bemerkung'] = $status_label === 'vor_ort' ? 'Polizei vor Ort.' : 'Polizei auf Anfahrt.';
        }
        $live_vehicles[] = $support_vehicle;
        $vehicle_statuses[] = $support_vehicle;
    }

    $patient_update_stmt = $pdo->prepare('UPDATE instanz_einsaetze SET meta_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND instanz_id = ?');

    foreach ($incidents as &$incident) {
        $required = lsttraining_sim_normalize_required_resources($incident['meta']['required_resources'] ?? []);
        $fulfilled = [];
        $arrived_fulfilled = [];
        $incident_assignments = [];
        foreach ($assignments as $assignment) {
            if ((int) ($assignment['einsatz_id'] ?? 0) !== (int) ($incident['id'] ?? 0)) {
                continue;
            }
            $incident_assignments[] = $assignment;
            if ((string) ($assignment['assignment_status'] ?? '') === 'rueckfahrt') {
                continue;
            }
            $class = (string) ($assignment['resource_class'] ?? '');
            if ($class === '') {
                continue;
            }
            $fulfilled[$class] = ($fulfilled[$class] ?? 0) + 1;
            if ((string) ($assignment['assignment_status'] ?? '') === 'vor_ort') {
                $arrived_fulfilled[$class] = ($arrived_fulfilled[$class] ?? 0) + 1;
            }
        }

        $resource_status = lsttraining_sim_resource_status_with_substitution($required, $fulfilled);
        $arrived_resource_status = lsttraining_sim_resource_status_with_substitution($required, $arrived_fulfilled);
        $meta_for_patients = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
        $raw_patients = (string) ($incident['state'] ?? '') !== 'closed' && is_array($meta_for_patients['patients'] ?? null) ? $meta_for_patients['patients'] : [];
        $patient_dirty = false;
        $now_ts = $sim_now_ts;
        if (!$simulation_paused) {
            foreach ($raw_patients as &$patient) {
                if (!is_array($patient)) {
                    continue;
                }
                $normalized = lsttraining_sim_normalize_patients([$patient]);
                $normalized_patient = $normalized[0] ?? $patient;
                if (($normalized_patient['patient_status'] ?? '') === 'deceased') {
                    continue;
                }
                if (in_array((string) ($normalized_patient['transport_status'] ?? 'none'), ['to_hospital', 'handover', 'completed'], true)) {
                    continue;
                }
                if (lsttraining_sim_patient_waiting_resources($normalized_patient, $arrived_resource_status)) {
                    continue;
                }
                $progress = max(0, min(100, (int) ($normalized_patient['care_progress_percent'] ?? 0)));
                $target = max(1, min(100, (int) ($normalized_patient['care_target_percent'] ?? 100)));
                if ($progress >= $target) {
                    $patient['care_progress_percent'] = $progress;
                    $patient['transport_ready'] = true;
                    if (($patient['transport_status'] ?? '') === '') {
                        $patient['transport_status'] = 'ready';
                    }
                    $patient_dirty = true;
                    continue;
                }
                $last_ts = lsttraining_sim_parse_wp_time($patient['last_care_progress_at'] ?? '');
                if ($last_ts <= 0) {
                    $patient['last_care_progress_at'] = $sim_now;
                    $patient_dirty = true;
                    continue;
                }
                $steps = (int) floor(max(0, $now_ts - $last_ts) / 30);
                if ($steps <= 0) {
                    continue;
                }
                $progress = min($target, $progress + min(10, $steps * 5));
                $patient['care_progress_percent'] = $progress;
                $patient['last_care_progress_at'] = $sim_now;
                $patient['transport_ready'] = $progress >= $target;
                if ($patient['transport_ready'] && (($patient['transport_status'] ?? '') === '')) {
                    $patient['transport_status'] = 'ready';
                }
                $patient_dirty = true;
            }
            unset($patient);
            if (lsttraining_sim_transport_try_start($pdo, $instanz_id, $incident, $raw_patients, $incident_assignments, $sim_now_ts)) {
                $patient_dirty = true;
            }
        }
        if ($patient_dirty) {
            $meta_for_patients['patients'] = $raw_patients;
            $incident['meta'] = $meta_for_patients;
            $patient_update_stmt->execute([lsttraining_sim_encode_meta($meta_for_patients), (int) $incident['id'], $instanz_id]);
        }


        $incident['required_resources'] = $required;
        $incident['resource_status'] = $resource_status;
        $incident['patients'] = lsttraining_sim_patients_for_snapshot($incident, $resource_status, $arrived_resource_status);
        $incident['has_missing_resources'] = !empty(array_filter($resource_status, static function (array $row): bool {
            return (int) ($row['missing'] ?? 0) > 0;
        }));
        $counts = [
            'alarmiert' => 0,
            'ausrueckend' => 0,
            'anfahrt' => 0,
            'vor_ort' => 0,
            'transport' => 0,
            'uebergabe' => 0,
            'rueckfahrt' => 0,
        ];
        $feedback = [];
        foreach ($incident_assignments as $assignment) {
            $status = (string) ($assignment['assignment_status'] ?? 'alarmiert');
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
            $name = (string) ($assignment['rufname'] ?: ('Fahrzeug ' . ((int) ($assignment['status_id'] ?? 0))));
            if ($status === 'transport') {
                $feedback[] = $name . ' auf Klinikfahrt.';
            } elseif ($status === 'uebergabe') {
                $feedback[] = $name . ' bei der Klinikübergabe.';
            } elseif ($status === 'rueckfahrt') {
                $feedback[] = $name . ' auf Rückfahrt.';
            } elseif ($status === 'vor_ort') {
                $feedback[] = $name . ' vor Ort.';
            } elseif ($status === 'anfahrt') {
                $feedback[] = $name . ' auf Anfahrt.';
            } elseif ($status === 'ausrueckend') {
                $feedback[] = $name . ' rückt aus.';
            } else {
                $feedback[] = $name . ' alarmiert.';
            }
        }
        $operational_status = 'unassigned';
        $operational_label = 'Keine Fahrzeuge alarmiert';
        if ($counts['transport'] > 0) {
            $operational_status = 'transport';
            $operational_label = 'Klinikfahrt läuft';
        } elseif ($counts['uebergabe'] > 0) {
            $operational_status = 'uebergabe';
            $operational_label = 'Klinikübergabe';
        } elseif ($counts['vor_ort'] > 0) {
            $operational_status = 'vor_ort';
            $operational_label = 'Fahrzeuge vor Ort';
        } elseif ($counts['rueckfahrt'] > 0) {
            $operational_status = 'rueckfahrt';
            $operational_label = 'Fahrzeuge auf Rückfahrt';
        } elseif ($counts['anfahrt'] > 0) {
            $operational_status = 'anfahrt';
            $operational_label = 'Auf Anfahrt';
        } elseif ($counts['ausrueckend'] > 0) {
            $operational_status = 'ausrueckend';
            $operational_label = 'Fahrzeuge rücken aus';
        } elseif ($counts['alarmiert'] > 0) {
            $operational_status = 'alarmiert';
            $operational_label = 'Fahrzeuge alarmiert';
        }
        if (!empty($incident['has_missing_resources']) && $incident_assignments) {
            $operational_label .= ' - Ressourcen fehlen';
        }
        $incident['assigned_units'] = $incident_assignments;
        $incident['unit_status_counts'] = $counts;
        $incident['operational_status'] = $operational_status;
        $incident['operational_status_label'] = $operational_label;
        $incident['feedback'] = array_slice($feedback, 0, 5);
        $incident['pending_unit_reports'] = $pending_reports_by_incident[(int) ($incident['id'] ?? 0)] ?? [];
        $incident['first_arrival_report_opened'] = !empty($first_arrival_report_opened_by_incident[(int) ($incident['id'] ?? 0)]);
        $close_blockers = [];
        if ($incident_assignments) {
            $close_blockers[] = 'Dem Einsatz sind noch Fahrzeuge zugeordnet.';
        }
        if (!empty($incident['pending_unit_reports'])) {
            $close_blockers[] = 'Es gibt noch einen offenen Sprechwunsch.';
        }
        if (!empty($incident['has_missing_resources']) && !empty($incident['meta']['pending_resource_request'])) {
            $close_blockers[] = 'Erforderliche Ressourcen fehlen noch.';
        }
        foreach ((array) $incident['patients'] as $patient) {
            if (in_array((string) ($patient['transport_status'] ?? ''), ['ready', 'to_hospital', 'handover'], true)) {
                $close_blockers[] = 'Patiententransport ist noch nicht abgeschlossen.';
                break;
            }
        }
        $incident['close_blockers'] = array_values(array_unique($close_blockers));
        $incident['can_close'] = !$incident['close_blockers'];
    }
    unset($incident);

    $caller_answer_event_ids = [];
    foreach ($events as $event) {
        if ((string) ($event['kind'] ?? '') === 'caller_answer') {
            $caller_answer_event_ids[(int) ($event['instanz_einsatz_id'] ?? 0)] = true;
        }
    }

    $call_log = [];
    foreach ($incidents as $incident) {
        $call_status = (string) ($incident['call_status'] ?? 'ringing');
        $incident_ts = (string) ($incident['sim_created_at'] ?? $incident['created_at']);
        $call_log[] = [
            'ts' => $incident_ts,
            'kind' => 'new_call',
            'text' => 'Neuer Telefonanruf.',
            'einsatz_id' => (int) $incident['id'],
            'call_status' => $call_status,
            'can_accept' => $call_status !== 'accepted',
            'can_open_dispatch' => false,
            'can_close_without_dispatch' => false,
            'sort_order' => 0,
        ];

        if ($call_status === 'accepted' && !empty($incident['caller_text']) && empty($caller_answer_event_ids[(int) $incident['id']])) {
            $call_log[] = [
                'ts' => $incident_ts,
                'kind' => 'caller_answer',
                'text' => lsttraining_sim_clean_caller_text_for_display((string) $incident['caller_text'], $incident),
                'einsatz_id' => (int) $incident['id'],
                'can_open_dispatch' => (string) ($incident['disposition_status'] ?? '') !== 'prepared',
                'can_close_without_dispatch' => (string) ($incident['disposition_status'] ?? '') !== 'prepared',
                'sort_order' => 1,
            ];
        }
    }
    foreach ($events as $event) {
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        if (($event['kind'] ?? '') === 'system' && (($meta['source'] ?? '') === 'template')) {
            continue;
        }

        if (in_array((string) $event['kind'], ['dispatcher_question', 'caller_answer', 'system'], true)) {
            $event_incident = $incidents_by_id[(int) ($event['instanz_einsatz_id'] ?? 0)] ?? null;
            $event_context = is_array($event_incident) ? $event_incident : [
                'meta' => is_array($event['einsatz_meta'] ?? null) ? $event['einsatz_meta'] : [],
                'poi_name_snapshot' => (string) ($event['poi_name_snapshot'] ?? ''),
                'leitstelle_name' => (string) ($event['leitstelle_name'] ?? ''),
            ];
            $event_text = (string) ($event['text'] ?? '');
            if ((string) ($event['kind'] ?? '') === 'caller_answer') {
                $event_text = lsttraining_sim_clean_caller_text_for_display($event_text, $event_context);
            }
            $event_is_actionable_answer = (string) ($event['kind'] ?? '') === 'caller_answer'
                && is_array($event_incident)
                && (string) ($event_incident['call_status'] ?? '') === 'accepted'
                && (string) ($event_incident['disposition_status'] ?? '') !== 'prepared';
            $call_log[] = [
                'ts' => $event['ts'],
                'kind' => $event['kind'],
                'text' => $event_text,
                'einsatz_id' => (int) $event['instanz_einsatz_id'],
                'can_open_dispatch' => $event_is_actionable_answer,
                'can_close_without_dispatch' => $event_is_actionable_answer,
                'sort_order' => 2,
            ];
        }
    }
    foreach ($radio_requests as $request) {
        if (empty($request['opened_at'])) {
            continue;
        }
        $call_log[] = [
            'ts' => (string) ($request['ts'] ?? ''),
            'kind' => 'unit_report',
            'text' => (string) ($request['text'] ?? ''),
            'einsatz_id' => (int) ($request['einsatz_id'] ?? 0),
            'event_id' => (int) ($request['event_id'] ?? 0),
            'status_id' => (int) ($request['status_id'] ?? 0),
            'fahrzeug_id' => (int) ($request['fahrzeug_id'] ?? 0),
            'rufname' => (string) ($request['rufname'] ?? ''),
            'fms_status' => (string) ($request['fms_status'] ?? '5'),
            'can_ack_unit_report' => true,
            'sort_order' => 3,
        ];
    }
    usort($call_log, static function (array $a, array $b): int {
        $time_compare = strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
        if ($time_compare !== 0) {
            return $time_compare;
        }

        return ((int) ($a['sort_order'] ?? 9)) <=> ((int) ($b['sort_order'] ?? 9));
    });
    $call_log = array_slice($call_log, 0, 80);

    $fms_log = [];
    foreach ($events as $event) {
        $kind = (string) ($event['kind'] ?? '');
        if ($kind === 'unit_report') {
            continue;
        }
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $has_fms_meta = isset($meta['fms_status']) || isset($meta['fahrzeug_id']) || isset($meta['vehicle_id']);
        $is_fms_event = in_array($kind, ['fms', 'fms_status', 'vehicle_status', 'fahrzeug_status', 'radio', 'funk'], true);

        if (!$is_fms_event && !$has_fms_meta) {
            continue;
        }

        $text = trim((string) ($event['text'] ?? ''));
        if ($text === '' && isset($meta['rufname'], $meta['fms_status'])) {
            $text = (string) $meta['rufname'] . ': S' . (string) $meta['fms_status'];
        } elseif ($text === '' && isset($meta['fms_status'])) {
            $text = 'FMS: S' . (string) $meta['fms_status'];
        }

        if ($text === '') {
            continue;
        }

        $fms_log[] = [
            'ts' => $event['ts'],
            'direction' => (string) ($meta['direction'] ?? 'down'),
            'text' => $text,
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? ($meta['vehicle_id'] ?? 0)),
            'fms_status' => isset($meta['fms_status']) ? (string) $meta['fms_status'] : '',
        ];
    }
    usort($fms_log, static function (array $a, array $b): int {
        return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
    });
    $fms_log = array_slice($fms_log, 0, 80);

    $neighbor_support_requests = [];
    $support_stmt = $pdo->prepare('
        SELECT ev.id, ev.instanz_einsatz_id, ev.ts, ev.text, ev.meta_json
        FROM instanz_einsatz_events ev
        INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
        WHERE ie.instanz_id = ? AND ev.kind = ?
        ORDER BY ev.id DESC
        LIMIT 100
    ');
    $support_stmt->execute([$instanz_id, 'dispatcher_question']);
    foreach (($support_stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $event) {
        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'neighbor_support_request') {
            continue;
        }
        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        if ($einsatz_id <= 0 || !isset($incidents_by_id[$einsatz_id])) {
            continue;
        }
        $accepted_ids = is_array($meta['accepted_vehicle_ids'] ?? null)
            ? array_values(array_map('intval', $meta['accepted_vehicle_ids']))
            : [];
        $neighbor_support_requests[] = [
            'event_id' => (int) ($event['id'] ?? 0),
            'einsatz_id' => $einsatz_id,
            'ts' => (string) ($event['ts'] ?? ''),
            'text' => (string) ($event['text'] ?? ''),
            'nebenleitstelle_id' => (int) ($meta['nebenleitstelle_id'] ?? 0),
            'nebenleitstelle_name' => (string) ($meta['nebenleitstelle_name'] ?? ''),
            'offer' => is_array($meta['offer'] ?? null) ? $meta['offer'] : [],
            'offer_generated_at' => (string) ($meta['offer_generated_at'] ?? ($meta['requested_at'] ?? '')),
            'offer_session_id' => (string) ($meta['offer_session_id'] ?? ''),
            'weather_primary' => (string) ($meta['weather_primary'] ?? ''),
            'weather_tags' => is_array($meta['weather_tags'] ?? null) ? $meta['weather_tags'] : [],
            'load_profile' => is_array($meta['load_profile'] ?? null) ? $meta['load_profile'] : [],
            'accepted_vehicle_ids' => $accepted_ids,
            'available_count' => (int) ($meta['available_count'] ?? 0),
            'total_count' => (int) ($meta['total_count'] ?? 0),
        ];
    }

    return [
        'schema_version' => 2,
        'sim_now' => $sim_now,
        'sim_timestamp' => $sim_now_ts,
        'next_radio_refresh_at' => $next_radio_refresh_ts > 0 ? lsttraining_sim_time_string($next_radio_refresh_ts) : '',
        'speed' => (int) ($instance['speed'] ?? 1),
        'paused' => (bool) ($instance['paused'] ?? false),
        'weather_current' => $instance['weather_current'],
        'weather_forecast_summary' => $instance['weather_forecast_summary'],
        'weather_next_change_at' => is_array($instance['weather_forecast_summary']['next_change'] ?? null)
            ? (string) ($instance['weather_forecast_summary']['next_change']['time'] ?? '')
            : '',
        'vehicles' => $live_vehicles,
        'vehicle_statuses' => $vehicle_statuses,
        'incidents' => $incidents,
        'events' => $events,
        'assignments' => $assignments,
        'neighbor_vehicle_availability' => lsttraining_sim_fetch_neighbor_vehicle_availability($pdo, $instanz_id, (int) ($instance['leitstelle_id'] ?? 0), $sim_now_ts),
        'neighbor_support_requests' => $neighbor_support_requests,
        'radio_requests' => $radio_requests,
        'fms_log' => $fms_log,
        'call_log' => $call_log,
    ];
}

function lsttraining_sim_repair_motorway_location_for_incident(PDO $pdo, array $incident): array {
    $meta = is_array($incident['meta'] ?? null) ? $incident['meta'] : [];
    $lat = isset($incident['latitude']) ? (float) $incident['latitude'] : 0.0;
    $lon = isset($incident['longitude']) ? (float) $incident['longitude'] : 0.0;
    $leitstelle_id = (int) ($incident['leitstelle_id'] ?? 0);
    if ($leitstelle_id <= 0 || $lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
        return ['repaired' => false, 'message' => 'Einsatzkoordinate fehlt.'];
    }

    $cache_key = 'lst_motorway_repair_' . md5($leitstelle_id . ':' . round($lat, 5) . ':' . round($lon, 5));
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['motorway_location_label']) && lsttraining_sim_spawn_motorway_label_is_complete((string) $cached['motorway_location_label'])) {
        $context = $cached;
    } else {
        $area = lsttraining_sim_load_area($pdo, $leitstelle_id);
        $loc = ['latitude' => $lat, 'longitude' => $lon];
        $tileState = lsttraining_sim_road_tile_state($pdo, $leitstelle_id);
        if (empty($tileState['complete'])) {
            return ['repaired' => false, 'message' => lsttraining_sim_road_tile_error($tileState)];
        }

        $road = lsttraining_sim_find_nearest_motorway_road($pdo, $leitstelle_id, $loc, $area, 80000);
        if (!$road) {
            return ['repaired' => false, 'message' => 'Keine nummerierte Autobahn in der Nähe gefunden.'];
        }

        $road['address_city'] = lsttraining_sim_incident_meta_value($meta, 'address_city');
        $road['address_suburb'] = lsttraining_sim_incident_meta_value($meta, 'address_suburb');

        $context = lsttraining_sim_spawn_motorway_location_context($road, $area);
        if (empty($context['motorway_location_label']) || !lsttraining_sim_spawn_motorway_label_is_complete((string) $context['motorway_location_label'])) {
            return ['repaired' => false, 'message' => 'Autobahnnummer, Richtung oder Ortsbezug konnte nicht sicher ermittelt werden.'];
        }

        foreach (['road_name', 'road_ref', 'road_highway', 'road_destination', 'road_destination_forward', 'road_destination_backward', 'road_junction_ref', 'road_exit_to', 'road_bearing_deg'] as $key) {
            if (isset($road[$key]) && trim((string) $road[$key]) !== '') {
                $context[$key] = $road[$key];
            }
        }
        $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        set_transient($cache_key, $context, $ttl);
    }

    foreach ($context as $key => $value) {
        $meta[$key] = $value;
        if (is_array($meta['caller'] ?? null)) {
            $meta['caller'][$key] = $value;
        }
    }
    $label = (string) ($context['motorway_location_label'] ?? '');
    $meta['generated_address'] = $label;
    if (is_array($meta['caller'] ?? null)) {
        $meta['caller']['generated_address'] = $label;
    }
    $meta['motorway_repaired_at'] = wp_date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('UPDATE instanz_einsaetze SET meta_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND instanz_id = ?');
    $stmt->execute([lsttraining_sim_encode_meta($meta), (int) ($incident['id'] ?? 0), (int) ($incident['instanz_id'] ?? 0)]);

    return [
        'repaired' => true,
        'display_address' => $label,
        'meta' => $context,
        'full_meta' => $meta,
    ];
}

add_action('wp_ajax_lsttraining_sim_get_snapshot', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        wp_send_json_success(lsttraining_sim_fetch_snapshot($pdo, $instanz_id, (int) get_current_user_id()));
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_snapshot] ' . $e->getMessage());
        $legacy_model = strpos($e->getMessage(), 'altes Fahrzeugstatusmodell') !== false;
        $message = $legacy_model
            ? $e->getMessage()
            : (current_user_can('manage_options')
            ? 'Snapshot konnte nicht geladen werden: ' . $e->getMessage()
            : 'Snapshot konnte nicht geladen werden.');
        wp_send_json_error(['message' => $message], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_repair_motorway_location', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;
    if ($instanz_id <= 0 || $einsatz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz oder Einsatz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $stmt = $pdo->prepare('
            SELECT
                ie.id,
                ie.instanz_id,
                ie.leitstelle_id,
                ie.latitude,
                ie.longitude,
                ie.caller_text,
                ie.meta_json,
                l.name AS leitstelle_name
            FROM instanz_einsaetze ie
            LEFT JOIN leitstellen l ON l.id = ie.leitstelle_id
            WHERE ie.id = ? AND ie.instanz_id = ?
            LIMIT 1
        ');
        $stmt->execute([$einsatz_id, $instanz_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            wp_send_json_error(['message' => 'Einsatz nicht gefunden.'], 404);
        }

        $incident['meta'] = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
        unset($incident['meta_json']);
        $incident['latitude'] = (float) ($incident['latitude'] ?? 0.0);
        $incident['longitude'] = (float) ($incident['longitude'] ?? 0.0);

        if (!lsttraining_sim_incident_needs_motorway_repair($incident)) {
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
            wp_send_json_success([
                'repaired' => false,
                'display_address' => lsttraining_sim_display_address_for_incident($incident),
            ]);
        }

        $result = lsttraining_sim_repair_motorway_location_for_incident($pdo, $incident);
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success($result);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_repair_motorway_location] ' . $e->getMessage());
        $message = current_user_can('manage_options')
            ? 'Autobahn-Ortsangabe konnte nicht repariert werden: ' . $e->getMessage()
            : 'Autobahn-Ortsangabe konnte nicht repariert werden.';
        wp_send_json_error(['message' => $message], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_get_bootstrap', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $bootstrap = lsttraining_sim_fetch_bootstrap($pdo, $instanz_id, (int) get_current_user_id());
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success($bootstrap);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_bootstrap] ' . $e->getMessage());
        $legacy_model = strpos($e->getMessage(), 'altes Fahrzeugstatusmodell') !== false;
        $message = $legacy_model
            ? $e->getMessage()
            : (current_user_can('manage_options')
            ? 'Simulationsbasis konnte nicht geladen werden: ' . $e->getMessage()
            : 'Simulationsbasis konnte nicht geladen werden.');
        wp_send_json_error(['message' => $message], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_request_neighbor_support', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;
    $nebenleitstelle_id = isset($_POST['nebenleitstelle_id']) ? absint($_POST['nebenleitstelle_id']) : 0;
    if ($instanz_id <= 0 || $einsatz_id <= 0 || $nebenleitstelle_id <= 0) {
        wp_send_json_error(['message' => 'Unterstützungsanfrage unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $incident_stmt = $pdo->prepare('
            SELECT id, instanz_id, leitstelle_id, einsatztyp, latitude, longitude, state, meta_json
            FROM instanz_einsaetze
            WHERE id = ? AND instanz_id = ? AND state IN (?, ?)
            LIMIT 1
        ');
        $incident_stmt->execute([$einsatz_id, $instanz_id, 'new', 'active']);
        $incident = $incident_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            wp_send_json_error(['message' => 'Einsatz nicht gefunden oder bereits abgeschlossen.'], 404);
        }

        lsttraining_sim_ensure_neighbor_schema($pdo);
        $neighbor_stmt = $pdo->prepare('
            SELECT n.id, n.name
            FROM leitstelle_nebenleitstellen ln
            INNER JOIN leitstellen l ON l.id = ln.leitstelle_id
            INNER JOIN nebenleitstellen n ON n.id = ln.nebenleitstelle_id
            WHERE ln.leitstelle_id = ? AND ln.nebenleitstelle_id = ?
              AND ln.nebenleitstelle_id <> ln.leitstelle_id
              AND LOWER(CONVERT(TRIM(n.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci <> LOWER(CONVERT(TRIM(l.name) USING utf8mb4)) COLLATE utf8mb4_unicode_ci
            LIMIT 1
        ');
        $neighbor_stmt->execute([(int) $incident['leitstelle_id'], $nebenleitstelle_id]);
        $neighbor = $neighbor_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$neighbor) {
            wp_send_json_error(['message' => 'Diese Nebenleitstelle ist für die aktive Leitstelle nicht als Nachbar hinterlegt.'], 409);
        }

        $runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $now_ts = (int) ($runtime['game_now_ts'] ?? time());
        $now = (string) ($runtime['sim_now'] ?? wp_date('Y-m-d H:i:s', $now_ts));
        $vehicles = array_values(array_filter(
            lsttraining_sim_fetch_neighbor_vehicle_availability($pdo, $instanz_id, (int) $incident['leitstelle_id'], $now_ts),
            static function (array $vehicle) use ($nebenleitstelle_id): bool {
                return (int) ($vehicle['nebenleitstelle_id'] ?? 0) === $nebenleitstelle_id;
            }
        ));
        $offer = array_map('lsttraining_sim_neighbor_offer_from_vehicle', $vehicles);
        $available_count = count(array_filter($offer, static function (array $vehicle): bool {
            return !empty($vehicle['available']);
        }));
        $settings_stmt = $pdo->prepare('SELECT settings_json FROM spielinstanzen WHERE id = ? LIMIT 1');
        $settings_stmt->execute([$instanz_id]);
        $weather_current = lsttraining_sim_weather_point_for_timestamp(
            lsttraining_sim_decode_meta((string) ($settings_stmt->fetchColumn() ?: '')),
            $now_ts
        );
        $load_profile = [];
        foreach ($offer as $offer_vehicle) {
            if (is_array($offer_vehicle['load_profile'] ?? null) && $offer_vehicle['load_profile']) {
                $load_profile = $offer_vehicle['load_profile'];
                break;
            }
        }
        $offer_session_id = 'neighbor-offer-' . $instanz_id . '-' . $einsatz_id . '-' . $nebenleitstelle_id . '-' . $now_ts . '-' . wp_generate_uuid4();

        $pdo->beginTransaction();
        $event_id = lsttraining_sim_insert_dispatch_event(
            $pdo,
            $einsatz_id,
            'Leitstelle von Leitstelle: Unterstützungsanfrage an ' . (string) ($neighbor['name'] ?? 'Nachbarleitstelle') . ', kommen.',
            [
                'event_type' => 'neighbor_support_request',
                'radio_message_type' => 'neighbor_support_request',
                'sender_type' => 'dispatch',
                'recipient_type' => 'neighbor_dispatch',
                'nebenleitstelle_id' => $nebenleitstelle_id,
                'nebenleitstelle_name' => (string) ($neighbor['name'] ?? ''),
                'offer' => $offer,
                'offer_generated_at' => $now,
                'offer_session_id' => $offer_session_id,
                'weather_primary' => (string) ($weather_current['primary'] ?? 'clear'),
                'weather_tags' => is_array($weather_current['tags'] ?? null) ? $weather_current['tags'] : [],
                'load_profile' => $load_profile,
                'requested_at' => $now,
                'requested_by' => (int) get_current_user_id(),
                'available_count' => $available_count,
                'total_count' => count($offer),
            ]
        );
        lsttraining_sim_insert_unit_event(
            $pdo,
            $einsatz_id,
            (string) ($neighbor['name'] ?? 'Nachbarleitstelle') . ': ' . ($available_count > 0 ? $available_count . ' Fahrzeug(e) verfügbar.' : 'Aktuell keine Fahrzeuge verfügbar.'),
            [
                'event_type' => 'neighbor_support_response',
                'radio_message_type' => 'neighbor_support_response',
                'sender_type' => 'neighbor_dispatch',
                'recipient_type' => 'dispatch',
                'source_event_id' => $event_id,
                'nebenleitstelle_id' => $nebenleitstelle_id,
                'nebenleitstelle_name' => (string) ($neighbor['name'] ?? ''),
                'available_count' => $available_count,
                'requires_ack' => false,
                'radio_base_at' => $now,
            ]
        );
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        wp_send_json_success([
            'event_id' => $event_id,
            'einsatz_id' => $einsatz_id,
            'nebenleitstelle_id' => $nebenleitstelle_id,
            'nebenleitstelle_name' => (string) ($neighbor['name'] ?? ''),
            'offer' => $offer,
            'offer_generated_at' => $now,
            'offer_session_id' => $offer_session_id,
            'available_count' => $available_count,
            'message' => $available_count > 0 ? 'Nachbarleitstelle hat verfügbare Fahrzeuge gemeldet.' : 'Nachbarleitstelle hat keine verfügbaren Fahrzeuge gemeldet.',
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_request_neighbor_support] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Unterstützungsanfrage konnte nicht gestellt werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_accept_neighbor_support', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $request_event_id = isset($_POST['request_event_id']) ? absint($_POST['request_event_id']) : 0;
    $raw_vehicle_ids = $_POST['vehicle_ids'] ?? [];
    if (!is_array($raw_vehicle_ids)) {
        $raw_vehicle_ids = explode(',', (string) wp_unslash($raw_vehicle_ids));
    }
    $vehicle_ids = array_values(array_unique(array_filter(array_map('absint', $raw_vehicle_ids))));
    if ($instanz_id <= 0 || $request_event_id <= 0 || !$vehicle_ids) {
        wp_send_json_error(['message' => 'Auswahl für Nachbarunterstützung unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $stmt = $pdo->prepare('
            SELECT ev.id, ev.instanz_einsatz_id, ev.meta_json, ie.instanz_id, ie.leitstelle_id,
                   ie.latitude, ie.longitude, ie.einsatztyp, ie.meta_json AS incident_meta_json
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$request_event_id, $instanz_id, 'dispatcher_question']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            wp_send_json_error(['message' => 'Unterstützungsangebot nicht gefunden.'], 404);
        }
        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'neighbor_support_request') {
            wp_send_json_error(['message' => 'Dieses Ereignis ist kein Nachbarleitstellen-Angebot.'], 400);
        }
        $offer_by_id = [];
        foreach ((array) ($meta['offer'] ?? []) as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $offer_by_id[(int) ($offer['fahrzeug_id'] ?? 0)] = $offer;
        }

        $active_states = lsttraining_sim_neighbor_support_states($pdo, $instanz_id);
        $runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $now_ts = (int) ($runtime['game_now_ts'] ?? time());
        $now = (string) ($runtime['sim_now'] ?? wp_date('Y-m-d H:i:s', $now_ts));
        $created = [];
        $failed = [];

        $pdo->beginTransaction();
        $event_update = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');

        foreach ($vehicle_ids as $vehicle_id) {
            $offer = $offer_by_id[$vehicle_id] ?? null;
            if (!$offer || empty($offer['available'])) {
                $failed[] = ['fahrzeug_id' => $vehicle_id, 'message' => 'Fahrzeug ist in diesem Angebot nicht verfügbar.'];
                continue;
            }
            if (isset($active_states[$vehicle_id])) {
                $failed[] = ['fahrzeug_id' => $vehicle_id, 'message' => 'Fahrzeug ist bereits angefordert.'];
                continue;
            }
            if (!is_numeric($offer['latitude'] ?? null) || !is_numeric($offer['longitude'] ?? null)) {
                $failed[] = ['fahrzeug_id' => $vehicle_id, 'message' => 'Startposition fehlt.'];
                continue;
            }

            $route = lsttraining_sim_transport_route(
                (float) $offer['latitude'],
                (float) $offer['longitude'],
                (float) $event['latitude'],
                (float) $event['longitude'],
                lsttraining_sim_transport_is_air_unit($offer)
            );
            if (count($route['coordinates'] ?? []) < 2) {
                $failed[] = ['fahrzeug_id' => $vehicle_id, 'message' => 'Keine Route zum Einsatzort gefunden.'];
                continue;
            }

            $rufname = (string) ($offer['rufname'] ?? ('Fremdfahrzeug ' . $vehicle_id));
            $support_id = 'neighbor-' . (int) ($offer['nebenleitstelle_id'] ?? 0) . '-' . $vehicle_id . '-' . $request_event_id;
            $alarm_meta = [
                'event_type' => 'support_vehicle_alarm',
                'support_type' => 'neighbor',
                'support_id' => $support_id,
                'foreign_unit' => true,
                'home_nebenleitstelle_id' => (int) ($offer['nebenleitstelle_id'] ?? 0),
                'home_nebenleitstelle_name' => (string) ($offer['nebenleitstelle_name'] ?? ''),
                'home_wache_id' => (int) ($offer['home_wache_id'] ?? ($offer['wache_id'] ?? 0)),
                'home_wache_name' => (string) ($offer['home_wache_name'] ?? ''),
                'home_position' => [
                    'latitude' => (float) ($offer['home_latitude'] ?? $offer['latitude']),
                    'longitude' => (float) ($offer['home_longitude'] ?? $offer['longitude']),
                ],
                'radio_message_type' => 'neighbor_support_dispatched',
                'sender_type' => 'neighbor_dispatch',
                'recipient_type' => 'dispatch',
                'status_transition' => '3',
                'status_id' => 0,
                'fahrzeug_id' => $vehicle_id,
                'fahrzeugtyp' => (string) ($offer['fahrzeugtyp'] ?? ''),
                'resource_class' => (string) ($offer['resource_class'] ?? ''),
                'resource_class_label' => (string) ($offer['resource_class_label'] ?? ''),
                'einsatz_id' => (int) ($event['instanz_einsatz_id'] ?? 0),
                'rufname' => $rufname,
                'alarmiert_at' => $now,
                'ausrueckzeit_sec' => 0,
                'movement_started_at' => $now,
                'sondersignal_allowed' => true,
                'sondersignal' => 1,
                'route_status' => 'ready',
                'route_coordinates' => $route['coordinates'],
                'route_segments' => is_array($route['route_segments'] ?? null) ? $route['route_segments'] : [],
                'route_distance_m' => (int) ($route['distance_m'] ?? 0),
                'route_duration_sec' => (int) ($route['duration_sec'] ?? 0),
                'route_duration_normal_sec' => (int) ($route['duration_sec'] ?? 0),
                'route_source' => (string) ($route['route_source'] ?? ''),
                'mission_phase' => 'to_scene',
                'current_progress' => 0,
                'current_segment_index' => 0,
                'current_segment_progress' => 0,
                'last_position' => [
                    'latitude' => (float) $offer['latitude'],
                    'longitude' => (float) $offer['longitude'],
                ],
                'image_url' => (string) ($offer['image_url'] ?? ''),
                'signal_lights' => is_array($offer['signal_lights'] ?? null) ? $offer['signal_lights'] : [],
                'source_request_event_id' => $request_event_id,
                'fms_status' => '3',
                'radio_base_at' => $now,
            ];
            $support_event_id = lsttraining_sim_insert_unit_event(
                $pdo,
                (int) $event['instanz_einsatz_id'],
                (string) ($offer['nebenleitstelle_name'] ?? 'Nachbarleitstelle') . ': ' . $rufname . ' fährt zur Unterstützung an.',
                $alarm_meta
            );
            $created[] = [
                'event_id' => $support_event_id,
                'fahrzeug_id' => $vehicle_id,
                'rufname' => $rufname,
            ];
            $active_states[$vehicle_id] = ['state' => 'unterwegs zur spielbaren Leitstelle', 'phase' => 'to_scene'];
        }

        $accepted = is_array($meta['accepted_vehicle_ids'] ?? null) ? $meta['accepted_vehicle_ids'] : [];
        foreach ($created as $created_row) {
            $accepted[] = (int) ($created_row['fahrzeug_id'] ?? 0);
        }
        $meta['accepted_vehicle_ids'] = array_values(array_unique(array_filter($accepted)));
        $meta['accepted_at'] = $created ? $now : ($meta['accepted_at'] ?? '');
        $meta['accepted_by'] = $created ? (int) get_current_user_id() : ($meta['accepted_by'] ?? 0);
        $event_update->execute([lsttraining_sim_encode_meta($meta), $request_event_id]);
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        if (!$created) {
            wp_send_json_error([
                'message' => 'Kein Fahrzeug konnte angefordert werden.',
                'failed' => $failed,
            ], 409);
        }

        wp_send_json_success([
            'einsatz_id' => (int) $event['instanz_einsatz_id'],
            'created' => $created,
            'failed' => $failed,
            'message' => count($created) . ' Fremdfahrzeug(e) angefordert.',
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_accept_neighbor_support] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Fremdfahrzeuge konnten nicht angefordert werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_accept_call', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;
    if ($einsatz_id <= 0) {
        wp_send_json_error(['message' => 'Einsatz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        $stmt = $pdo->prepare('
            SELECT
                ie.id,
                ie.instanz_id,
                ie.leitstelle_id,
                ie.latitude,
                ie.longitude,
                ie.caller_text,
                ie.meta_json,
                ie.state,
                l.name AS leitstelle_name
            FROM instanz_einsaetze ie
            LEFT JOIN leitstellen l ON l.id = ie.leitstelle_id
            WHERE ie.id = ?
            LIMIT 1
        ');
        $stmt->execute([$einsatz_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            wp_send_json_error(['message' => 'Einsatz nicht gefunden.'], 404);
        }

        $instanz_id = (int) $incident['instanz_id'];
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diesen Einsatz.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);
        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $game_now = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));

        $meta = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
        $incident['meta'] = $meta;
        $incident['latitude'] = (float) ($incident['latitude'] ?? 0.0);
        $incident['longitude'] = (float) ($incident['longitude'] ?? 0.0);
        if (($meta['call_status'] ?? '') !== 'accepted') {
            $meta['call_status'] = 'accepted';
            $meta['call_accepted_at'] = $game_now;
            $meta['call_accepted_by'] = (int) get_current_user_id();

            $pdo->beginTransaction();
            $update = $pdo->prepare('
                UPDATE instanz_einsaetze
                SET state = ?, meta_json = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $update->execute(['active', lsttraining_sim_encode_meta($meta), $einsatz_id]);

            $event = $pdo->prepare('
                INSERT INTO instanz_einsatz_events (instanz_einsatz_id, kind, text, meta_json)
                VALUES (?, ?, ?, ?)
            ');
            $event->execute([
                $einsatz_id,
                'dispatcher_question',
                'Leitstelle Notruf, wo genau ist der Einsatzort?',
                lsttraining_sim_encode_meta(['event_type' => 'call_accept', 'user_id' => (int) get_current_user_id()]),
            ]);

            $caller_text = trim((string) ($incident['caller_text'] ?? ''));
            if ($caller_text !== '') {
                $incident_for_display = $incident;
                $incident_for_display['meta'] = $meta;
                $caller_text = lsttraining_sim_clean_caller_text_for_display($caller_text, $incident_for_display);
                $event->execute([
                    $einsatz_id,
                    'caller_answer',
                    $caller_text,
                    lsttraining_sim_encode_meta(['event_type' => 'call_text']),
                ]);
            }
            $pdo->commit();
        } elseif ((string) ($incident['state'] ?? '') !== 'active') {
            $update = $pdo->prepare('UPDATE instanz_einsaetze SET state = ?, updated_at = NOW() WHERE id = ?');
            $update->execute(['active', $einsatz_id]);
        }

        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success([
            'einsatz_id' => $einsatz_id,
            'state' => 'active',
            'call_status' => 'accepted',
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_accept_call] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Anruf konnte nicht angenommen werden: ' . $e->getMessage()], 500);
    }
});

function lsttraining_sim_sync_signal_for_assigned_units(PDO $pdo, int $instanz_id, int $einsatz_id, string $game_now, bool $target_signal_allowed): int {
    $stmt = $pdo->prepare('
        SELECT id, text, meta_json
        FROM instanz_einsatz_events
        WHERE instanz_einsatz_id = ? AND kind = ?
        ORDER BY id DESC
        LIMIT 500
    ');
    $stmt->execute([$einsatz_id, 'unit_report']);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $update_event = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');
    $notified = 0;

    foreach ($events as $event) {
        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        $event_type = (string) ($meta['event_type'] ?? '');
        $is_vehicle = $event_type === 'vehicle_alarm';
        $support_type = $event_type === 'support_vehicle_alarm' ? (string) ($meta['support_type'] ?? '') : '';
        $is_support = in_array($support_type, ['police', 'neighbor'], true);
        $is_police = $support_type === 'police';
        if ((!$is_vehicle && !$is_support) || !empty($meta['cancelled_at'])) {
            continue;
        }
        if ($support_type === 'neighbor' && empty($meta['contact_established_at']) && empty($meta['entry_speak_request_event_id'])) {
            continue;
        }
        if ((string) ($meta['mission_phase'] ?? 'to_scene') !== 'to_scene' || !empty($meta['arrived_at'])) {
            continue;
        }
        if (!empty($meta['sondersignal_allowed']) === $target_signal_allowed) {
            continue;
        }

        $status_id = (int) ($meta['status_id'] ?? 0);
        $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
        if ($is_police && $rufname === 'Fahrzeug') {
            $rufname = 'Polizei';
        }
        $route_segments = lsttraining_sim_normalize_route_segments($meta['route_segments'] ?? []);
        $normal_segments = lsttraining_sim_normalize_route_segments($meta['route_segments_normal'] ?? []);
        $normal_duration = max(0, (int) ($meta['route_duration_normal_sec'] ?? 0));
        if (!$normal_segments && $route_segments) {
            $normal_segments = $route_segments;
            if ($normal_duration > 0) {
                $normal_segments = lsttraining_sim_route_segments_scaled_to_duration($normal_segments, $normal_duration);
            }
        }
        if (!$normal_segments && is_array($meta['route_coordinates'] ?? null) && count($meta['route_coordinates']) >= 2) {
            $normal_segments = [
                lsttraining_sim_route_segment(
                    (string) ($meta['route_source'] ?? '') === 'air' ? 'air' : 'road',
                    (array) $meta['route_coordinates'],
                    $normal_duration > 0 ? $normal_duration : max(1, (int) ($meta['route_duration_sec'] ?? 1)),
                    (int) ($meta['route_distance_m'] ?? 0)
                ),
            ];
        }
        if (!$normal_duration) {
            $normal_duration = lsttraining_sim_route_segments_total_duration($normal_segments);
        }
        if (!$normal_duration) {
            $normal_duration = max(0, (int) ($meta['route_duration_sec'] ?? 0));
        }

        $active_segments = $target_signal_allowed
            ? lsttraining_sim_route_segments_for_signal($normal_segments, true)
            : $normal_segments;
        if ($active_segments) {
            $meta['route_segments_normal'] = $normal_segments;
            $meta['route_segments'] = $active_segments;
            $meta['route_coordinates'] = lsttraining_sim_flatten_route_segments($active_segments);
            $meta['route_duration_sec'] = lsttraining_sim_route_segments_total_duration($active_segments);
        } elseif ($normal_duration > 0) {
            $meta['route_duration_sec'] = $target_signal_allowed
                ? lsttraining_sim_route_duration_for_signal($normal_duration, true)
                : $normal_duration;
        }
        if ($normal_duration > 0) {
            $meta['route_duration_normal_sec'] = $normal_duration;
        }

        $meta['sondersignal_allowed'] = $target_signal_allowed;
        $meta['sondersignal'] = $target_signal_allowed ? 1 : 0;
        $meta['sondersignal_changed_at'] = $game_now;
        $meta['sondersignal_changed_by'] = (int) get_current_user_id();
        $update_event->execute([lsttraining_sim_encode_meta($meta), (int) $event['id']]);

        if ($is_vehicle && $status_id > 0) {
            lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                'sondersignal' => $target_signal_allowed ? 1 : 0,
                'bemerkung' => $target_signal_allowed
                    ? 'Mit Sondersignal auf Anfahrt zum Einsatz.'
                    : 'Ohne Sondersignal auf Anfahrt zum Einsatz.',
            ]);
        }

        $dispatch_text = $target_signal_allowed
            ? $rufname . ' von Leitstelle: Sondersignale erlaubt, fahren Sie mit Sondersignal weiter, kommen.'
            : $rufname . ' von Leitstelle: Sondersignale zurücknehmen, ohne Sondersignal weiterfahren, kommen.';
        $ack_text = $target_signal_allowed
            ? 'Leitstelle von ' . $rufname . ': Verstanden, fahren mit Sondersignal weiter, Ende.'
            : 'Leitstelle von ' . $rufname . ': Verstanden, fahren ohne Sondersignal weiter, Ende.';
        $radio_type = $target_signal_allowed ? 'signal_allowed' : 'signal_cancelled';

        lsttraining_sim_insert_dispatch_event($pdo, $einsatz_id, $dispatch_text, [
            'event_type' => $is_police ? 'police_signal_order' : ($is_support ? 'support_signal_order' : 'signal_order'),
            'radio_message_type' => $radio_type,
            'sender_type' => 'dispatch',
            'recipient_type' => 'vehicle',
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => $rufname,
            'support_type' => $support_type,
            'source_event_id' => (int) $event['id'],
            'sondersignal_allowed' => $target_signal_allowed,
        ]);
        lsttraining_sim_insert_unit_event($pdo, $einsatz_id, $ack_text, [
            'event_type' => $is_police ? 'police_signal_order_ack' : ($is_support ? 'support_signal_order_ack' : 'signal_order_ack'),
            'radio_message_type' => $radio_type . '_ack',
            'sender_type' => 'vehicle',
            'recipient_type' => 'dispatch',
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => $rufname,
            'support_type' => $support_type,
            'source_event_id' => (int) $event['id'],
            'sondersignal_allowed' => $target_signal_allowed,
            'radio_base_at' => $game_now,
        ]);
        $notified++;
    }

    return $notified;
}

add_action('wp_ajax_lsttraining_sim_save_dispatch', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;
    if ($instanz_id <= 0 || $einsatz_id <= 0) {
        wp_send_json_error(['message' => 'Dispositionsdaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);
        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $game_now = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));

        $stmt = $pdo->prepare('
            SELECT id, instanz_id, meta_json, state
            FROM instanz_einsaetze
            WHERE id = ? AND instanz_id = ?
            LIMIT 1
        ');
        $stmt->execute([$einsatz_id, $instanz_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            wp_send_json_error(['message' => 'Einsatz nicht gefunden.'], 404);
        }

        $meta = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
        $meta['disposition_status'] = 'prepared';
        $meta['signal_allowed'] = !empty($_POST['signal_allowed']);
        $meta['einsatzcode'] = sanitize_text_field(wp_unslash($_POST['einsatzcode'] ?? ''));
        $meta['ausrueckorder'] = sanitize_text_field(wp_unslash($_POST['ausrueckorder'] ?? ''));
        $meta['einsatzkategorie'] = sanitize_text_field(wp_unslash($_POST['einsatzkategorie'] ?? ''));
        $meta['zusatz_text'] = sanitize_textarea_field(wp_unslash($_POST['zusatz_text'] ?? ''));
        $meta['abholzeit'] = sanitize_text_field(wp_unslash($_POST['abholzeit'] ?? ''));
        $meta['polizei_verstaendigen'] = !empty($_POST['polizei_verstaendigen']);
        $meta['dispatch_saved_at'] = $game_now;
        $meta['dispatch_saved_by'] = (int) get_current_user_id();

        $update = $pdo->prepare('
            UPDATE instanz_einsaetze
            SET meta_json = ?, updated_at = NOW()
            WHERE id = ? AND instanz_id = ?
        ');
        $update->execute([lsttraining_sim_encode_meta($meta), $einsatz_id, $instanz_id]);
        if (!empty($meta['polizei_verstaendigen'])) {
            lsttraining_sim_ensure_police_support($pdo, $instanz_id, $einsatz_id, $game_now);
        }
        $signal_notifications = lsttraining_sim_sync_signal_for_assigned_units($pdo, $instanz_id, $einsatz_id, $game_now, !empty($meta['signal_allowed']));

        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success([
            'einsatz_id' => $einsatz_id,
            'signal_allowed' => (bool) $meta['signal_allowed'],
            'signal_notifications' => $signal_notifications,
            'message' => 'Einsatzdaten gespeichert.',
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_save_dispatch] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Einsatzdaten konnten nicht gespeichert werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_alarm_vehicle', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;
    $status_id = isset($_POST['status_id']) ? absint($_POST['status_id']) : 0;
    $request_signal = isset($_POST['sondersignal_allowed']) ? (int) $_POST['sondersignal_allowed'] === 1 : null;
    if ($instanz_id <= 0 || $einsatz_id <= 0 || $status_id <= 0) {
        wp_send_json_error(['message' => 'Alarmierungsdaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $incident_stmt = $pdo->prepare('
            SELECT id, instanz_id, latitude, longitude, einsatztyp, poi_name_snapshot, state, meta_json
            FROM instanz_einsaetze
            WHERE id = ? AND instanz_id = ?
            LIMIT 1
        ');
        $incident_stmt->execute([$einsatz_id, $instanz_id]);
        $incident = $incident_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident) {
            wp_send_json_error(['message' => 'Einsatz nicht gefunden.'], 404);
        }

        $incident_meta = lsttraining_sim_decode_meta($incident['meta_json'] ?? '');
        $incident['meta'] = $incident_meta;
        $sondersignal_allowed = $request_signal !== null
            ? $request_signal
            : !empty($incident_meta['signal_allowed']);
        $call_status = (string) ($incident_meta['call_status'] ?? ($incident['state'] === 'active' ? 'accepted' : 'ringing'));
        if ($call_status !== 'accepted' && (string) $incident['state'] !== 'active') {
            wp_send_json_error(['message' => 'Der Anruf muss zuerst angenommen werden.'], 409);
        }

        $vehicle_stmt = $pdo->prepare('
            SELECT
                fs.id AS status_id,
                fs.fahrzeug_id,
                fs.instanz_id,
                fs.wache_id,
                CASE WHEN ifs.id IS NULL THEN fs.latitude ELSE ifs.latitude END AS latitude,
                CASE WHEN ifs.id IS NULL THEN fs.longitude ELSE ifs.longitude END AS longitude,
                CASE WHEN ifs.id IS NULL THEN fs.status ELSE ifs.status END AS status,
                CASE WHEN ifs.id IS NULL THEN fs.fms_status ELSE ifs.fms_status END AS fms_status,
                w.latitude AS wache_latitude,
                w.longitude AS wache_longitude,
                f.rufname,
                f.fahrzeugtyp
            FROM fahrzeug_status fs
            LEFT JOIN instanz_fahrzeug_status ifs
              ON ifs.instanz_id = fs.instanz_id
             AND ifs.fahrzeug_status_id = fs.id
            LEFT JOIN fahrzeuge f ON f.id = fs.fahrzeug_id
            LEFT JOIN wachen w ON w.id = fs.wache_id
            WHERE fs.id = ? AND fs.instanz_id = ?
            LIMIT 1
        ');
        $vehicle_stmt->execute([$status_id, $instanz_id]);
        $vehicle = $vehicle_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle) {
            wp_send_json_error(['message' => 'Fahrzeug nicht gefunden.'], 404);
        }
        $vehicle_fms = trim((string) ($vehicle['fms_status'] ?? ''));
        if (in_array($vehicle_fms, ['3', '4', '5', '6', '7', '8'], true)) {
            $reason = [
                '3' => 'auf Anfahrt',
                '4' => 'vor Ort',
                '5' => 'im Sprechwunsch',
                '6' => 'nicht verfügbar',
                '7' => 'im Patiententransport',
                '8' => 'im Krankenhaus',
            ][$vehicle_fms];
            wp_send_json_error(['message' => 'Dieses Fahrzeug kann nicht alarmiert werden: ' . $reason . '.'], 409);
        }
        $vehicle_status = trim((string) ($vehicle['status'] ?? ''));
        if ($vehicle_status !== '' && !in_array($vehicle_status, ['frei', 'einsatzbereit'], true)) {
            wp_send_json_error(['message' => 'Dieses Fahrzeug kann nicht alarmiert werden: nicht frei.'], 409);
        }
        $vehicle_start_lat = $vehicle['latitude'] !== null ? (float) $vehicle['latitude'] : null;
        $vehicle_start_lon = $vehicle['longitude'] !== null ? (float) $vehicle['longitude'] : null;
        if (($vehicle_start_lat === null || $vehicle_start_lon === null) && $vehicle['wache_latitude'] !== null && $vehicle['wache_longitude'] !== null) {
            $vehicle_start_lat = (float) $vehicle['wache_latitude'];
            $vehicle_start_lon = (float) $vehicle['wache_longitude'];
        }
        if ($vehicle_start_lat === null || $vehicle_start_lon === null) {
            wp_send_json_error(['message' => 'Fahrzeug hat keine Startkoordinaten.'], 400);
        }

        $rufname = (string) ($vehicle['rufname'] ?: ('Fahrzeug ' . $vehicle['fahrzeug_id']));
        $fahrzeugtyp = (string) ($vehicle['fahrzeugtyp'] ?? '');
        $resource_class = lsttraining_sim_resource_class_from_type($fahrzeugtyp);
        $air_unit = lsttraining_sim_transport_is_air_unit([
            'resource_class' => $resource_class,
            'fahrzeugtyp' => $fahrzeugtyp,
            'rufname' => $rufname,
        ]);

        $already_stmt = $pdo->prepare('
            SELECT ev.id, ev.meta_json
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ie.instanz_id = ? AND ev.kind = ? AND ie.state IN (?, ?)
            ORDER BY ev.id DESC
            LIMIT 250
        ');
        $already_stmt->execute([$instanz_id, 'unit_report', 'new', 'active']);
        foreach (($already_stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $meta = lsttraining_sim_decode_meta($row['meta_json'] ?? '');
            if (!empty($meta['cancelled_at'])) {
                continue;
            }
            if (($meta['event_type'] ?? '') === 'vehicle_alarm' && (int) ($meta['status_id'] ?? 0) === $status_id) {
                wp_send_json_error(['message' => 'Dieses Fahrzeug ist bereits alarmiert.'], 409);
            }
        }

        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $turnout_sec = 60;
        $alarmiert_at = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));
        $meta = [
            'event_type' => 'vehicle_alarm',
            'radio_message_type' => 'alarm_acknowledgement',
            'sender_type' => 'vehicle',
            'recipient_type' => 'dispatch',
            'status_transition' => '3',
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($vehicle['fahrzeug_id'] ?? 0),
            'fahrzeugtyp' => $fahrzeugtyp,
            'resource_class' => $resource_class,
            'resource_class_label' => $resource_class !== '' ? lsttraining_sim_resource_class_label($resource_class) : '',
            'einsatz_id' => $einsatz_id,
            'rufname' => $rufname,
            'alarmiert_at' => $alarmiert_at,
            'ausrueckzeit_sec' => $turnout_sec,
            'sondersignal_allowed' => $sondersignal_allowed,
            'route_status' => 'pending',
            'route_coordinates' => [],
            'route_segments' => [],
            'route_distance_m' => 0,
            'route_duration_sec' => 0,
            'route_duration_normal_sec' => 0,
            'route_source' => $air_unit ? 'air_pending' : 'routing_pending',
            'route_error_code' => '',
            'route_error_message' => '',
            'route_error_detail' => '',
            'route_requested_at' => $alarmiert_at,
            'route_start_position' => [
                'latitude' => $vehicle_start_lat,
                'longitude' => $vehicle_start_lon,
            ],
            'route_target_position' => [
                'latitude' => (float) $incident['latitude'],
                'longitude' => (float) $incident['longitude'],
            ],
            'mission_phase' => 'to_scene',
            'current_progress' => 0,
            'current_segment_index' => 0,
            'current_segment_progress' => 0,
            'last_position' => [
                'latitude' => $vehicle_start_lat,
                'longitude' => $vehicle_start_lon,
            ],
        ];

        $pdo->beginTransaction();
        $alarm_location = lsttraining_sim_alarm_location_text($incident);
        $alarm_subject = trim((string) ($incident['einsatztyp'] ?? 'Einsatz'));
        $alarm_parts = array_values(array_filter([$alarm_subject !== '' ? $alarm_subject : 'Einsatz', $alarm_location], static function (string $part): bool {
            return trim($part) !== '';
        }));
        lsttraining_sim_insert_dispatch_event(
            $pdo,
            $einsatz_id,
            $rufname . ' von Leitstelle: Einsatz für Sie, ' . implode(', ', $alarm_parts) . ', kommen.',
            [
                'event_type' => 'vehicle_alarm_dispatch',
                'radio_message_type' => 'alarm_order',
                'sender_type' => 'dispatch',
                'recipient_type' => 'vehicle',
                'status_id' => $status_id,
                'fahrzeug_id' => (int) ($vehicle['fahrzeug_id'] ?? 0),
                'rufname' => $rufname,
                'status_transition' => '3',
                'alarm_location' => $alarm_location,
            ]
        );
        $event_id = lsttraining_sim_insert_unit_event(
            $pdo,
            $einsatz_id,
            'Leitstelle von ' . $rufname . ': Verstanden, Einsatz übernommen, Ende.',
            $meta
        );

        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
            'ziel_latitude' => (float) $incident['latitude'],
            'ziel_longitude' => (float) $incident['longitude'],
            'status' => 'besetzt',
            'sondersignal' => 0,
            'bemerkung' => 'Alarmiert, Route wird berechnet.',
        ]);
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        wp_send_json_success([
            'einsatz_id' => $einsatz_id,
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($vehicle['fahrzeug_id'] ?? 0),
            'event_id' => $event_id,
            'alarmiert_at' => $alarmiert_at,
            'ausrueckzeit_sec' => $turnout_sec,
            'route_status' => 'pending',
            'route_distance_m' => 0,
            'route_duration_sec' => 0,
            'sondersignal_allowed' => $sondersignal_allowed,
            'message' => $rufname . ' wurde alarmiert. Route wird berechnet.',
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_alarm_vehicle] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Fahrzeug konnte nicht alarmiert werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_resolve_vehicle_route', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ($instanz_id <= 0 || $event_id <= 0) {
        wp_send_json_error(['message' => 'Routendaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_require_vehicle_delta_model($pdo, $instanz_id);

        $stmt = $pdo->prepare('
            SELECT
                ev.id,
                ev.instanz_einsatz_id,
                ev.meta_json,
                ie.latitude AS einsatz_latitude,
                ie.longitude AS einsatz_longitude,
                ie.instanz_id
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$event_id, $instanz_id, 'unit_report']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            wp_send_json_error(['message' => 'Alarmierung nicht gefunden.'], 404);
        }

        $meta = lsttraining_sim_decode_meta($row['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'vehicle_alarm') {
            wp_send_json_error(['message' => 'Diese Meldung ist keine Fahrzeugalarmierung.'], 400);
        }
        if (!empty($meta['cancelled_at'])) {
            wp_send_json_error(['message' => 'Diese Alarmierung wurde bereits aufgehoben.'], 409);
        }
        if ((string) ($meta['route_status'] ?? '') === 'ready' && is_array($meta['route_coordinates'] ?? null) && count($meta['route_coordinates']) >= 2) {
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
            wp_send_json_success([
                'event_id' => $event_id,
                'route_status' => 'ready',
                'message' => 'Route ist bereits berechnet.',
            ]);
        }

        $status_id = (int) ($meta['status_id'] ?? 0);
        $vehicle_stmt = $pdo->prepare('
            SELECT
                CASE WHEN ifs.id IS NULL THEN fs.latitude ELSE ifs.latitude END AS latitude,
                CASE WHEN ifs.id IS NULL THEN fs.longitude ELSE ifs.longitude END AS longitude,
                fs.instanz_id,
                w.latitude AS wache_latitude,
                w.longitude AS wache_longitude
            FROM fahrzeug_status fs
            LEFT JOIN instanz_fahrzeug_status ifs
              ON ifs.instanz_id = fs.instanz_id
             AND ifs.fahrzeug_status_id = fs.id
            LEFT JOIN wachen w ON w.id = fs.wache_id
            WHERE fs.id = ? AND fs.instanz_id = ?
            LIMIT 1
        ');
        $vehicle_stmt->execute([$status_id, $instanz_id]);
        $vehicle = $vehicle_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $start_meta = is_array($meta['last_position'] ?? null)
            ? $meta['last_position']
            : (is_array($meta['route_start_position'] ?? null) ? $meta['route_start_position'] : []);
        $from_lat = is_numeric($vehicle['latitude'] ?? null) ? (float) $vehicle['latitude'] : (is_numeric($start_meta['latitude'] ?? null) ? (float) $start_meta['latitude'] : null);
        $from_lon = is_numeric($vehicle['longitude'] ?? null) ? (float) $vehicle['longitude'] : (is_numeric($start_meta['longitude'] ?? null) ? (float) $start_meta['longitude'] : null);
        if (($from_lat === null || $from_lon === null) && is_numeric($vehicle['wache_latitude'] ?? null) && is_numeric($vehicle['wache_longitude'] ?? null)) {
            $from_lat = (float) $vehicle['wache_latitude'];
            $from_lon = (float) $vehicle['wache_longitude'];
        }
        $to_lat = is_numeric($row['einsatz_latitude'] ?? null) ? (float) $row['einsatz_latitude'] : null;
        $to_lon = is_numeric($row['einsatz_longitude'] ?? null) ? (float) $row['einsatz_longitude'] : null;
        if ($from_lat === null || $from_lon === null || $to_lat === null || $to_lon === null) {
            $result = lsttraining_sim_route_result_error(
                'invalid_coordinates',
                'Fahrzeug oder Einsatz hat keine gültigen Koordinaten.',
                'Start- oder Zielkoordinaten fehlen in fahrzeug_status/instanz_einsaetze.',
                'resolve_vehicle_route'
            );
        } else {
            $air_unit = lsttraining_sim_transport_is_air_unit($meta);
            if ($air_unit) {
                $air_route = lsttraining_sim_transport_route($from_lat, $from_lon, $to_lat, $to_lon, true);
                $result = count($air_route['coordinates'] ?? []) >= 2
                    ? lsttraining_sim_route_result_success($air_route, 'air_route')
                    : lsttraining_sim_route_result_error('route_not_found', 'Keine Flugroute erzeugt.', 'Luftlinienroute konnte nicht gebaut werden.', 'air_route');
            } else {
                $result = lsttraining_sim_ground_route_with_connector_result($from_lat, $from_lon, $to_lat, $to_lon);
            }
        }

        $now = (string) (lsttraining_sim_current_game_runtime($pdo, $instanz_id)['sim_now'] ?? wp_date('Y-m-d H:i:s'));
        $event_update = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');

        if (empty($result['ok']) || !is_array($result['route'] ?? null)) {
            $meta['route_status'] = 'failed';
            $meta['route_error_code'] = (string) ($result['error_code'] ?? 'route_not_found');
            $meta['route_error_message'] = (string) ($result['message'] ?? 'Keine verwertbare Route gefunden.');
            $meta['route_error_detail'] = (string) ($result['technical_detail'] ?? '');
            $meta['route_error_stage'] = (string) ($result['stage'] ?? 'resolve_vehicle_route');
            $meta['route_error_http_status'] = (int) ($result['http_status'] ?? 0);
            $meta['route_failed_at'] = $now;
            $event_update->execute([lsttraining_sim_encode_meta($meta), $event_id]);
            if ($status_id > 0) {
                lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                    'sondersignal' => 0,
                    'bemerkung' => 'Alarmiert, Route fehlgeschlagen.',
                ]);
            }
            wp_send_json_error([
                'event_id' => $event_id,
                'route_status' => 'failed',
                'route_error_code' => $meta['route_error_code'],
                'route_error_detail' => $meta['route_error_detail'],
                'stage' => $meta['route_error_stage'],
                'http_status' => $meta['route_error_http_status'],
                'message' => $meta['route_error_message'],
            ], 400);
        }

        $route = $result['route'];
        $normal_segments = lsttraining_sim_normalize_route_segments($route['route_segments'] ?? []);
        if (!$normal_segments) {
            $normal_segments = [
                lsttraining_sim_route_segment(
                    lsttraining_sim_transport_is_air_unit($meta) ? 'air' : 'road',
                    (array) ($route['coordinates'] ?? []),
                    (int) ($route['duration_sec'] ?? 0),
                    (int) ($route['distance_m'] ?? 0)
                ),
            ];
        }
        $route_segments = lsttraining_sim_route_segments_for_signal($normal_segments, !empty($meta['sondersignal_allowed']));
        $coordinates = lsttraining_sim_flatten_route_segments($route_segments);
        if (count($coordinates) < 2) {
            $meta['route_status'] = 'failed';
            $meta['route_error_code'] = 'ors_no_geometry';
            $meta['route_error_message'] = 'Route enthält keine verwertbare Linienführung.';
            $meta['route_error_detail'] = 'Nach Normalisierung blieben weniger als zwei Koordinaten übrig.';
            $meta['route_failed_at'] = $now;
            $event_update->execute([lsttraining_sim_encode_meta($meta), $event_id]);
            wp_send_json_error([
                'event_id' => $event_id,
                'route_status' => 'failed',
                'route_error_code' => $meta['route_error_code'],
                'route_error_detail' => $meta['route_error_detail'],
                'message' => $meta['route_error_message'],
            ], 400);
        }

        $route_start = $coordinates[0];
        $route_end = $coordinates[count($coordinates) - 1];
        if (
            lsttraining_sim_distance_m((float) $from_lat, (float) $from_lon, (float) $route_start[1], (float) $route_start[0]) > 750 ||
            lsttraining_sim_distance_m((float) $to_lat, (float) $to_lon, (float) $route_end[1], (float) $route_end[0]) > 750
        ) {
            $meta['route_status'] = 'failed';
            $meta['route_error_code'] = 'invalid_coordinates';
            $meta['route_error_message'] = 'Route passt nicht zu Fahrzeug und Einsatz.';
            $meta['route_error_detail'] = 'Start oder Ziel der berechneten Route liegt zu weit von Fahrzeug oder Einsatz entfernt.';
            $meta['route_failed_at'] = $now;
            $event_update->execute([lsttraining_sim_encode_meta($meta), $event_id]);
            wp_send_json_error([
                'event_id' => $event_id,
                'route_status' => 'failed',
                'route_error_code' => $meta['route_error_code'],
                'route_error_detail' => $meta['route_error_detail'],
                'message' => $meta['route_error_message'],
            ], 400);
        }

        $normal_duration = lsttraining_sim_route_segments_total_duration($normal_segments);
        $route_duration = lsttraining_sim_route_segments_total_duration($route_segments);
        $meta['route_status'] = 'ready';
        $meta['route_resolved_at'] = $now;
        $meta['route_coordinates'] = $coordinates;
        $meta['route_segments'] = $route_segments;
        $meta['route_segments_normal'] = $normal_segments;
        $meta['route_distance_m'] = (int) ($route['distance_m'] ?? lsttraining_sim_route_length_m($coordinates));
        $meta['route_duration_sec'] = $route_duration;
        $meta['route_duration_normal_sec'] = $normal_duration;
        $meta['route_source'] = (string) ($route['route_source'] ?? 'routing');
        $meta['route_error_code'] = '';
        $meta['route_error_message'] = '';
        $meta['route_error_detail'] = '';
        $meta['last_position'] = [
            'latitude' => (float) $route_start[1],
            'longitude' => (float) $route_start[0],
        ];
        $event_update->execute([lsttraining_sim_encode_meta($meta), $event_id]);
        if ($status_id > 0) {
            lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                'sondersignal' => !empty($meta['sondersignal_allowed']) ? 1 : 0,
                'bemerkung' => 'Alarmiert, Route berechnet.',
            ]);
        }

        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success([
            'event_id' => $event_id,
            'route_status' => 'ready',
            'route_distance_m' => $meta['route_distance_m'],
            'route_duration_sec' => $route_duration,
            'message' => 'Route wurde berechnet.',
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_resolve_vehicle_route] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Route konnte nicht berechnet werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_send_vehicle_radio_command', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $command_code = isset($_POST['command_code']) ? sanitize_key(wp_unslash($_POST['command_code'])) : '';
    $commands = [
        'request_situation' => 'Geben Sie Lagemeldung',
        'request_additional_resources' => 'Benötigen Sie weitere Kräfte',
        'request_transport_destination' => 'Transportziel bekannt',
        'request_notarzt_requirement' => 'Ist ein Notarzt erforderlich',
    ];
    if ($instanz_id <= 0 || $event_id <= 0 || !isset($commands[$command_code])) {
        wp_send_json_error(['message' => 'Funkbefehl unvollständig oder ungültig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);
        $stmt = $pdo->prepare('
            SELECT ev.instanz_einsatz_id, ev.meta_json, ie.lagemeldung, ie.meta_json AS incident_meta_json
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$event_id, $instanz_id, 'unit_report']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            wp_send_json_error(['message' => 'Fahrzeugzuordnung nicht gefunden.'], 404);
        }
        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        $is_neighbor_support = ($meta['event_type'] ?? '') === 'support_vehicle_alarm' && ($meta['support_type'] ?? '') === 'neighbor';
        if ((($meta['event_type'] ?? '') !== 'vehicle_alarm' && !$is_neighbor_support) || !empty($meta['cancelled_at'])) {
            wp_send_json_error(['message' => 'Für dieses Fahrzeug ist kein Funkbefehl verfügbar.'], 409);
        }
        $phase = (string) ($meta['mission_phase'] ?? '');
        if ($is_neighbor_support && empty($meta['contact_established_at']) && empty($meta['entry_speak_request_event_id'])) {
            wp_send_json_error(['message' => 'Das Fremdfahrzeug muss sich erst per Sprechwunsch melden.'], 409);
        }
        $allowed = ($phase === 'at_scene' || ($is_neighbor_support && in_array($phase, ['to_scene', 'at_scene'], true)))
            ? array_keys($commands)
            : (in_array($phase, ['to_hospital', 'handover'], true) ? ['request_transport_destination'] : []);
        if (!in_array($command_code, $allowed, true)) {
            wp_send_json_error(['message' => 'Dieser Funkbefehl passt nicht zur aktuellen Fahrzeugphase.'], 409);
        }

        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $now = (int) ($game_runtime['game_now_ts'] ?? time());
        $question = $rufname . ' von Leitstelle: ' . $commands[$command_code] . ', kommen.';
        $incident_meta = lsttraining_sim_decode_meta($event['incident_meta_json'] ?? '');
        $response = [
            'request_situation' => trim((string) ($event['lagemeldung'] ?? '')) !== ''
                ? 'Aktuelle Lage: ' . trim((string) $event['lagemeldung'])
                : 'Lage unverändert',
            'request_additional_resources' => !empty($incident_meta['pending_resource_request'])
                ? (string) ($incident_meta['pending_resource_request']['text'] ?? 'Weitere Kräfte erforderlich')
                : 'Derzeit keine weiteren Kräfte erforderlich',
            'request_transport_destination' => trim((string) ($meta['transport_hospital_name'] ?? '')) !== ''
                ? 'Transportziel ' . (string) $meta['transport_hospital_name']
                : 'Transportziel noch nicht bekannt',
            'request_notarzt_requirement' => !empty($incident_meta['notarzt_benoetigt'])
                ? 'Notarzt erforderlich'
                : 'Derzeit kein Notarzt erforderlich',
        ][$command_code];

        $pdo->beginTransaction();
        $question_id = lsttraining_sim_insert_dispatch_event($pdo, $einsatz_id, $question, [
            'event_type' => 'vehicle_radio_command',
            'radio_message_type' => 'dispatcher_question',
            'sender_type' => 'dispatch',
            'recipient_type' => 'vehicle',
            'command_code' => $command_code,
            'source_event_id' => $event_id,
            'status_id' => (int) ($meta['status_id'] ?? 0),
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => $rufname,
        ]);
        if (!lsttraining_sim_fire_phase_followups($pdo, $einsatz_id, $meta, $now, 'on_dispatcher_question', $command_code, $question_id)) {
            lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': ' . $response . ', Ende.', [
                'event_type' => 'vehicle_radio_response',
                'radio_message_type' => 'vehicle_response',
                'sender_type' => 'vehicle',
                'recipient_type' => 'dispatch',
                'reply_to_event_id' => $question_id,
                'command_code' => $command_code,
                'source_event_id' => $event_id,
                'status_id' => (int) ($meta['status_id'] ?? 0),
                'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                'rufname' => $rufname,
                'requires_ack' => false,
                'radio_base_at' => lsttraining_sim_time_string($now),
            ]);
        }
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success(['message' => 'Funkspruch gesendet.', 'einsatz_id' => $einsatz_id]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_vehicle_radio_command] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Funkspruch konnte nicht gesendet werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_recall_vehicle', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ($instanz_id <= 0 || $event_id <= 0) {
        wp_send_json_error(['message' => 'Rückrufdaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);
        lsttraining_sim_require_vehicle_delta_model($pdo, $instanz_id);

        $stmt = $pdo->prepare('
            SELECT ev.id, ev.instanz_einsatz_id, ev.meta_json, ie.instanz_id
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$event_id, $instanz_id, 'unit_report']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            wp_send_json_error(['message' => 'Fahrzeugzuordnung nicht gefunden.'], 404);
        }

        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'vehicle_alarm' || !empty($meta['cancelled_at'])) {
            wp_send_json_error(['message' => 'Dieses Fahrzeug kann nicht zurückgerufen werden.'], 409);
        }
        $phase = (string) ($meta['mission_phase'] ?? 'to_scene');
        if ($phase !== 'to_scene' || !empty($meta['arrived_at'])) {
            wp_send_json_error(['message' => 'Anfahrt kann nur vor dem Eintreffen abgebrochen werden.'], 409);
        }

        $status_id = (int) ($meta['status_id'] ?? 0);
        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
        $vehicle_state = $status_id > 0 ? lsttraining_sim_fetch_effective_vehicle_state($pdo, $instanz_id, $status_id) : null;
        $last = is_array($meta['last_position'] ?? null) ? $meta['last_position'] : [];
        $from_lat = is_numeric($vehicle_state['latitude'] ?? null) ? (float) $vehicle_state['latitude'] : (is_numeric($last['latitude'] ?? null) ? (float) $last['latitude'] : null);
        $from_lon = is_numeric($vehicle_state['longitude'] ?? null) ? (float) $vehicle_state['longitude'] : (is_numeric($last['longitude'] ?? null) ? (float) $last['longitude'] : null);
        if ($status_id <= 0 || $einsatz_id <= 0 || $from_lat === null || $from_lon === null) {
            wp_send_json_error(['message' => 'Fahrzeugposition fehlt für den Abbruch der Anfahrt.'], 409);
        }

        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $now = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));
        $meta['cancelled_at'] = $now;
        $meta['cancelled_by'] = (int) get_current_user_id();
        $meta['recall_requested_at'] = $now;
        $meta['return_completed_at'] = $now;
        $meta['mission_phase'] = 'available';
        $meta['assignment_status'] = 'cancelled';
        $meta['route_status'] = 'cancelled';
        $meta['route_coordinates'] = [];
        $meta['route_segments'] = [];
        $meta['route_distance_m'] = 0;
        $meta['route_duration_sec'] = 0;
        $meta['route_duration_normal_sec'] = 0;
        $meta['current_progress'] = 1;
        $meta['current_segment_index'] = 0;
        $meta['current_segment_progress'] = 1;
        $meta['last_position'] = [
            'latitude' => round($from_lat, 6),
            'longitude' => round($from_lon, 6),
        ];
        $meta['sondersignal_allowed'] = false;

        $pdo->beginTransaction();
        $event_update = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');
        $event_update->execute([lsttraining_sim_encode_meta($meta), $event_id]);
        lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
            'latitude' => $from_lat,
            'longitude' => $from_lon,
            'ziel_latitude' => null,
            'ziel_longitude' => null,
            'status' => 'einsatzbereit',
            'fms_status' => '1',
            'sondersignal' => 0,
            'bemerkung' => 'Anfahrt abgebrochen, Status 1, Zuordnung aufgehoben.',
        ]);
        lsttraining_sim_insert_unit_event($pdo, $einsatz_id, 'Leitstelle von ' . $rufname . ': Anfahrt abgebrochen, Zuordnung aufgehoben, Status 1, Ende.', [
            'event_type' => 'vehicle_recall_cancelled_assignment',
            'radio_message_type' => 'recall_confirmed',
            'sender_type' => 'vehicle',
            'recipient_type' => 'dispatch',
            'status_transition' => '1',
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => $rufname,
            'fms_status' => '1',
            'source_event_id' => $event_id,
            'assignment_status' => 'cancelled',
        ]);
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        wp_send_json_success([
            'einsatz_id' => $einsatz_id,
            'status_id' => $status_id,
            'event_id' => $event_id,
            'assignment_status' => 'cancelled',
            'fms_status' => '1',
            'message' => $rufname . ': Anfahrt abgebrochen, Zuordnung aufgehoben, Status 1.',
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_recall_vehicle] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Rückruf konnte nicht gefunkt werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_unassign_vehicle', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ($instanz_id <= 0 || $event_id <= 0) {
        wp_send_json_error(['message' => 'Zuordnungsdaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $stmt = $pdo->prepare('
            SELECT ev.id, ev.instanz_einsatz_id, ev.meta_json, ie.instanz_id
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$event_id, $instanz_id, 'unit_report']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            wp_send_json_error(['message' => 'Zuordnung nicht gefunden.'], 404);
        }

        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        $is_support = ($meta['event_type'] ?? '') === 'support_vehicle_alarm';
        if (($meta['event_type'] ?? '') !== 'vehicle_alarm' && !$is_support) {
            wp_send_json_error(['message' => 'Diese Meldung ist keine Fahrzeugzuordnung.'], 400);
        }
        if (!empty($meta['cancelled_at'])) {
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
            wp_send_json_success([
                'einsatz_id' => (int) $event['instanz_einsatz_id'],
                'message' => 'Zuordnung war bereits aufgehoben.',
            ]);
        }

        $status_id = (int) ($meta['status_id'] ?? 0);
        $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
        if ($is_support && $rufname === 'Fahrzeug') {
            $rufname = (string) (($meta['support_type'] ?? '') === 'police' ? 'Polizei' : 'Unterstützung');
        }
        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $now = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));

        if ($is_support && (string) ($meta['support_type'] ?? '') === 'neighbor') {
            $phase = (string) ($meta['mission_phase'] ?? 'to_scene');
            if (in_array($phase, ['available', 'returning'], true)) {
                lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
                wp_send_json_success([
                    'einsatz_id' => (int) $event['instanz_einsatz_id'],
                    'status_id' => 0,
                    'message' => $rufname . ' ist bereits auf Rückfahrt oder abgemeldet.',
                ]);
            }
            $last = is_array($meta['last_position'] ?? null) ? $meta['last_position'] : [];
            $home = is_array($meta['home_position'] ?? null) ? $meta['home_position'] : [];
            $from_lat = is_numeric($last['latitude'] ?? null) ? (float) $last['latitude'] : null;
            $from_lon = is_numeric($last['longitude'] ?? null) ? (float) $last['longitude'] : null;
            $to_lat = is_numeric($home['latitude'] ?? null) ? (float) $home['latitude'] : null;
            $to_lon = is_numeric($home['longitude'] ?? null) ? (float) $home['longitude'] : null;
            if ($from_lat === null || $from_lon === null || $to_lat === null || $to_lon === null) {
                wp_send_json_error(['message' => 'Heimat- oder Fahrzeugposition fehlt für die Rückfahrt.'], 409);
            }

            $route = lsttraining_sim_transport_route(
                $from_lat,
                $from_lon,
                $to_lat,
                $to_lon,
                lsttraining_sim_transport_is_air_unit($meta)
            );
            if (count($route['coordinates'] ?? []) < 2) {
                wp_send_json_error(['message' => 'Rückfahrt zur Heimatleitstelle konnte nicht geroutet werden.'], 409);
            }

            $meta['mission_phase'] = 'returning';
            $meta['assignment_status'] = 'returning';
            $meta['return_started_at'] = $now;
            $meta['phase_started_at'] = $now;
            $meta['route_coordinates'] = $route['coordinates'];
            $meta['route_segments'] = is_array($route['route_segments'] ?? null) ? $route['route_segments'] : [];
            $meta['route_distance_m'] = (int) ($route['distance_m'] ?? 0);
            $meta['route_duration_sec'] = (int) ($route['duration_sec'] ?? 0);
            $meta['route_duration_normal_sec'] = (int) ($route['duration_sec'] ?? 0);
            $meta['route_source'] = (string) ($route['route_source'] ?? '');
            $meta['current_progress'] = 0;
            $meta['current_segment_index'] = 0;
            $meta['current_segment_progress'] = 0;
            $meta['fms_status'] = '1';
            $meta['sondersignal'] = 0;

            $pdo->beginTransaction();
            $update_event = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');
            $update_event->execute([lsttraining_sim_encode_meta($meta), $event_id]);
            lsttraining_sim_insert_unit_event($pdo, (int) $event['instanz_einsatz_id'], 'Leitstelle von ' . $rufname . ': Fremdfahrzeug meldet sich ab und fährt zur Heimatleitstelle zurück, Status 1, Ende.', [
                'event_type' => 'support_vehicle_returning_home',
                'support_type' => 'neighbor',
                'support_id' => (string) ($meta['support_id'] ?? ''),
                'foreign_unit' => true,
                'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                'rufname' => $rufname,
                'fms_status' => '1',
                'source_event_id' => $event_id,
            ]);
            $pdo->commit();
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
            wp_send_json_success([
                'einsatz_id' => (int) $event['instanz_einsatz_id'],
                'status_id' => 0,
                'message' => $rufname . ' fährt zur Heimatleitstelle zurück.',
            ]);
        }

        $meta['cancelled_at'] = $now;
        $meta['cancelled_by'] = (int) get_current_user_id();
        $meta['assignment_status'] = 'cancelled';

        $pdo->beginTransaction();
        $update_event = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');
        $update_event->execute([lsttraining_sim_encode_meta($meta), $event_id]);

        if ($status_id > 0) {
            lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                'status' => 'frei',
                'fms_status' => '2',
                'sondersignal' => 0,
                'ziel_latitude' => null,
                'ziel_longitude' => null,
                'bemerkung' => 'Zuordnung aufgehoben.',
            ]);
        }

        lsttraining_sim_insert_unit_event($pdo, (int) $event['instanz_einsatz_id'], $rufname . ' abgezogen.', [
            'event_type' => $is_support ? 'support_vehicle_unassigned' : 'vehicle_unassigned',
            'support_type' => $is_support ? (string) ($meta['support_type'] ?? '') : '',
            'support_id' => $is_support ? (string) ($meta['support_id'] ?? '') : '',
            'status_id' => $status_id,
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'rufname' => $rufname,
            'source_event_id' => $event_id,
        ]);
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        wp_send_json_success([
            'einsatz_id' => (int) $event['instanz_einsatz_id'],
            'status_id' => $status_id,
            'message' => $rufname . ' wurde vom Einsatz gelöst.',
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_unassign_vehicle] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Zuordnung konnte nicht aufgehoben werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_ack_unit_report', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ($instanz_id <= 0 || $event_id <= 0) {
        wp_send_json_error(['message' => 'Rückmeldungsdaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $stmt = $pdo->prepare('
            SELECT ev.id, ev.instanz_einsatz_id, ev.meta_json, ie.instanz_id
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$event_id, $instanz_id, 'unit_report']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            wp_send_json_error(['message' => 'Rückmeldung nicht gefunden.'], 404);
        }

        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'situation_report' || empty($meta['requires_ack'])) {
            wp_send_json_error(['message' => 'Diese Rückmeldung kann nicht bestätigt werden.'], 400);
        }
        if (!empty($meta['acknowledged_at'])) {
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
            wp_send_json_success([
                'message' => 'Rückmeldung war bereits bestätigt.',
                'einsatz_id' => (int) ($event['instanz_einsatz_id'] ?? 0),
                'event_id' => $event_id,
            ]);
        }

        $status_id = (int) ($meta['status_id'] ?? 0);
        $rufname = (string) ($meta['rufname'] ?? 'Fahrzeug');
        $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
        $meta['acknowledged_at'] = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));
        $meta['acknowledged_by'] = (int) get_current_user_id();
        $meta['acknowledged_fms_status'] = '4';

        $pdo->beginTransaction();
        $update_event = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');
        $update_event->execute([lsttraining_sim_encode_meta($meta), $event_id]);

        if ($status_id > 0) {
            lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
                'fms_status' => '4',
                'status' => 'besetzt',
                'sondersignal' => 0,
                'bemerkung' => 'Am Einsatzort.',
            ]);
        }

        lsttraining_sim_insert_unit_event($pdo, (int) $event['instanz_einsatz_id'], 'Leitstelle von ' . $rufname . ': Lagemeldung übermittelt, Status 4, Ende.', [
            'event_type' => 'fms_update',
            'radio_message_type' => 'situation_acknowledged',
            'sender_type' => 'vehicle',
            'recipient_type' => 'dispatch',
            'status_transition' => '4',
            'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
            'status_id' => $status_id,
            'rufname' => $rufname,
            'fms_status' => '4',
            'direction' => 'down',
            'source_event_id' => $event_id,
            'source' => 'unit_report_ack',
        ]);
        $pdo->commit();
        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);

        wp_send_json_success([
            'message' => 'Lagemeldung bestätigt.',
            'einsatz_id' => (int) ($event['instanz_einsatz_id'] ?? 0),
            'event_id' => $event_id,
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_ack_unit_report] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Lagemeldung konnte nicht bestätigt werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_open_unit_report', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ($instanz_id <= 0 || $event_id <= 0) {
        wp_send_json_error(['message' => 'Sprechwunschdaten unvollständig.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);

        $stmt = $pdo->prepare('
            SELECT ev.id, ev.instanz_einsatz_id, ev.text, ev.meta_json
            FROM instanz_einsatz_events ev
            INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
            WHERE ev.id = ? AND ie.instanz_id = ? AND ev.kind = ?
            LIMIT 1
        ');
        $stmt->execute([$event_id, $instanz_id, 'unit_report']);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            wp_send_json_error(['message' => 'Sprechwunsch nicht gefunden.'], 404);
        }

        $meta = lsttraining_sim_decode_meta($event['meta_json'] ?? '');
        if (($meta['event_type'] ?? '') !== 'situation_report' || empty($meta['requires_ack'])) {
            wp_send_json_error(['message' => 'Diese Rückmeldung ist kein Sprechwunsch.'], 400);
        }
        $einsatz_id = (int) ($event['instanz_einsatz_id'] ?? 0);
        if (empty($meta['opened_at'])) {
            $rufname = trim((string) ($meta['rufname'] ?? 'Fahrzeug'));
            $report_text = trim((string) ($meta['report_text'] ?? ''));
            if ($report_text === '') {
                $report_text = trim((string) ($event['text'] ?? ''));
            }
            if ($report_text === '' || strcasecmp($report_text, 'Sprechwunsch') === 0) {
                $report_text = $rufname . ': Lagemeldung folgt.';
            }

            $game_runtime = lsttraining_sim_current_game_runtime($pdo, $instanz_id);
            $meta['opened_at'] = (string) ($game_runtime['sim_now'] ?? wp_date('Y-m-d H:i:s'));
            $meta['opened_by'] = (int) get_current_user_id();
            $meta['visible_text_pending'] = false;
            unset($meta['radio_visible_at'], $meta['radio_delay_sec']);
            $meta = lsttraining_sim_meta_with_radio_delay($meta, $meta['opened_at']);

            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE instanz_einsatz_events SET text = ?, meta_json = ? WHERE id = ?');
            $update->execute([$report_text, lsttraining_sim_encode_meta($meta), $event_id]);

            $dispatch = $pdo->prepare('
                INSERT INTO instanz_einsatz_events (instanz_einsatz_id, kind, text, meta_json)
                VALUES (?, ?, ?, ?)
            ');
            $dispatch->execute([
                $einsatz_id,
                'dispatcher_question',
                $rufname . ' von Leitstelle: Kommen.',
                lsttraining_sim_encode_meta([
                    'event_type' => 'unit_report_opened',
                    'radio_message_type' => 'speak_request_opened',
                    'sender_type' => 'dispatch',
                    'recipient_type' => 'vehicle',
                    'source_event_id' => $event_id,
                    'status_id' => (int) ($meta['status_id'] ?? 0),
                    'fahrzeug_id' => (int) ($meta['fahrzeug_id'] ?? 0),
                    'rufname' => $rufname,
                    'user_id' => (int) get_current_user_id(),
                ]),
            ]);
            $pdo->commit();
        }

        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success([
            'message' => 'Sprechwunsch geöffnet.',
            'einsatz_id' => $einsatz_id,
            'event_id' => $event_id,
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][sim_open_unit_report] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Sprechwunsch konnte nicht geöffnet werden: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_update_einsatz_state', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $einsatz_id = isset($_POST['einsatz_id']) ? absint($_POST['einsatz_id']) : 0;
    $state = isset($_POST['state']) ? sanitize_key(wp_unslash($_POST['state'])) : '';
    $no_vehicles_sent = !empty($_POST['no_vehicles_sent']);
    if ($einsatz_id <= 0 || !in_array($state, ['new', 'active', 'closed'], true)) {
        wp_send_json_error(['message' => 'Ungültiger Einsatzstatus.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        $stmt = $pdo->prepare('SELECT instanz_id FROM instanz_einsaetze WHERE id = ? LIMIT 1');
        $stmt->execute([$einsatz_id]);
        $instanz_id = (int) $stmt->fetchColumn();
        if (!$instanz_id || !lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diesen Einsatz.'], 403);
        }
        lsttraining_sim_guard_not_paused($pdo, $instanz_id);
        if ($state === 'closed') {
            $close_blockers = lsttraining_sim_incident_close_blockers($pdo, $einsatz_id);
            if ($close_blockers) {
                wp_send_json_error([
                    'message' => 'Einsatz kann noch nicht abgeschlossen werden: ' . implode(' ', $close_blockers),
                    'close_blockers' => $close_blockers,
                ], 409);
            }
        }

        $update = $pdo->prepare('UPDATE instanz_einsaetze SET state = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$state, $einsatz_id]);
        if ($state === 'closed' && $no_vehicles_sent) {
            $event = $pdo->prepare('
                INSERT INTO instanz_einsatz_events (instanz_einsatz_id, kind, text, meta_json)
                VALUES (?, ?, ?, ?)
            ');
            $event->execute([
                $einsatz_id,
                'dispatcher_note',
                'Keine Fahrzeuge geschickt.',
                lsttraining_sim_encode_meta([
                    'event_type' => 'no_vehicles_sent',
                    'user_id' => (int) get_current_user_id(),
                ]),
            ]);
        }

        lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        wp_send_json_success(['einsatz_id' => $einsatz_id, 'state' => $state]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_update_einsatz_state] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Einsatzstatus konnte nicht geändert werden.'], 500);
    }
});

function lsttraining_sim_workspace_department_config(): array {
    foreach ([LSTTRAINING_PATH . 'data/departments.json', LSTTRAINING_PATH . 'includes/departments.json'] as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    return [];
}

function lsttraining_sim_workspace_json_array($value): array {
    if (is_array($value)) {
        return $value;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (strpos($raw, ',') !== false) {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($item): bool {
            return $item !== '';
        }));
    }

    return [$raw];
}

function lsttraining_sim_workspace_table_columns(PDO $pdo, string $table): array {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $columns = [];
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            if (!empty($row['Field'])) {
                $columns[(string) $row['Field']] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        error_log('[LSTtraining][workspace_table_columns] ' . $table . ': ' . $e->getMessage());
        $cache[$table] = [];
        return [];
    }
}

function lsttraining_sim_workspace_department_code($value): string {
    $code = strtoupper(trim((string) $value));
    return preg_replace('/[^A-Z0-9_]/', '', $code) ?: '';
}

function lsttraining_sim_workspace_department_coordinate(array $value, ?float $fallback_lat, ?float $fallback_lon): ?array {
    $lat = $value['Lat'] ?? ($value['lat'] ?? ($value['latitude'] ?? $fallback_lat));
    $lon = $value['Long'] ?? ($value['long'] ?? ($value['lon'] ?? ($value['lng'] ?? ($value['longitude'] ?? $fallback_lon))));
    $lat = is_numeric($lat) ? (float) $lat : null;
    $lon = is_numeric($lon) ? (float) $lon : null;

    if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }

    return ['latitude' => $lat, 'longitude' => $lon];
}

function lsttraining_sim_workspace_department_details($value, ?float $fallback_lat = null, ?float $fallback_lon = null): array {
    $raw = lsttraining_sim_workspace_json_array($value);
    $codes = [];
    $locations = [];

    $add = static function ($code, $location = null) use (&$codes, &$locations): void {
        $code = lsttraining_sim_workspace_department_code($code);
        if ($code === '') {
            return;
        }
        $codes[$code] = true;
        if (is_array($location)) {
            $locations[$code] = $location;
        }
    };

    foreach ($raw as $key => $department) {
        if (!is_int($key) && !ctype_digit((string) $key)) {
            $add($key, is_array($department) ? lsttraining_sim_workspace_department_coordinate($department, $fallback_lat, $fallback_lon) : null);
            continue;
        }

        if (is_string($department) || is_numeric($department)) {
            $add($department);
            continue;
        }

        if (!is_array($department)) {
            continue;
        }

        $explicit = $department['code'] ?? ($department['department'] ?? ($department['label'] ?? null));
        if ($explicit !== null) {
            $add($explicit, lsttraining_sim_workspace_department_coordinate($department, $fallback_lat, $fallback_lon));
            continue;
        }

        if (count($department) === 1) {
            $code = array_key_first($department);
            $payload = $department[$code];
            $add($code, is_array($payload) ? lsttraining_sim_workspace_department_coordinate($payload, $fallback_lat, $fallback_lon) : null);
        }
    }

    return [
        'codes' => array_keys($codes),
        'locations' => $locations,
    ];
}

add_action('wp_ajax_lsttraining_sim_get_closed_incidents', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $stmt = $pdo->prepare("
            SELECT
                ie.id,
                ie.instanz_id,
                ie.leitstelle_id,
                ie.source,
                ie.source_id,
                ie.einsatzart,
                ie.einsatztyp,
                ie.latitude,
                ie.longitude,
                ie.poi_type,
                ie.poi_name_snapshot,
                ie.caller_text,
                ie.lagemeldung,
                ie.state,
                ie.meta_json,
                ie.created_at,
                ie.updated_at,
                e.title AS template_title,
                e.description AS template_description
            FROM instanz_einsaetze ie
            LEFT JOIN einsaetze e ON ie.source = 'template' AND e.id = ie.source_id
            WHERE ie.instanz_id = ? AND ie.state = 'closed'
            ORDER BY COALESCE(ie.updated_at, ie.created_at) DESC, ie.id DESC
            LIMIT 120
        ");
        $stmt->execute([$instanz_id]);

        $items = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $meta = lsttraining_sim_workspace_json_array($row['meta_json'] ?? '');
            unset($row['meta_json']);
            $row['meta'] = $meta;
            $row['latitude'] = $row['latitude'] !== null ? (float) $row['latitude'] : null;
            $row['longitude'] = $row['longitude'] !== null ? (float) $row['longitude'] : null;
            $row['title'] = trim((string) ($row['template_title'] ?? '')) !== ''
                ? (string) $row['template_title']
                : trim((string) (($row['einsatzart'] ?? '') . ' - ' . ($row['einsatztyp'] ?? 'Einsatz')));
            $row['description'] = (string) ($row['template_description'] ?? '');
            unset($row['template_title']);
            unset($row['template_description']);
            $row = lsttraining_sim_enrich_incident_motorway_meta($pdo, $row);
            $row['display_address'] = lsttraining_sim_display_address_for_incident($row);
            $row['polizei_verstaendigen'] = !empty($row['meta']['polizei_verstaendigen']);
            $items[] = $row;
        }

        wp_send_json_success(['items' => $items]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_closed_incidents] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Erledigte Einsätze konnten nicht geladen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_get_workspace_hospitals', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $instance = lsttraining_sim_fetch_instance_context($pdo, $instanz_id, (int) get_current_user_id());
        $leitstelle_id = (int) ($instance['leitstelle_id'] ?? 0);
        $leitstelle_columns = lsttraining_sim_workspace_table_columns($pdo, 'leitstellen');
        $available_raw = [];
        if (!empty($leitstelle_columns['available_hospitals'])) {
            $available_stmt = $pdo->prepare('SELECT available_hospitals FROM leitstellen WHERE id = ? LIMIT 1');
            $available_stmt->execute([$leitstelle_id]);
            $available_raw = lsttraining_sim_workspace_json_array($available_stmt->fetchColumn());
        }
        $available_ids = [];
        $available_poi_ids = [];
        foreach ($available_raw as $key => $value) {
            if (is_array($value)) {
                $value = $value['id']
                    ?? ($value['hospital_id']
                    ?? ($value['krankenhaus_id']
                    ?? ($value['poi_id'] ?? '')));
            } elseif ($value === true) {
                $value = $key;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (ctype_digit($value)) {
                $available_ids[] = (int) $value;
            } else {
                $available_poi_ids[] = $value;
            }
        }
        $available_ids = array_values(array_unique(array_filter($available_ids)));
        $available_poi_ids = array_values(array_unique($available_poi_ids));

        $hospital_columns = lsttraining_sim_workspace_table_columns($pdo, 'krankenhaeuser');
        $select_parts = [];
        foreach ([
            'id',
            'poi_id',
            'name',
            'latitude',
            'longitude',
            'versorgungsstufe',
            'trauma_level',
            'helipad',
            'departments',
        ] as $column) {
            $select_parts[] = !empty($hospital_columns[$column])
                ? '`' . $column . '`'
                : 'NULL AS `' . $column . '`';
        }

        $sql = 'SELECT ' . implode(', ', $select_parts) . ' FROM krankenhaeuser';
        $params = [];
        $where = [];
        if ($available_ids && !empty($hospital_columns['id'])) {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($available_ids), '?')) . ')';
            $params = array_merge($params, $available_ids);
        }
        if ($available_poi_ids && !empty($hospital_columns['poi_id'])) {
            $where[] = 'poi_id IN (' . implode(',', array_fill(0, count($available_poi_ids), '?')) . ')';
            $params = array_merge($params, $available_poi_ids);
        }
        if ($where) {
            $sql .= ' WHERE (' . implode(' OR ', $where) . ')';
        }
        $sql .= ' ORDER BY name ASC LIMIT 500';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows && $where) {
            $stmt = $pdo->query('SELECT ' . implode(', ', $select_parts) . ' FROM krankenhaeuser ORDER BY name ASC LIMIT 500');
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }
        $items = [];
        foreach ($rows as $row) {
            $lat = is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null;
            $lon = is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null;
            $departments = lsttraining_sim_workspace_department_details($row['departments'] ?? '[]', $lat, $lon);
            $first_department_location = $departments['locations'] ? reset($departments['locations']) : null;
            if (($lat === null || $lon === null || ($lat === 0.0 && $lon === 0.0)) && is_array($first_department_location)) {
                $lat = (float) $first_department_location['latitude'];
                $lon = (float) $first_department_location['longitude'];
            } elseif ($lat === 0.0 && $lon === 0.0) {
                $lat = null;
                $lon = null;
            }
            $row['departments'] = $departments['codes'];
            $row['department_locations'] = $departments['locations'];
            $row['latitude'] = $lat;
            $row['longitude'] = $lon;
            $row['trauma_level'] = (int) ($row['trauma_level'] ?? 0);
            $row['helipad'] = (int) ($row['helipad'] ?? 0);
            if ($row['id'] !== null && $row['name'] !== null) {
                $items[] = $row;
            }
        }

        wp_send_json_success([
            'items' => $items,
            'departments' => lsttraining_sim_workspace_department_config(),
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_workspace_hospitals] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Krankenhäuser konnten nicht geladen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_get_workspace_pois', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $instance = lsttraining_sim_fetch_instance_context($pdo, $instanz_id, (int) get_current_user_id());
        $stmt = $pdo->prepare('
            SELECT id, poi_type, name, comment, genus, latitude, longitude
            FROM leitstellen_pois
            WHERE leitstelle_id = ?
            ORDER BY name ASC, id ASC
            LIMIT 1000
        ');
        $stmt->execute([(int) ($instance['leitstelle_id'] ?? 0)]);
        $items = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $row['latitude'] = (float) $row['latitude'];
            $row['longitude'] = (float) $row['longitude'];
            $items[] = $row;
        }

        wp_send_json_success(['items' => $items]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_workspace_pois] ' . $e->getMessage());
        wp_send_json_error(['message' => 'POIs konnten nicht geladen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_sim_get_workspace_osm_layers', function () {
    lsttraining_sim_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        if (!lsttraining_sim_user_can_access_instance($pdo, $instanz_id, (int) get_current_user_id())) {
            wp_send_json_error(['message' => 'Kein Zugriff auf diese Simulation.'], 403);
        }

        $instance = lsttraining_sim_fetch_instance_context($pdo, $instanz_id, (int) get_current_user_id());
        $stmt = $pdo->prepare('
            SELECT
                s.layer_key,
                COUNT(*) AS tile_count,
                MAX(m.updated_at) AS updated_at,
                SUM(COALESCE(m.feature_count, 0)) AS feature_count
            FROM leitstelle_tile_scope s
            LEFT JOIN leitstellen_osm_layers m
                ON m.layer_key = s.layer_key
                AND m.tile_z = s.tile_z
                AND m.tile_x = s.tile_x
                AND m.tile_y = s.tile_y
            WHERE s.leitstelle_id = ?
            GROUP BY s.layer_key
            ORDER BY s.layer_key ASC
        ');
        $stmt->execute([(int) ($instance['leitstelle_id'] ?? 0)]);

        wp_send_json_success(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][sim_get_workspace_osm_layers] ' . $e->getMessage());
        wp_send_json_error(['message' => 'OSM-Layer konnten nicht geladen werden.'], 500);
    }
});
