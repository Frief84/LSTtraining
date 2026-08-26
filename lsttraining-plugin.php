<?php
/**
 * Plugin Name: LSTtraining
 * Description: Integration des LSTtraining-Frameworks in WordPress inkl. Map, API und Datenbank.
 * Version: 1.0
 * Author: Frief
 */

defined('ABSPATH') or exit;

// LSTtraining: Fatal-Catcher für AJAX, damit "kritischer Fehler" im Log erklärbar wird
register_shutdown_function(function () {
    if (!defined('DOING_AJAX') || !DOING_AJAX) return;

    $e = error_get_last();
    if (!$e) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'], $fatalTypes, true)) return;

    error_log('[LSTtraining][FATAL][AJAX] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
});

if (!defined('LSTTRAINING_PLUGIN_FILE')) {
    define('LSTTRAINING_PLUGIN_FILE', __FILE__);
}
if (!defined('LSTTRAINING_PATH')) {
    define('LSTTRAINING_PATH', plugin_dir_path(LSTTRAINING_PLUGIN_FILE));
}
if (!defined('LSTTRAINING_URL')) {
    define('LSTTRAINING_URL', plugin_dir_url(LSTTRAINING_PLUGIN_FILE));
}

require_once LSTTRAINING_PATH . 'includes/db.php';
require_once LSTTRAINING_PATH . 'includes/migrations.php';
require_once LSTTRAINING_PATH . 'includes/instance-lifecycle.php';
require_once LSTTRAINING_PATH . 'includes/permissions.php';
require_once LSTTRAINING_PATH . 'includes/settings.php';
require_once LSTTRAINING_PATH . 'includes/schema_import.php';
require_once LSTTRAINING_PATH . 'includes/ajax-handlers.php';
require_once LSTTRAINING_PATH . 'includes/ajax/ajax_index.php';
require_once LSTTRAINING_PATH . 'includes/ajax/ajax_simulation.php';
require_once LSTTRAINING_PATH . 'includes/rest-api.php';
require_once LSTTRAINING_PATH . 'includes/simulation-workspace.php';
require_once LSTTRAINING_PATH . 'includes/frontend.php';
require_once LSTTRAINING_PATH . 'includes/admin-menu.php';
require_once LSTTRAINING_PATH . 'includes/admin-ui.php';
require_once LSTTRAINING_PATH . 'includes/map-override.php';

register_activation_hook(LSTTRAINING_PLUGIN_FILE, 'lsttraining_run_migrations');
