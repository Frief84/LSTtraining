<?php
if (!defined('ABSPATH')) { exit; }

/**
 * DSN Builder für WordPress-DB_HOST:
 * - "localhost"
 * - "localhost:3306"
 * - "localhost:/path/to/mysql.sock"
 */
function lsttraining_build_pdo_dsn(string $host, string $dbname): string {
    $charset = 'utf8mb4';
    $host = trim($host);
    $dbname = trim($dbname);

    // Socket-Form: "localhost:/path/to/mysql.sock"
    if (strpos($host, ':/') !== false) {
        $parts  = explode(':', $host, 2);
        $socket = trim($parts[1]);
        return "mysql:unix_socket={$socket};dbname={$dbname};charset={$charset}";
    }

    // Port-Form: "127.0.0.1:3306"
    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        $h = trim($m[1]);
        $p = (int) $m[2];
        return "mysql:host={$h};port={$p};dbname={$dbname};charset={$charset}";
    }

    // Plain Host
    return "mysql:host={$host};dbname={$dbname};charset={$charset}";
}

/**
 * PDO Connection für LSTtraining.
 * Gibt bei Fehlern NULL zurück und loggt die Ursache ins error_log.
 */
function lsttraining_get_connection() {
    $mode = get_option('lsttraining_db_mode', 'wordpress');

    $host   = ($mode === 'extern') ? (string) get_option('lsttraining_ext_host') : (string) DB_HOST;
    $user   = ($mode === 'extern') ? (string) get_option('lsttraining_ext_user') : (string) DB_USER;
    $pass   = ($mode === 'extern') ? (string) get_option('lsttraining_ext_pass') : (string) DB_PASSWORD;
    $dbname = ($mode === 'extern') ? (string) get_option('lsttraining_ext_name') : (string) DB_NAME;

    try {
        $dsn = lsttraining_build_pdo_dsn($host, $dbname);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Wichtig: kein echo bei AJAX, sonst kaputtes JSON
        error_log('[LSTtraining] PDO connect failed: ' . $e->getMessage());
        error_log('[LSTtraining] mode=' . $mode . ' host=' . $host . ' db=' . $dbname);

        // Optional: nur im Admin-HTML-Kontext als Notice anzeigen, nicht bei AJAX
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            echo '<div class="notice notice-error"><p>DB-Verbindung fehlgeschlagen: ' .
                 esc_html($e->getMessage()) .
                 '</p></div>';
        }

        return null;
    }
}

/**
 * Prüft, ob ein eingeloggter Benutzer eine bestimmte Resource bearbeiten darf.
 *
 * @param string $resource 'leitstellen' | 'nebenstellen' | 'hospitals' | 'wachen' | 'fahrzeuge'
 */
function lsttraining_current_user_can_edit(string $resource): bool {
    if (function_exists('lsttraining_user_can')) {
        return lsttraining_user_can($resource);
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $user = wp_get_current_user();
    if (!$user || !$user->ID) {
        return false;
    }

    $map = [
        'leitstellen'  => 'can_edit_leitstellen',
        'nebenstellen' => 'can_edit_nebenstellen',
        'hospitals'    => 'can_edit_hospitals',
        'wachen'       => 'can_edit_wachen',
        'fahrzeuge'    => 'can_edit_fahrzeuge',
    ];
    if (!isset($map[$resource])) {
        return false;
    }
    $column = $map[$resource];

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        return false;
    }

    // Spaltenname kommt aus Whitelist ($map) -> ok
    $sql  = "SELECT {$column} FROM user_permissions WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([ (int) $user->ID ]);
    $flag = $stmt->fetchColumn();

    return (bool) $flag;
}

function lsttraining_current_user_leitstellen_ids(): array {
    if (function_exists('lsttraining_user_allowed_leitstellen')) {
        return lsttraining_user_allowed_leitstellen('leitstellen');
    }

    if (current_user_can('manage_options')) {
        return [];
    }

    $uid = (int) get_current_user_id();
    if (!$uid) {
        return [];
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT leitstellen_ids FROM user_permissions WHERE user_id = ?');
    $stmt->execute([ $uid ]);
    $csv = $stmt->fetchColumn();

    return $csv
        ? array_map('intval', array_filter(array_map('trim', explode(',', (string) $csv))))
        : [];
}
