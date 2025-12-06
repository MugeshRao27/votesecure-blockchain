<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Check if settings table exists, if not create it
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Get all settings
    $stmt = $pdo->query("SELECT setting_key, setting_value, description FROM settings");
    $settings = $stmt->fetchAll();
    
    // Convert to key-value pairs
    $settingsArray = [];
    foreach ($settings as $setting) {
        $settingsArray[$setting['setting_key']] = $setting['setting_value'];
    }
    
    // Set defaults if not exists
    if (!isset($settingsArray['auto_authorize_enabled'])) {
        $settingsArray['auto_authorize_enabled'] = '1'; // Default: enabled
    }
    if (!isset($settingsArray['auto_authorize_face_required'])) {
        $settingsArray['auto_authorize_face_required'] = '1'; // Default: face required
    }
    
    echo json_encode([
        'success' => true,
        'settings' => $settingsArray
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching settings: ' . $e->getMessage()
    ]);
}
?>

