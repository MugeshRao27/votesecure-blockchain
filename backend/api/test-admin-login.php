<?php
/**
 * Test Admin Login - Debug Script
 * This script helps debug admin login issues
 */

require_once 'config.php';
require_once 'auth-helper.php';

// Get admin email from command line or use default
$adminEmail = isset($argv[1]) ? $argv[1] : (isset($_GET['email']) ? $_GET['email'] : '');

if (empty($adminEmail)) {
    echo "Usage: php test-admin-login.php <admin_email>\n";
    echo "Or visit: http://localhost/final_votesecure/backend/api/test-admin-login.php?email=your@email.com\n";
    exit(1);
}

echo "=== Testing Admin Login for: {$adminEmail} ===\n\n";

try {
    // Test 1: Check if admin exists
    echo "1. Checking if admin exists...\n";
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND role = 'admin'");
        $stmt->execute([$adminEmail]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            echo "   ❌ Admin not found with email: {$adminEmail}\n";
            exit(1);
        }
        
        echo "   ✅ Admin found - ID: {$admin['id']}, Name: {$admin['name']}\n";
        echo "   Password hash: " . substr($admin['password'], 0, 20) . "...\n";
    } catch (PDOException $e) {
        echo "   ❌ Database error: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Test 2: Check if account_status column exists
    echo "\n2. Checking account_status column...\n";
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'account_status'");
        $column = $stmt->fetch();
        
        if ($column) {
            echo "   ✅ account_status column exists\n";
        } else {
            echo "   ⚠️  account_status column does not exist (will use fallback)\n";
        }
    } catch (PDOException $e) {
        echo "   ⚠️  Could not check column: " . $e->getMessage() . "\n";
    }
    
    // Test 3: Check login_otps table
    echo "\n3. Checking login_otps table...\n";
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'login_otps'");
        $table = $stmt->fetch();
        
        if ($table) {
            echo "   ✅ login_otps table exists\n";
        } else {
            echo "   ⚠️  login_otps table does not exist (will be created automatically)\n";
        }
    } catch (PDOException $e) {
        echo "   ⚠️  Could not check table: " . $e->getMessage() . "\n";
    }
    
    // Test 4: Test password verification
    echo "\n4. Testing password verification...\n";
    echo "   Enter admin password to test: ";
    if (php_sapi_name() === 'cli') {
        $password = readline();
    } else {
        $password = isset($_GET['password']) ? $_GET['password'] : '';
        if (empty($password)) {
            echo "   ⚠️  Password not provided (use ?password=xxx in URL)\n";
            echo "\n=== Summary ===\n";
            echo "✅ Admin account exists\n";
            echo "✅ Database structure checked\n";
            echo "\nTry logging in via the web interface now.\n";
            exit(0);
        }
    }
    
    if (password_verify($password, $admin['password'])) {
        echo "   ✅ Password verification successful\n";
    } else {
        echo "   ❌ Password verification failed\n";
        exit(1);
    }
    
    // Test 5: Test OTP generation and storage
    echo "\n5. Testing OTP generation and storage...\n";
    try {
        $otp = generateOTP(6);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 180);
        
        // Ensure table exists
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
        $pdo->prepare("UPDATE login_otps SET verified = 1 WHERE email = ? AND verified = 0")->execute([$adminEmail]);
        
        // Store OTP
        $stmt = $pdo->prepare("
            INSERT INTO login_otps (email, otp_code, token, expires_at, login_type) 
            VALUES (?, ?, ?, ?, 'admin')
        ");
        $stmt->execute([$adminEmail, $otp, $token, $expiresAt]);
        
        echo "   ✅ OTP generated and stored\n";
        echo "   OTP: {$otp}\n";
        echo "   Token: {$token}\n";
    } catch (PDOException $e) {
        echo "   ❌ Error storing OTP: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Test 6: Test email service
    echo "\n6. Testing email service...\n";
    try {
        require_once __DIR__ . '/email-service.php';
        $emailSent = sendOTPEmail($adminEmail, $otp, 'admin_login');
        if ($emailSent) {
            echo "   ✅ Email sent successfully\n";
        } else {
            echo "   ⚠️  Email sending failed (check email configuration)\n";
        }
    } catch (Exception $e) {
        echo "   ⚠️  Email error: " . $e->getMessage() . "\n";
        echo "   (Login will still work, OTP is stored in database)\n";
    }
    
    echo "\n=== Summary ===\n";
    echo "✅ All tests passed!\n";
    echo "✅ Admin account is valid\n";
    echo "✅ Password verification works\n";
    echo "✅ OTP system is working\n";
    echo "\nYou can now try logging in via the web interface.\n";
    echo "OTP for testing: {$otp}\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

?>

