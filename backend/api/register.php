<?php
require_once 'config.php';
require_once 'auth-helper.php';

/* =========================
   CSV HELPER FUNCTION
   ========================= */
function saveVoterToElectionCSV($electionId, $name, $email, $dob) {

    $csvDir = __DIR__ . '/uploads/election_csv/';

    if (!is_dir($csvDir)) {
        mkdir($csvDir, 0777, true);
    }

    $csvFile = $csvDir . 'election_' . $electionId . '.csv';
    $fileExists = file_exists($csvFile);

    $file = fopen($csvFile, 'a');

    if (!$fileExists) {
        fputcsv($file, ['name', 'email', 'dob']);
    }

    fputcsv($file, [$name, $email, $dob]);
    fclose($file);
}

/* =========================
   METHOD CHECK
   ========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/* =========================
   ADMIN AUTH CHECK
   ========================= */
$admin = verifyAdminToken($pdo);
if (!$admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/* =========================
   TABLE CHECKS
   ========================= */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            temp_password VARCHAR(255) NOT NULL,
            date_of_birth DATE NOT NULL,
            face_image VARCHAR(255) DEFAULT NULL,
            role ENUM('voter','admin') NOT NULL DEFAULT 'voter',
            authorized TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {}

/* =========================
   INPUT
   ========================= */
$data = json_decode(file_get_contents('php://input'), true);

$name  = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$role = $data['role'] ?? 'voter';
$face_image = $data['face_image'] ?? '';
$date_of_birth = $data['date_of_birth'] ?? null;

/* =========================
   VALIDATION
   ========================= */
if (!$name || !$email || !$password || strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email']);
    exit;
}

/* =========================
   DUPLICATE EMAIL CHECK
   ========================= */
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Email already exists']);
    exit;
}

/* =========================
   PASSWORD HASH
   ========================= */
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

/* =========================
   INSERT USER
   ========================= */
$stmt = $pdo->prepare("
    INSERT INTO users (name, email, password, role, face_image, authorized)
    VALUES (?, ?, ?, ?, ?, 1)
");
$stmt->execute([$name, $email, $hashed_password, $role, '']);
$user_id = $pdo->lastInsertId();

/* =========================
   CSV + ELECTION SYNC
   ========================= */
if ($role === 'voter') {

    $normalizedEmail = strtolower(trim($email));

    $listStmt = $pdo->prepare("
        SELECT DISTINCT election_id, name
        FROM election_voter_list
        WHERE LOWER(TRIM(email)) = ?
    ");
    $listStmt->execute([$normalizedEmail]);
    $electionList = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($electionList)) {

        $syncStmt = $pdo->prepare("
            INSERT INTO eligible_voters (election_id, name, email, active, has_registered)
            VALUES (?, ?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE has_registered = 1
        ");

        foreach ($electionList as $election) {

            // DB sync
            $syncStmt->execute([
                $election['election_id'],
                $election['name'],
                $normalizedEmail
            ]);

            // ✅ CSV CREATION (THIS WAS MISSING)
            saveVoterToElectionCSV(
                $election['election_id'],
                $name,
                $email,
                $date_of_birth
            );
        }
    }
}

/* =========================
   RESPONSE
   ========================= */
echo json_encode([
    'success' => true,
    'message' => 'Registration successful'
]);
