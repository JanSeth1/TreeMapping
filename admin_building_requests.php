<?php
// Include necessary files
require_once 'db_connect.php';
require_once 'auth.php';

// Check if user is admin
$user = verifyAuth();
if (!$user || $user['role'] !== 'admin') {
    header('Location: index.html');
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        // Update request status
        $requestId = intval($_POST['request_id']);
        $status = htmlspecialchars($_POST['status']);
        $adminNotes = htmlspecialchars($_POST['admin_notes']);
        
        // Connect to database
        $conn = openDatabaseConnection();
        
        // Update the request
        $stmt = $conn->prepare("UPDATE building_requests SET status = ?, admin_notes = ?, admin_id = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("sssi", $status, $adminNotes, $user['id'], $requestId);
        $stmt->execute();
        
        // Send email notification to user
        sendUserNotification($requestId, $status, $adminNotes);
        
        // Redirect to prevent form resubmission
        header('Location: admin_building_requests.php?update=success');
        exit;
    }
}

// Get building requests
$conn = openDatabaseConnection();
$requests = [];

// Check if filtering by status
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$whereClause = '';
$params = [];

if ($statusFilter) {
    $whereClause = "WHERE br.status = ?";
    $params[] = $statusFilter;
}

// Build the query
$query = "
    SELECT br.*, u.name as user_name, u.email as user_email 
    FROM building_requests br
    JOIN users u ON br.user_id = u.id
    $whereClause
    ORDER BY 
    CASE 
        WHEN br.status = 'pending' THEN 1
        WHEN br.status = 'in_progress' THEN 2
        WHEN br.status = 'approved' THEN 3
        WHEN br.status = 'rejected' THEN 4
    END, 
    br.created_at DESC
";

// Prepare and execute the query
$stmt = $conn->prepare($query);
if ($statusFilter) {
    $stmt->bind_param("s", $statusFilter);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch all requests
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

// Close connection
$conn->close();

// Function to send email notification to user
function sendUserNotification($requestId, $status, $adminNotes) {
    try {
        // Connect to database
        $conn = openDatabaseConnection();
        
        // Get request and user details
        $stmt = $conn->prepare("
            SELECT br.*, u.email, u.name 
            FROM building_requests br
            JOIN users u ON br.user_id = u.id
            WHERE br.id = ?
        ");
        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $request = $result->fetch_assoc();
        
        if (!$request) {
            return;
        }
        
        // Format status for email
        $statusText = ucfirst(str_replace('_', ' ', $status));
        
        // Compose email
        $subject = "Building Request #$requestId - Status Update";
        $message = "Dear {$request['name']},\n\n";
        $message .= "The status of your building request #$requestId has been updated to: $statusText\n\n";
        
        if ($adminNotes) {
            $message .= "Admin Notes: $adminNotes\n\n";
        }
        
        $message .= "Request Details:\n";
        $message .= "Structure Type: {$request['structure_type']}\n";
        $message .= "Structure Size: {$request['structure_size']} sq. meters\n\n";
        
        $message .= "You can view the full details of your request by logging into your account.\n\n";
        $message .= "Thank you for using our service.";
        
        $headers = "From: noreply@example.com\r\n";
        
        // Send email
        mail($request['email'], $subject, $message, $headers);
        
        // Close connection
        $conn->close();
        
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log('Error sending user notification: ' . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Building Requests - TIMS</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .request-filters {
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .request-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        
        .request-table th,
        .request-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .request-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        
        .request-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .request-table tr:hover {
            background-color: #f0f9f0;
        }
        
        .status-badge {
            padding: 6px 10px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-in_progress {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-view {
            background-color: #0a8806;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .btn-view:hover {
            background-color: #097205;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .modal-content {
            position: relative;
            background: white;
            width: 90%;
            max-width: 1000px;
            margin: 2rem auto;
            padding: 2rem;
            border-radius: 8px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .close-modal {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .modal-map {
            height: 400px;
            margin: 1rem 0;
            border-radius: 8px;
            border: 2px solid #0a8806;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .form-textarea {
            min-height: 100px;
        }
        
        .form-submit {
            background-color: #0a8806;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 1rem;
        }
        
        .form-submit:hover {
            background-color: #097205;
        }
        
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .request-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .detail-label {
            font-weight: bold;
            color: #333;
        }
        
        .nav-tabs {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0 0 2rem 0;
            border-bottom: 2px solid #ddd;
        }
        
        .nav-tabs li {
            margin-right: 0.5rem;
        }
        
        .nav-tabs a {
            display: block;
            padding: 10px 20px;
            text-decoration: none;
            color: #333;
            border-radius: 4px 4px 0 0;
            font-weight: 500;
        }
        
        .nav-tabs a.active {
            background-color: #0a8806;
            color: white;
        }
        
        .nav-tabs a:not(.active):hover {
            background-color: #f0f9f0;
            color: #0a8806;
        }
        
        .counter-badge {
            background-color: #0a8806;
            color: white;
            padding: 2px 5px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Building Availability Requests Administration</h1>
        
        <?php if (isset($_GET['update']) && $_GET['update'] === 'success'): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Request updated successfully!
        </div>
        <?php endif; ?>
        
        <!-- Status filter tabs -->
        <ul class="nav-tabs">
            <li>
                <a href="admin_building_requests.php" <?php echo !$statusFilter ? 'class="active"' : ''; ?>>
                    All Requests
                </a>
            </li>
            <li>
                <a href="admin_building_requests.php?status=pending" <?php echo $statusFilter === 'pending' ? 'class="active"' : ''; ?>>
                    Pending
                    <?php if (count(array_filter($requests, function($r) { return $r['status'] === 'pending'; })) > 0): ?>
                    <span class="counter-badge">
                        <?php echo count(array_filter($requests, function($r) { return $r['status'] === 'pending'; })); ?>
                    </span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="admin_building_requests.php?status=in_progress" <?php echo $statusFilter === 'in_progress' ? 'class="active"' : ''; ?>>
                    In Progress
                </a>
            </li>
            <li>
                <a href="admin_building_requests.php?status=approved" <?php echo $statusFilter === 'approved' ? 'class="active"' : ''; ?>>
                    Approved
                </a>
            </li>
            <li>
                <a href="admin_building_requests.php?status=rejected" <?php echo $statusFilter === 'rejected' ? 'class="active"' : ''; ?>>
                    Rejected
                </a>
            </li>
        </ul>
        
        <!-- Requests table -->
        <?php if (empty($requests)): ?>
        <div style="text-align: center; padding: 3rem; color: #666; background: #f9f9f9; border-radius: 8px;">
            <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
            <p>No building requests found for the selected filter.</p>
        </div>
        <?php else: ?>
        <table class="request-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Structure Type</th>
                    <th>Size (m²)</th>
                    <th>Submission Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                <tr>
                    <td>#<?php echo $request['id']; ?></td>
                    <td>
                        <?php echo htmlspecialchars($request['user_name']); ?><br>
                        <small><?php echo htmlspecialchars($request['user_email']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($request['structure_type']); ?></td>
                    <td><?php echo htmlspecialchars($request['structure_size']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $request['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-view" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                <i class="fas fa-eye"></i> View & Update
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- Request Details Modal -->
    <div id="requestModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Building Request Details</h2>
            
            <div id="requestDetails" class="request-details">
                <!-- Request details will be populated here -->
            </div>
            
            <div id="mapContainer" class="modal-map">
                <!-- Map will be initialized here -->
            </div>
            
            <form id="updateForm" method="POST" action="admin_building_requests.php">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" id="requestIdInput" name="request_id" value="">
                
                <div class="form-group">
                    <label class="form-label" for="statusInput">Update Status:</label>
                    <select class="form-select" id="statusInput" name="status" required>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="adminNotesInput">Admin Notes:</label>
                    <textarea class="form-textarea" id="adminNotesInput" name="admin_notes" placeholder="Add notes for the user about this request..."></textarea>
                </div>
                
                <button type="submit" class="form-submit">Update Request</button>
            </form>
        </div>
    </div>
    
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Initialize map variable
        let modalMap = null;
        
        // View request details
        function viewRequest(requestId) {
            // Set request ID in form
            document.getElementById('requestIdInput').value = requestId;
            
            // Fetch request details from server
            fetch(`get_building_request_details.php?id=${requestId}`, {
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('authToken')}`,
                    'X-User-ID': localStorage.getItem('user_id')
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const request = data.request;
                    
                    // Populate request details
                    document.getElementById('requestDetails').innerHTML = `
                        <div class="detail-item">
                            <div class="detail-label">Request ID:</div>
                            <div>#${request.id}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">User:</div>
                            <div>${request.user_name} (${request.user_email})</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Structure Type:</div>
                            <div>${request.structure_type}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Size:</div>
                            <div>${request.structure_size} sq. meters</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Submission Date:</div>
                            <div>${new Date(request.created_at).toLocaleDateString()}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status:</div>
                            <div>
                                <span class="status-badge status-${request.status}">
                                    ${request.status.charAt(0).toUpperCase() + request.status.slice(1).replace('_', ' ')}
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Project Description:</div>
                            <div>${request.project_description || 'No description provided'}</div>
                        </div>
                    `;
                    
                    // Set current status in select box
                    document.getElementById('statusInput').value = request.status;
                    
                    // Set existing admin notes
                    document.getElementById('adminNotesInput').value = request.admin_notes || '';
                    
                    // Initialize map after a short delay
                    setTimeout(() => {
                        initializeModalMap(request);
                    }, 300);
                    
                    // Show modal
                    document.getElementById('requestModal').style.display = 'block';
                    
                } else {
                    alert('Error loading request details: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to load request details. Please try again.');
            });
        }
        
        // Initialize map with request location
        function initializeModalMap(request) {
            // Clean up existing map if it exists
            if (modalMap) {
                modalMap.remove();
            }
            
            // Create new map instance
            modalMap = L.map('mapContainer').setView([0, 0], 13);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(modalMap);
            
            // Add area polygon
            const areaPolygon = L.polygon(request.area_coordinates, {
                color: '#0a8806',
                weight: 2,
                opacity: 0.8,
                fillOpacity: 0.2
            }).addTo(modalMap);
            
            // Add building polygon
            const buildingPolygon = L.polygon(request.coordinates, {
                color: '#ff7800',
                weight: 2,
                opacity: 0.8,
                fillOpacity: 0.3
            }).addTo(modalMap);
            
            // Fit map to show both polygons
            modalMap.fitBounds(areaPolygon.getBounds());
            
            // Force a refresh after a short delay
            setTimeout(() => {
                modalMap.invalidateSize();
            }, 200);
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('requestModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('requestModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html> 