<?php
/**
 * Database connection functions for the Tree Information Management System
 */

/**
 * Open a connection to the database
 *
 * @return mysqli Database connection object
 * @throws Exception If connection fails
 */
function openDatabaseConnection() {
    // Database configuration
    $db_host = 'localhost';
    $db_user = 'root';       // Change to your MySQL username
    $db_password = '';       // Change to your MySQL password
    $db_name = 'treemap';    // Changed to match the expected database name
    
    // Create connection with error handling
    try {
        $conn = new mysqli($db_host, $db_user, $db_password);
        
        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            throw new Exception("Database connection failed: " . $conn->connect_error);
        }
        
        // Check if database exists, create it if it doesn't
        if (!$conn->select_db($db_name)) {
            $createDb = "CREATE DATABASE IF NOT EXISTS $db_name";
            if (!$conn->query($createDb)) {
                throw new Exception("Failed to create database: " . $conn->error);
            }
            $conn->select_db($db_name);
        }
        
        // Set charset to UTF-8
        if (!$conn->set_charset("utf8mb4")) {
            throw new Exception("Error setting charset: " . $conn->error);
        }
        
        // Check if users table exists, create it if it doesn't
        $tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
        if ($tableCheck === false) {
            throw new Exception("Error checking tables: " . $conn->error);
        }
        
        if ($tableCheck->num_rows === 0) {
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
                throw new Exception("Failed to create users table: " . $conn->error);
            }
            
            // Create a default admin user
            $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $createAdminSQL = "
                INSERT INTO users (username, password, name, email, role) 
                VALUES ('admin', ?, 'Administrator', 'admin@example.com', 'admin')";
            
            $stmt = $conn->prepare($createAdminSQL);
            if (!$stmt) {
                throw new Exception("Failed to prepare admin user creation: " . $conn->error);
            }
            
            $stmt->bind_param('s', $adminPassword);
            if (!$stmt->execute()) {
                throw new Exception("Failed to create admin user: " . $stmt->error);
            }
            $stmt->close();
        }
        
        // Check if trees table exists
        $treeTableCheck = $conn->query("SHOW TABLES LIKE 'trees'");
        if ($treeTableCheck === false) {
            throw new Exception("Error checking trees table: " . $conn->error);
        }
        
        if ($treeTableCheck->num_rows === 0) {
            $createTreesTableSQL = "
                CREATE TABLE trees (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type VARCHAR(255),
                    lat DECIMAL(10,8) NOT NULL,
                    lng DECIMAL(11,8) NOT NULL,
                    description TEXT,
                    photo_path VARCHAR(255),
                    endemic BOOLEAN DEFAULT FALSE,
                    conservation_status VARCHAR(50),
                    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                    user_id INT,
                    area_id INT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX (status),
                    INDEX (area_id)
                )";
            
            if (!$conn->query($createTreesTableSQL)) {
                throw new Exception("Failed to create trees table: " . $conn->error);
            }
        } else {
            // Check if area_id column exists in trees table
            $checkAreaIdSQL = "
                SELECT COUNT(*) as column_exists 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 'trees' 
                AND COLUMN_NAME = 'area_id'";
            
            $stmt = $conn->prepare($checkAreaIdSQL);
            if (!$stmt) {
                throw new Exception("Failed to prepare column check query: " . $conn->error);
            }
            
            $stmt->bind_param('s', $db_name);
            if (!$stmt->execute()) {
                throw new Exception("Failed to check column existence: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['column_exists'] == 0) {
                // Add area_id column if it doesn't exist
                $addColumnSQL = "ALTER TABLE trees ADD COLUMN area_id INT, ADD INDEX (area_id)";
                if (!$conn->query($addColumnSQL)) {
                    throw new Exception("Failed to add area_id column: " . $conn->error);
                }
            }
            
            $stmt->close();
        }

        // Check if auth_tokens table exists
        $authTableCheck = $conn->query("SHOW TABLES LIKE 'auth_tokens'");
        if ($authTableCheck === false) {
            throw new Exception("Error checking auth_tokens table: " . $conn->error);
        }
        
        if ($authTableCheck->num_rows === 0) {
            $createAuthTableSQL = "
                CREATE TABLE auth_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(255) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX (token),
                    INDEX (expires_at)
                )";
            
            if (!$conn->query($createAuthTableSQL)) {
                throw new Exception("Failed to create auth_tokens table: " . $conn->error);
            }
        }

        // Check if areas table exists
        $areasTableCheck = $conn->query("SHOW TABLES LIKE 'areas'");
        if ($areasTableCheck === false) {
            throw new Exception("Error checking areas table: " . $conn->error);
        }
        
        if ($areasTableCheck->num_rows === 0) {
            $createAreasTableSQL = "
                CREATE TABLE areas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255),
                    description TEXT,
                    coordinates JSON NOT NULL,
                    user_id INT,
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX (status)
                )";
            
            if (!$conn->query($createAreasTableSQL)) {
                throw new Exception("Failed to create areas table: " . $conn->error);
            }
        }

        // Update trees table to add foreign key for area_id if it doesn't exist
        $checkAreaFKSQL = "
            SELECT COUNT(1) as fk_exists 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE CONSTRAINT_SCHEMA = ? 
            AND TABLE_NAME = 'trees' 
            AND CONSTRAINT_NAME = 'fk_trees_area_id'";
        
        $stmt = $conn->prepare($checkAreaFKSQL);
        if (!$stmt) {
            throw new Exception("Failed to prepare FK check query: " . $conn->error);
        }
        
        $stmt->bind_param('s', $db_name);
        if (!$stmt->execute()) {
            throw new Exception("Failed to check FK existence: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['fk_exists'] == 0) {
            // Drop any existing foreign key constraints on area_id
            try {
                $conn->query("ALTER TABLE trees DROP FOREIGN KEY IF EXISTS fk_trees_area_id");
            } catch (Exception $e) {
                // Ignore errors if constraint doesn't exist
            }
            
            // Add foreign key constraint
            $addFKSQL = "
                ALTER TABLE trees 
                ADD CONSTRAINT fk_trees_area_id 
                FOREIGN KEY (area_id) 
                REFERENCES areas(id) 
                ON DELETE SET NULL";
            
            if (!$conn->query($addFKSQL)) {
                throw new Exception("Failed to add area_id foreign key: " . $conn->error);
            }
        }
        
        $stmt->close();
        
        return $conn;
        
    } catch (Exception $e) {
        error_log("Database initialization error: " . $e->getMessage());
        throw new Exception("Database initialization failed: " . $e->getMessage());
    }
}

/**
 * Execute a SQL query and return all results
 *
 * @param string $query SQL query to execute
 * @param array $params Parameters for prepared statement
 * @return array Query results
 * @throws Exception If query fails
 */
function executeQuery($query, $params = []) {
    $conn = openDatabaseConnection();
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $conn->close();
        throw new Exception("Query preparation failed: " . $conn->error);
    }
    
    if (!empty($params)) {
        // Determine parameter types
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_string($param)) {
                $types .= 's';
            } else {
                $types .= 'b';
            }
        }
        
        // Bind parameters
        $bindParams = array_merge([$types], $params);
        $stmt->bind_param(...$bindParams);
    }
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        throw new Exception("Query execution failed: " . $error);
    }
    
    $result = $stmt->get_result();
    $data = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    $stmt->close();
    $conn->close();
    
    return $data;
}

/**
 * Execute a SQL query that doesn't return results (INSERT, UPDATE, DELETE)
 *
 * @param string $query SQL query to execute
 * @param array $params Parameters for prepared statement
 * @return int|string Number of affected rows or last insert ID
 * @throws Exception If query fails
 */
function executeNonQuery($query, $params = [], $returnInsertId = false) {
    $conn = openDatabaseConnection();
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $conn->close();
        throw new Exception("Query preparation failed: " . $conn->error);
    }
    
    if (!empty($params)) {
        // Determine parameter types
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_string($param)) {
                $types .= 's';
            } else {
                $types .= 'b';
            }
        }
        
        // Bind parameters
        $bindParams = array_merge([$types], $params);
        $stmt->bind_param(...$bindParams);
    }
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        throw new Exception("Query execution failed: " . $error);
    }
    
    $result = $returnInsertId ? $conn->insert_id : $stmt->affected_rows;
    
    $stmt->close();
    $conn->close();
    
    return $result;
}
?>