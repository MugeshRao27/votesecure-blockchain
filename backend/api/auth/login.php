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
$password = $data['password'] ?? '';
$faceImage = $data['face_image'] ?? null;

// Validate input
if (empty($voterId) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID and password are required']);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get user by voter ID
    $stmt = $pdo->prepare("
        SELECT u.*, 
               CASE 
                   WHEN u.account_locked_until IS NOT NULL AND u.account_locked_until > NOW() 
                   THEN 1 
                   ELSE 0 
               END as is_locked,
               TIMESTAMPDIFF(MINUTE, u.account_locked_until, NOW()) as minutes_until_unlock
        FROM users u 
        WHERE u.voter_id = ? AND u.role = 'voter'
    ");
    $stmt->execute([$voterId]);
    $user = $stmt->fetch();
    
    // Check if account is locked
    if ($user && $user['is_locked']) {
        $minutesLeft = abs((int)$user['minutes_until_unlock']);
        $pdo->rollBack();
        
        // Log failed login attempt
        logVoterActivity($pdo, $user['id'], 'login', 'failed', 'Account temporarily locked');
        
        echo json_encode([
            'success' => false,
            'message' => "Account locked. Please try again in $minutesLeft minutes.",
            'locked' => true,
            'minutes_remaining' => $minutesLeft
        ]);
        exit;
    }
    
    // Verify user exists and password is correct
    if (!$user || !password_verify($password, $user['temp_password'] ?? $user['password'])) {
        // Increment failed login attempts
        if ($user) {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET login_attempts = login_attempts + 1,
                    account_locked_until = CASE 
                        WHEN login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                        ELSE NULL 
                    END
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            
            // Log failed login attempt
            logVoterActivity($pdo, $user['id'], 'login', 'failed', 'Invalid credentials');
            
            $attemptsRemaining = 5 - ($user['login_attempts'] + 1);
            $message = $attemptsRemaining > 0 
                ? "Invalid credentials. $attemptsRemaining attempts remaining."
                : 'Account locked for 30 minutes due to too many failed attempts.';
                
            echo json_encode([
                'success' => false,
                'message' => $message,
                'attempts_remaining' => max(0, $attemptsRemaining)
            ]);
        } else {
            // Don't reveal that the user doesn't exist
            echo json_encode([
                'success' => false,
                'message' => 'Invalid credentials',
                'attempts_remaining' => 4 // Don't reveal actual attempts
            ]);
        }
        
        $pdo->commit();
        exit;
    }
    
    // Check if using temporary password
    if (!empty($user['temp_password']) && password_verify($password, $user['temp_password'])) {
        // If using temp password, force password change
        // But we still need to allow face verification, so generate a temporary JWT
        $tempJwt = generateJwtToken($user['id'], $user['role']);
        
        // Generate a token for password reset
        $passwordResetToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database
        $stmt = $pdo->prepare("
            INSERT INTO password_resets (user_id, token, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                token = VALUES(token),
                expires_at = VALUES(expires_at),
                created_at = VALUES(created_at)
        ");
        $stmt->execute([$user['id'], $passwordResetToken, $expiresAt]);
        
        // Clean user data before sending
        unset($user['password']);
        unset($user['temp_password']);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'requires_password_change' => true,
            'token' => $tempJwt, // JWT token for face verification
            'password_change_token' => $passwordResetToken, // Token for password change
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'face_image' => $user['face_image'] ?? null
            ],
            'message' => 'Please change your temporary password'
        ]);
        exit;
    }
    
    // If face image is provided, verify it
    if ($faceImage) {
        // In a real implementation, you would use a face recognition service here
        // This is a placeholder for the face verification logic
        $faceVerified = verifyFace($faceImage, $user['face_image']);
        
        if (!$faceVerified) {
            // Increment failed login attempts for failed face verification
            $stmt = $pdo->prepare("
                UPDATE users 
                SET login_attempts = login_attempts + 1,
                    account_locked_until = CASE 
                        WHEN login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                        ELSE NULL 
                    END
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            
            // Log failed face verification
            logVoterActivity($pdo, $user['id'], 'face_verification', 'failed', 'Face verification failed');
            
            $attemptsRemaining = 5 - ($user['login_attempts'] + 1);
            $message = $attemptsRemaining > 0 
                ? "Face verification failed. $attemptsRemaining attempts remaining."
                : 'Account locked for 30 minutes due to too many failed attempts.';
            
            $pdo->commit();
            
            echo json_encode([
                'success' => false,
                'message' => $message,
                'requires_face_verification' => true,
                'attempts_remaining' => max(0, $attemptsRemaining)
            ]);
            exit;
        }
        
        // Update face verification status
        $stmt = $pdo->prepare("UPDATE users SET face_verified = 1 WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Log successful face verification
        logVoterActivity($pdo, $user['id'], 'face_verification', 'success', 'Face verified successfully');
    } else if (!$user['face_verified']) {
        // If face is not verified and no face image provided, request it
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'requires_face_verification' => true,
            'message' => 'Face verification required'
        ]);
        exit;
    }
    
    // Check if OTP is required
    if ($user['otp_required'] && empty($data['otp'])) {
        // Generate and send OTP if not already sent or expired
        if (empty($user['otp']) || strtotime($user['otp_expiry']) < time()) {
            $otp = generateAndSendOtp($pdo, $user['id'], $user['email'], $user['phone_number']);
            
            if (!$otp) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to send OTP. Please try again.'
                ]);
                exit;
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'requires_otp' => true,
            'message' => 'OTP has been sent to your registered email/phone',
            'otp_sent_to' => maskEmail($user['email']) . ' / ' . maskPhone($user['phone_number'])
        ]);
        exit;
    }
    
    // Verify OTP if provided
    if (!empty($data['otp'])) {
        if ($user['otp'] !== $data['otp'] || strtotime($user['otp_expiry']) < time()) {
            // Increment failed login attempts for failed OTP
            $stmt = $pdo->prepare("
                UPDATE users 
                SET login_attempts = login_attempts + 1,
                    account_locked_until = CASE 
                        WHEN login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                        ELSE NULL 
                    END
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            
            // Log failed OTP attempt
            logVoterActivity($pdo, $user['id'], 'otp_verification', 'failed', 'Invalid or expired OTP');
            
            $attemptsRemaining = 5 - ($user['login_attempts'] + 1);
            $message = $attemptsRemaining > 0 
                ? "Invalid or expired OTP. $attemptsRemaining attempts remaining."
                : 'Account locked for 30 minutes due to too many failed attempts.';
            
            $pdo->commit();
            
            echo json_encode([
                'success' => false,
                'message' => $message,
                'requires_otp' => true,
                'attempts_remaining' => max(0, $attemptsRemaining)
            ]);
            exit;
        }
        
        // Clear OTP after successful verification
        $stmt = $pdo->prepare("UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Log successful OTP verification
        logVoterActivity($pdo, $user['id'], 'otp_verification', 'success', 'OTP verified successfully');
    }
    
    // Reset login attempts on successful login
    $stmt = $pdo->prepare("
        UPDATE users 
        SET login_attempts = 0, 
            account_locked_until = NULL,
            last_login = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user['id']]);
    
    // Generate JWT token
    $token = generateJwtToken($user['id'], $user['role']);
    
    // Log successful login
    logVoterActivity($pdo, $user['id'], 'login', 'success', 'Login successful');
    
    $pdo->commit();
    
    // Return success response with token
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'voter_id' => $user['voter_id'],
            'role' => $user['role'],
            'has_voted' => (bool)$user['has_voted'],
            'face_image' => $user['face_image'] ?? null,
            'authorized' => isset($user['authorized']) ? (bool)$user['authorized'] : false,
            'password_changed' => isset($user['password_changed']) ? (bool)$user['password_changed'] : false,
            'account_status' => $user['account_status'] ?? null
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during login. Please try again.'
    ]);
}

/**
 * Verify face against stored face image
 * This is a placeholder - in a real implementation, you would use a face recognition service
 */
function verifyFace($capturedFace, $storedFacePath) {
    // In a real implementation, you would:
    // 1. Load the stored face image from $storedFacePath
    // 2. Compare it with $capturedFace using a face recognition library/service
    // 3. Return true if they match, false otherwise
    
    // For this example, we'll simulate a 95% success rate
    return rand(1, 100) <= 95;
}

/**
 * Generate and send OTP to user's email/phone
 */
function generateAndSendOtp($pdo, $userId, $email, $phone) {
    // Generate 6-digit OTP
    $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    // Store OTP in database
    $stmt = $pdo->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?");
    $stmt->execute([$otp, $expiry, $userId]);
    
    // In a real implementation, you would send the OTP via email/SMS
    // This is a placeholder
    $emailSent = true; // Set to false if sending fails
    
    if ($emailSent) {
        // Log OTP sent
        logVoterActivity($pdo, $userId, 'otp_sent', 'success', 'OTP sent to user');
        return $otp;
    }
    
    return false;
}

/**
 * Mask email for display
 */
function maskEmail($email) {
    if (empty($email)) return '';
    
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    
    $username = $parts[0];
    $domain = $parts[1];
    
    $maskedUsername = substr($username, 0, 2) . str_repeat('*', max(0, strlen($username) - 4)) . substr($username, -2);
    
    return $maskedUsername . '@' . $domain;
}

/**
 * Mask phone number for display
 */
function maskPhone($phone) {
    if (empty($phone)) return '';
    
    $length = strlen($phone);
    if ($length <= 4) return str_repeat('*', $length);
    
    return str_repeat('*', $length - 4) . substr($phone, -4);
}

/**
 * Log voter activity
 */
function logVoterActivity($pdo, $userId, $activityType, $status, $details = '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO voter_activity_log 
            (user_id, activity_type, ip_address, user_agent, status, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $activityType,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $status,
            $details
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}
