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
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    // Wir wollen alle Hooks, die unser Plugin betreffen
    if ( strpos( $hook, 'lsttraining' ) !== false ) {

        $base = plugin_dir_url( __FILE__ ) . '..';

        // Dashicons + OpenLayers
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( 'openlayers-style', $base . '/openlayers/ol.css' );
        wp_enqueue_script( 'openlayers', $base . '/openlayers/ol.js', [], null, true );

        // allgemeine Admin-UI
        wp_enqueue_style( 'lsttraining-admin-style', $base . '/css/admin-ui.css', [], '1.0' );
        wp_enqueue_script( 'lsttraining-admin-ui', $base . '/js/admin-ui.js', ['jquery'], '1.0.2', true );
        wp_enqueue_script( 'lsttraining-einsatzgebiet-editor', $base . '/js/einsatzgebiet-editor.js', ['jquery'], '1.0', true );
        wp_enqueue_script( 'lsttraining-nebenstellen-editor', $base . '/js/nebenstellen_editor.js', ['openlayers'], '1.0', true );

        wp_enqueue_script(
			'lsttraining-departments',
			plugin_dir_url( dirname( __FILE__ ) ) . 'js/departments.js',
			['jquery', 'underscore', 'wp-util'],
			'1.0',
			true
		);

		wp_enqueue_script(
			'lsttraining-hospitals',
			plugin_dir_url( dirname( __FILE__ ) ) . 'js/hospitals.js',
			[ 'jquery', 'lsttraining-departments' ], // ← richtig!
			'1.0',
			true
		);

    }
} );


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
 * Render the Leitstellen & Wachen page.
 */
if ( ! function_exists( 'lsttraining_render_leitstellen_wachen' ) ) {
    function lsttraining_render_leitstellen_wachen() {
        $base_url = plugin_dir_url( __FILE__ );

        // OpenLayers einbinden
        wp_enqueue_script(
            'openlayers',
            'https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js',
            [],
            null,
            true
        );

        // Eigenes JS einbinden
        wp_enqueue_script(
            'lsttraining-wachen',
            $base_url . 'js/wachen.js',
            [ 'jquery', 'openlayers' ],
            '1.0',
            true
        );

        // AJAX-URLs für das JS bereitstellen
        wp_localize_script(
            'lsttraining-wachen',
            'lstWachenAjax',
            [
                'ajax_url'  => admin_url( 'admin-ajax.php' ),
                'admin_url' => admin_url( 'admin.php' ),
            ]
        );

        // HTML-Ausgabe der Admin-Seite
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Leitstellen & Wachen', 'lsttraining' ); ?></h1>
            <div id="lsttraining-wachen-container" style="width:100%; height:600px;"></div>
        </div>
        <?php
    }
}
