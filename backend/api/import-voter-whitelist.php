<?php
require_once 'config.php';

// Only allow POST with multipart/form-data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Basic admin guard (optional): require an admin token/session check here
// if (!is_admin()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

// Expect a CSV file uploaded as 'file'
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'CSV file is required']);
    exit;
}

$tmpPath = $_FILES['file']['tmp_name'];
$handle = fopen($tmpPath, 'r');
if ($handle === false) {
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded file']);
    exit;
}

// Parse CSV header
$header = fgetcsv($handle);
if ($header === false) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'Empty CSV']);
    exit;
}

// Normalize headers
$map = [];
foreach ($header as $i => $col) {
    $key = strtolower(trim($col));
    $map[$key] = $i;
}

// Required column
if (!isset($map['email'])) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'CSV must include an email column']);
    exit;
}

$countTotal = 0;
$countInserted = 0;
$countUpdated = 0;
$countSkipped = 0;
$errors = [];

// Optional default params via form fields (fallback if CSV columns missing)
$defaultOrgId = isset($_POST['organization_id']) ? intval($_POST['organization_id']) : null;
$defaultElectionId = isset($_POST['election_id']) ? intval($_POST['election_id']) : null;
$defaultActive = isset($_POST['active']) ? intval($_POST['active']) : 1;

// Prepare statements
$selectStmt = $pdo->prepare("SELECT id, email, organization_id, election_id, active FROM voter_whitelist WHERE email = ? AND IFNULL(organization_id, 0) <=> IFNULL(?, 0) AND IFNULL(election_id, 0) <=> IFNULL(?, 0) LIMIT 1");
$insertStmt = $pdo->prepare("INSERT INTO voter_whitelist (email, organization_id, election_id, active) VALUES (?, ?, ?, ?)");
$updateStmt = $pdo->prepare("UPDATE voter_whitelist SET active = ? WHERE id = ?");

// Read rows
while (($row = fgetcsv($handle)) !== false) {
    $countTotal++;
    try {
        $email = isset($row[$map['email']]) ? trim($row[$map['email']]) : '';
        if ($email === '') {
            $countSkipped++;
            $errors[] = "Row {$countTotal}: missing email";
            continue;
        }

        // Optional columns
        $orgId = isset($map['organization_id']) && isset($row[$map['organization_id']]) && $row[$map['organization_id']] !== '' ? intval($row[$map['organization_id']]) : $defaultOrgId;
        $electionId = isset($map['election_id']) && isset($row[$map['election_id']]) && $row[$map['election_id']] !== '' ? intval($row[$map['election_id']]) : $defaultElectionId;
        $active = isset($map['active']) && isset($row[$map['active']]) && $row[$map['active']] !== '' ? intval($row[$map['active']]) : $defaultActive;
        $active = $active ? 1 : 0;

        // Upsert: treat (email, orgId, electionId) as a composite key
        $selectStmt->execute([$email, $orgId, $electionId]);
        $existing = $selectStmt->fetch();

        if ($existing) {
            if ((int)$existing['active'] !== $active) {
                $updateStmt->execute([$active, $existing['id']]);
                $countUpdated++;
            } else {
                $countSkipped++;
            }
        } else {
            $insertStmt->execute([$email, $orgId, $electionId, $active]);
            $countInserted++;
        }
    } catch (Throwable $t) {
        $countSkipped++;
        $errors[] = "Row {$countTotal}: " . $t->getMessage();
    }
}

fclose($handle);

echo json_encode([
    'success' => true,
    'message' => 'Import completed',
    'summary' => [
        'total_rows' => $countTotal,
        'inserted' => $countInserted,
        'updated' => $countUpdated,
        'skipped' => $countSkipped
    ],
    'errors' => $errors
]);
?>


