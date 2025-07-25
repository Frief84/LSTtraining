<?php
/**
 * admin-ui.php
 *
 * Page-Render-Callbacks and Admin UI Dispatcher for the LST Training plugin.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin UI Dispatcher für LSTtraining Plugin
 */
/**
 * Enqueue admin assets for each LST-Training sub-page separately.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {

    // --- 1) Common values --------------------------------------------------
    $root_url = plugin_dir_url( dirname( __FILE__ ) ); // …/lsttraining-plugin/

    /* Shared libraries (loaded on every LST-Training page) */
    wp_enqueue_style(  'dashicons' );
    wp_enqueue_style(  'lst-openlayers-css', $root_url . 'openlayers/ol.css' );
    wp_enqueue_script( 'lst-openlayers',     $root_url . 'openlayers/ol.js',
                       [], null, true );

    wp_enqueue_style(  'lst-admin-css',      $root_url . 'css/admin-ui.css',
                       [], '1.0.0' );
    wp_enqueue_script( 'lst-admin-ui',       $root_url . 'js/admin-ui.js',
                       [ 'jquery' ], '1.0.2', true );

// ───────────────────────────────────────────
    // Assets only for ► Leitstellen (page=lsttraining_leitstellen)
    // ───────────────────────────────────────────
if ( $hook === 'toplevel_page_lsttraining_leitstellen' ) {
error_log( "Lade assets für Hook: {$hook}" );
    wp_enqueue_script(
        'lst-leitstellen-editor',
        $root_url . 'js/leitstellen_editor.js',
        [ 'jquery', 'wp-util', 'lst-openlayers' ],  // ← lst-hospitals entfernt
        '1.0.0',
        true
    );
    wp_localize_script(
        'lst-leitstellen-editor',
        'lstLeitstellenAjax',                      // eigene Variable
        [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'lsttraining_leitstellen' ),
        ]
    );
}

   // --- 2) Assets only for ► Leitstellen ▸ Krankenhäuser ------------------
if ( strpos( $hook, '_page_lsttraining_krankenhaeuser' ) !== false ) {

    /* ------------------------------------------------------------------
       1) JavaScript-Dateien
    ------------------------------------------------------------------ */
    wp_enqueue_script(
        'lst-departments',
        $root_url . 'js/departments.js',
        [ 'jquery', 'underscore', 'wp-util', 'lst-openlayers' ],
        '1.0.0',
        true
    );

    wp_enqueue_script(
        'lst-hospitals',
        $root_url . 'js/hospitals.js',
        [ 'jquery', 'lst-openlayers', 'lst-departments' ],
        '1.0.0',
        true
    );

    /* ------------------------------------------------------------------
       2) departments.json einlesen  (liegt in /includes/)
    ------------------------------------------------------------------ */
$json_path = __DIR__ . '/departments.json';
if ( ! file_exists( $json_path ) ) {
    wp_die( 'departments.json nicht gefunden unter: ' . esc_html( $json_path ) );
}
$departments = json_decode( file_get_contents( $json_path ), true );

    /* ------------------------------------------------------------------
       3) Daten für AJAX & Departments an JS übergeben
    ------------------------------------------------------------------ */
    wp_localize_script(
        'lst-hospitals',
        'lstHospitalsAjax',
        [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'lsttraining_hospitals' ),
            'plugin_url'   => plugin_dir_url( dirname( __FILE__ ) ),
            'departments'  => $departments          //  ← NEU
        ]
    );
}

    // --- 3) Assets only for ► Leitstellen ▸ Wachen -------------------------
    if ( $hook === 'lsttraining_leitstellen_page_lsttraining_leitstellen_wachen' ) {

        wp_enqueue_script(
            'lst-wachen',
            $root_url . 'js/wachen.js',       // ← correct path, no “includes/”
            [ 'jquery', 'lst-openlayers' ],
            '1.0.0', true
        );

        wp_localize_script(
            'lst-wachen',
            'lstWachenAjax',
            [
                'ajax_url'  => admin_url( 'admin-ajax.php' ),
                'admin_url' => admin_url( 'admin.php' ),
            ]
        );
    }

    // --- 4) Optional: further pages - add more branches here ---------------
});


/**
 * Render the Leitstellen page.
 */
if ( ! function_exists( 'lsttraining_render_leitstellen' ) ) {
    function lsttraining_render_leitstellen() {
        require_once plugin_dir_path( __FILE__ ) . 'leitstellen_editor.php';
    }
}

/**
 * Render-Funktion für die Nebenleitstellen-Adminseite
 * URL: wp-admin/admin.php?page=lsttraining_nebenleitstellen
 */
if ( ! function_exists( 'lsttraining_render_nebenleitstellen' ) ) {
    function lsttraining_render_nebenleitstellen() {

        /* 1 | Assets enqueuen */
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );      // …/lsttraining-plugin/

        // (falls Deine Nebenstellen-JS OpenLayers braucht, sonst Zeile löschen)
        wp_enqueue_script(
            'lst-openlayers',
            $plugin_url . 'openlayers/ol.js',
            [], null, true
        );

        wp_enqueue_script(
            'lst-nebenstellen-editor',
            $plugin_url . 'js/nebenstellen_editor.js',
            [ 'jquery', 'lst-openlayers' ],   // 'lst-openlayers' rausnehmen, wenn unnötig
            '1.0.0', true                     // Footer laden
        );

        // Ajax-URL und ggf. weitere Daten ins JS schieben
        wp_localize_script( 'lst-nebenstellen-editor', 'lstNebenstellenAjax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ] );

        /* 2 | Seite rendern */
        require_once plugin_dir_path( __FILE__ ) . 'nebenstellen_editor.php';
    }
}

/**
 * Render the Fahrzeuge page.
 */
if ( ! function_exists( 'lsttraining_render_leitstellen_fahrzeuge' ) ) {
    function lsttraining_render_leitstellen_fahrzeuge() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Leitstellen – Fahrzeuge', 'lsttraining' ) . '</h1></div>';
    }
}

/**
 * Render the Hospitals page.
 */
if ( ! function_exists( 'lsttraining_render_krankenhaeuser' ) ) {
    function lsttraining_render_krankenhaeuser() {
        require_once plugin_dir_path( __FILE__ ) . 'hospitals.php';
    }
}


/**
 * Render the “Leitstellen & Wachen” admin page.
 */
if ( ! function_exists( 'lsttraining_render_leitstellen_wachen' ) ) {
    function lsttraining_render_leitstellen_wachen() {

        /* 1 | Assets enqueuen (OpenLayers, wachen.js, Ajax-Variablen)  */
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );

        wp_enqueue_script( 'lst-openlayers',
            $plugin_url . 'openlayers/ol.js', [], null, true );

        wp_enqueue_script( 'lst-wachen',
            $plugin_url . 'js/wachen.js',
            [ 'jquery', 'lst-openlayers' ], '1.0.0', true );

        wp_localize_script( 'lst-wachen', 'lstWachenAjax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ] );

        /* 2 | Seite rendern – ENTWEDER wachen.php einbinden … */
        require_once plugin_dir_path( __FILE__ ) . 'wachen.php';

    }
}
/**
 * Zeigt den Inhalt der Seite „Benutzer“ an
 */
if ( ! function_exists( 'lsttraining_render_benutzer_page' ) ) {
    function lsttraining_render_benutzer_page() {
        $template = plugin_dir_path( __FILE__ ) . 'benutzer.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'Die Datei benutzer.php wurde nicht gefunden.', 'lsttraining' );
            echo '</p></div>';
        }
    }
}

