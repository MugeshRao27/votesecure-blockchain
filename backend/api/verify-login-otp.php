<?php
require_once 'config.php';
require_once 'auth-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$email = trim($data['email'] ?? '');
$otp = trim($data['otp'] ?? '');
$token = trim($data['login_token'] ?? '');
$role = $data['role'] ?? 'voter';

if (empty($email) || empty($otp) || empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Email, OTP, and login token are required']);
    exit;
}

try {
    // Ensure login_otps table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(10) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            verified TINYINT(1) NOT NULL DEFAULT 0,
            login_type VARCHAR(20) DEFAULT 'otp',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lo_email (email),
            INDEX idx_lo_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Lookup pending OTP
    $stmt = $pdo->prepare("SELECT * FROM login_otps WHERE email = ? AND token = ? AND verified = 0 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email, $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Invalid or already used login token']);
        exit;
    }

    // Check expiry
    $now = new DateTime();
    $exp = new DateTime($row['expires_at']);
    if ($now > $exp) {
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please login again.']);
        exit;
    }

    // Limit attempts
    if ((int)$row['attempts'] >= 5) {
        echo json_encode(['success' => false, 'message' => 'Too many OTP attempts. Please login again.']);
        exit;
    }

    // Verify OTP
    if (!hash_equals($row['otp_code'], $otp)) {
        $upd = $pdo->prepare("UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?");
        $upd->execute([$row['id']]);
        echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
        exit;
    }

    // Mark OTP as verified
    $upd = $pdo->prepare("UPDATE login_otps SET verified = 1 WHERE id = ?");
    $upd->execute([$row['id']]);

    // Load user
    $u = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
    $u->execute([$email]);
    $user = $u->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Check role match
    $userRole = strtolower(trim($user['role']));
    if (!empty($role) && $userRole !== strtolower(trim($role))) {
        echo json_encode(['success' => false, 'message' => 'Invalid role for this account']);
        exit;
    }

    // ========================================
    // ADMIN OTP VERIFICATION (Complete 2FA)
    // ========================================
    if ($userRole === 'admin' && $row['login_type'] === 'admin') {
        // Admin OTP verified - complete login
        unset($user['password']);
        $jwtToken = generateJWT($user['id'], $user['email'], $user['role']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Admin login successful (2FA complete)',
            'token' => $jwtToken,
            'user' => $user
        ]);
        exit;
    }

    // ========================================
    // USER (VOTER) OTP LOGIN
    // ========================================
    if ($userRole === 'voter') {
        // Check authorization
        if (!isset($user['authorized']) || intval($user['authorized']) !== 1) {
            echo json_encode([
                'success' => false, 
                'message' => 'Your account is not yet authorized. Please wait for admin approval.',
                'authorized' => false
            ]);
            exit;
        }
        
        // Generate JWT token
        unset($user['password']);
        $jwtToken = generateJWT($user['id'], $user['email'], $user['role']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'token' => $jwtToken,
            'user' => $user
        ]);
        exit;
    }

    // Unknown role
    echo json_encode(['success' => false, 'message' => 'Invalid user role']);
    
} catch (PDOException $e) {
    error_log("OTP verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'OTP verification error: ' . $e->getMessage()]);
}
?>
