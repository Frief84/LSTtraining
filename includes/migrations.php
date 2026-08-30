<?php
if (!defined('ABSPATH')) { exit; }

const LSTTRAINING_SCHEMA_VERSION = 2026082601;
const LSTTRAINING_SCHEMA_OPTION = 'lsttraining_schema_versions';

function lsttraining_schema_fingerprint(): string {
    $mode = (string) get_option('lsttraining_db_mode', 'wordpress');
    $host = $mode === 'extern' ? (string) get_option('lsttraining_ext_host') : (string) DB_HOST;
    $name = $mode === 'extern' ? (string) get_option('lsttraining_ext_name') : (string) DB_NAME;
    return hash('sha256', $mode . '|' . $host . '|' . $name);
}

function lsttraining_schema_installed_version(): int {
    $versions = get_option(LSTTRAINING_SCHEMA_OPTION, []);
    return is_array($versions) ? (int) ($versions[lsttraining_schema_fingerprint()] ?? 0) : 0;
}

function lsttraining_schema_store_version(int $version): void {
    $versions = get_option(LSTTRAINING_SCHEMA_OPTION, []);
    if (!is_array($versions)) {
        $versions = [];
    }
    $versions[lsttraining_schema_fingerprint()] = $version;
    update_option(LSTTRAINING_SCHEMA_OPTION, $versions, false);
}

function lsttraining_migration_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_migration_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_migration_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_migration_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!lsttraining_migration_table_exists($pdo, $table) || lsttraining_migration_column_exists($pdo, $table, $column)) {
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new InvalidArgumentException('Ungültiger Tabellen- oder Spaltenname.');
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function lsttraining_migration_execute_baseline(PDO $pdo): void {
    if (lsttraining_migration_table_exists($pdo, 'leitstellen')) {
        return;
    }

    $schema_file = LSTTRAINING_PATH . 'database/schema.sql';
    if (!is_readable($schema_file)) {
        throw new RuntimeException('database/schema.sql wurde nicht gefunden.');
    }
    $sql = file_get_contents($schema_file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('database/schema.sql ist leer oder nicht lesbar.');
    }
    try {
        $pdo->exec($sql);
    } finally {
        try {
            $pdo->exec('SET foreign_key_checks = 1');
        } catch (Throwable $ignored) {
            // Die Verbindung wird nach dem Request geschlossen.
        }
    }
}

function lsttraining_migration_prepare_name_pool(PDO $pdo): void {
    if (!lsttraining_migration_table_exists($pdo, 'anrufer_name_pool') || lsttraining_migration_index_exists($pdo, 'anrufer_name_pool', 'uk_anp_name')) {
        return;
    }
    // Frühere wiederholte Schema-Importe konnten identische Seed-Zeilen anlegen.
    $pdo->exec('DELETE newer FROM anrufer_name_pool newer INNER JOIN anrufer_name_pool older ON older.gender_key = newer.gender_key AND older.first_name = newer.first_name AND older.last_name = newer.last_name AND older.id < newer.id');
    $pdo->exec('ALTER TABLE anrufer_name_pool ADD UNIQUE INDEX uk_anp_name (gender_key, first_name, last_name)');
}

function lsttraining_migration_2026082601(PDO $pdo): void {
    $columns = [
        ['leitstellen', 'police_vehicle_image', "VARCHAR(255) NULL DEFAULT 'img/fahrzeug/default.png' AFTER geojson"],
        ['leitstellen', 'police_signal_lights_json', 'LONGTEXT NULL AFTER police_vehicle_image'],
        ['leitstellen', 'rescue_vehicle_image', "VARCHAR(255) NULL DEFAULT 'img/fahrzeug/default.png' AFTER police_signal_lights_json"],
        ['leitstellen', 'rescue_signal_lights_json', 'LONGTEXT NULL AFTER rescue_vehicle_image'],
        ['leitstellen', 'created_by_user_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER created_at'],
        ['fahrzeuge', 'signal_lights_json', 'LONGTEXT NULL AFTER bild_datei'],
        ['krankenhaeuser', 'last_editor', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER last_update'],
        ['einsaetze', 'patient_profile_json', 'LONGTEXT NULL AFTER patient_anforderung'],
        ['user_permissions', 'can_manage_spielinstanzen', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit_fahrzeuge'],
        ['instanz_user', 'connected', 'TINYINT(1) NULL DEFAULT 1 AFTER rolle'],
        ['spielinstanzen', 'settings_json', 'LONGTEXT NULL AFTER ist_aktiv'],
        ['spielinstanzen', 'started_at', 'DATETIME NULL DEFAULT NULL AFTER settings_json'],
        ['spielinstanzen', 'sim_state', "ENUM('created','running','paused','ended') NOT NULL DEFAULT 'created' AFTER started_at"],
        ['spielinstanzen', 'owner_user_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER sim_state'],
        ['spielinstanzen', 'last_activity_at', 'DATETIME NULL DEFAULT NULL AFTER owner_user_id'],
        ['spielinstanzen', 'retention_notice_sent_at', 'DATETIME NULL DEFAULT NULL AFTER last_activity_at'],
        ['spielinstanzen', 'retention_delete_at', 'DATETIME NULL DEFAULT NULL AFTER retention_notice_sent_at'],
    ];
    foreach ($columns as [$table, $column, $definition]) {
        lsttraining_migration_add_column($pdo, $table, $column, $definition);
    }

    if (lsttraining_migration_table_exists($pdo, 'spielinstanzen') && !lsttraining_migration_index_exists($pdo, 'spielinstanzen', 'idx_spielinstanzen_retention')) {
        $pdo->exec('ALTER TABLE spielinstanzen ADD INDEX idx_spielinstanzen_retention (ist_aktiv, sim_state, last_activity_at, retention_delete_at)');
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
        lsttraining_migration_table_exists($pdo, 'user_permissions')
        && lsttraining_migration_column_exists($pdo, 'user_permissions', 'leitstellen_ids')
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

    // Datenmigration: vorhandene Einzelspieler-/Einsatzleiter-Instanzen erhalten ihren Leiter als Eigentümer.
    if (lsttraining_migration_table_exists($pdo, 'spielinstanzen') && lsttraining_migration_table_exists($pdo, 'instanz_user')) {
        $instances = $pdo->query('SELECT id, settings_json FROM spielinstanzen WHERE owner_user_id IS NULL');
        $owner_stmt = $pdo->prepare("SELECT user_id FROM instanz_user WHERE instanz_id = ? AND (? = 'singleplayer' OR rolle = 'leiter') ORDER BY id ASC LIMIT 1");
        $update_stmt = $pdo->prepare('UPDATE spielinstanzen SET owner_user_id = ?, last_activity_at = COALESCE(last_activity_at, NOW()) WHERE id = ? AND owner_user_id IS NULL');
        foreach (($instances ? $instances->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
            $mode = is_array($settings) ? (string) ($settings['mode'] ?? '') : '';
            if (!in_array($mode, ['singleplayer', 'einsatzleiter'], true)) {
                continue;
            }
            $owner_stmt->execute([(int) $row['id'], $mode]);
            $owner_id = (int) $owner_stmt->fetchColumn();
            if ($owner_id > 0) {
                $update_stmt->execute([$owner_id, (int) $row['id']]);
            }
        }
    }
}

/**
 * MySQL-DDL führt implizite Commits aus. Deshalb sind alle Schritte idempotent;
 * die Versionsnummer wird erst gespeichert, wenn der komplette Lauf erfolgreich war.
 */
function lsttraining_run_migrations(bool $force = false): int {
    $pdo = lsttraining_get_connection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Datenbankverbindung fehlgeschlagen.');
    }

    $current = lsttraining_schema_installed_version();
    if (!$force && $current >= LSTTRAINING_SCHEMA_VERSION) {
        return $current;
    }

    lsttraining_migration_prepare_name_pool($pdo);
    lsttraining_migration_execute_baseline($pdo);
    if ($force || $current < 2026082601) {
        lsttraining_migration_2026082601($pdo);
    }
    lsttraining_schema_store_version(LSTTRAINING_SCHEMA_VERSION);
    return LSTTRAINING_SCHEMA_VERSION;
}

function lsttraining_maybe_run_migrations(): void {
    if (!current_user_can('manage_options') || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }
    if (lsttraining_schema_installed_version() >= LSTTRAINING_SCHEMA_VERSION) {
        return;
    }
    try {
        lsttraining_run_migrations();
    } catch (Throwable $e) {
        error_log('[LSTtraining][migration] ' . $e->getMessage());
        add_action('admin_notices', static function () use ($e): void {
            echo '<div class="notice notice-error"><p>LSTtraining-Datenbankupgrade fehlgeschlagen: ' . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

add_action('admin_init', 'lsttraining_maybe_run_migrations');
