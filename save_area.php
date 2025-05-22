<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connect.php';

// Get the JSON data from the request
$data = json_decode(file_get_contents('php://input'), true);
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

try {
    // Convert coordinates to JSON string for storage
    $coordinates_json = json_encode($data['coordinates']);
    $bounds_json = json_encode($data['bounds']);

    // Prepare and execute the SQL statement
    $sql = "INSERT INTO monitoring_areas (id, user_id, coordinates, bounds, created_at) 
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $data['id'], $user_id, $coordinates_json, $bounds_json);
    
    $success = $stmt->execute();
    
    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}