<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if user is admin (you can add session check here)
$data = json_decode(file_get_contents('php://input'), true);
$setting_key = $data['setting_key'] ?? '';
$setting_value = $data['setting_value'] ?? '';

if (empty($setting_key)) {
    echo json_encode(['success' => false, 'message' => 'Setting key is required']);
    exit;
}

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
    
    // Update or insert setting
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$setting_key, $setting_value]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Setting updated successfully',
        'setting_key' => $setting_key,
        'setting_value' => $setting_value
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error updating setting: ' . $e->getMessage()
    ]);
}
?>

