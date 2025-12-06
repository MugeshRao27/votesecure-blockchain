<?php
/**
 * Auto-Authorize Voters Endpoint
 * 
 * This endpoint automatically authorizes voters based on system settings.
 * Can be called:
 * 1. Manually by admin
 * 2. Via cron job (scheduled task)
 * 3. Automatically after voter registration
 * 
 * Security: Only authorizes voters with face images (if face_required setting is enabled)
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get auto-authorization settings
    $autoAuthEnabled = true; // Default
    $faceRequired = true; // Default
    
    try {
        // Check if settings table exists
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
        // Use defaults if settings table doesn't exist or error
        error_log("Settings error: " . $e->getMessage());
    }
    
    // If auto-authorization is disabled, return early
    if (!$autoAuthEnabled) {
        echo json_encode([
            'success' => true,
            'message' => 'Auto-authorization is disabled',
            'count' => 0,
            'enabled' => false
        ]);
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
    } else {
        // Authorize all voters (if face not required)
        $stmt = $pdo->prepare("
            UPDATE users 
            SET authorized = 1 
            WHERE role = 'voter' 
            AND (authorized = 0 OR authorized IS NULL)
        ");
    }
    
    $stmt->execute();
    $affected = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => "Successfully auto-authorized {$affected} voter(s)",
        'count' => $affected,
        'enabled' => true,
        'face_required' => $faceRequired
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error auto-authorizing voters: ' . $e->getMessage()
    ]);
}
?>

