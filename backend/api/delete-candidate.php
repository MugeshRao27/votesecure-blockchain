<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? 0;

if (empty($id) || !is_numeric($id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid candidate ID']);
    exit;
}

try {
    // Get candidate photo path before deletion
    $stmt = $pdo->prepare("SELECT photo FROM candidates WHERE id = ?");
    $stmt->execute([$id]);
    $candidate = $stmt->fetch();

    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit;
    }

    // Delete candidate
    $stmt = $pdo->prepare("DELETE FROM candidates WHERE id = ?");
    if ($stmt->execute([$id])) {
        // Delete photo file if exists
        if (!empty($candidate['photo'])) {
            $photo_path = dirname(__DIR__) . '/' . $candidate['photo'];
            if (file_exists($photo_path)) {
                unlink($photo_path);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Candidate deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete candidate']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error deleting candidate: ' . $e->getMessage()]);
}
?>

