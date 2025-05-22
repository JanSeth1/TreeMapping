<?php
header('Content-Type: application/json');

try {
    // Include database connection
    require_once 'db_connect.php';
    
    // Get the POST data
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['tree_ids']) || !isset($data['status'])) {
        throw new Exception('Missing required parameters');
    }
    
    $treeIds = $data['tree_ids'];
    $status = $data['status'];
    
    // Validate status
    if (!in_array($status, ['approved', 'rejected', 'pending'])) {
        throw new Exception('Invalid status value');
    }
    
    // Open database connection
    $conn = openDatabaseConnection();
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Prepare the update statement
        $stmt = $conn->prepare('UPDATE trees SET status = ? WHERE id = ?');
        
        // Update each tree
        foreach ($treeIds as $treeId) {
            $stmt->bind_param('si', $status, $treeId);
            if (!$stmt->execute()) {
                throw new Exception("Failed to update tree ID $treeId: " . $stmt->error);
            }
        }
        
        // Commit the transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => count($treeIds) . ' trees updated successfully'
        ]);
        
    } catch (Exception $e) {
        // Rollback the transaction on error
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log error
    error_log('Error updating multiple trees: ' . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while updating trees: ' . $e->getMessage()
    ]);
}

// Close resources
if (isset($stmt)) {
    $stmt->close();
}
if (isset($conn)) {
    $conn->close();
}
?> 