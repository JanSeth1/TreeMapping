<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connect.php';

$results = array(
    'registration' => null,
    'login' => null,
    'errors' => array()
);

try {
    $conn = openDatabaseConnection();
    
    // Test credentials
    $testUser = array(
        'username' => 'testuser_' . time(),
        'password' => 'Test123!',
        'email' => 'test@example.com',
        'name' => 'Test User'
    );
    
    // Step 1: Register the user
    $hashedPassword = password_hash($testUser['password'], PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare('INSERT INTO users (username, password, name, email, role) VALUES (?, ?, ?, ?, ?)');
    $role = 'user';
    $stmt->bind_param('sssss', $testUser['username'], $hashedPassword, $testUser['name'], $testUser['email'], $role);
    
    if ($stmt->execute()) {
        $results['registration'] = array(
            'success' => true,
            'user_id' => $conn->insert_id,
            'username' => $testUser['username']
        );
        
        // Step 2: Try to login
        $loginStmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
        $loginStmt->bind_param('s', $testUser['username']);
        $loginStmt->execute();
        $user = $loginStmt->get_result()->fetch_assoc();
        
        if ($user) {
            // Test password verification
            $passwordVerified = password_verify($testUser['password'], $user['password']);
            
            $results['login'] = array(
                'success' => $passwordVerified,
                'user_found' => true,
                'password_match' => $passwordVerified,
                'stored_hash' => $user['password'],
                'hash_info' => password_get_info($user['password'])
            );
        } else {
            $results['login'] = array(
                'success' => false,
                'user_found' => false
            );
        }
        
        // Clean up - remove test user
        $conn->query("DELETE FROM users WHERE username = '" . $testUser['username'] . "'");
        
    } else {
        $results['registration'] = array(
            'success' => false,
            'error' => $stmt->error
        );
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $results['errors'][] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT); 