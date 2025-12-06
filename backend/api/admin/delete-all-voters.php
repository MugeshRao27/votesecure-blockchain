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

// Require explicit confirmation
$data = json_decode(file_get_contents('php://input'), true);
$confirm = $data['confirm'] ?? false;

if ($confirm !== true) {
    echo json_encode([
        'success' => false, 
        'message' => 'Confirmation required. Set confirm to true to proceed.',
        'requires_confirmation' => true
    ]);
    exit;
}

try {
    // Get all voters first for reporting
    $votersStmt = $pdo->prepare("SELECT id, name, email, face_image FROM users WHERE role = 'voter'");
    $votersStmt->execute();
    $allVoters = $votersStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalVoters = count($allVoters);
    
    if ($totalVoters === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'No voters to delete',
            'deleted_count' => 0
        ]);
        exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    $deletedFaceImages = 0;
    $deletedVotes = 0;
    $deletedAuthRecords = 0;
    $deletedVoterListEntries = 0;
    
    try {
        // 1. Delete all face images
        foreach ($allVoters as $voter) {
            if (!empty($voter['face_image'])) {
                $faceImagePath = __DIR__ . '/../uploads/faces/' . basename($voter['face_image']);
                if (file_exists($faceImagePath)) {
                    @unlink($faceImagePath);
                    $deletedFaceImages++;
                }
            }
        }
        
        // 2. Delete from election_voter_authorization (if table exists)
        try {
            $authDelete = $pdo->prepare("DELETE FROM election_voter_authorization WHERE user_id IN (SELECT id FROM users WHERE role = 'voter')");
            $authDelete->execute();
            $deletedAuthRecords = $authDelete->rowCount();
        } catch (PDOException $e) {
            // Table might not exist, try alternative approach
            try {
                $voterIds = array_column($allVoters, 'id');
                if (!empty($voterIds)) {
                    $placeholders = implode(',', array_fill(0, count($voterIds), '?'));
                    $authDelete = $pdo->prepare("DELETE FROM election_voter_authorization WHERE user_id IN ($placeholders)");
                    $authDelete->execute($voterIds);
                    $deletedAuthRecords = $authDelete->rowCount();
                }
            } catch (PDOException $e2) {
                // Table doesn't exist, continue
            }
        }
        
        // 3. Delete from election_voter_list (delete by matching emails)
        try {
            $emails = array_column($allVoters, 'email');
            if (!empty($emails)) {
                $placeholders = implode(',', array_fill(0, count($emails), '?'));
                $listDelete = $pdo->prepare("DELETE FROM election_voter_list WHERE email IN ($placeholders)");
                $listDelete->execute($emails);
                $deletedVoterListEntries = $listDelete->rowCount();
            }
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 4. Delete from password_resets
        try {
            $voterIds = array_column($allVoters, 'id');
            if (!empty($voterIds)) {
                $placeholders = implode(',', array_fill(0, count($voterIds), '?'));
                $passResetDelete = $pdo->prepare("DELETE FROM password_resets WHERE user_id IN ($placeholders)");
                $passResetDelete->execute($voterIds);
            }
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 5. Delete from blockchain_votes
        try {
            $voterIds = array_column($allVoters, 'id');
            if (!empty($voterIds)) {
                $placeholders = implode(',', array_fill(0, count($voterIds), '?'));
                $blockchainDelete = $pdo->prepare("DELETE FROM blockchain_votes WHERE user_id IN ($placeholders)");
                $blockchainDelete->execute($voterIds);
            }
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 6. Count votes before deletion
        try {
            $voterIds = array_column($allVoters, 'id');
            if (!empty($voterIds)) {
                $placeholders = implode(',', array_fill(0, count($voterIds), '?'));
                $voteCountStmt = $pdo->prepare("SELECT COUNT(*) as count FROM votes WHERE user_id IN ($placeholders)");
                $voteCountStmt->execute($voterIds);
                $voteCountResult = $voteCountStmt->fetch(PDO::FETCH_ASSOC);
                $deletedVotes = $voteCountResult['count'] ?? 0;
                
                // Delete votes
                $votesDelete = $pdo->prepare("DELETE FROM votes WHERE user_id IN ($placeholders)");
                $votesDelete->execute($voterIds);
            }
        } catch (PDOException $e) {
            // Table might not exist, continue
        }
        
        // 7. Finally, delete all voters from users table
        $deleteStmt = $pdo->prepare("DELETE FROM users WHERE role = 'voter'");
        $deleteStmt->execute();
        $deletedVoters = $deleteStmt->rowCount();
        
        if ($deletedVoters === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'No voters were deleted']);
            exit;
        }
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully deleted {$deletedVoters} voter(s) and all related data",
            'deleted_count' => $deletedVoters,
            'related_data_deleted' => [
                'votes' => $deletedVotes,
                'face_images' => $deletedFaceImages,
                'authorization_records' => $deletedAuthRecords,
                'voter_list_entries' => $deletedVoterListEntries
            ]
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error deleting voters: ' . $e->getMessage()]);
}
?>

