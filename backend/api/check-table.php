<?php
require_once 'config.php';

$stmt = $pdo->query("DESCRIBE users");
$cols = $stmt->fetchAll();

echo "Users table columns:\n";
foreach($cols as $col) {
    echo "  - {$col['Field']} ({$col['Type']})\n";
}

?>

