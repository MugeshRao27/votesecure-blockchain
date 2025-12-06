<?php
// Turn off error display but log errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Don't set headers here - config.php will set them
// This prevents header conflicts

require_once '../config.php';
require_once '../auth-helper.php';

// Override config.php headers if needed (after includes)
if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Start output buffering to catch any unexpected output (after includes to avoid issues)
if (!ob_get_level()) {
    ob_start();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? '';
$newPassword = $data['new_password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

// Validate input
if (empty($token) || empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
    exit;
}

try {
    // Ensure password_resets table exists with required columns
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) DEFAULT 0,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user (user_id),
                KEY idx_token (token),
                CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Ensure 'used' column exists
        $columns = $pdo->query("SHOW COLUMNS FROM password_resets LIKE 'used'")->fetch();
        if (!$columns) {
            $pdo->exec("ALTER TABLE password_resets ADD COLUMN used TINYINT(1) DEFAULT 0");
        }
        
        // Ensure 'used_at' column exists
        $columns = $pdo->query("SHOW COLUMNS FROM password_resets LIKE 'used_at'")->fetch();
        if (!$columns) {
            $pdo->exec("ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL");
        }
    } catch (PDOException $e) {
        // Table might already exist, continue
        error_log("Note: password_resets table check: " . $e->getMessage());
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Verify token and get user
    $stmt = $pdo->prepare("
        SELECT u.*, pr.token as reset_token, pr.expires_at, pr.used
        FROM users u
        JOIN password_resets pr ON u.id = pr.user_id
        WHERE pr.token = ? 
        FOR UPDATE
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $pdo->rollBack();
        // Check if token exists but is expired or used
        $checkStmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ?");
        $checkStmt->execute([$token]);
        $tokenRecord = $checkStmt->fetch();
        
        // Clean buffer before output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        if ($tokenRecord) {
            $errorDetails = [];
            if ($tokenRecord['used'] == 1) {
                $errorDetails[] = 'Token has already been used';
            }
            if (strtotime($tokenRecord['expires_at']) <= time()) {
                $errorDetails[] = 'Token has expired';
            }
            error_log("Password change token issue: " . implode(', ', $errorDetails));
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid or expired token: ' . implode(', ', $errorDetails),
                'debug' => [
                    'token_exists' => true,
                    'used' => (bool)$tokenRecord['used'],
                    'expires_at' => $tokenRecord['expires_at'],
                    'is_expired' => strtotime($tokenRecord['expires_at']) <= time()
                ]
            ]);
        } else {
            error_log("Password change token not found: " . substr($token, 0, 10) . "...");
            echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        }
        exit;
    }
    
    // Check if token is expired
    if (strtotime($user['expires_at']) <= time()) {
        $pdo->rollBack();
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode([
            'success' => false, 
            'message' => 'Token has expired. Please log in again to get a new token.'
        ]);
        exit;
    }
    
    // Check if token is already used
    if ($user['used'] == 1) {
        $pdo->rollBack();
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode([
            'success' => false, 
            'message' => 'This token has already been used. Please log in again.'
        ]);
        exit;
    }
    
    // Hash the new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Ensure account_status column exists
    try {
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN IF NOT EXISTS account_status ENUM('TEMP_PASSWORD', 'ACTIVE', 'LOCKED', 'SUSPENDED') 
            DEFAULT 'TEMP_PASSWORD' 
            AFTER password_changed
        ");
    } catch (PDOException $e) {
        // Column might already exist, continue
    }
    
    // Update user's password, clear temp password, and set status to ACTIVE
    $stmt = $pdo->prepare("
        UPDATE users 
        SET password = ?, 
            temp_password = NULL,
            password_changed = 1,
            account_status = 'ACTIVE',
            login_attempts = 0,
            account_locked_until = NULL
        WHERE id = ?
    ");
    $stmt->execute([$hashedPassword, $user['id']]);
    
    // Mark token as used
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE token = ?");
    $stmt->execute([$token]);
    
    // Get updated user data with ACTIVE status first (needed for JWT generation)
    $updatedStmt = $pdo->prepare("SELECT id, name, email, role, password_changed, account_status FROM users WHERE id = ?");
    $updatedStmt->execute([$user['id']]);
    $updatedUser = $updatedStmt->fetch();
    
    // Ensure account_status is set
    if (empty($updatedUser['account_status'])) {
        $updatedUser['account_status'] = 'ACTIVE';
    }
    
    // Generate new JWT token with updated user data
    // Use generateJWT directly with email to avoid global $pdo issue
    try {
        $newToken = generateJWT($user['id'], $updatedUser['email'], $user['role']);
        
        if (empty($newToken)) {
            throw new Exception("generateJWT returned empty token");
        }
    } catch (Exception $tokenError) {
        error_log("JWT generation error: " . $tokenError->getMessage());
        throw new Exception("Failed to generate JWT token: " . $tokenError->getMessage());
    }
    
    // Log password change (optional - don't fail if logging fails)
    try {
        // Check if voter_activity_log table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'voter_activity_log'")->fetch();
        if ($tableCheck) {
            $logStmt = $pdo->prepare("
                INSERT INTO voter_activity_log 
                (user_id, activity_type, ip_address, user_agent, status, details, created_at)
                VALUES (?, 'password_change', ?, ?, 'success', 'Password changed successfully - Status set to ACTIVE', NOW())
            ");
            $logStmt->execute([
                $user['id'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        }
    } catch (Exception $logError) {
        // Logging failed but don't fail the password change
        error_log("Warning: Could not log password change activity: " . $logError->getMessage());
    }
    
    $pdo->commit();
    
    // Clean sensitive data
    unset($updatedUser['password']);
    unset($updatedUser['temp_password']);
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'Password changed successfully. Your account is now active.',
        'token' => $newToken,
        'user' => $updatedUser,
        'requires_password_change' => false,
        'account_status' => 'ACTIVE'
    ];
    
    // Clean output buffer completely before output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Output JSON response
    $jsonResponse = json_encode($response);
    
    if ($jsonResponse === false) {
        // JSON encoding failed
        error_log("JSON encoding error: " . json_last_error_msg());
        error_log("Response data that failed to encode: " . print_r($response, true));
        http_response_code(500);
        $fallbackResponse = json_encode([
            'success' => false,
            'message' => 'Failed to generate response. Please try again.'
        ]);
        echo $fallbackResponse ?: '{"success":false,"message":"Server error"}';
        exit;
    }
    
    // Ensure headers are set (in case they were cleared)
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    // Log the response for debugging
    error_log("Password change success - About to output response. Length: " . strlen($jsonResponse));
    error_log("Password change response preview: " . substr($jsonResponse, 0, 200));
    
    // Output the response
    echo $jsonResponse;
    
    // Force output flush
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    
    exit;
    
} catch (Throwable $e) {
    // Catch both Exception and Error (fatal errors)
    if (isset($pdo) && $pdo && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackError) {
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
    }
    
    error_log("Password change error: " . $e->getMessage());
    error_log("Password change error trace: " . $e->getTraceAsString());
    error_log("Password change error file: " . $e->getFile() . " line: " . $e->getLine());
    
    // Clean any output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure we always return a JSON response
    http_response_code(500);
    header('Content-Type: application/json');
    
    $errorResponse = [
        'success' => false,
        'message' => 'An error occurred while changing the password. Please try again.',
        'error' => $e->getMessage()
    ];
    
    $jsonError = json_encode($errorResponse);
    if ($jsonError === false) {
        // Even JSON encoding failed, send plain text
        echo '{"success":false,"message":"Server error occurred"}';
    } else {
        echo $jsonError;
    }
    exit;
}
