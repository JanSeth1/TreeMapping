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
    
    // Check if admin
    $isAdmin = ($user['role'] === 'admin');
    
    // Get request ID from query parameter
    if (!isset($_GET['id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Request ID is required.'
        ]);
        exit;
    }
    
    $requestId = intval($_GET['id']);
    
    // Get user ID from header (for regular users)
    $headers = getallheaders();
    $userId = isset($headers['X-User-ID']) ? $headers['X-User-ID'] : null;
    
    // Connect to database
    $conn = openDatabaseConnection();
    
    // Build query based on user role
    if ($isAdmin) {
        $query = "
            SELECT br.*, 
                   ea.coordinates as area_coordinates,
                   u.name as user_name,
                   u.email as user_email,
                   admin.name as admin_name
            FROM building_requests br
            JOIN enrolled_areas ea ON br.area_id = ea.id
            JOIN users u ON br.user_id = u.id
            LEFT JOIN users admin ON br.admin_id = admin.id
            WHERE br.id = ?
        ";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database query preparation failed: " . $conn->error);
        }
        $stmt->bind_param("i", $requestId);
    } else {
        // Regular users can only view their own requests
        $query = "
            SELECT br.*, 
                   ea.coordinates as area_coordinates,
                   u.name as user_name,
                   u.email as user_email,
                   admin.name as admin_name
            FROM building_requests br
            JOIN enrolled_areas ea ON br.area_id = ea.id
            JOIN users u ON br.user_id = u.id
            LEFT JOIN users admin ON br.admin_id = admin.id
            WHERE br.id = ? AND br.user_id = ?
        ";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Database query preparation failed: " . $conn->error);
        }
        $stmt->bind_param("is", $requestId, $userId);
    }
    
    // Execute query
    $result = $stmt->execute();
    if (!$result) {
        throw new Exception("Database query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Request not found or you do not have permission to view it.'
        ]);
        exit;
    }
    
    // Fetch request data
    $request = $result->fetch_assoc();
    
    // Decode coordinates
    $request['coordinates'] = json_decode($request['coordinates']);
    $request['area_coordinates'] = json_decode($request['area_coordinates']);
    
    // If admin, also fetch affected trees
    if ($isAdmin) {
        // Get the trees within the building area
        $treesQuery = "
            SELECT t.* 
            FROM trees t
            WHERE t.area_id = ?
        ";
        $treesStmt = $conn->prepare($treesQuery);
        if (!$treesStmt) {
            throw new Exception("Trees query preparation failed: " . $conn->error);
        }
        
        $treesStmt->bind_param("i", $request['area_id']);
        $treesResult = $treesStmt->execute();
        
        if (!$treesResult) {
            throw new Exception("Trees query execution failed: " . $treesStmt->error);
        }
        
        $treesResult = $treesStmt->get_result();
        
        $trees = [];
        while ($tree = $treesResult->fetch_assoc()) {
            $trees[] = $tree;
        }
        
        // Add trees to the response
        $request['trees'] = $trees;
    }
    
    // Close connection
    $conn->close();
    
    // Return success response with request data
    echo json_encode([
        'success' => true,
        'request' => $request
    ]);
    
} catch (Exception $e) {
    // Log error
    error_log('Error fetching building request details: ' . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching request details: ' . $e->getMessage()
    ]);
}
?> 