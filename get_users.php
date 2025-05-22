<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-ID');

require_once 'db_connect.php';

try {
    // Get authentication headers
    $headers = getallheaders();
    $userId = isset($headers['X-User-ID']) ? $headers['X-User-ID'] : null;
    
    // Open database connection
    $conn = openDatabaseConnection();
    
    // Verify user is admin
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized - Admin access required'
        ]);
        exit;
    }
    
    // Get all users
    $stmt = $conn->prepare("SELECT id, username, email, role, created_at FROM users");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = array();
    while ($row = $result->fetch_assoc()) {
        $users[] = array(
            'id' => $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'role' => $row['role'],
            'status' => 'active', // Default status since we don't have a status field
            'created_at' => $row['created_at']
        );
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $users
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_users.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch users: ' . $e->getMessage()
    ]);
}

if (isset($stmt)) {
    $stmt->close();
}
if (isset($conn)) {
    $conn->close();
}
?> 