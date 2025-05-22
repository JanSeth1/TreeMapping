<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$results = array(
    'database_connection' => false,
    'users_table' => false,
    'table_structure' => null,
    'sample_users' => null,
    'errors' => array(),
    'debug_info' => array()
);

try {
    // Test database connection
    $conn = new mysqli('localhost', 'root', '', 'treemap');
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    $results['database_connection'] = true;
    
    // Check if users table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
    $results['users_table'] = ($tableCheck->num_rows > 0);
    
    if ($results['users_table']) {
        // Get table structure
        $structure = $conn->query("DESCRIBE users");
        $tableStructure = array();
        while ($row = $structure->fetch_assoc()) {
            $tableStructure[] = $row;
        }
        $results['table_structure'] = $tableStructure;
        
        // Get sample users (without showing full passwords)
        $users = $conn->query("SELECT id, username, LEFT(password, 10) as password_preview, name, email, role, created_at FROM users LIMIT 5");
        $sampleUsers = array();
        while ($row = $users->fetch_assoc()) {
            $sampleUsers[] = $row;
        }
        $results['sample_users'] = $sampleUsers;
        
        // Count total users
        $countResult = $conn->query("SELECT COUNT(*) as total FROM users");
        $count = $countResult->fetch_assoc();
        $results['total_users'] = $count['total'];
        
        // Test password verification with a test user
        $testUsername = 'test_user_' . time();
        $testPassword = 'test123';
        $hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);
        
        // Create test user
        $stmt = $conn->prepare("INSERT INTO users (username, password, name, email, role) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $name = "Test User";
            $email = "test@example.com";
            $role = "user";
            $stmt->bind_param("sssss", $testUsername, $hashedPassword, $name, $email, $role);
            $results['test_user_creation'] = $stmt->execute();
            
            if ($results['test_user_creation']) {
                // Try to verify the password
                $verifyStmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
                $verifyStmt->bind_param("s", $testUsername);
                $verifyStmt->execute();
                $verifyResult = $verifyStmt->get_result();
                $userData = $verifyResult->fetch_assoc();
                
                if ($userData) {
                    $results['debug_info']['password_verification'] = password_verify($testPassword, $userData['password']);
                    $results['debug_info']['stored_hash'] = $userData['password'];
                    $results['debug_info']['hash_info'] = password_get_info($userData['password']);
                }
                
                // Clean up test user
                $conn->query("DELETE FROM users WHERE username = '$testUsername'");
            }
            
            $stmt->close();
        } else {
            $results['errors'][] = "Failed to prepare test user statement: " . $conn->error;
        }
    } else {
        // Create users table if it doesn't exist
        $createTableSQL = "
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                name VARCHAR(255),
                email VARCHAR(255),
                role ENUM('user', 'mapper', 'admin') DEFAULT 'user',
                auth_token VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
        
        if ($conn->query($createTableSQL)) {
            $results['debug_info']['table_created'] = true;
        } else {
            $results['errors'][] = "Failed to create users table: " . $conn->error;
        }
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $results['errors'][] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT); 