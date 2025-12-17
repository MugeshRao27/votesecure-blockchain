<?php
require_once 'config.php';
require_once 'auth-helper.php';
require_once __DIR__ . '/encryption-helper.php';
require_once __DIR__ . '/../blockchain-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require authentication (JWT)
$authUser = requireAuth('voter');

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $authUser['user_id']; // Use authenticated user ID from JWT
$election_id = $data['election_id'] ?? 0;
$candidate_id = $data['candidate_id'] ?? 0;
$providedTxHash = $data['blockchain_tx_hash'] ?? null;
$providedVoteHash = $data['vote_hash'] ?? null;

if (empty($election_id) || empty($candidate_id)) {
    echo json_encode(['success' => false, 'message' => 'Election and candidate are required']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ========================================
    // CHECK 1: User must be authorized (is_authorized = 1)
    // ========================================
    $userCheck = $pdo->prepare("SELECT id, authorized, face_image FROM users WHERE id = ?");
    $userCheck->execute([$user_id]);
    $user = $userCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    if (!isset($user['authorized']) || intval($user['authorized']) !== 1) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'You are not authorized to vote. Please wait for admin approval.',
            'authorized' => false
        ]);
        exit;
    }
    
    // Check if user has face image (required for voting)
    // First check if face_image field exists in database
    if (empty($user['face_image'])) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'Face image not found. Please contact administrator to add your face image, or complete your registration with face capture.',
            'error_code' => 'FACE_IMAGE_MISSING'
        ]);
        exit;
    }
    
    // Verify the face image file actually exists on the server
    $faceImagePath = $user['face_image'];
    
    // Try standard path first: backend/api/uploads/faces/
    $standardPath = __DIR__ . '/../uploads/faces/' . basename($faceImagePath);
    
    // Try legacy path: backend/uploads/faces/
    $legacyPath = dirname(__DIR__) . '/uploads/faces/' . basename($faceImagePath);
    
    // Check if file exists in either location
    $fileExists = false;
    $actualPath = '';
    
    if (file_exists($standardPath)) {
        $fileExists = true;
        $actualPath = $standardPath;
    } elseif (file_exists($legacyPath)) {
        $fileExists = true;
        $actualPath = $legacyPath;
    } elseif (file_exists($faceImagePath)) {
        // Try as absolute path
        $fileExists = true;
        $actualPath = $faceImagePath;
    }
    
    if (!$fileExists) {
        $pdo->rollBack();
        error_log("Face image file not found for user {$user_id}. Database path: {$faceImagePath}. Checked: {$standardPath}, {$legacyPath}");
        echo json_encode([
            'success' => false, 
            'message' => 'Face image file not found on server. Please contact administrator to update your face image.',
            'error_code' => 'FACE_IMAGE_FILE_MISSING'
        ]);
        exit;
    }
    
    // Verify it's a valid image file
    $imageInfo = @getimagesize($actualPath);
    if ($imageInfo === false || filesize($actualPath) < 100) {
        $pdo->rollBack();
        error_log("Face image file is invalid or corrupted for user {$user_id}. Path: {$actualPath}");
        echo json_encode([
            'success' => false, 
            'message' => 'Face image file is invalid or corrupted. Please contact administrator to update your face image.',
            'error_code' => 'FACE_IMAGE_INVALID'
        ]);
        exit;
    }

    // ========================================
    // CHECK 2: Election-Voter Authorization Mapping
    // Check both election_voter_authorization AND eligible_voters tables
    // ========================================
    $isAuthorized = false;
    $hasVoted = false;
    
    // First, check election_voter_authorization table (legacy system)
    $auth = $pdo->prepare("SELECT authorized, has_voted FROM election_voter_authorization WHERE user_id = ? AND election_id = ? FOR UPDATE");
    $auth->execute([$user_id, $election_id]);
    $authRow = $auth->fetch(PDO::FETCH_ASSOC);
    
    if ($authRow) {
        $isAuthorized = (intval($authRow['authorized']) === 1);
        $hasVoted = (intval($authRow['has_voted']) === 1);
    }
    
    // If not found in election_voter_authorization, check eligible_voters table (new system)
    if (!$isAuthorized) {
        try {
            // Get voter's email
            $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $emailStmt->execute([$user_id]);
            $userData = $emailStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userData) {
                $voter_email = strtolower(trim($userData['email']));
                
                // Check eligible_voters table
                $checkTable = $pdo->query("SHOW TABLES LIKE 'eligible_voters'");
                if ($checkTable->rowCount() > 0) {
                    $eligibleStmt = $pdo->prepare("
                        SELECT id, active, has_registered 
                        FROM eligible_voters 
                        WHERE election_id = ? 
                          AND LOWER(TRIM(email)) = ? 
                          AND active = 1
                    ");
                    $eligibleStmt->execute([$election_id, $voter_email]);
                    $eligibleRow = $eligibleStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($eligibleRow) {
                        // Voter is eligible - they can vote
                        $isAuthorized = true;
                        error_log("✅ Voter authorized via eligible_voters table for election $election_id");
                    }
                }
                
                // Also check election_voter_list table
                $checkTable = $pdo->query("SHOW TABLES LIKE 'election_voter_list'");
                if ($checkTable->rowCount() > 0 && !$isAuthorized) {
                    $listStmt = $pdo->prepare("
                        SELECT id 
                        FROM election_voter_list 
                        WHERE election_id = ? 
                          AND LOWER(TRIM(email)) = ?
                    ");
                    $listStmt->execute([$election_id, $voter_email]);
                    $listRow = $listStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($listRow) {
                        // Voter is in CSV list - they can vote
                        $isAuthorized = true;
                        error_log("✅ Voter authorized via election_voter_list table for election $election_id");
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Error checking eligible_voters/election_voter_list: " . $e->getMessage());
        }
    }
    
    if (!$isAuthorized) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'You are not authorized to vote in this election. Please contact the administrator.']);
        exit;
    }
    
    // ========================================
    // CHECK 3: Has not already voted (check votes table)
    // ========================================
    $voteCheck = $pdo->prepare("SELECT id FROM votes WHERE user_id = ? AND election_id = ?");
    $voteCheck->execute([$user_id, $election_id]);
    if ($voteCheck->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'You have already voted in this election']);
        exit;
    }

    // ========================================
    // CHECK 4: Election is active
    // ========================================
    $estmt = $pdo->prepare("SELECT start_date, end_date, status FROM elections WHERE id = ?");
    $estmt->execute([$election_id]);
    $e = $estmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
    
    // Use MySQL NOW() for accurate server time comparison
    // This ensures we're comparing in the same timezone as the database
    // Note: Using backticks around 'current_time' alias since it's a MySQL reserved keyword
    $timeCheck = $pdo->prepare("
        SELECT 
            CASE 
                WHEN NOW() >= start_date AND NOW() <= end_date THEN 1
                ELSE 0
            END as is_active,
            start_date,
            end_date,
            NOW() as `current_time`
        FROM elections 
        WHERE id = ?
    ");
    $timeCheck->execute([$election_id]);
    $timeResult = $timeCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$timeResult || intval($timeResult['is_active']) !== 1) {
        $pdo->rollBack();
        $startFormatted = date('Y-m-d H:i:s', strtotime($e['start_date']));
        $endFormatted = date('Y-m-d H:i:s', strtotime($e['end_date']));
        $currentFormatted = $timeResult['current_time'] ?? date('Y-m-d H:i:s');
        
        $message = 'Election is not currently active. ';
        if (strtotime($currentFormatted) < strtotime($startFormatted)) {
            $message .= "Election starts at: " . $startFormatted;
        } else {
            $message .= "Election ended at: " . $endFormatted . ". Current time: " . $currentFormatted;
        }
        
        echo json_encode([
            'success' => false, 
            'message' => $message,
            'debug' => [
                'start_date' => $startFormatted,
                'end_date' => $endFormatted,
                'current_time' => $currentFormatted,
                'is_active' => $timeResult['is_active'] ?? 0
            ]
        ]);
        exit;
    }

    // ========================================
    // CHECK 5: Candidate belongs to election
    // ========================================
    $cstmt = $pdo->prepare("SELECT id FROM candidates WHERE id = ? AND election_id = ?");
    $cstmt->execute([$candidate_id, $election_id]);
    if (!$cstmt->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Candidate does not belong to this election']);
        exit;
    }

    // ========================================
    // NOTE: Face verification should be done BEFORE calling this endpoint
    // The verify-face.php endpoint handles face comparison
    // ========================================

    // ========================================
    // All checks passed - Cast vote
    // ========================================
    
    // Initialize encryption and blockchain helpers
    $encryptionHelper = new EncryptionHelper();
    $blockchainHelper = new BlockchainHelper($pdo);
    
    // Prepare vote data for encryption
    $voteData = [
        'election_id' => $election_id,
        'candidate_id' => $candidate_id,
        'user_id' => $user_id,
        'timestamp' => time(),
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Encrypt vote data
    $encryptedVote = $encryptionHelper->encryptVote($voteData);
    
    // Use provided vote hash/tx hash from frontend if available (MetaMask flow), else generate and attempt backend chain write
    $voteHash = $providedVoteHash ?: $encryptionHelper->generateVoteHash($election_id, $candidate_id, $user_id);

    if (!empty($providedTxHash)) {
        // Frontend already recorded on-chain via MetaMask
        $blockchainResult = [
            'success' => true,
            'transaction_hash' => $providedTxHash,
            'vote_hash' => $voteHash,
            'skipped' => true
        ];
    } else {
        // Record vote on the blockchain from backend (may be skipped if not configured)
        $blockchainResult = $blockchainHelper->recordVote($user_id, $election_id, $candidate_id);
    }
    
    // Check if votes table uses user_id or voter_id column
    $checkColumn = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'votes' 
        AND COLUMN_NAME IN ('user_id', 'voter_id')
        LIMIT 1
    ");
    $columnInfo = $checkColumn->fetch(PDO::FETCH_ASSOC);
    $userIdColumn = $columnInfo['COLUMN_NAME'] ?? 'user_id'; // Default to user_id
    
    // Check if votes table has encrypted_data column, add if not
    $checkEncryptedColumn = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'votes' 
        AND COLUMN_NAME = 'encrypted_data'
    ");
    
    if ($checkEncryptedColumn->rowCount() === 0) {
        // Add encrypted_data column and related fields
        try {
            $pdo->exec("
                ALTER TABLE votes 
                ADD COLUMN encrypted_data TEXT NULL,
                ADD COLUMN encryption_iv VARCHAR(255) NULL,
                ADD COLUMN encryption_tag VARCHAR(255) NULL,
                ADD COLUMN vote_hash VARCHAR(64) NULL,
                ADD COLUMN blockchain_tx_hash VARCHAR(66) NULL,
                ADD INDEX idx_vote_hash (vote_hash)
            ");
        } catch (PDOException $e) {
            // Column might already exist or error occurred - log and continue
            error_log("Note: Could not add encryption columns (may already exist): " . $e->getMessage());
        }
    }
    
    // Insert vote with encrypted data
    $vstmt = $pdo->prepare("
        INSERT INTO votes (
            {$userIdColumn}, 
            candidate_id, 
            election_id, 
            encrypted_data, 
            encryption_iv, 
            encryption_tag, 
            vote_hash, 
            blockchain_tx_hash,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $vstmt->execute([
        $user_id, 
        $candidate_id, 
        $election_id,
        $encryptedVote['encrypted_data'],
        $encryptedVote['iv'],
        $encryptedVote['tag'],
        $voteHash,
        $blockchainResult['transaction_hash'] ?? null
    ]);

    // Mark has_voted = 1 in mapping
    $upd = $pdo->prepare("UPDATE election_voter_authorization SET has_voted = 1 WHERE user_id = ? AND election_id = ?");
    $upd->execute([$user_id, $election_id]);

    // Log the vote
    logVoterActivity(
        $pdo, 
        $user_id, 
        'vote_cast', 
        'success', 
        "Voted for candidate $candidate_id in election $election_id" . 
        ($blockchainResult['transaction_hash'] ? " (Blockchain TX: " . substr($blockchainResult['transaction_hash'], 0, 10) . "...)" : "")
    );

    $pdo->commit();
    
    // Prepare response
    $response = [
        'success' => true, 
        'message' => 'Vote submitted successfully',
        'vote_hash' => $voteHash
    ];
    
    // Add blockchain info if available
    if ($blockchainResult['success'] && !empty($blockchainResult['transaction_hash'])) {
        $response['blockchain'] = [
            'transaction_hash' => $blockchainResult['transaction_hash'],
            'vote_hash' => $blockchainResult['vote_hash'] ?? $voteHash,
            'source' => isset($blockchainResult['skipped']) && $blockchainResult['skipped'] ? 'frontend' : 'backend'
        ];
    } elseif (isset($blockchainResult['skipped']) && $blockchainResult['skipped']) {
        $response['blockchain'] = [
            'status' => 'skipped',
            'message' => 'Blockchain recording skipped (not configured)',
            'vote_hash' => $voteHash
        ];
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    
    // Duplicate voting attempt (unique constraint)
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'You have already voted in this election']);
        exit;
    }
    
    // Column not found error
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        error_log("Database schema error in cast-vote.php: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Database configuration error. Please contact administrator.',
            'error' => $e->getMessage()
        ]);
        exit;
    }
    
    error_log("Error casting vote: " . $e->getMessage() . " | Code: " . $e->getCode());
    echo json_encode([
        'success' => false, 
        'message' => 'Error casting vote: ' . $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Unexpected error casting vote: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
}
?>
