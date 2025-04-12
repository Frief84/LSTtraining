<?php
header('Content-Type: application/json');

require_once('../../../wp-load.php');
require_once plugin_dir_path(__FILE__) . '/includes/db.php';

$conn = lsttraining_get_connection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Keine Datenbankverbindung']);
    exit;
}

try {
    $stmt = $conn->query("SELECT id, name, typ, latitude, longitude, bild_datei FROM wachen WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
    $wachen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($wachen);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
