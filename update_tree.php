<?php
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'treemap');

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['id']) && isset($data['status'])) {
    $stmt = $conn->prepare('UPDATE trees SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $data['status'], $data['id']);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}

$conn->close();
?>