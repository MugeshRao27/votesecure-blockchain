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
$password = $data['password'] ?? '';
$role = $data['role'] ?? 'voter';
$loginType = $data['login_type'] ?? 'password'; // 'password' or 'otp'

// Rate limiting
if (!checkRateLimit("login_$email", 5, 300)) {
    echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please try again later.']);
    exit;
}

// Validation
if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

if ($loginType === 'password' && empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit;
}

try {
    // Find user by email - try to include account_status, but handle if column doesn't exist
    try {
        $stmt = $pdo->prepare("
            SELECT *, 
                   COALESCE(account_status, 
                       CASE 
                           WHEN temp_password IS NOT NULL AND temp_password != '' AND (password_changed IS NULL OR password_changed = 0) 
                           THEN 'TEMP_PASSWORD'
                           WHEN password_changed = 1 
                           THEN 'ACTIVE'
                           ELSE 'TEMP_PASSWORD'
                       END
                   ) as account_status
            FROM users 
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If account_status column doesn't exist, use simpler query
        if (strpos($e->getMessage(), 'account_status') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            // Set default account_status
            if ($user) {
                if (!empty($user['temp_password']) && (empty($user['password_changed']) || $user['password_changed'] == 0)) {
                    $user['account_status'] = 'TEMP_PASSWORD';
                } elseif (!empty($user['password_changed']) && $user['password_changed'] == 1) {
                    $user['account_status'] = 'ACTIVE';
                } else {
                    $user['account_status'] = 'TEMP_PASSWORD';
                }
            }
        } else {
            throw $e; // Re-throw if it's a different error
        }
    }

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit;
    }

    // Check role
    $userRole = strtolower(trim($user['role']));
    if (!empty($role) && $userRole !== strtolower(trim($role))) {
        echo json_encode(['success' => false, 'message' => 'Invalid role for this account']);
        exit;
    }

    // ========================================
    // ADMIN LOGIN - MANDATORY 2FA (Password + OTP)
    // ========================================
    if ($userRole === 'admin') {
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Password is required for admin login']);
            exit;
        }
        
        // Verify password first
        if (!password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            exit;
        }
        
        // Admin must complete 2FA - send OTP
        $otp = generateOTP(6);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 180); // 3 minutes
        
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
                login_type VARCHAR(20) DEFAULT 'admin',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lo_email (email),
                INDEX idx_lo_token (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Invalidate old OTPs
        $pdo->prepare("UPDATE login_otps SET verified = 1 WHERE email = ? AND verified = 0")->execute([$email]);
        
        // Store OTP
        $stmt = $pdo->prepare("
            INSERT INTO login_otps (email, otp_code, token, expires_at, login_type) 
            VALUES (?, ?, ?, ?, 'admin')
        ");
        $stmt->execute([$email, $otp, $token, $expiresAt]);
        
        // Send OTP email (non-blocking - continue even if email fails)
        try {
            require_once __DIR__ . '/email-service.php';
            $emailSent = sendOTPEmail($email, $otp, 'admin_login');
            if (!$emailSent) {
                error_log("Warning: Failed to send OTP email to {$email}, but login continues. OTP: {$otp}");
            }
        } catch (Exception $emailError) {
            error_log("Error sending OTP email to {$email}: " . $emailError->getMessage());
            error_log("OTP for manual entry: {$otp}");
            // Continue with login even if email fails - OTP is stored in database
        }
        
        // Return OTP token - admin must verify OTP to complete login
        unset($user['password']);
        unset($user['temp_password']);
        echo json_encode([
            'success' => true,
            'message' => 'Password verified. Please enter OTP sent to your email.',
            'requires_otp' => true,
            'login_token' => $token,
            'user' => $user
        ]);
        exit;
    }

    // ========================================
    // USER (VOTER) LOGIN - Password OR OTP
    // ========================================
    if ($userRole === 'voter') {
        if ($loginType === 'password') {
            if (empty($password)) {
                echo json_encode(['success' => false, 'message' => 'Password is required']);
                exit;
            }

            // Check both regular password and temp password
            $passwordMatches = password_verify($password, $user['password']);
            // Temp password is stored as plain text in temp_password column for email sending
            // But we also hash it in password column, so check both
            $tempPasswordMatched = false;
            if (!empty($user['temp_password'])) {
                // Check if password matches the plain temp_password (for initial login)
                $tempPasswordMatched = hash_equals($user['temp_password'], $password);
                // Also check if it matches the hashed password (which is set to temp password hash)
                if (!$tempPasswordMatched) {
                    $tempPasswordMatched = password_verify($password, $user['password']);
                }
            }

            if (!$passwordMatches && !$tempPasswordMatched) {
                echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                exit;
            }

            if (!isset($user['authorized']) || intval($user['authorized']) !== 1) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Your account is not yet authorized. Please wait for admin approval.',
                    'authorized' => false
                ]);
                exit;
            }

            $passwordChanged = isset($user['password_changed']) ? (int)$user['password_changed'] === 1 : false;
            $requiresPasswordChange = $tempPasswordMatched || !$passwordChanged;

            // Prepare password change token when required
            $passwordChangeToken = null;
            if ($requiresPasswordChange) {
                // Ensure password_resets table exists (safety for older installs)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS password_resets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        token VARCHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used TINYINT(1) DEFAULT 0,
                        used_at DATETIME NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_user (user_id),
                        CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $passwordChangeToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $tokenStmt = $pdo->prepare("
                    INSERT INTO password_resets (user_id, token, expires_at, created_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        token = VALUES(token),
                        expires_at = VALUES(expires_at),
                        used = 0,
                        used_at = NULL,
                        created_at = NOW()
                ");
                $tokenStmt->execute([$user['id'], $passwordChangeToken, $expiresAt]);
            }

            unset($user['password']);
            unset($user['temp_password']);
            
            // Get account_status if it exists
            $accountStatus = $user['account_status'] ?? 'TEMP_PASSWORD';
            if (empty($accountStatus) && !empty($user['temp_password'])) {
                $accountStatus = 'TEMP_PASSWORD';
            } elseif (empty($accountStatus) && !empty($user['password_changed'])) {
                $accountStatus = 'ACTIVE';
            }
            $user['account_status'] = $accountStatus;

            // Ensure role is lowercase for consistency
            $userRole = strtolower(trim($user['role'] ?? 'voter'));
            $jwtToken = generateJWT($user['id'], $user['email'], $userRole);

            echo json_encode([
                'success' => true,
                'message' => 'Credentials verified. Face verification required.',
                'token' => $jwtToken,
                'user' => $user,
                'requires_face_verification' => true,
                'requires_password_change' => $requiresPasswordChange,
                'password_change_token' => $passwordChangeToken,
                'account_status' => $accountStatus
            ]);
            exit;
        }

        echo json_encode([
            'success' => false,
            'message' => 'Please use send-otp.php first to receive OTP, then verify with verify-login-otp.php'
        ]);
        exit;
    }
    
    // Unknown role
    echo json_encode(['success' => false, 'message' => 'Invalid user role']);
    
} catch(PDOException $e) {
    error_log("Login error (PDO): " . $e->getMessage());
    error_log("Login error trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error during login. Please try again.',
        'debug' => 'Check server logs for details'
    ]);
} catch(Exception $e) {
    error_log("Login error (General): " . $e->getMessage());
    error_log("Login error trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Login error: ' . $e->getMessage(),
        'debug' => 'Check server logs for details'
    ]);
}
?>
