<?php
/**
 * admin-ui.php
 *
 * Page-Render-Callbacks and Admin UI Dispatcher for the LST Training plugin.
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_enqueue_scripts', function ($hook) {

    // admin-ui.php liegt in /includes/
    // Plugin-Root-Datei liegt eine Ebene höher:
    $plugin_file = dirname(__DIR__) . '/lsttraining-plugin.php';

    // Robust: Root-URL/Path immer aus Plugin-Root-Datei ableiten
    $root_url  = plugin_dir_url($plugin_file);   // .../wp-content/plugins/lsttraining-plugin/
    $root_path = plugin_dir_path($plugin_file);  // .../wp-content/plugins/lsttraining-plugin/

    // Gate robuster machen: entweder Hook oder ?page enthält lsttraining
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    $is_lst_page = (strpos((string)$hook, 'lsttraining') !== false) || (strpos($page, 'lsttraining') !== false);

    if (!$is_lst_page) {
        return;
    }

    // Common (alle LSTtraining-Seiten)
    wp_enqueue_style('dashicons');

    // OpenLayers (aus Plugin-Ordner)
    wp_enqueue_style('lst-openlayers-css', $root_url . 'openlayers/ol.css', [], null);
    wp_enqueue_script('lst-openlayers', $root_url . 'openlayers/ol.js', [], null, true);

    // Admin UI
    wp_enqueue_style('lst-admin-css', $root_url . 'css/admin-ui.css', [], '1.0.0');
    wp_enqueue_script('lst-admin-ui', $root_url . 'js/admin-ui.js', ['jquery'], '1.0.2', true);

    // ───────────────────────────────────────────
    // Leitstellen (Top-Level)
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_leitstellen' || $hook === 'toplevel_page_lsttraining_leitstellen') {

        wp_enqueue_script(
            'lst-leitstellen-editor',
            $root_url . 'js/leitstellen_editor.js',
            ['jquery', 'wp-util', 'lst-openlayers', 'lst-admin-ui'],
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'lst-leitstellen-pois',
            $root_url . 'js/pois.js',
            ['jquery', 'wp-util', 'lst-openlayers', 'lst-leitstellen-editor'],
            '1.0.0',
            true
        );

        // Einsatzgebiet-Editor (Popup) – benötigt für „Einsatzgebiet bearbeiten“
        wp_enqueue_script(
            'lsttraining-einsatzgebiet-editor',
            $root_url . 'js/einsatzgebiet-editor.js',
            ['jquery', 'lst-openlayers'],
            '1.0.0',
            true
        );

        // Turf (für simplify + union)
        wp_enqueue_script(
            'lsttraining-turf',
            $root_url . 'js/turf.min.js',
            [],
            '6.5.0',
            true
        );

        // Turf-Upload-Logik
        wp_enqueue_script(
            'lsttraining-einsatzgebiet-upload',
            $root_url . 'js/einsatzgebiet_upload.js',
            ['lsttraining-turf', 'lst-openlayers', 'lsttraining-einsatzgebiet-editor'],
            '1.0.0',
            true
        );

        wp_localize_script('lst-leitstellen-editor', 'lstLeitstellenAjax', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('lsttraining_leitstellen'),
            // falls du OSM-AJAX nutzt:
            'osm_nonce' => wp_create_nonce('lsttraining_osm_layers'),
        ]);

        wp_enqueue_script(
            'lst-zuordnung-inline',
            $root_url . 'js/zuordnung_modal.js',
            ['lst-openlayers'],
            '1.0.0',
            true
        );

        wp_localize_script('lst-zuordnung-inline', 'lstZuordnungAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('lst_zuordnung'),
        ]);
    }

    // ───────────────────────────────────────────
    // Krankenhäuser
    // ───────────────────────────────────────────
    if (strpos($page, 'lsttraining_krankenhaeuser') !== false || strpos((string)$hook, 'lsttraining_krankenhaeuser') !== false) {

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

        $departments = [];
        $json_path = $root_path . 'data/departments.json';
        if (is_readable($json_path)) {
            $tmp = json_decode(file_get_contents($json_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $departments = $tmp;
            }
        }

        wp_localize_script('lst-hospitals', 'lstHospitalsAjax', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('lsttraining_hospitals'),
            'plugin_url'  => $root_url,
            'departments' => $departments,
        ]);
    }

    // ───────────────────────────────────────────
    // Wachen (Subpage unter Leitstellen)
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_leitstellen_wachen' || strpos((string)$hook, 'leitstellen_wachen') !== false) {

        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0-rc.0'
        );

        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0-rc.0',
            true
        );

        wp_enqueue_script(
            'lst-wachen',
            $root_url . 'js/wachen.js',
            ['jquery', 'lst-openlayers', 'select2'],
            '1.0.2',
            true
        );

        wp_localize_script('lst-wachen', 'lstWachenAjax', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'admin_url'       => admin_url('admin.php'),
            'fahrzeuge_nonce' => wp_create_nonce('lst_fahrzeuge_nonce'),
        ]);
    }

    // ───────────────────────────────────────────
    // Fahrzeuge (eigene Seite)
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_fahrzeuge' || strpos((string)$hook, 'lsttraining_fahrzeuge') !== false || strpos((string)$hook, 'leitstellen_fahrzeuge') !== false) {

        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0-rc.0'
        );

        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0-rc.0',
            true
        );

        wp_enqueue_script(
            'lst-fahrzeuge',
            $root_url . 'js/fahrzeuge.js',
            ['jquery', 'select2'],
            '1.9.1',
            true
        );

        $bundeslaender = [];
        $json_path = $root_path . 'data/bundeslaender.json';
        if (is_readable($json_path)) {
            $tmp = json_decode(file_get_contents($json_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $bundeslaender = $tmp;
            }
        }

        $fahrzeugtypen = [];
        $ft_path = $root_path . 'data/fahrzeugtypen.json';
        if (is_readable($ft_path)) {
            $tmp = json_decode(file_get_contents($ft_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $fahrzeugtypen = array_values(array_filter(array_map('strval', $tmp)));
            }
        }
        if (empty($fahrzeugtypen)) {
            $fahrzeugtypen = ['RTW','NEF','KTW','HLF 20','LF 20','DLK 23/12','GW-San','ELW 1','MTW'];
        }

        wp_localize_script('lst-fahrzeuge', 'lstFahrzeugeAjax', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('lst_fahrzeuge_nonce'),
            'bundeslaender' => $bundeslaender,
            'fahrzeugtypen' => $fahrzeugtypen,
        ]);
    }
});

/* ---------------- Render-Callbacks (Templates liegen in /includes) ---------------- */

if (!function_exists('lsttraining_render_leitstellen')) {
    function lsttraining_render_leitstellen() {
        require_once plugin_dir_path(__FILE__) . 'leitstellen_editor.php';
    }
}

if (!function_exists('lsttraining_render_nebenstellen')) {
    function lsttraining_render_nebenstellen() {
        require_once plugin_dir_path(__FILE__) . 'nebenstellen_editor.php';
    }
}

if (!function_exists('lsttraining_render_leitstellen_fahrzeuge')) {
    function lsttraining_render_leitstellen_fahrzeuge() {
        if (!current_user_can('read')) {
            wp_die('Keine Berechtigung.');
        }
        $template = plugin_dir_path(__FILE__) . 'fahrzeuge.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>Die Datei fahrzeuge.php wurde nicht gefunden.</p></div>';
        }
    }
}

if (!function_exists('lsttraining_render_krankenhaeuser')) {
    function lsttraining_render_krankenhaeuser() {
        require_once plugin_dir_path(__FILE__) . 'hospitals.php';
    }
}

if (!function_exists('lsttraining_render_leitstellen_wachen')) {
    function lsttraining_render_leitstellen_wachen() {
        require_once plugin_dir_path(__FILE__) . 'wachen.php';
    }
}

if (!function_exists('lsttraining_render_benutzer_page')) {
    function lsttraining_render_benutzer_page() {
        $template = plugin_dir_path(__FILE__) . 'benutzer.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>Die Datei benutzer.php wurde nicht gefunden.</p></div>';
        }
    }
}

if (!function_exists('lsttraining_render_verlauf_page')) {
    function lsttraining_render_verlauf_page() {
        $template = plugin_dir_path(__FILE__) . 'verlauf.php';
        if (file_exists($template)) {
            require_once $template;
        } else {
            echo '<div class="notice notice-error"><p>Die Datei verlauf.php wurde nicht gefunden.</p></div>';
        }
    }
}
