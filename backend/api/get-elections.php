<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get elections with creator name
    $stmt = $pdo->query("
        SELECT e.*, u.name as creator_name
        FROM elections e
        LEFT JOIN users u ON e.created_by = u.id
        ORDER BY e.created_at DESC
    ");
    $elections = $stmt->fetchAll();

    // Update election status based on current datetime (not just date)
    $now = date('Y-m-d H:i:s');
    foreach ($elections as &$election) {
        $startDateTime = $election['start_date'];
        $endDateTime = $election['end_date'];

        // Normalize dates to DATETIME format for comparison
        // Handle both DATE and DATETIME formats
        if (strlen($startDateTime) == 10) {
            $startDateTime .= ' 00:00:00';
        }
        if (strlen($endDateTime) == 10) {
            $endDateTime .= ' 23:59:59';
        }

        // Determine status: active, upcoming, or completed
        // Active: current time >= start_date AND current time <= end_date
        // Upcoming: current time < start_date
        // Completed: current time > end_date
        if ($now >= $startDateTime && $now <= $endDateTime) {
            $status = 'active';
        } elseif ($now < $startDateTime) {
            $status = 'upcoming';
        } else {
            $status = 'completed'; // now > end_date
        }

        // Update status in database if changed
        if ($election['status'] !== $status) {
            $updateStmt = $pdo->prepare("UPDATE elections SET status = ? WHERE id = ?");
            $updateStmt->execute([$status, $election['id']]);
            $election['status'] = $status;
        }
    }
    unset($election); // Break reference

    echo json_encode([
        'success' => true,
        'elections' => $elections
    ]);
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching elections: ' . $e->getMessage()
    ]);
}
?>

