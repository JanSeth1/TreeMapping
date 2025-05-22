<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['username']) || !isset($data['password']) || !isset($data['email']) || !isset($data['name'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields. Username, password, email, and name are required.'
    ]);
    exit;
}

try {
    $conn = openDatabaseConnection();

    // Check if username already exists
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $data['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Username already exists'
        ]);
        exit;
    }

    // Hash password
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $conn->prepare('INSERT INTO users (username, password, name, email, role) VALUES (?, ?, ?, ?, ?)');
    $role = isset($data['role']) ? $data['role'] : 'user';
    $stmt->bind_param('sssss', $data['username'], $hashedPassword, $data['name'], $data['email'], $role);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'User registered successfully',
            'user_id' => $conn->insert_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed: ' . $stmt->error
        ]);
    }

} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Registration failed',
        'details' => $e->getMessage()
    ]);
}

if (isset($stmt)) {
    $stmt->close();
}
if (isset($conn)) {
    $conn->close();
}
?>