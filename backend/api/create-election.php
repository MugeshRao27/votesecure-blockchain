<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$title = trim($data['title'] ?? '');
$description = trim($data['description'] ?? '');
$startDate = $data['startDate'] ?? '';
$endDate = $data['endDate'] ?? '';
$startTime = $data['startTime'] ?? '00:00';
$endTime = $data['endTime'] ?? '23:59';
$userId = $data['userId'] ?? 0;

// Validation
if (empty($title)) {
    echo json_encode(['success' => false, 'message' => 'Title is required']);
    exit;
}

if (empty($startDate) || empty($endDate)) {
    echo json_encode(['success' => false, 'message' => 'Start date and end date are required']);
    exit;
}

// Combine date and time into datetime format
$startDateTime = $startDate . ' ' . $startTime . ':00';
$endDateTime = $endDate . ' ' . $endTime . ':00';

// Validate datetime format
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $startDateTime) || 
    !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $endDateTime)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date or time format']);
    exit;
}

if ($startDateTime > $endDateTime) {
    echo json_encode(['success' => false, 'message' => 'End date/time must be after start date/time']);
    exit;
}

// Determine status based on datetime
// Active: current time >= start_date AND current time <= end_date
// Upcoming: current time < start_date
// Completed: current time > end_date
$now = date('Y-m-d H:i:s');
if ($now >= $startDateTime && $now <= $endDateTime) {
    $status = 'active';
} elseif ($now < $startDateTime) {
    $status = 'upcoming';
} else {
    $status = 'completed'; // now > end_date
}

try {
    $stmt = $pdo->prepare("INSERT INTO elections (title, description, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$title, $description, $startDateTime, $endDateTime, $status, $userId])) {
        $election_id = $pdo->lastInsertId();
        
        // Fetch created election
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
        $stmt->execute([$election_id]);
        $election = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Election created successfully',
            'election' => $election
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create election']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error creating election: ' . $e->getMessage()]);
}
?>

