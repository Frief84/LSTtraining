<?php
/**
 * Globale Rechte-Helpers für LST-Training
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path( __FILE__ ) . '/db.php';

/**
 * Holt einen Datensatz aus user_permissions (einmal pro Request gecacht).
 */
function lsttraining_get_user_permissions( $user_id = 0 ) {
    static $cache = [];
    $user_id = $user_id ?: get_current_user_id();

    if ( isset( $cache[ $user_id ] ) ) {
        return $cache[ $user_id ];
    }

    // --- Fixierter DB-Zugriff ------------------------------------------
    $pdo = lsttraining_get_connection();
    if ( $pdo ) {
        $stmt = $pdo->prepare( "SELECT * FROM user_permissions WHERE user_id = ?" );
        $stmt->execute( [ $user_id ] );
        $row = $stmt->fetch( PDO::FETCH_OBJ );
    } else {
        $row = false;
    }
    // -------------------------------------------------------------------

    // Fallback: nichts gesetzt → alles false
    $cache[ $user_id ] = $row ?: (object) [
        'can_edit_leitstellen'   => 0,
        'can_edit_nebenstellen'  => 0,
        'can_edit_hospitals'     => 0,
        'can_edit_wachen'        => 0,
        'can_edit_fahrzeuge'     => 0,
        'leistellen_ids'         => '',
    ];
    return $cache[ $user_id ];
}

/**
 * Prüft, ob der aktuelle Nutzer eine Objekt-Kategorie (und ggf. eine
 * konkrete Leitstelle) bearbeiten darf.
 *
 * @param string      $area   leitstellen | nebenstellen | hospitals | wachen | fahrzeuge
 * @param int|null    $ls_id  Leitstellen-ID oder null (Kategorie-breit)
 */
function lsttraining_user_can( string $area, ?int $ls_id = null, ?int $user_id = null ): bool {
    if ( current_user_can( 'manage_options' ) ) {       // Admins dürfen immer
        return true;
    }
    $perm = lsttraining_get_user_permissions( $user_id );

    // 1. Grobe Bereichsberechtigung
    $flag = match ( $area ) {
        'leitstellen'  => $perm->can_edit_leitstellen,
        'nebenstellen' => $perm->can_edit_nebenstellen,
        'hospitals'    => $perm->can_edit_hospitals,
        'wachen'       => $perm->can_edit_wachen,
        'fahrzeuge'    => $perm->can_edit_fahrzeuge,
        default        => 0,
    };
    if ( ! $flag ) {
        return false;                                   // Bereich komplett gesperrt
    }

    // 2. Falls Leitstellen-Scope erforderlich → IDs prüfen
    if ( $ls_id && property_exists( $perm, 'leistellen_ids' ) ) {
        $allowed = array_map( 'intval',
            array_filter( explode( ',', $perm->leistellen_ids ) )
        );
        return in_array( $ls_id, $allowed, true );
    }
    return true;
}
