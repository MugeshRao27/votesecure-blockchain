<?php
require_once '../../config.php';
require_once '../../auth-helper.php';
require_once '../../blockchain-helper.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

// Get election ID from query parameters
$electionId = isset($_GET['election_id']) ? (int)$_GET['election_id'] : 0;

// If no election ID provided, check all elections
if ($electionId <= 0) {
    // Get all active elections
    $stmt = $pdo->query("
        SELECT id, title, start_date, end_date 
        FROM elections 
        WHERE status = 'active' AND end_date >= NOW()
    ");
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check voting status for each election
    $result = [];
    foreach ($elections as $election) {
        $hasVoted = $this->checkVoteStatus($pdo, $user['id'], $election['id']);
        $result[] = [
            'election_id' => $election['id'],
            'title' => $election['title'],
            'has_voted' => $hasVoted,
            'start_date' => $election['start_date'],
            'end_date' => $election['end_date']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    exit;
}

// Check status for a specific election
try {
    // Get election details
    $stmt = $pdo->prepare("
        SELECT id, title, start_date, end_date 
        FROM elections 
        WHERE id = ?
    ");
    $stmt->execute([$electionId]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
    
    // Check if user has voted in this election
    $hasVoted = checkVoteStatus($pdo, $user['id'], $electionId);
    
    // Get vote details if user has voted
    $voteDetails = null;
    if ($hasVoted) {
        $stmt = $pdo->prepare("
            SELECT v.*, c.name as candidate_name
            FROM votes v
            JOIN candidates c ON v.candidate_id = c.id
            WHERE v.user_id = ? AND v.election_id = ?
        ");
        $stmt->execute([$user['id'], $electionId]);
        $voteDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify vote on blockchain if needed
        if ($voteDetails && empty($voteDetails['blockchain_verified'])) {
            $blockchain = new BlockchainHelper($pdo);
            $verification = $blockchain->verifyVote($user['id'], $electionId);
            
            if ($verification['success'] && $verification['vote_recorded']) {
                // Update database with blockchain verification
                $stmt = $pdo->prepare("
                    UPDATE votes 
                    SET blockchain_verified = 1, 
                        verified_at = NOW() 
                    WHERE user_id = ? AND election_id = ?
                ");
                $stmt->execute([$user['id'], $electionId]);
                $voteDetails['blockchain_verified'] = true;
            } else {
                $voteDetails['blockchain_verified'] = false;
                $voteDetails['verification_error'] = $verification['message'] ?? 'Verification failed';
            }
        }
    }
    
    // Return response
    echo json_encode([
        'success' => true,
        'data' => [
            'election_id' => $election['id'],
            'title' => $election['title'],
            'has_voted' => $hasVoted,
            'start_date' => $election['start_date'],
            'end_date' => $election['end_date'],
            'vote_details' => $voteDetails
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Vote status check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while checking vote status.',
        'code' => 'INTERNAL_ERROR'
    ]);
}

/**
 * Check if a user has voted in a specific election
 */
function checkVoteStatus($pdo, $userId, $electionId) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM votes 
        WHERE user_id = ? AND election_id = ?
    ");
    $stmt->execute([$userId, $electionId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result && $result['count'] > 0;
}
