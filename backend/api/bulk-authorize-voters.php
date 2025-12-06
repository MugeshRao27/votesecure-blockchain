<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$voter_ids = $data['voter_ids'] ?? [];
$authorize_all = $data['authorize_all'] ?? false;

try {
    if ($authorize_all) {
        // Authorize all unauthorized voters who have face images
        $stmt = $pdo->prepare("
            UPDATE users 
            SET authorized = 1 
            WHERE role = 'voter' 
            AND (authorized = 0 OR authorized IS NULL)
            AND face_image IS NOT NULL 
            AND face_image != ''
        ");
        $stmt->execute();
        $affected = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully authorized {$affected} voter(s)",
            'count' => $affected
        ]);
    } else if (!empty($voter_ids) && is_array($voter_ids)) {
        // Authorize specific voters
        $placeholders = implode(',', array_fill(0, count($voter_ids), '?'));
        $stmt = $pdo->prepare("
            UPDATE users 
            SET authorized = 1 
            WHERE role = 'voter' 
            AND id IN ($placeholders)
        ");
        $stmt->execute($voter_ids);
        $affected = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully authorized {$affected} voter(s)",
            'count' => $affected
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request. Provide voter_ids array or set authorize_all to true']);
    }
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error authorizing voters: ' . $e->getMessage()
    ]);
}
?>

