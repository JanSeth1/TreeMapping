<?php
session_start();
require_once 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['coordinates']) || empty($data['coordinates'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No coordinates provided']);
    exit;
}

try {
    // Create enrolled_areas table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS enrolled_areas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        coordinates JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )";
    $conn->query($sql);

    // Insert the new area
    $stmt = $conn->prepare("INSERT INTO enrolled_areas (user_id, coordinates) VALUES (?, ?)");
    $userId = $_SESSION['user_id'];
    $coordinates = json_encode($data['coordinates']);
    $stmt->bind_param("is", $userId, $coordinates);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Area saved successfully']);
    } else {
        throw new Exception("Error executing query: " . $stmt->error);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?> 