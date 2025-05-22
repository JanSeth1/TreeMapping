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
    
    // Verify authentication
    $user = verifyAuth();
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'Authentication failed. Please log in again.'
        ]);
        exit;
    }
    
    // Get user ID from the request header
    $headers = getallheaders();
    $userId = isset($headers['X-User-ID']) ? $headers['X-User-ID'] : null;
    
    if (!$userId) {
        echo json_encode([
            'success' => false,
            'message' => 'User ID is required.'
        ]);
        exit;
    }
    
    // Open database connection
    $conn = openDatabaseConnection();
    
    // Check if building_requests table exists
    $tableCheckResult = $conn->query("SHOW TABLES LIKE 'building_requests'");
    if ($tableCheckResult->num_rows == 0) {
        // Table doesn't exist yet, return empty list
        echo json_encode([
            'success' => true,
            'message' => 'No requests found (table does not exist yet).',
            'requests' => []
        ]);
        $conn->close();
        exit;
    }
    
    // Get the specific request if ID is provided
    if (isset($_GET['id'])) {
        $requestId = intval($_GET['id']);
        
        // Check if enrolled_areas table exists
        $areaTableExists = $conn->query("SHOW TABLES LIKE 'enrolled_areas'")->num_rows > 0;
        
        if ($areaTableExists) {
            // Query for a specific request with area data
            $stmt = $conn->prepare("
                SELECT br.*, ea.coordinates as area_coordinates 
                FROM building_requests br
                JOIN enrolled_areas ea ON br.area_id = ea.id
                WHERE br.id = ? AND br.user_id = ?
            ");
        } else {
            // Query for a specific request without area data
            $stmt = $conn->prepare("
                SELECT br.*, NULL as area_coordinates 
                FROM building_requests br
                WHERE br.id = ? AND br.user_id = ?
            ");
        }
        
        $stmt->bind_param("is", $requestId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Request not found or you do not have permission to access it.'
            ]);
            $conn->close();
            exit;
        }
        
        $request = $result->fetch_assoc();
        
        // Decode coordinates
        $request['coordinates'] = json_decode($request['coordinates']);
        if ($request['area_coordinates']) {
            $request['area_coordinates'] = json_decode($request['area_coordinates']);
        } else {
            // Provide a placeholder for development
            $request['area_coordinates'] = [
                [0, 0],
                [0, 0.001],
                [0.001, 0.001],
                [0.001, 0]
            ];
        }
        
        echo json_encode([
            'success' => true,
            'request' => $request
        ]);
    } else {
        // Check if enrolled_areas table exists
        $areaTableExists = $conn->query("SHOW TABLES LIKE 'enrolled_areas'")->num_rows > 0;
        
        if ($areaTableExists) {
            // Query for all requests by the user with area data
            $stmt = $conn->prepare("
                SELECT br.*, ea.coordinates as area_coordinates 
                FROM building_requests br
                JOIN enrolled_areas ea ON br.area_id = ea.id
                WHERE br.user_id = ?
                ORDER BY br.created_at DESC
            ");
        } else {
            // Query for all requests by the user without area data
            $stmt = $conn->prepare("
                SELECT br.*, NULL as area_coordinates 
                FROM building_requests br
                WHERE br.user_id = ?
                ORDER BY br.created_at DESC
            ");
        }
        
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $requests = [];
        while ($row = $result->fetch_assoc()) {
            // Decode coordinates
            $row['coordinates'] = json_decode($row['coordinates']);
            if ($row['area_coordinates']) {
                $row['area_coordinates'] = json_decode($row['area_coordinates']);
            } else {
                // Provide a placeholder for development
                $row['area_coordinates'] = [
                    [0, 0],
                    [0, 0.001],
                    [0.001, 0.001],
                    [0.001, 0]
                ];
            }
            $requests[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'requests' => $requests
        ]);
    }
    
    // Close the connection
    $conn->close();
    
} catch (Exception $e) {
    // Log the error
    error_log('Error retrieving building requests: ' . $e->getMessage());
    
    // Return JSON error
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while retrieving your requests: ' . $e->getMessage()
    ]);
}
?> 