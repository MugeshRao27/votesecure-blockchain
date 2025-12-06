<?php
require_once 'config.php';

// This script adds the created_at column to the votes table if it doesn't exist
// Run this once: http://localhost/final_votesecure/backend/api/add-created-at-to-votes.php

try {
    // Check if column exists
    $checkStmt = $pdo->query("
        SELECT COUNT(*) as col_exists 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'votes' 
        AND COLUMN_NAME = 'created_at'
    ");
    $columnExists = $checkStmt->fetch(PDO::FETCH_ASSOC)['col_exists'] > 0;
    
    if ($columnExists) {
        echo json_encode([
            'success' => true,
            'message' => 'Column created_at already exists in votes table'
        ]);
    } else {
        // Add the column
        $pdo->exec("
            ALTER TABLE votes 
            ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ");
        
        echo json_encode([
            'success' => true,
            'message' => 'Column created_at added successfully to votes table'
        ]);
    }
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>

