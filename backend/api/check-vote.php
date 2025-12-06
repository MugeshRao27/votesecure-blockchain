<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$voter_id = $_GET['voter_id'] ?? 0;
$election_id = $_GET['election_id'] ?? 0;

if (empty($voter_id) || empty($election_id)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID and election ID are required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM votes WHERE voter_id = ? AND election_id = ?");
    $stmt->execute([$voter_id, $election_id]);
    $hasVoted = $stmt->fetch() !== false;

    echo json_encode([
        'success' => true,
        'hasVoted' => $hasVoted
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error checking vote: ' . $e->getMessage()
    ]);
}
?>

