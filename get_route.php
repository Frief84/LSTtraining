<?php
header('Content-Type: application/json');

// Nur POST erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// JSON-Daten lesen
$body = json_decode(file_get_contents('php://input'), true);
$coordinates = $body['coordinates'] ?? [];

if (count($coordinates) !== 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Koordinaten']);
    exit;
}

// WordPress laden (damit get_option funktioniert)
require_once('../../../wp-load.php');

// API-Key aus Plugin-Einstellungen
$apiKey = get_option('lsttraining_ors_key', '');

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'API-Key nicht gesetzt']);
    exit;
}

// Anfrage an ORS vorbereiten
$url = 'https://api.openrouteservice.org/v2/directions/driving-car/geojson';
$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => [
            "Authorization: $apiKey",
            "Content-Type: application/json"
        ],
        'content' => json_encode(['coordinates' => $coordinates])
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

// Fehler abfangen
if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Routing-Anfrage fehlgeschlagen']);
    exit;
}

// Antwort weitergeben
echo $response;
?>
