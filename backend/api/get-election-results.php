<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$election_id = $_GET['election_id'] ?? 0;

if (empty($election_id)) {
    echo json_encode(['success' => false, 'message' => 'Election ID is required']);
    exit;
}

try {
    // Get election details
    $electionStmt = $pdo->prepare("SELECT id, title, description, start_date, end_date, status FROM elections WHERE id = ?");
    $electionStmt->execute([$election_id]);
    $election = $electionStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
    
    // Get all candidates for this election with vote counts
    $candidatesStmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.position,
            c.party,
            c.photo,
            c.bio,
            COUNT(v.id) as vote_count
        FROM candidates c
        LEFT JOIN votes v ON c.id = v.candidate_id AND v.election_id = ?
        WHERE c.election_id = ?
        GROUP BY c.id, c.name, c.position, c.party, c.photo, c.bio
        ORDER BY vote_count DESC, c.name ASC
    ");
    $candidatesStmt->execute([$election_id, $election_id]);
    $candidates = $candidatesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total votes for this election
    $totalVotesStmt = $pdo->prepare("SELECT COUNT(*) as total FROM votes WHERE election_id = ?");
    $totalVotesStmt->execute([$election_id]);
    $totalVotes = $totalVotesStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total authorized voters for this election
    $totalAuthorizedStmt = $pdo->prepare("SELECT COUNT(*) as total FROM election_voter_authorization WHERE election_id = ? AND authorized = 1");
    $totalAuthorizedStmt->execute([$election_id]);
    $totalAuthorized = $totalAuthorizedStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Calculate voter turnout percentage
    $turnoutPercentage = $totalAuthorized > 0 ? round(($totalVotes / $totalAuthorized) * 100, 2) : 0;
    
    // Get winner(s) - candidates with highest votes
    $winnerVoteCount = 0;
    $winners = [];
    if (count($candidates) > 0 && $totalVotes > 0) {
        $winnerVoteCount = (int)$candidates[0]['vote_count'];
        foreach ($candidates as $candidate) {
            if ((int)$candidate['vote_count'] === $winnerVoteCount) {
                $winners[] = $candidate;
            } else {
                break;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'election' => $election,
        'candidates' => $candidates,
        'statistics' => [
            'total_votes' => (int)$totalVotes,
            'total_authorized_voters' => (int)$totalAuthorized,
            'turnout_percentage' => $turnoutPercentage,
            'winner_vote_count' => $winnerVoteCount,
            'is_tie' => count($winners) > 1
        ],
        'winners' => $winners
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching results: ' . $e->getMessage()
    ]);
}
?>

