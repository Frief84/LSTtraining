<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/regions.php';
require_once __DIR__ . '/einsatz-flow.php';

const LSTTRAINING_SCHEMA_VERSION = 2026110302;
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

function lsttraining_migration_baseline_tables(): array {
    static $tables = null;
    if (is_array($tables)) {
        return $tables;
    }

    $schema_file = LSTTRAINING_PATH . 'database/schema.sql';
    if (!is_readable($schema_file)) {
        throw new RuntimeException('database/schema.sql wurde nicht gefunden.');
    }
    $sql = file_get_contents($schema_file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('database/schema.sql ist leer oder nicht lesbar.');
    }

    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $matches);
    $tables = array_values(array_unique($matches[1] ?? []));
    if (!$tables) {
        throw new RuntimeException('database/schema.sql enthält keine Tabellen-Definitionen.');
    }

    return $tables;
}

function lsttraining_migration_baseline_complete(PDO $pdo): bool {
    foreach (lsttraining_migration_baseline_tables() as $table) {
        if (!lsttraining_migration_table_exists($pdo, $table)) {
            return false;
        }
    }
    return true;
}

function lsttraining_migration_execute_missing_baseline_tables(PDO $pdo, string $sql): void {
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`[\s\S]*?;\s*/i', $sql, $matches, PREG_SET_ORDER);
    if (!$matches) {
        throw new RuntimeException('database/schema.sql enthält keine ausführbaren Tabellen-Definitionen.');
    }

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec('SET foreign_key_checks = 0');
    $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    foreach ($matches as $match) {
        $table = (string) $match[1];
        if (lsttraining_migration_table_exists($pdo, $table)) {
            continue;
        }
        $statement = preg_replace('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', (string) $match[0]);
        if (!is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('Tabellen-Definition konnte nicht vorbereitet werden: ' . $table);
        }
        $pdo->exec($statement);
    }

    if (lsttraining_migration_table_exists($pdo, 'anrufer_name_pool') && preg_match('/INSERT\s+IGNORE\s+INTO\s+anrufer_name_pool[\s\S]*?;\s*/i', $sql, $insert_match)) {
        $pdo->exec((string) $insert_match[0]);
    }
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
    if (lsttraining_migration_baseline_complete($pdo)) {
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
        lsttraining_migration_execute_missing_baseline_tables($pdo, $sql);
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
 * Ältere Installationen besaßen die Krankenhaustabelle bereits vor Einführung
 * der externen, idempotenten POI-Kennung. CREATE TABLE IF NOT EXISTS ergänzt
 * vorhandene Tabellen nicht, daher muss die Spalte ausdrücklich migriert werden.
 */
function lsttraining_migration_2026090901(PDO $pdo): void {
    if (!lsttraining_migration_table_exists($pdo, 'krankenhaeuser')) {
        return;
    }

    lsttraining_migration_add_column(
        $pdo,
        'krankenhaeuser',
        'poi_id',
        "VARCHAR(50) COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'Externe POI-ID' AFTER id"
    );

    // Bestehende Datensätze erhalten eine stabile, konfliktfreie Legacy-ID.
    $pdo->exec("UPDATE krankenhaeuser SET poi_id = CONCAT('legacy-', id) WHERE poi_id IS NULL OR TRIM(poi_id) = ''");

    if (!lsttraining_migration_index_exists($pdo, 'krankenhaeuser', 'poi_id')) {
        $pdo->exec('ALTER TABLE krankenhaeuser ADD UNIQUE INDEX poi_id (poi_id)');
    }

    $pdo->exec("ALTER TABLE krankenhaeuser MODIFY COLUMN poi_id VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Externe POI-ID'");
}

function lsttraining_migration_2026090902(PDO $pdo): void {
    lsttraining_migration_add_column(
        $pdo,
        'krankenhaeuser',
        'land',
        "VARCHAR(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Deutschland' AFTER name"
    );

    // Die stabilen Importkennungen erlauben eine sichere Bestandszuordnung.
    if (lsttraining_migration_column_exists($pdo, 'krankenhaeuser', 'poi_id')) {
        $pdo->exec("UPDATE krankenhaeuser SET land = 'Schweiz' WHERE poi_id LIKE 'ch-bag-%'");
    }
}

/**
 * Der historische Österreich-Import belegt den stabilen Legacy-ID-Bereich
 * 918–1022. Zwei später in freie IDs eingefügte deutsche Kliniken bleiben
 * ausdrücklich ausgenommen.
 */
function lsttraining_migration_2026090903(PDO $pdo): void {
    if (
        !lsttraining_migration_table_exists($pdo, 'krankenhaeuser')
        || !lsttraining_migration_column_exists($pdo, 'krankenhaeuser', 'poi_id')
        || !lsttraining_migration_column_exists($pdo, 'krankenhaeuser', 'land')
    ) {
        return;
    }

    $pdo->exec("
        UPDATE krankenhaeuser
           SET land = 'Österreich'
         WHERE poi_id REGEXP '^legacy-(9(1[8-9]|[2-9][0-9])|10([01][0-9]|2[0-2]))$'
           AND poi_id NOT IN ('legacy-950', 'legacy-970')
    ");
}

function lsttraining_migration_2026090904(PDO $pdo): void {
    foreach ([
        ['wachen', 'source_key', "VARCHAR(100) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER source_note"],
        ['wachen', 'source_url', "VARCHAR(500) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER source_key"],
        ['wachen', 'source_checked_at', 'DATETIME NULL DEFAULT NULL AFTER source_url'],
        ['fahrzeuge', 'availability_rule_json', 'LONGTEXT COLLATE utf8mb4_general_ci NULL AFTER dienstzeiten'],
        ['fahrzeuge', 'source_key', "VARCHAR(100) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER source_note"],
        ['fahrzeuge', 'source_url', "VARCHAR(500) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER source_key"],
        ['fahrzeuge', 'source_checked_at', 'DATETIME NULL DEFAULT NULL AFTER source_url'],
    ] as [$table, $column, $definition]) {
        lsttraining_migration_add_column($pdo, $table, $column, $definition);
    }
    if (lsttraining_migration_table_exists($pdo, 'wachen') && !lsttraining_migration_index_exists($pdo, 'wachen', 'uk_wachen_source_key')) {
        $pdo->exec('ALTER TABLE wachen ADD UNIQUE INDEX uk_wachen_source_key (source_key)');
    }
    if (lsttraining_migration_table_exists($pdo, 'fahrzeuge') && !lsttraining_migration_index_exists($pdo, 'fahrzeuge', 'uk_fahrzeuge_source_key')) {
        $pdo->exec('ALTER TABLE fahrzeuge ADD UNIQUE INDEX uk_fahrzeuge_source_key (source_key)');
    }
}

function lsttraining_migration_2026091101(PDO $pdo): void {
    foreach ([
        ['nebenleitstellen', 'land', 'VARCHAR(100) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER name'],
        ['nebenleitstellen', 'bundesland', 'VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER land'],
    ] as [$table, $column, $definition]) {
        lsttraining_migration_add_column($pdo, $table, $column, $definition);
    }

    if (!lsttraining_migration_table_exists($pdo, 'nebenleitstellen')) {
        return;
    }
    if (!lsttraining_migration_index_exists($pdo, 'nebenleitstellen', 'idx_nebenleitstellen_land')) {
        $pdo->exec('ALTER TABLE nebenleitstellen ADD INDEX idx_nebenleitstellen_land (land)');
    }
    if (!lsttraining_migration_index_exists($pdo, 'nebenleitstellen', 'idx_nebenleitstellen_bundesland')) {
        $pdo->exec('ALTER TABLE nebenleitstellen ADD INDEX idx_nebenleitstellen_bundesland (bundesland(191))');
    }

    $rows = $pdo->query("SELECT id, name, zustandigkeit, land, bundesland FROM nebenleitstellen WHERE land IS NULL OR TRIM(land) = '' OR bundesland IS NULL OR TRIM(bundesland) = ''");
    $update = $pdo->prepare('UPDATE nebenleitstellen SET land = ?, bundesland = ? WHERE id = ?');
    foreach (($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $current_land = trim((string) ($row['land'] ?? ''));
        $current_bundesland = trim((string) ($row['bundesland'] ?? ''));
        if (function_exists('lsttraining_region_backfill_values')) {
            $backfill = lsttraining_region_backfill_values(
                $current_land,
                $current_bundesland,
                (string) ($row['name'] ?? ''),
                (string) ($row['zustandigkeit'] ?? '')
            );
        } else {
            // Deployments können während eines Datei-Updates kurz gemischte
            // PHP-/Opcode-Versionen ausführen. Die Migration darf deshalb nicht
            // hart von dem neuen Helper abhängen.
            $detected = function_exists('lsttraining_region_detect')
                ? lsttraining_region_detect((string) ($row['name'] ?? ''), (string) ($row['zustandigkeit'] ?? ''))
                : ['land' => '', 'bundesland' => ''];
            $land = $current_land;
            $bundesland = $current_bundesland;
            if ($land === '' && $bundesland === '') {
                $land = (string) ($detected['land'] ?? '');
                $bundesland = (string) ($detected['bundesland'] ?? '');
            } elseif ($land === '' && $bundesland !== '' && function_exists('lsttraining_region_country_for_bundesland')) {
                $land = lsttraining_region_country_for_bundesland($bundesland);
            } elseif ($bundesland === '' && $land === (string) ($detected['land'] ?? '')) {
                $bundesland = (string) ($detected['bundesland'] ?? '');
            }
            $backfill = ['land' => $land, 'bundesland' => $bundesland];
        }
        $land = $backfill['land'];
        $bundesland = $backfill['bundesland'];

        if ($land !== $current_land || $bundesland !== $current_bundesland) {
            $update->execute([$land !== '' ? $land : null, $bundesland !== '' ? $bundesland : null, (int) $row['id']]);
        }
    }
}

function lsttraining_migration_2026091102(PDO $pdo): void {
    if (
        !lsttraining_migration_table_exists($pdo, 'nebenleitstellen')
        || !lsttraining_migration_column_exists($pdo, 'nebenleitstellen', 'land')
        || !lsttraining_migration_column_exists($pdo, 'nebenleitstellen', 'bundesland')
    ) {
        return;
    }

    $german_bundeslaender = (array) (lsttraining_region_map()['Deutschland'] ?? []);
    $rows = $pdo->query('SELECT id, name, zustandigkeit, land, bundesland FROM nebenleitstellen');
    $update = $pdo->prepare('UPDATE nebenleitstellen SET land = ?, bundesland = ? WHERE id = ?');
    foreach (($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $current_land = trim((string) ($row['land'] ?? ''));
        $current_bundesland = trim((string) ($row['bundesland'] ?? ''));
        $detected_bundesland = lsttraining_region_detect_bundesland_for_land(
            'Deutschland',
            (string) ($row['name'] ?? ''),
            (string) ($row['zustandigkeit'] ?? '')
        );
        $bundesland = $detected_bundesland !== ''
            ? $detected_bundesland
            : (in_array($current_bundesland, $german_bundeslaender, true) ? $current_bundesland : '');

        if ($current_land !== 'Deutschland' || $current_bundesland !== $bundesland) {
            $update->execute(['Deutschland', $bundesland !== '' ? $bundesland : null, (int) $row['id']]);
        }
    }
}

function lsttraining_migration_2026103001(PDO $pdo): void {
    foreach ([
        'flow_key' => 'VARCHAR(64) NULL',
        'flow_role' => 'VARCHAR(20) NULL',
        'applicable_initial_keys' => 'TEXT NULL',
    ] as $column => $definition) {
        lsttraining_migration_add_column($pdo, 'einsatz_followups', $column, $definition);
    }
    if (!lsttraining_migration_table_exists($pdo, 'einsatz_followups')) { return; }
    $pdo->exec("ALTER TABLE einsatz_followups MODIFY COLUMN trigger_mode ENUM('random','on_unit_arrival','on_missing_resources','on_dispatcher_question','on_transport_started','on_hospital_arrival','on_vehicle_available','manual') NOT NULL DEFAULT 'random'");
    $rows = $pdo->query('SELECT id, einsatz_id, kind, trigger_mode, probability_percent, flow_key, flow_role FROM einsatz_followups ORDER BY einsatz_id, step_no, id')->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    foreach ($rows as $row) { $groups[(int) $row['einsatz_id']][] = $row; }
    $update = $pdo->prepare('UPDATE einsatz_followups SET flow_key = ?, flow_role = ?, probability_percent = ?, applicable_initial_keys = COALESCE(applicable_initial_keys, ?) WHERE id = ?');
    foreach ($groups as $group) {
        $initial = array_values(array_filter($group, static fn($row) => !$row['flow_role'] && $row['kind'] === 'unit_report' && $row['trigger_mode'] === 'on_unit_arrival'));
        $percentages = lsttraining_einsatz_flow_percentages(array_column($initial, 'probability_percent'));
        $initialIndex = 0;
        foreach ($group as $row) {
            if ($row['flow_role'] && $row['flow_key']) { continue; }
            $role = $row['flow_role'] ?: (($row['kind'] === 'unit_report' && $row['trigger_mode'] === 'on_unit_arrival') ? 'initial' : 'development');
            $probability = $role === 'initial' ? $percentages[$initialIndex++] : (int) $row['probability_percent'];
            $update->execute([$row['flow_key'] ?: 'flow_' . bin2hex(random_bytes(8)), $role, $probability, '[]', (int) $row['id']]);
        }
    }
}

function lsttraining_migration_2026110201(PDO $pdo): void {
    if (!lsttraining_migration_table_exists($pdo, 'leitstellen_osm_layers')) {
        return;
    }

    $oldPrefix = 'data/osm_tiles/';
    $newPrefix = 'landuse/landuse_tiles_out/osm/';
    $oldRoot = LSTTRAINING_PATH . 'data/osm_tiles';
    $newRoot = LSTTRAINING_PATH . 'landuse/landuse_tiles_out/osm';
    if (is_dir($oldRoot) && !is_dir($newRoot) && !rename($oldRoot, $newRoot)) {
        throw new RuntimeException('OSM-Tiles konnten nicht in den gemeinsamen Landuse-Ordner verschoben werden.');
    }
    $select = $pdo->prepare('SELECT id, file_relpath FROM leitstellen_osm_layers WHERE LEFT(file_relpath, ?) = ?');
    $select->execute([strlen($oldPrefix), $oldPrefix]);
    $update = $pdo->prepare('UPDATE leitstellen_osm_layers SET file_relpath = ? WHERE id = ?');
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $newPath = $newPrefix . substr((string) $row['file_relpath'], strlen($oldPrefix));
        if (is_file(LSTTRAINING_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newPath))) {
            $update->execute([$newPath, (int) $row['id']]);
        }
    }
}

function lsttraining_migration_2026110202(PDO $pdo): void {
    if (!lsttraining_migration_table_exists($pdo, 'einsatz_followups')) { return; }
    $templates = $pdo->query('SELECT id, lagemeldung FROM einsaetze ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $select = $pdo->prepare('SELECT * FROM einsatz_followups WHERE einsatz_id = ? ORDER BY step_no, id');
    $update = $pdo->prepare('UPDATE einsatz_followups SET flow_role = ?, trigger_mode = ?, probability_percent = ?, applicable_initial_keys = ?, effect_json = ? WHERE id = ?');
    $insert = $pdo->prepare("INSERT INTO einsatz_followups (einsatz_id, step_no, label, kind, text, min_after_sec, max_after_sec, probability_percent, speaker_type, trigger_mode, required_resources_json, effect_json, flow_key, flow_role, applicable_initial_keys) VALUES (?, 0, 'Eintreffen und Erkundung', 'unit_report', ?, 0, 0, 0, 'fire_unit', 'on_unit_arrival', '[]', ?, ?, 'initial', '[]')");
    foreach ($templates as $template) {
        $select->execute([(int) $template['id']]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $default = null;
        foreach ($rows as $row) {
            $effect = json_decode((string) ($row['effect_json'] ?? ''), true);
            if ((is_array($effect) && ($effect['flow_stage'] ?? '') === 'arrival_default')
                || (($row['flow_role'] ?? '') === 'initial' && preg_match('/eintreffen.*erkundung/i', (string) $row['label']))) {
                $default = $row; break;
            }
        }
        if ($default) {
            $effect = json_decode((string) ($default['effect_json'] ?? ''), true);
            if (!is_array($effect)) { $effect = []; }
            $effect['flow_stage'] = 'arrival_default';
            $effect['flow_parent_keys'] = [];
            $effect['radio_report_text'] = (string) ($effect['radio_report_text'] ?? $default['text'] ?? $template['lagemeldung']);
            $update->execute(['initial', 'on_unit_arrival', 0, '[]', json_encode($effect, JSON_UNESCAPED_UNICODE), (int) $default['id']]);
            $defaultKey = (string) $default['flow_key'];
        } else {
            $defaultKey = 'flow_' . bin2hex(random_bytes(8));
            $report = (string) ($template['lagemeldung'] ?? '');
            $effect = ['flow_stage' => 'arrival_default', 'flow_parent_keys' => [], 'radio_report_text' => $report];
            $insert->execute([(int) $template['id'], $report, json_encode($effect, JSON_UNESCAPED_UNICODE), $defaultKey]);
        }
        $variants = []; $existing = 0;
        foreach ($rows as $row) {
            if ($default && (int) $row['id'] === (int) $default['id']) { continue; }
            $effect = json_decode((string) ($row['effect_json'] ?? ''), true);
            if (!is_array($effect)) { $effect = []; }
            if (($effect['flow_stage'] ?? '') === 'arrival_variant' || ($row['flow_role'] ?? '') === 'initial') {
                $existing += max(0, (int) $row['probability_percent']); continue;
            }
            $parents = is_array($effect['flow_parent_keys'] ?? null) ? $effect['flow_parent_keys'] : [];
            if (($row['flow_role'] ?? '') === 'development'
                && preg_match('/^Lage [A-D](?:$|[\s:-])/iu', (string) $row['label'])
                && (!$parents || in_array($defaultKey, $parents, true))) {
                $variants[] = [$row, $effect];
            }
        }
        $each = count($variants) ? (int) floor(max(0, 100 - $existing) / (count($variants) + 1)) : 0;
        foreach ($variants as [$row, $effect]) {
            $effect['flow_stage'] = 'arrival_variant';
            $effect['flow_parent_keys'] = array_values(array_filter(
                is_array($effect['flow_parent_keys'] ?? null) ? $effect['flow_parent_keys'] : [],
                static fn($key) => $key !== $defaultKey
            ));
            $update->execute(['initial', 'on_unit_arrival', $each, '[]', json_encode($effect, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
        }
    }
}

/**
 * MySQL-DDL führt implizite Commits aus. Deshalb sind alle Schritte idempotent;
 * die Versionsnummer wird erst gespeichert, wenn der komplette Lauf erfolgreich war.
 */
function lsttraining_stichwoerter_create_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS einsatzstichwoerter (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        leitstelle_id INT NOT NULL,
        code VARCHAR(100) NOT NULL,
        bezeichnung VARCHAR(255) NOT NULL,
        einsatzart ENUM(\'RD\',\'FW\') NOT NULL,
        fahrzeuge_json TEXT NULL,
        beschreibung TEXT NULL,
        hinweis TEXT NULL,
        aktiv TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ls_code (leitstelle_id, code),
        CONSTRAINT fk_stichwort_leitstelle FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

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
    if ($force || $current < 2026090901) {
        lsttraining_migration_2026090901($pdo);
    }
    if ($force || $current < 2026090902) {
        lsttraining_migration_2026090902($pdo);
    }
    if ($force || $current < 2026090903) {
        lsttraining_migration_2026090903($pdo);
    }
    if ($force || $current < 2026090904) {
        lsttraining_migration_2026090904($pdo);
    }
    if ($force || $current < 2026091101) {
        lsttraining_migration_2026091101($pdo);
    }
    if ($force || $current < 2026091102) {
        lsttraining_migration_2026091102($pdo);
    }
    if ($force || $current < 2026100701) {
        lsttraining_migration_add_column($pdo, 'wachen', 'adresse', 'VARCHAR(500) NULL DEFAULT NULL AFTER name');
    }
    if ($force || $current < 2026102901) {
        lsttraining_migration_add_column($pdo, 'einsaetze', 'patient_profile_json', 'LONGTEXT NULL AFTER patient_anforderung');
        lsttraining_migration_add_column($pdo, 'einsaetze', 'base_required_resources_json', 'LONGTEXT NULL AFTER patient_profile_json');
    }
    if ($force || $current < 2026103001) {
        lsttraining_migration_2026103001($pdo);
    }
    if ($force || $current < 2026110201) {
        lsttraining_migration_2026110201($pdo);
    }
    if ($force || $current < 2026110202) {
        lsttraining_migration_2026110202($pdo);
    }
    if ($force || $current < 2026110203) {
        lsttraining_migration_add_column($pdo, 'einsaetze', 'poi_types_json', 'TEXT NULL AFTER poi_type');
        $pdo->exec("UPDATE einsaetze SET poi_types_json = JSON_ARRAY(poi_type) WHERE poi_type IS NOT NULL AND poi_type <> '' AND (poi_types_json IS NULL OR poi_types_json = '')");
    }
    if ($force || $current < 2026110301) {
        lsttraining_stichwoerter_create_table($pdo);
        lsttraining_migration_add_column($pdo, 'einsaetze', 'leitstelle_id', 'INT NULL DEFAULT NULL AFTER id');
        lsttraining_migration_add_column($pdo, 'einsaetze', 'stichwort_id', 'INT NULL DEFAULT NULL AFTER leitstelle_id');
        lsttraining_stichwoerter_seed_all($pdo);
    }
    if ($force || $current < 2026110302) {
        lsttraining_migration_add_column($pdo, 'einsatzstichwoerter', 'beschreibung', 'TEXT NULL AFTER bezeichnung');
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
