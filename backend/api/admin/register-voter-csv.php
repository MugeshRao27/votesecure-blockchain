<?php
/**
 * Voter Registration API with CSV Export
 * 
 * This endpoint allows admins to register voters and automatically
 * maintains CSV files for each election.
 * 
 * Endpoint: POST /backend/api/admin/register-voter-csv.php
 * 
 * Required JSON Input:
 * {
 *   "name": "John Doe",
 *   "email": "john.doe@example.com",
 *   "dob": "1990-01-15",
 *   "election_id": 2025
 * }
 * 
 * @author VoteSecure Team
 * @version 1.0
 */

// Include required files
require_once '../config.php';
require_once '../auth-helper.php';
require_once '../csv-helper.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// Verify admin authentication
$admin = verifyAdminToken($pdo);
if (!$admin) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized - Admin access required'
    ]);
    exit;
}

// Get and parse JSON input
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

// Check if JSON parsing was successful
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON format. Please check your request body.',
        'error' => json_last_error_msg()
    ]);
    exit;
}

// Validate required fields
$requiredFields = ['name', 'email', 'dob', 'election_id'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || empty(trim($data[$field]))) {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missingFields),
        'missing_fields' => $missingFields
    ]);
    exit;
}

// Extract and sanitize input data
$name = trim($data['name']);
$email = strtolower(trim($data['email']));
$dob = trim($data['dob']);
$electionId = intval($data['election_id']);

// Validate election ID
if ($electionId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid election ID. Election ID must be a positive integer.'
    ]);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email format. Please provide a valid email address.'
    ]);
    exit;
}

// Validate date of birth format (YYYY-MM-DD)
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $dob)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid date format. Date of birth must be in YYYY-MM-DD format (e.g., 1990-01-15).'
    ]);
    exit;
}

// Validate date of birth is a valid date
try {
    $dateObj = new DateTime($dob);
    $today = new DateTime();
    
    // Check if date is in the future
    if ($dateObj > $today) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid date of birth. Date cannot be in the future.'
        ]);
        exit;
    }
    
    // Optional: Check if voter is at least 18 years old
    $age = $today->diff($dateObj)->y;
    if ($age < 18) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Voter must be at least 18 years old to register.',
            'calculated_age' => $age
        ]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid date of birth. Please use YYYY-MM-DD format.',
        'error' => $e->getMessage()
    ]);
    exit;
}

// Verify election exists in database
try {
    $electionStmt = $pdo->prepare("SELECT id, title FROM elections WHERE id = ?");
    $electionStmt->execute([$electionId]);
    $election = $electionStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Election with ID {$electionId} does not exist."
        ]);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while validating election.',
        'error' => $e->getMessage()
    ]);
    exit;
}

// Generate registration timestamp
$registrationTimestamp = date('Y-m-d H:i:s');

// Write voter data to CSV file
try {
    $csvResult = appendVoterToElectionCsv(
        $electionId,
        $name,
        $email,
        $dob,
        $registrationTimestamp
    );
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Voter registered successfully and added to CSV file.',
        'data' => [
            'voter' => [
                'name' => $name,
                'email' => $email,
                'date_of_birth' => $dob,
                'registration_timestamp' => $registrationTimestamp
            ],
            'election' => [
                'id' => $electionId,
                'title' => $election['title'] ?? 'Unknown'
            ],
            'csv' => [
                'file_created' => $csvResult['created'],
                'filename' => $csvResult['filename'],
                'file_path' => $csvResult['file_path']
            ]
        ]
    ]);
    
} catch (InvalidArgumentException $e) {
    // Validation error
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid input data: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    // CSV write error
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to write voter data to CSV file.',
        'error' => $e->getMessage()
    ]);
    
    // Log error for debugging
    error_log("CSV write error for election {$electionId}: " . $e->getMessage());
}

?>

