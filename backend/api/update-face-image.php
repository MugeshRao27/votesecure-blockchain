<?php
require_once 'config.php';
require_once 'auth-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require authentication (JWT)
$authUser = requireAuth('voter');

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $authUser['user_id'];
$face_image = $data['face_image'] ?? '';

if (empty($face_image)) {
    echo json_encode(['success' => false, 'message' => 'Face image is required']);
    exit;
}

try {
    // Verify user exists and is a voter
    $userCheck = $pdo->prepare("SELECT id, email FROM users WHERE id = ? AND role = 'voter'");
    $userCheck->execute([$user_id]);
    $user = $userCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Handle face image upload
    // Standard path: backend/api/uploads/faces/
    $uploadDir = __DIR__ . '/uploads/faces/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Convert base64 to image file
    $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $face_image));
    if ($imageData === false) {
        echo json_encode(['success' => false, 'message' => 'Invalid face image data']);
        exit;
    }
    
    // Verify it's actually image data
    $imageInfo = @getimagesizefromstring($imageData);
    if ($imageInfo === false) {
        echo json_encode(['success' => false, 'message' => 'Invalid image data - not a valid image file']);
        exit;
    }
    
    // Delete old face image if exists
    $oldFaceStmt = $pdo->prepare("SELECT face_image FROM users WHERE id = ?");
    $oldFaceStmt->execute([$user_id]);
    $oldFace = $oldFaceStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($oldFace && !empty($oldFace['face_image'])) {
        $oldPath = __DIR__ . '/uploads/faces/' . basename($oldFace['face_image']);
        $oldLegacyPath = dirname(__DIR__) . '/uploads/faces/' . basename($oldFace['face_image']);
        
        if (file_exists($oldPath)) {
            @unlink($oldPath);
        } elseif (file_exists($oldLegacyPath)) {
            @unlink($oldLegacyPath);
        }
    }
    
    // Save new face image
    $fileName = 'face_' . uniqid() . '.jpg';
    $filePath = $uploadDir . $fileName;
    
    if (file_put_contents($filePath, $imageData) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to save face image']);
        exit;
    }
    
    // Store relative path in database: uploads/faces/filename.jpg
    $faceImagePath = 'uploads/faces/' . $fileName;
    
    // Update user's face image
    $updateStmt = $pdo->prepare("UPDATE users SET face_image = ? WHERE id = ?");
    if ($updateStmt->execute([$faceImagePath, $user_id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Face image updated successfully',
            'face_image_path' => $faceImagePath
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update face image']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating face image: ' . $e->getMessage()]);
}
?>

