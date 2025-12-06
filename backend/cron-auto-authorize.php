<?php
/**
 * Cron Job Script for Auto-Authorization
 * 
 * This script can be run via cron job to automatically authorize pending voters.
 * 
 * Setup Instructions:
 * 
 * For Windows (Task Scheduler):
 * 1. Open Task Scheduler
 * 2. Create Basic Task
 * 3. Set trigger (e.g., every 5 minutes)
 * 4. Action: Start a program
 * 5. Program: php.exe
 * 6. Arguments: "C:\laragon\www\final_votesecure\backend\cron-auto-authorize.php"
 * 7. Start in: C:\laragon\www\final_votesecure\backend
 * 
 * For Linux (crontab):
 * Add this line to crontab (crontab -e):
 * */5 * * * * php /path/to/final_votesecure/backend/cron-auto-authorize.php
 * 
 * For Laragon (Manual):
 * You can call this script via URL:
 * http://localhost/final_votesecure/backend/cron-auto-authorize.php
 * 
 * Or set up a scheduled task in Windows Task Scheduler.
 */

// Set execution time limit
set_time_limit(60);

// Include config
require_once __DIR__ . '/api/config.php';

// Log file path
$log_file = __DIR__ . '/logs/auto-authorize.log';

// Create logs directory if it doesn't exist
$log_dir = dirname($log_file);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0777, true);
}

// Function to log messages
function logMessage($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    
    // Also output if running from command line
    if (php_sapi_name() === 'cli') {
        echo $log_entry;
    }
}

logMessage("=== Auto-Authorization Cron Job Started ===");

try {
    // Get auto-authorization settings
    $autoAuthEnabled = true; // Default
    $faceRequired = true; // Default
    
    try {
        // Ensure settings table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                description TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_authorize_enabled', 'auto_authorize_face_required')");
        $settings = $stmt->fetchAll();
        
        foreach ($settings as $setting) {
            if ($setting['setting_key'] === 'auto_authorize_enabled') {
                $autoAuthEnabled = ($setting['setting_value'] === '1');
            }
            if ($setting['setting_key'] === 'auto_authorize_face_required') {
                $faceRequired = ($setting['setting_value'] === '1');
            }
        }
    } catch (Exception $e) {
        logMessage("Warning: Could not load settings, using defaults. Error: " . $e->getMessage());
    }
    
    if (!$autoAuthEnabled) {
        logMessage("Auto-authorization is disabled in settings. Exiting.");
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Auto-authorization is disabled',
                'count' => 0
            ]);
        }
        exit;
    }
    
    // Build query based on face requirement
    if ($faceRequired) {
        // Only authorize voters with face images
        $stmt = $pdo->prepare("
            UPDATE users 
            SET authorized = 1 
            WHERE role = 'voter' 
            AND (authorized = 0 OR authorized IS NULL)
            AND face_image IS NOT NULL 
            AND face_image != ''
        ");
        logMessage("Authorizing voters with face images...");
    } else {
        // Authorize all voters (if face not required)
        $stmt = $pdo->prepare("
            UPDATE users 
            SET authorized = 1 
            WHERE role = 'voter' 
            AND (authorized = 0 OR authorized IS NULL)
        ");
        logMessage("Authorizing all pending voters...");
    }
    
    $stmt->execute();
    $affected = $stmt->rowCount();
    
    logMessage("Successfully auto-authorized {$affected} voter(s)");
    logMessage("=== Auto-Authorization Cron Job Completed ===\n");
    
    // Return JSON if called via HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "Successfully auto-authorized {$affected} voter(s)",
            'count' => $affected,
            'enabled' => true,
            'face_required' => $faceRequired
        ]);
    }
    
} catch(PDOException $e) {
    $error_msg = 'Error auto-authorizing voters: ' . $e->getMessage();
    logMessage("ERROR: $error_msg");
    
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $error_msg
        ]);
    }
    exit(1);
}
?>

