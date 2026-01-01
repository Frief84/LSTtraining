<?php
function lsttraining_get_connection() {
    $mode = get_option('lsttraining_db_mode', 'wordpress');

    $host = ($mode === 'extern') ? get_option('lsttraining_ext_host') : DB_HOST;
    $user = ($mode === 'extern') ? get_option('lsttraining_ext_user') : DB_USER;
    $pass = ($mode === 'extern') ? get_option('lsttraining_ext_pass') : DB_PASSWORD;
    $dbname = ($mode === 'extern') ? get_option('lsttraining_ext_name') : DB_NAME;

    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        echo '<div class="notice notice-error"><p>DB-Verbindung fehlgeschlagen: ' . $e->getMessage() . '</p></div>';
        return null;
    }
}

/**
 * Prüft, ob ein eingeloggter Benutzer eine bestimmte Resource bearbeiten darf.
 *
 * @param string $resource   'leitstellen' | 'nebenstellen' | 'hospitals' | 'wachen' | 'fahrzeuge'
 * @return bool              true, wenn erlaubt; sonst false
 */
function lsttraining_current_user_can_edit( string $resource ): bool {
    if ( current_user_can( 'manage_options' ) ) {
        // Admins dürfen immer alles
        return true;
    }

    $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) {
        return false;
    }

    // Mapping: Ressource → Spaltenname in user_permissions
    $map = [
        'leitstellen'   => 'can_edit_leitstellen',
        'nebenstellen'  => 'can_edit_nebenstellen',
        'hospitals'     => 'can_edit_hospitals',
        'wachen'        => 'can_edit_wachen',
        'fahrzeuge'     => 'can_edit_fahrzeuge',
    ];
    if ( ! isset( $map[ $resource ] ) ) {
        return false;
    }
    $column = $map[ $resource ];

    // PDO‐Verbindung holen
    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) {
        return false;
    }

    // Prüfen, ob es einen Eintrag in user_permissions gibt und das Flag = 1
    $sql  = "SELECT $column FROM user_permissions WHERE user_id = ?";
    $stmt = $pdo->prepare( $sql );
    $stmt->execute([ $user->ID ]);
    $flag = $stmt->fetchColumn();

    return (bool) $flag;
}

function lsttraining_current_user_leitstellen_ids(): array {
    if ( current_user_can( 'manage_options' ) ) {
        return [];
    }

    $uid = get_current_user_id();
    if ( ! $uid ) { return []; }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) { return []; }

    $stmt = $pdo->prepare('SELECT leitstellen_ids FROM user_permissions WHERE user_id = ?');
    $stmt->execute([ $uid ]);
    $csv = $stmt->fetchColumn();

    return $csv
        ? array_map('intval', array_filter(array_map('trim', explode(',', (string)$csv))))
        : [];
}

?>
