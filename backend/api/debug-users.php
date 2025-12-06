<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$results = [
    'database_connected' => false,
    'users_table_exists' => false,
    'table_structure' => null,
    'total_users' => 0,
    'recent_users' => [],
    'errors' => []
];

try {
    // Test connection
    $results['database_connected'] = true;
    $results['database_name'] = 'votesecure_db';
    
    // Check if users table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $results['users_table_exists'] = $stmt->rowCount() > 0;
    
    if ($results['users_table_exists']) {
        // Get table structure
        $stmt = $pdo->query("DESCRIBE users");
        $results['table_structure'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        $results['total_users'] = (int)$count['count'];
        
        // Get recent users (last 10)
        $stmt = $pdo->query("SELECT id, name, email, role, verified, authorized, created_at FROM users ORDER BY id DESC LIMIT 10");
        $results['recent_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Test insert (will rollback)
        $pdo->beginTransaction();
        try {
            $testEmail = 'debug_test_' . time() . '@test.com';
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $testResult = $stmt->execute([
                'Debug Test User', 
                $testEmail, 
                password_hash('test123', PASSWORD_DEFAULT), 
                'voter'
            ]);
            $testInsertId = $pdo->lastInsertId();
            $testRowCount = $stmt->rowCount();
            
            $results['test_insert'] = [
                'success' => $testResult,
                'insert_id' => $testInsertId,
                'row_count' => $testRowCount,
                'email' => $testEmail
            ];
            
            // Verify it was inserted
            $verifyStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $verifyStmt->execute([$testInsertId]);
            $testUser = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            $results['test_insert']['verified_in_db'] = !empty($testUser);
            
            // Rollback
            $pdo->rollBack();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $results['test_insert'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];
        }
    } else {
        $results['errors'][] = 'Users table does not exist';
    }
    
} catch (PDOException $e) {
    $results['errors'][] = 'Database error: ' . $e->getMessage();
    $results['database_connected'] = false;
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>

