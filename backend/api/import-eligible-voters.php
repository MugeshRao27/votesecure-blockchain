<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// election_id is required
$electionId = isset($_POST['election_id']) ? intval($_POST['election_id']) : 0;
if ($electionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'election_id is required']);
    exit;
}

// CSV is required
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'CSV file is required']);
    exit;
}

// Validate election exists
try {
    $eStmt = $pdo->prepare("SELECT id FROM elections WHERE id = ?");
    $eStmt->execute([$electionId]);
    if (!$eStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Election validation error: ' . $e->getMessage()]);
    exit;
}

$tmpPath = $_FILES['file']['tmp_name'];
$handle = fopen($tmpPath, 'r');
if ($handle === false) {
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded file']);
    exit;
}

$header = fgetcsv($handle);
if ($header === false) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'Empty CSV']);
    exit;
}

// Expect headers: name,email
$map = [];
foreach ($header as $i => $col) {
    $key = strtolower(trim($col));
    $map[$key] = $i;
}
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

$sel = $pdo->prepare("SELECT id, name, email, active, has_registered FROM eligible_voters WHERE email = ? AND election_id = ? LIMIT 1");
$ins = $pdo->prepare("INSERT INTO eligible_voters (name, email, election_id, active, has_registered) VALUES (?, ?, ?, 1, 0)");
$upd = $pdo->prepare("UPDATE eligible_voters SET name = ?, active = 1 WHERE id = ?");

while (($row = fgetcsv($handle)) !== false) {
    $countTotal++;
    try {
        $email = isset($row[$map['email']]) ? trim($row[$map['email']]) : '';
        if ($email === '') {
            $countSkipped++;
            $errors[] = "Row {$countTotal}: missing email";
            continue;
        }
        $name = isset($map['name']) && isset($row[$map['name']]) ? trim($row[$map['name']]) : '';
        if ($name === '') {
            $name = $email; // fallback
        }

        $sel->execute([$email, $electionId]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            // Update name / ensure active
            $upd->execute([$name, $existing['id']]);
            $countUpdated++;
        } else {
            $ins->execute([$name, $email, $electionId]);
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


