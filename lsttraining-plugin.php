<?php
/**
 * Plugin Name: LSTtraining
 * Description: Integration des LSTtraining-Frameworks in WordPress inkl. Map, API und Datenbank.
 * Version: 1.0
 * Author: Frief
 */

defined('ABSPATH') or die('No script kiddies please!');

require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/schema_import.php';
require_once plugin_dir_path(__FILE__) . 'includes/map-override.php';