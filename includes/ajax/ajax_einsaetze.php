<?php
/**
 * AJAX: Einsatzeditor
 */

if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/ajax_common.php';

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

function lsttraining_einsatzeditor_group_caller_parts(array $rows): array {
    $grouped = [
        'greeting' => [],
        'person'   => [],
        'location' => [],
        'problem'  => [],
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

    $followupStmt = $pdo->prepare('
        SELECT step_no, kind, text, min_after_sec, max_after_sec, condition_json
        FROM einsatz_followups
        WHERE einsatz_id = ?
        ORDER BY step_no ASC
    ');
    $followupStmt->execute([$id]);

    $einsatz['seasons'] = array_map('strval', $seasonsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $einsatz['weather_conditions'] = array_map('strval', $weatherStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $einsatz['caller_parts'] = lsttraining_einsatzeditor_group_caller_parts($callerRows);
    $einsatz['followups'] = $followupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $einsatz;
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

    $caller_template_text = isset($_POST['caller_template_text']) ? trim((string) wp_unslash($_POST['caller_template_text'])) : '';
    $lagemeldung = isset($_POST['lagemeldung']) ? sanitize_textarea_field(wp_unslash($_POST['lagemeldung'])) : '';

    $patientenzahl = isset($_POST['patientenzahl']) ? (int) $_POST['patientenzahl'] : 0;
    $patient_anforderung = isset($_POST['patient_anforderung']) ? sanitize_text_field(wp_unslash($_POST['patient_anforderung'])) : '';
    $notarzt_benoetigt = isset($_POST['notarzt_benoetigt']) ? (int) $_POST['notarzt_benoetigt'] : 0;
    $feuerwehr_benoetigt = isset($_POST['feuerwehr_benoetigt']) ? (int) $_POST['feuerwehr_benoetigt'] : 0;

    $seasons = isset($_POST['seasons']) ? json_decode(wp_unslash($_POST['seasons']), true) : [];
    $weather_conditions = isset($_POST['weather_conditions']) ? json_decode(wp_unslash($_POST['weather_conditions']), true) : [];
    $caller_parts = isset($_POST['caller_parts']) ? json_decode(wp_unslash($_POST['caller_parts']), true) : [];
    $followups = isset($_POST['followups']) ? json_decode(wp_unslash($_POST['followups']), true) : [];

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
    if ($caller_template_text === '') {
        wp_send_json_error(['message' => 'Anrufer-Template darf nicht leer sein.'], 400);
    }
    if ($lagemeldung === '') {
        wp_send_json_error(['message' => 'Lagemeldung darf nicht leer sein.'], 400);
    }

    $tags_json = lsttraining_einsatzeditor_validate_json_array($tags_json);
    $landscape_tags_json = lsttraining_einsatzeditor_validate_json_array($landscape_tags_json);

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
    if (!is_array($followups)) { $followups = []; }

    $allowedPartKeys = ['greeting', 'person', 'location', 'problem', 'extra'];
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

    $callerWho = lsttraining_einsatzeditor_first_enabled_text($caller_parts['person']);
    $callerWhere = lsttraining_einsatzeditor_first_enabled_text($caller_parts['location']);
    $callerWhat = lsttraining_einsatzeditor_first_enabled_text($caller_parts['problem']);

    if ($callerWho === '') { $callerWho = '-'; }
    if ($callerWhere === '') { $callerWhere = '-'; }
    if ($callerWhat === '') { $callerWhat = '-'; }

    $anrufertext = $caller_template_text;

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare('
                UPDATE einsaetze
                   SET title = ?, description = ?, einsatzart = ?, einsatztyp = ?, enabled = ?,
                       scope_type = ?, landscape_tags_json = ?, poi_type = ?, fixed_latitude = ?, fixed_longitude = ?, fixed_radius_m = ?,
                       caller_who = ?, caller_where = ?, caller_what = ?, anrufertext = ?, caller_template_text = ?,
                       lagemeldung = ?, patientenzahl = ?, patient_anforderung = ?, notarzt_benoetigt = ?, feuerwehr_benoetigt = ?,
                       poi_tag = ?, tags_json = ?, updated_at = NOW()
                 WHERE id = ?
            ');

            $stmt->execute([
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
                $poi_type !== '' ? $poi_type : null,
                $tags_json,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO einsaetze
                    (title, description, einsatzart, einsatztyp, enabled,
                     scope_type, landscape_tags_json, poi_type, fixed_latitude, fixed_longitude, fixed_radius_m,
                     caller_who, caller_where, caller_what, anrufertext, caller_template_text,
                     lagemeldung, patientenzahl, patient_anforderung, notarzt_benoetigt, feuerwehr_benoetigt,
                     poi_tag, tags_json, created_at, updated_at, weight_base)
                VALUES
                    (?,?,?,?,?,
                     ?,?,?,?,?,?,
                     ?,?,?,?,?,?,
                     ?,?,?,?,?,?,
                     ?,?,NOW(),NOW(),100)
            ');

            $stmt->execute([
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
                $poi_type !== '' ? $poi_type : null,
                $tags_json,
            ]);

            $id = (int) $pdo->lastInsertId();
        }

        foreach (['einsatz_seasons', 'einsatz_weather_conditions', 'einsatz_caller_parts', 'einsatz_followups'] as $table) {
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
                INSERT INTO einsatz_followups (einsatz_id, step_no, kind, text, min_after_sec, max_after_sec, condition_json)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($followups as $row) {
                $kind = sanitize_text_field((string) ($row['kind'] ?? 'update'));
                if (!in_array($kind, $allowedFollowupKinds, true)) {
                    $kind = 'update';
                }

                $ins->execute([
                    $id,
                    (int) $row['step_no'],
                    $kind,
                    sanitize_textarea_field((string) ($row['text'] ?? '')),
                    (isset($row['min_after_sec']) && $row['min_after_sec'] !== '') ? (int) $row['min_after_sec'] : null,
                    (isset($row['max_after_sec']) && $row['max_after_sec'] !== '') ? (int) $row['max_after_sec'] : null,
                    trim((string) ($row['condition_json'] ?? '')) !== '' ? (string) $row['condition_json'] : null,
                ]);
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