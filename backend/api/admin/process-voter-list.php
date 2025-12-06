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

// Check if file was uploaded
if (!isset($_FILES['voter_list']) || $_FILES['voter_list']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$election_id = $_POST['election_id'] ?? 0;
$replace_existing = isset($_POST['replace_existing']) && $_POST['replace_existing'] === 'true';

if (empty($election_id)) {
    echo json_encode(['success' => false, 'message' => 'Election ID is required']);
    exit;
}

// Verify election exists
try {
    $stmt = $pdo->prepare("SELECT id, start_date, status FROM elections WHERE id = ?");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
    
    // Check if election has started (prevent updates after start)
    $now = date('Y-m-d H:i:s');
    $startDate = $election['start_date'];
    if (strlen($startDate) === 10) $startDate .= ' 00:00:00';
    
    if ($now >= $startDate && !$replace_existing) {
        echo json_encode(['success' => false, 'message' => 'Cannot update voter list after election has started']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$file = $_FILES['voter_list'];
$fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Validate file type
$allowedExtensions = ['csv', 'xlsx', 'xls'];
if (!in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only CSV and Excel files are allowed']);
    exit;
}

// Process file based on extension
$voters = [];

try {
    if ($fileExtension === 'csv') {
        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            throw new Exception('Failed to open CSV file');
        }
        
        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new Exception('CSV file is empty');
        }
        
        // Normalize headers (case-insensitive, trim whitespace)
        $headers = array_map(function($h) {
            return strtolower(trim($h));
        }, $headers);
        
        // Find required columns
        $nameIndex = array_search('name', $headers);
        $emailIndex = array_search('email', $headers);
        $phoneIndex = array_search('phone', $headers);
        
        if ($nameIndex === false || $emailIndex === false) {
            fclose($handle);
            throw new Exception('CSV must contain "name" and "email" columns');
        }
        
        $rowNum = 1; // Start from 1 (header is row 0)
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            $name = trim($row[$nameIndex] ?? '');
            $email = strtolower(trim($row[$emailIndex] ?? ''));
            $phone = isset($phoneIndex) && $phoneIndex !== false ? trim($row[$phoneIndex] ?? '') : null;
            
            // Validate required fields
            if (empty($name) || empty($email)) {
                continue; // Skip invalid rows
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue; // Skip invalid emails
            }
            
            $voters[] = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone
            ];
        }
        fclose($handle);
        
    } else {
        // Process Excel file (xlsx, xls)
        // Note: Requires PHPExcel or PhpSpreadsheet library
        // For now, we'll provide a basic implementation that requires the library
        
        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            // Fallback: Try to read as CSV if it's actually a CSV with .xlsx extension
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $headers = fgetcsv($handle);
                if ($headers) {
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $headers);
                    
                    $nameIndex = array_search('name', $headers);
                    $emailIndex = array_search('email', $headers);
                    $phoneIndex = array_search('phone', $headers);
                    
                    if ($nameIndex !== false && $emailIndex !== false) {
                        while (($row = fgetcsv($handle)) !== false) {
                            if (empty(array_filter($row))) continue;
                            
                            $name = trim($row[$nameIndex] ?? '');
                            $email = strtolower(trim($row[$emailIndex] ?? ''));
                            $phone = isset($phoneIndex) && $phoneIndex !== false ? trim($row[$phoneIndex] ?? '') : null;
                            
                            if (!empty($name) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $voters[] = [
                                    'name' => $name,
                                    'email' => $email,
                                    'phone' => $phone
                                ];
                            }
                        }
                    }
                }
                fclose($handle);
            }
        } else {
            // Use PhpSpreadsheet for proper Excel parsing
            require_once __DIR__ . '/../../vendor/autoload.php';
            
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows)) {
                throw new Exception('Excel file is empty');
            }
            
            // First row is headers
            $headers = array_map(function($h) {
                return strtolower(trim($h ?? ''));
            }, array_shift($rows));
            
            $nameIndex = array_search('name', $headers);
            $emailIndex = array_search('email', $headers);
            $phoneIndex = array_search('phone', $headers);
            
            if ($nameIndex === false || $emailIndex === false) {
                throw new Exception('Excel file must contain "name" and "email" columns');
            }
            
            foreach ($rows as $row) {
                if (empty(array_filter($row))) continue;
                
                $name = trim($row[$nameIndex] ?? '');
                $email = strtolower(trim($row[$emailIndex] ?? ''));
                $phone = isset($phoneIndex) && $phoneIndex !== false ? trim($row[$phoneIndex] ?? '') : null;
                
                if (!empty($name) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $voters[] = [
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone
                    ];
                }
            }
        }
    }
    
    if (empty($voters)) {
        echo json_encode(['success' => false, 'message' => 'No valid voters found in the file']);
        exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // If replacing, delete existing voter list
        if ($replace_existing) {
            $deleteStmt = $pdo->prepare("DELETE FROM election_voter_list WHERE election_id = ?");
            $deleteStmt->execute([$election_id]);
        }
        
        // Create eligible_voters table if it doesn't exist (for compatibility)
        try {
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
        } catch (PDOException $e) {
            error_log("Could not create eligible_voters table: " . $e->getMessage());
        }
        
        // Generate unique voter IDs and insert voters into election_voter_list
        $insertStmt = $pdo->prepare("
            INSERT INTO election_voter_list (election_id, voter_id, email, name, phone) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), updated_at = NOW()
        ");
        
        // Also prepare statement for eligible_voters table (for compatibility with manual registration)
        // IMPORTANT: Use ON DUPLICATE KEY UPDATE that preserves has_registered = 1 if it was manually set
        // This ensures manually registered voters are not overwritten by CSV uploads
        $insertEligibleStmt = $pdo->prepare("
            INSERT INTO eligible_voters (election_id, name, email, active, has_registered) 
            VALUES (?, ?, ?, 1, 0)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name), 
                active = 1, 
                updated_at = NOW(),
                has_registered = IF(has_registered = 1, 1, VALUES(has_registered))
        ");
        
        $inserted = 0;
        $updated = 0;
        $errors = [];
        
        foreach ($voters as $index => $voter) {
            // Generate unique voter ID: ELECTION_ID + timestamp + random string
            $voterId = 'V' . $election_id . '_' . time() . '_' . bin2hex(random_bytes(4));
            
            try {
                // Insert into election_voter_list
                $result = $insertStmt->execute([
                    $election_id,
                    $voterId,
                    $voter['email'],
                    $voter['name'],
                    $voter['phone']
                ]);
                
                if ($result) {
                    if ($insertStmt->rowCount() === 1) {
                        $inserted++;
                    } else {
                        $updated++;
                    }
                    
                    // Also add to eligible_voters table for compatibility
                    // This ensures voters from CSV can see elections just like manually registered voters
                    try {
                        $normalizedEmail = strtolower(trim($voter['email']));
                        $insertEligibleStmt->execute([
                            $election_id,
                            $voter['name'],
                            $normalizedEmail
                        ]);
                    } catch (PDOException $e) {
                        // Log but don't fail - eligible_voters insert is optional
                        error_log("Could not add to eligible_voters: " . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                // Check if it's a duplicate key error
                if ($e->getCode() === '23000') {
                    $updated++;
                } else {
                    $errors[] = "Row " . ($index + 2) . " (Email: {$voter['email']}): " . $e->getMessage();
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Voter list processed successfully",
            'stats' => [
                'total' => count($voters),
                'inserted' => $inserted,
                'updated' => $updated,
                'errors' => count($errors)
            ],
            'errors' => $errors
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error processing voter list: ' . $e->getMessage()]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error reading file: ' . $e->getMessage()]);
}

