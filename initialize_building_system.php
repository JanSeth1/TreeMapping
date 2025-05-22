<?php
// Set proper JSON header early
header('Content-Type: application/json');

// Prevent direct access via browser
if (isset($_SERVER['HTTP_USER_AGENT']) && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    echo json_encode([
        'success' => false,
        'message' => 'This script must be called via AJAX.'
    ]);
    exit;
}

// Include database connection
require_once 'db_connect.php';

// Function to log messages both to error log and to the response
function logMessage($message, &$logs) {
    error_log($message);
    $logs[] = $message;
}

try {
    // Store logs for response
    $logs = [];
    $errors = [];
    $tables_created = [];
    $tables_modified = [];
    
    // Open database connection
    $conn = openDatabaseConnection();
    logMessage("Connected to database successfully", $logs);
    
    // Check if the enrolled_areas table exists
    $tableCheckResult = $conn->query("SHOW TABLES LIKE 'enrolled_areas'");
    if ($tableCheckResult->num_rows == 0) {
        // Table doesn't exist, create it
        logMessage("Creating enrolled_areas table...", $logs);
        
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
        
        $tables_created[] = 'enrolled_areas';
        logMessage("Enrolled areas table created successfully", $logs);
        
        // Create a sample area for testing
        $sampleAreaSQL = "
            INSERT INTO enrolled_areas (user_id, name, coordinates) 
            VALUES 
            ('test123', 'Test Area 1', '[[[51.505, -0.09], [51.51, -0.1], [51.51, -0.08], [51.505, -0.09]]]'),
            ('admin', 'Admin Area', '[[[51.51, -0.12], [51.52, -0.13], [51.52, -0.11], [51.51, -0.12]]]')
        ";
        
        if (!$conn->query($sampleAreaSQL)) {
            logMessage("Warning: Failed to insert sample areas: " . $conn->error, $logs);
        } else {
            logMessage("Added sample areas for testing", $logs);
        }
    } else {
        logMessage("Enrolled areas table already exists, checking structure...", $logs);
        
        // Check and modify the table structure if needed
        $alterTableAttempts = [];
        
        // Check if user_id is a varchar
        $userIdCheckResult = $conn->query("SHOW COLUMNS FROM enrolled_areas WHERE Field = 'user_id'");
        if ($userIdCheckResult->num_rows > 0) {
            $userIdColumn = $userIdCheckResult->fetch_assoc();
            if (strpos($userIdColumn['Type'], 'int') !== false) {
                logMessage("Converting user_id from integer to varchar...", $logs);
                // Need to modify the column type
                $alterUserIdSQL = "ALTER TABLE `enrolled_areas` MODIFY `user_id` varchar(60) NOT NULL";
                if (!$conn->query($alterUserIdSQL)) {
                    $errors[] = "Failed to alter user_id column: " . $conn->error;
                } else {
                    $tables_modified[] = 'enrolled_areas (user_id)';
                }
            }
        }
        
        // Check if coordinates is stored as JSON or TEXT
        $coordsCheckResult = $conn->query("SHOW COLUMNS FROM enrolled_areas WHERE Field = 'coordinates'");
        if ($coordsCheckResult->num_rows > 0) {
            $coordsColumn = $coordsCheckResult->fetch_assoc();
            if (strpos(strtolower($coordsColumn['Type']), 'json') !== false) {
                logMessage("Converting coordinates from JSON to TEXT...", $logs);
                // Need to convert from JSON to TEXT
                $alterCoordsSQL = "ALTER TABLE `enrolled_areas` MODIFY `coordinates` text NOT NULL";
                if (!$conn->query($alterCoordsSQL)) {
                    $errors[] = "Failed to alter coordinates column: " . $conn->error;
                } else {
                    $tables_modified[] = 'enrolled_areas (coordinates)';
                }
            }
        }
    }
    
    // Now check if the building_requests table exists and create it if not
    $buildingRequestsCheckResult = $conn->query("SHOW TABLES LIKE 'building_requests'");
    if ($buildingRequestsCheckResult->num_rows == 0) {
        // Table doesn't exist, create it
        logMessage("Creating building_requests table...", $logs);
        
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
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `area_id` (`area_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        if (!$conn->query($createBuildingRequestsSQL)) {
            throw new Exception("Failed to create building_requests table: " . $conn->error);
        }
        
        $tables_created[] = 'building_requests';
        logMessage("Building requests table created successfully", $logs);
    } else {
        logMessage("Building requests table already exists, checking structure...", $logs);
        
        // Check if the timestamps have DEFAULT values
        $createdCheckResult = $conn->query("SHOW COLUMNS FROM building_requests WHERE Field = 'created_at'");
        if ($createdCheckResult->num_rows > 0) {
            $createdColumn = $createdCheckResult->fetch_assoc();
            if (empty($createdColumn['Default'])) {
                logMessage("Adding default value for created_at...", $logs);
                $alterCreatedSQL = "ALTER TABLE `building_requests` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP";
                if (!$conn->query($alterCreatedSQL)) {
                    $errors[] = "Failed to alter created_at column: " . $conn->error;
                } else {
                    $tables_modified[] = 'building_requests (created_at)';
                }
            }
        }
        
        $updatedCheckResult = $conn->query("SHOW COLUMNS FROM building_requests WHERE Field = 'updated_at'");
        if ($updatedCheckResult->num_rows > 0) {
            $updatedColumn = $updatedCheckResult->fetch_assoc();
            if (empty($updatedColumn['Default']) || strpos($updatedColumn['Extra'], 'on update') === false) {
                logMessage("Adding ON UPDATE for updated_at...", $logs);
                $alterUpdatedSQL = "ALTER TABLE `building_requests` MODIFY `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP";
                if (!$conn->query($alterUpdatedSQL)) {
                    $errors[] = "Failed to alter updated_at column: " . $conn->error;
                } else {
                    $tables_modified[] = 'building_requests (updated_at)';
                }
            }
        }
    }
    
    // Check if we need to create or update the users table
    $usersCheckResult = $conn->query("SHOW TABLES LIKE 'users'");
    if ($usersCheckResult->num_rows == 0) {
        // Table doesn't exist, create a simplified one for development
        logMessage("Creating simplified users table for development...", $logs);
        
        $createUsersSQL = "
            CREATE TABLE IF NOT EXISTS `users` (
              `id` varchar(60) NOT NULL,
              `name` varchar(255) NOT NULL,
              `email` varchar(255) NOT NULL,
              `role` enum('user','mapper','admin') NOT NULL DEFAULT 'user',
              `auth_token` varchar(255) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        if (!$conn->query($createUsersSQL)) {
            logMessage("Warning: Failed to create users table: " . $conn->error, $logs);
        } else {
            $tables_created[] = 'users';
            logMessage("Created simplified users table", $logs);
            
            // Insert sample users
            $sampleUsersSQL = "
                INSERT INTO users (id, name, email, role, auth_token) VALUES 
                ('test123', 'Test User', 'test@example.com', 'user', 'test123token'),
                ('admin', 'Admin User', 'admin@example.com', 'admin', 'admintoken')
            ";
            
            if (!$conn->query($sampleUsersSQL)) {
                logMessage("Warning: Failed to insert sample users: " . $conn->error, $logs);
            } else {
                logMessage("Added sample users for testing", $logs);
            }
        }
    } else {
        logMessage("Users table already exists", $logs);
    }
    
    // Check table counts
    $areasCount = $conn->query("SELECT COUNT(*) as count FROM enrolled_areas")->fetch_assoc()['count'];
    $requestsCount = $conn->query("SELECT COUNT(*) as count FROM building_requests")->fetch_assoc()['count'];
    $usersCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    
    logMessage("Current data counts: Areas: $areasCount, Requests: $requestsCount, Users: $usersCount", $logs);
    
    // Close the connection
    $conn->close();
    
    // Return success with details
    echo json_encode([
        'success' => true,
        'message' => 'Database initialization complete',
        'tables_created' => $tables_created,
        'tables_modified' => $tables_modified,
        'data_counts' => [
            'areas' => $areasCount,
            'requests' => $requestsCount,
            'users' => $usersCount
        ],
        'logs' => $logs,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    // Log the error
    error_log('Error setting up tables: ' . $e->getMessage());
    
    // Return JSON error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while setting up tables: ' . $e->getMessage(),
        'logs' => $logs,
        'errors' => array_merge($errors, [$e->getMessage()])
    ]);
}
?> 