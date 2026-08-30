<?php
/**
 * Globale Rechte-Helpers für LST-Training.
 */

if (!defined('ABSPATH')) { exit; }

require_once plugin_dir_path(__FILE__) . '/db.php';

if (!function_exists('lsttraining_permissions_table_exists')) {
    function lsttraining_permissions_table_exists(PDO $pdo, string $table): bool {
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
}

if (!function_exists('lsttraining_permissions_column_exists')) {
    function lsttraining_permissions_column_exists(PDO $pdo, string $table, string $column): bool {
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
}

if (!function_exists('lsttraining_permissions_index_exists')) {
    function lsttraining_permissions_index_exists(PDO $pdo, string $table, string $index): bool {
        try {
            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
            ');
            $stmt->execute([$table, $index]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('lsttraining_permissions_ensure_schema')) {
    function lsttraining_permissions_ensure_schema(?PDO $pdo = null): void {
        static $ready = false;
        if ($ready) {
            return;
        }

        $ready = true;
    }
}

if (!function_exists('lsttraining_permission_area_columns')) {
    function lsttraining_permission_area_columns(string $area): ?array {
        return match ($area) {
            'leitstellen' => ['global' => 'can_edit_leitstellen', 'scoped' => 'can_edit_leitstelle'],
            'hospitals'   => ['global' => 'can_edit_hospitals', 'scoped' => 'can_edit_hospitals'],
            'wachen'      => ['global' => 'can_edit_wachen', 'scoped' => 'can_edit_wachen'],
            'fahrzeuge'   => ['global' => 'can_edit_fahrzeuge', 'scoped' => 'can_edit_fahrzeuge'],
            'einsaetze'   => ['global' => 'can_edit_leitstellen', 'scoped' => 'can_edit_leitstelle'],
            default       => null,
        };
    }
}

/**
 * Holt einen Datensatz aus user_permissions einmal pro Request.
 */
function lsttraining_get_user_permissions($user_id = 0) {
    static $cache = [];
    $user_id = $user_id ?: get_current_user_id();

    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    $pdo = lsttraining_get_connection();
    if ($pdo instanceof PDO) {
        lsttraining_permissions_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM user_permissions WHERE user_id = ?');
        $stmt->execute([(int) $user_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
    } else {
        $row = false;
    }

    $cache[$user_id] = $row ?: (object) [
        'can_edit_leitstellen' => 0,
        'can_edit_nebenstellen' => 0,
        'can_edit_hospitals' => 0,
        'can_edit_wachen' => 0,
        'can_edit_fahrzeuge' => 0,
        'can_manage_spielinstanzen' => 0,
        'leitstellen_ids' => '',
    ];
    if (!property_exists($cache[$user_id], 'can_manage_spielinstanzen')) {
        $cache[$user_id]->can_manage_spielinstanzen = 0;
    }
    return $cache[$user_id];
}

function lsttraining_user_owns_leitstelle(int $leitstelle_id, int $user_id): bool {
    if ($leitstelle_id <= 0 || $user_id <= 0) {
        return false;
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        return false;
    }
    lsttraining_permissions_ensure_schema($pdo);
    if (!lsttraining_permissions_column_exists($pdo, 'leitstellen', 'created_by_user_id')) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT created_by_user_id FROM leitstellen WHERE id = ? LIMIT 1');
    $stmt->execute([$leitstelle_id]);
    return (int) $stmt->fetchColumn() === $user_id;
}

function lsttraining_user_has_scoped_leitstelle_permission(string $area, int $leitstelle_id, int $user_id): bool {
    $columns = lsttraining_permission_area_columns($area);
    if (!$columns || $leitstelle_id <= 0 || $user_id <= 0) {
        return false;
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        return false;
    }
    lsttraining_permissions_ensure_schema($pdo);

    $column = $columns['scoped'];
    $stmt = $pdo->prepare("SELECT {$column} FROM user_leitstelle_permissions WHERE user_id = ? AND leitstelle_id = ? LIMIT 1");
    $stmt->execute([$user_id, $leitstelle_id]);
    return (int) $stmt->fetchColumn() === 1;
}

function lsttraining_user_has_any_leitstelle_permission(string $area, int $user_id): bool {
    $columns = lsttraining_permission_area_columns($area);
    if (!$columns || $user_id <= 0) {
        return false;
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        return false;
    }
    lsttraining_permissions_ensure_schema($pdo);
    $column = $columns['scoped'];

    $stmt = $pdo->prepare("
        SELECT 1
        FROM user_leitstelle_permissions
        WHERE user_id = ? AND {$column} = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    if (lsttraining_permissions_column_exists($pdo, 'leitstellen', 'created_by_user_id')) {
        $owned = $pdo->prepare('SELECT 1 FROM leitstellen WHERE created_by_user_id = ? LIMIT 1');
        $owned->execute([$user_id]);
        return (bool) $owned->fetchColumn();
    }

    return false;
}

function lsttraining_user_allowed_leitstellen(string $area, ?int $user_id = null): array {
    $user_id = $user_id ?: (int) get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    if (current_user_can('manage_options')) {
        return [];
    }

    $columns = lsttraining_permission_area_columns($area);
    if (!$columns) {
        return [];
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        return [];
    }
    lsttraining_permissions_ensure_schema($pdo);

    $ids = [];
    if (lsttraining_permissions_column_exists($pdo, 'leitstellen', 'created_by_user_id')) {
        $owned = $pdo->prepare('SELECT id FROM leitstellen WHERE created_by_user_id = ?');
        $owned->execute([$user_id]);
        $ids = array_merge($ids, array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    $column = $columns['scoped'];
    $stmt = $pdo->prepare("SELECT leitstelle_id FROM user_leitstelle_permissions WHERE user_id = ? AND {$column} = 1");
    $stmt->execute([$user_id]);
    $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

    return array_values(array_unique(array_filter($ids)));
}

function lsttraining_user_visible_leitstellen(?int $user_id = null): array {
    $user_id = $user_id ?: (int) get_current_user_id();
    if ($user_id <= 0 || current_user_can('manage_options')) {
        return [];
    }

    $ids = [];
    foreach (['leitstellen', 'hospitals', 'wachen', 'fahrzeuge'] as $area) {
        $ids = array_merge($ids, lsttraining_user_allowed_leitstellen($area, $user_id));
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

function lsttraining_user_can_view_leitstellen_admin(?int $user_id = null): bool {
    $user_id = $user_id ?: (int) get_current_user_id();
    if (current_user_can('manage_options')) {
        return true;
    }
    if ($user_id <= 0) {
        return false;
    }

    $perm = lsttraining_get_user_permissions($user_id);
    if (!empty($perm->can_edit_leitstellen)) {
        return true;
    }
    return (bool) lsttraining_user_visible_leitstellen($user_id);
}

function lsttraining_user_can_global_area(string $area, ?int $user_id = null): bool {
    $user_id = $user_id ?: (int) get_current_user_id();
    if (current_user_can('manage_options')) {
        return true;
    }
    if ($user_id <= 0) {
        return false;
    }
    $perm = lsttraining_get_user_permissions($user_id);
    if ($area === 'spielinstanzen') {
        return !empty($perm->can_manage_spielinstanzen);
    }
    if ($area === 'nebenstellen') {
        return !empty($perm->can_edit_nebenstellen);
    }
    $columns = lsttraining_permission_area_columns($area);
    return $columns ? !empty($perm->{$columns['global']}) : false;
}

/**
 * Prüft, ob der aktuelle Nutzer eine Objekt-Kategorie und ggf. eine konkrete Leitstelle bearbeiten darf.
 */
function lsttraining_user_can(string $area, ?int $ls_id = null, ?int $user_id = null): bool {
    $user_id = $user_id ?: (int) get_current_user_id();

    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    if ($user_id <= 0) {
        return false;
    }

    $perm = lsttraining_get_user_permissions($user_id);

    if ($area === 'spielinstanzen') {
        return !empty($perm->can_manage_spielinstanzen);
    }

    if ($area === 'nebenstellen') {
        return !empty($perm->can_edit_nebenstellen);
    }

    $columns = lsttraining_permission_area_columns($area);
    if (!$columns) {
        return false;
    }

    $global_flag = !empty($perm->{$columns['global']});
    if ($ls_id !== null && $ls_id > 0) {
        if (lsttraining_user_owns_leitstelle((int) $ls_id, $user_id)) {
            return true;
        }
        if (lsttraining_user_has_scoped_leitstelle_permission($area, (int) $ls_id, $user_id)) {
            return true;
        }

        return false;
    }

    return $global_flag || lsttraining_user_has_any_leitstelle_permission($area, $user_id);
}

if (!function_exists('lsttraining_current_user_can_edit')) {
    function lsttraining_current_user_can_edit(string $resource): bool {
        return lsttraining_user_can($resource);
    }
}

if (!function_exists('lsttraining_current_user_leitstellen_ids')) {
    function lsttraining_current_user_leitstellen_ids(): array {
        return lsttraining_user_allowed_leitstellen('leitstellen');
    }
}

/**
 * Normalisiert die Leitstellen-Freigaben eines Benutzers.
 */
function lsttraining_user_leitstellen_ids(?int $user_id = null): array {
    return lsttraining_user_visible_leitstellen($user_id);
}

/**
 * Prüft einen Bereich gegen alle Leitstellen, denen ein Objekt zugeordnet ist.
 * Unzugeordnete Objekte werden für Nicht-Admins absichtlich gesperrt.
 */
function lsttraining_user_can_all_leitstellen(string $area, array $leitstellen_ids, ?int $user_id = null): bool {
    $user_id = $user_id ?: (int) get_current_user_id();
    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', $leitstellen_ids),
        static fn(int $id): bool => $id > 0
    )));
    if (!$ids || !lsttraining_user_can($area, null, $user_id)) {
        return false;
    }

    $allowed = lsttraining_user_allowed_leitstellen($area, $user_id);
    return !array_diff($ids, $allowed);
}

/**
 * Ermittelt den Leitstellen-Scope eines Objekts ausschließlich aus der DB.
 */
function lsttraining_object_leitstellen_ids(PDO $pdo, string $object_type, int $object_id): array {
    if ($object_id <= 0) {
        return [];
    }

    $queries = [
        'leitstelle'   => 'SELECT id FROM leitstellen WHERE id = ?',
        'nebenstelle'  => 'SELECT leitstelle_id AS id FROM leitstelle_nebenleitstellen WHERE nebenleitstelle_id = ?',
        'wache'        => 'SELECT leitstelle_id AS id FROM wache_leitstellen WHERE wache_id = ? UNION SELECT ln.leitstelle_id AS id FROM wache_nebenleitstellen wn JOIN leitstelle_nebenleitstellen ln ON ln.nebenleitstelle_id = wn.nebenleitstelle_id WHERE wn.wache_id = ?',
        'fahrzeug'     => 'SELECT wl.leitstelle_id AS id FROM fahrzeuge f JOIN wache_leitstellen wl ON wl.wache_id = f.wache_id WHERE f.id = ? UNION SELECT ln.leitstelle_id AS id FROM fahrzeuge f JOIN wache_nebenleitstellen wn ON wn.wache_id = f.wache_id JOIN leitstelle_nebenleitstellen ln ON ln.nebenleitstelle_id = wn.nebenleitstelle_id WHERE f.id = ?',
    ];
    if (!isset($queries[$object_type])) {
        return [];
    }

    $stmt = $pdo->prepare($queries[$object_type]);
    $placeholder_count = substr_count($queries[$object_type], '?');
    $stmt->execute(array_fill(0, $placeholder_count, $object_id));
    return array_values(array_unique(array_filter(array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
    ))));
}

/**
 * Löst neue Wachen-Zuordnungen in Leitstellen-IDs auf. Request-IDs werden
 * dabei nie als Berechtigungsnachweis akzeptiert, sondern in der DB geprüft.
 */
function lsttraining_assignment_leitstellen_ids(PDO $pdo, array $leitstellen_ids, array $nebenstellen_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $leitstellen_ids))));
    $nls = array_values(array_unique(array_filter(array_map('intval', $nebenstellen_ids))));
    if ($nls) {
        $placeholders = implode(',', array_fill(0, count($nls), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT leitstelle_id FROM leitstelle_nebenleitstellen WHERE nebenleitstelle_id IN ($placeholders)");
        $stmt->execute($nls);
        $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }
    return array_values(array_unique(array_filter($ids)));
}

function lsttraining_user_can_object(PDO $pdo, string $area, string $object_type, int $object_id, ?int $user_id = null): bool {
    return lsttraining_user_can_all_leitstellen(
        $area,
        lsttraining_object_leitstellen_ids($pdo, $object_type, $object_id),
        $user_id
    );
}

add_action('init', static function (): void {
    lsttraining_permissions_ensure_schema();
}, 5);
