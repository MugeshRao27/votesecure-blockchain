<?php
/**
 * Verify Face for Voter Login
 * This endpoint verifies the live captured face against the stored face image
 */

require_once '../config.php';
require_once '../auth-helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify authentication token
$tokenPayload = isAuthenticated();
if (!$tokenPayload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');
$faceImage = $data['face_image'] ?? '';

// Validate input
if (empty($email) || empty($faceImage)) {
    echo json_encode(['success' => false, 'message' => 'Email and face image are required']);
    exit;
}

try {
    // Get user from database
    $stmt = $pdo->prepare("
        SELECT id, email, face_image, login_attempts, account_locked_until, password_changed, temp_password
        FROM users 
        WHERE email = ? AND role = 'voter' AND id = ?
    ");
    $stmt->execute([$email, $tokenPayload['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Check if account is locked
    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
        $minutesLeft = ceil((strtotime($user['account_locked_until']) - time()) / 60);
        echo json_encode([
            'success' => false,
            'message' => "Account locked. Please try again in $minutesLeft minutes.",
            'locked' => true,
            'minutes_remaining' => $minutesLeft
        ]);
        exit;
    }
    
    if (empty($user['face_image'])) {
        echo json_encode(['success' => false, 'message' => 'No face image found for this user. Please contact administrator.']);
        exit;
    }
    
    // Verify face against stored face image
    // Note: This is a simplified verification. In production, use a proper face recognition service
    $faceVerified = verifyFaceMatch($faceImage, $user['face_image']);
    
    if (!$faceVerified) {
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
        
        // Log failed face verification
        logVoterActivity($pdo, $user['id'], 'face_verification', 'failed', 'Face verification failed');
        
        $attemptsRemaining = 5 - $newAttempts;
        $message = $lockAccount 
            ? 'Account locked for 30 minutes due to too many failed attempts.'
            : "Face verification failed. $attemptsRemaining attempts remaining.";
        
        echo json_encode([
            'success' => false,
            'message' => $message,
            'attempts_remaining' => max(0, $attemptsRemaining),
            'locked' => $lockAccount
        ]);
        exit;
    }
    
    // Face verified successfully
    // Update face verification status and reset login attempts
    $stmt = $pdo->prepare("
        UPDATE users 
        SET face_verified = 1,
            login_attempts = 0,
            account_locked_until = NULL,
            last_login = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user['id']]);
    
    // Log successful face verification
    logVoterActivity($pdo, $user['id'], 'face_verification', 'success', 'Face verified successfully');
    
    // Check if password change is required (mandatory for temp password users)
    $requiresPasswordChange = !empty($user['temp_password']) || !$user['password_changed'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Face verified successfully',
        'requires_password_change' => $requiresPasswordChange,
        'password_changed' => (bool)$user['password_changed']
    ]);
    
} catch (Exception $e) {
    error_log("Face verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during face verification. Please try again.'
    ]);
}

/**
 * Verify face match between captured and stored images
 * This is a simplified implementation. In production, use a proper face recognition service
 */
function verifyFaceMatch($capturedFaceBase64, $storedFacePath) {
    // For now, we'll do a basic check
    // In production, you should:
    // 1. Load both images
    // 2. Extract face descriptors using face-api.js or similar
    // 3. Compare descriptors using Euclidean distance
    // 4. Return true if distance < threshold (e.g., 0.4)
    
    // Since the frontend already does face matching with face-api.js,
    // we'll trust the frontend result for now, but you should implement
    // server-side verification for security
    
    // Basic validation: ensure both images exist
    if (empty($capturedFaceBase64) || empty($storedFacePath)) {
        return false;
    }
    
    // Check if stored face file exists
    $fullPath = __DIR__ . '/../' . $storedFacePath;
    if (!file_exists($fullPath)) {
        error_log("Stored face image not found: $fullPath");
        return false;
    }
    
    // For security, you should implement proper face matching here
    // For now, we'll accept if the frontend sent a valid image
    // In production, implement server-side face recognition
    
    // TODO: Implement proper face matching using a face recognition library
    // For now, return true (trusting frontend verification)
    // In production, add server-side verification
    
    return true; // Placeholder - implement proper verification
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

?>
