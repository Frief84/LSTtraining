<?php
if (!defined('ABSPATH')) { exit; }

function lsttraining_execute_schema_sql(): void {
    if (!current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['lsttraining_install_schema'])) {
        return;
    }
    if (!check_admin_referer('lsttraining_install_schema', 'lsttraining_schema_nonce')) {
        return;
    }

    try {
        $version = lsttraining_run_migrations(true);
        echo '<div class="notice notice-success"><p>Datenbankschema erfolgreich auf Version ' . esc_html((string) $version) . ' aktualisiert.</p></div>';
    } catch (Throwable $e) {
        echo '<div class="notice notice-error"><p>Datenbankupgrade fehlgeschlagen: ' . esc_html($e->getMessage()) . '</p></div>';
    }
}

add_action('admin_notices', 'lsttraining_execute_schema_sql');
