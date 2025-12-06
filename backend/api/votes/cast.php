<?php
require_once '../../config.php';
require_once '../../auth-helper.php';
require_once '../../blockchain-helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify authentication
$user = verifyToken($pdo);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Only voters can cast votes
if ($user['role'] !== 'voter') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only voters can cast votes']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$electionId = (int)($data['election_id'] ?? 0);
$candidateId = (int)($data['candidate_id'] ?? 0);

// Validate input
if ($electionId <= 0 || $candidateId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid election or candidate']);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Check if user has already voted in this election
    $stmt = $pdo->prepare("
        SELECT id FROM votes 
        WHERE user_id = ? AND election_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$user['id'], $electionId]);
    
    if ($stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'You have already voted in this election',
            'code' => 'ALREADY_VOTED'
        ]);
        exit;
    }
    
    // Check if election is active
    $stmt = $pdo->prepare("
        SELECT id FROM elections 
        WHERE id = ? AND status = 'active' 
        AND start_date <= NOW() AND end_date >= NOW()
    ");
    $stmt->execute([$electionId]);
    
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'Election is not active or does not exist',
            'code' => 'INACTIVE_ELECTION'
        ]);
        exit;
    }
    
    // Check if candidate exists in this election
    $stmt = $pdo->prepare("
        SELECT id FROM candidates 
        WHERE id = ? AND election_id = ?
    ");
    $stmt->execute([$candidateId, $electionId]);
    
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid candidate for this election',
            'code' => 'INVALID_CANDIDATE'
        ]);
        exit;
    }
    
    // Record vote in the database
    $stmt = $pdo->prepare("
        INSERT INTO votes (user_id, election_id, candidate_id, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $electionId, $candidateId]);
    
    // Update user's has_voted status
    $stmt = $pdo->prepare("UPDATE users SET has_voted = 1 WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Record vote on the blockchain
    $blockchain = new BlockchainHelper($pdo);
    $blockchainResult = $blockchain->recordVote($user['id'], $electionId, $candidateId);
    
    if (!$blockchainResult['success']) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to record vote on blockchain: ' . ($blockchainResult['message'] ?? 'Unknown error'),
            'code' => 'BLOCKCHAIN_ERROR'
        ]);
        exit;
    }
    
    // Log the vote
    logVoterActivity(
        $pdo, 
        $user['id'], 
        'vote_cast', 
        'success', 
        "Voted for candidate $candidateId in election $electionId"
    );
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Vote recorded successfully',
        'transaction_hash' => $blockchainResult['transaction_hash'] ?? null,
        'blockchain_address' => $blockchainResult['blockchain_address'] ?? null
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Vote casting error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your vote. Please try again.',
        'code' => 'INTERNAL_ERROR'
    ]);
}
