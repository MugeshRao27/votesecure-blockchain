<?php
// Test Database Connection
require_once 'config.php';

try {
    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful!',
        'users_count' => $result['count'],
        'database' => 'votesecure_db',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
}
?>

