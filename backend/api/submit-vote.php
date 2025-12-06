<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$voter_id = $data['voter_id'] ?? 0;
$candidate_id = $data['candidate_id'] ?? 0;
$election_id = $data['election_id'] ?? 0;

// Validation
if (empty($voter_id) || empty($candidate_id) || empty($election_id)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID, candidate ID, and election ID are required']);
    exit;
}

try {
    // Check if voter exists and is authorized
    $stmt = $pdo->prepare("SELECT id, role, authorized FROM users WHERE id = ? AND role = 'voter'");
    $stmt->execute([$voter_id]);
    $voter = $stmt->fetch();
    
    if (!$voter) {
        echo json_encode(['success' => false, 'message' => 'Invalid voter']);
        exit;
    }

    // Check if voter is authorized to vote
    if (!$voter['authorized']) {
        echo json_encode([
            'success' => false,
            'message' => 'You are not authorized to vote. Please contact the administrator for approval.'
        ]);
        exit;
    }

    // Check if election exists and is active
    $stmt = $pdo->prepare("SELECT id, status, start_date, end_date FROM elections WHERE id = ?");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch();
    
    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    
    // Normalize dates for comparison (handle both DATE and DATETIME formats)
    $startDateTime = $election['start_date'];
    $endDateTime = $election['end_date'];
    
    if (strlen($startDateTime) == 10) {
        $startDateTime .= ' 00:00:00';
    }
    if (strlen($endDateTime) == 10) {
        $endDateTime .= ' 23:59:59';
    }
    
    // Check if election is active based on dates
    if ($now < $startDateTime) {
        echo json_encode(['success' => false, 'message' => 'This election has not started yet. Voting will be available when the election becomes active.']);
        exit;
    }
    
    if ($now > $endDateTime) {
        echo json_encode(['success' => false, 'message' => 'This election has ended. Voting is no longer allowed.']);
        exit;
    }
    
    // Also check the status field - only allow voting if status is 'active'
    if (strtolower($election['status']) !== 'active') {
        echo json_encode(['success' => false, 'message' => 'This election is not currently active. Voting is only allowed during active elections.']);
        exit;
    }

    // Check voter eligibility for this election
    try {
        // Check if eligible_voters table exists
        $checkTable = $pdo->query("SHOW TABLES LIKE 'eligible_voters'");
        if ($checkTable->rowCount() > 0) {
            // Get voter's email
            $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $emailStmt->execute([$voter_id]);
            $voter_data = $emailStmt->fetch();
            
            if ($voter_data) {
                $voter_email = strtolower(trim($voter_data['email']));
                
                // Check if voter is eligible for this election
                $eligibilityStmt = $pdo->prepare("
                    SELECT id FROM eligible_voters 
                    WHERE election_id = ? 
                      AND LOWER(TRIM(email)) = ? 
                      AND active = 1
                ");
                $eligibilityStmt->execute([$election_id, $voter_email]);
                $eligible = $eligibilityStmt->fetch();
                
                if (!$eligible) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'You are not eligible to vote in this election. Please contact the administrator.'
                    ]);
                    exit;
                }
            }
        }
    } catch (PDOException $e) {
        // eligible_voters table doesn't exist or error - continue without eligibility check (backward compatibility)
        error_log("Eligibility check error: " . $e->getMessage());
    }

    // Check if candidate belongs to this election
    $stmt = $pdo->prepare("SELECT id FROM candidates WHERE id = ? AND election_id = ?");
    $stmt->execute([$candidate_id, $election_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Candidate does not belong to this election']);
        exit;
    }

    // Check if voter has already voted in this election
    $stmt = $pdo->prepare("SELECT id FROM votes WHERE voter_id = ? AND election_id = ?");
    $stmt->execute([$voter_id, $election_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You have already voted in this election']);
        exit;
    }

    // Insert vote
    $stmt = $pdo->prepare("INSERT INTO votes (voter_id, candidate_id, election_id) VALUES (?, ?, ?)");
    if ($stmt->execute([$voter_id, $candidate_id, $election_id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Vote submitted successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit vote']);
    }
} catch(PDOException $e) {
    // Check if error is due to duplicate vote (UNIQUE constraint)
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'You have already voted in this election']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error submitting vote: ' . $e->getMessage()]);
    }
}
?>

