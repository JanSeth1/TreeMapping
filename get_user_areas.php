<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connect.php';

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['error' => 'User not authenticated']);
    exit;
}

try {
    $sql = "SELECT * FROM monitoring_areas WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $areas = [];
    while ($row = $result->fetch_assoc()) {
        $areas[] = [
            'id' => $row['id'],
            'coordinates' => json_decode($row['coordinates']),
            'bounds' => json_decode($row['bounds'])
        ];
    }

    echo json_encode($areas);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}