<?php
/**
 * Authentication functions for the Tree Information Management System
 */

/**
 * Verify user authentication from JWT token
 *
 * @return array|false User data if authenticated, false otherwise
 */
function verifyAuth() {
    try {
        // For testing/development, we'll allow a simplified auth method
        // You can replace this with your actual authentication logic
        
        // Get headers
        $headers = getallheaders();
        
        // Check for Authorization header
        $token = null;
        if (isset($headers['Authorization']) && strpos($headers['Authorization'], 'Bearer ') === 0) {
            $token = substr($headers['Authorization'], 7);
        }
        
        // Get user ID from header
        $userId = isset($headers['X-User-ID']) ? $headers['X-User-ID'] : null;
        
        if (!$userId) {
            error_log("Auth failed: No user ID provided");
            return false;
        }
        
        // For testing purposes, we'll create a mock user if needed
        // This allows development without a full authentication system
        
        // In production, uncomment this to require proper token validation
        /*
        // Open database connection
        $conn = openDatabaseConnection();
        
        // Query the database for this user with this token
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND auth_token = ?");
        $stmt->bind_param("ss", $userId, $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // No matching user found
            $conn->close();
            return false;
        }
        
        // Return the user data
        $user = $result->fetch_assoc();
        $conn->close();
        return $user;
        */
        
        // For development - just return a mock user with the provided ID
        return [
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'user',  // Default role
            'auth_token' => $token
        ];
        
    } catch (Exception $e) {
        error_log("Authentication error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the current authenticated user
 *
 * @return array|false User data if authenticated, false otherwise
 */
function getCurrentUser() {
    static $user = null;
    
    if ($user === null) {
        $user = verifyAuth();
    }
    
    return $user;
}

/**
 * Check if user is an admin
 *
 * @param array $user User data
 * @return bool True if admin, false otherwise
 */
function isAdmin($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    
    return $user && isset($user['role']) && $user['role'] === 'admin';
}

/**
 * Check if user is a mapper
 *
 * @param array $user User data
 * @return bool True if mapper, false otherwise
 */
function isMapper($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    
    return $user && isset($user['role']) && $user['role'] === 'mapper';
}

/**
 * Generate a simple auth token
 *
 * @return string A random token
 */
function generateAuthToken() {
    return bin2hex(random_bytes(32));
}
?> 