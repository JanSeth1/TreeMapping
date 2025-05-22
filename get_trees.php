<?php
// Start session
session_start();

// Prevent PHP from outputting HTML errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Buffer output to prevent header issues
ob_start();

// Set JSON content type header early
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-ID');

// Debug log for request details
error_log("Debug: Request received - " . date('Y-m-d H:i:s'));
error_log("Debug: GET parameters - " . print_r($_GET, true));
error_log("Debug: Headers - " . print_r(getallheaders(), true));

// Point in polygon check function
function pointInPolygon($point, $polygon) {
    if (empty($polygon)) {
        error_log("Debug: Empty polygon coordinates");
        return false;
    }
    
    // Extract lat/lng from point
    $lat = $point['lat'];
    $lng = $point['lng'];
    
    error_log("Debug: Checking point lat: $lat, lng: $lng");
    
    $inside = false;
    $j = count($polygon) - 1;
    
    for ($i = 0; $i < count($polygon); $i++) {
        $vertex1 = $polygon[$i];
        $vertex2 = $polygon[$j];
        
        // Check if point is inside polygon using ray casting algorithm
        if (($vertex1['lng'] > $lng) != ($vertex2['lng'] > $lng) &&
            ($lat < ($vertex2['lat'] - $vertex1['lat']) * ($lng - $vertex1['lng']) /
            ($vertex2['lng'] - $vertex1['lng']) + $vertex1['lat'])) {
            $inside = !$inside;
        }
        $j = $i;
    }
    
    return $inside;
}

// Custom error handler to return JSON instead of HTML
function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    ob_clean();
    http_response_code(500);
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
        ob_clean(); // Clear any previous output
        http_response_code(500);
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
    
    // Debug log
    error_log("Debug: Starting get_trees.php execution");
    
    // Get authentication headers
    $headers = getallheaders();
    $authToken = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : '';
    $userId = isset($headers['X-User-ID']) ? $headers['X-User-ID'] : null;
    
    error_log("Debug: Auth Token: " . substr($authToken, 0, 10) . "..., User ID: " . $userId);
    
    // Open database connection with error checking
    try {
        $conn = openDatabaseConnection();
        error_log("Debug: Database connection successful");

        // First, let's check if there are any trees at all
        $countQuery = "SELECT COUNT(*) as total FROM trees";
        $countResult = $conn->query($countQuery);
        $totalCount = $countResult->fetch_assoc()['total'];
        error_log("Debug: Total trees in database: " . $totalCount);

        // Check if the user exists and token is valid
        if (!empty($authToken) && !empty($userId)) {
            $authQuery = "SELECT COUNT(*) as valid FROM users WHERE id = ? AND auth_token = ?";
            $authStmt = $conn->prepare($authQuery);
            $authStmt->bind_param("is", $userId, $authToken);
            $authStmt->execute();
            $authResult = $authStmt->get_result();
            $isValidAuth = $authResult->fetch_assoc()['valid'] > 0;
            $authStmt->close();
            error_log("Debug: Auth validation result: " . ($isValidAuth ? "valid" : "invalid"));
            
            if (!$isValidAuth) {
                error_log("Debug: Invalid authentication. Token: " . substr($authToken, 0, 10) . "..., User ID: " . $userId);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid authentication'
                ]);
                exit;
            }
        } else {
            error_log("Debug: Missing authentication headers. Auth Token: " . ($authToken ? "present" : "missing") . ", User ID: " . ($userId ? "present" : "missing"));
            echo json_encode([
                'success' => false,
                'message' => 'Missing authentication headers'
            ]);
            exit;
        }
        
        // Get all trees without complex joins
        $query = "SELECT * FROM trees WHERE status != 'deleted'";
        error_log("Debug: Simple query: " . $query);
        
        $result = $conn->query($query);
        if ($result === false) {
            error_log("Debug: Query failed: " . $conn->error);
            throw new Exception("Query failed: " . $conn->error);
        }
        
        $trees = array();
        $totalTrees = 0;
        
        while ($row = $result->fetch_assoc()) {
            error_log("Debug: Processing tree ID: " . $row['id']);
            $tree = array(
                'id' => $row['id'],
                'type' => $row['type'],
                'lat' => floatval($row['lat']),
                'lng' => floatval($row['lng']),
                'description' => $row['description'],
                'photo_path' => $row['photo_path'],
                'endemic' => (bool)$row['endemic'],
                'conservation_status' => $row['conservation_status'] ?? null,
                'status' => $row['status'],
                'user_id' => $row['user_id'],
                'created_at' => $row['created_at'],
                'area_id' => $row['area_id']
            );
            $trees[] = $tree;
            $totalTrees++;
        }
        
        error_log("Debug: Found " . $totalTrees . " trees in total");
        
        // Return all trees for now (we'll filter by area in the frontend)
        echo json_encode([
            'success' => true,
            'data' => $trees,
            'total' => count($trees)
        ]);
        
    } catch (Exception $e) {
        error_log("Debug: Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
} catch (Exception $e) {
    // Log error
    error_log('Error fetching trees: ' . $e->getMessage());
    
    // Clear any previous output
    ob_clean();
    
    // Set error status code
    http_response_code(500);
    
    // Return error response
    $errorResponse = json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching trees: ' . $e->getMessage()
    ]);
    
    if ($errorResponse === false) {
        echo json_encode([
            'success' => false,
            'message' => 'Error generating error response'
        ]);
    } else {
        echo $errorResponse;
    }
} finally {
    // End output buffering
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
?>