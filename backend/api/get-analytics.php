<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$election_id = $_GET['election_id'] ?? null;

try {
    $analytics = [];
    
    if ($election_id) {
        // Get analytics for specific election
        // Total votes
        $totalVotesStmt = $pdo->prepare("SELECT COUNT(*) as total FROM votes WHERE election_id = ?");
        $totalVotesStmt->execute([$election_id]);
        $totalVotes = (int)$totalVotesStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total authorized voters
        $totalAuthStmt = $pdo->prepare("SELECT COUNT(*) as total FROM election_voter_authorization WHERE election_id = ? AND authorized = 1");
        $totalAuthStmt->execute([$election_id]);
        $totalAuthorized = (int)$totalAuthStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Votes by hour (for activity timeline)
        // Check if created_at column exists first
        try {
            $hourlyStmt = $pdo->prepare("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
                    COUNT(*) as vote_count
                FROM votes
                WHERE election_id = ?
                GROUP BY hour
                ORDER BY hour ASC
            ");
            $hourlyStmt->execute([$election_id]);
            $hourlyData = $hourlyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            // If created_at doesn't exist, return empty array
            $hourlyData = [];
        }
        
        // Votes by candidate
        $candidateStmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.position,
                COUNT(v.id) as vote_count
            FROM candidates c
            LEFT JOIN votes v ON c.id = v.candidate_id AND v.election_id = ?
            WHERE c.election_id = ?
            GROUP BY c.id, c.name, c.position
            ORDER BY vote_count DESC
        ");
        $candidateStmt->execute([$election_id, $election_id]);
        $candidateData = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $analytics = [
            'election_id' => $election_id,
            'total_votes' => $totalVotes,
            'total_authorized' => $totalAuthorized,
            'turnout_percentage' => $totalAuthorized > 0 ? round(($totalVotes / $totalAuthorized) * 100, 2) : 0,
            'hourly_data' => $hourlyData,
            'candidate_data' => $candidateData
        ];
    } else {
        // Get overall analytics
        // Total elections
        $electionsStmt = $pdo->query("SELECT COUNT(*) as total FROM elections");
        $totalElections = (int)$electionsStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total votes across all elections
        $totalVotesStmt = $pdo->query("SELECT COUNT(*) as total FROM votes");
        $totalVotes = (int)$totalVotesStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total voters
        $totalVotersStmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'voter'");
        $totalVoters = (int)$totalVotersStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total candidates
        $totalCandidatesStmt = $pdo->query("SELECT COUNT(*) as total FROM candidates");
        $totalCandidates = (int)$totalCandidatesStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Elections by status
        $statusStmt = $pdo->query("
            SELECT status, COUNT(*) as count 
            FROM elections 
            GROUP BY status
        ");
        $statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent votes (last 24 hours)
        // Check if created_at column exists first
        try {
            $recentVotesStmt = $pdo->query("
                SELECT COUNT(*) as total 
                FROM votes 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $recentVotes = (int)$recentVotesStmt->fetch(PDO::FETCH_ASSOC)['total'];
        } catch(PDOException $e) {
            // If created_at doesn't exist, set to 0
            $recentVotes = 0;
        }
        
        $analytics = [
            'total_elections' => $totalElections,
            'total_votes' => $totalVotes,
            'total_voters' => $totalVoters,
            'total_candidates' => $totalCandidates,
            'recent_votes_24h' => $recentVotes,
            'elections_by_status' => $statusData
        ];
    }
    
    echo json_encode([
        'success' => true,
        'analytics' => $analytics
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching analytics: ' . $e->getMessage()
    ]);
}
?>

