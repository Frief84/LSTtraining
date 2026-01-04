<?php
/**
 * Plugin Name: LSTtraining
 * Description: Integration des LSTtraining-Frameworks in WordPress inkl. Map, API und Datenbank.
 * Version: 1.0
 * Author: Frief
 */

defined('ABSPATH') or exit;


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
require_once LSTTRAINING_PATH . 'includes/permissions.php';
require_once LSTTRAINING_PATH . 'includes/settings.php';
require_once LSTTRAINING_PATH . 'includes/schema_import.php';
require_once LSTTRAINING_PATH . 'includes/ajax-handlers.php';
require_once LSTTRAINING_PATH . 'includes/rest-api.php';
require_once LSTTRAINING_PATH . 'includes/admin-menu.php';
require_once LSTTRAINING_PATH . 'includes/admin-ui.php';
require_once LSTTRAINING_PATH . 'includes/map-override.php';
