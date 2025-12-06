<?php
/**
 * Delete User by Email
 * Use this to delete a user so you can recreate them as admin
 */

require_once 'config.php';

// Only allow CLI or with confirmation
if (php_sapi_name() === 'cli') {
    echo "=== Delete User by Email ===\n\n";
    $email = readline("Enter email to delete: ");
    
    if (empty($email)) {
        echo "Error: Email is required.\n";
        exit(1);
    }
    
    // Confirm deletion
    echo "\n⚠️  WARNING: This will permanently delete the user: $email\n";
    $confirm = readline("Type 'DELETE' to confirm: ");
    
    if (strtoupper(trim($confirm)) !== 'DELETE') {
        echo "Deletion cancelled.\n";
        exit(0);
    }
    
    try {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo "❌ User not found: $email\n";
            exit(1);
        }
        
        echo "\nFound user:\n";
        echo "  ID: {$user['id']}\n";
        echo "  Name: {$user['name']}\n";
        echo "  Email: {$user['email']}\n";
        echo "  Role: {$user['role']}\n\n";
        
        // Delete user
        $stmt = $pdo->prepare("DELETE FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            echo "✅ User deleted successfully!\n";
            echo "You can now create an admin account with this email.\n";
        } else {
            echo "❌ Failed to delete user.\n";
            exit(1);
        }
        
    } catch (PDOException $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Web mode - show simple form
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $confirm = $_POST['confirm'] ?? '';
        
        if (empty($email)) {
            die("Email is required.");
        }
        
        if (strtoupper(trim($confirm)) !== 'DELETE') {
            die("Please type 'DELETE' to confirm.");
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                die("User not found: $email");
            }
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            echo "✅ User deleted successfully! You can now create an admin account.";
        } catch (PDOException $e) {
            die("Error: " . $e->getMessage());
        }
    } else {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Delete User</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 500px; margin: 50px auto; padding: 20px; }
                .form-group { margin-bottom: 15px; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
                button { background: #dc3545; color: white; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <h1>Delete User</h1>
            <div class="warning">
                <strong>⚠️ Warning:</strong> This will permanently delete the user account.
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Email to Delete *</label>
                    <input type="email" name="email" required placeholder="user@example.com">
                </div>
                <div class="form-group">
                    <label>Type 'DELETE' to confirm *</label>
                    <input type="text" name="confirm" required placeholder="DELETE">
                </div>
                <button type="submit">Delete User</button>
            </form>
        </body>
        </html>
        <?php
    }
}
?>