<?php
/**
 * Fix Admin Account - Diagnostic and Recovery Tool
 * 
 * This script helps diagnose and fix admin login issues:
 * 1. Checks if admin accounts exist
 * 2. Allows you to create a new admin account if needed
 * 
 * Usage:
 * - Via browser: http://localhost/final_votesecure/backend/api/fix-admin-account.php
 * - Via CLI: php fix-admin-account.php
 */

require_once 'config.php';

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? 'check';
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    // CLI mode
    echo "=== VoteSecure Admin Account Diagnostic Tool ===\n\n";
    
    // Check existing admins
    echo "1. Checking for existing admin accounts...\n";
    try {
        $stmt = $pdo->query("SELECT id, name, email, role, authorized, created_at FROM users WHERE role = 'admin' ORDER BY id");
        $admins = $stmt->fetchAll();
        
        if (empty($admins)) {
            echo "   ❌ No admin accounts found in database!\n\n";
            echo "   This is why you're getting 'Invalid email or password' error.\n";
            echo "   The admin account was likely deleted.\n\n";
            
            // Prompt to create new admin
            echo "Would you like to create a new admin account? (yes/no): ";
            $response = trim(fgets(STDIN));
            
            if (strtolower($response) === 'yes' || strtolower($response) === 'y') {
                echo "\n=== Creating New Admin Account ===\n\n";
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
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo "Error: Invalid email format.\n";
                    exit(1);
                }
                
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    echo "Error: This email is already registered.\n";
                    exit(1);
                }
                
                // Create admin
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, password, role, authorized, verified, created_at)
                    VALUES (?, ?, ?, 'admin', 1, 1, NOW())
                ");
                $stmt->execute([$name, $email, $hashedPassword]);
                $adminId = $pdo->lastInsertId();
                
                echo "\n✅ Admin account created successfully!\n\n";
                echo "Admin Details:\n";
                echo "  ID: $adminId\n";
                echo "  Name: $name\n";
                echo "  Email: $email\n";
                echo "  Role: admin\n\n";
                echo "You can now login with these credentials.\n";
            } else {
                echo "\nTo create an admin account later, run:\n";
                echo "  php create-admin.php\n";
                echo "Or visit: http://localhost/final_votesecure/backend/api/create-admin.php\n";
            }
        } else {
            echo "   ✅ Found " . count($admins) . " admin account(s):\n\n";
            foreach ($admins as $admin) {
                echo "   ID: {$admin['id']}\n";
                echo "   Name: {$admin['name']}\n";
                echo "   Email: {$admin['email']}\n";
                echo "   Authorized: " . ($admin['authorized'] ? 'Yes' : 'No') . "\n";
                echo "   Created: {$admin['created_at']}\n";
                echo "   " . str_repeat("-", 40) . "\n";
            }
            
            echo "\nIf you're still getting login errors, the issue might be:\n";
            echo "  1. Wrong password\n";
            echo "  2. Email case sensitivity (try exact email from above)\n";
            echo "  3. Password hash corruption\n\n";
            
            // Check for specific email
            if (isset($argv[1])) {
                $testEmail = $argv[1];
                echo "Testing login for: $testEmail\n";
                $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND role = 'admin'");
                $stmt->execute([$testEmail]);
                $testAdmin = $stmt->fetch();
                
                if ($testAdmin) {
                    echo "✅ Admin found with email: {$testAdmin['email']}\n";
                    echo "   Make sure you're using the exact email: {$testAdmin['email']}\n";
                } else {
                    echo "❌ No admin found with email: $testEmail\n";
                    echo "   Available admin emails:\n";
                    foreach ($admins as $admin) {
                        echo "     - {$admin['email']}\n";
                    }
                }
            }
        }
    } catch (PDOException $e) {
        echo "   ❌ Database error: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    exit(0);
}

// Web mode
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Admin Account - VoteSecure</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            margin-top: 0;
        }
        .section {
            margin: 30px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        .admin-list {
            margin: 15px 0;
        }
        .admin-item {
            padding: 10px;
            margin: 5px 0;
            background: white;
            border-left: 4px solid #667eea;
        }
        .error {
            color: #d32f2f;
            background: #ffebee;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .success {
            color: #2e7d32;
            background: #e8f5e9;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Admin Account Diagnostic Tool</h1>
        
        <?php
        try {
            // Check for existing admins
            $stmt = $pdo->query("SELECT id, name, email, role, authorized, created_at FROM users WHERE role = 'admin' ORDER BY id");
            $admins = $stmt->fetchAll();
            
            if (empty($admins)) {
                echo '<div class="error">';
                echo '<strong>❌ No admin accounts found!</strong><br>';
                echo 'This is why you\'re getting "Invalid email or password" error.<br>';
                echo 'The admin account was likely deleted when you removed users.';
                echo '</div>';
                
                // Show create form
                if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                    $name = trim($_POST['name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $confirmPassword = $_POST['confirm_password'] ?? '';
                    
                    $errors = [];
                    if (empty($name)) $errors[] = "Name is required";
                    if (empty($email)) $errors[] = "Email is required";
                    if (empty($password)) $errors[] = "Password is required";
                    if ($password !== $confirmPassword) $errors[] = "Passwords do not match";
                    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
                    
                    if (empty($errors)) {
                        // Check if email exists
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $errors[] = "This email is already registered";
                        } else {
                            // Create admin
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("
                                INSERT INTO users (name, email, password, role, authorized, verified, created_at)
                                VALUES (?, ?, ?, 'admin', 1, 1, NOW())
                            ");
                            $stmt->execute([$name, $email, $hashedPassword]);
                            $adminId = $pdo->lastInsertId();
                            
                            echo '<div class="success">';
                            echo '<strong>✅ Admin account created successfully!</strong><br><br>';
                            echo '<strong>Admin Details:</strong><br>';
                            echo "ID: $adminId<br>";
                            echo "Name: " . htmlspecialchars($name) . "<br>";
                            echo "Email: " . htmlspecialchars($email) . "<br>";
                            echo "Role: admin<br><br>";
                            echo 'You can now <a href="../../src/components/AuthPage.js">login</a> with these credentials.';
                            echo '</div>';
                            
                            // Refresh to show new admin
                            echo '<script>setTimeout(function(){ window.location.href = "fix-admin-account.php"; }, 3000);</script>';
                            exit;
                        }
                    }
                    
                    if (!empty($errors)) {
                        echo '<div class="error">';
                        echo '<strong>Errors:</strong><ul>';
                        foreach ($errors as $error) {
                            echo "<li>$error</li>";
                        }
                        echo '</ul></div>';
                    }
                }
                
                echo '<div class="section">';
                echo '<h2>Create New Admin Account</h2>';
                echo '<form method="POST" action="?action=create">';
                echo '<div class="form-group">';
                echo '<label>Admin Name *</label>';
                echo '<input type="text" name="name" required value="' . htmlspecialchars($_POST['name'] ?? '') . '">';
                echo '</div>';
                echo '<div class="form-group">';
                echo '<label>Email *</label>';
                echo '<input type="email" name="email" required value="' . htmlspecialchars($_POST['email'] ?? '') . '">';
                echo '</div>';
                echo '<div class="form-group">';
                echo '<label>Password * (min 8 characters)</label>';
                echo '<input type="password" name="password" required minlength="8">';
                echo '</div>';
                echo '<div class="form-group">';
                echo '<label>Confirm Password *</label>';
                echo '<input type="password" name="confirm_password" required minlength="8">';
                echo '</div>';
                echo '<button type="submit">Create Admin Account</button>';
                echo '</form>';
                echo '</div>';
            } else {
                echo '<div class="success">';
                echo '<strong>✅ Found ' . count($admins) . ' admin account(s):</strong>';
                echo '</div>';
                
                echo '<div class="section">';
                echo '<h2>Existing Admin Accounts</h2>';
                echo '<div class="admin-list">';
                foreach ($admins as $admin) {
                    echo '<div class="admin-item">';
                    echo '<strong>ID:</strong> ' . $admin['id'] . '<br>';
                    echo '<strong>Name:</strong> ' . htmlspecialchars($admin['name']) . '<br>';
                    echo '<strong>Email:</strong> ' . htmlspecialchars($admin['email']) . '<br>';
                    echo '<strong>Authorized:</strong> ' . ($admin['authorized'] ? 'Yes' : 'No') . '<br>';
                    echo '<strong>Created:</strong> ' . $admin['created_at'];
                    echo '</div>';
                }
                echo '</div>';
                echo '</div>';
                
                echo '<div class="warning">';
                echo '<strong>⚠️ If you\'re still getting login errors:</strong><ul>';
                echo '<li>Make sure you\'re using the exact email address shown above (case-sensitive)</li>';
                echo '<li>Check if the password is correct</li>';
                echo '<li>If password doesn\'t work, you may need to reset it or create a new admin account</li>';
                echo '</ul>';
                echo '</div>';
                
                echo '<div class="section">';
                echo '<h2>Create Additional Admin Account</h2>';
                echo '<p>You can create another admin account if needed:</p>';
                echo '<a href="?action=create"><button class="btn-secondary">Create New Admin</button></a>';
                echo '</div>';
            }
        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage());
            echo '</div>';
        }
        ?>
        
        <div class="section">
            <h2>Alternative: Use Command Line</h2>
            <p>You can also run this script from command line:</p>
            <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px;">php fix-admin-account.php</pre>
            <p>Or use the create-admin.php script:</p>
            <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px;">php create-admin.php</pre>
        </div>
    </div>
</body>
</html>

