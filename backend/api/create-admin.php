<?php
/**
 * Create Admin Account Script
 * 
 * This script allows you to create an admin account for VoteSecure.
 * 
 * Usage:
 * 1. Run via command line: php create-admin.php
 * 2. Or access via browser: http://localhost/final_votesecure/backend/api/create-admin.php
 * 
 * SECURITY: After creating the admin account, delete or secure this file!
 */

require_once 'config.php';

// Only allow this script to run in development or with a secure token
$allowed = false;

// Check if running from command line (CLI)
if (php_sapi_name() === 'cli') {
    $allowed = true;
} else {
    // For web access, require a secure token
    $secureToken = getenv('ADMIN_CREATE_TOKEN') ?: 'CHANGE_THIS_SECURE_TOKEN';
    $providedToken = $_GET['token'] ?? '';
    
    if (hash_equals($secureToken, $providedToken)) {
        $allowed = true;
    } else {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Provide a valid token: ?token=YOUR_SECURE_TOKEN'
        ]);
        exit;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get input data
if (php_sapi_name() === 'cli') {
    // CLI mode - prompt for input
    echo "=== VoteSecure Admin Account Creation ===\n\n";
    
    $name = readline("Enter admin name: ");
    $email = readline("Enter admin email: ");
    $password = readline("Enter admin password (min 8 characters): ");
    $confirmPassword = readline("Confirm password: ");
    
    if (empty($name) || empty($email) || empty($password)) {
        echo "Error: All fields are required.\n";
        exit(1);
    }
    
    if ($password !== $confirmPassword) {
        echo "Error: Passwords do not match.\n";
        exit(1);
    }
    
    if (strlen($password) < 8) {
        echo "Error: Password must be at least 8 characters.\n";
        exit(1);
    }
} else {
    // Web mode - get from POST data
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Show form
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Create Admin Account - VoteSecure</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 500px; margin: 50px auto; padding: 20px; }
                .form-group { margin-bottom: 15px; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
                button { background: #667eea; color: white; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; }
                button:hover { background: #5568d3; }
                .error { color: red; margin-top: 10px; }
                .success { color: green; margin-top: 10px; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <h1>Create Admin Account</h1>
            <div class="warning">
                <strong>⚠️ Security Notice:</strong> After creating the admin account, delete or secure this file!
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Admin Name *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Password * (min 8 characters)</label>
                    <input type="password" name="password" required minlength="8">
                </div>
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" required minlength="8">
                </div>
                <button type="submit">Create Admin Account</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
    
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
}

// Validation
if (empty($name) || empty($email) || empty($password)) {
    $error = "All fields are required.";
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

if ($password !== $confirmPassword) {
    $error = "Passwords do not match.";
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

if (strlen($password) < 8) {
    $error = "Password must be at least 8 characters.";
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Invalid email format.";
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

try {
    // Check if email already exists (allow multiple admins, just check if email is taken)
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $error = "This email is already registered. Please use a different email or delete the existing account first.";
        if (php_sapi_name() === 'cli') {
            echo "Error: $error\n";
            echo "Existing account details:\n";
            echo "  ID: {$existing['id']}\n";
            echo "  Name: {$existing['name']}\n";
            echo "  Email: {$existing['email']}\n";
            echo "  Role: {$existing['role']}\n";
            exit(1);
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $error]);
        exit;
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Create admin account
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role, authorized, verified, created_at)
        VALUES (?, ?, ?, 'admin', 1, 1, NOW())
    ");
    
    $stmt->execute([$name, $email, $hashedPassword]);
    $adminId = $pdo->lastInsertId();
    
    $success = "Admin account created successfully!";
    $details = [
        'id' => $adminId,
        'name' => $name,
        'email' => $email,
        'role' => 'admin'
    ];
    
    if (php_sapi_name() === 'cli') {
        echo "\n✅ $success\n\n";
        echo "Admin Details:\n";
        echo "  ID: {$details['id']}\n";
        echo "  Name: {$details['name']}\n";
        echo "  Email: {$details['email']}\n";
        echo "  Role: {$details['role']}\n\n";
        echo "⚠️  IMPORTANT: Delete or secure this file (create-admin.php) after creating the admin account!\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $success,
            'admin' => $details,
            'warning' => 'Delete or secure this file (create-admin.php) after creating the admin account!'
        ]);
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    error_log("Admin creation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create admin account']);
}

?>

