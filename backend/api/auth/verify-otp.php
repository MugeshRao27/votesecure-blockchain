<?php
require_once '../config.php';
require_once '../auth-helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$voterId = $data['voter_id'] ?? '';
$otp = $data['otp'] ?? '';

// Validate input
if (empty($voterId) || empty($otp)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID and OTP are required']);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get user by voter ID
    $stmt = $pdo->prepare("
        SELECT id, otp, otp_expiry, login_attempts, account_locked_until
        FROM users 
        WHERE voter_id = ? AND role = 'voter'
        FOR UPDATE
    ");
    $stmt->execute([$voterId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    // Check if account is locked
    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
        $minutesLeft = ceil((strtotime($user['account_locked_until']) - time()) / 60);
        $pdo->rollBack();
        
        // Log failed OTP attempt
        logVoterActivity($pdo, $user['id'], 'otp_verification', 'failed', 'Account locked');
        
        echo json_encode([
            'success' => false,
            'message' => "Account locked. Please try again in $minutesLeft minutes.",
            'locked' => true,
            'minutes_remaining' => $minutesLeft
        ]);
        exit;
    }
    
    // Check if OTP matches and is not expired
    $currentTime = date('Y-m-d H:i:s');
    if (empty($user['otp']) || $user['otp'] !== $otp || $user['otp_expiry'] < $currentTime) {
        // Increment failed login attempts
        $newAttempts = $user['login_attempts'] + 1;
        $lockAccount = $newAttempts >= 5;
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET login_attempts = ?,
                account_locked_until = ?
            WHERE id = ?
        ");
        
        $lockUntil = $lockAccount ? date('Y-m-d H:i:s', strtotime('+30 minutes')) : null;
        $stmt->execute([$newAttempts, $lockUntil, $user['id']]);
        
        // Log failed OTP attempt
        logVoterActivity($pdo, $user['id'], 'otp_verification', 'failed', 'Invalid or expired OTP');
        
        $attemptsRemaining = 5 - $newAttempts;
        $message = $lockAccount 
            ? 'Account locked for 30 minutes due to too many failed attempts.'
            : "Invalid or expired OTP. $attemptsRemaining attempts remaining.";
        
        $pdo->commit();
        
        echo json_encode([
            'success' => false,
            'message' => $message,
            'attempts_remaining' => max(0, $attemptsRemaining),
            'locked' => $lockAccount
        ]);
        exit;
    }
    
    // OTP is valid, clear it and reset login attempts
    $stmt = $pdo->prepare("
        UPDATE users 
        SET otp = NULL, 
            otp_expiry = NULL,
            login_attempts = 0,
            account_locked_until = NULL,
            last_login = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user['id']]);
    
    // Generate JWT token
    $token = generateJwtToken($user['id'], 'voter');
    
    // Log successful OTP verification
    logVoterActivity($pdo, $user['id'], 'otp_verification', 'success', 'OTP verified successfully');
    
    $pdo->commit();
    
    // Return success response with token
    echo json_encode([
        'success' => true,
        'message' => 'OTP verified successfully',
        'token' => $token
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("OTP verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during OTP verification. Please try again.'
    ]);
}
