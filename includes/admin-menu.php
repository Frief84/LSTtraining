<?php
/**
 * Admin Menu for LSTtraining Plugin
 *
 * @package LSTtraining
 */

add_action('admin_menu', function () {
    // Hauptmenüpunkt für LSTtraining (spielbare Umgebung)
    add_menu_page(
        'LSTtraining Einstellungen',
        'LSTtraining',
        'manage_options',
        'lsttraining',
        'lsttraining_settings_page',
        'dashicons-shield-alt',
        30
    );

    // Unterpunkte für LSTtraining
    add_submenu_page('lsttraining', 'Einstellungen', 'Einstellungen', 'manage_options', 'lsttraining', 'lsttraining_settings_page');
    add_submenu_page('lsttraining', 'Leitstellen', 'Leitstellen', 'manage_options', 'lsttraining_leitstellen', 'lsttraining_render_leitstellen');
    add_submenu_page('lsttraining', 'Wachen', 'Wachen', 'manage_options', 'lsttraining_leitstellen_wachen', 'lsttraining_render_leitstellen_wachen');
    add_submenu_page('lsttraining', 'Fahrzeuge', 'Fahrzeuge', 'manage_options', 'lsttraining_leitstellen_fahrzeuge', 'lsttraining_render_leitstellen_fahrzeuge');

    // Nebenstruktur als Hauptpunkt mit Dummy-Seite (zeigt ersten Unterpunkt)
    add_menu_page(
        'Nebenstruktur',
        'Nebenstruktur',
        'manage_options',
        'lsttraining_nebenleitstellen',
        'lsttraining_render_nebenleitstellen',
        'dashicons-location',
        31
    );

    // Unterpunkte für Nebenstruktur
    add_submenu_page('lsttraining_nebenleitstellen', 'Nebenleitstellen', 'Nebenleitstellen', 'manage_options', 'lsttraining_nebenleitstellen', 'lsttraining_render_nebenleitstellen');
    add_submenu_page('lsttraining_nebenleitstellen', 'Neben-Wachen', 'Wachen', 'manage_options', 'lsttraining_neben_wachen', 'lsttraining_render_neben_wachen');
    add_submenu_page('lsttraining_nebenleitstellen', 'Neben-Fahrzeuge', 'Fahrzeuge', 'manage_options', 'lsttraining_neben_fahrzeuge', 'lsttraining_render_neben_fahrzeuge');
});
