<?php
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

try {
    // Include database connection
    require_once 'db_connect.php';
    
    // Open database connection
    $conn = openDatabaseConnection();
    
    // Check if the enrolled_areas table exists
    $tableCheckResult = $conn->query("SHOW TABLES LIKE 'enrolled_areas'");
    if ($tableCheckResult->num_rows == 0) {
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
            throw new Exception("Failed to create enrolled_areas table: " . $conn->error);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Enrolled areas table created successfully'
        ]);
    } else {
        // Table exists, check if it has the required structure
        $alterTableAttempts = [];
        
        // Check if user_id is a varchar
        $userIdCheckResult = $conn->query("SHOW COLUMNS FROM enrolled_areas WHERE Field = 'user_id'");
        if ($userIdCheckResult->num_rows > 0) {
            $userIdColumn = $userIdCheckResult->fetch_assoc();
            if (strpos($userIdColumn['Type'], 'int') !== false) {
                // Need to modify the column type
                $alterUserIdSQL = "ALTER TABLE `enrolled_areas` MODIFY `user_id` varchar(60) NOT NULL";
                if (!$conn->query($alterUserIdSQL)) {
                    $alterTableAttempts[] = "Failed to alter user_id column: " . $conn->error;
                }
            }
        }
        
        // Check if coordinates is stored as JSON or TEXT
        $coordsCheckResult = $conn->query("SHOW COLUMNS FROM enrolled_areas WHERE Field = 'coordinates'");
        if ($coordsCheckResult->num_rows > 0) {
            $coordsColumn = $coordsCheckResult->fetch_assoc();
            if (strpos(strtolower($coordsColumn['Type']), 'json') !== false) {
                // Need to convert from JSON to TEXT
                $alterCoordsSQL = "ALTER TABLE `enrolled_areas` MODIFY `coordinates` text NOT NULL";
                if (!$conn->query($alterCoordsSQL)) {
                    $alterTableAttempts[] = "Failed to alter coordinates column: " . $conn->error;
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Enrolled areas table verified',
            'alter_attempts' => $alterTableAttempts
        ]);
    }
    
    // Now check if the building_requests table exists and create it if not
    $buildingRequestsCheckResult = $conn->query("SHOW TABLES LIKE 'building_requests'");
    if ($buildingRequestsCheckResult->num_rows == 0) {
        // Table doesn't exist, create it
        $createBuildingRequestsSQL = "
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
              `created_at` datetime NOT NULL,
              `updated_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `area_id` (`area_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        if (!$conn->query($createBuildingRequestsSQL)) {
            throw new Exception("Failed to create building_requests table: " . $conn->error);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Building requests table created successfully'
        ]);
    }
    
    // Close the connection
    $conn->close();
    
} catch (Exception $e) {
    // Log the error
    error_log('Error setting up tables: ' . $e->getMessage());
    
    // Return JSON error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while setting up tables: ' . $e->getMessage()
    ]);
}
?> 