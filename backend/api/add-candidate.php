<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Handle form data (multipart/form-data)
$name = trim($_POST['name'] ?? '');
$position = trim($_POST['position'] ?? '');
$party = trim($_POST['party'] ?? '');
$bio = trim($_POST['bio'] ?? '');
$electionId = $_POST['electionId'] ?? 0;

// Validation
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

// Handle photo upload
$photo_path = '';
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

try {
    $stmt = $pdo->prepare("INSERT INTO candidates (name, position, party, bio, photo, election_id) VALUES (?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$name, $position, $party, $bio, $photo_path, $electionId])) {
        $candidate_id = $pdo->lastInsertId();
        
        // Fetch created candidate
        $stmt = $pdo->prepare("
            SELECT c.*, e.title as election_title
            FROM candidates c
            LEFT JOIN elections e ON c.election_id = e.id
            WHERE c.id = ?
        ");
        $stmt->execute([$candidate_id]);
        $candidate = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Candidate added successfully',
            'candidate' => $candidate
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add candidate']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error adding candidate: ' . $e->getMessage()]);
}
?>

