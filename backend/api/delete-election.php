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
    echo json_encode(['success' => false, 'message' => 'Invalid election ID']);
    exit;
}

try {
    // Check if election exists
    $stmt = $pdo->prepare("SELECT id FROM elections WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }

    // Delete election (candidates and votes will be deleted due to CASCADE)
    $stmt = $pdo->prepare("DELETE FROM elections WHERE id = ?");
    if ($stmt->execute([$id])) {
        echo json_encode(['success' => true, 'message' => 'Election deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete election']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error deleting election: ' . $e->getMessage()]);
}
?>

