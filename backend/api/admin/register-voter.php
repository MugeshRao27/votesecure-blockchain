<?php
require_once '../config.php';
require_once '../auth-helper.php';
require_once '../email-service.php';

/**
 * Convert election title/ID to a safe CSV filename.
 *
 * Result format (examples):
 *   - title "College Election 2025", id 5  -> election_5_college_election_2025_voters.csv
 *   - title empty, id 10                  -> election_10_voters.csv
 *
 * This keeps one CSV per election, stored in csv_exports/.
 */
function electionTitleToFilename($electionTitle, $electionId) {
    $electionId = intval($electionId);
    if ($electionId <= 0) {
        $electionId = 0;
    }

    $base = (string)$electionId;
    if (!empty($electionTitle)) {
        $slug = strtolower(trim($electionTitle));
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $slug);
        $slug = trim($slug, '_');
        if ($slug !== '') {
            $base .= '_' . $slug;
        }
    }

    // Fallback name if everything above fails
    if ($base === '' || $base === '0') {
        $base = 'election';
    }

    return 'election_' . $base . '_voters.csv';
}

/**
 * Write or append a voter row to the election CSV stored in csv_exports/.
 *
 * Behaviour:
 * - Creates one CSV per election in backend/api/csv_exports/
 * - If the file is new: writes a header row
 * - Always appends (never overwrites) a new line for each registered voter
 * - Columns: Name, Email, DOB, Registration Timestamp
 *
 * Returns ['path' => ..., 'created' => bool, 'written' => bool]
 * Throws on hard errors; caller should catch to avoid breaking registration.
 */
function appendVoterToCsv($electionTitle, $electionId, $name, $email, $dateOfBirth) {
    $csvDir = __DIR__ . '/../csv_exports';
    if (!is_dir($csvDir)) {
        if (!mkdir($csvDir, 0777, true) && !is_dir($csvDir)) {
            throw new Exception("Could not create csv_exports directory: {$csvDir}");
        }
    }
    if (!is_writable($csvDir)) {
        @chmod($csvDir, 0777);
    }
    if (!is_writable($csvDir)) {
        throw new Exception("csv_exports directory is not writable: {$csvDir}");
    }

    // Generate a stable per‑election filename like:
    //   csv_exports/election_5_college_election_2025_voters.csv
    //   csv_exports/election_10_voters.csv
    $filename = electionTitleToFilename($electionTitle ?? '', $electionId);
    $csvPath = $csvDir . DIRECTORY_SEPARATOR . $filename;
    $isNew = !file_exists($csvPath);

    $fh = fopen($csvPath, 'ab');
    if ($fh === false) {
        throw new Exception("Could not open CSV file for writing: {$csvPath}");
    }

    // Add header if new
    if ($isNew) {
        fputcsv($fh, ['Name', 'Email', 'DOB', 'Registration Timestamp']);
    }

    // Current server timestamp for this registration
    $registrationTimestamp = date('Y-m-d H:i:s');

    // Write the row
    fputcsv($fh, [$name, $email, $dateOfBirth, $registrationTimestamp]);
    fclose($fh);

    return [
        'path' => $csvPath,
        'created' => $isNew,
        'written' => true,
    ];
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify admin authentication
$admin = verifyAdminToken($pdo);
if (!$admin) {
    // Enhanced error logging for debugging
    $authDebug = [];
    
    // Check if Authorization header exists
    $headers = getAllHeaders();
    $authHeader = $headers['authorization'] ?? 
                 $_SERVER['HTTP_AUTHORIZATION'] ?? 
                 $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 
                 $_SERVER['Authorization'] ?? '';
    
    $authDebug['has_auth_header'] = !empty($authHeader);
    $authDebug['header_preview'] = !empty($authHeader) ? substr($authHeader, 0, 30) . '...' : 'not found';
    
    // Try to get JWT payload
    $user = isAuthenticated();
    $authDebug['is_authenticated'] = $user !== false;
    if ($user) {
        $authDebug['user_id'] = $user['user_id'] ?? null;
        $authDebug['user_role'] = $user['role'] ?? null;
        
        // Check if user exists in database
        try {
            $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id = ?");
            $stmt->execute([$user['user_id']]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
            $authDebug['user_in_db'] = $dbUser !== false;
            if ($dbUser) {
                $authDebug['db_role'] = $dbUser['role'];
                $authDebug['db_email'] = $dbUser['email'];
            }
        } catch (Exception $e) {
            $authDebug['db_check_error'] = $e->getMessage();
        }
    }
    
    // Only include debug info in development
    $isDevelopment = (isset($_SERVER['HTTP_HOST']) && 
                      (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
                       strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false));
    
    http_response_code(401);
    $response = [
        'success' => false, 
        'message' => 'Unauthorized - Admin access required'
    ];
    
    if ($isDevelopment) {
        $response['debug'] = $authDebug;
        $response['suggestion'] = 'Please check: 1) You are logged in as admin, 2) Your token is not expired, 3) Authorization header is being sent correctly';
    }
    
    echo json_encode($response);
    exit;
}

// Get and validate input data
$data = json_decode(file_get_contents('php://input'), true);

// Required fields - include name so admin can set full name explicitly
$requiredFields = [
    'name', 'email', 'date_of_birth', 'face_image', 'election_id'
];

foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
        exit;
    }
}

// Extract and validate data
$name = isset($data['name']) ? trim($data['name']) : '';
$email = strtolower(trim($data['email']));
$dateOfBirth = $data['date_of_birth'];
$faceImage = $data['face_image'];
$electionId = intval($data['election_id']);

// Validate election_id
if ($electionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid election selected']);
    exit;
}

// Verify election exists
try {
    $electionStmt = $pdo->prepare("SELECT id, title FROM elections WHERE id = ?");
    $electionStmt->execute([$electionId]);
    $election = $electionStmt->fetch();
    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Selected election does not exist']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error validating election: ' . $e->getMessage()]);
    exit;
}

// Use provided name, or fallback to default from email (first part before @)
if (empty($name)) {
    $name = ucfirst(explode('@', $email)[0]);
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Validate date of birth and check age (must be 18 or older)
try {
    // Try to parse the date - handle different formats
    $birthDate = new DateTime($dateOfBirth);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    
    if ($age < 18) {
        echo json_encode([
            'success' => false, 
            'message' => 'Voter is not eligible. Age must be 18 or above.',
            'age' => $age
        ]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid date of birth format. Please use YYYY-MM-DD format.',
        'error' => $e->getMessage()
    ]);
    exit;
}

// Always auto-generate temporary password (no custom option)
$tempPassword = bin2hex(random_bytes(8)); // 16-character random string
$hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

// Handle face image upload
// Standard path: backend/api/uploads/faces/
$faceImagePath = '';
$filePath = null; // Initialize for cleanup
if (!empty($faceImage)) {
    // Use absolute path from the api directory
    // register-voter.php is in backend/api/admin/, so we go up one level to backend/api/
    $uploadDir = __DIR__ . '/../uploads/faces/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Convert base64 to image file
    $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $faceImage));
    if ($imageData === false) {
        echo json_encode(['success' => false, 'message' => 'Invalid face image data']);
        exit;
    }
    
    $fileName = 'face_' . uniqid() . '.jpg';
    $filePath = $uploadDir . $fileName;
    
    if (file_put_contents($filePath, $imageData) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to save face image']);
        exit;
    }
    
    // Store relative path in database: uploads/faces/filename.jpg
    // This path is relative to backend/api/ directory
    $faceImagePath = 'uploads/faces/' . $fileName;
}

// Check if email already exists BEFORE starting transaction
$stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
$stmt->execute([$email]);
if ($existingUser = $stmt->fetch()) {
    // Delete uploaded face image if it was saved
    if ($filePath && file_exists($filePath)) {
        @unlink($filePath);
    }
    echo json_encode(['success' => false, 'message' => 'Email already exists']);
    exit;
}

// Start transaction
$pdo->beginTransaction();

try {
    // Double-check email inside transaction (to prevent race conditions)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) FOR UPDATE");
    $stmt->execute([$email]);
    if ($existingUser = $stmt->fetch()) {
        // Rollback only if transaction is active
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Exception $rollbackError) {
                error_log("Rollback error in email check: " . $rollbackError->getMessage());
            }
        }
        // Delete uploaded face image if it was saved
        if ($filePath && file_exists($filePath)) {
            @unlink($filePath);
        }
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }
    
    // Ensure required columns exist (for migration compatibility)
    try {
        // Check and add authorized_at column
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'authorized_at'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("ALTER TABLE users ADD COLUMN authorized_at TIMESTAMP NULL DEFAULT NULL AFTER authorized");
        }
        
        // Check and add authorized_by column
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'authorized_by'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("ALTER TABLE users ADD COLUMN authorized_by INT NULL DEFAULT NULL AFTER authorized_at");
        }
        
        // Check and add account_status column
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'account_status'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE users 
                ADD COLUMN account_status ENUM('TEMP_PASSWORD', 'ACTIVE', 'LOCKED', 'SUSPENDED') 
                DEFAULT 'TEMP_PASSWORD' 
                AFTER password_changed
            ");
        }
        
        // Check and add password_changed column
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_changed'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_changed TINYINT(1) NOT NULL DEFAULT 0 AFTER temp_password");
        }
    } catch (PDOException $e) {
        // Column might already exist, continue
        if (strpos($e->getMessage(), 'Duplicate column name') === false && 
            strpos($e->getMessage(), 'already exists') === false) {
            error_log("Warning: Could not add column: " . $e->getMessage());
        }
    }
    
    // Check which columns actually exist to build dynamic INSERT
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $hasAuthorizedAt = in_array('authorized_at', $columns);
    $hasAuthorizedBy = in_array('authorized_by', $columns);
    $hasAccountStatus = in_array('account_status', $columns);
    $hasPasswordChanged = in_array('password_changed', $columns);
    
    // Build INSERT statement dynamically based on available columns
    $insertFields = ['name', 'email', 'password', 'temp_password', 'date_of_birth', 
                     'phone_number', 'voter_id', 'face_image', 'role', 'created_at', 'authorized'];
    $insertValues = [':name', ':email', ':password', ':temp_password', ':date_of_birth', 
                      'NULL', 'NULL', ':face_image', "'voter'", 'NOW()', '1'];
    
    $params = [
        ':name' => $name,
        ':email' => $email,
        ':password' => $hashedPassword,
        ':temp_password' => $tempPassword,
        ':date_of_birth' => $dateOfBirth,
        ':face_image' => $faceImagePath
    ];
    
    if ($hasAuthorizedAt) {
        $insertFields[] = 'authorized_at';
        $insertValues[] = 'NOW()';
    }
    
    if ($hasAuthorizedBy) {
        $insertFields[] = 'authorized_by';
        $insertValues[] = ':admin_id';
        $params[':admin_id'] = $admin['id'];
    }
    
    if ($hasAccountStatus) {
        $insertFields[] = 'account_status';
        $insertValues[] = "'TEMP_PASSWORD'";
    }
    
    if ($hasPasswordChanged) {
        $insertFields[] = 'password_changed';
        $insertValues[] = '0';
    }
    
    $sql = "INSERT INTO users (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $userId = $pdo->lastInsertId();
    
    // Generate blockchain address (placeholder - integrate with your blockchain service)
    // Only update if column exists
    try {
        $blockchainAddress = '0x' . bin2hex(random_bytes(20));
        $stmt = $pdo->prepare("UPDATE users SET blockchain_address = ? WHERE id = ?");
        $stmt->execute([$blockchainAddress, $userId]);
    } catch (PDOException $e) {
        // blockchain_address column might not exist, that's okay
        error_log("Warning: Could not set blockchain_address: " . $e->getMessage());
    }
    
    // Add voter to eligible_voters table for the selected election
    // This MUST happen inside the transaction to ensure data consistency
    try {
        // Create eligible_voters table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `eligible_voters` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `election_id` int(11) NOT NULL,
                `name` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `has_registered` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_election_email` (`election_id`, `email`),
                KEY `election_id` (`election_id`),
                KEY `email` (`email`),
                KEY `active` (`active`),
                CONSTRAINT `eligible_voters_ibfk_1` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Normalize email for comparison (lowercase, trimmed) - IMPORTANT: Store normalized email
        $normalizedEmail = strtolower(trim($email));
        
        // Verify election exists before inserting
        $electionCheck = $pdo->prepare("SELECT id FROM elections WHERE id = ?");
        $electionCheck->execute([$electionId]);
        if (!$electionCheck->fetch()) {
            throw new Exception("Election ID $electionId does not exist");
        }
        
        // Check if voter is already eligible for this election (using normalized email for comparison)
        $checkEligible = $pdo->prepare("SELECT id FROM eligible_voters WHERE election_id = ? AND LOWER(TRIM(email)) = ?");
        $checkEligible->execute([$electionId, $normalizedEmail]);
        $existingEligible = $checkEligible->fetch();
        
        if ($existingEligible) {
            // Update existing record to set active and has_registered
            // IMPORTANT: Also update email to normalized version to ensure consistency
            $updateEligible = $pdo->prepare("UPDATE eligible_voters SET active = 1, has_registered = 1, name = ?, email = ? WHERE id = ?");
            $updateResult = $updateEligible->execute([$name, $normalizedEmail, $existingEligible['id']]);
            if (!$updateResult) {
                $errorInfo = $updateEligible->errorInfo();
                throw new Exception("Failed to update eligible_voters: " . ($errorInfo[2] ?? 'Unknown error'));
            }
            error_log("✅ Updated eligible_voters record for email: '$normalizedEmail' (normalized), election_id: $electionId, record_id: " . $existingEligible['id']);
        } else {
            // Insert new record - manually registered voters should always be active
            // IMPORTANT: Store normalized email to ensure consistent matching
            $insertEligible = $pdo->prepare("INSERT INTO eligible_voters (name, email, election_id, active, has_registered) VALUES (?, ?, ?, 1, 1)");
            $insertResult = $insertEligible->execute([$name, $normalizedEmail, $electionId]);
            if (!$insertResult) {
                $errorInfo = $insertEligible->errorInfo();
                throw new Exception("Failed to insert into eligible_voters: " . ($errorInfo[2] ?? 'Unknown error'));
            }
            $eligibleId = $pdo->lastInsertId();
            if (!$eligibleId) {
                throw new Exception("Failed to get insert ID for eligible_voters");
            }
            error_log("✅ Inserted new eligible_voters record - Email: '$normalizedEmail' (normalized), Election ID: $electionId, Record ID: $eligibleId");
        }
        
        // Verify the record was created/updated (using normalized email)
        $verifyStmt = $pdo->prepare("SELECT id, election_id, email, active, has_registered, LOWER(TRIM(email)) as normalized FROM eligible_voters WHERE election_id = ? AND LOWER(TRIM(email)) = ?");
        $verifyStmt->execute([$electionId, $normalizedEmail]);
        $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if ($verified) {
            error_log("✅ Verified eligible_voters record exists: " . json_encode($verified));
            error_log("   └─ Stored email: '{$verified['email']}', Normalized: '{$verified['normalized']}'");
        } else {
            error_log("❌ ERROR: eligible_voters record not found after insert/update!");
            error_log("   └─ Searched for: election_id=$electionId, email='$normalizedEmail'");
            throw new Exception("eligible_voters record verification failed");
        }
        
        // Also add to election_voter_authorization table for compatibility with voting system
        // This ensures manually registered voters can vote even if the system checks this table
        try {
            // Check if election_voter_authorization table exists
            $checkAuthTable = $pdo->query("SHOW TABLES LIKE 'election_voter_authorization'");
            if ($checkAuthTable->rowCount() > 0) {
                // Insert or update authorization record
                $authInsert = $pdo->prepare("
                    INSERT INTO election_voter_authorization (user_id, election_id, authorized, has_voted) 
                    VALUES (?, ?, 1, 0)
                    ON DUPLICATE KEY UPDATE authorized = 1, has_voted = 0
                ");
                $authResult = $authInsert->execute([$userId, $electionId]);
                if ($authResult) {
                    error_log("✅ Added/updated election_voter_authorization record for user_id: $userId, election_id: $electionId");
                } else {
                    error_log("⚠️ Could not add to election_voter_authorization (non-critical)");
                }
            } else {
                error_log("ℹ️ election_voter_authorization table does not exist - skipping");
            }
        } catch (PDOException $e) {
            // Non-critical - eligible_voters is the primary source
            error_log("⚠️ Could not add to election_voter_authorization: " . $e->getMessage());
        }
    } catch (PDOException $e) {
        // Rollback transaction if eligible_voters insert fails
        error_log("❌ PDO Error adding voter to eligible_voters: " . $e->getMessage());
        error_log("Error code: " . $e->getCode());
        error_log("Stack trace: " . $e->getTraceAsString());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e; // Re-throw to fail the registration
    } catch (Exception $e) {
        // Rollback transaction if eligible_voters insert fails
        error_log("❌ Error adding voter to eligible_voters: " . $e->getMessage());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e; // Re-throw to fail the registration
    }
    
    // Log the registration (only if table exists)
    try {
        // Check if voter_activity_log table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'voter_activity_log'")->fetch();
        if ($tableCheck) {
            $stmt = $pdo->prepare("
                INSERT INTO voter_activity_log 
                (user_id, activity_type, ip_address, user_agent, status, details, created_at)
                VALUES (?, 'registration', ?, ?, 'success', 'Voter registered by admin', NOW())
            ");
            $stmt->execute([
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        }
    } catch (PDOException $e) {
        // voter_activity_log table might not exist, that's okay - just log it
        error_log("Warning: Could not log voter activity: " . $e->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Write/append CSV for this election (non-blocking if it fails)
    $csvStatus = ['written' => false];
    try {
        $csvStatus = appendVoterToCsv($election['title'] ?? '', $electionId, $name, $email, $dateOfBirth);
    } catch (Exception $csvEx) {
        $csvStatus['error'] = $csvEx->getMessage();
        error_log("CSV export failed for election {$electionId}: " . $csvEx->getMessage());
    }
    
    $loginUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth';
    
    // Send email with credentials
    $emailSent = false;
    $emailError = null;
    try {
        $emailSent = sendVoterCredentialsEmail($email, $name, $tempPassword, $loginUrl);
        if (!$emailSent) {
            $emailError = "Email service returned false. Check SMTP configuration and PHP error logs.";
            error_log("Failed to send credentials email to {$email}. Voter created but email notification failed.");
        }
    } catch (Exception $e) {
        $emailError = $e->getMessage();
        error_log("Exception while sending email to {$email}: " . $emailError);
    }
    
    // Return success response (voter is created even if email fails)
    $response = [
        'success' => true,
        'message' => 'Voter registered successfully',
        'data' => [
            'email' => $email,
            'email_sent' => $emailSent,
            'user_id' => $userId,
            'csv_status' => $csvStatus
        ]
    ];
    
    if (!$emailSent) {
        $response['warning'] = 'Voter registered but email could not be sent. ' . ($emailError ?? 'Check email configuration.');
        $response['data']['email_error'] = $emailError;
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    // Rollback transaction on database error (only if transaction is active)
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackError) {
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
    }
    
    // Delete uploaded face image if it was saved
    if (!empty($filePath) && file_exists($filePath)) {
        @unlink($filePath);
    }
    
    // Log detailed error information
    $errorMessage = $e->getMessage();
    $errorCode = $e->getCode();
    $errorInfo = $e->errorInfo ?? [];
    
    error_log("Voter registration PDO error: " . $errorMessage);
    error_log("PDO Error Code: " . $errorCode);
    error_log("PDO Error Info: " . print_r($errorInfo, true));
    
    // Check for specific database errors
    if (strpos($errorMessage, 'Duplicate entry') !== false || 
        strpos($errorMessage, 'email') !== false && strpos($errorMessage, 'unique') !== false) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email already exists. Please use a different email address.'
        ]);
    } else {
        http_response_code(500);
        $isDevelopment = (isset($_SERVER['HTTP_HOST']) && 
                          (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
                           strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false));
        
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred while registering the voter',
            'error' => $isDevelopment ? $errorMessage : 'Please try again or contact support.',
            'debug' => $isDevelopment ? [
                'code' => $errorCode,
                'info' => $errorInfo
            ] : null
        ]);
    }
} catch (Exception $e) {
    // Rollback transaction on other errors (only if transaction is active)
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollbackError) {
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
    }
    
    // Delete uploaded face image if it was saved
    if (!empty($filePath) && file_exists($filePath)) {
        @unlink($filePath);
    }
    
    // Log detailed error information
    $errorMessage = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    error_log("Voter registration error: " . $errorMessage);
    error_log("Stack trace: " . $errorTrace);
    
    http_response_code(500);
    
    // In development, show detailed error. In production, show generic message
    $isDevelopment = (isset($_SERVER['HTTP_HOST']) && 
                      (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
                       strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false));
    
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while registering the voter: ' . $errorMessage,
        'error' => $isDevelopment ? $errorMessage : 'Please try again or contact support.',
        'debug' => $isDevelopment ? [
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $errorTrace
        ] : null
    ]);
}
