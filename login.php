<?php
session_start();
header('Content-Type: application/json');

require_once 'db_connect.php';

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Username and password are required'
    ]);
    exit;
}

$username = $data['username'];
$password = $data['password'];

try {
    $conn = openDatabaseConnection();

    // Get user data
    $stmt = $conn->prepare('SELECT id, username, password, role, auth_token FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username or password'
        ]);
        exit;
    }

    // Verify password using password_verify
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username or password'
        ]);
        exit;
    }

    // Generate new auth token
    $auth_token = bin2hex(random_bytes(32));

    // Update user's auth token in database
    $stmt = $conn->prepare('UPDATE users SET auth_token = ? WHERE id = ?');
    $stmt->bind_param('si', $auth_token, $user['id']);
    $stmt->execute();

    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['auth_token'] = $auth_token;

    // Return success with user data
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'auth_token' => $auth_token
        ]
    ]);

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Login failed',
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