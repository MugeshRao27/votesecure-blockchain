<?php
require_once 'config.php';
require_once 'auth-helper.php';

// Only allow POST with multipart/form-data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Require admin authentication
$adminUser = requireAuth('admin');

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

// Normalize headers (case-insensitive)
$map = [];
foreach ($header as $i => $col) {
    $key = strtolower(trim($col));
    $map[$key] = $i;
}

// Required columns: name, email
if (!isset($map['email'])) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'CSV must include an email column']);
    exit;
}

if (!isset($map['name'])) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'CSV must include a name column']);
    exit;
}

$countTotal = 0;
$countCreated = 0;
$countUpdated = 0;
$countSkipped = 0;
$errors = [];
$createdUsers = [];

// Ensure users table has required columns
try {
    $pdo->exec("
        ALTER TABLE users 
        ADD COLUMN IF NOT EXISTS authorized_at TIMESTAMP NULL DEFAULT NULL AFTER authorized,
        ADD COLUMN IF NOT EXISTS authorized_by INT NULL DEFAULT NULL AFTER authorized_at
    ");
} catch(PDOException $e) {
    // Columns might already exist, continue
}

// Prepare statements
$checkUserStmt = $pdo->prepare("SELECT id, authorized FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
$insertUserStmt = $pdo->prepare("
    INSERT INTO users (name, email, password, role, authorized, authorized_at, authorized_by, verified) 
    VALUES (?, ?, ?, 'voter', 1, NOW(), ?, 1)
");
$updateUserStmt = $pdo->prepare("UPDATE users SET name = ?, authorized = 1, authorized_at = NOW(), authorized_by = ? WHERE id = ?");

// Function to generate temporary password
function generateTempPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

// Function to send credentials email (implement with your email service)
function sendCredentialsEmail($email, $name, $tempPassword, $loginUrl) {
    $subject = 'Your VoteSecure Account Credentials';
    $message = "Hello $name,\n\n";
    $message .= "Your VoteSecure account has been created.\n\n";
    $message .= "Login URL: $loginUrl\n";
    $message .= "Temporary Password: $tempPassword\n\n";
    $message .= "IMPORTANT:\n";
    $message .= "1. Please login and change your password\n";
    $message .= "2. You must capture your face photo during first login\n";
    $message .= "3. After face capture, you can vote in elections\n\n";
    $message .= "Keep this password secure. Do not share it with anyone.\n";
    
    // TODO: Replace with actual email service (SendGrid, SMTP, etc.)
    error_log("Credentials email for $email: Password: $tempPassword");
    // return mail($email, $subject, $message);
    return true;
}

// Read rows
while (($row = fgetcsv($handle)) !== false) {
    $countTotal++;
    try {
        $email = isset($row[$map['email']]) ? trim($row[$map['email']]) : '';
        $name = isset($map['name']) && isset($row[$map['name']]) ? trim($row[$map['name']]) : '';
        
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $countSkipped++;
            $errors[] = "Row {$countTotal}: Invalid email";
            continue;
        }
        
        if ($name === '') {
            $name = explode('@', $email)[0]; // Fallback to email prefix
        }
        
        // Optional: DOB column (can be used for validation)
        $dob = isset($map['dob']) && isset($row[$map['dob']]) ? trim($row[$map['dob']]) : null;
        
        // Check if user already exists
        $checkUserStmt->execute([$email]);
        $existingUser = $checkUserStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // User exists - update authorization if not already authorized
            if (!isset($existingUser['authorized']) || intval($existingUser['authorized']) !== 1) {
                $updateUserStmt->execute([$name, $adminUser['user_id'], $existingUser['id']]);
                $countUpdated++;
            } else {
                $countSkipped++;
            }
        } else {
            // Create new user account
            $tempPassword = generateTempPassword();
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            $insertUserStmt->execute([
                $name,
                $email,
                $hashedPassword,
                $adminUser['user_id'] // authorized_by
            ]);
            
            $newUserId = $pdo->lastInsertId();
            $countCreated++;
            
            // Store credentials to email later
            $createdUsers[] = [
                'id' => $newUserId,
                'email' => $email,
                'name' => $name,
                'password' => $tempPassword
            ];
        }
        
    } catch (Throwable $t) {
        $countSkipped++;
        $errors[] = "Row {$countTotal}: " . $t->getMessage();
        error_log("Bulk upload error at row $countTotal: " . $t->getMessage());
    }
}

fclose($handle);

// Send credential emails (batch)
$loginUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/auth";
foreach ($createdUsers as $user) {
    sendCredentialsEmail($user['email'], $user['name'], $user['password'], $loginUrl);
}

echo json_encode([
    'success' => true,
    'message' => 'Bulk upload completed',
    'summary' => [
        'total_rows' => $countTotal,
        'created' => $countCreated,
        'updated' => $countUpdated,
        'skipped' => $countSkipped
    ],
    'errors' => array_slice($errors, 0, 10) // Limit errors in response
]);
?>

