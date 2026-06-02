<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/activity.php';

const LSTTRAINING_INSTANCE_RETENTION_HOOK = 'lsttraining_instance_retention_daily';

function lsttraining_instance_lifecycle_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_instance_lifecycle_column_type(PDO $pdo, string $table, string $column): string {
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
}

function lsttraining_instance_lifecycle_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_instance_lifecycle_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_instance_lifecycle_is_shared_mode(string $mode): bool {
    return in_array($mode, ['multiplayer', 'einsatzleiter'], true);
}

function lsttraining_instance_lifecycle_ensure_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $runtime_columns = [
        'settings_json' => 'ALTER TABLE spielinstanzen ADD COLUMN settings_json LONGTEXT NULL AFTER ist_aktiv',
        'started_at'    => 'ALTER TABLE spielinstanzen ADD COLUMN started_at DATETIME NULL DEFAULT NULL AFTER settings_json',
        'sim_state'     => "ALTER TABLE spielinstanzen ADD COLUMN sim_state ENUM('created','running','paused','ended') NOT NULL DEFAULT 'created' AFTER started_at",
    ];

    foreach ($runtime_columns as $column => $alter_sql) {
        if (!lsttraining_instance_lifecycle_column_exists($pdo, 'spielinstanzen', $column)) {
            $pdo->exec($alter_sql);
        }
    }

    if (lsttraining_instance_lifecycle_column_type($pdo, 'spielinstanzen', 'settings_json') !== 'longtext') {
        $pdo->exec('ALTER TABLE spielinstanzen MODIFY COLUMN settings_json LONGTEXT NULL');
    }

    $columns = [
        'owner_user_id'            => 'ALTER TABLE spielinstanzen ADD COLUMN owner_user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER sim_state',
        'last_activity_at'         => 'ALTER TABLE spielinstanzen ADD COLUMN last_activity_at DATETIME NULL DEFAULT NULL AFTER owner_user_id',
        'retention_notice_sent_at' => 'ALTER TABLE spielinstanzen ADD COLUMN retention_notice_sent_at DATETIME NULL DEFAULT NULL AFTER last_activity_at',
        'retention_delete_at'      => 'ALTER TABLE spielinstanzen ADD COLUMN retention_delete_at DATETIME NULL DEFAULT NULL AFTER retention_notice_sent_at',
    ];

    foreach ($columns as $column => $alter_sql) {
        if (!lsttraining_instance_lifecycle_column_exists($pdo, 'spielinstanzen', $column)) {
            $pdo->exec($alter_sql);
        }
    }

    if (!lsttraining_instance_lifecycle_index_exists($pdo, 'spielinstanzen', 'idx_spielinstanzen_retention')) {
        $pdo->exec('
            ALTER TABLE spielinstanzen
            ADD INDEX idx_spielinstanzen_retention
                (ist_aktiv, sim_state, last_activity_at, retention_delete_at)
        ');
    }

    // Existing owned simulations receive a fresh retention period when this feature is introduced.
    $stmt = $pdo->query('
        SELECT si.id, si.settings_json
        FROM spielinstanzen si
        WHERE si.owner_user_id IS NULL
        ORDER BY si.id ASC
    ');
    $owner_stmt = $pdo->prepare('
        SELECT user_id
        FROM instanz_user
        WHERE instanz_id = ?
          AND (? = ? OR rolle = ?)
        ORDER BY id ASC
        LIMIT 1
    ');
    $update_stmt = $pdo->prepare('
        UPDATE spielinstanzen
        SET owner_user_id = ?,
            last_activity_at = COALESCE(last_activity_at, NOW()),
            retention_notice_sent_at = NULL,
            retention_delete_at = NULL
        WHERE id = ? AND owner_user_id IS NULL
    ');

    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
        $mode = is_array($settings) ? (string) ($settings['mode'] ?? '') : '';
        if (!in_array($mode, ['singleplayer', 'einsatzleiter'], true)) {
            continue;
        }

        $owner_stmt->execute([(int) $row['id'], $mode, 'singleplayer', 'leiter']);
        $owner_user_id = (int) $owner_stmt->fetchColumn();
        if ($owner_user_id > 0) {
            $update_stmt->execute([$owner_user_id, (int) $row['id']]);
        }
    }

    $ready = true;
}

function lsttraining_instance_lifecycle_touch(PDO $pdo, int $instanz_id): void {
    if ($instanz_id <= 0) {
        return;
    }

    lsttraining_instance_lifecycle_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        UPDATE spielinstanzen
        SET last_activity_at = NOW(),
            retention_notice_sent_at = NULL,
            retention_delete_at = NULL
        WHERE id = ? AND COALESCE(ist_aktiv, 1) = 1
    ');
    $stmt->execute([$instanz_id]);
}

function lsttraining_instance_lifecycle_log(int $instanz_id, int $user_id, string $action, array $meta = []): void {
    if (!function_exists('lsttraining_log_activity')) {
        return;
    }

    lsttraining_log_activity([
        'entity_type' => 'spielinstanz',
        'entity_id' => $instanz_id,
        'user_id' => $user_id ?: null,
        'action' => $action,
        'meta' => $meta,
    ]);
}

function lsttraining_instance_lifecycle_delete(PDO $pdo, int $instanz_id, int $user_id, string $action): bool {
    $stmt = $pdo->prepare('
        SELECT id, name, owner_user_id, settings_json
        FROM spielinstanzen
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$instanz_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
    $mode = is_array($settings) ? (string) ($settings['mode'] ?? '') : '';
    $started_transaction = !$pdo->inTransaction();

    try {
        if ($started_transaction) {
            $pdo->beginTransaction();
        }

        // Legacy installations may still have restrictive relations instead of cascade constraints.
        if (
            lsttraining_instance_lifecycle_table_exists($pdo, 'instanz_einsatz_events')
            && lsttraining_instance_lifecycle_table_exists($pdo, 'instanz_einsaetze')
        ) {
            $events = $pdo->prepare('
                DELETE ev
                FROM instanz_einsatz_events ev
                INNER JOIN instanz_einsaetze ie ON ie.id = ev.instanz_einsatz_id
                WHERE ie.instanz_id = ?
            ');
            $events->execute([$instanz_id]);
        }

        foreach (['instanz_fahrzeug_status', 'instanz_einsaetze', 'fahrzeug_status', 'instanz_wachen', 'instanz_user'] as $table) {
            if (
                !lsttraining_instance_lifecycle_table_exists($pdo, $table)
                || !lsttraining_instance_lifecycle_column_exists($pdo, $table, 'instanz_id')
            ) {
                continue;
            }

            $delete_child = $pdo->prepare("DELETE FROM `{$table}` WHERE instanz_id = ?");
            $delete_child->execute([$instanz_id]);
        }

        $delete = $pdo->prepare('DELETE FROM spielinstanzen WHERE id = ?');
        $delete->execute([$instanz_id]);

        if ($delete->rowCount() <= 0) {
            if ($started_transaction) {
                $pdo->rollBack();
            }
            return false;
        }

        if ($started_transaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($started_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    lsttraining_instance_lifecycle_log($instanz_id, $user_id, $action, [
        'name' => (string) ($row['name'] ?? ''),
        'mode' => $mode,
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
    ]);
    return true;
}

function lsttraining_instance_lifecycle_continue_url(int $instanz_id): string {
    if (function_exists('lsttraining_frontend_simulation_url')) {
        return lsttraining_frontend_simulation_url($instanz_id, home_url('/'));
    }

    return add_query_arg([
        'lst_sim_view' => '1',
        'lst_instance' => $instanz_id,
    ], home_url('/'));
}

function lsttraining_instance_lifecycle_run_retention(): void {
    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        return;
    }

    try {
        lsttraining_instance_lifecycle_ensure_schema($pdo);
        $now = current_time('mysql');
        $cutoff = (new DateTimeImmutable('now', wp_timezone()))->modify('-1 month')->format('Y-m-d H:i:s');

        $due_delete = $pdo->prepare("
            SELECT id, owner_user_id
            FROM spielinstanzen
            WHERE COALESCE(ist_aktiv, 1) = 1
              AND sim_state IN ('created', 'running', 'paused')
              AND owner_user_id IS NOT NULL
              AND retention_delete_at IS NOT NULL
              AND retention_delete_at <= ?
            LIMIT 100
        ");
        $due_delete->execute([$now]);
        foreach (($due_delete->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            lsttraining_instance_lifecycle_delete(
                $pdo,
                (int) $row['id'],
                0,
                'retention_delete'
            );
        }

        $due_notice = $pdo->prepare("
            SELECT si.id, si.name, si.owner_user_id, si.last_activity_at, l.name AS leitstelle_name
            FROM spielinstanzen si
            INNER JOIN leitstellen l ON l.id = si.leitstelle_id
            WHERE COALESCE(si.ist_aktiv, 1) = 1
              AND si.sim_state IN ('created', 'running', 'paused')
              AND si.owner_user_id IS NOT NULL
              AND si.last_activity_at IS NOT NULL
              AND si.last_activity_at <= ?
              AND si.retention_notice_sent_at IS NULL
              AND si.retention_delete_at IS NULL
            ORDER BY si.last_activity_at ASC, si.id ASC
            LIMIT 100
        ");
        $due_notice->execute([$cutoff]);
        foreach (($due_notice->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $owner_id = (int) ($row['owner_user_id'] ?? 0);
            $owner = get_userdata($owner_id);
            if (!$owner || !is_email($owner->user_email)) {
                error_log('[LSTtraining][instance_retention] E-Mail-Adresse fehlt fuer Instanz ' . (int) $row['id']);
                continue;
            }

            $delete_at = (new DateTimeImmutable('now', wp_timezone()))->modify('+14 days')->format('Y-m-d H:i:s');
            $subject = 'LSTtraining: Gespeicherte Simulation wird bald geloescht';
            $message = "Hallo " . ($owner->display_name ?: $owner->user_login) . ",\n\n";
            $message .= "die gespeicherte Simulation \"" . (string) $row['name'] . "\"";
            $message .= " (" . (string) $row['leitstelle_name'] . ") wurde seit " . (string) $row['last_activity_at'] . " nicht weitergespielt.\n\n";
            $message .= "Fortsetzen: " . lsttraining_instance_lifecycle_continue_url((int) $row['id']) . "\n";
            $message .= "Alternativ kannst du die Instanz unter \"Meine gespeicherten Spiele\" loeschen.\n\n";
            $message .= "Wenn nichts passiert, wird die Instanz am " . $delete_at . " automatisch geloescht.\n";

            if (!wp_mail($owner->user_email, $subject, $message)) {
                error_log('[LSTtraining][instance_retention] Erinnerungs-Mail fehlgeschlagen fuer Instanz ' . (int) $row['id']);
                continue;
            }

            $mark = $pdo->prepare('
                UPDATE spielinstanzen
                SET retention_notice_sent_at = ?,
                    retention_delete_at = ?
                WHERE id = ?
                  AND last_activity_at = ?
                  AND retention_notice_sent_at IS NULL
            ');
            $mark->execute([$now, $delete_at, (int) $row['id'], (string) $row['last_activity_at']]);
            if ($mark->rowCount() > 0) {
                lsttraining_instance_lifecycle_log((int) $row['id'], 0, 'retention_notice', [
                    'delete_at' => $delete_at,
                    'email' => (string) $owner->user_email,
                    'owner_user_id' => $owner_id,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('[LSTtraining][instance_retention] ' . $e->getMessage());
    }
}

function lsttraining_instance_lifecycle_schedule(): void {
    if (!wp_next_scheduled(LSTTRAINING_INSTANCE_RETENTION_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', LSTTRAINING_INSTANCE_RETENTION_HOOK);
    }
}

function lsttraining_instance_lifecycle_deactivate(): void {
    $timestamp = wp_next_scheduled(LSTTRAINING_INSTANCE_RETENTION_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, LSTTRAINING_INSTANCE_RETENTION_HOOK);
    }
}

add_action('init', 'lsttraining_instance_lifecycle_schedule');
add_action(LSTTRAINING_INSTANCE_RETENTION_HOOK, 'lsttraining_instance_lifecycle_run_retention');
register_activation_hook(LSTTRAINING_PLUGIN_FILE, 'lsttraining_instance_lifecycle_schedule');
register_deactivation_hook(LSTTRAINING_PLUGIN_FILE, 'lsttraining_instance_lifecycle_deactivate');
