<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connect.php';

$debug = array(
    'database_connection' => false,
    'users_table_exists' => false,
    'table_structure' => null,
    'sample_users' => null,
    'test_login' => null,
    'errors' => array()
);

try {
    // Test database connection
    $conn = openDatabaseConnection();
    $debug['database_connection'] = true;
    
    // Check users table
    $tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
    $debug['users_table_exists'] = ($tableCheck->num_rows > 0);
    
    if ($debug['users_table_exists']) {
        // Get table structure
        $structure = $conn->query("DESCRIBE users");
        $tableStructure = array();
        while ($row = $structure->fetch_assoc()) {
            $tableStructure[] = $row;
        }
        $debug['table_structure'] = $tableStructure;
        
        // Get sample users (without showing full passwords)
        $users = $conn->query("SELECT id, username, LEFT(password, 20) as password_preview, name, email, role FROM users");
        $sampleUsers = array();
        while ($row = $users->fetch_assoc()) {
            $sampleUsers[] = $row;
        }
        $debug['sample_users'] = $sampleUsers;
        
        // Test login with admin account
        $testUsername = 'admin';
        $testPassword = 'admin123';
        
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param('s', $testUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            $debug['test_login'] = array(
                'user_found' => true,
                'password_hash_length' => strlen($user['password']),
                'password_starts_with' => substr($user['password'], 0, 7),
                'password_verify_result' => password_verify($testPassword, $user['password'])
            );
        } else {
            $debug['test_login'] = array(
                'user_found' => false
            );
            
            // Try to recreate admin user
            $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $createAdminSQL = "INSERT INTO users (username, password, name, email, role) VALUES (?, ?, 'Administrator', 'admin@example.com', 'admin')";
            $stmt = $conn->prepare($createAdminSQL);
            $stmt->bind_param('ss', $testUsername, $adminPassword);
            
            if ($stmt->execute()) {
                $debug['admin_user_created'] = true;
            } else {
                $debug['errors'][] = "Failed to create admin user: " . $stmt->error;
            }
        }
    } else {
        // Create users table
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
            $debug['table_created'] = true;
            
            // Create admin user
            $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $createAdminSQL = "INSERT INTO users (username, password, name, email, role) VALUES ('admin', ?, 'Administrator', 'admin@example.com', 'admin')";
            $stmt = $conn->prepare($createAdminSQL);
            $stmt->bind_param('s', $adminPassword);
            
            if ($stmt->execute()) {
                $debug['admin_user_created'] = true;
            } else {
                $debug['errors'][] = "Failed to create admin user: " . $stmt->error;
            }
        } else {
            $debug['errors'][] = "Failed to create users table: " . $conn->error;
        }
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $debug['errors'][] = $e->getMessage();
}

echo json_encode($debug, JSON_PRETTY_PRINT); 