<?php
/**
 * Test Email Configuration
 * 
 * This script tests your email configuration to ensure emails can be sent.
 * 
 * Usage: php test-email.php
 * Or access via browser: http://localhost/final_votesecure/backend/api/test-email.php
 */

require_once 'config.php';
require_once 'email-service.php';

// Get test email from command line argument or form
$testEmail = '';

if (php_sapi_name() === 'cli') {
    // CLI mode
    if (isset($argv[1])) {
        $testEmail = $argv[1];
    } else {
        $testEmail = readline("Enter test email address: ");
    }
} else {
    // Web mode
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $testEmail = trim($_POST['email'] ?? '');
    } else {
        // Show form
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Test Email Configuration - VoteSecure</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
                .form-group { margin-bottom: 15px; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
                button { background: #667eea; color: white; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; }
                button:hover { background: #5568d3; }
                .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 4px; margin: 20px 0; }
                .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; margin: 20px 0; }
                .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin: 20px 0; }
                pre { background: #f4f4f4; padding: 15px; border-radius: 4px; overflow-x: auto; }
            </style>
        </head>
        <body>
            <h1>Test Email Configuration</h1>
            <div class="info">
                <strong>ℹ️ Info:</strong> This will send a test email to verify your email configuration is working correctly.
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Test Email Address *</label>
                    <input type="email" name="email" required placeholder="your-email@gmail.com">
                </div>
                <button type="submit">Send Test Email</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
}

// Validation
if (empty($testEmail)) {
    $error = "Email address is required.";
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    $error = "Invalid email format.";
    if (php_sapi_name() === 'cli') {
        echo "Error: $error\n";
        exit(1);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

// Get email configuration
global $emailConfig;
$provider = $emailConfig['provider'] ?? 'php_mail';

// Send test email
echo "Testing email configuration...\n";
echo "Provider: $provider\n";
echo "Sending test email to: $testEmail\n\n";

$subject = 'VoteSecure Email Configuration Test';
$plainMessage = "This is a test email from VoteSecure.\n\n";
$plainMessage .= "If you receive this email, your email configuration is working correctly!\n\n";
$plainMessage .= "Email Provider: $provider\n";
$plainMessage .= "Test Time: " . date('Y-m-d H:i:s') . "\n";

$htmlMessage = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
        .success-box { background: #d4edda; border: 2px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 8px; text-align: center; }
        .info { background: white; padding: 15px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>✅ Email Test Successful!</h1>
        </div>
        <div class='content'>
            <div class='success-box'>
                <h2 style='margin: 0; color: #28a745;'>Your Email Configuration is Working!</h2>
            </div>
            <p>This is a test email from VoteSecure.</p>
            <div class='info'>
                <strong>Email Provider:</strong> $provider<br>
                <strong>Test Time:</strong> " . date('Y-m-d H:i:s') . "
            </div>
            <p>If you received this email, your email service is properly configured and ready to send:</p>
            <ul>
                <li>Voter credentials</li>
                <li>OTP codes for login</li>
                <li>Password reset links</li>
                <li>Other system notifications</li>
            </ul>
            <div class='footer'>
                <p>© " . date('Y') . " VoteSecure. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
";

$result = sendEmail($testEmail, $subject, $plainMessage, $htmlMessage);

if ($result) {
    $success = "✅ Test email sent successfully to $testEmail!\n";
    $success .= "Please check your inbox (and spam folder) to confirm receipt.\n";
    
    if (php_sapi_name() === 'cli') {
        echo $success;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "Test email sent successfully to $testEmail! Please check your inbox.",
            'provider' => $provider
        ]);
    }
} else {
    $error = "❌ Failed to send test email.\n";
    $error .= "Please check:\n";
    $error .= "1. Email configuration in config.php\n";
    $error .= "2. SMTP credentials (username, password)\n";
    $error .= "3. Network connectivity\n";
    $error .= "4. PHP error logs for detailed error messages\n";
    
    if (php_sapi_name() === 'cli') {
        echo $error;
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send test email. Check your email configuration.',
            'provider' => $provider,
            'help' => 'Check email configuration in config.php and verify SMTP credentials'
        ]);
    }
}

?>

