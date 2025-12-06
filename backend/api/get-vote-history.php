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
    // Check if created_at column exists in votes table
    $checkColumn = $pdo->query("
        SELECT COUNT(*) as col_exists 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'votes' 
        AND COLUMN_NAME = 'created_at'
    ");
    $columnExists = $checkColumn->fetch(PDO::FETCH_ASSOC)['col_exists'] > 0;
    
    // Build query based on whether created_at exists
    if ($columnExists) {
        $stmt = $pdo->prepare("
            SELECT 
                v.id as vote_id,
                v.created_at as voted_at,
                e.id as election_id,
                e.title as election_title,
                e.description as election_description,
                e.status as election_status,
                c.id as candidate_id,
                c.name as candidate_name,
                c.position as candidate_position,
                c.party as candidate_party,
                c.photo as candidate_photo
            FROM votes v
            INNER JOIN elections e ON v.election_id = e.id
            INNER JOIN candidates c ON v.candidate_id = c.id
            WHERE v.voter_id = ?
            ORDER BY v.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                v.id as vote_id,
                NULL as voted_at,
                e.id as election_id,
                e.title as election_title,
                e.description as election_description,
                e.status as election_status,
                c.id as candidate_id,
                c.name as candidate_name,
                c.position as candidate_position,
                c.party as candidate_party,
                c.photo as candidate_photo
            FROM votes v
            INNER JOIN elections e ON v.election_id = e.id
            INNER JOIN candidates c ON v.candidate_id = c.id
            WHERE v.voter_id = ?
            ORDER BY v.id DESC
        ");
    }
    $stmt->execute([$user_id]);
    $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'votes' => $votes,
        'total_votes' => count($votes)
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching vote history: ' . $e->getMessage()
    ]);
}
?>

