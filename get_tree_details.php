<?php
header('Content-Type: application/json');
$conn = new mysqli('localhost', 'root', '', 'treemap');

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$id = $_GET['id'] ?? null;

if ($id) {
    $stmt = $conn->prepare('SELECT * FROM trees WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $tree = $result->fetch_assoc();
        echo json_encode($tree);
    } else {
        echo json_encode(['error' => true, 'message' => 'Tree not found']);
    }
} else {
    echo json_encode(['error' => true, 'message' => 'Invalid request']);
}

$conn->close();
?>