<?php
function lsttraining_execute_schema_sql() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['lsttraining_install_schema'])) return;

    $mode = get_option('lsttraining_db_mode', 'wordpress');
    $host = ($mode === 'extern') ? get_option('lsttraining_ext_host') : DB_HOST;
    $user = ($mode === 'extern') ? get_option('lsttraining_ext_user') : DB_USER;
    $pass = ($mode === 'extern') ? get_option('lsttraining_ext_pass') : DB_PASSWORD;
    $dbname = ($mode === 'extern') ? get_option('lsttraining_ext_name') : DB_NAME;

    $schema_file = plugin_dir_path(__FILE__) . '../database/schema.sql';
    if (!file_exists($schema_file)) {
        echo '<div class="notice notice-error"><p>schema.sql wurde nicht gefunden.</p></div>';
        return;
    }

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = file_get_contents($schema_file);
        $pdo->exec($sql);

        echo '<div class="notice notice-success"><p>Die Tabellen wurden erfolgreich erstellt.</p></div>';
    } catch (PDOException $e) {
        echo '<div class="notice notice-error"><p>Fehler beim Ausführen der schema.sql: ' . $e->getMessage() . '</p></div>';
    }
}

add_action('admin_notices', 'lsttraining_execute_schema_sql');
?>
