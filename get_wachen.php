<?php
// get-wachen.php

header('Content-Type: application/json');
require_once __DIR__ . '/../../../wp-load.php';
require_once plugin_dir_path(__FILE__) . '/includes/db.php';

$pdo = lsttraining_get_connection();
if ( ! $pdo instanceof \PDO ) {
    http_response_code(500);
    echo json_encode([ 'error' => 'Keine Datenbankverbindung' ]);
    exit;
}

// IDs aus GET auslesen (oder null, wenn nicht gesetzt)
$leitId    = filter_input(INPUT_GET,  'leitstelle_id',        FILTER_VALIDATE_INT);
$nebenlId  = filter_input(INPUT_GET,  'nebenleitstelle_id',   FILTER_VALIDATE_INT);

try {
    if ( $leitId ) {
        // nur Wachen, die zur Leitstelle gehören
        $sql  = "
          SELECT w.id, w.name, w.typ, w.latitude, w.longitude, w.bild_datei
            FROM wachen AS w
       LEFT JOIN wache_leitstellen AS wl ON w.id = wl.wache_id
           WHERE wl.leitstelle_id = :lid
             AND w.latitude  IS NOT NULL
             AND w.longitude IS NOT NULL
        ";
        $stmt = $pdo->prepare( $sql );
        $stmt->execute([ ':lid' => $leitId ]);

    } elseif ( $nebenlId ) {
        // nur Wachen, die zur Nebenleitstelle gehören
        $sql  = "
          SELECT w.id, w.name, w.typ, w.latitude, w.longitude, w.bild_datei
            FROM wachen AS w
       LEFT JOIN wache_nebenleitstellen AS wn ON w.id = wn.wache_id
           WHERE wn.nebenleitstelle_id = :nlid
             AND w.latitude  IS NOT NULL
             AND w.longitude IS NOT NULL
        ";
        $stmt = $pdo->prepare( $sql );
        $stmt->execute([ ':nlid' => $nebenlId ]);

    } else {
        // alle Wachen mit gültigen Koordinaten
        $stmt = $pdo->query("
          SELECT id, name, typ, latitude, longitude, bild_datei
            FROM wachen
           WHERE latitude  IS NOT NULL
             AND longitude IS NOT NULL
        ");
    }

    $wachen = $stmt->fetchAll( PDO::FETCH_ASSOC );
    echo json_encode( $wachen );

} catch ( PDOException $e ) {
    http_response_code(500);
    error_log( 'get-wachen.php ERROR: ' . $e->getMessage() );
    echo json_encode([ 'error' => 'Datenbankfehler' ]);
}
