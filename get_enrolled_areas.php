<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-ID');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connect.php';

// Get authentication headers
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
$userId = isset($headers['X-User-ID']) ? $headers['X-User-ID'] : null;

// Log incoming request data
error_log("Received request for enrolled areas. User ID: " . $userId);
error_log("Auth header present: " . (!empty($authHeader) ? 'yes' : 'no'));

// Basic validation
if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized - Missing or invalid authentication token'
    ]);
    exit;
}

$token = $matches[1];

// Validate user ID
if (empty($userId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'User ID is required'
    ]);
    exit;
}

try {
    // Connect to the database
    $conn = openDatabaseConnection();
    
    // Check if enrolled_areas table exists
    $result = $conn->query("SHOW TABLES LIKE 'enrolled_areas'");
    if ($result->num_rows === 0) {
        // Create enrolled_areas table if it doesn't exist
        $sql = "CREATE TABLE IF NOT EXISTS `enrolled_areas` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` varchar(60) NOT NULL,
            `name` varchar(255) DEFAULT NULL,
            `description` text DEFAULT NULL,
            `coordinates` text NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($sql);
        error_log("Created enrolled_areas table");
    }

    // Verify token in the database
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND auth_token = ?");
    $stmt->bind_param("ss", $userId, $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        error_log("Invalid token for user: " . $userId);
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized - Invalid token'
        ]);
        exit;
    }

    // Get all enrolled areas for the user
    $stmt = $conn->prepare("SELECT id, coordinates, created_at FROM enrolled_areas WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $areas = [];
    
    while ($row = $result->fetch_assoc()) {
        $areas[] = [
            'id' => $row['id'],
            'coordinates' => json_decode($row['coordinates'], true),
            'created_at' => $row['created_at']
        ];
    }

    error_log("Found " . count($areas) . " areas for user " . $userId);
    
    // Always return a success response with data array
    echo json_encode([
        'success' => true,
        'data' => $areas
    ]);

} catch (Exception $e) {
    error_log("Error in get_enrolled_areas.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load areas',
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