<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$user_id = $_GET['user_id'] ?? 0;
if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    // Get user's email
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || empty($user['email'])) {
        echo json_encode(['success' => false, 'message' => 'User not found or email not set']);
        exit;
    }
    
    // Normalize email (lowercase, trimmed) for consistent matching
    $user_email = strtolower(trim($user['email']));
    $now = date('Y-m-d H:i:s');
    
    error_log("🔍 Fetching elections for user - ID: $user_id, Email: '$user_email' (normalized)");

    // Check which tables exist and create eligible_voters if needed
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'election_voter_list'");
    $hasVoterListTable = $tableCheck->rowCount() > 0;
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'eligible_voters'");
    $hasEligibleVotersTable = $tableCheck->rowCount() > 0;
    
    // Create eligible_voters table if it doesn't exist
    if (!$hasEligibleVotersTable) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `eligible_voters` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `election_id` int(11) NOT NULL,
                    `name` varchar(255) NOT NULL,
                    `email` varchar(255) NOT NULL,
                    `active` tinyint(1) NOT NULL DEFAULT 1,
                    `has_registered` tinyint(1) NOT NULL DEFAULT 0,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_election_email` (`election_id`, `email`),
                    KEY `election_id` (`election_id`),
                    KEY `email` (`email`),
                    KEY `active` (`active`),
                    CONSTRAINT `eligible_voters_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $hasEligibleVotersTable = true;
            error_log("Created eligible_voters table");
        } catch (PDOException $e) {
            error_log("Could not create eligible_voters table: " . $e->getMessage());
        }
    }
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'election_voter_authorization'");
    $hasAuthTable = $tableCheck->rowCount() > 0;
    
    // Build query that checks all available tables
    // Check eligible_voters, election_voter_list, and election_voter_authorization
    // Return elections if voter is found in ANY of these tables
    $electionIds = [];
    
    if ($hasEligibleVotersTable) {
        try {
            // DEBUG: First, let's see ALL records for this email (case-insensitive)
            $debugAllStmt = $pdo->prepare("SELECT id, election_id, email, active, has_registered, LOWER(TRIM(email)) as normalized_email FROM eligible_voters WHERE LOWER(TRIM(email)) = ? OR email = ?");
            $debugAllStmt->execute([$user_email, $user['email']]);
            $allDebugRecords = $debugAllStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("🔍 DEBUG: All eligible_voters records for email '$user_email' or '{$user['email']}': " . json_encode($allDebugRecords));
            
            // Check eligible_voters table for ALL active voters
            // This includes:
            // 1. Manually registered voters (has_registered = 1) - registered by admin, should ALWAYS see elections
            // 2. CSV-imported voters (has_registered = 0 or NULL) - from CSV upload, will see elections once they register
            // 3. CSV voters who have registered (has_registered = 1) - from CSV but have now registered
            // 
            // IMPORTANT: Manually registered voters are added with has_registered=1 and active=1
            // They will be found by this query and will see their elections regardless of CSV lists
            $stmt = $pdo->prepare("
                SELECT DISTINCT election_id 
                FROM eligible_voters 
                WHERE LOWER(TRIM(email)) = ? 
                  AND active = 1
            ");
            $stmt->execute([$user_email]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $electionIds = array_merge($electionIds, $ids);
            
            error_log("🔍 Query executed: SELECT DISTINCT election_id FROM eligible_voters WHERE LOWER(TRIM(email)) = '$user_email' AND active = 1");
            error_log("🔍 Result: " . count($ids) . " election IDs found: " . json_encode($ids));
            
            if (count($ids) > 0) {
                // Check how many are manually registered (has_registered = 1)
                $manualCheck = $pdo->prepare("
                    SELECT COUNT(DISTINCT election_id) as count 
                    FROM eligible_voters 
                    WHERE LOWER(TRIM(email)) = ? 
                      AND active = 1 
                      AND has_registered = 1
                ");
                $manualCheck->execute([$user_email]);
                $manualCount = $manualCheck->fetch(PDO::FETCH_ASSOC)['count'];
                
                error_log("✅ Eligible voters table: Found " . count($ids) . " elections for email: " . $user_email . " (Election IDs: " . implode(', ', $ids) . ")");
                if ($manualCount > 0) {
                    error_log("   └─ " . $manualCount . " of these are manually registered (has_registered=1)");
                }
            } else {
                // Debug: Check if any records exist for this email at all (with different case)
                $debugStmt = $pdo->prepare("SELECT id, election_id, email, active, has_registered, LOWER(TRIM(email)) as normalized FROM eligible_voters WHERE LOWER(TRIM(email)) = ? OR email LIKE ?");
                $debugStmt->execute([$user_email, '%' . $user_email . '%']);
                $debugRecords = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($debugRecords) > 0) {
                    error_log("⚠️ Found " . count($debugRecords) . " eligible_voters records for email pattern but none are active. Records: " . json_encode($debugRecords));
                } else {
                    error_log("ℹ️ No eligible_voters records found for email: " . $user_email);
                    // Additional debug: check what emails exist in the table
                    $allEmailsStmt = $pdo->query("SELECT DISTINCT email, LOWER(TRIM(email)) as normalized FROM eligible_voters LIMIT 20");
                    $allEmails = $allEmailsStmt->fetchAll(PDO::FETCH_ASSOC);
                    error_log("ℹ️ Sample emails in eligible_voters table (first 20): " . json_encode($allEmails));
                }
            }
        } catch (PDOException $e) {
            error_log("❌ Error checking eligible_voters: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    } else {
        error_log("⚠️ eligible_voters table does not exist");
    }
    
    // Check election_voter_list table
    if ($hasVoterListTable) {
        try {
            $stmt = $pdo->prepare("SELECT DISTINCT election_id FROM election_voter_list WHERE LOWER(TRIM(email)) = ?");
            $stmt->execute([$user_email]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $electionIds = array_merge($electionIds, $ids);
            error_log("Election voter list table: Found " . count($ids) . " elections for email: " . $user_email);
        } catch (PDOException $e) {
            error_log("Error checking election_voter_list: " . $e->getMessage());
        }
    }
    
    // Check election_voter_authorization table
    if ($hasAuthTable) {
        try {
            $stmt = $pdo->prepare("SELECT DISTINCT election_id FROM election_voter_authorization WHERE user_id = ? AND authorized = 1");
            $stmt->execute([$user_id]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $electionIds = array_merge($electionIds, $ids);
            error_log("Election voter authorization table: Found " . count($ids) . " elections for user_id: " . $user_id);
        } catch (PDOException $e) {
            error_log("Error checking election_voter_authorization: " . $e->getMessage());
        }
    }
    
    // Remove duplicates and filter out empty values
    $electionIds = array_filter(array_unique($electionIds));
    error_log("📊 Total unique election IDs found across all tables: " . count($electionIds));
    if (!empty($electionIds)) {
        error_log("📋 Election IDs: " . implode(', ', $electionIds));
    } else {
        error_log("⚠️ No election IDs found for user. Email: $user_email, User ID: $user_id");
        
        // Additional check: If voter exists but has no eligibility records,
        // check if they were recently registered (within last 24 hours)
        // This helps catch cases where registration didn't properly create eligibility records
        try {
            $recentCheck = $pdo->prepare("
                SELECT id, created_at 
                FROM users 
                WHERE id = ? 
                  AND role = 'voter' 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $recentCheck->execute([$user_id]);
            $recentUser = $recentCheck->fetch();
            
            if ($recentUser) {
                error_log("⚠️ User was recently registered (within 24 hours) but has no eligibility records!");
                error_log("⚠️ This suggests the registration process may not have created eligible_voters entries properly.");
                error_log("⚠️ User created at: " . $recentUser['created_at']);
            }
        } catch (PDOException $e) {
            error_log("Could not check recent registration: " . $e->getMessage());
        }
    }
    
    // Build the main query
    if (!empty($electionIds)) {
        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($electionIds), '?'));
        $sql = "SELECT 
                    e.id,
                    e.title,
                    e.description,
                    e.start_date,
                    e.end_date,
                    e.status,
                    e.created_at,
                    1 AS authorized
                FROM elections e
                WHERE e.id IN ($placeholders)
                ORDER BY 
                    CASE 
                        WHEN e.start_date <= ? AND e.end_date >= ? THEN 1
                        WHEN e.start_date > ? THEN 2
                        ELSE 3
                    END,
                    e.created_at DESC";
        
        $params = array_merge($electionIds, [$now, $now, $now]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        // No elections found in any eligibility table
        // For backward compatibility, if no eligibility tables exist, show all elections
        // Otherwise return empty result
        if (!$hasEligibleVotersTable && !$hasVoterListTable && !$hasAuthTable) {
            error_log("No eligibility tables found - showing all elections for backward compatibility");
            $sql = "SELECT 
                        e.id,
                        e.title,
                        e.description,
                        e.start_date,
                        e.end_date,
                        e.status,
                        e.created_at,
                        1 AS authorized
                    FROM elections e
                    ORDER BY 
                        CASE 
                            WHEN e.start_date <= ? AND e.end_date >= ? THEN 1
                            WHEN e.start_date > ? THEN 2
                            ELSE 3
                        END,
                        e.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$now, $now, $now]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // No elections found in eligibility tables
            // Check if there are ANY elections in the system
            $allElectionsCheck = $pdo->query("SELECT COUNT(*) as total FROM elections");
            $totalElections = $allElectionsCheck->fetch(PDO::FETCH_ASSOC)['total'];
            
            error_log("No elections found for user. User email: " . $user_email . ", User ID: " . $user_id);
            error_log("Tables checked - eligible_voters: " . ($hasEligibleVotersTable ? "exists" : "missing") . 
                      ", election_voter_list: " . ($hasVoterListTable ? "exists" : "missing") . 
                      ", election_voter_authorization: " . ($hasAuthTable ? "exists" : "missing"));
            error_log("Total elections in system: " . $totalElections);
            
            // No elections found - return empty array
            // Don't show all elections - voter must be explicitly assigned to elections
            $rows = [];
            
            // Additional debugging for troubleshooting
            if ($totalElections > 0) {
                try {
                    // Check if voter was recently registered
                    $recentCheck = $pdo->prepare("
                        SELECT id, created_at, role, email 
                        FROM users 
                        WHERE id = ? 
                          AND role = 'voter' 
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $recentCheck->execute([$user_id]);
                    $recentUser = $recentCheck->fetch();
                    
                    if ($recentUser) {
                        error_log("⚠️ Recently registered voter (within 7 days) but no eligibility records found!");
                        error_log("⚠️ User email in users table: '" . $recentUser['email'] . "'");
                        error_log("⚠️ Normalized email used in query: '" . $user_email . "'");
                        error_log("⚠️ Emails match: " . (strtolower(trim($recentUser['email'])) === $user_email ? 'YES' : 'NO'));
                        error_log("⚠️ This suggests the eligible_voters record was not created during registration.");
                        error_log("⚠️ Please check server logs during registration for errors.");
                        
                        // Double-check: Try to find records with the exact email from users table
                        $doubleCheck = $pdo->prepare("SELECT * FROM eligible_voters WHERE LOWER(TRIM(email)) = ?");
                        $doubleCheck->execute([strtolower(trim($recentUser['email']))]);
                        $doubleCheckRecords = $doubleCheck->fetchAll(PDO::FETCH_ASSOC);
                        if (count($doubleCheckRecords) > 0) {
                            error_log("⚠️ Found " . count($doubleCheckRecords) . " eligible_voters records with users table email: " . json_encode($doubleCheckRecords));
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Error checking recent registration: " . $e->getMessage());
                }
            }
        }
    }
    
    // Only fetch from $stmt if $rows wasn't already set by the fallback logic
    if (!isset($rows)) {
        if (isset($stmt)) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = [];
        }
    }
    
    error_log("Final elections returned: " . count($rows));
    
    // Check which elections the user has voted in
    $votedElectionIds = [];
    if (!empty($rows)) {
        try {
            $voteStmt = $pdo->prepare("SELECT DISTINCT election_id FROM votes WHERE user_id = ?");
            $voteStmt->execute([$user_id]);
            $votedElectionIds = $voteStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Could not fetch voted elections: " . $e->getMessage());
        }
    }
    
    // Add has_voted flag to each election
    foreach ($rows as &$row) {
        $row['has_voted'] = in_array($row['id'], $votedElectionIds) ? 1 : 0;
    }
    unset($row);

    // Normalize status by current time (active/upcoming/completed)
    foreach ($rows as &$e) {
        $start = $e['start_date'];
        $end = $e['end_date'];
        if (strlen($start) === 10) $start .= ' 00:00:00';
        if (strlen($end) === 10) $end .= ' 23:59:59';
        if ($now >= $start && $now <= $end) {
            $status = 'active';
        } elseif ($now < $start) {
            $status = 'upcoming';
        } else {
            $status = 'completed';
        }
        if ($e['status'] !== $status) {
            // Keep DB up-to-date
            $upd = $pdo->prepare("UPDATE elections SET status = ? WHERE id = ?");
            $upd->execute([$status, $e['id']]);
            $e['status'] = $status;
        }
    }
    unset($e);

    // Add debug information to response (always include for troubleshooting)
    $response = ['success' => true, 'elections' => $rows];
    
    // Always include debug info for troubleshooting
    $debugInfo = [
        'user_id' => $user_id,
        'user_email' => $user_email,
        'user_email_raw' => $user['email'] ?? 'N/A',
        'election_ids_found' => $electionIds,
        'elections_returned' => count($rows),
        'tables_checked' => [
            'eligible_voters' => $hasEligibleVotersTable,
            'election_voter_list' => $hasVoterListTable,
            'election_voter_authorization' => $hasAuthTable
        ]
    ];
    
    // Add detailed eligible_voters check for debugging
    if ($hasEligibleVotersTable) {
        try {
            $debugStmt = $pdo->prepare("SELECT id, election_id, email, active, has_registered, LOWER(TRIM(email)) as normalized_email FROM eligible_voters WHERE LOWER(TRIM(email)) = ? OR email = ?");
            $debugStmt->execute([$user_email, $user['email'] ?? '']);
            $debugInfo['eligible_voters_records'] = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $debugInfo['eligible_voters_error'] = $e->getMessage();
        }
    }
    
    if (empty($rows)) {
        $debugInfo['message'] = 'No elections found. Check eligible_voters_records above to see if voter is registered.';
    }
    
    $response['debug'] = $debugInfo;
    
    echo json_encode($response);
} catch (PDOException $e) {
    error_log("❌ PDO Exception in get-authorized-elections: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Error fetching elections: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("❌ General Exception in get-authorized-elections: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>



