<?php
/**
 * Plugin Name: LSTtraining
 * Description: Integration des LSTtraining-Frameworks in WordPress inkl. Map, API und Datenbank.
 * Version: 1.0
 * Author: Frief
 */

defined('ABSPATH') or die('No script kiddies please!');

require_once plugin_dir_path(__FILE__) . 'includes/permissions.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax-handlers.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';        // Optionen
require_once plugin_dir_path(__FILE__) . 'includes/admin-menu.php';     // Menüstruktur
require_once plugin_dir_path(__FILE__) . 'includes/admin-ui.php';       // Rendering-Logik
require_once plugin_dir_path(__FILE__) . 'includes/schema_import.php';  // SQL/Tabellen
require_once plugin_dir_path(__FILE__) . 'includes/map-override.php';   // Map-Hooks
if (!defined('LSTTRAINING_PLUGIN_FILE')) define('LSTTRAINING_PLUGIN_FILE', __FILE__);
if (!defined('LSTTRAINING_PATH'))       define('LSTTRAINING_PATH', plugin_dir_path(LSTTRAINING_PLUGIN_FILE));
if (!defined('LSTTRAINING_URL'))        define('LSTTRAINING_URL',  plugin_dir_url(LSTTRAINING_PLUGIN_FILE));

