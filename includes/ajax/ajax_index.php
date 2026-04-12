<?php
if (!defined('ABSPATH')) { exit(); }

require_once plugin_dir_path(__FILE__) . 'ajax_common.php';

// Aufteilung nach Verantwortung
require_once plugin_dir_path(__FILE__) . 'ajax_einsatzgebiet.php';
require_once plugin_dir_path(__FILE__) . 'ajax_nebenstellen.php';
require_once plugin_dir_path(__FILE__) . 'ajax_wachen.php';
require_once plugin_dir_path(__FILE__) . 'ajax_hospitals.php';
require_once plugin_dir_path(__FILE__) . 'ajax_pois.php';
require_once plugin_dir_path(__FILE__) . 'ajax_users.php';
require_once plugin_dir_path(__FILE__) . 'ajax_leitstellen.php';
require_once plugin_dir_path(__FILE__) . 'ajax_fahrzeuge.php';
require_once plugin_dir_path(__FILE__) . 'ajax_osm_layers.php';
require_once plugin_dir_path(__FILE__) . 'ajax_einsaetze.php';
require_once plugin_dir_path(__FILE__) . 'ajax_anruferprofile.php';
