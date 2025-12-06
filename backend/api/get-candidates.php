<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get candidates with election title
    $stmt = $pdo->query("
        SELECT c.*, e.title as election_title
        FROM candidates c
        LEFT JOIN elections e ON c.election_id = e.id
        ORDER BY c.created_at DESC
    ");
    $candidates = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'candidates' => $candidates
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching candidates: ' . $e->getMessage()
    ]);
}
?>

