<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = array(
    'success' => false,
    'messages' => array()
);

try {
    // Connect to MySQL without selecting a database
    $conn = new mysqli('localhost', 'root', '');
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Create database if it doesn't exist
    if (!$conn->query("CREATE DATABASE IF NOT EXISTS treemap")) {
        throw new Exception("Error creating database: " . $conn->error);
    }
    $response['messages'][] = "Database 'treemap' created or already exists";
    
    // Select the database
    if (!$conn->select_db('treemap')) {
        throw new Exception("Error selecting database: " . $conn->error);
    }
    
    // Drop existing users table if it exists
    $conn->query("DROP TABLE IF EXISTS users");
    $response['messages'][] = "Dropped existing users table";
    
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
    
    if (!$conn->query($createTableSQL)) {
        throw new Exception("Error creating users table: " . $conn->error);
    }
    $response['messages'][] = "Created users table";
    
    // Create default admin user
    $adminUsername = 'admin';
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $adminName = 'Administrator';
    $adminEmail = 'admin@example.com';
    $adminRole = 'admin';
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, name, email, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $adminUsername, $adminPassword, $adminName, $adminEmail, $adminRole);
    
    if (!$stmt->execute()) {
        throw new Exception("Error creating admin user: " . $stmt->error);
    }
    $response['messages'][] = "Created default admin user (username: admin, password: admin123)";
    
    // Create a test user
    $testUsername = 'testuser';
    $testPassword = password_hash('test123', PASSWORD_DEFAULT);
    $testName = 'Test User';
    $testEmail = 'test@example.com';
    $testRole = 'user';
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, name, email, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $testUsername, $testPassword, $testName, $testEmail, $testRole);
    
    if (!$stmt->execute()) {
        throw new Exception("Error creating test user: " . $stmt->error);
    }
    $response['messages'][] = "Created test user (username: testuser, password: test123)";
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['messages'][] = "Error: " . $e->getMessage();
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}

echo json_encode($response, JSON_PRETTY_PRINT); 