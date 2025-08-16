<?php
/**
 * admin-ui.php
 *
 * Page-Render-Callbacks and Admin UI Dispatcher for the LST Training plugin.
 */
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Admin UI Dispatcher für LSTtraining Plugin
 */
/**
 * Enqueue admin assets for each LST-Training sub-page separately.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    // --- 1) Common values --------------------------------------------------
    $root_url = plugin_dir_url(dirname(__FILE__)); // …/lsttraining-plugin/
    /* Shared libraries (loaded on every LST-Training page) */
    wp_enqueue_style('dashicons');
    wp_enqueue_style('lst-openlayers-css', $root_url . 'openlayers/ol.css');
    wp_enqueue_script('lst-openlayers', $root_url . 'openlayers/ol.js', [], null, true);
    wp_enqueue_style('lst-admin-css', $root_url . 'css/admin-ui.css', [], '1.0.0');
    wp_enqueue_script('lst-admin-ui', $root_url . 'js/admin-ui.js', ['jquery'], '1.0.2', true);
    // ───────────────────────────────────────────
    // Assets only for ► Leitstellen (page=lsttraining_leitstellen)
    // ───────────────────────────────────────────
    if ($hook === 'toplevel_page_lsttraining_leitstellen') {
        error_log("Lade assets für Hook: {$hook}");
        wp_enqueue_script('lst-leitstellen-editor', $root_url . 'js/leitstellen_editor.js', ['jquery', 'wp-util', 'lst-openlayers'], // ← lst-hospitals entfernt
        '1.0.0', true);
        wp_localize_script('lst-leitstellen-editor', 'lstLeitstellenAjax', // eigene Variable
        ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lsttraining_leitstellen'), ]);
    }
    // --- 2) Assets only for ► Leitstellen ▸ Krankenhäuser ------------------
   if (strpos($hook, '_page_lsttraining_krankenhaeuser') !== false) {
        // 1) Scripts
        wp_enqueue_script(
            'lst-departments',
            $root_url . 'js/departments.js',
            ['jquery', 'underscore', 'wp-util', 'lst-openlayers'],
            '1.0.0',
            true
        );
        wp_enqueue_script(
            'lst-hospitals',
            $root_url . 'js/hospitals.js',
            ['jquery', 'lst-openlayers', 'lst-departments'],
            '1.0.0',
            true
        );

        // 2) departments.json laden (aus /data/)
        $json_path = LSTTRAINING_PATH . 'data/departments.json';
        $departments = [];
        if (is_readable($json_path)) {
            $tmp = json_decode(file_get_contents($json_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $departments = $tmp;
            } else {
                error_log('lsttraining: departments.json invalid JSON: ' . json_last_error_msg());
            }
        } else {
            error_log('lsttraining: departments.json not readable at ' . $json_path);
        }

        // 3) Daten an JS
        wp_localize_script('lst-hospitals', 'lstHospitalsAjax', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('lsttraining_hospitals'),
            'plugin_url'  => LSTTRAINING_URL, // nicht plugin_dir_url(dirname(__FILE__))
            'departments' => $departments,    // leeres Array, falls Datei fehlte
        ]);
    }

    /* --- Leitstellen ▸ Wachen ------------------------------------------- */
    if ($hook === 'lsttraining_leitstellen_page_lsttraining_leitstellen_wachen') {
        wp_enqueue_script(
            'lst-wachen',
            $root_url . 'js/wachen.js',
            ['jquery', 'lst-openlayers'],
            '1.0.0',
            true
        );
        wp_localize_script('lst-wachen', 'lstWachenAjax', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'admin_url' => admin_url('admin.php'),
        ]);
    }
    // --- 3) Assets only for ► Leitstellen ▸ Wachen -------------------------
    if ($hook === 'lsttraining_leitstellen_page_lsttraining_leitstellen_wachen') {
        wp_enqueue_script('lst-wachen', $root_url . 'js/wachen.js', // ← correct path, no “includes/”
        ['jquery', 'lst-openlayers'], '1.0.0', true);
        wp_localize_script('lst-wachen', 'lstWachenAjax', ['ajax_url' => admin_url('admin-ajax.php'), 'admin_url' => admin_url('admin.php'), ]);
    }
    // --- 4) Optional: further pages - add more branches here ---------------
    
});
/**
 * Render the Leitstellen page.
 */
if (!function_exists('lsttraining_render_leitstellen')) {
    function lsttraining_render_leitstellen() {
        require_once plugin_dir_path(__FILE__) . 'leitstellen_editor.php';
    }
}

/**
 * Admin-Assets nur für die Nebenstellen-Seite enqueuen
 */
add_action('admin_enqueue_scripts', function ($hook) {
    // Nebenstellen-Seite (Submenü unter Leitstellen ODER Top-Level)
    if ($hook !== 'lsttraining_leitstellen_page_lsttraining_nebenstellen' && $hook !== 'toplevel_page_lsttraining_nebenstellen' && strpos($hook, '_page_lsttraining_nebenstellen') === false) {
        return;
    }
    $plugin_url = plugin_dir_url(dirname(__FILE__));
    // 1) OpenLayers
    wp_enqueue_script('lst-openlayers', $plugin_url . 'openlayers/ol.js', [], null, true);
    // 2) Turf (Simplify & Union)
    wp_enqueue_script('turf', $plugin_url . 'js/turf.min.js', [], null, true);
    // 3) Nebenstellen-Editor
    wp_enqueue_script('lst-nebenstellen-editor', $plugin_url . 'js/nebenstellen_editor.js', ['jquery', 'lst-openlayers'], '1.1.2', true);
    // 4) GeoJSON-Upload
    wp_enqueue_script('lst-einsatzgebiet-upload', $plugin_url . 'js/einsatzgebiet_upload.js', ['jquery', 'lst-nebenstellen-editor', 'turf'], '1.1.4', true);
    // 5) Daten/Nonces für Ajax
    require_once plugin_dir_path(__FILE__) . 'db.php';
    $all_ls = [];
    try {
        $pdo = lsttraining_get_connection();
        if ($pdo) {
            // Prüfen, ob unprefixt existiert (neues Schema)
            $table = 'leitstellen';
            $stmt = $pdo->query("SHOW TABLES LIKE 'leitstellen'");
            $exists_unpref = $stmt && $stmt->rowCount() > 0;
            if (!$exists_unpref && isset($GLOBALS['wpdb'])) {
                // Fallback: evtl. alte, prefixed Tabelle
                $candidate = $GLOBALS['wpdb']->prefix . 'leitstellen';
                $stmt2 = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($candidate));
                if ($stmt2 && $stmt2->rowCount() > 0) {
                    $table = $candidate;
                }
            }
            $stmt = $pdo->query("SELECT id, name FROM {$table} ORDER BY name");
            if ($stmt) {
                $all_ls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
    catch(Exception $e) {
        // still: $all_ls bleibt [], UI funktioniert ohne Liste (Modal zeigt leer)
        
    }
    wp_localize_script('lst-nebenstellen-editor', 'LSTTRAINING', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce_nebenstellen' => wp_create_nonce('lst_nebenstellen_nonce'), ]);
    wp_localize_script('lst-nebenstellen-editor', 'lstNebenstellenAjax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce_copy' => wp_create_nonce('lsttraining_copy_leitstelle'), 'nonce_delete' => wp_create_nonce('lsttraining_delete_nebenstelle'), 'allLeitstellen' => $all_ls, // jetzt ohne WP-DB-Fehler
    ]);
});
add_action('admin_enqueue_scripts', function ($hook) {
    $root_url = plugin_dir_url(dirname(__FILE__));
    // ────────────────────────────────────────────────────────────────
    // Common assets (all pages)
    wp_enqueue_style('dashicons');
    wp_enqueue_style('lst-openlayers-css', $root_url . 'openlayers/ol.css');
    wp_enqueue_script('lst-openlayers', $root_url . 'openlayers/ol.js', [], null, true);
    wp_enqueue_style('lst-admin-css', $root_url . 'css/admin-ui.css', [], '1.0.0');
    wp_enqueue_script('lst-admin-ui', $root_url . 'js/admin-ui.js', ['jquery'], '1.0.2', true);
    // ────────────────────────────────────────────────────────────────
    // Leitstellen & Wachen page=lsttraining_leitstellen_wachen
    if ($hook === 'lsttraining_leitstellen_page_lsttraining_leitstellen_wachen') {
        wp_enqueue_script('lst-wachen', $root_url . 'js/wachen.js', ['jquery', 'lst-openlayers'], '1.0.0', true);
        wp_localize_script('lst-wachen', 'lstWachenAjax', ['ajax_url' => admin_url('admin-ajax.php'), ]);
    }
    // ────────────────────────────────────────────────────────────────
    // Krankenhäuser (hook contains lsttraining_krankenhaeuser)
    if (strpos($hook, '_page_lsttraining_krankenhaeuser') !== false) {
        wp_enqueue_script('lst-departments', $root_url . 'js/departments.js', ['jquery', 'underscore', 'wp-util', 'lst-openlayers'], '1.0.0', true);
        wp_enqueue_script('lst-hospitals', $root_url . 'js/hospitals.js', ['jquery', 'lst-openlayers', 'lst-departments'], '1.0.0', true);
        $json_path = LSTTRAINING_PATH . 'data/departments.json';
        if (!file_exists($json_path)) {
            wp_die('departments.json not found at ' . esc_html($json_path));
        }
        $departments = json_decode(file_get_contents($json_path), true);
        wp_localize_script('lst-hospitals', 'lstHospitalsAjax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lsttraining_hospitals'), 'plugin_url' => plugin_dir_url(dirname(__FILE__)), 'departments' => $departments, ]);
    }
});
/**
 * Render-Funktion für „Nebenstellen“
 *  – hier nur das Template einbinden
 */
if (!function_exists('lsttraining_render_nebenstellen')) {
    function lsttraining_render_nebenstellen() {
        require_once plugin_dir_path(__FILE__) . 'nebenstellen_editor.php';
    }
}
/**
 * Render the Fahrzeuge page.
 */
if (!function_exists('lsttraining_render_leitstellen_fahrzeuge')) {
    function lsttraining_render_leitstellen_fahrzeuge() {
        echo '<div class="wrap"><h1>' . esc_html__('Leitstellen – Fahrzeuge', 'lsttraining') . '</h1></div>';
    }
}
/**
 * Render the Hospitals page.
 */
if (!function_exists('lsttraining_render_krankenhaeuser')) {
    function lsttraining_render_krankenhaeuser() {
        require_once plugin_dir_path(__FILE__) . 'hospitals.php';
    }
}
/**
 * Render the “Leitstellen & Wachen” admin page.
 */
if (!function_exists('lsttraining_render_leitstellen_wachen')) {
    function lsttraining_render_leitstellen_wachen() {
        /* 1 | Assets enqueuen (OpenLayers, wachen.js, Ajax-Variablen)  */
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        wp_enqueue_script('lst-openlayers', $plugin_url . 'openlayers/ol.js', [], null, true);
        wp_enqueue_script('geojson-utilities', 'https://cdn.jsdelivr.net/npm/@opendatalab/geojson-utilities@2/dist/index.umd.js', [], null, true);
        wp_enqueue_script('lst-wachen', $plugin_url . 'js/wachen.js', ['jquery', 'lst-openlayers'], '1.0.0', true);
        wp_localize_script('lst-wachen', 'lstWachenAjax', ['ajax_url' => admin_url('admin-ajax.php'), ]);
        /* 2 | Seite rendern – ENTWEDER wachen.php einbinden … */
        require_once plugin_dir_path(__FILE__) . 'wachen.php';
    }
}
/**
 * Zeigt den Inhalt der Seite „Benutzer“ an
 */
if (!function_exists('lsttraining_render_benutzer_page')) {
    function lsttraining_render_benutzer_page() {
        $template = plugin_dir_path(__FILE__) . 'benutzer.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Die Datei benutzer.php wurde nicht gefunden.', 'lsttraining');
            echo '</p></div>';
        }
    }
}
