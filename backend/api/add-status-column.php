<?php
require_once 'config.php';

try {
    // Check if column already exists
    $checkStmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'account_status'");
    $exists = $checkStmt->fetch();
    
    if ($exists) {
        echo "✅ Column 'account_status' already exists.\n";
    } else {
        // Add the column
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN account_status ENUM('TEMP_PASSWORD', 'ACTIVE', 'LOCKED', 'SUSPENDED') 
            DEFAULT 'TEMP_PASSWORD' 
            AFTER password_changed
        ");
        echo "✅ Column 'account_status' added successfully.\n";
    }
    
    // Update existing records
    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET account_status = 'TEMP_PASSWORD' 
        WHERE role = 'voter' 
          AND (temp_password IS NOT NULL AND temp_password != '')
          AND (password_changed IS NULL OR password_changed = 0)
    ");
    $updateStmt->execute();
    echo "✅ Updated existing voters with temp passwords to TEMP_PASSWORD status.\n";
    
    $updateStmt2 = $pdo->prepare("
        UPDATE users 
        SET account_status = 'ACTIVE' 
        WHERE role = 'voter' 
          AND password_changed = 1
    ");
    $updateStmt2->execute();
    echo "✅ Updated existing voters who changed passwords to ACTIVE status.\n";
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

