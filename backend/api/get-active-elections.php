<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get voter_id from query parameter if provided
    $voter_id = $_GET['voter_id'] ?? 0;
    
    $now = date('Y-m-d H:i:s');
    
    // Get voter's email and authorization status
    $voter_email = null;
    $is_authorized = false;
    if ($voter_id) {
        try {
            // Check if authorized column exists first
            $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'authorized'");
            $columnExists = $checkColumn->rowCount() > 0;
            
            $emailStmt = $pdo->prepare("SELECT email" . ($columnExists ? ", authorized" : "") . " FROM users WHERE id = ? AND role = 'voter'");
            $emailStmt->execute([$voter_id]);
            $voter_data = $emailStmt->fetch();
            if ($voter_data) {
                $voter_email = strtolower(trim($voter_data['email']));
                $is_authorized = $columnExists ? (bool)$voter_data['authorized'] : true; // Default to true if column doesn't exist
            }
        } catch (PDOException $e) {
            error_log("Could not fetch voter data: " . $e->getMessage());
        }
    }
    
    // Check if eligible_voters table exists
    $eligibilityEnabled = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'eligible_voters'");
        $eligibilityEnabled = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist, continue without eligibility filtering
        error_log("eligible_voters table not found, showing all elections");
    }

    // Get elections where voter has voted (even if completed)
    $voted_election_ids = [];
    if ($voter_id) {
        try {
            $votedStmt = $pdo->prepare("SELECT DISTINCT election_id FROM votes WHERE voter_id = ?");
            $votedStmt->execute([$voter_id]);
            $voted_election_ids = $votedStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Could not fetch voted elections: " . $e->getMessage());
        }
    }

    // Build query to get elections
    // Show ALL elections to voters (not filtered by end_date)
    $elections = [];
    
    if ($voter_id) {
        // For voters: show ALL elections (not filtered by eligibility)
        // Show all elections regardless of eligible_voters table
        $stmt = $pdo->prepare("
            SELECT *
            FROM elections
            ORDER BY 
              CASE 
                WHEN start_date <= ? AND end_date >= ? THEN 1  -- Active elections first
                WHEN start_date > ? THEN 2  -- Upcoming elections second
                ELSE 3  -- Completed elections last
              END,
              created_at DESC
        ");
        $stmt->execute([$now, $now, $now]);
        $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log query result
        error_log("Voter ID: $voter_id, Elections found: " . count($elections));
        if (count($elections) == 0) {
            // Check if any elections exist at all
            $checkStmt = $pdo->query("SELECT COUNT(*) as total FROM elections");
            $totalElections = $checkStmt->fetch(PDO::FETCH_ASSOC)['total'];
            error_log("Total elections in database: $totalElections");
        }
    } else {
        // No voter_id provided - show ALL elections (admin view or public)
        $stmt = $pdo->prepare("
            SELECT *
            FROM elections
            ORDER BY 
              CASE 
                WHEN start_date <= ? AND end_date >= ? THEN 1  -- Active elections first
                WHEN start_date > ? THEN 2  -- Upcoming elections second
                ELSE 3  -- Completed elections last
              END,
              created_at DESC
        ");
        $stmt->execute([$now, $now, $now]);
        $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Update status for each election based on current time
    foreach ($elections as &$election) {
        $startDateTime = $election['start_date'];
        $endDateTime = $election['end_date'];
        
        // Normalize dates to DATETIME format for comparison
        // Handle both DATE and DATETIME formats
        if (strlen($startDateTime) == 10) {
            $startDateTime .= ' 00:00:00';
        }
        if (strlen($endDateTime) == 10) {
            $endDateTime .= ' 23:59:59';
        }
        
        // Determine status: active, upcoming, or completed
        // Active: current time >= start_date AND current time <= end_date
        // Upcoming: current time < start_date
        // Completed: current time > end_date
        if ($now >= $startDateTime && $now <= $endDateTime) {
            $status = 'active';
        } elseif ($now < $startDateTime) {
            $status = 'upcoming';
        } else {
            $status = 'completed'; // now > end_date
        }
        
        // Update status in database if changed
        if ($election['status'] !== $status) {
            $updateStmt = $pdo->prepare("UPDATE elections SET status = ? WHERE id = ?");
            $updateStmt->execute([$status, $election['id']]);
            $election['status'] = $status;
        }
    }
    unset($election); // Break reference

    // Note: status auto-correction handled in eligibility query; optional global update removed
    
    // Debug: Log election count and details
    error_log("Elections found: " . count($elections));
    foreach ($elections as $election) {
        error_log("Election: " . $election['title'] . " - Status: " . $election['status'] . " - Dates: " . $election['start_date'] . " to " . $election['end_date']);
    }

    // Prepare response message
    $message = null;
    if ($voter_id && !$is_authorized) {
        // Voter is not authorized
        $message = 'You are not authorized to vote. Please contact administrator.';
    }

    $response = [
        'success' => true,
        'elections' => $elections
    ];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    echo json_encode($response);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching active elections: ' . $e->getMessage()
    ]);
}
?>

