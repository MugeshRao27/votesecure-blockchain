<?php
/**
 * Check Users in Database
 * This script shows all users to help debug
 */

require_once 'config.php';

echo "=== Checking Users in Database ===\n\n";

try {
    // Get all users
    $stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "No users found in database.\n";
    } else {
        echo "Found " . count($users) . " user(s):\n\n";
        foreach ($users as $user) {
            echo "ID: {$user['id']}\n";
            echo "Name: {$user['name']}\n";
            echo "Email: {$user['email']}\n";
            echo "Role: {$user['role']}\n";
            echo "Created: {$user['created_at']}\n";
            echo str_repeat("-", 50) . "\n";
        }
    }
    
    // Check specifically for the email
    echo "\n=== Checking for specific email ===\n";
    $email = 'mugeshwarr727@gmail.com';
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ? OR LOWER(email) = LOWER(?)");
    $stmt->execute([$email, $email]);
    $found = $stmt->fetchAll();
    
    if (empty($found)) {
        echo "Email '$email' not found (case-sensitive and case-insensitive check).\n";
    } else {
        echo "Found " . count($found) . " user(s) with email '$email':\n";
        foreach ($found as $user) {
            echo "  ID: {$user['id']}, Name: {$user['name']}, Role: {$user['role']}\n";
        }
    }
    
    // Check for any admin
    echo "\n=== Checking for admin accounts ===\n";
    $stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'admin'");
    $admins = $stmt->fetchAll();
    
    if (empty($admins)) {
        echo "No admin accounts found.\n";
    } else {
        echo "Found " . count($admins) . " admin account(s):\n";
        foreach ($admins as $admin) {
            echo "  ID: {$admin['id']}, Name: {$admin['name']}, Email: {$admin['email']}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>

