<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Handle form data (multipart/form-data)
$id = $_POST['id'] ?? 0;
$name = trim($_POST['name'] ?? '');
$position = trim($_POST['position'] ?? '');
$party = trim($_POST['party'] ?? '');
$bio = trim($_POST['bio'] ?? '');
$electionId = $_POST['electionId'] ?? 0;

// Validation
if (empty($id) || !is_numeric($id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid candidate ID']);
    exit;
}

if (empty($name) || empty($position) || empty($electionId)) {
    echo json_encode(['success' => false, 'message' => 'Name, position, and election are required']);
    exit;
}

// Validate election exists
try {
    $stmt = $pdo->prepare("SELECT id FROM elections WHERE id = ?");
    $stmt->execute([$electionId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Check if candidate exists
try {
    $stmt = $pdo->prepare("SELECT id, photo FROM candidates WHERE id = ?");
    $stmt->execute([$id]);
    $candidate = $stmt->fetch();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit;
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Handle photo upload (optional - only update if new file provided)
$photo_path = $candidate['photo']; // Keep existing photo by default

if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = dirname(__DIR__) . '/uploads/candidates/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (!in_array($file_extension, $allowed_extensions)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only images are allowed']);
        exit;
    }

    // Delete old photo if exists
    if (!empty($candidate['photo']) && file_exists(dirname(__DIR__) . '/' . $candidate['photo'])) {
        @unlink(dirname(__DIR__) . '/' . $candidate['photo']);
    }

    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $target_path = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
        $photo_path = 'uploads/candidates/' . $filename;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload photo']);
        exit;
    }
}

// Update candidate
try {
    $stmt = $pdo->prepare("UPDATE candidates SET name = ?, position = ?, party = ?, bio = ?, election_id = ?, photo = ? WHERE id = ?");
    
    if ($stmt->execute([$name, $position, $party, $bio, $electionId, $photo_path, $id])) {
        // Fetch updated candidate
        $stmt = $pdo->prepare("
            SELECT c.*, e.title as election_title 
            FROM candidates c 
            LEFT JOIN elections e ON c.election_id = e.id 
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $updatedCandidate = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Candidate updated successfully',
            'candidate' => $updatedCandidate
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update candidate']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating candidate: ' . $e->getMessage()]);
}
?>

