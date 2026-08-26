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
        'leitstellen_ids'         => '',
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
    $user_id = $user_id ?: get_current_user_id();
    if ( user_can( $user_id, 'manage_options' ) ) {     // Admins dürfen immer
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
    if ( $ls_id && property_exists( $perm, 'leitstellen_ids' ) ) {
        $allowed = array_map( 'intval',
            array_filter( explode( ',', $perm->leitstellen_ids ) )
        );
        return in_array( $ls_id, $allowed, true );
    }
    return true;
}

/**
 * Normalisiert die Leitstellen-Freigaben eines Benutzers.
 */
function lsttraining_user_leitstellen_ids( ?int $user_id = null ): array {
    $perm = lsttraining_get_user_permissions( $user_id ?: get_current_user_id() );
    $raw  = property_exists( $perm, 'leitstellen_ids' ) ? (string) $perm->leitstellen_ids : '';

    return array_values( array_unique( array_filter(
        array_map( 'intval', explode( ',', $raw ) ),
        static fn( int $id ): bool => $id > 0
    ) ) );
}

/**
 * Prüft einen Bereich gegen alle Leitstellen, denen ein Objekt zugeordnet ist.
 * Unzugeordnete Objekte werden für Nicht-Admins absichtlich gesperrt.
 */
function lsttraining_user_can_all_leitstellen( string $area, array $leitstellen_ids, ?int $user_id = null ): bool {
    $user_id = $user_id ?: get_current_user_id();
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }

    $ids = array_values( array_unique( array_filter(
        array_map( 'intval', $leitstellen_ids ),
        static fn( int $id ): bool => $id > 0
    ) ) );
    if ( ! $ids || ! lsttraining_user_can( $area, null, $user_id ) ) {
        return false;
    }

    $allowed = lsttraining_user_leitstellen_ids( $user_id );
    return ! array_diff( $ids, $allowed );
}

/**
 * Ermittelt den Leitstellen-Scope eines Objekts ausschließlich aus der DB.
 */
function lsttraining_object_leitstellen_ids( PDO $pdo, string $object_type, int $object_id ): array {
    if ( $object_id <= 0 ) {
        return [];
    }

    $queries = [
        'leitstelle'   => 'SELECT id FROM leitstellen WHERE id = ?',
        'nebenstelle'  => 'SELECT leitstelle_id AS id FROM leitstelle_nebenleitstellen WHERE nebenleitstelle_id = ?',
        'wache'        => 'SELECT leitstelle_id AS id FROM wache_leitstellen WHERE wache_id = ? UNION SELECT ln.leitstelle_id AS id FROM wache_nebenleitstellen wn JOIN leitstelle_nebenleitstellen ln ON ln.nebenleitstelle_id = wn.nebenleitstelle_id WHERE wn.wache_id = ?',
        'fahrzeug'     => 'SELECT wl.leitstelle_id AS id FROM fahrzeuge f JOIN wache_leitstellen wl ON wl.wache_id = f.wache_id WHERE f.id = ? UNION SELECT ln.leitstelle_id AS id FROM fahrzeuge f JOIN wache_nebenleitstellen wn ON wn.wache_id = f.wache_id JOIN leitstelle_nebenleitstellen ln ON ln.nebenleitstelle_id = wn.nebenleitstelle_id WHERE f.id = ?',
    ];
    if ( ! isset( $queries[ $object_type ] ) ) {
        return [];
    }

    $stmt = $pdo->prepare( $queries[ $object_type ] );
    $placeholder_count = substr_count( $queries[ $object_type ], '?' );
    $stmt->execute( array_fill( 0, $placeholder_count, $object_id ) );
    return array_values( array_unique( array_filter( array_map(
        'intval',
        $stmt->fetchAll( PDO::FETCH_COLUMN ) ?: []
    ) ) ) );
}

/**
 * Löst neue Wachen-Zuordnungen in Leitstellen-IDs auf. Request-IDs werden
 * dabei nie als Berechtigungsnachweis akzeptiert, sondern in der DB geprüft.
 */
function lsttraining_assignment_leitstellen_ids( PDO $pdo, array $leitstellen_ids, array $nebenstellen_ids ): array {
    $ids = array_values( array_unique( array_filter( array_map( 'intval', $leitstellen_ids ) ) ) );
    $nls = array_values( array_unique( array_filter( array_map( 'intval', $nebenstellen_ids ) ) ) );
    if ( $nls ) {
        $placeholders = implode( ',', array_fill( 0, count( $nls ), '?' ) );
        $stmt = $pdo->prepare( "SELECT DISTINCT leitstelle_id FROM leitstelle_nebenleitstellen WHERE nebenleitstelle_id IN ($placeholders)" );
        $stmt->execute( $nls );
        $ids = array_merge( $ids, array_map( 'intval', $stmt->fetchAll( PDO::FETCH_COLUMN ) ?: [] ) );
    }
    return array_values( array_unique( array_filter( $ids ) ) );
}

function lsttraining_user_can_object( PDO $pdo, string $area, string $object_type, int $object_id, ?int $user_id = null ): bool {
    return lsttraining_user_can_all_leitstellen(
        $area,
        lsttraining_object_leitstellen_ids( $pdo, $object_type, $object_id ),
        $user_id
    );
}
