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

    // --- 2) Assets only for ► Leitstellen ▸ Krankenhäuser ------------------
    if ( strpos( $hook, '_page_lsttraining_krankenhaeuser' ) !== false ) {

    wp_enqueue_script(
        'lst-departments',
        $root_url . 'js/departments.js',
        [ 'jquery', 'underscore', 'wp-util' , 'lst-openlayers'],
        '1.0.0', true
    );

    wp_enqueue_script(
        'lst-hospitals',
        $root_url . 'js/hospitals.js',
        [ 'jquery', 'lst-openlayers', 'lst-departments' ],
        '1.0.0', true
    );

    wp_localize_script(
        'lst-hospitals',
        'lstHospitalsAjax',
        [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'lsttraining_hospitals' ),
			'plugin_url' => plugin_dir_url( dirname( __FILE__ ) ), 
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





// ─────────────────────────────────────────────
// Page-Render-Callbacks (nur jeweils EINE Definition!)
// ─────────────────────────────────────────────

/**
 * Render the Leitstellen page.
 */
if ( ! function_exists( 'lsttraining_render_leitstellen' ) ) {
    function lsttraining_render_leitstellen() {
        require_once plugin_dir_path( __FILE__ ) . 'leitstellen_editor.php';
    }
}

/**
 * Render the Nebenleitstellen page.
 */
if ( ! function_exists( 'lsttraining_render_nebenleitstellen' ) ) {
    function lsttraining_render_nebenleitstellen() {
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

