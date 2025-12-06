<?php
require_once '../config.php';
require_once '../auth-helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify admin authentication
$admin = verifyAdminToken($pdo);
if (!$admin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Admin access required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$voter_id = $data['voter_id'] ?? 0;

if (empty($voter_id)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID is required']);
    exit;
}

try {
    // Check if voter exists
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ? AND role = 'voter'");
    $stmt->execute([$voter_id]);
    $voter = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$voter) {
        echo json_encode(['success' => false, 'message' => 'Voter not found']);
        exit;
    }
    
    // Check if voter has voted in any election
    $voteCheck = $pdo->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE user_id = ?");
    $voteCheck->execute([$voter_id]);
    $voteResult = $voteCheck->fetch(PDO::FETCH_ASSOC);
    
    $hasVoted = $voteResult['vote_count'] > 0;
    
    // Start transaction for atomic deletion
    $pdo->beginTransaction();
    
    try {
        // 1. Delete voter's face image file if exists
        $faceStmt = $pdo->prepare("SELECT face_image FROM users WHERE id = ?");
        $faceStmt->execute([$voter_id]);
        $faceData = $faceStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($faceData && !empty($faceData['face_image'])) {
            $faceImagePath = __DIR__ . '/../uploads/faces/' . basename($faceData['face_image']);
            if (file_exists($faceImagePath)) {
                @unlink($faceImagePath);
            }
        }
        
        // 2. Delete from election_voter_authorization (if table exists)
        try {
            $authDelete = $pdo->prepare("DELETE FROM election_voter_authorization WHERE user_id = ?");
            $authDelete->execute([$voter_id]);
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 3. Delete from election_voter_list (if email matches - this is for the new voter list system)
        // Note: election_voter_list doesn't have user_id, it uses email
        try {
            $listDelete = $pdo->prepare("DELETE FROM election_voter_list WHERE email = ?");
            $listDelete->execute([$voter['email']]);
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 4. Delete from password_resets (will cascade if FK exists, but explicit for clarity)
        try {
            $passResetDelete = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $passResetDelete->execute([$voter_id]);
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 5. Delete from blockchain_votes (if table exists)
        try {
            $blockchainDelete = $pdo->prepare("DELETE FROM blockchain_votes WHERE user_id = ?");
            $blockchainDelete->execute([$voter_id]);
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 6. Delete votes (will cascade if FK exists, but explicit for clarity)
        // Note: If voter has voted, their votes will be deleted
        try {
            $votesDelete = $pdo->prepare("DELETE FROM votes WHERE user_id = ?");
            $votesDelete->execute([$voter_id]);
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 7. Finally, delete the voter from users table
        // This will cascade delete any remaining related records via foreign keys
        $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'voter'");
        $deleteStmt->execute([$voter_id]);
        
        if ($deleteStmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to delete voter - no rows affected']);
            exit;
        }
        
        // Commit transaction
        $pdo->commit();
        
        $message = 'Voter deleted successfully from database';
        if ($hasVoted) {
            $message .= '. Note: All votes cast by this voter have also been deleted.';
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'deleted_voter' => [
                'id' => $voter_id,
                'name' => $voter['name'],
                'email' => $voter['email']
            ],
            'related_data_deleted' => [
                'votes' => $hasVoted,
                'face_image' => !empty($faceData['face_image']),
                'authorization_records' => true,
                'password_resets' => true
            ]
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error deleting voter: ' . $e->getMessage()]);
}
?>

