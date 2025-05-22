<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connect.php';

try {
    $conn = openDatabaseConnection();
    
    // Update table structure
    $alterQueries = [
        "ALTER TABLE users MODIFY COLUMN username VARCHAR(255) NOT NULL",
        "ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL",
        "ALTER TABLE users MODIFY COLUMN role ENUM('user', 'mapper', 'admin') NOT NULL DEFAULT 'user'",
        "ALTER TABLE users MODIFY COLUMN email VARCHAR(255)",
        "ALTER TABLE users MODIFY COLUMN auth_token VARCHAR(255)",
        "ALTER TABLE users MODIFY COLUMN name VARCHAR(255)",
        "ALTER TABLE users MODIFY COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        // Drop extra columns that aren't needed
        "ALTER TABLE users DROP COLUMN IF EXISTS status",
        "ALTER TABLE users DROP COLUMN IF EXISTS date_joined",
        "ALTER TABLE users DROP COLUMN IF EXISTS last_login"
    ];
    
    foreach ($alterQueries as $query) {
        if (!$conn->query($query)) {
            echo "Warning: Failed to execute query: $query\nError: " . $conn->error . "\n";
        }
    }
    
    // Fix empty roles - set them to 'user'
    $conn->query("UPDATE users SET role = 'user' WHERE role = '' OR role IS NULL");
    
    // Update existing users to have proper password hashing if needed
    $result = $conn->query("SELECT id, username, password FROM users");
    while ($row = $result->fetch_assoc()) {
        // Check if password needs to be hashed
        if (strlen($row['password']) < 60) { // Not a bcrypt hash
            $hashedPassword = password_hash($row['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hashedPassword, $row['id']);
            $stmt->execute();
            $stmt->close();
            echo "Updated password hash for user: {$row['username']}\n";
        }
    }
    
    echo "Database structure has been fixed successfully!\n";
    echo "Please try logging in again with your username and password.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 