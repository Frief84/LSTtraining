<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Einheitliche CRUD-API fuer die zentralen Verwaltungsobjekte.
 * Alle Tabellen- und Spaltennamen stammen ausschliesslich aus den Whitelists
 * dieser Datei. Nutzereingaben werden nur als gebundene Werte verwendet.
 */

function lst_manage_resource_configs(): array {
    static $configs = null;
    if ($configs !== null) {
        return $configs;
    }

    $configs = [
        'leitstellen' => [
            'table' => 'leitstellen',
            'area' => 'leitstellen',
            'object_type' => 'leitstelle',
            'label' => 'Leitstelle',
            'search_field' => 'name',
            'order' => 'name ASC, id ASC',
            'list_fields' => ['id', 'name', 'ort', 'bundesland', 'land', 'latitude', 'longitude', 'created_at'],
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 255],
                'ort' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                'bundesland' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                'land' => ['type' => 'string', 'nullable' => true, 'max' => 100, 'default' => 'Deutschland'],
                'latitude' => ['type' => 'latitude', 'nullable' => true],
                'longitude' => ['type' => 'longitude', 'nullable' => true],
                'geojson' => ['type' => 'json', 'nullable' => true],
                'available_hospitals' => ['type' => 'id_list_json', 'default' => []],
                'police_vehicle_image' => ['type' => 'string', 'nullable' => true, 'max' => 255, 'default' => 'img/fahrzeug/default.png'],
                'police_signal_lights_json' => ['type' => 'signal_json', 'nullable' => true],
                'rescue_vehicle_image' => ['type' => 'string', 'nullable' => true, 'max' => 255, 'default' => 'img/fahrzeug/default.png'],
                'rescue_signal_lights_json' => ['type' => 'signal_json', 'nullable' => true],
            ],
            'relations' => [
                'nebenleitstellen' => ['table' => 'leitstelle_nebenleitstellen', 'source_col' => 'leitstelle_id', 'target_col' => 'nebenleitstelle_id', 'target_table' => 'nebenleitstellen'],
                'wachen' => ['table' => 'wache_leitstellen', 'source_col' => 'leitstelle_id', 'target_col' => 'wache_id', 'target_table' => 'wachen'],
            ],
        ],
        'nebenleitstellen' => [
            'table' => 'nebenleitstellen',
            'area' => 'nebenstellen',
            'object_type' => 'nebenstelle',
            'label' => 'Nebenleitstelle',
            'search_field' => 'name',
            'order' => 'name ASC, id ASC',
            'list_fields' => ['id', 'name', 'zustandigkeit', 'einwohner', 'flaeche_km2', 'gps', 'nachbarleitstelle', 'created_at'],
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 255],
                'aufgaben' => ['type' => 'text', 'nullable' => true],
                'zustandigkeit' => ['type' => 'text', 'nullable' => true],
                'standorte' => ['type' => 'text', 'nullable' => true],
                'einwohner' => ['type' => 'int', 'nullable' => true, 'min' => 0],
                'flaeche_km2' => ['type' => 'float', 'nullable' => true, 'min' => 0],
                'gps' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                'nachbarleitstelle' => ['type' => 'bool', 'nullable' => true],
                'geojson' => ['type' => 'json', 'nullable' => true],
            ],
            'relations' => [
                'leitstellen' => ['table' => 'leitstelle_nebenleitstellen', 'source_col' => 'nebenleitstelle_id', 'target_col' => 'leitstelle_id', 'target_table' => 'leitstellen'],
                'wachen' => ['table' => 'wache_nebenleitstellen', 'source_col' => 'nebenleitstelle_id', 'target_col' => 'wache_id', 'target_table' => 'wachen'],
            ],
        ],
        'wachen' => [
            'table' => 'wachen',
            'area' => 'wachen',
            'object_type' => 'wache',
            'label' => 'Wache',
            'search_field' => 'name',
            'order' => 'name ASC, id ASC',
            'list_fields' => ['id', 'name', 'typ', 'bundesland', 'land', 'latitude', 'longitude', 'exists_in_reality', 'created_at'],
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 255],
                'typ' => ['type' => 'string', 'required' => true, 'max' => 50],
                'bundesland' => ['type' => 'string', 'nullable' => true, 'max' => 50],
                'latitude' => ['type' => 'latitude', 'required' => true],
                'longitude' => ['type' => 'longitude', 'required' => true],
                'land' => ['type' => 'string', 'nullable' => true, 'max' => 64, 'default' => 'Deutschland'],
                'arrival_pos' => ['type' => 'string', 'nullable' => true, 'max' => 50],
                'departure_pos' => ['type' => 'string', 'nullable' => true, 'max' => 50],
                'bild_datei' => ['type' => 'string', 'max' => 255, 'default' => ''],
                'exists_in_reality' => ['type' => 'bool', 'default' => true],
                'source_note' => ['type' => 'string', 'nullable' => true, 'max' => 255],
            ],
            'relations' => [
                'leitstellen' => ['table' => 'wache_leitstellen', 'source_col' => 'wache_id', 'target_col' => 'leitstelle_id', 'target_table' => 'leitstellen'],
                'nebenleitstellen' => ['table' => 'wache_nebenleitstellen', 'source_col' => 'wache_id', 'target_col' => 'nebenleitstelle_id', 'target_table' => 'nebenleitstellen'],
            ],
        ],
        'fahrzeuge' => [
            'table' => 'fahrzeuge',
            'area' => 'fahrzeuge',
            'object_type' => 'fahrzeug',
            'label' => 'Fahrzeug',
            'search_field' => 'rufname',
            'order' => 'rufname ASC, id ASC',
            'list_fields' => ['id', 'wache_id', 'rufname', 'fahrzeugtyp', 'status', 'fms_status', 'sondersignal', 'is_first_responder', 'created_at'],
            'fields' => [
                'wache_id' => ['type' => 'id', 'required' => true],
                'rufname' => ['type' => 'string', 'required' => true, 'max' => 100],
                'fahrzeugtyp' => ['type' => 'string', 'required' => true, 'max' => 100],
                'source_note' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                'is_first_responder' => ['type' => 'bool', 'default' => false],
                'status' => ['type' => 'enum', 'allowed' => ['frei', 'besetzt', 'einsatzbereit', 'nicht einsatzbereit'], 'default' => 'frei'],
                'fms_status' => ['type' => 'enum', 'allowed' => ['1', '2', '3', '4', '5', '6'], 'default' => '2'],
                'sondersignal' => ['type' => 'bool', 'default' => false],
                'dienstzeiten' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                'latitude' => ['type' => 'latitude', 'nullable' => true],
                'longitude' => ['type' => 'longitude', 'nullable' => true],
                'bild_datei' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                'signal_lights_json' => ['type' => 'signal_json', 'nullable' => true],
            ],
            'relations' => [],
        ],
        'krankenhaeuser' => [
            'table' => 'krankenhaeuser',
            'area' => 'hospitals',
            'object_type' => 'krankenhaus',
            'label' => 'Krankenhaus',
            'search_field' => 'name',
            'order' => 'name ASC, id ASC',
            'list_fields' => ['id', 'poi_id', 'name', 'latitude', 'longitude', 'versorgungsstufe', 'trauma_level', 'helipad', 'last_update', 'created_at'],
            'fields' => [
                'poi_id' => ['type' => 'string', 'max' => 50],
                'name' => ['type' => 'string', 'required' => true, 'max' => 255],
                'latitude' => ['type' => 'latitude', 'required' => true],
                'longitude' => ['type' => 'longitude', 'required' => true],
                'versorgungsstufe' => ['type' => 'enum', 'allowed' => ['Grundversorgung', 'Schwerpunktversorger', 'Maximalversorger'], 'default' => 'Grundversorgung'],
                'trauma_level' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 9],
                'helipad' => ['type' => 'bool', 'default' => false],
                'departments' => ['type' => 'json', 'default' => []],
            ],
            'relations' => [],
        ],
    ];

    return $configs;
}

function lst_manage_get_config(string $resource): ?array {
    $configs = lst_manage_resource_configs();
    return $configs[$resource] ?? null;
}

add_action('rest_api_init', static function (): void {
    $resource_pattern = '(?P<resource>leitstellen|nebenleitstellen|wachen|fahrzeuge|krankenhaeuser)';

    register_rest_route('lst/v1', '/verwaltung/' . $resource_pattern, [
        [
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'lst_manage_list_resources',
            'permission_callback' => 'lst_manage_can_access_resource',
            'args' => [
                'search' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['required' => false, 'sanitize_callback' => 'absint', 'default' => 1],
                'per_page' => ['required' => false, 'sanitize_callback' => 'absint', 'default' => 50],
            ],
        ],
        [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'lst_manage_create_resource',
            'permission_callback' => 'lst_manage_can_access_resource',
        ],
    ]);

    register_rest_route('lst/v1', '/verwaltung/' . $resource_pattern . '/(?P<id>\d+)', [
        [
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'lst_manage_get_resource',
            'permission_callback' => 'lst_manage_can_access_resource',
        ],
        [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => 'lst_manage_update_resource',
            'permission_callback' => 'lst_manage_can_access_resource',
        ],
        [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => 'lst_manage_delete_resource',
            'permission_callback' => 'lst_manage_can_access_resource',
            'args' => [
                'confirm' => ['required' => true, 'validate_callback' => static fn($value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true],
            ],
        ],
    ]);
});

/** @return bool|WP_Error */
function lst_manage_can_access_resource(WP_REST_Request $request) {
    if (!is_user_logged_in()) {
        return new WP_Error('lst_manage_not_logged_in', 'Anmeldung erforderlich.', ['status' => 401]);
    }

    $config = lst_manage_get_config((string) $request->get_param('resource'));
    if (!$config) {
        return new WP_Error('lst_manage_unknown_resource', 'Unbekannte Ressource.', ['status' => 404]);
    }

    if (current_user_can('manage_options') || lsttraining_user_can((string) $config['area'])) {
        return true;
    }

    return new WP_Error('lst_manage_forbidden', 'Keine Berechtigung fuer diesen Bereich.', ['status' => 403]);
}

function lst_manage_success($data, int $status = 200): WP_REST_Response {
    $response = new WP_REST_Response(['ok' => true, 'data' => $data], $status);
    $response->header('Cache-Control', 'no-store, private');
    return $response;
}

function lst_manage_error(string $code, string $message, int $status): WP_REST_Response {
    $response = new WP_REST_Response(['ok' => false, 'error' => $code, 'message' => $message], $status);
    $response->header('Cache-Control', 'no-store, private');
    return $response;
}

function lst_manage_connection() {
    require_once plugin_dir_path(__FILE__) . 'db.php';
    return lsttraining_get_connection();
}

function lst_manage_clean_ids($value): array {
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('absint', $value))));
}

function lst_manage_normalize_signal_json($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_string($value)) {
        $decoded = json_decode(wp_unslash($value), true);
    } else {
        $decoded = $value;
    }
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Ungueltige Signallicht-Konfiguration.');
    }
    $lights = is_array($decoded['lights'] ?? null) ? $decoded['lights'] : (array_values($decoded) === $decoded ? $decoded : []);
    $normalized = [];
    foreach ($lights as $light) {
        if (!is_array($light) || !is_numeric($light['x'] ?? null) || !is_numeric($light['y'] ?? null)) {
            continue;
        }
        $type = sanitize_key((string) ($light['type'] ?? 'beacon'));
        if (!in_array($type, ['beacon', 'strobe', 'bar', 'glow'], true)) {
            $type = 'beacon';
        }
        $normalized[] = [
            'x' => max(0.0, min(1.0, (float) $light['x'])),
            'y' => max(0.0, min(1.0, (float) $light['y'])),
            'type' => $type,
            'interval' => max(120, min(2000, (int) ($light['interval'] ?? 420))),
            'phase' => max(0, min(5000, (int) ($light['phase'] ?? 0))),
            'size' => max(0.4, min(2.5, (float) ($light['size'] ?? 1))),
        ];
    }
    return $normalized ? (string) wp_json_encode(['version' => 1, 'lights' => $normalized]) : null;
}

function lst_manage_normalize_field(string $name, $value, array $definition) {
    $nullable = !empty($definition['nullable']);
    if ($value === null || ($value === '' && $nullable)) {
        return null;
    }

    $type = (string) ($definition['type'] ?? 'string');
    switch ($type) {
        case 'string':
            $normalized = sanitize_text_field((string) $value);
            if (isset($definition['max']) && strlen($normalized) > (int) $definition['max']) {
                throw new InvalidArgumentException($name . ' ist zu lang.');
            }
            return $normalized;

        case 'text':
            return sanitize_textarea_field((string) $value);

        case 'int':
        case 'id':
            if (!is_numeric($value)) {
                throw new InvalidArgumentException($name . ' muss eine Ganzzahl sein.');
            }
            $normalized = (int) $value;
            if ($type === 'id' && $normalized <= 0) {
                throw new InvalidArgumentException($name . ' muss groesser als 0 sein.');
            }
            if (isset($definition['min']) && $normalized < (int) $definition['min']) {
                throw new InvalidArgumentException($name . ' ist zu klein.');
            }
            if (isset($definition['max']) && $normalized > (int) $definition['max']) {
                throw new InvalidArgumentException($name . ' ist zu gross.');
            }
            return $normalized;

        case 'float':
        case 'latitude':
        case 'longitude':
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new InvalidArgumentException($name . ' muss eine Zahl sein.');
            }
            $normalized = (float) $value;
            if ($type === 'latitude' && ($normalized < -90 || $normalized > 90)) {
                throw new InvalidArgumentException('latitude liegt ausserhalb des gueltigen Bereichs.');
            }
            if ($type === 'longitude' && ($normalized < -180 || $normalized > 180)) {
                throw new InvalidArgumentException('longitude liegt ausserhalb des gueltigen Bereichs.');
            }
            if (isset($definition['min']) && $normalized < (float) $definition['min']) {
                throw new InvalidArgumentException($name . ' ist zu klein.');
            }
            return $normalized;

        case 'bool':
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized === null) {
                throw new InvalidArgumentException($name . ' muss true oder false sein.');
            }
            return $normalized ? 1 : 0;

        case 'enum':
            $normalized = sanitize_text_field((string) $value);
            if (!in_array($normalized, (array) ($definition['allowed'] ?? []), true)) {
                throw new InvalidArgumentException('Ungueltiger Wert fuer ' . $name . '.');
            }
            return $normalized;

        case 'json':
            if (is_string($value)) {
                $decoded = json_decode(wp_unslash($value), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException($name . ' enthaelt ungueltiges JSON.');
                }
            } elseif (is_array($value) || is_object($value)) {
                $decoded = $value;
            } else {
                throw new InvalidArgumentException($name . ' muss JSON enthalten.');
            }
            return (string) wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        case 'id_list_json':
            return (string) wp_json_encode(lst_manage_clean_ids($value));

        case 'signal_json':
            return lst_manage_normalize_signal_json($value);
    }

    throw new InvalidArgumentException('Unbekannter Feldtyp fuer ' . $name . '.');
}

function lst_manage_normalize_payload(array $config, array $payload, bool $creating): array {
    $data = [];
    foreach ((array) $config['fields'] as $field => $definition) {
        if (array_key_exists($field, $payload)) {
            $data[$field] = lst_manage_normalize_field($field, $payload[$field], $definition);
        } elseif ($creating && array_key_exists('default', $definition)) {
            $data[$field] = lst_manage_normalize_field($field, $definition['default'], $definition);
        } elseif ($creating && !empty($definition['required'])) {
            throw new InvalidArgumentException($field . ' ist erforderlich.');
        }
        if (!empty($definition['required']) && array_key_exists($field, $data) && ($data[$field] === null || $data[$field] === '')) {
            throw new InvalidArgumentException($field . ' darf nicht leer sein.');
        }
    }
    return $data;
}

function lst_manage_decode_row(array $config, array $row): array {
    foreach ((array) $config['fields'] as $field => $definition) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $type = (string) ($definition['type'] ?? '');
        if (in_array($type, ['int', 'id'], true) && $row[$field] !== null) {
            $row[$field] = (int) $row[$field];
        } elseif (in_array($type, ['float', 'latitude', 'longitude'], true) && $row[$field] !== null) {
            $row[$field] = (float) $row[$field];
        } elseif ($type === 'bool' && $row[$field] !== null) {
            $row[$field] = (bool) $row[$field];
        } elseif (in_array($type, ['json', 'id_list_json', 'signal_json'], true) && $row[$field] !== null && $row[$field] !== '') {
            $decoded = json_decode((string) $row[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$field] = $decoded;
            }
        }
    }
    foreach (['id', 'placed_by_user_id', 'updated_by_user_id', 'verified_by', 'last_editor'] as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null) {
            $row[$field] = (int) $row[$field];
        }
    }
    return $row;
}

function lst_manage_scope_sql(string $resource, string $alias = 'r'): string {
    if (current_user_can('manage_options') || $resource === 'krankenhaeuser') {
        return '1=1';
    }
    $allowed = lsttraining_user_leitstellen_ids();
    if (!$allowed) {
        return '1=0';
    }
    $ids = implode(',', array_map('intval', $allowed));
    return match ($resource) {
        'leitstellen' => "{$alias}.id IN ({$ids})",
        'nebenleitstellen' => "EXISTS (SELECT 1 FROM leitstelle_nebenleitstellen lsn WHERE lsn.nebenleitstelle_id = {$alias}.id AND lsn.leitstelle_id IN ({$ids}))",
        'wachen' => "(EXISTS (SELECT 1 FROM wache_leitstellen wl WHERE wl.wache_id = {$alias}.id AND wl.leitstelle_id IN ({$ids})) OR EXISTS (SELECT 1 FROM wache_nebenleitstellen wn JOIN leitstelle_nebenleitstellen lsn ON lsn.nebenleitstelle_id = wn.nebenleitstelle_id WHERE wn.wache_id = {$alias}.id AND lsn.leitstelle_id IN ({$ids})))",
        'fahrzeuge' => "(EXISTS (SELECT 1 FROM wache_leitstellen wl WHERE wl.wache_id = {$alias}.wache_id AND wl.leitstelle_id IN ({$ids})) OR EXISTS (SELECT 1 FROM wache_nebenleitstellen wn JOIN leitstelle_nebenleitstellen lsn ON lsn.nebenleitstelle_id = wn.nebenleitstelle_id WHERE wn.wache_id = {$alias}.wache_id AND lsn.leitstelle_id IN ({$ids})))",
        default => '1=0',
    };
}

function lst_manage_user_can_object(PDO $pdo, string $resource, int $id): bool {
    if (current_user_can('manage_options')) {
        return true;
    }
    $config = lst_manage_get_config($resource);
    if (!$config || $id <= 0) {
        return false;
    }
    if ($resource === 'leitstellen') {
        return lsttraining_user_can('leitstellen', $id);
    }
    if ($resource === 'krankenhaeuser') {
        return lsttraining_user_can('hospitals');
    }
    return lsttraining_user_can_object($pdo, (string) $config['area'], (string) $config['object_type'], $id);
}

function lst_manage_fetch_relations(PDO $pdo, array $config, int $id): array {
    $relations = [];
    foreach ((array) ($config['relations'] ?? []) as $name => $relation) {
        $stmt = $pdo->prepare('SELECT ' . $relation['target_col'] . ' FROM ' . $relation['table'] . ' WHERE ' . $relation['source_col'] . ' = ? ORDER BY ' . $relation['target_col']);
        $stmt->execute([$id]);
        $relations[$name] = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }
    return $relations;
}

function lst_manage_enrich_relations(PDO $pdo, string $resource, array $row): array {
    $config = lst_manage_get_config($resource);
    $id = (int) ($row['id'] ?? 0);
    $row['relations'] = lst_manage_fetch_relations($pdo, $config ?: [], $id);

    if ($resource === 'wachen') {
        $stmt = $pdo->prepare('SELECT id FROM fahrzeuge WHERE wache_id = ? ORDER BY rufname, id');
        $stmt->execute([$id]);
        $row['relations']['fahrzeuge'] = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    } elseif ($resource === 'krankenhaeuser') {
        $row['relations']['leitstellen'] = [];
        $stmt = $pdo->query('SELECT id, available_hospitals FROM leitstellen ORDER BY id');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $leitstelle) {
            $hospital_ids = json_decode((string) ($leitstelle['available_hospitals'] ?? ''), true);
            if (is_array($hospital_ids) && in_array($id, array_map('intval', $hospital_ids), true)) {
                $row['relations']['leitstellen'][] = (int) $leitstelle['id'];
            }
        }
    }
    return $row;
}

function lst_manage_fetch_one(PDO $pdo, string $resource, int $id): ?array {
    $config = lst_manage_get_config($resource);
    if (!$config) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM ' . $config['table'] . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return lst_manage_enrich_relations($pdo, $resource, lst_manage_decode_row($config, $row));
}

function lst_manage_list_resources(WP_REST_Request $request) {
    $resource = (string) $request->get_param('resource');
    $config = lst_manage_get_config($resource);
    $pdo = lst_manage_connection();
    if (!$config || !$pdo instanceof PDO) {
        return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500);
    }

    $page = max(1, absint($request->get_param('page')) ?: 1);
    $per_page = max(1, min(200, absint($request->get_param('per_page')) ?: 50));
    $offset = ($page - 1) * $per_page;
    $search = trim((string) ($request->get_param('search') ?? ''));
    $where = [lst_manage_scope_sql($resource, 'r')];
    $params = [];
    if ($search !== '') {
        $where[] = 'r.' . $config['search_field'] . ' LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    try {
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $config['table'] . ' r WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $fields = implode(', ', array_map(static fn(string $field): string => 'r.' . $field, $config['list_fields']));
        $stmt = $pdo->prepare('SELECT ' . $fields . ' FROM ' . $config['table'] . ' r WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $config['order'] . ' LIMIT ' . $per_page . ' OFFSET ' . $offset);
        $stmt->execute($params);
        $items = array_map(static fn(array $row): array => lst_manage_decode_row($config, $row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return lst_manage_success([
            'resource' => $resource,
            'items' => $items,
            'pagination' => ['page' => $page, 'per_page' => $per_page, 'total' => $total, 'pages' => (int) ceil($total / $per_page)],
        ]);
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST management list] ' . $e->getMessage());
        return lst_manage_error('db_query_failed', 'Daten konnten nicht geladen werden.', 500);
    }
}

function lst_manage_get_resource(WP_REST_Request $request) {
    $resource = (string) $request->get_param('resource');
    $id = absint($request->get_param('id'));
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) {
        return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500);
    }
    try {
        $row = lst_manage_fetch_one($pdo, $resource, $id);
        if (!$row) {
            return lst_manage_error('not_found', 'Datensatz nicht gefunden.', 404);
        }
        if (!lst_manage_user_can_object($pdo, $resource, $id)) {
            return lst_manage_error('forbidden', 'Keine Berechtigung fuer diesen Datensatz.', 403);
        }
        return lst_manage_success($row);
    } catch (Throwable $e) {
        error_log('[LSTtraining][REST management get] ' . $e->getMessage());
        return lst_manage_error('db_query_failed', 'Datensatz konnte nicht geladen werden.', 500);
    }
}

function lst_manage_validate_relation_ids(PDO $pdo, array $relation, array $ids): void {
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT id FROM ' . $relation['target_table'] . ' WHERE id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (array_diff($ids, $found)) {
        throw new InvalidArgumentException('Eine oder mehrere Zuordnungs-IDs existieren nicht.');
    }
}

function lst_manage_sync_relations(PDO $pdo, array $config, int $id, array $payload): void {
    foreach ((array) ($config['relations'] ?? []) as $name => $relation) {
        if (!array_key_exists($name, $payload)) {
            continue;
        }
        $ids = lst_manage_clean_ids($payload[$name]);
        if (($config['table'] ?? '') === 'leitstellen' && $name === 'nebenleitstellen' && $ids) {
            $name_stmt = $pdo->prepare('SELECT name FROM leitstellen WHERE id = ? LIMIT 1');
            $name_stmt->execute([$id]);
            $own_name = trim((string) $name_stmt->fetchColumn());
            if ($own_name !== '') {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $self_stmt = $pdo->prepare('SELECT id FROM nebenleitstellen WHERE id IN (' . $placeholders . ') AND LOWER(TRIM(name)) = LOWER(TRIM(?))');
                $self_stmt->execute(array_merge($ids, [$own_name]));
                $self_ids = array_map('intval', $self_stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $ids = array_values(array_diff($ids, $self_ids));
            }
        }
        lst_manage_validate_relation_ids($pdo, $relation, $ids);

        $pdo->prepare('DELETE FROM ' . $relation['table'] . ' WHERE ' . $relation['source_col'] . ' = ?')->execute([$id]);
        if (!$ids) {
            continue;
        }
        $insert = $pdo->prepare('INSERT INTO ' . $relation['table'] . ' (' . $relation['source_col'] . ', ' . $relation['target_col'] . ') VALUES (?, ?)');
        foreach ($ids as $target_id) {
            $insert->execute([$id, $target_id]);
        }
    }
}

/** @return true|WP_REST_Response */
function lst_manage_validate_write_scope(PDO $pdo, string $resource, int $id, array $payload, bool $creating) {
    if (!$creating && !lst_manage_user_can_object($pdo, $resource, $id)) {
        return lst_manage_error('forbidden', 'Keine Berechtigung fuer diesen Datensatz.', 403);
    }

    if ($resource === 'leitstellen' && $creating && !current_user_can('manage_options')) {
        return lst_manage_error('forbidden', 'Neue Leitstellen duerfen nur Administratoren anlegen.', 403);
    }

    if ($resource === 'nebenleitstellen' && array_key_exists('leitstellen', $payload)) {
        $ids = lst_manage_clean_ids($payload['leitstellen']);
        if (!lsttraining_user_can_all_leitstellen('nebenstellen', $ids)) {
            return lst_manage_error('forbidden', 'Keine Berechtigung fuer die Leitstellen-Zuordnung.', 403);
        }
    }
    if ($resource === 'nebenleitstellen' && $creating && !current_user_can('manage_options') && empty($payload['leitstellen'])) {
        return lst_manage_error('validation_failed', 'Beim Anlegen ist mindestens eine Leitstelle erforderlich.', 400);
    }

    if (in_array($resource, ['leitstellen', 'nebenleitstellen'], true) && array_key_exists('wachen', $payload) && !current_user_can('manage_options')) {
        foreach (lst_manage_clean_ids($payload['wachen']) as $wache_id) {
            if (!lsttraining_user_can_object($pdo, 'wachen', 'wache', $wache_id)) {
                return lst_manage_error('forbidden', 'Keine Berechtigung fuer eine zugeordnete Wache.', 403);
            }
        }
    }

    if ($resource === 'wachen') {
        $leitstellen = array_key_exists('leitstellen', $payload) ? lst_manage_clean_ids($payload['leitstellen']) : [];
        $nebenleitstellen = array_key_exists('nebenleitstellen', $payload) ? lst_manage_clean_ids($payload['nebenleitstellen']) : [];
        if ($creating || array_key_exists('leitstellen', $payload) || array_key_exists('nebenleitstellen', $payload)) {
            $scope = lsttraining_assignment_leitstellen_ids($pdo, $leitstellen, $nebenleitstellen);
            if (!lsttraining_user_can_all_leitstellen('wachen', $scope)) {
                return lst_manage_error('forbidden', 'Keine Berechtigung fuer die Wachen-Zuordnung.', 403);
            }
        }
    }

    if ($resource === 'fahrzeuge' && array_key_exists('wache_id', $payload)) {
        $wache_id = absint($payload['wache_id']);
        if (!lsttraining_user_can_object($pdo, 'fahrzeuge', 'wache', $wache_id)) {
            return lst_manage_error('forbidden', 'Keine Berechtigung fuer die Zielwache.', 403);
        }
    }

    return true;
}

function lst_manage_check_unique(PDO $pdo, string $resource, array $data, int $exclude_id = 0): ?WP_REST_Response {
    if ($resource === 'nebenleitstellen' && isset($data['name'])) {
        $sql = 'SELECT id FROM nebenleitstellen WHERE name = ?' . ($exclude_id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1';
        $params = [$data['name']];
        if ($exclude_id > 0) { $params[] = $exclude_id; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return lst_manage_error('name_conflict', 'Name ist bereits vorhanden.', 409);
        }
    }
    if ($resource === 'fahrzeuge' && isset($data['rufname'], $data['wache_id'])) {
        $sql = 'SELECT id FROM fahrzeuge WHERE wache_id = ? AND TRIM(rufname) = ?' . ($exclude_id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1';
        $params = [(int) $data['wache_id'], (string) $data['rufname']];
        if ($exclude_id > 0) { $params[] = $exclude_id; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return lst_manage_error('rufname_conflict', 'Rufname ist in dieser Wache bereits vorhanden.', 409);
        }
    }
    if ($resource === 'krankenhaeuser' && isset($data['poi_id'])) {
        $sql = 'SELECT id FROM krankenhaeuser WHERE poi_id = ?' . ($exclude_id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1';
        $params = [(string) $data['poi_id']];
        if ($exclude_id > 0) { $params[] = $exclude_id; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return lst_manage_error('poi_id_conflict', 'poi_id ist bereits vorhanden.', 409);
        }
    }
    return null;
}

function lst_manage_validate_hospital_ids(PDO $pdo, array $data): void {
    if (!array_key_exists('available_hospitals', $data)) {
        return;
    }
    $ids = json_decode((string) $data['available_hospitals'], true);
    $ids = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT id FROM krankenhaeuser WHERE id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (array_diff($ids, $found)) {
        throw new InvalidArgumentException('Eine oder mehrere Krankenhaus-IDs existieren nicht.');
    }
}

function lst_manage_create_resource(WP_REST_Request $request) {
    $resource = (string) $request->get_param('resource');
    $config = lst_manage_get_config($resource);
    $payload = $request->get_json_params();
    if (!$config || !is_array($payload)) {
        return lst_manage_error('invalid_json', 'Ein JSON-Objekt ist erforderlich.', 400);
    }
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) {
        return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500);
    }

    try {
        $scope = lst_manage_validate_write_scope($pdo, $resource, 0, $payload, true);
        if ($scope instanceof WP_REST_Response) { return $scope; }
        $data = lst_manage_normalize_payload($config, $payload, true);
        lst_manage_validate_hospital_ids($pdo, $data);
        if ($resource === 'krankenhaeuser' && empty($data['poi_id'])) {
            $data['poi_id'] = 'manual-' . wp_generate_uuid4();
        }
        $conflict = lst_manage_check_unique($pdo, $resource, $data);
        if ($conflict) { return $conflict; }

        if ($resource === 'wachen') {
            $data['placed_by_user_id'] = (int) get_current_user_id();
            $data['updated_by_user_id'] = (int) get_current_user_id();
            $data['updated_at'] = current_time('mysql');
        } elseif ($resource === 'krankenhaeuser') {
            $data['last_editor'] = (int) get_current_user_id();
        }

        $columns = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO ' . $config['table'] . ' (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')');
        $stmt->execute(array_values($data));
        $id = (int) $pdo->lastInsertId();
        lst_manage_sync_relations($pdo, $config, $id, $payload);
        $pdo->commit();

        if (function_exists('lsttraining_log_activity')) {
            lsttraining_log_activity(['entity_type' => $config['object_type'], 'action' => 'create', 'entity_id' => $id, 'meta' => ['source' => 'rest-management']]);
        }
        return lst_manage_success(lst_manage_fetch_one($pdo, $resource, $id), 201);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return lst_manage_error('validation_failed', $e->getMessage(), 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[LSTtraining][REST management create] ' . $e->getMessage());
        $status = $e instanceof PDOException && (string) $e->getCode() === '23000' ? 409 : 500;
        return lst_manage_error($status === 409 ? 'conflict' : 'db_write_failed', $status === 409 ? 'Datensatz kollidiert mit vorhandenen Daten.' : 'Datensatz konnte nicht angelegt werden.', $status);
    }
}

function lst_manage_update_resource(WP_REST_Request $request) {
    $resource = (string) $request->get_param('resource');
    $id = absint($request->get_param('id'));
    $config = lst_manage_get_config($resource);
    $payload = $request->get_json_params();
    if (!$config || !is_array($payload)) {
        return lst_manage_error('invalid_json', 'Ein JSON-Objekt ist erforderlich.', 400);
    }
    $pdo = lst_manage_connection();
    if (!$pdo instanceof PDO) {
        return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500);
    }

    try {
        if (!lst_manage_fetch_one($pdo, $resource, $id)) {
            return lst_manage_error('not_found', 'Datensatz nicht gefunden.', 404);
        }
        $scope = lst_manage_validate_write_scope($pdo, $resource, $id, $payload, false);
        if ($scope instanceof WP_REST_Response) { return $scope; }
        $data = lst_manage_normalize_payload($config, $payload, false);
        lst_manage_validate_hospital_ids($pdo, $data);

        if ($resource === 'fahrzeuge' && (isset($data['rufname']) || isset($data['wache_id']))) {
            $current = lst_manage_fetch_one($pdo, $resource, $id) ?: [];
            $data_for_unique = [
                'rufname' => $data['rufname'] ?? $current['rufname'] ?? '',
                'wache_id' => $data['wache_id'] ?? $current['wache_id'] ?? 0,
            ];
            $conflict = lst_manage_check_unique($pdo, $resource, $data_for_unique, $id);
        } else {
            $conflict = lst_manage_check_unique($pdo, $resource, $data, $id);
        }
        if ($conflict) { return $conflict; }

        if ($resource === 'wachen') {
            $data['updated_by_user_id'] = (int) get_current_user_id();
            $data['updated_at'] = current_time('mysql');
        } elseif ($resource === 'krankenhaeuser') {
            $data['last_editor'] = (int) get_current_user_id();
            $data['last_update'] = current_time('mysql', true);
        }

        $relation_keys = array_keys((array) ($config['relations'] ?? []));
        $has_relations = (bool) array_intersect($relation_keys, array_keys($payload));
        if (!$data && !$has_relations) {
            return lst_manage_error('no_changes', 'Keine aenderbaren Felder uebermittelt.', 400);
        }

        $pdo->beginTransaction();
        if ($data) {
            $set = implode(', ', array_map(static fn(string $field): string => $field . ' = ?', array_keys($data)));
            $stmt = $pdo->prepare('UPDATE ' . $config['table'] . ' SET ' . $set . ' WHERE id = ?');
            $stmt->execute(array_merge(array_values($data), [$id]));
        }
        lst_manage_sync_relations($pdo, $config, $id, $payload);
        $pdo->commit();

        if (function_exists('lsttraining_log_activity')) {
            lsttraining_log_activity(['entity_type' => $config['object_type'], 'action' => 'update', 'entity_id' => $id, 'meta' => ['source' => 'rest-management', 'fields' => array_keys($payload)]]);
        }
        return lst_manage_success(lst_manage_fetch_one($pdo, $resource, $id));
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return lst_manage_error('validation_failed', $e->getMessage(), 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[LSTtraining][REST management update] ' . $e->getMessage());
        $status = $e instanceof PDOException && (string) $e->getCode() === '23000' ? 409 : 500;
        return lst_manage_error($status === 409 ? 'conflict' : 'db_write_failed', $status === 409 ? 'Aenderung kollidiert mit vorhandenen Daten.' : 'Datensatz konnte nicht gespeichert werden.', $status);
    }
}

function lst_manage_remove_hospital_assignments(PDO $pdo, int $hospital_id): void {
    $stmt = $pdo->query('SELECT id, available_hospitals FROM leitstellen');
    $update = $pdo->prepare('UPDATE leitstellen SET available_hospitals = ? WHERE id = ?');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $ids = json_decode((string) ($row['available_hospitals'] ?? ''), true);
        if (!is_array($ids)) {
            continue;
        }
        $clean = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id !== $hospital_id));
        if (count($clean) !== count($ids)) {
            $update->execute([wp_json_encode($clean), (int) $row['id']]);
        }
    }
}

function lst_manage_delete_resource(WP_REST_Request $request) {
    $resource = (string) $request->get_param('resource');
    $id = absint($request->get_param('id'));
    $config = lst_manage_get_config($resource);
    $pdo = lst_manage_connection();
    if (!$config || !$pdo instanceof PDO) {
        return lst_manage_error('db_connection_failed', 'Datenbankverbindung fehlgeschlagen.', 500);
    }

    try {
        $existing = lst_manage_fetch_one($pdo, $resource, $id);
        if (!$existing) {
            return lst_manage_error('not_found', 'Datensatz nicht gefunden.', 404);
        }
        if (!lst_manage_user_can_object($pdo, $resource, $id)) {
            return lst_manage_error('forbidden', 'Keine Berechtigung fuer diesen Datensatz.', 403);
        }

        $pdo->beginTransaction();
        if ($resource === 'krankenhaeuser') {
            lst_manage_remove_hospital_assignments($pdo, $id);
        }
        $stmt = $pdo->prepare('DELETE FROM ' . $config['table'] . ' WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->commit();

        if (function_exists('lsttraining_log_activity')) {
            lsttraining_log_activity(['entity_type' => $config['object_type'], 'action' => 'delete', 'entity_id' => $id, 'meta' => ['source' => 'rest-management']]);
        }
        return lst_manage_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[LSTtraining][REST management delete] ' . $e->getMessage());
        return lst_manage_error('db_write_failed', 'Datensatz konnte nicht geloescht werden.', 500);
    }
}
