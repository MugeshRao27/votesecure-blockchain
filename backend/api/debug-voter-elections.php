<?php
/**
 * Debug endpoint to check voter election eligibility
 * This helps troubleshoot why voters can't see elections
 */
require_once 'config.php';
require_once 'auth-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get user_id from query parameter
$user_id = $_GET['user_id'] ?? 0;
if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    // Get user info
    $userStmt = $pdo->prepare("SELECT id, email, name, role FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    $user_email = strtolower(trim($user['email']));
    
    $debug = [
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'normalized_email' => $user_email,
            'name' => $user['name'],
            'role' => $user['role']
        ],
        'tables' => [],
        'elections_found' => []
    ];
    
    // Check eligible_voters table
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'eligible_voters'");
    $hasEligibleVotersTable = $tableCheck->rowCount() > 0;
    
    if ($hasEligibleVotersTable) {
        $stmt = $pdo->prepare("SELECT * FROM eligible_voters WHERE LOWER(TRIM(email)) = ?");
        $stmt->execute([$user_email]);
        $eligibleRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $debug['tables']['eligible_voters'] = [
            'exists' => true,
            'records_found' => count($eligibleRecords),
            'records' => $eligibleRecords
        ];
        
        // Get election IDs
        $electionIds = array_column($eligibleRecords, 'election_id');
        if (!empty($electionIds)) {
            $placeholders = implode(',', array_fill(0, count($electionIds), '?'));
            $electionStmt = $pdo->prepare("SELECT id, title, status FROM elections WHERE id IN ($placeholders)");
            $electionStmt->execute($electionIds);
            $debug['elections_found']['eligible_voters'] = $electionStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $debug['tables']['eligible_voters'] = ['exists' => false];
    }
    
    // Check election_voter_list table
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'election_voter_list'");
    $hasVoterListTable = $tableCheck->rowCount() > 0;
    
    if ($hasVoterListTable) {
        $stmt = $pdo->prepare("SELECT * FROM election_voter_list WHERE LOWER(TRIM(email)) = ?");
        $stmt->execute([$user_email]);
        $voterListRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $debug['tables']['election_voter_list'] = [
            'exists' => true,
            'records_found' => count($voterListRecords),
            'records' => $voterListRecords
        ];
        
        // Get election IDs
        $electionIds = array_column($voterListRecords, 'election_id');
        if (!empty($electionIds)) {
            $placeholders = implode(',', array_fill(0, count($electionIds), '?'));
            $electionStmt = $pdo->prepare("SELECT id, title, status FROM elections WHERE id IN ($placeholders)");
            $electionStmt->execute($electionIds);
            $debug['elections_found']['election_voter_list'] = $electionStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $debug['tables']['election_voter_list'] = ['exists' => false];
    }
    
    // Check all elections in system
    $allElectionsStmt = $pdo->query("SELECT id, title, status, start_date, end_date FROM elections ORDER BY created_at DESC");
    $debug['all_elections_in_system'] = $allElectionsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'debug' => $debug]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

