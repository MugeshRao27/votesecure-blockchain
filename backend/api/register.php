<?php
require_once 'config.php';
require_once 'auth-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify admin authentication
$admin = verifyAdminToken($pdo);
if (!$admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

// Ensure users table exists with correct structure
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            temp_password VARCHAR(255) NOT NULL,
            date_of_birth DATE NOT NULL,
            face_image VARCHAR(255) DEFAULT NULL,
            role ENUM('voter', 'admin') NOT NULL DEFAULT 'voter',
            authorized TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Check if authorized column exists, add if not
    $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'authorized'")->fetch();
    if (!$columns) {
        $pdo->exec("ALTER TABLE users ADD COLUMN authorized TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    }
    
    // Check if face_image column is VARCHAR, modify if needed
    $faceImageColumn = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'face_image'")->fetch();
    if ($faceImageColumn && strpos(strtolower($faceImageColumn['Type']), 'text') !== false) {
        $pdo->exec("ALTER TABLE users MODIFY face_image VARCHAR(255) DEFAULT NULL");
    }
    
    // Check if date_of_birth column exists, add if not
    $dateOfBirthColumn = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'date_of_birth'")->fetch();
    if (!$dateOfBirthColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN date_of_birth DATE NOT NULL AFTER password");
    }
    
    // Check if temp_password column exists, add if not
    $tempPasswordColumn = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'temp_password'")->fetch();
    if (!$tempPasswordColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN temp_password VARCHAR(255) NOT NULL AFTER password");
    }
} catch(PDOException $e) {
    error_log("Error ensuring users table exists: " . $e->getMessage());
    // Continue anyway - table might already exist
}

// Get and validate input data
$data = json_decode(file_get_contents('php://input'), true);

// Validate input
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$role = $data['role'] ?? 'voter';
$face_image = $data['face_image'] ?? '';

// Validation
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Name is required']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Valid email is required']);
    exit;
}

if (empty($password) || strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

if (!in_array($role, ['voter', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit;
}

// Registration is open; no eligible_voters check

// Check if email already exists
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Handle face image - save as file if provided (for voters)
$face_image_path = '';
if (!empty($face_image) && $role === 'voter') {
    try {
        // Handle base64 image - remove data URL prefix if present
        $base64_data = $face_image;
        if (strpos($base64_data, ',') !== false) {
            // If it contains a comma, it might have the data URL prefix
            $parts = explode(',', $base64_data, 2);
            $base64_data = $parts[1]; // Take the part after the comma
        }
        
        // Decode base64 image
        $image_data = base64_decode($base64_data, true); // strict mode
        if ($image_data === false) {
            error_log("Failed to decode base64 image data. Length: " . strlen($base64_data));
            echo json_encode(['success' => false, 'message' => 'Invalid image data - could not decode base64']);
            exit;
        }

        // Verify it's actually image data (check for image headers)
        $image_info = @getimagesizefromstring($image_data);
        if ($image_info === false) {
            error_log("Decoded data is not a valid image");
            echo json_encode(['success' => false, 'message' => 'Invalid image data - not a valid image file']);
            exit;
        }

        // Create upload directory if it doesn't exist
        // Standard path: backend/api/uploads/faces/
        // register.php is in backend/api/, so we use __DIR__ directly
        $upload_dir = __DIR__ . '/uploads/faces/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                error_log("Failed to create upload directory: $upload_dir");
                echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
                exit;
            }
        }

        // Check if directory is writable
        if (!is_writable($upload_dir)) {
            error_log("Upload directory is not writable: $upload_dir");
            echo json_encode(['success' => false, 'message' => 'Upload directory is not writable']);
            exit;
        }

        // Generate unique filename
        $filename = uniqid() . '_' . time() . '.jpg';
        $file_path = $upload_dir . $filename;

        // Save image file
        $bytes_written = file_put_contents($file_path, $image_data);
        if ($bytes_written === false || $bytes_written === 0) {
            error_log("Failed to write image file to: $file_path");
            echo json_encode(['success' => false, 'message' => 'Failed to save face image file']);
            exit;
        }
        
        // Verify file was created
        if (!file_exists($file_path)) {
            error_log("File was not created after write: $file_path");
            echo json_encode(['success' => false, 'message' => 'Face image file was not created']);
            exit;
        }
        
        $face_image_path = 'uploads/faces/' . $filename;
        error_log("Face image saved successfully: $face_image_path (Size: $bytes_written bytes)");
    } catch (Exception $e) {
        error_log("Exception processing face image: " . $e->getMessage());
        error_log("Exception trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Error processing face image: ' . $e->getMessage()]);
        exit;
    }
}

// Check auto-authorization settings
$autoAuthEnabled = true; // Default
$faceRequired = true; // Default

try {
    // Check if settings table exists and get settings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_authorize_enabled', 'auto_authorize_face_required')");
    $settings = $stmt->fetchAll();
    
    foreach ($settings as $setting) {
        if ($setting['setting_key'] === 'auto_authorize_enabled') {
            $autoAuthEnabled = ($setting['setting_value'] === '1');
        }
        if ($setting['setting_key'] === 'auto_authorize_face_required') {
            $faceRequired = ($setting['setting_value'] === '1');
        }
    }
} catch (Exception $e) {
    // Use defaults if settings table doesn't exist
}

// Insert user
try {
    // First verify the table exists and check structure
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$tableCheck) {
        echo json_encode(['success' => false, 'message' => 'Users table does not exist. Please check database setup.']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, face_image, verified, authorized) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $verified = ($role === 'admin') ? 1 : 0; // Auto-verify admins
    
    // Auto-authorize logic based on settings
    if ($role === 'admin') {
        $authorized = 1; // Admins always authorized
    } elseif ($autoAuthEnabled) {
        // Auto-authorization is enabled
        if ($faceRequired) {
            // Face image required - only authorize if face image provided
            $authorized = empty($face_image_path) ? 0 : 1;
        } else {
            // Face image not required - authorize all voters
            $authorized = 1;
        }
    } else {
        // Auto-authorization disabled - require manual approval
        $authorized = 0;
    }
    
    // Log for debugging
    error_log("Registration attempt - Email: $email, Role: $role, Face image path: $face_image_path, Authorized: $authorized");
    
    // Prepare values array
    $values = [$name, $email, $hashed_password, $role, $face_image_path, $verified, $authorized];
    error_log("Insert values: " . print_r($values, true));
    
    $executeResult = $stmt->execute($values);
    $rowCount = $stmt->rowCount();
    $user_id = $pdo->lastInsertId();
    
    error_log("Execute result: " . ($executeResult ? 'true' : 'false'));
    error_log("Row count: $rowCount");
    error_log("Last insert ID: $user_id");
    
    // Check for SQL errors
    $errorInfo = $stmt->errorInfo();
    if ($errorInfo[0] !== '00000') {
        error_log("SQL Error Info: " . print_r($errorInfo, true));
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . ($errorInfo[2] ?? 'Unknown error'),
            'error_code' => $errorInfo[0],
            'sqlstate' => $errorInfo[0]
        ]);
        exit;
    }
    
    if ($executeResult && $rowCount > 0 && $user_id > 0) {
        error_log("User inserted successfully with ID: $user_id");
        
        // Immediately verify the user was inserted
        $verifyStmt = $pdo->prepare("SELECT id, name, email, role, face_image, verified, authorized, created_at FROM users WHERE id = ?");
        $verifyStmt->execute([$user_id]);
        $user = $verifyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log("ERROR: User ID $user_id was returned but user not found in database!");
            echo json_encode([
                'success' => false, 
                'message' => 'Registration failed: User was not saved to database. Please check database connection and table structure.',
                'debug' => ['insert_id' => $user_id, 'row_count' => $rowCount]
            ]);
            exit;
        }
        
        error_log("User verified in database: " . print_r($user, true));

        // Link voter to eligible_voters and election_voter_list when they register
        if ($role === 'voter') {
            $normalizedEmail = strtolower(trim($email));
            
            try {
                // Update eligible_voters table - mark as registered
                $checkTable = $pdo->query("SHOW TABLES LIKE 'eligible_voters'");
                if ($checkTable->rowCount() > 0) {
                    // Update has_registered flag for all elections where this email is eligible
                    $updateEligible = $pdo->prepare("
                        UPDATE eligible_voters 
                        SET has_registered = 1 
                        WHERE LOWER(TRIM(email)) = ? 
                          AND has_registered = 0
                    ");
                    $updateEligible->execute([$normalizedEmail]);
                    error_log("Updated eligible_voters has_registered for email: $normalizedEmail");
                }
            } catch (PDOException $e) {
                error_log("Could not update eligible_voters: " . $e->getMessage());
            }
            
            // Also check election_voter_list and sync to eligible_voters if needed
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'election_voter_list'");
                if ($checkTable->rowCount() > 0) {
                    // Get all elections this voter is in from election_voter_list
                    $listStmt = $pdo->prepare("SELECT DISTINCT election_id, name FROM election_voter_list WHERE LOWER(TRIM(email)) = ?");
                    $listStmt->execute([$normalizedEmail]);
                    $electionList = $listStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($electionList)) {
                        // Ensure eligible_voters table exists
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
                        
                        // Sync to eligible_voters table
                        $syncStmt = $pdo->prepare("
                            INSERT INTO eligible_voters (election_id, name, email, active, has_registered) 
                            VALUES (?, ?, ?, 1, 1)
                            ON DUPLICATE KEY UPDATE has_registered = 1, active = 1, name = VALUES(name)
                        ");
                        
                        foreach ($electionList as $election) {
                            $syncStmt->execute([
                                $election['election_id'],
                                $election['name'],
                                $normalizedEmail
                            ]);
                        }
                        error_log("Synced " . count($electionList) . " elections from election_voter_list to eligible_voters for email: $normalizedEmail");
                    }
                }
            } catch (PDOException $e) {
                error_log("Could not sync election_voter_list to eligible_voters: " . $e->getMessage());
            }
        }
        
        $message = 'Registration successful';
        if ($role === 'voter' && $authorized) {
            $message .= ' - You have been automatically authorized to vote!';
        }
        
        error_log("Registration successful for user ID: $user_id, Email: $email");
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'user' => $user
        ]);
    } else {
        $errorInfo = $stmt->errorInfo();
        error_log("Registration failed - Execute returned false");
        error_log("SQL Error Info: " . print_r($errorInfo, true));
        error_log("Row count: $rowCount, Last insert ID: $user_id");
        
        // Check if it's a duplicate email error
        if ($errorInfo[0] === '23000' || strpos($errorInfo[2] ?? '', 'Duplicate') !== false) {
            echo json_encode([
                'success' => false, 
                'message' => 'Email already exists. Please use a different email or try logging in.'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Registration failed: ' . ($errorInfo[2] ?? 'Unknown database error'),
                'debug' => [
                    'execute_result' => $executeResult,
                    'row_count' => $rowCount,
                    'last_insert_id' => $user_id,
                    'error_info' => $errorInfo
                ]
            ]);
        }
    }
} catch(PDOException $e) {
    error_log("Registration PDO Exception: " . $e->getMessage());
    error_log("Registration PDO Exception Code: " . $e->getCode());
    error_log("Registration PDO Exception Trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Registration error: ' . $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
}
?>

