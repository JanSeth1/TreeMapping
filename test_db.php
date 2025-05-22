<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connect.php';

try {
    $conn = openDatabaseConnection();
    echo "Database connection successful!\n\n";
    
    // Check users table
    $result = $conn->query("DESCRIBE users");
    if ($result) {
        echo "Users table structure:\n";
        while ($row = $result->fetch_assoc()) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    }
    
    // Check for existing users
    $result = $conn->query("SELECT id, username, role FROM users");
    if ($result) {
        echo "\nExisting users:\n";
        while ($row = $result->fetch_assoc()) {
            echo "ID: {$row['id']}, Username: {$row['username']}, Role: {$row['role']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?> 