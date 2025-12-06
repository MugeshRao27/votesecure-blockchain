<?php
require_once '../config.php';
require_once '../auth-helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify admin authentication
$admin = verifyAdminToken($pdo);
if (!$admin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Admin access required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$voter_id = $data['voter_id'] ?? 0;
$name = trim($data['name'] ?? '');
$email = strtolower(trim($data['email'] ?? ''));
$phone = trim($data['phone'] ?? '');
$date_of_birth = $data['date_of_birth'] ?? null;

if (empty($voter_id)) {
    echo json_encode(['success' => false, 'message' => 'Voter ID is required']);
    exit;
}

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Name is required']);
    exit;
}

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

try {
    // Check if voter exists
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ? AND role = 'voter'");
    $stmt->execute([$voter_id]);
    $voter = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$voter) {
        echo json_encode(['success' => false, 'message' => 'Voter not found']);
        exit;
    }
    
    // Check if email is being changed and if new email already exists
    if ($email !== $voter['email']) {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkStmt->execute([$email, $voter_id]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit;
        }
    }
    
    // Validate date of birth if provided
    if (!empty($date_of_birth)) {
        try {
            $birthDate = new DateTime($date_of_birth);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            
            if ($age < 18) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Voter must be 18 or older',
                    'age' => $age
                ]);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid date of birth format. Please use YYYY-MM-DD format.'
            ]);
            exit;
        }
    }
    
    // Build UPDATE query dynamically based on available columns
    $updateFields = [];
    $updateValues = [];
    
    $updateFields[] = "name = ?";
    $updateValues[] = $name;
    
    $updateFields[] = "email = ?";
    $updateValues[] = $email;
    
    if (!empty($phone)) {
        $updateFields[] = "phone_number = ?";
        $updateValues[] = $phone;
    }
    
    if (!empty($date_of_birth)) {
        $updateFields[] = "date_of_birth = ?";
        $updateValues[] = $date_of_birth;
    }
    
    $updateValues[] = $voter_id;
    
    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    
    if ($stmt->execute($updateValues)) {
        // Fetch updated voter
        $fetchStmt = $pdo->prepare("SELECT id, name, email, phone_number, date_of_birth, role, authorized, verified, created_at FROM users WHERE id = ?");
        $fetchStmt->execute([$voter_id]);
        $updatedVoter = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Voter updated successfully',
            'voter' => $updatedVoter
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update voter']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating voter: ' . $e->getMessage()]);
}
?>

