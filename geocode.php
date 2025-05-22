<?php
header('Content-Type: application/json');
$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;

if ($lat && $lng) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lng";
    $response = file_get_contents($url);
    echo $response;
} else {
    echo json_encode(['error' => 'Missing coordinates']);
}
?>