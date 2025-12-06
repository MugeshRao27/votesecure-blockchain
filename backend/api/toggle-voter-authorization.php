<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$voter_id = $data['voter_id'] ?? 0;
$authorized = $data['authorized'] ?? false;

if (empty($voter_id)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID is required']);
    exit;
}

try {
    // Check if voter exists
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'voter'");
    $stmt->execute([$voter_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Voter not found']);
        exit;
    }

    // Update authorization status
    $stmt = $pdo->prepare("UPDATE users SET authorized = ? WHERE id = ?");
    if ($stmt->execute([$authorized ? 1 : 0, $voter_id])) {
        echo json_encode([
            'success' => true,
            'message' => $authorized ? 'Voter authorized successfully' : 'Voter authorization revoked',
            'authorized' => $authorized
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update authorization']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating authorization: ' . $e->getMessage()]);
}
?>

