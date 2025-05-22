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
    } catch (Exception $e) {
        error_log("Debug: Database connection failed: " . $e->getMessage());
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
    
    // Initialize parameters array for binding
    $params = array();
    $types = '';
    
    // Base query to get trees
    $query = "SELECT DISTINCT t.* FROM trees t";
    
    // Start WHERE clause
    $whereClause = array();
    
    // If authentication is provided, verify it
    if (!empty($authToken) && !empty($userId)) {
        $query .= " LEFT JOIN users u ON t.user_id = u.id";
        $query .= " LEFT JOIN auth_tokens at ON u.id = at.user_id";
        $whereClause[] = "(at.token = ? AND at.user_id = ? AND at.expires_at > NOW())";
        $types .= "ss";
        $params[] = $authToken;
        $params[] = $userId;
        
        // Get status filter (only apply if user is authenticated)
        $status = isset($_GET['status']) ? $_GET['status'] : 'approved';
        if ($status !== 'all') {
            $whereClause[] = "t.status = ?";
            $types .= "s";
            $params[] = $status;
        }
    } else {
        // If no authentication, just show approved trees
        $whereClause[] = "t.status = 'approved'";
    }
    
    // Add area filter if area_id is provided
    if (isset($_GET['area_id']) && !empty($_GET['area_id'])) {
        error_log("Debug: Processing area_id: " . $_GET['area_id']);
        
        // First verify the area exists and get its coordinates
        $areaQuery = "SELECT coordinates FROM enrolled_areas WHERE id = ? AND user_id = ?";
        $areaStmt = $conn->prepare($areaQuery);
        $areaStmt->bind_param("ii", $_GET['area_id'], $userId);
        $areaStmt->execute();
        $areaResult = $areaStmt->get_result();
        $areaData = $areaResult->fetch_assoc();
        $areaStmt->close();
        
        if ($areaData) {
            error_log("Debug: Area exists, adding area_id filter");
            $whereClause[] = "t.area_id = ?";
            $types .= "i";
            $params[] = $_GET['area_id'];
        } else {
            error_log("Debug: Area not found or not enrolled: " . $_GET['area_id']);
            // Return empty result if area doesn't exist
            echo json_encode([
                'success' => true,
                'data' => [],
                'total' => 0
            ]);
            exit;
        }
    }
    
    // Combine WHERE clauses
    if (!empty($whereClause)) {
        $query .= " WHERE " . implode(" AND ", $whereClause);
    }
    
    // Add sorting
    $query .= " ORDER BY t.created_at DESC";
    
    error_log("Debug: Final query: " . $query);
    error_log("Debug: Parameters: " . print_r($params, true));
    
    // Prepare and execute the query
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        error_log("Debug: Query preparation failed: " . $conn->error);
        throw new Exception("Query preparation failed: " . $conn->error);
    }
    
    error_log("Debug: Query prepared successfully");
    
    // Bind parameters if there are any
    if (!empty($params)) {
        $refs = array();
        $refs[0] = $types;
        for($i = 0; $i < count($params); $i++) {
            $refs[$i + 1] = &$params[$i];
        }
        call_user_func_array(array($stmt, 'bind_param'), $refs);
        error_log("Debug: Parameters bound successfully");
    }

    // Execute the query with error checking
    if (!$stmt->execute()) {
        error_log("Debug: Query execution failed: " . $stmt->error);
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    error_log("Debug: Query executed successfully");
    
    // Get results
    $result = $stmt->get_result();
    $trees = array();
    $totalTrees = 0;
    
    while ($row = $result->fetch_assoc()) {
        $totalTrees++;
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
    }
    
    error_log("Debug: Total trees found: " . $totalTrees);
    
    // Return success response with trees data
    $response = json_encode([
        'success' => true,
        'data' => $trees,
        'total' => count($trees)
    ]);
    
    if ($response === false) {
        error_log("Debug: JSON encoding failed: " . json_last_error_msg());
        throw new Exception("JSON encoding failed: " . json_last_error_msg());
    }
    
    echo $response;
    
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
    // Clean up resources safely
    try {
        // Only close statement if it's a valid object
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        
        // Only close connection if it's a valid object
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    } catch (Exception $e) {
        error_log('Error during cleanup: ' . $e->getMessage());
    }
    
    // End output buffering
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
?>