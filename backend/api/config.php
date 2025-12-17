<?php
// Laragon Database Configuration
$host = 'localhost';
$dbname = 'votesecure_db';
$username = 'root';
$password = 'Mugeshrao@2004'; // Default Laragon MySQL password (empty)

// If you changed Laragon MySQL password, update it here:
// $password = 'your_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Return JSON error for API consistency
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}

// CORS Headers (for React development) - only set in web context
if (php_sapi_name() !== 'cli') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');
    
    // Handle preflight requests
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// ============================================
// EMAIL CONFIGURATION
// ============================================
// Configure your email service here or use environment variables
// For Gmail: Use App Password (not your regular password)
// Get App Password from: https://myaccount.google.com/apppasswords

// Email Provider: 'smtp', 'sendgrid', 'mailgun', or 'php_mail'
if (!getenv('EMAIL_PROVIDER')) {
    putenv('EMAIL_PROVIDER=smtp'); // Change to your preferred provider
}

// SMTP Configuration (for Gmail, Outlook, Yahoo, etc.)
if (!getenv('SMTP_HOST')) {
    putenv('SMTP_HOST=smtp.gmail.com'); // Gmail: smtp.gmail.com, Outlook: smtp-mail.outlook.com
    putenv('SMTP_PORT=587'); // TLS: 587, SSL: 465
    putenv('SMTP_USERNAME=mugeshwarr727@gmail.com'); // Your email address
    putenv('SMTP_PASSWORD=intefljaxunxnktz'); // Gmail App Password (16 characters - spaces removed)
    putenv('SMTP_ENCRYPTION=tls'); // 'tls' or 'ssl'
    putenv('SMTP_FROM_EMAIL=mugeshwarr727@gmail.com'); // Sender email
    putenv('SMTP_FROM_NAME=VoteSecure'); // Sender name
}

// SendGrid Configuration (if using SendGrid)
if (!getenv('SENDGRID_API_KEY')) {
    putenv('SENDGRID_API_KEY='); // Your SendGrid API key
    putenv('SENDGRID_FROM_EMAIL=noreply@votesecure.com');
    putenv('SENDGRID_FROM_NAME=VoteSecure');
}

// Mailgun Configuration (if using Mailgun)
if (!getenv('MAILGUN_API_KEY')) {
    putenv('MAILGUN_API_KEY='); // Your Mailgun API key
    putenv('MAILGUN_DOMAIN='); // Your Mailgun domain
    putenv('MAILGUN_FROM_EMAIL=noreply@votesecure.com');
    putenv('MAILGUN_FROM_NAME=VoteSecure');
}

// ============================================
// BLOCKCHAIN CONFIGURATION
// ============================================
// Polygon RPC (Amoy testnet)
// Switch to mainnet or other testnets by updating this value and chain id accordingly
putenv('POLYGON_RPC_URL=https://rpc-amoy.polygon.technology'); // Amoy testnet RPC
putenv('POLYGON_CONTRACT_ADDRESS=0xa9AdE7C396b4FC5d340D7A10D34a84a3906471CB'); // Deployed on Amoy

// Optional: Private key for backend blockchain transactions
// Note: For security, use frontend MetaMask integration instead
if (!getenv('BLOCKCHAIN_PRIVATE_KEY')) {
    putenv('BLOCKCHAIN_PRIVATE_KEY='); // Leave empty to use frontend MetaMask
}

// ============================================
// ENCRYPTION CONFIGURATION
// ============================================
// Vote encryption key - MUST be set in production!
// Generate a secure key: openssl rand -hex 32
if (!getenv('VOTE_ENCRYPTION_KEY')) {
    // Default key for development (CHANGE IN PRODUCTION!)
    $defaultKey = hash('sha256', 'votesecure_default_encryption_key_change_in_production_' . date('Y'));
    putenv('VOTE_ENCRYPTION_KEY=' . $defaultKey);
}

// Application secret for additional security
if (!getenv('APP_SECRET')) {
    putenv('APP_SECRET=votesecure_app_secret_change_in_production');
}

// ============================================
// IMPORTANT: Update the email settings above
// with your actual email credentials!
// ============================================
?>

