<?php
/**
 * AJAX: Einsatzeditor
 */

if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/ajax_common.php';
require_once dirname(__DIR__) . '/simulation/spawn.php';
require_once dirname(__DIR__) . '/simulation/transport.php';

function lsttraining_einsatzeditor_validate_json_array(?string $raw): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        wp_send_json_error(['message' => 'Ungültiges JSON-Array.'], 400);
    }

    return wp_json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function lsttraining_einsatzeditor_normalize_optional_json(?string $raw): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function lsttraining_einsatzeditor_normalize_resources_json(?string $raw): ?string {
    $resources = lsttraining_sim_normalize_required_resources($raw);
    return $resources ? wp_json_encode($resources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function lsttraining_einsatzeditor_normalize_patients_json(?string $raw): ?string {
    $patients = lsttraining_sim_normalize_patients((string) $raw);
    return $patients ? wp_json_encode($patients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function lsttraining_einsatzeditor_normalize_effect_json(?string $raw): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return null;
    }
    if (isset($decoded['patients']) && is_array($decoded['patients'])) {
        $decoded['patients'] = lsttraining_sim_normalize_patients($decoded['patients']);
    }

    return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function lsttraining_einsatzeditor_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_einsatzeditor_table_exists(PDO $pdo, string $table): bool {
    $status = lsttraining_einsatzeditor_probe_table($pdo, $table);
    return (bool) ($status['exists'] ?? false);
}

function lsttraining_einsatzeditor_probe_table(PDO $pdo, string $table): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return ['exists' => false, 'error' => 'Ungültiger Tabellenname.'];
    }

    $errors = [];
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) {
            return ['exists' => true, 'error' => ''];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return ['exists' => true, 'error' => ''];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return [
        'exists' => false,
        'error' => implode(' | ', array_filter($errors)),
    ];
}

function lsttraining_einsatzeditor_require_table(PDO $pdo, string $table, string $label): void {
    $status = lsttraining_einsatzeditor_probe_table($pdo, $table);
    if (!empty($status['exists'])) {
        return;
    }

    $message = $label . ' `' . $table . '` fehlt oder ist nicht lesbar.';
    if (!empty($status['error'])) {
        $message .= ' DB-Fehler: ' . $status['error'];
    }
    throw new RuntimeException($message);
}

function lsttraining_einsatzeditor_ensure_column(PDO $pdo, string $table, string $column, string $definition): bool {
    if (lsttraining_einsatzeditor_column_exists($pdo, $table, $column)) {
        return true;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        return lsttraining_einsatzeditor_column_exists($pdo, $table, $column);
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_einsatzeditor_ensure_profile_assignment_table(PDO $pdo): void {
    if (lsttraining_einsatzeditor_table_exists($pdo, 'einsatz_anrufer_profiles')) {
        return;
    }

    lsttraining_einsatzeditor_require_table($pdo, 'einsaetze', 'Basistabelle');
    lsttraining_einsatzeditor_require_table($pdo, 'anrufer_profile', 'Basistabelle');

    $createWithForeignKeys = "
        CREATE TABLE IF NOT EXISTS `einsatz_anrufer_profiles` (
          `einsatz_id` INT NOT NULL,
          `profile_id` INT NOT NULL,
          `weight` INT NOT NULL DEFAULT 100,
          PRIMARY KEY (`einsatz_id`, `profile_id`),
          KEY `idx_eap_profile` (`profile_id`),
          CONSTRAINT `fk_eap_einsatz`
            FOREIGN KEY (`einsatz_id`) REFERENCES `einsaetze`(`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_eap_profile`
            FOREIGN KEY (`profile_id`) REFERENCES `anrufer_profile`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $createWithoutForeignKeys = "
        CREATE TABLE IF NOT EXISTS `einsatz_anrufer_profiles` (
          `einsatz_id` INT NOT NULL,
          `profile_id` INT NOT NULL,
          `weight` INT NOT NULL DEFAULT 100,
          PRIMARY KEY (`einsatz_id`, `profile_id`),
          KEY `idx_eap_profile` (`profile_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $errors = [];
    try {
        $pdo->exec($createWithForeignKeys);
    } catch (Throwable $e) {
        $errors[] = 'mit Foreign Keys: ' . $e->getMessage();
    }

    if (!lsttraining_einsatzeditor_table_exists($pdo, 'einsatz_anrufer_profiles')) {
        try {
            $pdo->exec($createWithoutForeignKeys);
        } catch (Throwable $e) {
            $errors[] = 'ohne Foreign Keys: ' . $e->getMessage();
        }
    }

    if (!lsttraining_einsatzeditor_table_exists($pdo, 'einsatz_anrufer_profiles')) {
        $message = 'Zuordnungstabelle `einsatz_anrufer_profiles` konnte nicht angelegt werden.';
        if ($errors) {
            $message .= ' DB-Fehler: ' . implode(' | ', $errors);
        }
        throw new RuntimeException($message);
    }
}

function lsttraining_einsatzeditor_group_caller_parts(array $rows): array {
    $grouped = [
        'greeting' => [],
        'person'   => [],
        'location' => [],
        'problem'  => [],
        'observation' => [],
        'extra'    => [],
    ];

    foreach ($rows as $row) {
        $key = (string) ($row['part_key'] ?? '');
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }

        $grouped[$key][] = [
            'text'       => (string) ($row['text'] ?? ''),
            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
            'enabled'    => isset($row['enabled']) ? (int) $row['enabled'] : 1,
        ];
    }

    return $grouped;
}

function lsttraining_einsatzeditor_first_enabled_text(array $rows): string {
    foreach ($rows as $row) {
        $enabled = isset($row['enabled']) ? (int) $row['enabled'] : 1;
        $text = trim((string) ($row['text'] ?? ''));

        if ($enabled === 1 && $text !== '') {
            return $text;
        }
    }

    foreach ($rows as $row) {
        $text = trim((string) ($row['text'] ?? ''));
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

function lsttraining_einsatzeditor_fetch_one(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM einsaetze WHERE id = ?');
    $stmt->execute([$id]);
    $einsatz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$einsatz) {
        wp_send_json_error(['message' => 'Einsatz nicht gefunden.'], 404);
    }

    $seasonsStmt = $pdo->prepare('SELECT season FROM einsatz_seasons WHERE einsatz_id = ? ORDER BY season ASC');
    $seasonsStmt->execute([$id]);

    $weatherStmt = $pdo->prepare('SELECT weather_type FROM einsatz_weather_conditions WHERE einsatz_id = ? ORDER BY weather_type ASC');
    $weatherStmt->execute([$id]);

    $callerStmt = $pdo->prepare('
        SELECT part_key, text, sort_order, enabled
        FROM einsatz_caller_parts
        WHERE einsatz_id = ?
        ORDER BY part_key ASC, sort_order ASC, id ASC
    ');
    $callerStmt->execute([$id]);
    $callerRows = $callerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $callerProfiles = [];
    if (
        lsttraining_einsatzeditor_table_exists($pdo, 'einsatz_anrufer_profiles') &&
        lsttraining_einsatzeditor_table_exists($pdo, 'anrufer_profile')
    ) {
        $profileStmt = $pdo->prepare('
            SELECT eap.profile_id, eap.weight, ap.name, ap.category, ap.tone, ap.enabled
            FROM einsatz_anrufer_profiles eap
            INNER JOIN anrufer_profile ap ON ap.id = eap.profile_id
            WHERE eap.einsatz_id = ?
            ORDER BY ap.sort_order ASC, ap.name ASC, ap.id ASC
        ');
        $profileStmt->execute([$id]);
        foreach (($profileStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $profileRow) {
            $callerProfiles[] = [
                'profile_id' => (int) ($profileRow['profile_id'] ?? 0),
                'weight' => max(1, (int) ($profileRow['weight'] ?? 100)),
                'name' => (string) ($profileRow['name'] ?? ''),
                'category' => (string) ($profileRow['category'] ?? ''),
                'tone' => (string) ($profileRow['tone'] ?? ''),
                'enabled' => isset($profileRow['enabled']) ? (int) $profileRow['enabled'] : 1,
            ];
        }
    }

    $followupStmt = $pdo->prepare('
        SELECT step_no, label, kind, text, min_after_sec, max_after_sec, condition_json,
               probability_percent, speaker_type, trigger_mode, required_resources_json, effect_json
        FROM einsatz_followups
        WHERE einsatz_id = ?
        ORDER BY step_no ASC
    ');
    $followupStmt->execute([$id]);

    $einsatz['seasons'] = array_map('strval', $seasonsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $einsatz['weather_conditions'] = array_map('strval', $weatherStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $einsatz['caller_parts'] = lsttraining_einsatzeditor_group_caller_parts($callerRows);
    $einsatz['caller_profiles'] = $callerProfiles;
    $einsatz['followups'] = $followupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $einsatz;
}

function lsttraining_einsatzeditor_pick_preview_profile(PDO $pdo, array $caller_profiles): ?array {
    if (!lsttraining_einsatzeditor_table_exists($pdo, 'anrufer_profile')) {
        return null;
    }

    $items = [];
    $selectedWeights = [];
    foreach ($caller_profiles as $profileRow) {
        $profileId = isset($profileRow['profile_id']) ? (int) $profileRow['profile_id'] : (int) ($profileRow['id'] ?? 0);
        if ($profileId <= 0) {
            continue;
        }
        $selectedWeights[$profileId] = max(1, (int) ($profileRow['weight'] ?? 100));
    }

    if ($selectedWeights) {
        $placeholders = implode(',', array_fill(0, count($selectedWeights), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM anrufer_profile
            WHERE enabled = 1 AND id IN ({$placeholders})
            ORDER BY sort_order ASC, name ASC, id ASC
        ");
        $stmt->execute(array_keys($selectedWeights));
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $row['weight'] = $selectedWeights[(int) ($row['id'] ?? 0)] ?? 100;
            $items[] = $row;
        }
        if ($items) {
            return lsttraining_sim_weighted_pick($items);
        }
    }

    $stmt = $pdo->query('
        SELECT *
        FROM anrufer_profile
        WHERE enabled = 1
        ORDER BY sort_order ASC, name ASC, id ASC
    ');
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['weight'] = 100;
        $items[] = $row;
    }
    return lsttraining_sim_weighted_pick($items);
}

function lsttraining_einsatzeditor_build_preview_caller(PDO $pdo, array $caller_parts, array $caller_profiles): string {
    foreach (['problem', 'observation', 'extra'] as $partKey) {
        if (!isset($caller_parts[$partKey]) || !is_array($caller_parts[$partKey])) {
            $caller_parts[$partKey] = [];
        }
    }

    $problem = lsttraining_sim_pick_enabled_text($caller_parts['problem']);
    $observation = lsttraining_sim_pick_enabled_text($caller_parts['observation']);
    $extra = lsttraining_sim_pick_enabled_text($caller_parts['extra']);
    if ($problem === '') {
        $problem = 'Hier ist ein Unfall passiert.';
    }

    $tokens = lsttraining_build_anrufer_tokens($pdo, null, [
        'address_full' => 'Musterstraße 12, Musterstadt',
        'address_street' => 'Musterstraße',
        'address_housenumber' => '12',
        'address_city' => 'Musterstadt',
        'location' => 'Musterstraße 12, Musterstadt',
        'poi_name' => 'Beispielobjekt',
        'company_name' => 'Firma Beispiel GmbH',
        'problem' => $problem,
        'observation' => $observation,
        'extra' => $extra,
    ]);

    $profile = lsttraining_einsatzeditor_pick_preview_profile($pdo, $caller_profiles);
    $fragments = [];
    $usedProfileKeys = [];
    $locationFragments = [];
    if ($profile) {
        $profileTokens = lsttraining_sim_profile_tokens($tokens, $profile);
        $profileParts = lsttraining_sim_fetch_profile_parts($pdo, (int) ($profile['id'] ?? 0));
        $openingStart = count($fragments);
        lsttraining_sim_add_part_fragment($fragments, $usedProfileKeys, 'greeting', $profileParts['greeting'] ?? [], $tokens);
        lsttraining_sim_add_part_fragment($fragments, $usedProfileKeys, 'self_intro', $profileParts['self_intro'] ?? [], $tokens);
        lsttraining_sim_ensure_caller_opener($fragments, $usedProfileKeys, $tokens, $openingStart, count($fragments) - $openingStart);
        lsttraining_sim_add_part_fragment($fragments, $usedProfileKeys, 'problem_intro', $profileParts['problem_intro'] ?? [], $profileTokens);
        lsttraining_sim_add_part_fragment($locationFragments, $usedProfileKeys, 'location_intro', $profileParts['location_intro'] ?? [], $tokens);
        $locationFallback = lsttraining_sim_default_location_intro($tokens);
        if (!in_array('location_intro', $usedProfileKeys, true) && $locationFallback !== '') {
            $locationFragments[] = $locationFallback;
            $usedProfileKeys[] = 'location_intro_fallback';
        }
    } else {
        $fragments[] = lsttraining_sim_default_caller_opener($tokens, true);
        $usedProfileKeys[] = 'system_greeting_fallback';
        $locationFallback = lsttraining_sim_default_location_intro($tokens);
        if ($locationFallback !== '') {
            $locationFragments[] = $locationFallback;
            $usedProfileKeys[] = 'system_location_fallback';
        }
    }

    $messageFragments = [];
    foreach ([$problem, $observation, $extra] as $messageText) {
        $messageText = trim((string) $messageText);
        if ($messageText !== '') {
            $filled = lsttraining_fill_anrufer_placeholders($messageText, $tokens);
            if ($filled !== '') {
                $messageFragments[] = $filled;
            }
        }
    }
    $fragments = array_merge($fragments, $messageFragments);
    $fragments = array_merge($fragments, $locationFragments);

    if ($profile) {
        $profileTokens = lsttraining_sim_profile_tokens($tokens, $profile);
        $profileParts = lsttraining_sim_fetch_profile_parts($pdo, (int) ($profile['id'] ?? 0));
        lsttraining_sim_add_part_fragment($fragments, $usedProfileKeys, 'urgency', $profileParts['urgency'] ?? [], $profileTokens);
        lsttraining_sim_add_part_fragment($fragments, $usedProfileKeys, 'closing', $profileParts['closing'] ?? [], $profileTokens);
        lsttraining_sim_add_part_fragment($fragments, $usedProfileKeys, 'callback_request', $profileParts['callback_request'] ?? [], $profileTokens);
    }

    $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter($fragments))));
    return lsttraining_sim_caller_text_needs_rebuild($text, $tokens)
        ? lsttraining_sim_compose_system_caller_text($tokens, $messageFragments)
        : $text;
}

add_action('wp_ajax_lst_get_einsaetze', function () {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $search  = isset($_POST['search']) ? trim((string) wp_unslash($_POST['search'])) : '';
    $art     = isset($_POST['einsatzart']) ? trim((string) wp_unslash($_POST['einsatzart'])) : '';
    $enabled = isset($_POST['enabled']) ? trim((string) wp_unslash($_POST['enabled'])) : '';

    $sql = 'SELECT id, title, description, einsatzart, einsatztyp, enabled, scope_type FROM einsaetze WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (title LIKE ? OR description LIKE ? OR einsatztyp LIKE ? OR id = ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = ctype_digit($search) ? (int) $search : 0;
    }

    if (in_array($art, ['RD', 'FW'], true)) {
        $sql .= ' AND einsatzart = ?';
        $params[] = $art;
    }

    if ($enabled === '0' || $enabled === '1') {
        $sql .= ' AND enabled = ?';
        $params[] = (int) $enabled;
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    wp_send_json_success([
        'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
});

add_action('wp_ajax_lst_preview_einsatz_caller', function () {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $caller_parts = isset($_POST['caller_parts']) ? json_decode(wp_unslash($_POST['caller_parts']), true) : [];
    $caller_profiles = isset($_POST['caller_profiles']) ? json_decode(wp_unslash($_POST['caller_profiles']), true) : [];
    if (!is_array($caller_parts)) { $caller_parts = []; }
    if (!is_array($caller_profiles)) { $caller_profiles = []; }

    $examples = [];
    for ($i = 0; $i < 3; $i++) {
        $text = lsttraining_einsatzeditor_build_preview_caller($pdo, $caller_parts, $caller_profiles);
        if ($text !== '') {
            $examples[] = $text;
        }
    }

    wp_send_json_success(['examples' => $examples]);
});

add_action('wp_ajax_lst_preview_patient_hospital_routing', function () {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $raw_patients = isset($_POST['patients']) ? (string) wp_unslash($_POST['patients']) : '[]';
    $patients = lsttraining_sim_normalize_patients($raw_patients);
    $incident = [
        'einsatzart' => isset($_POST['einsatzart']) ? sanitize_text_field(wp_unslash($_POST['einsatzart'])) : '',
        'einsatztyp' => isset($_POST['einsatztyp']) ? sanitize_text_field(wp_unslash($_POST['einsatztyp'])) : '',
        'caller_text' => '',
        'lagemeldung' => isset($_POST['lagemeldung']) ? sanitize_textarea_field(wp_unslash($_POST['lagemeldung'])) : '',
    ];

    $items = [];
    foreach ($patients as $index => $patient) {
        $resolution = lsttraining_sim_transport_patient_department_resolution($patient, $incident);
        $items[] = [
            'patient_id' => (string) ($patient['patient_id'] ?? ('p' . ($index + 1))),
            'mode' => (string) ($resolution['mode'] ?? 'automatic'),
            'reason_label' => (string) ($resolution['reason_label'] ?? ''),
            'department_preferences' => array_values((array) ($resolution['department_preferences'] ?? [])),
            'notice' => (string) ($resolution['notice'] ?? ''),
        ];
    }

    wp_send_json_success([
        'items' => $items,
        'automatic_notice' => 'Der erzeugte Anruftext kann die Auswahl im laufenden Einsatz noch verändern.',
    ]);
});

add_action('wp_ajax_lst_get_einsatz', function () {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'Einsatz-ID fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    wp_send_json_success([
        'item' => lsttraining_einsatzeditor_fetch_one($pdo, $id),
    ]);
});

add_action('wp_ajax_lst_save_einsatz', function () {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
    $einsatzart = isset($_POST['einsatzart']) ? sanitize_text_field(wp_unslash($_POST['einsatzart'])) : '';
    $einsatztyp = isset($_POST['einsatztyp']) ? sanitize_text_field(wp_unslash($_POST['einsatztyp'])) : '';
    $enabled = isset($_POST['enabled']) ? (int) $_POST['enabled'] : 0;

    $tags_json = isset($_POST['tags_json']) ? trim((string) wp_unslash($_POST['tags_json'])) : '';

    $scope_type = isset($_POST['scope_type']) ? sanitize_text_field(wp_unslash($_POST['scope_type'])) : 'anywhere';
    $landscape_tags_json = isset($_POST['landscape_tags_json']) ? trim((string) wp_unslash($_POST['landscape_tags_json'])) : '';
    $poi_type = isset($_POST['poi_type']) ? sanitize_text_field(wp_unslash($_POST['poi_type'])) : '';

    $fixed_latitude = (isset($_POST['fixed_latitude']) && $_POST['fixed_latitude'] !== '') ? (float) $_POST['fixed_latitude'] : null;
    $fixed_longitude = (isset($_POST['fixed_longitude']) && $_POST['fixed_longitude'] !== '') ? (float) $_POST['fixed_longitude'] : null;
    $fixed_radius_m = (isset($_POST['fixed_radius_m']) && $_POST['fixed_radius_m'] !== '') ? (int) $_POST['fixed_radius_m'] : null;

    $caller_template_text = '';
    $lagemeldung = isset($_POST['lagemeldung']) ? sanitize_textarea_field(wp_unslash($_POST['lagemeldung'])) : '';

    $patientenzahl = isset($_POST['patientenzahl']) ? (int) $_POST['patientenzahl'] : 0;
    $patient_anforderung = isset($_POST['patient_anforderung']) ? sanitize_text_field(wp_unslash($_POST['patient_anforderung'])) : '';
    $notarzt_benoetigt = isset($_POST['notarzt_benoetigt']) ? (int) $_POST['notarzt_benoetigt'] : 0;
    $feuerwehr_benoetigt = isset($_POST['feuerwehr_benoetigt']) ? (int) $_POST['feuerwehr_benoetigt'] : 0;
    $base_required_resources_json = isset($_POST['base_required_resources_json'])
        ? (string) wp_unslash($_POST['base_required_resources_json'])
        : '';
    $patient_profile_json = isset($_POST['patient_profile_json'])
        ? (string) wp_unslash($_POST['patient_profile_json'])
        : '';

    $seasons = isset($_POST['seasons']) ? json_decode(wp_unslash($_POST['seasons']), true) : [];
    $weather_conditions = isset($_POST['weather_conditions']) ? json_decode(wp_unslash($_POST['weather_conditions']), true) : [];
    $caller_parts = isset($_POST['caller_parts']) ? json_decode(wp_unslash($_POST['caller_parts']), true) : [];
    $caller_profiles = isset($_POST['caller_profiles']) ? json_decode(wp_unslash($_POST['caller_profiles']), true) : [];
    $followups = isset($_POST['followups']) ? json_decode(wp_unslash($_POST['followups']), true) : [];
    $caller_profiles_loaded = isset($_POST['caller_profiles_loaded']) && (int) $_POST['caller_profiles_loaded'] === 1;

    if ($title === '') {
        wp_send_json_error(['message' => 'Titel darf nicht leer sein.'], 400);
    }
    if (!in_array($einsatzart, ['RD', 'FW'], true)) {
        wp_send_json_error(['message' => 'Ungültige Einsatzart.'], 400);
    }
    if ($einsatztyp === '') {
        wp_send_json_error(['message' => 'Einsatztyp fehlt.'], 400);
    }
    if (!in_array($scope_type, ['anywhere', 'landscape', 'poi_type', 'fixed_point'], true)) {
        wp_send_json_error(['message' => 'Ungültiger Ortsmodus.'], 400);
    }
    if ($lagemeldung === '') {
        wp_send_json_error(['message' => 'Lagemeldung darf nicht leer sein.'], 400);
    }

    $tags_json = lsttraining_einsatzeditor_validate_json_array($tags_json);
    $landscape_tags_json = lsttraining_einsatzeditor_validate_json_array($landscape_tags_json);
    $base_required_resources_json = lsttraining_einsatzeditor_normalize_resources_json($base_required_resources_json);
    $patient_profile_json = lsttraining_einsatzeditor_normalize_patients_json($patient_profile_json);
    $hasBaseRequiredResourcesColumn = lsttraining_einsatzeditor_column_exists($pdo, 'einsaetze', 'base_required_resources_json');
    $hasPatientProfileColumn = lsttraining_einsatzeditor_ensure_column($pdo, 'einsaetze', 'patient_profile_json', 'LONGTEXT NULL');

    if ($scope_type === 'poi_type' && $poi_type === '') {
        wp_send_json_error(['message' => 'Für poi_type muss ein POI-Typ gesetzt sein.'], 400);
    }

    if ($scope_type === 'fixed_point' && ($fixed_latitude === null || $fixed_longitude === null)) {
        wp_send_json_error(['message' => 'Für fixed_point sind Latitude und Longitude Pflicht.'], 400);
    }

    if ($scope_type === 'landscape') {
        $landscapeDecoded = json_decode((string) $landscape_tags_json, true);
        if (!is_array($landscapeDecoded) || !$landscapeDecoded) {
            wp_send_json_error(['message' => 'Für landscape muss mindestens ein Landscape-Tag gesetzt sein.'], 400);
        }
    }

    if (!is_array($seasons)) { $seasons = []; }
    if (!is_array($weather_conditions)) { $weather_conditions = []; }
    if (!is_array($caller_parts)) { $caller_parts = []; }
    if (!is_array($caller_profiles)) { $caller_profiles = []; }
    if (!is_array($followups)) { $followups = []; }
    if (!$caller_profiles_loaded && $caller_profiles) {
        $caller_profiles_loaded = true;
    }

    $allowedPartKeys = ['problem', 'observation', 'extra'];
    foreach ($allowedPartKeys as $partKey) {
        if (!isset($caller_parts[$partKey]) || !is_array($caller_parts[$partKey])) {
            $caller_parts[$partKey] = [];
        }
    }

    $hasProblem = false;
    foreach ($caller_parts['problem'] as $row) {
        $text = trim((string) ($row['text'] ?? ''));
        $enabledRow = isset($row['enabled']) ? (int) $row['enabled'] : 1;
        if ($enabledRow === 1 && $text !== '') {
            $hasProblem = true;
            break;
        }
    }

    if (!$hasProblem) {
        foreach ($caller_parts['problem'] as $row) {
            $text = trim((string) ($row['text'] ?? ''));
            if ($text !== '') {
                $hasProblem = true;
                break;
            }
        }
    }

    if (!$hasProblem) {
        wp_send_json_error(['message' => 'Mindestens ein problem-Baustein ist erforderlich.'], 400);
    }

    if ($caller_profiles_loaded) {
        try {
            lsttraining_einsatzeditor_ensure_profile_assignment_table($pdo);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    $seenSteps = [];
    foreach ($followups as $row) {
        $step = isset($row['step_no']) ? (int) $row['step_no'] : 0;
        $text = trim((string) ($row['text'] ?? ''));

        if ($step <= 0) {
            wp_send_json_error(['message' => 'Follow-up step_no muss größer als 0 sein.'], 400);
        }
        if ($text === '') {
            wp_send_json_error(['message' => 'Follow-up Text darf nicht leer sein.'], 400);
        }
        if (isset($seenSteps[$step])) {
            wp_send_json_error(['message' => 'Follow-up step_no muss eindeutig sein.'], 400);
        }
        $seenSteps[$step] = true;
    }

    $allowedSeasons = ['spring', 'summer', 'autumn', 'winter'];
    $allowedWeather = ['clear', 'cloudy', 'rain', 'snow', 'storm', 'windy', 'fog', 'cold', 'hot'];
    $allowedFollowupKinds = ['dispatcher_question', 'caller_answer', 'update', 'unit_report'];
    $allowedFollowupSpeakers = ['caller', 'fire_unit', 'ems_unit', 'police', 'dispatch', 'system'];
    $allowedFollowupTriggers = ['random', 'on_unit_arrival', 'on_missing_resources', 'on_dispatcher_question', 'on_transport_started', 'on_hospital_arrival', 'on_vehicle_available', 'manual'];

    $callerWho = lsttraining_einsatzeditor_first_enabled_text($caller_parts['person'] ?? []);
    $callerWhere = lsttraining_einsatzeditor_first_enabled_text($caller_parts['location'] ?? []);
    $callerWhat = lsttraining_einsatzeditor_first_enabled_text($caller_parts['problem'] ?? []);

    if ($callerWho === '') { $callerWho = '-'; }
    if ($callerWhere === '') { $callerWhere = '-'; }
    if ($callerWhat === '') { $callerWhat = '-'; }

    $anrufertext = '';

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare('
                UPDATE einsaetze
                   SET title = ?, description = ?, einsatzart = ?, einsatztyp = ?, enabled = ?,
                       scope_type = ?, landscape_tags_json = ?, poi_type = ?, fixed_latitude = ?, fixed_longitude = ?, fixed_radius_m = ?,
                       caller_who = ?, caller_where = ?, caller_what = ?, anrufertext = ?, caller_template_text = ?,
                       lagemeldung = ?, patientenzahl = ?, patient_anforderung = ?, notarzt_benoetigt = ?, feuerwehr_benoetigt = ?,
                       ' . ($hasBaseRequiredResourcesColumn ? 'base_required_resources_json = ?,' : '') . '
                       ' . ($hasPatientProfileColumn ? 'patient_profile_json = ?,' : '') . '
                       poi_tag = ?, tags_json = ?, updated_at = NOW()
                 WHERE id = ?
            ');

            $params = [
                $title,
                $description !== '' ? $description : null,
                $einsatzart,
                $einsatztyp,
                $enabled,
                $scope_type,
                $landscape_tags_json,
                $poi_type !== '' ? $poi_type : null,
                $fixed_latitude,
                $fixed_longitude,
                $fixed_radius_m,
                $callerWho,
                $callerWhere,
                $callerWhat,
                $anrufertext,
                $caller_template_text,
                $lagemeldung,
                $patientenzahl,
                $patient_anforderung !== '' ? $patient_anforderung : null,
                $notarzt_benoetigt,
                $feuerwehr_benoetigt,
            ];
            if ($hasBaseRequiredResourcesColumn) {
                $params[] = $base_required_resources_json;
            }
            if ($hasPatientProfileColumn) {
                $params[] = $patient_profile_json;
            }
            $params = array_merge($params, [
                $poi_type !== '' ? $poi_type : null,
                $tags_json,
                $id,
            ]);
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO einsaetze
                    (title, description, einsatzart, einsatztyp, enabled,
                     scope_type, landscape_tags_json, poi_type, fixed_latitude, fixed_longitude, fixed_radius_m,
                     caller_who, caller_where, caller_what, anrufertext, caller_template_text,
                     lagemeldung, patientenzahl, patient_anforderung, notarzt_benoetigt, feuerwehr_benoetigt,
                     ' . ($hasBaseRequiredResourcesColumn ? 'base_required_resources_json,' : '') . '
                     ' . ($hasPatientProfileColumn ? 'patient_profile_json,' : '') . '
                     poi_tag, tags_json, created_at, updated_at, weight_base)
                VALUES
                    (?,?,?,?,?,
                     ?,?,?,?,?,?,
                     ?,?,?,?,?,
                     ?,?,?,?,?,
                     ' . ($hasBaseRequiredResourcesColumn ? '?,' : '') . '
                     ' . ($hasPatientProfileColumn ? '?,' : '') . '?,?,NOW(),NOW(),100)
            ');

            $params = [
                $title,
                $description !== '' ? $description : null,
                $einsatzart,
                $einsatztyp,
                $enabled,
                $scope_type,
                $landscape_tags_json,
                $poi_type !== '' ? $poi_type : null,
                $fixed_latitude,
                $fixed_longitude,
                $fixed_radius_m,
                $callerWho,
                $callerWhere,
                $callerWhat,
                $anrufertext,
                $caller_template_text,
                $lagemeldung,
                $patientenzahl,
                $patient_anforderung !== '' ? $patient_anforderung : null,
                $notarzt_benoetigt,
                $feuerwehr_benoetigt,
            ];
            if ($hasBaseRequiredResourcesColumn) {
                $params[] = $base_required_resources_json;
            }
            if ($hasPatientProfileColumn) {
                $params[] = $patient_profile_json;
            }
            $params = array_merge($params, [
                $poi_type !== '' ? $poi_type : null,
                $tags_json,
            ]);
            $stmt->execute($params);

            $id = (int) $pdo->lastInsertId();
        }

        $deleteTables = ['einsatz_seasons', 'einsatz_weather_conditions', 'einsatz_caller_parts', 'einsatz_followups'];
        if ($caller_profiles_loaded && lsttraining_einsatzeditor_table_exists($pdo, 'einsatz_anrufer_profiles')) {
            $deleteTables[] = 'einsatz_anrufer_profiles';
        }

        foreach ($deleteTables as $table) {
            $del = $pdo->prepare("DELETE FROM {$table} WHERE einsatz_id = ?");
            $del->execute([$id]);
        }

        if ($seasons) {
            $ins = $pdo->prepare('INSERT INTO einsatz_seasons (einsatz_id, season) VALUES (?, ?)');
            foreach (array_values(array_unique(array_map('strval', $seasons))) as $season) {
                if (!in_array($season, $allowedSeasons, true)) {
                    continue;
                }
                $ins->execute([$id, $season]);
            }
        }

        if ($weather_conditions) {
            $ins = $pdo->prepare('INSERT INTO einsatz_weather_conditions (einsatz_id, weather_type) VALUES (?, ?)');
            foreach (array_values(array_unique(array_map('strval', $weather_conditions))) as $weather) {
                if (!in_array($weather, $allowedWeather, true)) {
                    continue;
                }
                $ins->execute([$id, $weather]);
            }
        }

        $insCaller = $pdo->prepare('
            INSERT INTO einsatz_caller_parts (einsatz_id, part_key, text, sort_order, enabled)
            VALUES (?, ?, ?, ?, ?)
        ');

        foreach ($allowedPartKeys as $partKey) {
            foreach ($caller_parts[$partKey] as $row) {
                $text = trim((string) ($row['text'] ?? ''));
                $sortOrder = isset($row['sort_order']) ? (int) $row['sort_order'] : 0;
                $enabledRow = isset($row['enabled']) ? (int) $row['enabled'] : 1;

                if ($text === '') {
                    continue;
                }

                $insCaller->execute([
                    $id,
                    $partKey,
                    $text,
                    $sortOrder,
                    $enabledRow ? 1 : 0,
                ]);
            }
        }

        if ($followups) {
            $ins = $pdo->prepare('
                INSERT INTO einsatz_followups
                    (einsatz_id, label, step_no, kind, text, min_after_sec, max_after_sec, condition_json,
                     probability_percent, speaker_type, trigger_mode, required_resources_json, effect_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($followups as $followupIndex => $row) {
                $kind = sanitize_text_field((string) ($row['kind'] ?? 'update'));
                if (!in_array($kind, $allowedFollowupKinds, true)) {
                    $kind = 'update';
                }

                $speakerType = sanitize_text_field((string) ($row['speaker_type'] ?? 'system'));
                if (!in_array($speakerType, $allowedFollowupSpeakers, true)) {
                    $speakerType = 'system';
                }

                $triggerMode = sanitize_text_field((string) ($row['trigger_mode'] ?? 'random'));
                if (!in_array($triggerMode, $allowedFollowupTriggers, true)) {
                    $triggerMode = 'random';
                }

                $probability = isset($row['probability_percent']) && $row['probability_percent'] !== ''
                    ? max(0, min(100, (int) $row['probability_percent']))
                    : 100;
                $stepNo = isset($row['step_no']) && $row['step_no'] !== ''
                    ? max(1, (int) $row['step_no'])
                    : ((int) $followupIndex + 1);

                $ins->execute([
                    $id,
                    trim((string) ($row['label'] ?? '')) !== '' ? sanitize_text_field((string) $row['label']) : null,
                    $stepNo,
                    $kind,
                    sanitize_textarea_field((string) ($row['text'] ?? '')),
                    (isset($row['min_after_sec']) && $row['min_after_sec'] !== '') ? max(0, (int) $row['min_after_sec']) : null,
                    (isset($row['max_after_sec']) && $row['max_after_sec'] !== '') ? max(0, (int) $row['max_after_sec']) : null,
                    lsttraining_einsatzeditor_normalize_optional_json(isset($row['condition_json']) ? (string) $row['condition_json'] : null),
                    $probability,
                    $speakerType,
                    $triggerMode,
                    lsttraining_einsatzeditor_normalize_resources_json(isset($row['required_resources_json']) ? (string) $row['required_resources_json'] : null),
                    lsttraining_einsatzeditor_normalize_effect_json(isset($row['effect_json']) ? (string) $row['effect_json'] : null),
                ]);
            }
        }

        if ($caller_profiles_loaded) {
            lsttraining_einsatzeditor_require_table($pdo, 'einsatz_anrufer_profiles', 'Zuordnungstabelle');
            lsttraining_einsatzeditor_require_table($pdo, 'anrufer_profile', 'Basistabelle');

            if ($caller_profiles) {
                $profileExists = $pdo->prepare('SELECT COUNT(*) FROM anrufer_profile WHERE id = ?');
                $insProfile = $pdo->prepare('
                    INSERT INTO einsatz_anrufer_profiles (einsatz_id, profile_id, weight)
                    VALUES (?, ?, ?)
                ');
                $seenProfiles = [];
                foreach ($caller_profiles as $profileRow) {
                    $profileId = isset($profileRow['profile_id']) ? (int) $profileRow['profile_id'] : (int) ($profileRow['id'] ?? 0);
                    if ($profileId <= 0 || isset($seenProfiles[$profileId])) {
                        continue;
                    }

                    $profileExists->execute([$profileId]);
                    if ((int) $profileExists->fetchColumn() <= 0) {
                        throw new RuntimeException('Anruferprofil #' . $profileId . ' existiert nicht und kann nicht zugeordnet werden.');
                    }

                    $weight = isset($profileRow['weight']) ? max(1, min(1000, (int) $profileRow['weight'])) : 100;
                    $insProfile->execute([$id, $profileId, $weight]);
                    $seenProfiles[$profileId] = true;
                }
            }
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'einsatz',
            'entity_id'   => $id,
            'action'      => (isset($_POST['id']) && absint($_POST['id']) > 0) ? 'update' : 'create',
            'meta'        => [
                'einsatzart' => $einsatzart,
                'einsatztyp' => $einsatztyp,
                'scope_type' => $scope_type,
            ],
        ]);

        wp_send_json_success([
            'id'   => $id,
            'item' => lsttraining_einsatzeditor_fetch_one($pdo, $id),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        wp_send_json_error([
            'message' => 'Speichern fehlgeschlagen: ' . $e->getMessage()
        ], 500);
    }
});

add_action('wp_ajax_lst_delete_einsatz', function () {
    lsttraining_ajax_guard([
        'area'         => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field'  => 'nonce',
        'method'       => 'POST',
    ]);

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'Einsatz-ID fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $stmt = $pdo->prepare('DELETE FROM einsaetze WHERE id = ?');
    $stmt->execute([$id]);

    lsttraining_log_activity([
        'entity_type' => 'einsatz',
        'entity_id'   => $id,
        'action'      => 'delete',
        'meta'        => ['source' => 'ajax_einsaetze'],
    ]);

    wp_send_json_success(['id' => $id]);
});
