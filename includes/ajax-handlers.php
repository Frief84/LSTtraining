<?php
/**
 * AJAX-Handler für das LST-Training-Plugin
 * – sämtliche Rechteprüfungen laufen über lsttraining_user_can()
 *   (siehe includes/permissions.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direktzugriff verhindern
}

require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/permissions.php';


/* -------------------------------------------------------------------------
 * 1. LEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Leitstelle laden
 * @action wp_ajax_lsttraining_get_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_get_einsatzgebiet', function () {

    $leitstelle_id = intval( $_GET['leitstelle_id'] ?? 0 );
    if ( ! $leitstelle_id ) {
        wp_send_json_error( 'Leitstellen-ID fehlt' );
    }
    if ( ! lsttraining_user_can( 'leitstellen', $leitstelle_id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "SELECT geojson FROM leitstellen WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $leitstelle_id ] );
    $geojson = $stmt->fetchColumn();

    wp_send_json_success( $geojson ? json_decode( $geojson, true ) : null );
});

/**
 * GeoJSON einer Leitstelle speichern
 * @action wp_ajax_lsttraining_save_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_save_einsatzgebiet', function () {

    $leitstelle_id = intval( $_POST['leitstelle_id'] ?? 0 );
    if ( ! lsttraining_user_can( 'leitstellen', $leitstelle_id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $geojson = stripslashes( $_POST['geojson'] ?? '' );
    if ( $leitstelle_id <= 0 || empty( $geojson ) ) {
        wp_send_json_error( 'Invalid data', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "UPDATE leitstellen SET geojson = ? WHERE id = ?" );
    $stmt->execute( [ $geojson, $leitstelle_id ] );

    wp_send_json_success();
});


/* -------------------------------------------------------------------------
 * 2. NEBENLEITSTELLEN (GeoJSON-Editor)
 * ---------------------------------------------------------------------- */

/**
 * GeoJSON einer Nebenleitstelle laden
 * @action wp_ajax_lsttraining_get_neben_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_get_neben_einsatzgebiet', function () {

    $neben_id = intval( $_GET['neben_id'] ?? 0 );
    if ( ! $neben_id ) {
        wp_send_json_error( 'Nebenleitstellen-ID fehlt' );
    }
    if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "SELECT geojson FROM nebenleitstellen WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $neben_id ] );
    $geojson = $stmt->fetchColumn();

    wp_send_json_success( $geojson ? json_decode( $geojson, true ) : null );
});

/**
 * GeoJSON einer Nebenleitstelle speichern
 * @action wp_ajax_lsttraining_save_neben_einsatzgebiet
 */
add_action( 'wp_ajax_lsttraining_save_neben_einsatzgebiet', function () {

    if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $neben_id = intval( $_POST['neben_id'] ?? 0 );
    $geojson  = stripslashes( $_POST['geojson'] ?? '' );

    if ( $neben_id <= 0 || empty( $geojson ) ) {
        wp_send_json_error( 'Invalid data', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "UPDATE nebenleitstellen SET geojson = ? WHERE id = ?" );
    $stmt->execute( [ $geojson, $neben_id ] );

    wp_send_json_success();
});

/**
 * Ajax: neue Nebenleitstelle anlegen
 * @action wp_ajax_lsttraining_create_nebenleitstelle
 */
add_action('wp_ajax_lsttraining_create_nebenleitstelle', function () use ($wpdb) {

	error_log('[AJAX] reached lsttraining_create_nebenstelle');
	check_ajax_referer('lst_nebenstellen_nonce');
    $table = $wpdb->prefix . 'nebenleitstellen';

    $id   = intval($_POST['id'] ?? 0); // optional, falls externe ID-Vergabe
    $name = sanitize_text_field($_POST['name'] ?? '');

    if ($name !== '') {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE name=%s", $name));
        if ($exists) {
            wp_send_json_error(['code' => 'name_conflict', 'message' => 'Name bereits vorhanden'], 409);
        }
    }

    $data = [
        'name'                => $name,
        'zustandigkeit'       => sanitize_text_field($_POST['zustandigkeit'] ?? ''),
        'einwohner'           => intval($_POST['einwohner'] ?? 0),
        'flaeche_km2'         => floatval($_POST['flaeche'] ?? 0),
        'gps'                 => sanitize_text_field($_POST['gps'] ?? ''),
        'nachbarleitstelle'   => 0,
        'geojson'             => null,
        'created_by'          => get_current_user_id(),
        'created_at'          => current_time('mysql', 1),
    ];

    $format = ['%s','%s','%d','%f','%s','%d','%s','%d','%s'];
    if ($id > 0) {
        $data['id'] = $id;
        array_unshift($format, '%d');
    }

    $ok = $wpdb->insert($table, $data, $format);
    if (!$ok) wp_send_json_error('Insert fehlgeschlagen', 500);

    $new_id = $wpdb->insert_id ?: $id;
    wp_send_json_success(['id' => $new_id]);
});

/**
 * Speichern einer Nebenleitstelle (Insert oder Update)
 * @action wp_ajax_lsttraining_save_nebenleitstelle
 */
add_action('wp_ajax_lsttraining_save_nebenleitstelle', function () {
    // 1) Nonce + Rechte prüfen (ohne wp_die)
    if ( ! check_ajax_referer('lst_nebenstellen_nonce', '_ajax_nonce', false) ) {
        wp_send_json_error(['code'=>'bad_nonce','msg'=>'Nonce ungültig'], 403);
    }
    if ( function_exists('lsttraining_user_can') && ! lsttraining_user_can('nebenstellen') ) {
        wp_send_json_error(['code'=>'forbidden','msg'=>'Keine Berechtigung'], 403);
    }

    // 2) DB verbinden (PDO aus db.php, nicht $wpdb)
    require_once plugin_dir_path(__FILE__) . 'db.php';
    try {
        $pdo = lsttraining_get_connection(); // verbindet zu deiner externen DB
        if ( ! $pdo ) {
            wp_send_json_error(['code'=>'db','msg'=>'DB-Verbindung fehlgeschlagen'], 500);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code'=>'db','msg'=>'DB-Verbindung: '.$e->getMessage()], 500);
    }

    // 3) Eingaben
    $id          = intval($_POST['id'] ?? 0);          // 0/leer => INSERT
    $desired_id  = intval($_POST['desired_id'] ?? 0);  // optional bei INSERT
    $name        = sanitize_text_field($_POST['name'] ?? '');
    $zust        = sanitize_text_field($_POST['zustandigkeit'] ?? '');
    $einwohner   = intval($_POST['einwohner'] ?? 0);
    $flaeche_km2 = floatval($_POST['flaeche'] ?? 0);
    $gps         = sanitize_text_field($_POST['gps'] ?? '');

    if ($name === '') {
        wp_send_json_error(['code'=>'validation','msg'=>'Name darf nicht leer sein'], 400);
    }

    // 4) Eindeutigkeit Name prüfen
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM nebenleitstellen WHERE name = :name AND id <> :id");
            $stmt->execute([':name'=>$name, ':id'=>$id]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM nebenleitstellen WHERE name = :name");
            $stmt->execute([':name'=>$name]);
        }
        if ((int)$stmt->fetchColumn() > 0) {
            wp_send_json_error(['code'=>'name_conflict','msg'=>'Name bereits vorhanden'], 409);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code'=>'db','msg'=>'Prüfung fehlgeschlagen: '.$e->getMessage()], 500);
    }

    // 5) INSERT oder UPDATE mit PDO
    try {
        if ($id > 0) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE nebenleitstellen
                   SET name = :name,
                       zustandigkeit = :zust,
                       einwohner = :einwohner,
                       flaeche_km2 = :flaeche_km2,
                       gps = :gps
                 WHERE id = :id
            ");
            $stmt->execute([
                ':name'=>$name, ':zust'=>$zust, ':einwohner'=>$einwohner,
                ':flaeche_km2'=>$flaeche_km2, ':gps'=>$gps, ':id'=>$id
            ]);
            wp_send_json_success(['id'=>$id]);

        } else {
            // INSERT
            if ($desired_id > 0) {
                // gewünschte ID frei?
                $chk = $pdo->prepare("SELECT 1 FROM nebenleitstellen WHERE id = :id LIMIT 1");
                $chk->execute([':id'=>$desired_id]);
                if ($chk->fetchColumn()) {
                    wp_send_json_error(['code'=>'id_conflict','msg'=>'Gewünschte ID bereits vergeben'], 409);
                }
                $stmt = $pdo->prepare("
                    INSERT INTO nebenleitstellen (id, name, zustandigkeit, einwohner, flaeche_km2, gps)
                    VALUES (:id, :name, :zust, :einwohner, :flaeche_km2, :gps)
                ");
                $stmt->execute([
                    ':id'=>$desired_id, ':name'=>$name, ':zust'=>$zust,
                    ':einwohner'=>$einwohner, ':flaeche_km2'=>$flaeche_km2, ':gps'=>$gps
                ]);
                wp_send_json_success(['id'=>$desired_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO nebenleitstellen (name, zustandigkeit, einwohner, flaeche_km2, gps)
                    VALUES (:name, :zust, :einwohner, :flaeche_km2, :gps)
                ");
                $stmt->execute([
                    ':name'=>$name, ':zust'=>$zust, ':einwohner'=>$einwohner,
                    ':flaeche_km2'=>$flaeche_km2, ':gps'=>$gps
                ]);
                $newId = (int)$pdo->lastInsertId();
                wp_send_json_success(['id'=>$newId]);
            }
        }
    } catch (Throwable $e) {
        wp_send_json_error(['code'=>'db','msg'=>'Speichern fehlgeschlagen: '.$e->getMessage()], 500);
    }
});




/**
 * Löscht eine Nebenstelle via AJAX
 * @action wp_ajax_lsttraining_delete_nebenstelle
 */
add_action( 'wp_ajax_lsttraining_delete_nebenstelle', function() {
    // Rechte prüfen
    if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }
    // Nonce prüfen
    check_ajax_referer( 'lsttraining_delete_nebenstelle', '_wpnonce' );

    // ID validieren
    $id = filter_input( INPUT_POST, 'id', FILTER_VALIDATE_INT );
    if ( ! $id ) {
        wp_send_json_error( 'Ungültige ID', 400 );
    }

    $pdo = lsttraining_get_connection();
    // Nebenstelle löschen
    $stmt = $pdo->prepare( "DELETE FROM nebenleitstellen WHERE id = ?" );
    $ok   = $stmt->execute( [ $id ] );
    // Pivot-Beziehungen löschen
    $pdo->prepare( "DELETE FROM wache_nebenleitstellen WHERE nebenleitstelle_id = ?" )
        ->execute( [ $id ] );

    if ( $ok ) {
        wp_send_json_success();
    } else {
        wp_send_json_error( 'Löschen fehlgeschlagen', 500 );
    }
});

/* -------------------------------------------------------------------------
 * 3. POP-UP-EDITOR (gemeinsamer Render-Endpunkt)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_lsttraining_render_einsatzgebiet_editor', function () {

    require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

    $mapId        = sanitize_text_field( $_GET['map_id']        ?? 'einsatzgebiet_edit' );
    $inputId      = sanitize_text_field( $_GET['input_id']      ?? 'geojson_edit' );
    $leitstelleId = intval( $_GET['leitstelle_id'] ?? 0 );
    $context      = sanitize_text_field( $_GET['context']       ?? 'leitstelle' );
    $center       = sanitize_text_field( $_GET['center']        ?? '' );

    $geojson = '';

    ob_start();
    lsttraining_einsatzgebiet_editor(
        $mapId,
        $inputId,
        $geojson,
        $leitstelleId,
        $context,
        $center
    );
    echo ob_get_clean();
    wp_die();
});


/* -------------------------------------------------------------------------
 * 4. WACHEN (Liste, Details, CRUD)
 * ---------------------------------------------------------------------- */

/**
 * Liste der Wachen für Karte/Tabelle
 * @action wp_ajax_lsttraining_get_wachen
 */
add_action('wp_ajax_lsttraining_get_wachen', 'lsttraining_get_wachen');

function lsttraining_get_wachen() {
    // Keine vorherige Ausgabe → pures JSON
    while (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }

    // 1) DB verbinden: IMMER deinen funktionierenden Helper benutzen
    //    (so machst du es auch bei save/delete/create)
    //    db.php nur einbinden, wenn nicht ohnehin geladen
    if (!function_exists('lsttraining_get_connection')) {
        require_once plugin_dir_path(__FILE__) . 'db.php';
    }
    try {
        $pdo = lsttraining_get_connection();
        if (!$pdo instanceof PDO) {
            wp_send_json_error(['msg' => 'DB-Verbindung fehlgeschlagen'], 500);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['msg' => 'DB-Verbindung fehlgeschlagen'], 500);
    }

    // 2) Filter lesen & Exklusivität erzwingen (BL > LS > NLS)
    $ls  = isset($_REQUEST['ls_id'])  ? (int)$_REQUEST['ls_id']  : 0;
    $nls = isset($_REQUEST['nls_id']) ? (int)$_REQUEST['nls_id'] : 0;
    $bl  = isset($_REQUEST['bundesland']) ? sanitize_text_field($_REQUEST['bundesland']) : '';

    if ($bl !== '') { $ls = 0; $nls = 0; }
    elseif ($ls)    { $nls = 0; $bl = ''; }
    elseif ($nls)   { $ls  = 0; $bl = ''; }

    if (!$ls && !$nls && $bl === '') {
        wp_send_json_error(['msg' => 'Kein Filter angegeben.'], 400);
    }

    // 3) SQL wie in wachen.php: Pivot-Tabellen für LS/NLS, direktes Feld für BL
    $sql = "
        SELECT
            w.id,
            w.name,
            w.typ,
            w.latitude,
            w.longitude
        FROM wachen AS w
    ";
    $joins  = '';
    $where  = [];
    $params = [];

    if ($bl !== '') {
        if ($bl === '__none__') {
            $where[] = '(w.bundesland IS NULL OR w.bundesland = \'\')';
        } else {
            $where[]   = 'w.bundesland = :bl';
            $params[':bl'] = $bl;
        }
    } elseif ($ls) {
        $joins   .= ' INNER JOIN wache_leitstellen AS wl ON w.id = wl.wache_id ';
        $where[]  = 'wl.leitstelle_id = :ls';
        $params[':ls'] = $ls;
    } elseif ($nls) {
        $joins   .= ' INNER JOIN wache_nebenleitstellen AS wn ON w.id = wn.wache_id ';
        $where[]  = 'wn.nebenleitstelle_id = :nls';
        $params[':nls'] = $nls;
    }

    $sql .= $joins;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY w.name ASC LIMIT 2000';

    // 4) Ausführen und JSON liefern
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        while (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
        wp_send_json_success(['count' => count($rows), 'wachen' => $rows], 200);

    } catch (Throwable $e) {
        // Fehlermeldung absichtlich generisch halten
        error_log('lsttraining_get_wachen SQL ERROR: ' . $e->getMessage());
        wp_send_json_error(['msg' => 'DB-Fehler'], 500);
    }
}





/**
 * Daten einer einzelnen Wache laden
 * @action wp_ajax_lsttraining_get_wache
 */
add_action( 'wp_ajax_lsttraining_get_wache', function () {
    // 1) Capability check
    if ( ! lsttraining_user_can( 'wachen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    // 2) Parameter prüfen
    $id = intval( $_GET['wache_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Wache-ID fehlt', 400 );
    }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo instanceof PDO ) {
        wp_send_json_error( 'Datenbankfehler', 500 );
    }

    try {
        // 3) Basis-Daten der Wache
        $stmt = $pdo->prepare(
            'SELECT id, name, typ, latitude, longitude, arrival_pos, departure_pos
               FROM wachen
              WHERE id = ?'
        );
        $stmt->execute( [ $id ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );

        if ( ! $row ) {
            wp_send_json_error( 'Nicht gefunden', 404 );
        }

        // 4) Zugeordnete Leitstellen holen
        $stmt2 = $pdo->prepare(
            'SELECT leitstelle_id
               FROM wache_leitstellen
              WHERE wache_id = ?'
        );
        $stmt2->execute( [ $id ] );
        $row['leitstellen'] = $stmt2->fetchAll( PDO::FETCH_COLUMN );

        // 5) Zugeordnete Nebenleitstellen holen
        $stmt3 = $pdo->prepare(
            'SELECT nebenleitstelle_id
               FROM wache_nebenleitstellen
              WHERE wache_id = ?'
        );
        $stmt3->execute( [ $id ] );
        $row['nebenleitstellen'] = $stmt3->fetchAll( PDO::FETCH_COLUMN );

        // 6) Alles ok, JSON zurückliefern
        wp_send_json_success( $row );

    } catch ( PDOException $e ) {
        error_log( 'lsttraining_get_wache ERROR: ' . $e->getMessage() );
        wp_send_json_error( 'Datenbankfehler', 500 );
    }
} );




/**
 * Speichert eine bestehende Wache (Basis-Daten + Zuordnungen)
 * @action wp_ajax_lsttraining_save_wache
 */
add_action( 'wp_ajax_lsttraining_save_wache', function () {
    // 1) Rechte prüfen: nur Nutzer mit „wachen“-Capability dürfen
    if ( ! lsttraining_user_can( 'wachen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    // 2) Eingabe holen & validieren
    $id        = intval(   $_POST['id']           ?? 0 );
    $name      = sanitize_text_field( $_POST['name']      ?? '' );
    $typ       = sanitize_text_field( $_POST['typ']       ?? '' );
    $latitude  = floatval( $_POST['latitude']  ?? 0 );
    $longitude = floatval( $_POST['longitude'] ?? 0 );
    $arrival   = sanitize_text_field( $_POST['arrival_pos']   ?? '' );
    $departure = sanitize_text_field( $_POST['departure_pos'] ?? '' );
    // Pivot-IDs: Arrays aus dem JS (z.B. [1,3,5])
    $leit_ids  = array_map('intval', (array) ($_POST['leitstellen']      ?? []) );
    $neben_ids = array_map('intval', (array) ($_POST['nebenleitstellen'] ?? []) );

    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige Wache-ID', 400 );
    }

    $pdo = lsttraining_get_connection();
    try {
        $pdo->beginTransaction();

        // 3) Basis-Daten in `wachen` updaten
        $stmt = $pdo->prepare(
            'UPDATE wachen
                SET name          = ?,
                    typ           = ?,
                    latitude      = ?,
                    longitude     = ?,
                    arrival_pos   = ?,
                    departure_pos = ?
              WHERE id = ?'
        );
        $ok = $stmt->execute( [
            $name, $typ, $latitude, $longitude,
            $arrival, $departure, $id
        ] );
        if ( ! $ok ) {
            throw new Exception( 'Basis-Update fehlgeschlagen: '. implode(', ', $stmt->errorInfo()) );
        }

        // 4) Pivot-Tabelle `wache_leitstellen` neu befüllen
        //    ➔ alle alten Einträge für diese Wache löschen…
        $pdo->prepare('DELETE FROM wache_leitstellen WHERE wache_id = ?')
            ->execute([ $id ]);
        //    ➔ …und dann alle neuen Zuordnungen einfügen
        if ( ! empty( $leit_ids ) ) {
            $ins = $pdo->prepare('INSERT INTO wache_leitstellen (wache_id, leitstelle_id) VALUES (?, ?)');
            foreach ( $leit_ids as $lid ) {
                $ins->execute([ $id, $lid ]);
            }
        }

        // 5) Pivot-Tabelle `wache_nebenleitstellen` neu befüllen
        $pdo->prepare('DELETE FROM wache_nebenleitstellen WHERE wache_id = ?')
            ->execute([ $id ]);
        if ( ! empty( $neben_ids ) ) {
            $ins2 = $pdo->prepare('INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id) VALUES (?, ?)');
            foreach ( $neben_ids as $nlid ) {
                $ins2->execute([ $id, $nlid ]);
            }
        }

        $pdo->commit();
        wp_send_json_success();
    } catch ( Exception $e ) {
        $pdo->rollBack();
        error_log( 'lsttraining_save_wache ERROR: ' . $e->getMessage() );
        wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
    }
});


/**
 * Löscht eine Wache inklusive aller Pivot-Beziehungen
 * @action wp_ajax_lsttraining_delete_wache
 */
add_action( 'wp_ajax_lsttraining_delete_wache', function () {
    if ( ! lsttraining_user_can( 'wachen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id = intval( $_POST['wache_id'] ?? 0 );
    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige Wache-ID', 400 );
    }

    $pdo = lsttraining_get_connection();
    try {
        $pdo->beginTransaction();

        // 1) Alle Pivot-Beziehungen löschen
        $pdo->prepare('DELETE FROM wache_leitstellen WHERE wache_id = ?')->execute([ $id ]);
        $pdo->prepare('DELETE FROM wache_nebenleitstellen WHERE wache_id = ?')->execute([ $id ]);

        // 2) Die Wache selbst entfernen
        $stmt = $pdo->prepare('DELETE FROM wachen WHERE id = ?');
        $ok   = $stmt->execute([ $id ]);
        if ( ! $ok ) {
            throw new Exception( 'Löschen fehlgeschlagen: '. implode(', ', $stmt->errorInfo()) );
        }

        $pdo->commit();
        wp_send_json_success();
    } catch ( Exception $e ) {
        $pdo->rollBack();
        error_log( 'lsttraining_delete_wache ERROR: ' . $e->getMessage() );
        wp_send_json_error( 'Löschen fehlgeschlagen', 500 );
    }
});


/**
 * Legt eine neue Wache an und setzt erste Zuordnungen
 * @action wp_ajax_lsttraining_create_wache
 */
add_action( 'wp_ajax_lsttraining_create_wache', function () {
    if ( ! lsttraining_user_can( 'wachen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    // Eingaben bereinigen
    $name       = sanitize_text_field( $_POST['name']      ?? '' );
    $typ        = sanitize_text_field( $_POST['typ']       ?? '' );
    $latitude   = floatval( $_POST['latitude']   ?? 0 );
    $longitude  = floatval( $_POST['longitude']  ?? 0 );
    $arrival    = sanitize_text_field( $_POST['arrival_pos']   ?? '' );
    $departure  = sanitize_text_field( $_POST['departure_pos'] ?? '' );
    $ls_ids     = array_map('intval', (array) ($_POST['leitstellen']      ?? []) );
    $nls_ids    = array_map('intval', (array) ($_POST['nebenleitstellen'] ?? []) );

    // Pflichtfelder prüfen
    if ( $name === '' || $latitude === 0 || $longitude === 0 ) {
        wp_send_json_error( 'Pflichtfelder fehlen', 400 );
    }

    $pdo = lsttraining_get_connection();
    try {
        $pdo->beginTransaction();

        // 1) Neue Wache anlegen
        $stmt = $pdo->prepare(
            'INSERT INTO wachen (name, typ, latitude, longitude, arrival_pos, departure_pos)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ok = $stmt->execute([
            $name, $typ, $latitude, $longitude, $arrival, $departure
        ]);
        if ( ! $ok ) {
            throw new Exception( 'Anlegen fehlgeschlagen: '. implode(', ', $stmt->errorInfo()) );
        }
        $new_id = $pdo->lastInsertId();

        // 2) Pivot-Zuordnungen: Leitstellen
        if ( ! empty( $ls_ids ) ) {
            $ins = $pdo->prepare('INSERT INTO wache_leitstellen (wache_id, leitstelle_id) VALUES (?, ?)');
            foreach ( $ls_ids as $lid ) {
                $ins->execute([ $new_id, $lid ]);
            }
        }

        // 3) Pivot-Zuordnungen: Nebenleitstellen
        if ( ! empty( $nls_ids ) ) {
            $ins2 = $pdo->prepare('INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id) VALUES (?, ?)');
            foreach ( $nls_ids as $nlid ) {
                $ins2->execute([ $new_id, $nlid ]);
            }
        }

        $pdo->commit();
        wp_send_json_success([ 'id' => $new_id ]);
    } catch ( Exception $e ) {
        $pdo->rollBack();
        error_log( 'lsttraining_create_wache ERROR: ' . $e->getMessage() );
        wp_send_json_error( 'Anlegen fehlgeschlagen', 500 );
    }
});



/* -------------------------------------------------------------------------
 * 5. KRANKENHÄUSER (Liste, Details, CRUD, Departments)
 * ---------------------------------------------------------------------- */

/**
 * Liste aller Krankenhäuser
 * @action wp_ajax_get_krankenhaeuser
 */
add_action( 'wp_ajax_get_krankenhaeuser', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'SELECT id, name, versorgungsstufe, trauma_level, latitude, longitude
           FROM krankenhaeuser
          ORDER BY name'
    );
    $stmt->execute();

    wp_send_json( $stmt->fetchAll( PDO::FETCH_ASSOC ) );
});


/**
 * Einzelnes Krankenhaus lesen (read-only)
 *  – nopriv-Hook bleibt erhalten
 */
add_action( 'wp_ajax_get_krankenhaus',        'lsttraining_ajax_get_krankenhaus' );
add_action( 'wp_ajax_nopriv_get_krankenhaus', 'lsttraining_ajax_get_krankenhaus' );
function lsttraining_ajax_get_krankenhaus() {

    header( 'Content-Type: application/json; charset=utf-8' );

    $id = intval( $_GET['id'] ?? 0 );
    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige Krankenhaus-ID', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'SELECT id, name, versorgungsstufe, trauma_level,
                latitude, longitude, departments, helipad
           FROM krankenhaeuser
          WHERE id = ?'
    );
    $stmt->execute( [ $id ] );
    $row = $stmt->fetch( PDO::FETCH_ASSOC );

    $row ? wp_send_json_success( $row )
         : wp_send_json_error   ( 'Krankenhaus nicht gefunden', 404 );

    wp_die();
}


/**
 * Ein Krankenhaus löschen
 * @action wp_ajax_delete_krankenhaus
 */
add_action( 'wp_ajax_delete_krankenhaus', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $id = intval( $_POST['id'] ?? 0 );
    if ( $id <= 0 ) {
        wp_send_json_error( 'Ungültige ID', 400 );
    }

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( 'DELETE FROM krankenhaeuser WHERE id = ?' );
    $ok   = $stmt->execute( [ $id ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Löschen fehlgeschlagen', 500 );
});

/**
 * Krankenhaus speichern
 * @action wp_ajax_save_krankenhaus
 */
add_action( 'wp_ajax_save_krankenhaus', function () {

    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    /* ---------- Basisfelder ---------- */
    $id               = intval( $_POST['id'] ?? 0 );
    $name             = sanitize_text_field( $_POST['name'] ?? '' );
    $versorgungsstufe = sanitize_text_field( $_POST['versorgungsstufe'] ?? '' );
    $trauma_level     = intval( $_POST['trauma_level'] ?? 0 );
    $latitude         = floatval( $_POST['latitude'] ?? 0 );
    $longitude        = floatval( $_POST['longitude'] ?? 0 );
    $helipad          = isset( $_POST['helipad'] ) ? 1 : 0;

    /* ---------- optionale Departments ---------- */
    $departments_in = array_key_exists( 'departments', $_POST )
                      ? stripslashes( $_POST['departments'] )
                      : null;                       // bleibt NULL bei Edit ohne Änderungen

    /* ---------- Metadaten ---------- */
    $editor_id   = get_current_user_id();           // WordPress-Nutzer
    $now_mysql   = current_time( 'mysql', 1 );      // UTC | für TIMESTAMP ohne auto-update

    /* ---------- Prüfen ---------- */
    if ( $id <= 0 || $name === '' ) {
        wp_send_json_error( 'Ungültige Daten', 400 );
    }

    /* ---------- Statement dynamisch zusammenbauen ---------- */
    $set = '
        name             = ?,
        versorgungsstufe = ?,
        trauma_level     = ?,
        latitude         = ?,
        longitude        = ?,
        helipad          = ?,
        last_update      = ?,
        last_editor      = ?';
    $params = [
        $name, $versorgungsstufe, $trauma_level,
        $latitude, $longitude, $helipad,
        $now_mysql,             // nur nötig, wenn last_update NICHT per ON UPDATE gesetzt
        $editor_id
    ];

    if ( $departments_in !== null ) {
        $set     .= ', departments = ?';
        $params[] = $departments_in;
    }

    $params[] = $id;   // WHERE-Parameter

    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare( "UPDATE krankenhaeuser SET $set WHERE id = ?" );

    $ok = $stmt->execute( $params );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
});



/* -------------------------------------------------------------------------
 *  NEW  ·  Krankenhaus anlegen
 *  @action wp_ajax_lsttraining_create_krankenhaus
 * ---------------------------------------------------------------------- */
add_action( 'wp_ajax_lsttraining_create_krankenhaus', function () {

    /* 1 | Rechteprüfung: darf der Nutzer Hospitals bearbeiten? */
    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    /* 2 | Pflichtfeld NAME einlesen und prüfen */
    $name = sanitize_text_field( $_POST['name'] ?? '' );
    if ( $name === '' ) {
        wp_send_json_error( 'Name fehlt', 400 );
    }

    /* 3 | optionale Felder vorbereiten  -------------------------------- */
    $versorgungsstufe = sanitize_text_field( $_POST['versorgungsstufe'] ?? '' );
    $trauma_level     = intval( $_POST['trauma_level']     ?? 0 );

    /* Koordinaten: bevorzugt einzelne Felder latitude/longitude,
       alternativ gepacktes Feld coords = "lat,lon" (z. B. aus einem <input hidden>) */
    $lat = floatval( $_POST['latitude']  ?? 0 );
    $lon = floatval( $_POST['longitude'] ?? 0 );
    if ( $lat === 0 && $lon === 0 && ! empty( $_POST['coords'] ) ) {
        [ $lat, $lon ] = array_map( 'floatval', explode( ',', $_POST['coords'] ) );
    }

    /* Departments als JSON-String (leerer String = kein Dept-Array) */
    $departments = stripslashes( $_POST['departments'] ?? '' );
    $departments = $departments === '' ? '[]' : $departments;

    /* Landeplatz vorhanden? (Checkbox) */
    $helipad = isset( $_POST['helipad'] ) ? 1 : 0;

    /* 4 | Einfügen  ----------------------------------------------------- */
    $pdo = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'INSERT INTO krankenhaeuser
             (name, versorgungsstufe, trauma_level, latitude, longitude, departments, helipad)
         VALUES (?,?,?,?,?,?,?)'
    );

    $ok = $stmt->execute( [
        $name,
        $versorgungsstufe,
        $trauma_level,
        $lat,
        $lon,
        $departments,
        $helipad
    ] );

    /* 5 | Antwort  ------------------------------------------------------ */
    if ( $ok ) {
        wp_send_json_success( [
            'new_id' => (int) $pdo->lastInsertId()
        ] );
    }
    wp_send_json_error( 'Anlegen fehlgeschlagen', 500 );
} );



/* -------------------------------------------------------------------------
 * Departments-Liste speichern
 *  – erwartet:
 *        hospital_id   (int)
 *        departments   • Array   departments[]=CAT&departments[]=ORTH
 *                      • oder    JSON-String  '["CAT","ORTH"]'
 *                      • oder    JSON-String  '{"CAT":{"enabled":true}, ...}'
 * ---------------------------------------------------------------------- */
add_action( 'wp_ajax_lsttraining_save_departments', 'lsttraining_save_departments' );

function lsttraining_save_departments() {

    /* 1 | Berechtigung */
    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    /* 2 | Krankenhaus-ID */
    $hid = intval( $_POST['hospital_id'] ?? 0 );
    if ( $hid <= 0 ) {
        wp_send_json_error( 'Krankenhaus-ID fehlt', 400 );
    }

    /* 3 | Rohdaten einlesen */
    $raw = $_POST['departments'] ?? [];
    if ( is_string( $raw ) && $raw !== '' ) {
        $raw = json_decode( wp_unslash( $raw ), true );
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( 'Ungültiges JSON', 400 );
        }
    }
    if ( empty( $raw ) ) {
        wp_send_json_error( 'Keine Departments übermittelt', 400 );
    }

    /* Default-Koordinaten = Klinik-Lat/Lon (falls gewünscht → mit­schicken) */
    $defLat = isset( $_POST['hospital_lat'] ) ? floatval( $_POST['hospital_lat'] ) : 0;
    $defLon = isset( $_POST['hospital_lon'] ) ? floatval( $_POST['hospital_lon'] ) : 0;

    /* 4 | Codes → neues Format mappen */
    $map = [];  // code => [Lat,Long]

    foreach ( $raw as $key => $val ) {

        /* A) Checkbox-Array (numerischer Key) */
        if ( is_int( $key ) || ctype_digit( (string) $key ) ) {
            $code = strtoupper( sanitize_key( $val ) );
            if ( $code !== '' ) {
                $map[ $code ] = [ 'Lat' => $defLat, 'Long' => $defLon ];
            }
            continue;
        }

        /* B) Neues JSON: CODE => {Lat,Long} */
        if ( is_array( $val ) && isset( $val['Lat'], $val['Long'] ) ) {
            $code = strtoupper( sanitize_key( $key ) );
            if ( $code !== '' ) {
                $map[ $code ] = [
                    'Lat'  => floatval( $val['Lat']  ),
                    'Long' => floatval( $val['Long'] )
                ];
            }
            continue;
        }

        /* C) Altes Einzel-Objekt {code, latitude, longitude} */
        if ( is_array( $val ) && isset( $val['code'] ) ) {
            $code = strtoupper( sanitize_key( $val['code'] ) );
            if ( $code !== '' ) {
                $map[ $code ] = [
                    'Lat'  => floatval( $val['latitude']  ?? $defLat ),
                    'Long' => floatval( $val['longitude'] ?? $defLon )
                ];
            }
        }
    }

    if ( empty( $map ) ) {
        wp_send_json_error( 'Keine gültigen Codes gefunden', 400 );
    }

    /* 5 | Endformat bauen */
    $out = [];
    foreach ( $map as $code => $latlon ) {
        $out[] = [ $code => $latlon ];      // exakt gewünschtes Schema
    }
    $json = wp_json_encode( $out );

    /* 6 | DB-Update */
    $pdo  = lsttraining_get_connection();
    $stmt = $pdo->prepare(
        'UPDATE krankenhaeuser
            SET departments = ?
          WHERE id = ?'
    );

    $ok = $stmt->execute( [ $json, $hid ] );

    $ok ? wp_send_json_success()
        : wp_send_json_error( 'Speichern fehlgeschlagen', 500 );
}


/* -------------------------------------------------------------------------
 * Liefert Fachbereiche für ein Krankenhaus
 *  – akzeptiert Legacy- und neues Format in der DB
 *  – gibt IMMER das neue Objekt-Array zurück
 * ---------------------------------------------------------------------- */
add_action( 'wp_ajax_get_departments', 'lsttraining_get_departments' );

function lsttraining_get_departments() {

    /* 1 | Rechteprüfung (Admins oder wer hospitals pflegen darf) */
    if ( ! lsttraining_user_can( 'hospitals' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    /* 2 | Krankenhaus-ID */
    $hid = intval( $_REQUEST['hospital_id'] ?? 0 );
    if ( $hid <= 0 ) {
        wp_send_json_error( 'Ungültige Krankenhaus-ID.', 400 );
    }

    /* 3 | Datenbank holen */
    require_once plugin_dir_path( __FILE__ ) . '/db.php';
    $pdo = lsttraining_get_connection();

    $stmt = $pdo->prepare(
        'SELECT departments, latitude, longitude
           FROM krankenhaeuser
          WHERE id = :hid'
    );
    if ( ! $stmt->execute( [ ':hid' => $hid ] ) ) {
        wp_send_json_error( 'Datenbankfehler.', 500 );
    }
    $row = $stmt->fetch( PDO::FETCH_ASSOC );
    if ( ! $row ) {
        wp_send_json_error( 'Krankenhaus nicht gefunden.', 404 );
    }

    /* 4 | Departments-JSON dekodieren */
    /* 4 | Departments-JSON dekodieren */
$existing = json_decode( $row['departments'], true ) ?: [];

/* ---- Alles in ein einheitliches Array wandeln -------------------- */
$norm = [];

foreach ( $existing as $item ) {

    /* a) Neues Schema  { "CODE": {Lat,Long} } ----------------------- */
    if ( is_array($item) && count($item) === 1 && isset($item[array_key_first($item)]) ) {
        $code = array_key_first($item);
        $lat  = $item[$code]['Lat']  ?? $item[$code]['latitude']  ?? $row['latitude'];
        $lon  = $item[$code]['Long'] ?? $item[$code]['longitude'] ?? $row['longitude'];
        $norm[] = [ 'code' => strtoupper($code), 'latitude' => (float)$lat, 'longitude' => (float)$lon ];
        continue;
    }

    /* b) Legacy-Eintrag  {code, latitude, longitude} --------------- */
    if ( isset($item['code']) ) {
        $norm[] = [
          'code'      => strtoupper($item['code']),
          'latitude'  => (float)($item['latitude']  ?? $row['latitude']),
          'longitude' => (float)($item['longitude'] ?? $row['longitude'])
        ];
        continue;
    }

    /* c) Reiner Code-String ---------------------------------------- */
    if ( is_string($item) ) {
        $norm[] = [
          'code'      => strtoupper($item),
          'latitude'  => (float)$row['latitude'],
          'longitude' => (float)$row['longitude']
        ];
    }
}

$existing = $norm;          // ab hier immer gleiches Format<br>

    /* 5 | Erlaubte Fachbereiche (Codes → Labels) laden */
    $departments = json_decode(
        file_get_contents( plugin_dir_path( __FILE__ ) . 'departments.json' ),
        true
    );

    /* 6 | Antwort zurück */
    wp_send_json_success([
        'hospital_id'  => $hid,
        'existing'     => $existing,               // jetzt immer NEUES Format
        'allowed'      => array_map(
                            fn($d) => $d['label'],
                            $departments
                          ),
        'hospital_lat' => (float) $row['latitude'],
        'hospital_lon' => (float) $row['longitude'],
    ]);
}

/* -------------------------------------------------------------------------
 * 6. LEITSTELLE ↔ HOSPITAL ZUORDNUNG
 * ---------------------------------------------------------------------- */

/**
 * Liefert Hospitals je Leitstelle
 * @action wp_ajax_get_leitstelle_hospitals
 */
add_action( 'wp_ajax_get_leitstelle_hospitals', function () {

    $id = intval( $_GET['leitstelle_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Ungültige Leitstelle' );
    }
    if ( ! lsttraining_user_can( 'leitstellen', $id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $pdo = lsttraining_get_connection();

    try {
        // Leitstelle
        $stmt = $pdo->prepare(
            'SELECT available_hospitals,
                    latitude   AS leitstelle_lat,
                    longitude  AS leitstelle_lon,
                    geojson
               FROM leitstellen
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute( [ ':id' => $id ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! $row ) {
            wp_send_json_error( 'Leitstelle nicht gefunden' );
        }

        $existing = json_decode( $row['available_hospitals'], true ) ?: [];

        // Fallback: alle KH im Polygon
        if ( empty( $existing ) ) {
            $stmt2 = $pdo->prepare(
                'SELECT id
                   FROM krankenhaeuser
                  WHERE ST_Contains(
                          ST_GeomFromText( ST_AsText( ST_GeomFromGeoJSON( :geojson ) ) ),
                          ST_GeomFromText( CONCAT( "POINT(", longitude, " ", latitude, ")" ) )
                        )'
            );
            $stmt2->execute( [ ':geojson' => $row['geojson'] ] );
            $existing = $stmt2->fetchAll( PDO::FETCH_COLUMN );
        }

        // alle KH für Liste
        $stmt3 = $pdo->query(
            'SELECT id, name, latitude, longitude
               FROM krankenhaeuser
              ORDER BY name'
        );
        $hospitals = $stmt3->fetchAll( PDO::FETCH_ASSOC );

        wp_send_json_success( [
            'leitstelle_id'  => $id,
            'existing'       => $existing,
            'hospitals'      => $hospitals,
            'leitstelle_lat' => (float) $row['leitstelle_lat'],
            'leitstelle_lon' => (float) $row['leitstelle_lon'],
            'geojson'        => $row['geojson'],
        ] );

    } catch ( PDOException $e ) {
        wp_send_json_error( 'Datenbankfehler: ' . $e->getMessage() );
    }
});


/**
 * Speichert Hospitals je Leitstelle
 * @action wp_ajax_save_leitstelle_hospitals
 */
add_action( 'wp_ajax_save_leitstelle_hospitals', function () {

    $id = intval( $_POST['leitstelle_id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Ungültige Leitstelle' );
    }
    if ( ! lsttraining_user_can( 'leitstellen', $id ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }

    $items = isset( $_POST['hospitals'] )
        ? json_decode( wp_unslash( $_POST['hospitals'] ), true )
        : [];

    if ( ! is_array( $items ) ) {
        wp_send_json_error( 'Ungültige Daten' );
    }

    $json = wp_json_encode( array_map( 'intval', $items ) );

    $pdo = lsttraining_get_connection();
    try {
        $stmt = $pdo->prepare(
            'UPDATE leitstellen
                SET available_hospitals = :json
              WHERE id = :id'
        );
        $stmt->execute( [
            ':json' => $json,
            ':id'   => $id,
        ] );
        wp_send_json_success();
    } catch ( PDOException $e ) {
        wp_send_json_error( 'Speicherfehler: ' . $e->getMessage() );
    }
});


/* -------------------------------------------------------------------------
 * 7. BENUTZER-RECHTE (nur Admins – global)
 * ---------------------------------------------------------------------- */

/**
 * Alle Rechte abrufen
 * @action wp_ajax_lsttraining_get_user_permissions
 */
add_action( 'wp_ajax_lsttraining_get_user_permissions', function () {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) {
        wp_send_json_error( 'Datenbankverbindung fehlgeschlagen.', 500 );
    }

    $wp_users = get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] );
    $user_ids = wp_list_pluck( $wp_users, 'ID' );
    if ( empty( $user_ids ) ) {
        wp_send_json_success( [] );
    }

    $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '?' ) );
    $sql = "SELECT user_id,
                   can_edit_leitstellen,
                   can_edit_nebenstellen,
                   can_edit_hospitals,
                   can_edit_wachen,
                   can_edit_fahrzeuge,
                   leistellen_ids
              FROM user_permissions
             WHERE user_id IN ($placeholders)";

    $stmt = $pdo->prepare( $sql );
    $stmt->execute( $user_ids );
    $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

    $perms_by_user = [];
    foreach ( $rows as $r ) {
        $perms_by_user[ (int) $r['user_id'] ] = [
            'leitstellen'    => (int) $r['can_edit_leitstellen'],
            'nebenstellen'   => (int) $r['can_edit_nebenstellen'],
            'hospitals'      => (int) $r['can_edit_hospitals'],
            'wachen'         => (int) $r['can_edit_wachen'],
            'fahrzeuge'      => (int) $r['can_edit_fahrzeuge'],
            'leistellen_ids' => $r['leistellen_ids'],
        ];
    }

    $result = [];
    foreach ( $wp_users as $u ) {
        $uid = (int) $u->ID;
        $result[] = [
            'ID'           => $uid,
            'user_login'   => $u->user_login,
            'display_name' => $u->display_name ?: $u->user_login,
            'permissions'  => $perms_by_user[ $uid ] ?? [
                'leitstellen'    => 0,
                'nebenstellen'   => 0,
                'hospitals'      => 0,
                'wachen'         => 0,
                'fahrzeuge'      => 0,
                'leistellen_ids' => '',
            ],
        ];
    }

    wp_send_json_success( $result );
});


/**
 * Rechte speichern / updaten
 * @action wp_ajax_lsttraining_save_user_permissions
 */
add_action( 'wp_ajax_lsttraining_save_user_permissions', function () {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }

    $json = wp_unslash( $_POST['user_permissions'] ?? '' );
    if ( empty( $json ) ) {
        wp_send_json_error( 'Keine Daten übermittelt.', 400 );
    }

    $data = json_decode( $json, true );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( 'Ungültiges JSON-Format.', 400 );
    }

    $pdo = lsttraining_get_connection();
    if ( ! $pdo ) {
        wp_send_json_error( 'Datenbankverbindung fehlgeschlagen.', 500 );
    }

    $stmtCheck  = $pdo->prepare( "SELECT user_id FROM user_permissions WHERE user_id = ?" );
    $stmtInsert = $pdo->prepare(
        'INSERT INTO user_permissions (
            user_id,
            can_edit_leitstellen,
            can_edit_nebenstellen,
            can_edit_hospitals,
            can_edit_wachen,
            can_edit_fahrzeuge,
            leistellen_ids
        ) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtUpdate = $pdo->prepare(
        'UPDATE user_permissions
            SET can_edit_leitstellen  = ?,
                can_edit_nebenstellen = ?,
                can_edit_hospitals    = ?,
                can_edit_wachen       = ?,
                can_edit_fahrzeuge    = ?,
                leistellen_ids        = ?

          WHERE user_id = ?'
    );

    try {
        $pdo->beginTransaction();

        foreach ( $data as $entry ) {
            $user_id          = intval( $entry['user_id'] );
            $can_leitstellen  = isset( $entry['can_edit_leitstellen'] ) ? 1 : 0;
            $can_nebenstellen = isset( $entry['can_edit_nebenstellen'] ) ? 1 : 0;
            $can_hospitals    = isset( $entry['can_edit_hospitals'] ) ? 1 : 0;
            $can_wachen       = isset( $entry['can_edit_wachen'] ) ? 1 : 0;
            $can_fahrzeuge    = isset( $entry['can_edit_fahrzeuge'] ) ? 1 : 0;

            $ids_raw = sanitize_text_field( $entry['leistellen_ids'] ?? '' );
            $ids_arr = array_filter(
                array_map( 'trim', explode( ',', $ids_raw ) ),
                static fn( $v ) => $v !== '' && ctype_digit( $v )
            );
            $leistellen_ids = implode( ',', $ids_arr );

            $stmtCheck->execute( [ $user_id ] );
            $exists = (bool) $stmtCheck->fetchColumn();

            if ( $exists ) {
                $stmtUpdate->execute( [
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leistellen_ids,
                    $user_id
                ] );
            } else {
                $stmtInsert->execute( [
                    $user_id,
                    $can_leitstellen,
                    $can_nebenstellen,
                    $can_hospitals,
                    $can_wachen,
                    $can_fahrzeuge,
                    $leistellen_ids
                ] );
            }
        }

        $pdo->commit();
        wp_send_json_success();
    } catch ( PDOException $e ) {
        $pdo->rollBack();
        wp_send_json_error( 'Datenbank-Fehler: ' . $e->getMessage(), 500 );
    }
});


add_action( 'wp_ajax_lsttraining_copy_leitstelle', 'lsttraining_ajax_copy_leitstelle' );
function lsttraining_ajax_copy_leitstelle() {
    // 1) Capability- & Nonce-Check
    if ( ! lsttraining_user_can( 'nebenstellen' ) ) {
        wp_send_json_error( 'Keine Berechtigung', 403 );
    }
    check_ajax_referer( 'lsttraining_copy_leitstelle', '_wpnonce' );

    // 2) POST-Parameter validieren
    $neben_id = filter_input( INPUT_POST, 'neben_id', FILTER_VALIDATE_INT );
    $leit_id  = filter_input( INPUT_POST, 'leit_id',  FILTER_VALIDATE_INT );
    if ( ! $neben_id || ! $leit_id ) {
        wp_send_json_error( 'Ungültige IDs', 400 );
    }

    try {
        $pdo = lsttraining_get_connection();
        if ( ! $pdo instanceof PDO ) {
            throw new Exception( 'DB-Verbindung fehlgeschlagen' );
        }

        // 3) Pivot-Beziehungen anlegen:
        //    Wachen, die über wache_leitstellen mit dieser Leitstelle verbunden sind
        //    und noch keine Beziehung zur Nebenstelle haben, bekommen eine neue.
        $insert = $pdo->prepare( "
            INSERT INTO wache_nebenleitstellen (wache_id, nebenleitstelle_id)
            SELECT wl.wache_id, :nid
              FROM wache_leitstellen AS wl
             WHERE wl.leitstelle_id = :lid
               AND wl.wache_id NOT IN (
                   SELECT wache_id
                     FROM wache_nebenleitstellen
                    WHERE nebenleitstelle_id = :nid
               )
        " );
        $insert->execute([
            ':nid' => $neben_id,
            ':lid' => $leit_id,
        ] );

        // 4) Geo-Daten der Leitstelle laden
        $stmt = $pdo->prepare( "
            SELECT latitude, longitude, geojson
              FROM leitstellen
             WHERE id = :lid
             LIMIT 1
        " );
        $stmt->execute([ ':lid' => $leit_id ]);
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! $row ) {
            throw new Exception( 'Leitstelle nicht gefunden (ID ' . $leit_id . ')' );
        }

        // 5) Existenz der Nebenleitstelle prüfen
        $stmtChk = $pdo->prepare(
            "SELECT 1
               FROM nebenleitstellen
              WHERE id = :nid
              LIMIT 1"
        );
        $stmtChk->execute([ ':nid' => $neben_id ]);
        if ( ! $stmtChk->fetchColumn() ) {
            throw new Exception( 'Nebenleitstelle nicht gefunden (ID ' . $neben_id . ')' );
        }

        // 6) Nebenleitstellen-Tabelle updaten:
        //    gps = "lat, lng", geojson = kopierter GeoJSON-String
        $gps = $row['latitude'] . ', ' . $row['longitude'];
        $upd = $pdo->prepare( "
            UPDATE nebenleitstellen
               SET gps     = :gps,
                   geojson = :geo
             WHERE id      = :nid
        " );
        $upd->execute([
            ':gps' => $gps,
            ':geo' => $row['geojson'],
            ':nid' => $neben_id,
        ] );

        // 7) Erfolg zurückgeben
        wp_send_json_success( 'Nebenstelle erfolgreich kopiert' );
    }
    catch ( Exception $e ) {
        // Fehler ins Log, generische Meldung an Client
        error_log( 'lsttraining_copy_leitstelle ERROR: ' . $e->getMessage() );
        wp_send_json_error( 'Server-Fehler beim Übernehmen', 500 );
    }
}


