<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get all voters (users with role = 'voter')
    $stmt = $pdo->query("
        SELECT id, name, email, role, verified, authorized, created_at
        FROM users
        WHERE role = 'voter'
        ORDER BY created_at DESC
    ");
    $voters = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'voters' => $voters
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching voters: ' . $e->getMessage()
    ]);
}
?>

