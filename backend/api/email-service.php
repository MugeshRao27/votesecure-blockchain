<?php
/**
 * Email Service Integration
 * Supports multiple email providers: SMTP, SendGrid, Mailgun, etc.
 */

// Email Configuration - Set these in your environment or config.php
$emailConfig = [
    'provider' => getenv('EMAIL_PROVIDER') ?: 'smtp', // 'smtp', 'sendgrid', 'mailgun', 'php_mail'
    'smtp' => [
        'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => getenv('SMTP_PORT') ?: 587,
        'username' => getenv('SMTP_USERNAME') ?: '',
        'password' => getenv('SMTP_PASSWORD') ?: '',
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', // 'tls' or 'ssl'
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'noreply@votesecure.com',
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'VoteSecure'
    ],
    'sendgrid' => [
        'api_key' => getenv('SENDGRID_API_KEY') ?: '',
        'from_email' => getenv('SENDGRID_FROM_EMAIL') ?: 'noreply@votesecure.com',
        'from_name' => getenv('SENDGRID_FROM_NAME') ?: 'VoteSecure'
    ],
    'mailgun' => [
        'api_key' => getenv('MAILGUN_API_KEY') ?: '',
        'domain' => getenv('MAILGUN_DOMAIN') ?: '',
        'from_email' => getenv('MAILGUN_FROM_EMAIL') ?: 'noreply@votesecure.com',
        'from_name' => getenv('MAILGUN_FROM_NAME') ?: 'VoteSecure'
    ]
];

/**
 * Send Email using configured provider
 */
function sendEmail($to, $subject, $message, $htmlMessage = null) {
    global $emailConfig;
    
    $provider = $emailConfig['provider'];
    
    switch ($provider) {
        case 'sendgrid':
            return sendEmailViaSendGrid($to, $subject, $message, $htmlMessage);
        case 'mailgun':
            return sendEmailViaMailgun($to, $subject, $message, $htmlMessage);
        case 'smtp':
            return sendEmailViaSMTP($to, $subject, $message, $htmlMessage);
        case 'php_mail':
        default:
            return sendEmailViaPHPMail($to, $subject, $message, $htmlMessage);
    }
}

/**
 * Send Email via SMTP using socket connection
 */
function sendEmailViaSMTP($to, $subject, $message, $htmlMessage = null) {
    global $emailConfig;
    $config = $emailConfig['smtp'];
    
    // Prepare email content
    $boundary = uniqid('boundary_');
    $emailBody = "";
    
    if ($htmlMessage) {
        $emailBody = "--$boundary\r\n";
        $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $emailBody .= $message . "\r\n\r\n";
        $emailBody .= "--$boundary\r\n";
        $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $emailBody .= $htmlMessage . "\r\n\r\n";
        $emailBody .= "--$boundary--";
    } else {
        $emailBody = $message;
    }
    
    // Build email headers
    $headers = "From: {$config['from_name']} <{$config['from_email']}>\r\n";
    $headers .= "Reply-To: {$config['from_email']}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    if ($htmlMessage) {
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    }
    
    // Try using PHPMailer if available
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->Port = $config['port'];
            $mail->CharSet = 'UTF-8';
            
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            
            if ($htmlMessage) {
                $mail->isHTML(true);
                $mail->Body = $htmlMessage;
                $mail->AltBody = $message;
            } else {
                $mail->Body = $message;
            }
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("SMTP Error (PHPMailer): " . $e->getMessage());
            // Fall through to socket method
        }
    }
    
    // Use socket-based SMTP connection
    try {
        $host = $config['host'];
        $port = $config['port'];
        $username = $config['username'];
        $password = $config['password'];
        $encryption = $config['encryption'];
        
        // Create socket connection
        $context = stream_context_create();
        if ($encryption === 'ssl') {
            $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        } else {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        }
        
        if (!$socket) {
            error_log("SMTP Connection failed: $errstr ($errno)");
            return false;
        }
        
        // Helper function to read SMTP response (handles multi-line)
        $readResponse = function($socket) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break; // Last line of multi-line response
                }
            }
            return $response;
        };
        
        // Read server greeting
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '220') {
            error_log("SMTP Error: Server greeting failed - $response");
            fclose($socket);
            return false;
        }
        
        // Send EHLO
        fputs($socket, "EHLO {$host}\r\n");
        $response = $readResponse($socket);
        
        // Start TLS if needed
        if ($encryption === 'tls' && strpos($response, 'STARTTLS') !== false) {
            fputs($socket, "STARTTLS\r\n");
            $response = $readResponse($socket);
            if (substr($response, 0, 3) === '220') {
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fputs($socket, "EHLO {$host}\r\n");
                $response = $readResponse($socket);
            }
        }
        
        // Authenticate
        fputs($socket, "AUTH LOGIN\r\n");
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '334') {
            error_log("SMTP Error: AUTH LOGIN failed - $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, base64_encode($username) . "\r\n");
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '334') {
            error_log("SMTP Error: Username authentication failed - $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, base64_encode($password) . "\r\n");
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '235') {
            error_log("SMTP Error: Password authentication failed - $response");
            fclose($socket);
            return false;
        }
        
        // Send email
        fputs($socket, "MAIL FROM: <{$config['from_email']}>\r\n");
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '250') {
            error_log("SMTP Error: MAIL FROM failed - $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "RCPT TO: <{$to}>\r\n");
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '250') {
            error_log("SMTP Error: RCPT TO failed - $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "DATA\r\n");
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '354') {
            error_log("SMTP Error: DATA command failed - $response");
            fclose($socket);
            return false;
        }
        
        // Send email headers and body
        fputs($socket, "Subject: {$subject}\r\n");
        fputs($socket, $headers);
        fputs($socket, "\r\n");
        fputs($socket, $emailBody);
        fputs($socket, "\r\n.\r\n");
        
        $response = $readResponse($socket);
        if (substr($response, 0, 3) !== '250') {
            error_log("SMTP Error: Email sending failed - $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return true;
        
    } catch (Exception $e) {
        error_log("SMTP Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send Email via SendGrid API
 */
function sendEmailViaSendGrid($to, $subject, $message, $htmlMessage = null) {
    global $emailConfig;
    $config = $emailConfig['sendgrid'];
    
    if (empty($config['api_key'])) {
        error_log("SendGrid API key not configured");
        return false;
    }
    
    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $to]]
            ]
        ],
        'from' => [
            'email' => $config['from_email'],
            'name' => $config['from_name']
        ],
        'subject' => $subject,
        'content' => [
            [
                'type' => 'text/plain',
                'value' => $message
            ]
        ]
    ];
    
    if ($htmlMessage) {
        $payload['content'][] = [
            'type' => 'text/html',
            'value' => $htmlMessage
        ];
    }
    
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $config['api_key'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    
    error_log("SendGrid Error: HTTP $httpCode - $response");
    return false;
}

/**
 * Send Email via Mailgun API
 */
function sendEmailViaMailgun($to, $subject, $message, $htmlMessage = null) {
    global $emailConfig;
    $config = $emailConfig['mailgun'];
    
    if (empty($config['api_key']) || empty($config['domain'])) {
        error_log("Mailgun API key or domain not configured");
        return false;
    }
    
    $data = [
        'from' => "{$config['from_name']} <{$config['from_email']}>",
        'to' => $to,
        'subject' => $subject,
        'text' => $message
    ];
    
    if ($htmlMessage) {
        $data['html'] = $htmlMessage;
    }
    
    $ch = curl_init("https://api.mailgun.net/v3/{$config['domain']}/messages");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_USERPWD, "api:{$config['api_key']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    
    error_log("Mailgun Error: HTTP $httpCode - $response");
    return false;
}

/**
 * Send Email via PHP mail() function (fallback)
 */
function sendEmailViaPHPMail($to, $subject, $message, $htmlMessage = null) {
    global $emailConfig;
    $config = $emailConfig['smtp'];
    
    $headers = "From: {$config['from_name']} <{$config['from_email']}>\r\n";
    $headers .= "Reply-To: {$config['from_email']}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    
    if ($htmlMessage) {
        $boundary = uniqid('boundary_');
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $message . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlMessage . "\r\n\r\n";
        $body .= "--$boundary--";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body = $message;
    }
    
    return @mail($to, $subject, $body, $headers);
}

/**
 * Send OTP Email with formatted template
 */
function sendOTPEmail($email, $otp, $type = 'login') {
    $subject = $type === 'login' ? 'Your VoteSecure Login OTP' : 'Your VoteSecure Verification Code';
    
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
            .otp-box { background: white; border: 2px dashed #667eea; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
            .otp-code { font-size: 32px; font-weight: bold; color: #667eea; letter-spacing: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>VoteSecure</h1>
            </div>
            <div class='content'>
                <h2>Your Verification Code</h2>
                <p>Hello,</p>
                <p>You have requested a verification code for your VoteSecure account.</p>
                <div class='otp-box'>
                    <p style='margin: 0 0 10px 0;'>Your OTP code is:</p>
                    <div class='otp-code'>$otp</div>
                </div>
                <p>This code will expire in <strong>3 minutes</strong>.</p>
                <div class='warning'>
                    <strong>⚠️ Security Notice:</strong> Never share this code with anyone. VoteSecure staff will never ask for your OTP.
                </div>
                <p>If you did not request this code, please ignore this email or contact support.</p>
                <div class='footer'>
                    <p>© " . date('Y') . " VoteSecure. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $plainMessage = "Your VoteSecure OTP code is: $otp\n\nThis code will expire in 3 minutes.\n\nDo not share this code with anyone.\n\nIf you did not request this code, please ignore this email.";
    
    return sendEmail($email, $subject, $plainMessage, $htmlMessage);
}

/**
 * Send Voter Credentials Email
 */
function sendVoterCredentialsEmail($email, $name, $tempPassword, $loginUrl) {
    $subject = 'Your VoteSecure Voter Account Credentials';
    
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
            .credentials-box { background: white; border: 2px solid #667eea; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .credential-item { margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px; }
            .credential-label { font-weight: bold; color: #667eea; }
            .credential-value { font-family: monospace; font-size: 14px; color: #333; }
            .steps { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #28a745; }
            .steps ol { margin: 10px 0; padding-left: 20px; }
            .steps li { margin: 8px 0; }
            .button { display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to VoteSecure</h1>
            </div>
            <div class='content'>
                <h2>Hello $name,</h2>
                <p>You have been registered as a voter on VoteSecure. Your account has been created and you can now log in to cast your vote.</p>
                
                <div class='credentials-box'>
                    <h3 style='margin-top: 0;'>Your Login Credentials</h3>
                    <div class='credential-item'>
                        <span class='credential-label'>Login URL:</span><br>
                        <span class='credential-value'><a href='$loginUrl'>$loginUrl</a></span>
                    </div>
                    <div class='credential-item'>
                        <span class='credential-label'>Email:</span><br>
                        <span class='credential-value'>$email</span>
                    </div>
                    <div class='credential-item'>
                        <span class='credential-label'>Temporary Password:</span><br>
                        <span class='credential-value'>$tempPassword</span>
                    </div>
                </div>
                
                <div class='steps'>
                    <h3 style='margin-top: 0;'>Next Steps:</h3>
                    <ol>
                        <li>Click the login URL above or visit the VoteSecure login page</li>
                        <li>Enter your email and temporary password</li>
                        <li>Complete the mandatory face verification when prompted</li>
                        <li><strong>Change your password immediately</strong> when asked (this is mandatory)</li>
                        <li>Access the election page to cast your vote</li>
                    </ol>
                </div>
                
                <div class='warning'>
                    <strong>⚠️ Important Security Notice:</strong>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>This is a temporary password. You <strong>must</strong> change it on first login.</li>
                        <li>Never share your credentials with anyone.</li>
                        <li>If you did not expect this email, please contact your election administrator immediately.</li>
                    </ul>
                </div>
                
                <p style='text-align: center;'>
                    <a href='$loginUrl' class='button'>Login to VoteSecure</a>
                </p>
                
                <div class='footer'>
                    <p>© " . date('Y') . " VoteSecure. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $plainMessage = "Hello $name,\n\n";
    $plainMessage .= "You have been registered on VoteSecure.\n\n";
    $plainMessage .= "Login URL: $loginUrl\n";
    $plainMessage .= "Username: $email\n";
    $plainMessage .= "Temporary Password: $tempPassword\n\n";
    $plainMessage .= "Next Steps:\n";
    $plainMessage .= "1. Login with the temporary password.\n";
    $plainMessage .= "2. Complete the mandatory face verification when prompted.\n";
    $plainMessage .= "3. Change your password immediately when asked.\n";
    $plainMessage .= "4. Access the election page to cast your vote.\n\n";
    $plainMessage .= "If you did not expect this email, please contact your election administrator.\n";
    
    return sendEmail($email, $subject, $plainMessage, $htmlMessage);
}

?>

