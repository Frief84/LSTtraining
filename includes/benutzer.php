<?php
/**
 * benutzer.php – Admin‐Seite: Benutzer‐Rechte verwalten (serverseitiges Laden)
 *
 * Diese Seite holt direkt alle WordPress‐Benutzer per get_users()
 * und liest ihre Rechte aus der Tabelle `user_permissions` via PDO.
 * Änderungen werden per POST direkt abgespeichert.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direktzugriff verhindern
}
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'Du hast keine ausreichenden Rechte, um diese Seite aufzurufen.', 'lsttraining' ) );
}

require_once plugin_dir_path( __FILE__ ) . 'db.php';
$table_name = 'user_permissions';

// 1) POST‐Verarbeitung: Speichern der Rechte
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['lsttraining_nonce'] ) ) {
    if ( ! wp_verify_nonce( $_POST['lsttraining_nonce'], 'lsttraining_save_permissions' ) ) {
        wp_die( __( 'Nonce‐Check fehlgeschlagen.', 'lsttraining' ) );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Du hast keine ausreichenden Rechte.', 'lsttraining' ) );
    }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Datenbankverbindung fehlgeschlagen.', 'lsttraining' ) . '</p></div>';
    } else {
        // Bereite Statements für Check/Insert/Update vor
        $stmtCheck  = $pdo->prepare( "SELECT user_id FROM {$table_name} WHERE user_id = ?" );
        $stmtInsert = $pdo->prepare( "
            INSERT INTO {$table_name} (
                user_id,
                can_edit_leitstellen,
                can_edit_nebenstellen,
                can_edit_hospitals,
                can_edit_wachen,
                can_edit_fahrzeuge,
                leistellen_ids
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        " );
        $stmtUpdate = $pdo->prepare( "
            UPDATE {$table_name}
               SET can_edit_leitstellen  = ?,
                   can_edit_nebenstellen = ?,
                   can_edit_hospitals    = ?,
                   can_edit_wachen       = ?,
                   can_edit_fahrzeuge    = ?,
                   leistellen_ids        = ?
             WHERE user_id = ?
        " );

        $all_user_ids = array_map( 'intval', $_POST['user_ids'] ?? [] );
        try {
            $pdo->beginTransaction();
            foreach ( $all_user_ids as $user_id ) {
                $can_leitstelle   = isset( $_POST["leitstellen_$user_id"] )   ? 1 : 0;
                $can_nebenstelle  = isset( $_POST["nebenstellen_$user_id"] )  ? 1 : 0;
                $can_hospital     = isset( $_POST["hospitals_$user_id"] )    ? 1 : 0;
                $can_wache        = isset( $_POST["wachen_$user_id"] )       ? 1 : 0;
                $can_fahrzeug     = isset( $_POST["fahrzeuge_$user_id"] )    ? 1 : 0;
                $leistellen_ids_raw = sanitize_text_field( $_POST["leistellen_ids_$user_id"] ?? '' );
                $ids_array = array_filter( array_map( 'trim', explode( ',', $leistellen_ids_raw ) ), function( $v ) {
                    return ( $v !== '' && ctype_digit( $v ) );
                } );
                $leistellen_ids = implode( ',', $ids_array );

                // Existenz prüfen
                $stmtCheck->execute( [ $user_id ] );
                $exists = (bool) $stmtCheck->fetchColumn();

                if ( $exists ) {
                    $stmtUpdate->execute( [
                        $can_leitstelle,
                        $can_nebenstelle,
                        $can_hospital,
                        $can_wache,
                        $can_fahrzeug,
                        $leistellen_ids,
                        $user_id
                    ] );
                } else {
                    $stmtInsert->execute( [
                        $user_id,
                        $can_leitstelle,
                        $can_nebenstelle,
                        $can_hospital,
                        $can_wache,
                        $can_fahrzeug,
                        $leistellen_ids
                    ] );
                }
            }
            $pdo->commit();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zugriffsrechte wurden gespeichert.', 'lsttraining' ) . '</p></div>';
        } catch ( PDOException $e ) {
            $pdo->rollBack();
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Datenbank‐Fehler: ', 'lsttraining' ) . esc_html( $e->getMessage() ) . '</p></div>';
        }
    }
}

// 2) Daten laden: alle WP‐Benutzer und ihre gespeicherten Rechte
$users = get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] );
$permissions = [];
if ( ! empty( $users ) ) {
    $user_ids = wp_list_pluck( $users, 'ID' );
    $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '?' ) );
    $pdo = lsttraining_get_connection();
    if ( $pdo ) {
        $sql = "SELECT * FROM {$table_name} WHERE user_id IN ($placeholders)";
        $stmt = $pdo->prepare( $sql );
        $stmt->execute( $user_ids );
        $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
        foreach ( $rows as $r ) {
            $permissions[ (int) $r['user_id'] ] = [
                'leitstellen'    => (int) $r['can_edit_leitstellen'],
                'nebenstellen'   => (int) $r['can_edit_nebenstellen'],
                'hospitals'      => (int) $r['can_edit_hospitals'],
                'wachen'         => (int) $r['can_edit_wachen'],
                'fahrzeuge'      => (int) $r['can_edit_fahrzeuge'],
                'leistellen_ids' => $r['leistellen_ids'],
            ];
        }
    }
}

// 3) HTML‐Formular ausgeben
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Benutzer‐Rechte verwalten', 'lsttraining' ); ?></h1>
    <form method="post" action="">
        <?php wp_nonce_field( 'lsttraining_save_permissions', 'lsttraining_nonce' ); ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Benutzername', 'lsttraining' ); ?></th>
                    <th style="text-align:center;"><?php esc_html_e( 'Leitstellen', 'lsttraining' ); ?></th>
                    <th style="text-align:center;"><?php esc_html_e( 'Nebenstellen', 'lsttraining' ); ?></th>
                    <th style="text-align:center;"><?php esc_html_e( 'Krankenhäuser', 'lsttraining' ); ?></th>
                    <th style="text-align:center;"><?php esc_html_e( 'Wachen', 'lsttraining' ); ?></th>
                    <th style="text-align:center;"><?php esc_html_e( 'Fahrzeuge', 'lsttraining' ); ?></th>
                    <th><?php esc_html_e( 'Leitstellen‐IDs (CSV)', 'lsttraining' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $users ) ) : ?>
                <tr>
                    <td colspan="7"><?php esc_html_e( 'Keine Benutzer gefunden.', 'lsttraining' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $users as $user ) :
                    $uid = (int) $user->ID;
                    $perm = $permissions[ $uid ] ?? [
                        'leitstellen'    => 0,
                        'nebenstellen'   => 0,
                        'hospitals'      => 0,
                        'wachen'         => 0,
                        'fahrzeuge'      => 0,
                        'leistellen_ids' => '',
                    ];
                ?>
                <tr>
                    <td style="vertical-align: middle;">
                        <?php echo esc_html( $user->user_login ); ?>
                        <input type="hidden" name="user_ids[]" value="<?php echo esc_attr( $uid ); ?>">
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="leitstellen_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( $perm['leitstellen'], 1 ); ?>>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="nebenstellen_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( $perm['nebenstellen'], 1 ); ?>>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="hospitals_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( $perm['hospitals'], 1 ); ?>>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="wachen_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( $perm['wachen'], 1 ); ?>>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="fahrzeuge_<?php echo esc_attr( $uid ); ?>" value="1" <?php checked( $perm['fahrzeuge'], 1 ); ?>>
                    </td>
                    <td>
                        <input
                            type="text"
                            name="leistellen_ids_<?php echo esc_attr( $uid ); ?>"
                            value="<?php echo esc_attr( $perm['leistellen_ids'] ); ?>"
                            class="regular-text"
                            placeholder="<?php esc_attr_e( 'z. B. 3,5,12', 'lsttraining' ); ?>"
                            title="<?php esc_attr_e( 'Kommagetrennte Liste von Leitstellen‐IDs', 'lsttraining' ); ?>"
                        >
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php submit_button( __( 'Rechte speichern', 'lsttraining' ) ); ?>
    </form>
</div>
