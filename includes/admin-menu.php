<?php
/**
 * Admin Menu for LST Training Plugin
 */

add_action( 'admin_menu', function() {

    // ────────────────────────────────
    // Top-Level: LST Training
    // ────────────────────────────────
    add_menu_page(
        'LST Training',
        'LST Training',
        'manage_options',
        'lsttraining_leitstellen',
        'lsttraining_render_leitstellen',
        'dashicons-location-alt',
        30
    );

    // ────────────────────────────────
    //  1) Leitstellen (default)
    // ────────────────────────────────
    // wird automatisch vom add_menu_page() angelegt

    // ────────────────────────────────
    //  2) Nebenleitstellen
    // ────────────────────────────────
    add_submenu_page(
        'lsttraining_leitstellen',
        'Nebenleitstellen',
        'Nebenleitstellen',
        'manage_options',
        'lsttraining_nebenleitstellen',
        'lsttraining_render_nebenleitstellen'
    );

    // ────────────────────────────────
    //  2a) Krankenhäuser
    // ────────────────────────────────
    add_submenu_page(
        'lsttraining_leitstellen',
        'Krankenhäuser',
        'Krankenhäuser',
        'manage_options',
        'lsttraining_krankenhaeuser',
        'lsttraining_render_krankenhaeuser'
    );

    // ────────────────────────────────
    //  3) Wachen
    // ────────────────────────────────
    add_submenu_page(
        'lsttraining_leitstellen',
        'Wachen',
        'Wachen',
        'manage_options',
        'lsttraining_leitstellen_wachen',
        'lsttraining_render_leitstellen_wachen'
    );

    // ────────────────────────────────
    //  4) Fahrzeuge
    // ────────────────────────────────
    add_submenu_page(
        'lsttraining_leitstellen',
        'Fahrzeuge',
        'Fahrzeuge',
        'manage_options',
        'lsttraining_leitstellen_fahrzeuge',
        'lsttraining_render_leitstellen_fahrzeuge'
    );

    // ────────────────────────────────
    //  5) Einstellungen
    // ────────────────────────────────
    add_submenu_page(
        'lsttraining_leitstellen',
        'Einstellungen',
        'Einstellungen',
        'manage_options',
        'lsttraining',
        'lsttraining_settings_page'
    );

    // ────────────────────────────────
    //  Position der “Leitstellen” ganz oben
    // ────────────────────────────────
    remove_submenu_page( 'lsttraining_leitstellen', 'lsttraining_leitstellen' );
    add_submenu_page(
        'lsttraining_leitstellen',
        'Leitstellen',
        'Leitstellen',
        'manage_options',
        'lsttraining_leitstellen',
        'lsttraining_render_leitstellen',
        0
    );
});

