<?php
/**
 * Vote Verification Utility
 * Allows verification of vote integrity and blockchain records
 */

require_once 'config.php';
require_once 'auth-helper.php';
require_once 'encryption-helper.php';
require_once __DIR__ . '/../blockchain-helper.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require authentication
$authUser = requireAuth();

// Get parameters
$election_id = isset($_GET['election_id']) ? intval($_GET['election_id']) : 0;
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Admin can verify any vote, users can only verify their own
if ($authUser['role'] !== 'admin' && $authUser['user_id'] != $user_id) {
    $user_id = $authUser['user_id']; // Force to own user_id
}

if (empty($election_id) || empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'Election ID and User ID are required']);
    exit;
}

try {
    $encryptionHelper = new EncryptionHelper();
    $blockchainHelper = new BlockchainHelper($pdo);
    
    // Get vote from database
    $checkColumn = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'votes' 
        AND COLUMN_NAME IN ('user_id', 'voter_id')
        LIMIT 1
    ");
    $columnInfo = $checkColumn->fetch(PDO::FETCH_ASSOC);
    $userIdColumn = $columnInfo['COLUMN_NAME'] ?? 'user_id';
    
    $stmt = $pdo->prepare("
        SELECT 
            {$userIdColumn} as user_id,
            candidate_id,
            election_id,
            encrypted_data,
            encryption_iv,
            encryption_tag,
            vote_hash,
            blockchain_tx_hash,
            created_at
        FROM votes 
        WHERE {$userIdColumn} = ? AND election_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $election_id]);
    $vote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vote) {
        echo json_encode([
            'success' => false,
            'message' => 'Vote not found'
        ]);
        exit;
    }
    
    $verification = [
        'success' => true,
        'vote_found' => true,
        'election_id' => $vote['election_id'],
        'candidate_id' => $vote['candidate_id'],
        'user_id' => $vote['user_id'],
        'created_at' => $vote['created_at']
    ];
    
    // Verify encryption
    if (!empty($vote['encrypted_data']) && !empty($vote['encryption_iv']) && !empty($vote['encryption_tag'])) {
        try {
            $decryptedVote = $encryptionHelper->decryptVote([
                'encrypted_data' => $vote['encrypted_data'],
                'iv' => $vote['encryption_iv'],
                'tag' => $vote['encryption_tag']
            ]);
            
            // Verify decrypted data matches stored data
            $verification['encryption'] = [
                'status' => 'valid',
                'decrypted' => [
                    'election_id' => $decryptedVote['election_id'],
                    'candidate_id' => $decryptedVote['candidate_id'],
                    'user_id' => $decryptedVote['user_id']
                ],
                'matches' => (
                    $decryptedVote['election_id'] == $vote['election_id'] &&
                    $decryptedVote['candidate_id'] == $vote['candidate_id'] &&
                    $decryptedVote['user_id'] == $vote['user_id']
                )
            ];
        } catch (Exception $e) {
            $verification['encryption'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    } else {
        $verification['encryption'] = [
            'status' => 'not_encrypted',
            'message' => 'Vote was stored before encryption was implemented'
        ];
    }
    
    // Verify vote hash
    if (!empty($vote['vote_hash'])) {
        $expectedHash = $encryptionHelper->generateVoteHash(
            $vote['election_id'],
            $vote['candidate_id'],
            $vote['user_id'],
            strtotime($vote['created_at'])
        );
        
        $verification['hash'] = [
            'stored_hash' => $vote['vote_hash'],
            'calculated_hash' => $expectedHash,
            'matches' => hash_equals($vote['vote_hash'], $expectedHash)
        ];
    }
    
    // Verify blockchain record
    if (!empty($vote['blockchain_tx_hash'])) {
        $verification['blockchain'] = [
            'transaction_hash' => $vote['blockchain_tx_hash'],
            'status' => 'recorded',
            'message' => 'Vote hash recorded on blockchain'
        ];
        
        // Try to verify on blockchain (if configured)
        try {
            // Get user's blockchain address if available
            $userStmt = $pdo->prepare("SELECT blockchain_address FROM users WHERE id = ?");
            $userStmt->execute([$user_id]);
            $user = $userStmt->fetch();
            
            if (!empty($user['blockchain_address'])) {
                $blockchainVerify = $blockchainHelper->verifyVote($user['blockchain_address'], $election_id);
                if ($blockchainVerify['success']) {
                    $verification['blockchain']['verified_on_chain'] = $blockchainVerify['vote_recorded'];
                }
            }
        } catch (Exception $e) {
            $verification['blockchain']['verification_error'] = $e->getMessage();
        }
    } else {
        $verification['blockchain'] = [
            'status' => 'not_recorded',
            'message' => 'Vote hash not recorded on blockchain (blockchain may not be configured)'
        ];
    }
    
    // Overall integrity check
    $integrityPassed = true;
    if (isset($verification['encryption']['matches']) && !$verification['encryption']['matches']) {
        $integrityPassed = false;
    }
    if (isset($verification['hash']['matches']) && !$verification['hash']['matches']) {
        $integrityPassed = false;
    }
    
    $verification['integrity'] = [
        'status' => $integrityPassed ? 'passed' : 'failed',
        'message' => $integrityPassed ? 'Vote integrity verified' : 'Vote integrity check failed'
    ];
    
    echo json_encode($verification);
    
} catch (Exception $e) {
    error_log("Vote verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error verifying vote: ' . $e->getMessage()
    ]);
}

