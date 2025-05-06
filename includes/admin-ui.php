<?php
/**
 * Admin UI Dispatcher für LSTtraining Plugin
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    // Basis-Skripte/CSS für alle LSTtraining-Seiten
    if ( $hook === 'toplevel_page_lsttraining' || strpos( $hook, 'lsttraining_' ) !== false ) {
        $base = plugin_dir_url( __FILE__ ) . '..';
		wp_enqueue_style( 'dashicons' );
        // OpenLayers
        wp_enqueue_style(  'openlayers-style',    $base . '/openlayers/ol.css' );
        wp_enqueue_script( 'openlayers',          $base . '/openlayers/ol.js', [], null, true );

        // allgemeine Admin-UI
        wp_enqueue_style(  'lsttraining-admin-style',         $base . '/css/admin-ui.css', [], '1.0' );
        wp_enqueue_script( 'lsttraining-admin-ui',            $base . '/js/admin-ui.js', ['jquery'], '1.0.2', true );
        wp_enqueue_script( 'lsttraining-einsatzgebiet-editor', $base . '/js/einsatzgebiet-editor.js', ['jquery'], '1.0', true );
        wp_enqueue_script( 'lsttraining-nebenstellen-editor',  $base . '/js/nebenstellen_editor.js', ['openlayers'], '1.0', true );
    }
} );

// ------------------------------------------------------------------------------------------------
// Callback für die Seite "Leitstellen → Wachen"
// Hier enqueuen wir wachen.js NUR auf dieser einen Seite.
// ------------------------------------------------------------------------------------------------
function lsttraining_render_leitstellen_wachen() {
    $base = plugin_dir_url( __FILE__ ) . '..';

    // wachen.js & Localize nur hier
    wp_enqueue_script(
        'lsttraining-wachen',
        $base . '/js/wachen.js',
        ['jquery', 'openlayers'],
        '1.0',
        true
    );
    wp_localize_script(
        'lsttraining-wachen',
        'lstWachenAjax',
        [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'admin_url' => admin_url( 'admin.php' ),
        ]
    );

    // und dann das Template ausgeben
    include plugin_dir_path( __FILE__ ) . '/wachen.php';
}

// die anderen Callbacks unverändert…
function lsttraining_render_leitstellen() {
    require_once plugin_dir_path( __FILE__ ) . '/leitstellen_editor.php';
}

function lsttraining_render_leitstellen_fahrzeuge() {
    echo '<div class="wrap"><h1>Leitstellen – Fahrzeuge</h1><p>Hier könnten die Fahrzeuge der spielbaren Leitstellen verwaltet werden.</p></div>';
}

function lsttraining_render_nebenleitstellen() {
    require_once plugin_dir_path( __FILE__ ) . '/nebenstellen_editor.php';
}

function lsttraining_render_neben_wachen() {
    echo '<div class="wrap"><h1>Neben-Wachen</h1><p>Bearbeite realistische Wachen aus Nebenleitstellen.</p></div>';
}

function lsttraining_render_neben_fahrzeuge() {
    echo '<div class="wrap"><h1>Neben-Fahrzeuge</h1><p>Verwalte Fahrzeuge, die zu Neben-Wachen gehören.</p></div>';
}
