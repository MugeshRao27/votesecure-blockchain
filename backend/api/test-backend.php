<?php
/**
 * Backend Connection Test Endpoint
 * Tests database connection, authentication, and email configuration
 */

require_once 'config.php';
require_once 'auth-helper.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => []
];

// Test 1: Database Connection
try {
    $stmt = $pdo->query("SELECT 1");
    $results['tests']['database'] = [
        'status' => 'success',
        'message' => 'Database connection successful'
    ];
} catch (Exception $e) {
    $results['tests']['database'] = [
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ];
}

// Test 2: Authentication Header Reading
$authHeader = '';
$headers = getAllHeaders();
if (!empty($headers['authorization'])) {
    $authHeader = $headers['authorization'];
} else {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 
                 $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 
                 $_SERVER['Authorization'] ?? '';
}

$results['tests']['auth_header'] = [
    'status' => !empty($authHeader) ? 'success' : 'warning',
    'message' => !empty($authHeader) ? 'Authorization header found' : 'No Authorization header detected',
    'header_value' => !empty($authHeader) ? substr($authHeader, 0, 20) . '...' : 'not found',
    'debug' => [
        'getallheaders_available' => function_exists('getallheaders'),
        'headers_from_getallheaders' => !empty($headers['authorization']),
        'HTTP_AUTHORIZATION' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'set' : 'not set',
        'REDIRECT_HTTP_AUTHORIZATION' => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? 'set' : 'not set',
        'Authorization' => isset($_SERVER['Authorization']) ? 'set' : 'not set'
    ]
];

// Test 3: JWT Token Verification
if (!empty($authHeader) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
    $payload = verifyJWT($token);
    
    if ($payload) {
        $results['tests']['jwt_token'] = [
            'status' => 'success',
            'message' => 'JWT token is valid',
            'user_id' => $payload['user_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'role' => $payload['role'] ?? null
        ];
        
        // Test 4: Admin Verification
        $admin = verifyAdminToken($pdo);
        if ($admin) {
            $results['tests']['admin_verification'] = [
                'status' => 'success',
                'message' => 'User is verified as admin',
                'admin_id' => $admin['id'] ?? null,
                'admin_email' => $admin['email'] ?? null
            ];
        } else {
            $results['tests']['admin_verification'] = [
                'status' => 'error',
                'message' => 'User is not an admin or admin verification failed'
            ];
        }
    } else {
        $results['tests']['jwt_token'] = [
            'status' => 'error',
            'message' => 'JWT token is invalid or expired'
        ];
    }
} else {
    $results['tests']['jwt_token'] = [
        'status' => 'warning',
        'message' => 'No Bearer token found in Authorization header'
    ];
}

// Test 5: Email Configuration
require_once 'email-service.php';
global $emailConfig;

$results['tests']['email_config'] = [
    'status' => 'info',
    'provider' => $emailConfig['provider'] ?? 'not set',
    'smtp_host' => $emailConfig['smtp']['host'] ?? 'not set',
    'smtp_port' => $emailConfig['smtp']['port'] ?? 'not set',
    'smtp_username' => !empty($emailConfig['smtp']['username']) ? 'configured' : 'not configured',
    'smtp_password' => !empty($emailConfig['smtp']['password']) ? 'configured' : 'not configured',
    'from_email' => $emailConfig['smtp']['from_email'] ?? 'not set'
];

// Test 6: Users Table
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch();
    $results['tests']['users_table'] = [
        'status' => 'success',
        'message' => 'Users table accessible',
        'user_count' => $count['count'] ?? 0
    ];
} catch (Exception $e) {
    $results['tests']['users_table'] = [
        'status' => 'error',
        'message' => 'Users table error: ' . $e->getMessage()
    ];
}

// Overall status
$hasErrors = false;
$hasWarnings = false;
foreach ($results['tests'] as $test) {
    if (isset($test['status'])) {
        if ($test['status'] === 'error') $hasErrors = true;
        if ($test['status'] === 'warning') $hasWarnings = true;
    }
}

$results['overall_status'] = $hasErrors ? 'error' : ($hasWarnings ? 'warning' : 'success');

echo json_encode($results, JSON_PRETTY_PRINT);
?>

