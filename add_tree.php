<?php
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'treemap');

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]));
}

// Check if the request method is POST and it's multipart/form-data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $type = $_POST['type'] ?? null;
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;
    $description = $_POST['description'] ?? null;
    $userLat = $_POST['userLat'] ?? null;
    $userLng = $_POST['userLng'] ?? null;
    $accuracy = $_POST['accuracy'] ?? null;
    $user_id = $_POST['user_id'] ?? null; // Add this line

    // Validate required fields
    if ($type === null || $lat === null || $lng === null || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields or photo upload failed.']);
        exit;
    }

    $uploadDir = 'uploads/'; // Directory to save photos
    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $photo = $_FILES['photo'];
    $photoFileName = uniqid() . '_' . basename($photo['name']);
    $photoFilePath = $uploadDir . $photoFileName;

    // Move the uploaded file
    if (move_uploaded_file($photo['tmp_name'], $photoFilePath)) {
        // File uploaded successfully, now insert data into database
        $stmt = $conn->prepare('INSERT INTO trees (type, lat, lng, description, photo_path, status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $status = 'pending';
        $stmt->bind_param('sddssss', $type, $lat, $lng, $description, $photoFilePath, $status, $_POST['user_id']);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id, 'type' => $type, 'lat' => $lat, 'lng' => $lng, 'description' => $description, 'photo_path' => $photoFilePath, 'status' => $status]);
        } else {
            // If database insert fails, you might want to delete the uploaded file
            unlink($photoFilePath);
            echo json_encode(['success' => false, 'message' => 'Database insert failed: ' . $stmt->error]);
        }

        $stmt->close();

    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing photo.']);
}

$conn->close();
?>