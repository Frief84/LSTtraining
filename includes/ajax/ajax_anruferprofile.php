<?php
if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/ajax_common.php';
require_once dirname(__DIR__) . '/anrufer_names.php';

function lsttraining_ap_part_keys(): array {
    return ['greeting', 'self_intro', 'location_intro', 'problem_intro', 'urgency', 'closing', 'callback_request'];
}

function lsttraining_ap_fetch_one(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM anrufer_profile WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        wp_send_json_error(['message' => 'Anruferprofil nicht gefunden.'], 404);
    }

    $partsStmt = $pdo->prepare('SELECT part_key, text, sort_order, enabled FROM anrufer_profile_parts WHERE profile_id = ? ORDER BY part_key ASC, sort_order ASC, id ASC');
    $partsStmt->execute([$id]);
    $rows = $partsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = array_fill_keys(lsttraining_ap_part_keys(), []);

    foreach ($rows as $row) {
        $key = (string) ($row['part_key'] ?? '');
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = [
            'text' => (string) ($row['text'] ?? ''),
            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
            'enabled' => isset($row['enabled']) ? (int) $row['enabled'] : 1,
        ];
    }

    $item['parts'] = $grouped;
    return $item;
}

add_action('wp_ajax_lst_preview_anruferprofile', function () {
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field' => 'nonce',
        'method' => 'POST',
    ]);

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $parts = isset($_POST['parts']) ? json_decode(wp_unslash($_POST['parts']), true) : [];
    $problem = isset($_POST['problem']) ? sanitize_text_field(wp_unslash($_POST['problem'])) : 'hier ist eine Person gestürzt und hat starke Schmerzen';
    $observation = isset($_POST['observation']) ? sanitize_text_field(wp_unslash($_POST['observation'])) : 'Rauch ist sichtbar';
    $extra = isset($_POST['extra']) ? sanitize_text_field(wp_unslash($_POST['extra'])) : 'Der Zugang ist über den Hinterhof';
    $address = isset($_POST['address_full']) ? sanitize_text_field(wp_unslash($_POST['address_full'])) : 'Musterstraße 12';
    $poiName = isset($_POST['poi_name']) ? sanitize_text_field(wp_unslash($_POST['poi_name'])) : 'Seniorenheim Sonnenhof';
    $companyName = isset($_POST['company_name']) ? sanitize_text_field(wp_unslash($_POST['company_name'])) : 'Firma Beispiel GmbH';
    $genderKey = isset($_POST['gender_key']) ? sanitize_text_field(wp_unslash($_POST['gender_key'])) : null;

    if (!is_array($parts)) {
        $parts = [];
    }

    $examples = [];

    foreach (lsttraining_ap_part_keys() as $partKey) {
        if (!isset($parts[$partKey]) || !is_array($parts[$partKey])) {
            $parts[$partKey] = [];
        }
    }

    for ($i = 0; $i < 3; $i++) {
        $tokens = lsttraining_build_anrufer_tokens($pdo, $genderKey ?: null, [
            'address_full' => $address,
            'poi_name' => $poiName,
            'company_name' => $companyName,
            'problem' => $problem,
            'observation' => $observation,
            'extra' => $extra,
            'location' => $address,
        ]);

        $fragments = [];

        foreach (lsttraining_ap_part_keys() as $partKey) {
            $rows = $parts[$partKey];

            $enabledRows = array_values(array_filter($rows, function ($row) {
                return isset($row['enabled']) ? (int)$row['enabled'] === 1 : true;
            }));

            $source = !empty($enabledRows) ? $enabledRows : $rows;
            if (empty($source)) {
                continue;
            }

            $row = $source[array_rand($source)];
            $text = trim((string)($row['text'] ?? ''));
            if ($text !== '') {
                $fragments[] = lsttraining_fill_anrufer_placeholders($text, $tokens);
            }
        }

        $examples[] = trim((string)preg_replace('/\s+/', ' ', implode(' ', $fragments)));
    }

    wp_send_json_success([
        'examples' => $examples,
    ]);
});

add_action('wp_ajax_lst_get_anruferprofile_list', function () {
    lsttraining_ajax_guard([
    'area' => 'leitstellen',
    'capability' => 'read',
    'nonce_action' => 'lsttraining_leitstellen',
    'nonce_field' => 'nonce',
    'method' => 'POST',
]);

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $search = isset($_POST['search']) ? trim((string) wp_unslash($_POST['search'])) : '';
    $category = isset($_POST['category']) ? trim((string) wp_unslash($_POST['category'])) : '';
    $enabled = isset($_POST['enabled']) ? trim((string) wp_unslash($_POST['enabled'])) : '';

    $sql = 'SELECT id, name, category, tone, uses_name, uses_address, enabled, notes FROM anrufer_profile WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (name LIKE ? OR category LIKE ? OR tone LIKE ? OR id = ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = ctype_digit($search) ? (int) $search : 0;
    }

    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }

    if ($enabled === '0' || $enabled === '1') {
        $sql .= ' AND enabled = ?';
        $params[] = (int) $enabled;
    }

    $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    wp_send_json_success(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
});

add_action('wp_ajax_lst_get_anruferprofile', function () {
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field' => 'nonce',
        'method' => 'POST',
    ]);

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'Profil-ID fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    wp_send_json_success(['item' => lsttraining_ap_fetch_one($pdo, $id)]);
});

add_action('wp_ajax_lst_save_anruferprofile', function () {
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field' => 'nonce',
        'method' => 'POST',
    ]);

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : 'private';
    $tone = isset($_POST['tone']) ? sanitize_text_field(wp_unslash($_POST['tone'])) : 'calm';
    $uses_name = isset($_POST['uses_name']) ? (int) $_POST['uses_name'] : 0;
    $uses_address = isset($_POST['uses_address']) ? (int) $_POST['uses_address'] : 0;
    $uses_poi_name = isset($_POST['uses_poi_name']) ? (int) $_POST['uses_poi_name'] : 0;
    $uses_company_name = isset($_POST['uses_company_name']) ? (int) $_POST['uses_company_name'] : 0;
    $emotion_level = isset($_POST['emotion_level']) ? (int) $_POST['emotion_level'] : 1;
    $enabled = isset($_POST['enabled']) ? (int) $_POST['enabled'] : 0;
    $sort_order = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
    $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
    $parts = isset($_POST['parts']) ? json_decode(wp_unslash($_POST['parts']), true) : [];

    if ($name === '') {
        wp_send_json_error(['message' => 'Name darf nicht leer sein.'], 400);
    }
    if ($emotion_level < 1 || $emotion_level > 4) {
        wp_send_json_error(['message' => 'Emotionslevel muss zwischen 1 und 4 liegen.'], 400);
    }
    if (!is_array($parts)) {
        $parts = [];
    }

    $allowedPartKeys = lsttraining_ap_part_keys();

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE anrufer_profile SET name = ?, category = ?, tone = ?, uses_name = ?, uses_address = ?, uses_poi_name = ?, uses_company_name = ?, emotion_level = ?, enabled = ?, sort_order = ?, notes = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$name, $category, $tone, $uses_name, $uses_address, $uses_poi_name, $uses_company_name, $emotion_level, $enabled, $sort_order, $notes !== '' ? $notes : null, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO anrufer_profile (name, category, tone, uses_name, uses_address, uses_poi_name, uses_company_name, emotion_level, enabled, sort_order, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$name, $category, $tone, $uses_name, $uses_address, $uses_poi_name, $uses_company_name, $emotion_level, $enabled, $sort_order, $notes !== '' ? $notes : null]);
            $id = (int) $pdo->lastInsertId();
        }

        $del = $pdo->prepare('DELETE FROM anrufer_profile_parts WHERE profile_id = ?');
        $del->execute([$id]);

        $ins = $pdo->prepare('INSERT INTO anrufer_profile_parts (profile_id, part_key, text, sort_order, enabled, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
        foreach ($allowedPartKeys as $partKey) {
            $rows = isset($parts[$partKey]) && is_array($parts[$partKey]) ? $parts[$partKey] : [];
            foreach ($rows as $row) {
                $text = trim((string) ($row['text'] ?? ''));
                $rowSort = isset($row['sort_order']) ? (int) $row['sort_order'] : 0;
                $rowEnabled = isset($row['enabled']) ? (int) $row['enabled'] : 1;
                if ($text === '') {
                    continue;
                }
                $ins->execute([$id, $partKey, $text, $rowSort, $rowEnabled ? 1 : 0]);
            }
        }

        $pdo->commit();

        lsttraining_log_activity([
            'entity_type' => 'anruferprofil',
            'entity_id'   => $id,
            'action'      => (isset($_POST['id']) && absint($_POST['id']) > 0) ? 'update' : 'create',
            'meta'        => ['category' => $category, 'tone' => $tone],
        ]);

        wp_send_json_success(['id' => $id, 'item' => lsttraining_ap_fetch_one($pdo, $id)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        wp_send_json_error(['message' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
    }
});

add_action('wp_ajax_lst_delete_anruferprofile', function () {
    lsttraining_ajax_guard([
        'area' => 'leitstellen',
        'nonce_action' => 'lsttraining_leitstellen',
        'nonce_field' => 'nonce',
        'method' => 'POST',
    ]);

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'Profil-ID fehlt.'], 400);
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        wp_send_json_error(['message' => 'DB-Verbindung fehlgeschlagen.'], 500);
    }

    $stmt = $pdo->prepare('DELETE FROM anrufer_profile WHERE id = ?');
    $stmt->execute([$id]);

    lsttraining_log_activity([
        'entity_type' => 'anruferprofil',
        'entity_id'   => $id,
        'action'      => 'delete',
        'meta'        => ['source' => 'ajax_anruferprofile'],
    ]);

    wp_send_json_success(['id' => $id]);
});
