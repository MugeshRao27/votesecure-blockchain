<?php
/**
 * Authentication and Security Helper Functions
 * Provides JWT, OTP, CSRF, and rate limiting functionality
 */

// JWT Secret Key - Change this in production!
define('JWT_SECRET', 'your-secret-jwt-key-change-in-production-' . md5(__DIR__));
define('JWT_EXPIRY', 86400); // 24 hours

/**
 * Generate JWT Token
 */
function generateJWT($userId, $email, $role) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $userId,
        'email' => $email,
        'role' => $role,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY
    ]);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

/**
 * Verify JWT Token
 */
function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($base64Header, $base64Payload, $base64Signature) = $parts;
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
    $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    if (!hash_equals($base64Signature, $expectedSignature)) {
        return false;
    }
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);
    
    if (!$payload || $payload['exp'] < time()) {
        return false;
    }
    
    return $payload;
}

/**
 * Generate 6-digit OTP
 */
function generateOTP($length = 6) {
    return str_pad(rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * Send OTP Email (Uses email-service.php)
 * Note: This function is defined in email-service.php to avoid conflicts
 */

/**
 * Rate Limiting - Simple in-memory (use Redis in production)
 */
$rateLimitStore = [];

function checkRateLimit($identifier, $maxAttempts = 5, $windowSeconds = 300) {
    global $rateLimitStore;
    
    $key = $identifier;
    $now = time();
    
    if (!isset($rateLimitStore[$key])) {
        $rateLimitStore[$key] = ['attempts' => 0, 'reset' => $now + $windowSeconds];
    }
    
    if ($now > $rateLimitStore[$key]['reset']) {
        $rateLimitStore[$key] = ['attempts' => 0, 'reset' => $now + $windowSeconds];
    }
    
    if ($rateLimitStore[$key]['attempts'] >= $maxAttempts) {
        return false;
    }
    
    $rateLimitStore[$key]['attempts']++;
    return true;
}

/**
 * Generate CSRF Token
 */
function generateCSRFToken() {
    if (!isset($_SESSION)) {
        session_start();
    }
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION)) {
        session_start();
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize Input
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Get all HTTP headers (cross-platform compatible)
 */
function getAllHeaders() {
    $headers = [];
    
    // Try getallheaders() first (works in Apache module mode)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        // Normalize header keys to lowercase for consistency
        if ($headers) {
            $normalized = [];
            foreach ($headers as $key => $value) {
                $normalized[strtolower($key)] = $value;
            }
            $headers = $normalized;
        }
    }
    
    // Fallback: manually extract from $_SERVER (works in CGI/FastCGI mode)
    if (empty($headers)) {
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($headerName)] = $value;
            }
        }
    }
    
    return $headers;
}

/**
 * Check if user is authenticated (JWT)
 */
function isAuthenticated() {
    // Get Authorization header from multiple possible sources
    $authHeader = '';
    
    // Try getallheaders() first
    $headers = getAllHeaders();
    if (!empty($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    }
    
    // Fallback to $_SERVER variables (for different server configurations)
    if (empty($authHeader)) {
        // Check various possible locations
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 
                     $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 
                     $_SERVER['Authorization'] ?? '';
    }
    
    // Also check if Authorization header was passed via apache_request_headers()
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        if (isset($apacheHeaders['Authorization'])) {
            $authHeader = $apacheHeaders['Authorization'];
        } elseif (isset($apacheHeaders['authorization'])) {
            $authHeader = $apacheHeaders['authorization'];
        }
    }
    
    // Extract Bearer token
    if (!empty($authHeader) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
        if (!empty($token)) {
            $payload = verifyJWT($token);
            return $payload ? $payload : false;
        }
    }
    
    // Log for debugging (only in development)
    if (isset($_SERVER['HTTP_HOST']) && 
        (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
         strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
        error_log("Auth debug - Headers: " . print_r($headers, true));
        error_log("Auth debug - SERVER vars: " . print_r([
            'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not set',
            'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'not set',
            'Authorization' => $_SERVER['Authorization'] ?? 'not set'
        ], true));
    }
    
    return false;
}

/**
 * Require Authentication
 */
function requireAuth($requiredRole = null) {
    $user = isAuthenticated();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    
    if ($requiredRole) {
        $userRole = isset($user['role']) ? strtolower(trim($user['role'])) : '';
        $requiredRoleLower = strtolower(trim($requiredRole));
        if ($userRole !== $requiredRoleLower) {
            http_response_code(403);
            error_log("Role mismatch: JWT role='{$userRole}', required='{$requiredRoleLower}', user_id={$user['user_id']}");
            echo json_encode([
                'success' => false, 
                'message' => 'Insufficient permissions',
                'debug' => [
                    'jwt_role' => $userRole,
                    'required_role' => $requiredRoleLower,
                    'user_id' => $user['user_id'] ?? null
                ]
            ]);
            exit;
        }
    }
    
    return $user;
}

/**
 * Get current authenticated user
 */
function getCurrentUser($pdo) {
    $user = isAuthenticated();
    if (!$user) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT id, name, email, role, authorized, verified FROM users WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    return $stmt->fetch();
}

/**
 * Verify Admin Token - Check if user is authenticated and is an admin
 */
function verifyAdminToken($pdo) {
    $user = isAuthenticated();
    if (!$user) {
        // Log authentication failure for debugging
        if (isset($_SERVER['HTTP_HOST']) && 
            (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
             strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
            error_log("verifyAdminToken: User not authenticated. No valid JWT token found.");
        }
        return false;
    }
    
    // Get full user data from database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$user['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        // Log role check failure for debugging
        if (isset($_SERVER['HTTP_HOST']) && 
            (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
             strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
            // Check if user exists but is not admin
            $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
            $stmt->execute([$user['user_id']]);
            $userCheck = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userCheck) {
                error_log("verifyAdminToken: User ID {$user['user_id']} exists but role is '{$userCheck['role']}', not 'admin'");
            } else {
                error_log("verifyAdminToken: User ID {$user['user_id']} from JWT does not exist in database");
            }
        }
        return false;
    }
    
    // Remove sensitive data
    unset($admin['password']);
    unset($admin['temp_password']);
    
    return $admin;
}

/**
 * Generate JWT Token (alias for generateJWT for backward compatibility)
 */
function generateJwtToken($userId, $role) {
    // Get email from database
    global $pdo;
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return false;
    }
    
    return generateJWT($userId, $user['email'], $role);
}

?>
