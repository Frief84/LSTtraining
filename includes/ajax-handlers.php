<?php
/**
 * Legacy-Lader: ajax-handlers.php wurde in Module unter /includes/ajax/ aufgeteilt.
 * Diese Datei bleibt als Entry-Point bestehen, damit bestehende require_once Aufrufe weiter funktionieren.
 */
if (!defined('ABSPATH')) { exit(); }

require_once plugin_dir_path(__FILE__) . 'ajax/ajax_index.php';
