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
$loginType = $data['login_type'] ?? 'otp'; // 'otp' for OTP-only login

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Valid email is required']);
    exit;
}

// Rate limiting
if (!checkRateLimit("otp_$email", 5, 300)) {
    echo json_encode(['success' => false, 'message' => 'Too many OTP requests. Please try again later.']);
    exit;
}

try {
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Don't reveal if email exists (security)
        echo json_encode(['success' => true, 'message' => 'If this email exists, an OTP has been sent.']);
        exit;
    }
    
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
    
    // Generate OTP
    $otp = generateOTP(6);
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 180); // 3 minutes
    
    // Invalidate old OTPs for this email
    $pdo->prepare("UPDATE login_otps SET verified = 1 WHERE email = ? AND verified = 0")->execute([$email]);
    
    // Store new OTP
    $stmt = $pdo->prepare("
        INSERT INTO login_otps (email, otp_code, token, expires_at, login_type) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$email, $otp, $token, $expiresAt, $loginType]);
    
    // Send OTP email
    require_once __DIR__ . '/email-service.php';
    $emailSent = sendOTPEmail($email, $otp, 'login');
    
    echo json_encode([
        'success' => true,
        'message' => 'OTP sent to your email',
        'token' => $token, // Return token for OTP verification
        'expires_in' => 180
    ]);
    
} catch(PDOException $e) {
    error_log("Error sending OTP: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error sending OTP. Please try again.']);
}
?>

