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

        $pdo = $pdo ?: lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            return;
        }

        try {
            if (
                lsttraining_permissions_table_exists($pdo, 'leitstellen')
                && !lsttraining_permissions_column_exists($pdo, 'leitstellen', 'created_by_user_id')
            ) {
                $pdo->exec('ALTER TABLE leitstellen ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER created_at');
            }

            if (
                lsttraining_permissions_table_exists($pdo, 'user_permissions')
                && !lsttraining_permissions_column_exists($pdo, 'user_permissions', 'can_manage_spielinstanzen')
            ) {
                $pdo->exec('ALTER TABLE user_permissions ADD COLUMN can_manage_spielinstanzen TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit_fahrzeuge');
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `user_leitstelle_permissions` (
                  `user_id` BIGINT UNSIGNED NOT NULL,
                  `leitstelle_id` INT NOT NULL,
                  `can_edit_leitstelle` TINYINT(1) NOT NULL DEFAULT 0,
                  `can_edit_hospitals` TINYINT(1) NOT NULL DEFAULT 0,
                  `can_edit_wachen` TINYINT(1) NOT NULL DEFAULT 0,
                  `can_edit_fahrzeuge` TINYINT(1) NOT NULL DEFAULT 0,
                  `granted_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`user_id`, `leitstelle_id`),
                  KEY `idx_ulp_leitstelle` (`leitstelle_id`),
                  KEY `idx_ulp_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (
                lsttraining_permissions_table_exists($pdo, 'user_permissions')
                && lsttraining_permissions_column_exists($pdo, 'user_permissions', 'leitstellen_ids')
            ) {
                $rows = $pdo->query('
                    SELECT user_id, can_edit_leitstellen, can_edit_hospitals, can_edit_wachen, can_edit_fahrzeuge, leitstellen_ids
                    FROM user_permissions
                    WHERE leitstellen_ids IS NOT NULL AND leitstellen_ids <> \'\'
                ');
                $insert = $pdo->prepare('
                    INSERT IGNORE INTO user_leitstelle_permissions
                        (user_id, leitstelle_id, can_edit_leitstelle, can_edit_hospitals, can_edit_wachen, can_edit_fahrzeuge, granted_by_user_id, granted_at)
                    VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())
                ');
                foreach (($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                    $ids = array_filter(array_map('trim', explode(',', (string) ($row['leitstellen_ids'] ?? ''))), static function ($id): bool {
                        return $id !== '' && ctype_digit($id);
                    });
                    foreach (array_unique($ids) as $leitstelle_id) {
                        $insert->execute([
                            (int) $row['user_id'],
                            (int) $leitstelle_id,
                            (int) ($row['can_edit_leitstellen'] ?? 0),
                            (int) ($row['can_edit_hospitals'] ?? 0),
                            (int) ($row['can_edit_wachen'] ?? 0),
                            (int) ($row['can_edit_fahrzeuge'] ?? 0),
                        ]);
                    }
                }
            }

            $ready = true;
        } catch (Throwable $e) {
            error_log('[LSTtraining][permissions_schema] ' . $e->getMessage());
        }
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

add_action('init', static function (): void {
    lsttraining_permissions_ensure_schema();
}, 5);
