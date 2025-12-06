<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$results = [
    'database_connection' => false,
    'users_table_exists' => false,
    'users_table_structure' => null,
    'can_insert' => false,
    'can_read' => false,
    'upload_directory' => false,
    'upload_directory_writable' => false,
    'errors' => []
];

try {
    // Test database connection
    $results['database_connection'] = true;
    
    // Check if users table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $results['users_table_exists'] = $stmt->rowCount() > 0;
    
    if ($results['users_table_exists']) {
        // Get table structure
        $stmt = $pdo->query("DESCRIBE users");
        $results['users_table_structure'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Test INSERT (with rollback)
        $pdo->beginTransaction();
        try {
            $testEmail = 'test_' . time() . '@test.com';
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $result = $stmt->execute(['Test User', $testEmail, password_hash('test123', PASSWORD_DEFAULT), 'voter']);
            $results['can_insert'] = $result;
            
            if ($result) {
                $userId = $pdo->lastInsertId();
                $results['last_insert_id'] = $userId;
                
                // Test READ
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $results['can_read'] = !empty($user);
                
                // Clean up - rollback test insert
                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $results['errors'][] = 'Insert test failed: ' . $e->getMessage();
        }
    } else {
        $results['errors'][] = 'Users table does not exist';
    }
    
    // Check upload directory
    // Standard path: backend/api/uploads/faces/
    $upload_dir = __DIR__ . '/uploads/faces/';
    $results['upload_directory_path'] = $upload_dir;
    $results['upload_directory'] = file_exists($upload_dir);
    $results['upload_directory_writable'] = is_writable($upload_dir);
    
    if (!$results['upload_directory']) {
        $results['errors'][] = 'Upload directory does not exist: ' . $upload_dir;
    }
    if (!$results['upload_directory_writable'] && $results['upload_directory']) {
        $results['errors'][] = 'Upload directory is not writable: ' . $upload_dir;
    }
    
} catch (PDOException $e) {
    $results['errors'][] = 'Database error: ' . $e->getMessage();
    $results['database_connection'] = false;
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>

