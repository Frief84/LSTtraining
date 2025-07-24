<?php
/**
 * Admin-Menü für das LST-Training-Plugin
 * – Sichtbarkeit der Menüpunkte richtet sich nach
 *   lsttraining_user_can() und Admin-Status.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_menu', function () {

    /* ------------------------------------------------------------------ */
    /*   1. Berechtigungen des aktuellen Benutzers prüfen                  */
    /* ------------------------------------------------------------------ */
    $is_admin = current_user_can( 'manage_options' );

    // Statt der alten Helper-Funktion nutzen wir jetzt lsttraining_user_can()
    $can = [
        'leitstellen'  => lsttraining_user_can( 'leitstellen'  ),
        'nebenstellen' => lsttraining_user_can( 'nebenstellen' ),
        'hospitals'    => lsttraining_user_can( 'hospitals'    ),
        'wachen'       => lsttraining_user_can( 'wachen'       ),
        'fahrzeuge'    => lsttraining_user_can( 'fahrzeuge'    ),
    ];

    // Hat der Benutzer weder Admin-Rechte noch irgendeine Ressource?
    if ( ! $is_admin && ! in_array( true, $can, true ) ) {
        return;                       // → Menü gar nicht erst anlegen
    }

    /* ------------------------------------------------------------------ */
    /*   2. Top-Level-Eintrag                                             */
    /* ------------------------------------------------------------------ */
    $parent_slug = 'lsttraining_leitstellen';

    add_menu_page(
        'LST Training',               // Page-Titel
        'LST Training',               // Menü-Label
        'read',                       // minimale Cap: jeder eingeloggte User
        $parent_slug,                 // Slug
        'lsttraining_render_leitstellen',
        'dashicons-location-alt',
        30
    );

    /* ------------------------------------------------------------------ */
    /*   3. Unterpunkte – nur hinzufügen, wenn die Berechtigung stimmt     */
    /* ------------------------------------------------------------------ */

    // 3.1 Leitstellen (Placement 0 → ganz oben)
    if ( $can['leitstellen'] ) {
        add_submenu_page(
            $parent_slug,
            'Leitstellen',
            'Leitstellen',
            'read',
            $parent_slug,             // identisch mit Parent-Slug
            'lsttraining_render_leitstellen',
            0
        );
    }

    // 3.2 Nebenstellen
    if ( $can['nebenstellen'] ) {
        add_submenu_page(
            $parent_slug,
            'Nebenstellen',
            'Nebenstellen',
            'read',
            'lsttraining_nebenstellen',
            'lsttraining_render_nebenleitstellen'
        );
    }

    // 3.3 Krankenhäuser
    if ( $can['hospitals'] ) {
        add_submenu_page(
            $parent_slug,
            'Krankenhäuser',
            'Krankenhäuser',
            'read',
            'lsttraining_krankenhaeuser',
            'lsttraining_render_krankenhaeuser'
        );
    }

    // 3.4 Wachen
    if ( $can['wachen'] ) {
        add_submenu_page(
            $parent_slug,
            'Wachen',
            'Wachen',
            'read',
            'lsttraining_leitstellen_wachen',
            'lsttraining_render_leitstellen_wachen'
        );
    }

    // 3.5 Fahrzeuge
    if ( $can['fahrzeuge'] ) {
        add_submenu_page(
            $parent_slug,
            'Fahrzeuge',
            'Fahrzeuge',
            'read',
            'lsttraining_fahrzeuge',
            'lsttraining_render_leitstellen_fahrzeuge'
        );
    }

    /* ------------------------------------------------------------------ */
    /*   4. Admin-exklusive Punkte                                        */
    /* ------------------------------------------------------------------ */
    if ( $is_admin ) {

        add_submenu_page(
            $parent_slug,
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'lsttraining',
            'lsttraining_settings_page'
        );

        add_submenu_page(
            $parent_slug,
            'Benutzer',
            'Benutzer',
            'manage_options',
            'lsttraining_benutzer',
            'lsttraining_render_benutzer_page'
        );
    }
} );
