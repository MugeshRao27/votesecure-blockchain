<?php
require_once '../config.php';
require_once '../auth-helper.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require admin
$admin = verifyAdminToken($pdo);
if (!$admin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Admin access required']);
    exit;
}

// Build dataset: one row per election/voter with name, email, dob
$sql = "
    SELECT 
        e.id AS election_id,
        e.title AS election_title,
        COALESCE(u.name, ev.name) AS voter_name,
        COALESCE(u.email, ev.email) AS voter_email,
        u.date_of_birth AS voter_dob
    FROM elections e
    LEFT JOIN eligible_voters ev ON ev.election_id = e.id
    LEFT JOIN users u 
        ON LOWER(TRIM(u.email)) = LOWER(TRIM(ev.email))
    WHERE ev.id IS NOT NULL
    ORDER BY e.id, voter_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no rows, return a tiny CSV with headers only so admin can fill it
if (!$rows) {
    $rows = [];
}

// Send CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="voters_by_election.csv"');

$output = fopen('php://output', 'w');

// CSV columns
$header = ['election_id', 'election_title', 'name', 'email', 'date_of_birth'];
fputcsv($output, $header);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['election_id'],
        $row['election_title'],
        $row['voter_name'],
        $row['voter_email'],
        $row['voter_dob'],
    ]);
}

fclose($output);
exit;

