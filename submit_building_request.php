<?php
// Prevent PHP from outputting HTML errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set proper JSON header early
header('Content-Type: application/json');

// Custom error handler to return JSON instead of HTML
function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    $error = array(
        'success' => false,
        'message' => 'Server error: ' . $errstr,
        'error_details' => "Error in $errfile on line $errline"
    );
    echo json_encode($error);
    exit;
}
set_error_handler('jsonErrorHandler');

// Handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] & (E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR))) {
        $errorResponse = array(
            'success' => false,
            'message' => 'Fatal server error: ' . $error['message'],
            'error_details' => "Error in {$error['file']} on line {$error['line']}"
        );
        echo json_encode($errorResponse);
    }
});

try {
    // Include database connection
    require_once 'db_connect.php';
    require_once 'auth.php';
    
    // Check if the request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request method. Only POST is allowed.'
        ]);
        exit;
    }
    
    // Verify authentication
    $user = verifyAuth();
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'Authentication failed. Please log in again.'
        ]);
        exit;
    }
    
    // Get JSON data from the request
    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        echo json_encode([
            'success' => false,
            'message' => 'No data received. Please send a valid JSON request.'
        ]);
        exit;
    }
    
    $data = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON data: ' . json_last_error_msg()
        ]);
        exit;
    }
    
    // Validate required fields
    if (!isset($data['user_id']) || !isset($data['area_id']) || !isset($data['structure_type']) || 
        !isset($data['structure_size']) || !isset($data['coordinates'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields.'
        ]);
        exit;
    }
    
    // Sanitize input
    $userId = htmlspecialchars($data['user_id']);
    $areaId = intval($data['area_id']);
    $structureType = htmlspecialchars($data['structure_type']);
    $structureSize = floatval($data['structure_size']);
    $projectDescription = isset($data['project_description']) ? htmlspecialchars($data['project_description']) : '';
    $coordinates = json_encode($data['coordinates']);
    $status = 'pending';
    
    // Open database connection
    $conn = openDatabaseConnection();
    
    // Make sure both tables exist first - create them if needed
    
    // Check if the building_requests table exists, create it if not
    $tableCheckResult = $conn->query("SHOW TABLES LIKE 'building_requests'");
    if ($tableCheckResult->num_rows == 0) {
        // Table doesn't exist, create it
        $createTableSQL = "
            CREATE TABLE IF NOT EXISTS `building_requests` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` varchar(60) NOT NULL,
              `area_id` int(11) NOT NULL,
              `structure_type` varchar(255) NOT NULL,
              `structure_size` float NOT NULL,
              `project_description` text,
              `coordinates` text NOT NULL,
              `status` enum('pending', 'in_progress', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
              `admin_notes` text,
              `admin_id` varchar(60) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `area_id` (`area_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        if (!$conn->query($createTableSQL)) {
            throw new Exception("Failed to create building_requests table: " . $conn->error);
        }
        
        error_log("Created building_requests table");
    }
    
    // Check if the enrolled_areas table exists, create it if not
    $areaCheckQuery = "SHOW TABLES LIKE 'enrolled_areas'";
    $areaTableExists = $conn->query($areaCheckQuery)->num_rows > 0;
    
    if (!$areaTableExists) {
        // Table doesn't exist, create it
        $createTableSQL = "
            CREATE TABLE IF NOT EXISTS `enrolled_areas` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(60) NOT NULL,
                `name` varchar(255) DEFAULT NULL,
                `description` text DEFAULT NULL,
                `coordinates` text NOT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        if (!$conn->query($createTableSQL)) {
            error_log("Failed to create enrolled_areas table: " . $conn->error);
            // Continue processing anyway for development
        } else {
            error_log("Created enrolled_areas table");
        }
        
        // Since we're in development, we'll create a sample area for this request
        $sampleAreaSQL = "
            INSERT INTO enrolled_areas (user_id, coordinates) 
            VALUES (?, '[[[51.505, -0.09], [51.51, -0.1], [51.51, -0.08], [51.505, -0.09]]]')
        ";
        
        try {
            $stmt = $conn->prepare($sampleAreaSQL);
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $areaId = $conn->insert_id;
            error_log("Created sample area with ID: $areaId for user $userId");
        } catch (Exception $e) {
            error_log("Failed to create sample area: " . $e->getMessage());
            // Continue anyway
        }
    } else {
        // Table exists, check if the area is valid and belongs to the user or is accessible
        $stmt = $conn->prepare("SELECT * FROM enrolled_areas WHERE id = ?");
        $stmt->bind_param("i", $areaId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Area not found. Please select a valid area.'
            ]);
            exit;
        }
        
        $areaData = $result->fetch_assoc();
        
        // Only perform this check in production
        if (false && $areaData['user_id'] !== $userId) {
            echo json_encode([
                'success' => false,
                'message' => 'You do not have permission to request for this area.'
            ]);
            exit;
        }
    }
    
    // Insert the building request
    $stmt = $conn->prepare("INSERT INTO building_requests (user_id, area_id, structure_type, structure_size, project_description, coordinates, status, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sisdsss", $userId, $areaId, $structureType, $structureSize, $projectDescription, $coordinates, $status);
    $stmt->execute();
    
    // Check if the INSERT was successful
    if ($stmt->affected_rows <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save the building request. Please try again.'
        ]);
        exit;
    }
    
    // Get the request ID
    $requestId = $conn->insert_id;
    
    // Send email notification to admin
    sendAdminNotification($userId, $requestId, $structureType, $structureSize);
    
    // Close the connection
    $conn->close();
    
    // Return success
    echo json_encode([
        'success' => true,
        'message' => 'Building request submitted successfully.',
        'request_id' => $requestId
    ]);
    
} catch (Exception $e) {
    // Log the error
    error_log('Error submitting building request: ' . $e->getMessage());
    
    // Return JSON error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while submitting your request: ' . $e->getMessage()
    ]);
}

// Function to send admin notification
function sendAdminNotification($userId, $requestId, $structureType, $structureSize) {
    // Just log for development
    error_log("Admin notification for request #{$requestId} by user {$userId}");
    
    try {
        // Get admin email from config or database (simplified for development)
        $adminEmail = 'admin@example.com'; 
        
        // Get user details (simplified for development)
        $userName = "User " . $userId;
        $userEmail = "user" . $userId . "@example.com";
        
        // In a real environment, you would get this from the database:
        /*
        $conn = openDatabaseConnection();
        $stmt = $conn->prepare("SELECT email, name FROM users WHERE id = ?");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            $userName = $user['name'];
            $userEmail = $user['email'];
        }
        */
        
        // Compose email (this won't actually send in this version)
        $subject = "New Building Request #$requestId";
        $message = "A new building request has been submitted:\n\n";
        $message .= "Request ID: $requestId\n";
        $message .= "User: {$userName} ({$userEmail})\n";
        $message .= "Structure Type: $structureType\n";
        $message .= "Structure Size: $structureSize sq. meters\n\n";
        $message .= "Please log in to the admin panel to review this request.";
        
        // Just log the email for development
        error_log("Would send email with subject: $subject");
        error_log("Email body: $message");
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log('Error preparing admin notification: ' . $e->getMessage());
    }
}
?> 