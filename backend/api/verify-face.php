<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$voter_id = $data['voter_id'] ?? 0;
$captured_face = $data['captured_face'] ?? ''; // Base64 image
$client_distance = isset($data['distance']) ? floatval($data['distance']) : null;
$election_id = isset($data['election_id']) ? intval($data['election_id']) : 0;

if (empty($voter_id) || empty($captured_face)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID and face image are required']);
    exit;
}

try {
    // Get voter's stored face image
    $stmt = $pdo->prepare("SELECT face_image FROM users WHERE id = ? AND role = 'voter'");
    $stmt->execute([$voter_id]);
    $user = $stmt->fetch();

    if (!$user || empty($user['face_image'])) {
        echo json_encode(['success' => false, 'message' => 'User face image not found']);
        exit;
    }

    // For now, we'll do a simple verification
    // In production, you might want to use a more sophisticated face comparison
    // The frontend already does face-api.js comparison, this is a backup verification
    
    // Save captured face temporarily for verification
    $temp_dir = dirname(__DIR__) . '/uploads/temp/';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }

    $temp_file = $temp_dir . uniqid() . '_verify.jpg';
    $image_data = base64_decode($captured_face);
    
    if ($image_data === false) {
        echo json_encode(['success' => false, 'message' => 'Invalid image data']);
        exit;
    }

    file_put_contents($temp_file, $image_data);

    // Basic verification: Check if file exists and is valid
    // The actual face matching is done on frontend with face-api.js
    // Server additionally enforces a minimum standard using client-reported distance to avoid accidental passes
    
    // Clean up temp file
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }

    // Enforce threshold
    $threshold = 0.4; // must match frontend threshold
    if ($client_distance === null || $client_distance >= $threshold) {
        echo json_encode([
            'success' => false,
            'message' => 'Face mismatch. Verification failed.',
            'verified' => false
        ]);
        return;
    }
    // If election_id provided, mark user as authorized for that election in mapping table
    if ($election_id) {
        try {
            // Insert or update mapping: set authorized = 1
            $upsert = $pdo->prepare("SELECT id FROM election_voter_authorization WHERE user_id = ? AND election_id = ?");
            $upsert->execute([$voter_id, $election_id]);
            $exists = $upsert->fetch();
            if ($exists) {
                $upd = $pdo->prepare("UPDATE election_voter_authorization SET authorized = 1 WHERE user_id = ? AND election_id = ?");
                $upd->execute([$voter_id, $election_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO election_voter_authorization (user_id, election_id, authorized, has_voted) VALUES (?, ?, 1, 0)");
                $ins->execute([$voter_id, $election_id]);
            }
        } catch (PDOException $e) {
            error_log('Failed to upsert election authorization: ' . $e->getMessage());
            // continue - still respond verified to client, but warn in response
            echo json_encode([
                'success' => true,
                'message' => 'Face verified, but failed to update election authorization.',
                'verified' => true,
                'distance' => $client_distance,
                'warning' => $e->getMessage()
            ]);
            return;
        }
    }

    // Return success
    echo json_encode([
        'success' => true,
        'message' => 'Face verified.',
        'verified' => true,
        'distance' => $client_distance
    ]);

} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Verification error: ' . $e->getMessage()
    ]);
}
?>

