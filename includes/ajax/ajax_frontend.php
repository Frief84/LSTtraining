<?php
if (!defined('ABSPATH')) { exit(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/simulation/weather.php';

function lsttraining_frontend_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_frontend_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_frontend_column_type(PDO $pdo, string $table, string $column): string {
    try {
        $stmt = $pdo->prepare('
            SELECT DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$table, $column]);
        return strtolower((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function lsttraining_frontend_ensure_instance_columns(PDO $pdo): void {
    $instance_columns = [
        'settings_json' => 'ALTER TABLE spielinstanzen ADD COLUMN settings_json LONGTEXT NULL AFTER ist_aktiv',
        'started_at'    => 'ALTER TABLE spielinstanzen ADD COLUMN started_at DATETIME NULL AFTER settings_json',
        'sim_state'     => "ALTER TABLE spielinstanzen ADD COLUMN sim_state ENUM('created','running','paused','ended') NOT NULL DEFAULT 'created' AFTER started_at",
    ];

    foreach ($instance_columns as $column => $alter_sql) {
        if (!lsttraining_frontend_column_exists($pdo, 'spielinstanzen', $column)) {
            $pdo->exec($alter_sql);
        }
    }

    if (lsttraining_frontend_column_type($pdo, 'spielinstanzen', 'settings_json') !== 'longtext') {
        $pdo->exec('ALTER TABLE spielinstanzen MODIFY COLUMN settings_json LONGTEXT NULL');
    }

    // Ältere Installationen haben diese Spalte ggf. noch nicht; die Frontend-Join-Logik nutzt sie.
    if (lsttraining_frontend_table_exists($pdo, 'instanz_user') && !lsttraining_frontend_column_exists($pdo, 'instanz_user', 'connected')) {
        $pdo->exec('ALTER TABLE instanz_user ADD COLUMN connected TINYINT(1) NULL DEFAULT 1 AFTER rolle');
    }

    if (function_exists('lsttraining_instance_lifecycle_ensure_schema')) {
        lsttraining_instance_lifecycle_ensure_schema($pdo);
    }
}

function lsttraining_frontend_required_schema_errors(PDO $pdo): array {
    $errors = [];
    $required_tables = [
        'spielinstanzen',
        'instanz_user',
        'fahrzeug_status',
        'instanz_fahrzeug_status',
        'leitstellen',
        'fahrzeuge',
        'wachen',
        'wache_leitstellen',
    ];

    foreach ($required_tables as $table) {
        if (!lsttraining_frontend_table_exists($pdo, $table)) {
            $errors[] = 'Datenbanktabelle fehlt: ' . $table;
        }
    }

    $required_columns = [
        'spielinstanzen' => ['leitstelle_id', 'name', 'erstellt_am', 'ist_aktiv', 'settings_json', 'started_at', 'sim_state', 'owner_user_id', 'last_activity_at', 'retention_notice_sent_at', 'retention_delete_at'],
        'instanz_user' => ['instanz_id', 'user_id', 'rolle', 'connected'],
        'fahrzeug_status' => ['instanz_id', 'fahrzeug_id', 'wache_id', 'latitude', 'longitude', 'status', 'fms_status', 'sondersignal'],
        'instanz_fahrzeug_status' => ['instanz_id', 'fahrzeug_status_id', 'latitude', 'longitude', 'ziel_latitude', 'ziel_longitude', 'status', 'fms_status', 'sondersignal', 'bemerkung'],
        'leitstellen' => ['id', 'name', 'ort', 'bundesland'],
        'fahrzeuge' => ['id', 'wache_id', 'latitude', 'longitude', 'status', 'fms_status', 'sondersignal'],
        'wachen' => ['id', 'latitude', 'longitude'],
        'wache_leitstellen' => ['wache_id', 'leitstelle_id'],
    ];

    foreach ($required_columns as $table => $columns) {
        if (!lsttraining_frontend_table_exists($pdo, $table)) {
            continue;
        }
        foreach ($columns as $column) {
            if (!lsttraining_frontend_column_exists($pdo, $table, $column)) {
                $errors[] = 'Datenbankspalte fehlt: ' . $table . '.' . $column;
            }
        }
    }

    return $errors;
}

function lsttraining_frontend_season_from_date(string $date): string {
    $month = (int) substr($date, 5, 2);

    if ($month >= 3 && $month <= 5) {
        return 'spring';
    }
    if ($month >= 6 && $month <= 8) {
        return 'summer';
    }
    if ($month >= 9 && $month <= 11) {
        return 'autumn';
    }

    return 'winter';
}

function lsttraining_frontend_normalize_bool(string $key): bool {
    return isset($_POST[$key]) && in_array((string) wp_unslash($_POST[$key]), ['1', 'true', 'on'], true);
}

function lsttraining_frontend_current_page_url(): string {
    $raw_url = isset($_POST['current_url']) ? esc_url_raw(wp_unslash($_POST['current_url'])) : '';
    if (!$raw_url) {
        $raw_url = wp_get_referer() ?: home_url('/');
    }

    return remove_query_arg('lst_instance', $raw_url);
}

function lsttraining_frontend_mode_label(?string $mode): string {
    $labels = [
        'singleplayer'       => 'Einzelspieler',
        'multiplayer'        => 'Multiplayer',
        'einsatzleiter'      => 'Einsatzleiter',
        'leiter'             => 'Einsatzleiter',
        'leiter_multiplayer' => 'Einsatzleiter',
    ];

    return $labels[$mode ?? ''] ?? (string) $mode;
}

function lsttraining_frontend_decode_settings(?string $settings_json): array {
    $settings = json_decode((string) $settings_json, true);
    return is_array($settings) ? $settings : [];
}

function lsttraining_frontend_redirect_url(int $instanz_id): string {
    if (function_exists('lsttraining_frontend_simulation_url')) {
        return lsttraining_frontend_simulation_url($instanz_id, lsttraining_frontend_current_page_url());
    }

    return add_query_arg('lst_instance', $instanz_id, lsttraining_frontend_current_page_url());
}

function lsttraining_frontend_check_nonce(): void {
    if (!check_ajax_referer('lsttraining_frontend_start', 'nonce', false)) {
        wp_send_json_error(['message' => 'Ungültiger Sicherheits-Token.'], 403);
    }
}

function lsttraining_frontend_difficulty_profiles(): array {
    return [
        'easy' => [
            'label' => 'Einsteiger',
            'description' => 'Ruhiger Einstieg mit mehr Zeit zwischen neuen Lagen.',
            'auto_spawn' => true,
            'spawn_mode' => 'dynamic',
            'base_interval_min_sec' => 180,
            'base_interval_max_sec' => 480,
            'leitstelle_load_factor' => 0.75,
            'max_active_einsaetze' => 4,
            'spawn_interval_sec' => 180,
        ],
        'normal' => [
            'label' => 'Normal',
            'description' => 'Ausgewogene Belastung für reguläres Training.',
            'auto_spawn' => true,
            'spawn_mode' => 'dynamic',
            'base_interval_min_sec' => 90,
            'base_interval_max_sec' => 300,
            'leitstelle_load_factor' => 1.0,
            'max_active_einsaetze' => 6,
            'spawn_interval_sec' => 120,
        ],
        'hard' => [
            'label' => 'Anspruchsvoll',
            'description' => 'Kürzere Abstände und mehr parallele Einsätze.',
            'auto_spawn' => true,
            'spawn_mode' => 'dynamic',
            'base_interval_min_sec' => 60,
            'base_interval_max_sec' => 210,
            'leitstelle_load_factor' => 1.25,
            'max_active_einsaetze' => 8,
            'spawn_interval_sec' => 90,
        ],
        'realistic' => [
            'label' => 'Realistisch',
            'description' => 'Hohe Grundlast mit stärkerem Simulationsdruck.',
            'auto_spawn' => true,
            'spawn_mode' => 'dynamic',
            'base_interval_min_sec' => 45,
            'base_interval_max_sec' => 180,
            'leitstelle_load_factor' => 1.5,
            'max_active_einsaetze' => 10,
            'spawn_interval_sec' => 60,
        ],
    ];
}

function lsttraining_frontend_difficulty_settings(?string $difficulty): array {
    $difficulty = sanitize_key((string) $difficulty);
    $profiles = lsttraining_frontend_difficulty_profiles();

    if (!isset($profiles[$difficulty])) {
        $difficulty = 'normal';
    }

    return array_merge(['key' => $difficulty], $profiles[$difficulty]);
}

function lsttraining_frontend_prepare_settings(): array {
    $allowed_modes = ['singleplayer', 'multiplayer', 'einsatzleiter'];
    $allowed_seasons = ['spring', 'summer', 'autumn', 'winter'];
    $allowed_weather = ['auto', 'clear', 'cloudy', 'rain', 'snow', 'storm', 'windy', 'fog', 'cold', 'hot'];
    $difficulty_settings = lsttraining_frontend_difficulty_settings(
        get_user_meta((int) get_current_user_id(), 'lsttraining_sim_difficulty', true)
    );

    $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'singleplayer';
    if (!in_array($mode, $allowed_modes, true)) {
        wp_send_json_error(['message' => 'Ungültiger Spielmodus.'], 400);
    }

    $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
    $start_time = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{2}:\d{2}$/', $start_time)) {
        wp_send_json_error(['message' => 'Startdatum oder Startzeit ist ungültig.'], 400);
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $start_date . ' ' . $start_time, wp_timezone());
    if (!$dt || $dt->format('Y-m-d') !== $start_date || $dt->format('H:i') !== $start_time) {
        wp_send_json_error(['message' => 'Startdatum oder Startzeit ist ungültig.'], 400);
    }

    $season_override = isset($_POST['season_override']) ? sanitize_key(wp_unslash($_POST['season_override'])) : 'auto';
    $season_mode = 'auto';
    $season = lsttraining_frontend_season_from_date($start_date);
    if ($season_override !== 'auto') {
        if (!in_array($season_override, $allowed_seasons, true)) {
            wp_send_json_error(['message' => 'Ungültige Jahreszeit.'], 400);
        }
        $season_mode = 'manual';
        $season = $season_override;
    }

    $weather = isset($_POST['weather']) ? sanitize_key(wp_unslash($_POST['weather'])) : 'auto';
    if (!in_array($weather, $allowed_weather, true)) {
        wp_send_json_error(['message' => 'Ungültiges Wetter.'], 400);
    }

    $max_active = isset($_POST['max_active_einsaetze'])
        ? absint($_POST['max_active_einsaetze'])
        : (int) $difficulty_settings['max_active_einsaetze'];
    $spawn_interval = isset($_POST['spawn_interval_sec'])
        ? absint($_POST['spawn_interval_sec'])
        : (int) $difficulty_settings['spawn_interval_sec'];
    $spawn_mode = isset($_POST['spawn_mode'])
        ? sanitize_key(wp_unslash($_POST['spawn_mode']))
        : (string) $difficulty_settings['spawn_mode'];
    if (!in_array($spawn_mode, ['fixed', 'dynamic'], true)) {
        $spawn_mode = 'dynamic';
    }
    $base_interval_min = isset($_POST['base_interval_min_sec'])
        ? absint($_POST['base_interval_min_sec'])
        : (int) $difficulty_settings['base_interval_min_sec'];
    $base_interval_max = isset($_POST['base_interval_max_sec'])
        ? absint($_POST['base_interval_max_sec'])
        : (int) $difficulty_settings['base_interval_max_sec'];
    $base_interval_min = max(10, $base_interval_min ?: 60);
    $base_interval_max = max($base_interval_min, $base_interval_max ?: 300);
    $leitstelle_load_factor = isset($_POST['leitstelle_load_factor'])
        ? (float) str_replace(',', '.', (string) wp_unslash($_POST['leitstelle_load_factor']))
        : (float) $difficulty_settings['leitstelle_load_factor'];
    $auto_spawn = isset($_POST['auto_spawn'])
        ? lsttraining_frontend_normalize_bool('auto_spawn')
        : (bool) $difficulty_settings['auto_spawn'];

    return [
        'mode'                 => $mode,
        'start_date'           => $start_date,
        'start_time'           => $start_time,
        'started_at'           => $dt->format('Y-m-d H:i:s'),
        'season'               => $season,
        'season_mode'          => $season_mode,
        'weather'              => $weather,
        'demo_mode'            => isset($_POST['demo_mode']) ? lsttraining_frontend_normalize_bool('demo_mode') : false,
        'auto_spawn'           => $auto_spawn,
        'max_active_einsaetze' => max(1, min(999, $max_active ?: 5)),
        'spawn_interval_sec'   => max(10, min(86400, $spawn_interval ?: 120)),
        'spawn_mode'           => $spawn_mode,
        'base_interval_min_sec' => $base_interval_min,
        'base_interval_max_sec' => $base_interval_max,
        'leitstelle_load_factor' => max(0.1, min(10.0, $leitstelle_load_factor ?: 1.0)),
        'next_auto_spawn_at'    => null,
        'last_auto_spawn_at'    => null,
        'last_spawn_delay_sec'  => null,
        'difficulty'            => (string) $difficulty_settings['key'],
    ];
}

function lsttraining_frontend_fetch_instance(PDO $pdo, int $instanz_id, int $user_id): ?array {
    lsttraining_frontend_ensure_instance_columns($pdo);

    $stmt = $pdo->prepare('
        SELECT
            si.id,
            si.name,
            si.leitstelle_id,
            si.started_at,
            si.settings_json,
            si.sim_state,
            l.name AS leitstelle_name,
            l.ort AS leitstelle_ort,
            l.bundesland AS leitstelle_bundesland,
            iu.rolle AS user_rolle,
            (
                SELECT COUNT(*)
                FROM fahrzeug_status fs
                WHERE fs.instanz_id = si.id
            ) AS fahrzeuge_count
        FROM spielinstanzen si
        INNER JOIN leitstellen l ON l.id = si.leitstelle_id
        LEFT JOIN instanz_user iu ON iu.instanz_id = si.id AND iu.user_id = ?
        WHERE si.id = ?
        LIMIT 1
    ');
    $stmt->execute([$user_id, $instanz_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (!$row['user_rolle'] && !current_user_can('manage_options'))) {
        return null;
    }

    $row['settings'] = lsttraining_frontend_decode_settings($row['settings_json'] ?? null);
    $row['mode_label'] = lsttraining_frontend_mode_label($row['settings']['mode'] ?? null);
    unset($row['settings_json']);

    return $row;
}

function lsttraining_frontend_fetch_open_instances(PDO $pdo, int $user_id, array &$diagnostics = []): array {
    try {
        lsttraining_frontend_ensure_instance_columns($pdo);
    } catch (Throwable $e) {
        $diagnostics[] = 'Schema-Prüfung fehlgeschlagen: ' . $e->getMessage();
        error_log('[LSTtraining][frontend_open_instances_schema] ' . $e->getMessage());
    }

    if (!lsttraining_frontend_table_exists($pdo, 'spielinstanzen')) {
        $diagnostics[] = 'Datenbanktabelle fehlt: spielinstanzen';
        return [];
    }

    if (!lsttraining_frontend_table_exists($pdo, 'leitstellen')) {
        $diagnostics[] = 'Datenbanktabelle fehlt: leitstellen';
        return [];
    }

    if (!lsttraining_frontend_column_exists($pdo, 'spielinstanzen', 'settings_json')) {
        $diagnostics[] = 'Datenbankspalte fehlt: spielinstanzen.settings_json';
        return [];
    }

    $has_started_at = lsttraining_frontend_column_exists($pdo, 'spielinstanzen', 'started_at');
    $has_sim_state = lsttraining_frontend_column_exists($pdo, 'spielinstanzen', 'sim_state');
    $has_instanz_user = lsttraining_frontend_table_exists($pdo, 'instanz_user');
    $has_connected = $has_instanz_user && lsttraining_frontend_column_exists($pdo, 'instanz_user', 'connected');
    $has_fahrzeug_status = lsttraining_frontend_table_exists($pdo, 'fahrzeug_status');
    $has_instanz_fahrzeug_status = lsttraining_frontend_table_exists($pdo, 'instanz_fahrzeug_status');
    if (!$has_started_at) {
        $diagnostics[] = 'Optionale Datenbankspalte fehlt: spielinstanzen.started_at';
    }
    if (!$has_sim_state) {
        $diagnostics[] = 'Optionale Datenbankspalte fehlt: spielinstanzen.sim_state';
    }
    if (!$has_instanz_user) {
        $diagnostics[] = 'Optionale Datenbanktabelle fehlt: instanz_user';
    } elseif (!$has_connected) {
        $diagnostics[] = 'Optionale Datenbankspalte fehlt: instanz_user.connected';
    }
    if (!$has_fahrzeug_status) {
        $diagnostics[] = 'Optionale Datenbanktabelle fehlt: fahrzeug_status';
    }
    if (!$has_instanz_fahrzeug_status) {
        $diagnostics[] = 'Datenbanktabelle fehlt: instanz_fahrzeug_status. Bitte Datenbankstruktur aktualisieren.';
    }
    $participants_where = $has_connected ? 'iu.instanz_id = si.id AND iu.connected = 1' : 'iu.instanz_id = si.id';
    $participants_count = $has_instanz_user
        ? "(SELECT COUNT(*) FROM instanz_user iu WHERE {$participants_where})"
        : '0';
    $fahrzeuge_count = $has_fahrzeug_status
        ? '(SELECT COUNT(*) FROM fahrzeug_status fs WHERE fs.instanz_id = si.id)'
        : '0';
    $current_user_role_where = $has_connected ? ' AND COALESCE(iu_me.connected, 1) = 1' : '';
    $current_user_role = $has_instanz_user
        ? '(SELECT rolle FROM instanz_user iu_me WHERE iu_me.instanz_id = si.id AND iu_me.user_id = ' . (int) $user_id . $current_user_role_where . ' LIMIT 1)'
        : 'NULL';
    $started_at_select = $has_started_at ? 'si.started_at' : 'NULL AS started_at';
    $sim_state_select = $has_sim_state ? 'si.sim_state' : "'created' AS sim_state";
    $sim_state_where = $has_sim_state ? "AND COALESCE(si.sim_state, 'created') IN ('created', 'running', 'paused')" : '';

    $stmt = $pdo->query("
        SELECT
            si.id,
            si.name,
            {$started_at_select},
            si.settings_json,
            {$sim_state_select},
            l.name AS leitstelle_name,
            l.ort AS leitstelle_ort,
            l.bundesland AS leitstelle_bundesland,
            {$participants_count} AS participants_count,
            {$fahrzeuge_count} AS fahrzeuge_count,
            {$current_user_role} AS current_user_rolle
        FROM spielinstanzen si
        INNER JOIN leitstellen l ON l.id = si.leitstelle_id
        WHERE COALESCE(si.ist_aktiv, 1) = 1
          {$sim_state_where}
        ORDER BY si.erstellt_am DESC, si.id DESC
        LIMIT 50
    ");

    $items = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $settings = lsttraining_frontend_decode_settings($row['settings_json'] ?? null);
        $mode = (string) ($settings['mode'] ?? '');
        if (!in_array($mode, ['multiplayer', 'einsatzleiter'], true)) {
            continue;
        }
        if (!empty($row['current_user_rolle'])) {
            continue;
        }

        unset($row['settings_json']);
        $row['settings'] = $settings;
        $row['mode'] = $mode;
        $row['mode_label'] = lsttraining_frontend_mode_label($mode);
        $row['can_join'] = true;
        $items[] = $row;
    }

    return $items;
}

function lsttraining_frontend_fetch_saved_instances(PDO $pdo, int $user_id, array &$diagnostics = [], int $offset = 0, int $limit = 10): array {
    try {
        lsttraining_frontend_ensure_instance_columns($pdo);
    } catch (Throwable $e) {
        $diagnostics[] = 'Schema-Prüfung fehlgeschlagen: ' . $e->getMessage();
        error_log('[LSTtraining][frontend_saved_instances_schema] ' . $e->getMessage());
    }

    foreach (['spielinstanzen', 'instanz_user', 'leitstellen'] as $table) {
        if (!lsttraining_frontend_table_exists($pdo, $table)) {
            $diagnostics[] = 'Datenbanktabelle fehlt: ' . $table;
            return ['items' => [], 'has_more' => false];
        }
    }

    if (!lsttraining_frontend_column_exists($pdo, 'spielinstanzen', 'settings_json')) {
        $diagnostics[] = 'Datenbankspalte fehlt: spielinstanzen.settings_json';
        return ['items' => [], 'has_more' => false];
    }

    $offset = max(0, $offset);
    $limit = max(1, min(20, $limit));
    $fetch_limit = $limit + 1;
    $has_started_at = lsttraining_frontend_column_exists($pdo, 'spielinstanzen', 'started_at');
    $has_sim_state = lsttraining_frontend_column_exists($pdo, 'spielinstanzen', 'sim_state');
    $has_connected = lsttraining_frontend_column_exists($pdo, 'instanz_user', 'connected');
    $has_fahrzeug_status = lsttraining_frontend_table_exists($pdo, 'fahrzeug_status');
    $participants_where = $has_connected ? 'iu.instanz_id = si.id AND iu.connected = 1' : 'iu.instanz_id = si.id';
    $participants_count = "(SELECT COUNT(*) FROM instanz_user iu WHERE {$participants_where})";
    $fahrzeuge_count = $has_fahrzeug_status
        ? '(SELECT COUNT(*) FROM fahrzeug_status fs WHERE fs.instanz_id = si.id)'
        : '0';
    $started_at_select = $has_started_at ? 'si.started_at' : 'NULL AS started_at';
    $sim_state_select = $has_sim_state ? 'si.sim_state' : "'created' AS sim_state";
    $sim_state_where = $has_sim_state ? "AND COALESCE(si.sim_state, 'created') IN ('created', 'running', 'paused')" : '';
    $membership_where = $has_connected ? 'AND COALESCE(iu_me.connected, 1) = 1' : '';

    $stmt = $pdo->prepare("
        SELECT
            si.id,
            si.name,
            {$started_at_select},
            si.settings_json,
            {$sim_state_select},
            si.owner_user_id,
            si.last_activity_at,
            si.retention_delete_at,
            l.name AS leitstelle_name,
            l.ort AS leitstelle_ort,
            l.bundesland AS leitstelle_bundesland,
            {$participants_count} AS participants_count,
            {$fahrzeuge_count} AS fahrzeuge_count,
            iu_me.rolle AS current_user_rolle
        FROM instanz_user iu_me
        INNER JOIN spielinstanzen si ON si.id = iu_me.instanz_id
        INNER JOIN leitstellen l ON l.id = si.leitstelle_id
        WHERE iu_me.user_id = ?
          {$membership_where}
          AND COALESCE(si.ist_aktiv, 1) = 1
          {$sim_state_where}
        ORDER BY si.erstellt_am DESC, si.id DESC
        LIMIT {$fetch_limit}
        OFFSET {$offset}
    ");
    $stmt->execute([$user_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $has_more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $items = [];
    foreach ($rows as $row) {
        $settings = lsttraining_frontend_decode_settings($row['settings_json'] ?? null);
        $mode = (string) ($settings['mode'] ?? '');
        unset($row['settings_json']);
        $row['settings'] = $settings;
        $row['mode'] = $mode;
        $row['mode_label'] = lsttraining_frontend_mode_label($mode);
        $owner_user_id = (int) ($row['owner_user_id'] ?? 0);
        $is_owner = $owner_user_id > 0 && $owner_user_id === $user_id;
        $is_shared = function_exists('lsttraining_instance_lifecycle_is_shared_mode')
            ? lsttraining_instance_lifecycle_is_shared_mode($mode)
            : in_array($mode, ['multiplayer', 'einsatzleiter'], true);
        $row['is_shared'] = $is_shared;
        $row['is_owner'] = $is_owner;
        $row['can_delete'] = $is_owner || current_user_can('manage_options');
        $row['can_leave'] = $is_shared && !$is_owner && !current_user_can('manage_options');
        $items[] = $row;
    }

    return [
        'items' => $items,
        'has_more' => $has_more,
    ];
}

add_action('wp_ajax_lsttraining_frontend_get_leitstellen', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        $stmt = $pdo->query('
            SELECT id, name, ort, bundesland, latitude, longitude
            FROM leitstellen
            WHERE id IS NOT NULL AND id > 0
            ORDER BY name ASC, ort ASC
        ');
        wp_send_json_success(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][frontend_get_leitstellen] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Leitstellen konnten nicht geladen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_frontend_create_instance', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Bitte melde dich an, um eine Simulation zu starten.'], 401);
    }

    $leitstelle_id = isset($_POST['leitstelle_id']) ? absint($_POST['leitstelle_id']) : 0;
    if ($leitstelle_id <= 0) {
        wp_send_json_error(['message' => 'Bitte wähle eine Leitstelle aus.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    $debug_step = 'start';

    try {
        $debug_step = 'ensure_instance_columns';
        lsttraining_frontend_ensure_instance_columns($pdo);

        $debug_step = 'check_required_schema';
        $schema_errors = lsttraining_frontend_required_schema_errors($pdo);
        if ($schema_errors) {
            wp_send_json_error([
                'message' => 'Schema-Prüfung fehlgeschlagen: ' . implode(' | ', $schema_errors),
                'diagnostics' => $schema_errors,
                'debug_step' => $debug_step,
            ], 500);
        }

        $debug_step = 'prepare_settings';
        $settings = lsttraining_frontend_prepare_settings();

        $debug_step = 'fetch_leitstelle';
        $leitstelle_stmt = $pdo->prepare('
            SELECT id, name, ort, bundesland, latitude, longitude
            FROM leitstellen
            WHERE id = ?
            LIMIT 1
        ');
        $leitstelle_stmt->execute([$leitstelle_id]);
        $leitstelle = $leitstelle_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$leitstelle) {
            wp_send_json_error(['message' => 'Die gewählte Leitstelle existiert nicht.'], 404);
        }

        $debug_step = 'capture_weather_forecast';
        $settings['weather_forecast'] = lsttraining_sim_capture_weather_forecast($leitstelle, $settings);
        $settings['weather_snapshot'] = lsttraining_sim_weather_point_for_timestamp(
            $settings,
            lsttraining_sim_weather_ts((string) $settings['started_at'])
        );

        $debug_step = 'encode_settings';
        $settings_json = wp_json_encode([
            'mode'                 => $settings['mode'],
            'start_date'           => $settings['start_date'],
            'start_time'           => $settings['start_time'],
            'season'               => $settings['season'],
            'season_mode'          => $settings['season_mode'],
            'weather'              => $settings['weather'],
            'weather_forecast'      => $settings['weather_forecast'],
            'weather_snapshot'      => $settings['weather_snapshot'],
            'demo_mode'            => $settings['demo_mode'],
            'auto_spawn'           => $settings['auto_spawn'],
            'max_active_einsaetze' => $settings['max_active_einsaetze'],
            'spawn_interval_sec'   => $settings['spawn_interval_sec'],
            'spawn_mode'           => $settings['spawn_mode'],
            'base_interval_min_sec' => $settings['base_interval_min_sec'],
            'base_interval_max_sec' => $settings['base_interval_max_sec'],
            'leitstelle_load_factor' => $settings['leitstelle_load_factor'],
            'next_auto_spawn_at'    => $settings['next_auto_spawn_at'],
            'last_auto_spawn_at'    => $settings['last_auto_spawn_at'],
            'last_spawn_delay_sec'  => $settings['last_spawn_delay_sec'],
            'difficulty'            => $settings['difficulty'],
            'vehicle_state_model'   => 'baseline_delta_v1',
        ]);
        if (!is_string($settings_json)) {
            $settings_json = '{}';
        }

        $name = sprintf(
            'Simulation %s %s %s',
            (string) $leitstelle['name'],
            $settings['start_date'],
            $settings['start_time']
        );

        $debug_step = 'begin_transaction';
        $pdo->beginTransaction();

        $debug_step = 'insert_instance';
        $insert_instance = $pdo->prepare('
            INSERT INTO spielinstanzen
                (leitstelle_id, name, erstellt_am, ist_aktiv, settings_json, started_at, sim_state, owner_user_id, last_activity_at)
            VALUES
                (?, ?, NOW(), 1, ?, ?, ?, ?, NOW())
        ');
        $insert_instance->execute([
            $leitstelle_id,
            $name,
            $settings_json,
            $settings['started_at'],
            'created',
            (int) get_current_user_id(),
        ]);
        $instanz_id = (int) $pdo->lastInsertId();

        $rolle = in_array($settings['mode'], ['singleplayer', 'einsatzleiter'], true) ? 'leiter' : 'mitspieler';
        $user_id = (int) get_current_user_id();

        $debug_step = 'select_instanz_user';
        $existing_user = $pdo->prepare('
            SELECT id
            FROM instanz_user
            WHERE instanz_id = ? AND user_id = ?
            LIMIT 1
        ');
        $existing_user->execute([$instanz_id, $user_id]);
        $instanz_user_id = (int) $existing_user->fetchColumn();

        if ($instanz_user_id > 0) {
            $debug_step = 'update_instanz_user';
            $update_user = $pdo->prepare('
                UPDATE instanz_user
                SET rolle = ?, connected = 1
                WHERE id = ?
            ');
            $update_user->execute([$rolle, $instanz_user_id]);
        } else {
            $debug_step = 'insert_instanz_user';
            $insert_user = $pdo->prepare('
                INSERT INTO instanz_user (instanz_id, user_id, rolle, connected)
                VALUES (?, ?, ?, 1)
            ');
            $insert_user->execute([$instanz_id, $user_id, $rolle]);
        }

        // Fahrzeug-Status bleibt die instanzbezogene Baseline vom Spielstart.
        $debug_step = 'fetch_fahrzeuge';
        $fahrzeuge_stmt = $pdo->prepare('
            SELECT
                f.id AS fahrzeug_id,
                f.wache_id,
                COALESCE(f.latitude, w.latitude) AS latitude,
                COALESCE(f.longitude, w.longitude) AS longitude,
                COALESCE(NULLIF(f.status, \'\'), \'frei\') AS status,
                COALESCE(NULLIF(f.fms_status, \'\'), \'2\') AS fms_status,
                COALESCE(f.sondersignal, 0) AS sondersignal
            FROM fahrzeuge f
            INNER JOIN wache_leitstellen wl ON wl.wache_id = f.wache_id
            INNER JOIN wachen w ON w.id = f.wache_id
            WHERE wl.leitstelle_id = ?
            ORDER BY f.id ASC
        ');
        $fahrzeuge_stmt->execute([$leitstelle_id]);
        $fahrzeuge = $fahrzeuge_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $debug_step = 'prepare_fahrzeug_status';
        $insert_status = $pdo->prepare('
            INSERT INTO fahrzeug_status
                (instanz_id, fahrzeug_id, wache_id, latitude, longitude, status, fms_status, sondersignal)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $initialized = 0;
        foreach ($fahrzeuge as $fahrzeug) {
            $debug_step = 'insert_fahrzeug_status fahrzeug_id=' . (int) $fahrzeug['fahrzeug_id'];
            $insert_status->execute([
                $instanz_id,
                (int) $fahrzeug['fahrzeug_id'],
                (int) $fahrzeug['wache_id'],
                $fahrzeug['latitude'] !== null ? (float) $fahrzeug['latitude'] : null,
                $fahrzeug['longitude'] !== null ? (float) $fahrzeug['longitude'] : null,
                $fahrzeug['status'] ?: 'frei',
                $fahrzeug['fms_status'] ?: '2',
                (int) $fahrzeug['sondersignal'],
            ]);
            $initialized++;
        }

        $debug_step = 'commit';
        $pdo->commit();

        if (function_exists('lsttraining_instance_lifecycle_touch')) {
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        }

        $redirect_url = lsttraining_frontend_redirect_url($instanz_id);
        unset($settings['started_at']);

        wp_send_json_success([
            'instanz_id'           => $instanz_id,
            'redirect_url'         => $redirect_url,
            'settings'             => $settings,
            'initialized_vehicles' => $initialized,
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][frontend_create_instance][' . $debug_step . '] ' . $e->getMessage());

        $message = 'Simulation konnte nicht erstellt werden.';
        $response = [
            'message' => $message,
            'debug_step' => $debug_step,
        ];

        if (current_user_can('manage_options')) {
            $debug_message = 'Fehler bei ' . $debug_step . ': ' . $e->getMessage();
            $response['message'] = $message . ' ' . $debug_message;
            $response['debug_message'] = $debug_message;
            $response['error_code'] = (string) $e->getCode();
        }

        wp_send_json_error($response, 500);
    }
});

add_action('wp_ajax_lsttraining_frontend_get_open_instances', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        $diagnostics = [];
        $items = lsttraining_frontend_fetch_open_instances($pdo, (int) get_current_user_id(), $diagnostics);
        $response = ['items' => $items];
        if ($diagnostics && current_user_can('manage_options')) {
            $response['diagnostics'] = $diagnostics;
            $response['message'] = implode(' | ', $diagnostics);
        }

        wp_send_json_success([
            'items' => $items,
            'diagnostics' => $response['diagnostics'] ?? [],
            'message' => $response['message'] ?? '',
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][frontend_get_open_instances] ' . $e->getMessage());
        $message = 'Offene Spiele konnten nicht geladen werden: ' . $e->getMessage();
        wp_send_json_success([
            'items' => [],
            'diagnostics' => current_user_can('manage_options') ? [$message] : [],
            'message' => current_user_can('manage_options') ? $message : '',
        ]);
    }
});

add_action('wp_ajax_lsttraining_frontend_get_saved_instances', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        $diagnostics = [];
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $result = lsttraining_frontend_fetch_saved_instances($pdo, (int) get_current_user_id(), $diagnostics, $offset);
        $items = $result['items'];

        wp_send_json_success([
            'items' => $items,
            'has_more' => !empty($result['has_more']),
            'next_offset' => $offset + count($items),
            'diagnostics' => current_user_can('manage_options') ? $diagnostics : [],
            'message' => current_user_can('manage_options') ? implode(' | ', $diagnostics) : '',
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][frontend_get_saved_instances] ' . $e->getMessage());
        $message = 'Gespeicherte Spiele konnten nicht geladen werden: ' . $e->getMessage();
        wp_send_json_success([
            'items' => [],
            'has_more' => false,
            'next_offset' => null,
            'diagnostics' => current_user_can('manage_options') ? [$message] : [],
            'message' => current_user_can('manage_options') ? $message : '',
        ]);
    }
});

add_action('wp_ajax_lsttraining_frontend_delete_saved_instance', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Simulation fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        lsttraining_frontend_ensure_instance_columns($pdo);
        $stmt = $pdo->prepare('SELECT owner_user_id FROM spielinstanzen WHERE id = ? LIMIT 1');
        $stmt->execute([$instanz_id]);
        $owner_user_id = (int) $stmt->fetchColumn();
        if ($owner_user_id <= 0) {
            wp_send_json_error(['message' => 'Simulation nicht gefunden oder ohne Verantwortlichen.'], 404);
        }
        if ($owner_user_id !== (int) get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nur der Verantwortliche kann diese Simulation löschen.'], 403);
        }

        if (!lsttraining_instance_lifecycle_delete($pdo, $instanz_id, (int) get_current_user_id(), 'delete')) {
            wp_send_json_error(['message' => 'Simulation konnte nicht gelöscht werden.'], 404);
        }

        wp_send_json_success(['message' => 'Gespeicherte Simulation wurde gelöscht.']);
    } catch (Throwable $e) {
        error_log('[LSTtraining][frontend_delete_saved_instance] ' . $e->getMessage());
        $message = 'Simulation konnte nicht gelöscht werden.';
        if (current_user_can('manage_options')) {
            $message .= ' Datenbankfehler: ' . $e->getMessage();
        }
        wp_send_json_error(['message' => $message], 500);
    }
});

add_action('wp_ajax_lsttraining_frontend_leave_saved_instance', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Simulation fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        lsttraining_frontend_ensure_instance_columns($pdo);
        $stmt = $pdo->prepare('
            SELECT si.owner_user_id, si.settings_json, iu.id AS membership_id
            FROM spielinstanzen si
            INNER JOIN instanz_user iu ON iu.instanz_id = si.id AND iu.user_id = ?
            WHERE si.id = ? AND COALESCE(iu.connected, 1) = 1
            LIMIT 1
        ');
        $stmt->execute([(int) get_current_user_id(), $instanz_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$instance) {
            wp_send_json_error(['message' => 'Simulation nicht gefunden.'], 404);
        }

        $settings = lsttraining_frontend_decode_settings($instance['settings_json'] ?? null);
        $mode = (string) ($settings['mode'] ?? '');
        if (!lsttraining_instance_lifecycle_is_shared_mode($mode)) {
            wp_send_json_error(['message' => 'Ein Einzelspiel kann nur gelöscht werden.'], 409);
        }
        if ((int) ($instance['owner_user_id'] ?? 0) === (int) get_current_user_id()) {
            wp_send_json_error(['message' => 'Der Verantwortliche kann das gemeinsame Spiel nicht verlassen, sondern nur löschen.'], 409);
        }

        $update = $pdo->prepare('UPDATE instanz_user SET connected = 0 WHERE id = ?');
        $update->execute([(int) $instance['membership_id']]);
        lsttraining_instance_lifecycle_log($instanz_id, (int) get_current_user_id(), 'leave', ['mode' => $mode]);

        wp_send_json_success(['message' => 'Du hast das gemeinsame Spiel verlassen.']);
    } catch (Throwable $e) {
        error_log('[LSTtraining][frontend_leave_saved_instance] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Spiel konnte nicht verlassen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_frontend_join_instance', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Bitte melde dich an, um einer Simulation beizutreten.'], 401);
    }

    $instanz_id = isset($_POST['instanz_id']) ? absint($_POST['instanz_id']) : 0;
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Simulation fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        lsttraining_frontend_ensure_instance_columns($pdo);

        $stmt = $pdo->prepare('
            SELECT id, settings_json, ist_aktiv, sim_state
            FROM spielinstanzen
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$instanz_id]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$instance) {
            wp_send_json_error(['message' => 'Simulation nicht gefunden.'], 404);
        }

        $settings = lsttraining_frontend_decode_settings($instance['settings_json'] ?? null);
        $mode = (string) ($settings['mode'] ?? '');
        $sim_state = (string) ($instance['sim_state'] ?? 'created');
        if ((int) ($instance['ist_aktiv'] ?? 1) !== 1 || !in_array($sim_state, ['created', 'running', 'paused'], true)) {
            wp_send_json_error(['message' => 'Diese Simulation ist nicht mehr offen.'], 409);
        }
        if (!in_array($mode, ['multiplayer', 'einsatzleiter'], true)) {
            wp_send_json_error(['message' => 'Dieser Simulation kann nicht beigetreten werden.'], 409);
        }

        $user_id = (int) get_current_user_id();
        $pdo->beginTransaction();

        $existing = $pdo->prepare('
            SELECT id, rolle
            FROM instanz_user
            WHERE instanz_id = ? AND user_id = ?
            LIMIT 1
        ');
        $existing->execute([$instanz_id, $user_id]);
        $existing_row = $existing->fetch(PDO::FETCH_ASSOC);
        $instanz_user_id = $existing_row ? (int) $existing_row['id'] : 0;

        if ($instanz_user_id > 0) {
            if (($existing_row['rolle'] ?? '') === 'leiter') {
                $update = $pdo->prepare('UPDATE instanz_user SET connected = 1 WHERE id = ?');
                $update->execute([$instanz_user_id]);
            } else {
                $update = $pdo->prepare('
                    UPDATE instanz_user
                    SET rolle = ?, connected = 1
                    WHERE id = ?
                ');
                $update->execute(['mitspieler', $instanz_user_id]);
            }
        } else {
            $insert = $pdo->prepare('
                INSERT INTO instanz_user (instanz_id, user_id, rolle, connected)
                VALUES (?, ?, ?, 1)
            ');
            $insert->execute([$instanz_id, $user_id, 'mitspieler']);
        }

        $pdo->commit();

        if (function_exists('lsttraining_instance_lifecycle_touch')) {
            lsttraining_instance_lifecycle_touch($pdo, $instanz_id);
        }

        wp_send_json_success([
            'instanz_id'   => $instanz_id,
            'redirect_url' => lsttraining_frontend_redirect_url($instanz_id),
        ]);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LSTtraining][frontend_join_instance] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Beitritt konnte nicht abgeschlossen werden.'], 500);
    }
});

add_action('wp_ajax_lsttraining_frontend_get_instance', function () {
    lsttraining_frontend_check_nonce();

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Nicht angemeldet.'], 401);
    }

    $instanz_id = isset($_GET['instanz_id']) ? absint($_GET['instanz_id']) : absint($_POST['instanz_id'] ?? 0);
    if ($instanz_id <= 0) {
        wp_send_json_error(['message' => 'Instanz fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'Datenbankverbindung fehlgeschlagen.'], 500);
    }

    try {
        $instance = lsttraining_frontend_fetch_instance($pdo, $instanz_id, (int) get_current_user_id());
        if (!$instance) {
            wp_send_json_error(['message' => 'Simulation nicht gefunden.'], 404);
        }

        wp_send_json_success(['instance' => $instance]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][frontend_get_instance] ' . $e->getMessage());
        wp_send_json_error(['message' => 'Simulation konnte nicht geladen werden.'], 500);
    }
});
