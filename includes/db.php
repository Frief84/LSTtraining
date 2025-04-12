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
?>
