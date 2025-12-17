<?php
/**
 * CSV Helper Functions for VoteSecure
 * 
 * This file provides reusable functions for managing CSV files
 * for voter registration data. Each election has its own CSV file.
 * 
 * @author VoteSecure Team
 * @version 1.0
 */

/**
 * Generate a safe filename for election CSV based on election ID
 * 
 * Format: election_{election_id}_voters.csv
 * Example: election_2025_voters.csv
 * 
 * @param int $electionId The election ID
 * @return string The safe filename
 */
function generateElectionCsvFilename($electionId) {
    // Validate election ID
    $electionId = intval($electionId);
    if ($electionId <= 0) {
        throw new InvalidArgumentException("Invalid election ID: $electionId");
    }
    
    // Generate filename: election_{id}_voters.csv
    $filename = "election_{$electionId}_voters.csv";
    
    return $filename;
}

/**
 * Get the full path to the elections directory
 * Creates the directory if it doesn't exist
 * 
 * @return string The full path to the elections directory
 * @throws Exception If directory cannot be created or is not writable
 */
function getElectionsDirectory() {
    // Path relative to this file: backend/api/elections/
    $electionsDir = __DIR__ . DIRECTORY_SEPARATOR . 'elections';
    
    // Create directory if it doesn't exist
    if (!is_dir($electionsDir)) {
        if (!mkdir($electionsDir, 0755, true) && !is_dir($electionsDir)) {
            throw new Exception("Could not create elections directory: {$electionsDir}");
        }
    }
    
    // Ensure directory is writable
    if (!is_writable($electionsDir)) {
        // Try to make it writable
        @chmod($electionsDir, 0755);
        
        // Check again
        if (!is_writable($electionsDir)) {
            throw new Exception("Elections directory is not writable: {$electionsDir}");
        }
    }
    
    return $electionsDir;
}

/**
 * Write or append voter data to election CSV file
 * 
 * This function:
 * - Creates the CSV file with headers if it doesn't exist
 * - Appends new voter data as a new row if file exists
 * - Never overwrites existing data
 * 
 * @param int $electionId The election ID
 * @param string $name Voter's full name
 * @param string $email Voter's email address
 * @param string $dateOfBirth Voter's date of birth (YYYY-MM-DD format)
 * @param string|null $registrationTimestamp Optional registration timestamp (defaults to current time)
 * @return array Returns array with 'success', 'message', 'file_path', and 'created' status
 * @throws Exception If file operations fail
 */
function appendVoterToElectionCsv($electionId, $name, $email, $dateOfBirth, $registrationTimestamp = null) {
    try {
        // Validate inputs
        $electionId = intval($electionId);
        if ($electionId <= 0) {
            throw new InvalidArgumentException("Invalid election ID");
        }
        
        $name = trim($name);
        if (empty($name)) {
            throw new InvalidArgumentException("Name cannot be empty");
        }
        
        $email = strtolower(trim($email));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address");
        }
        
        $dateOfBirth = trim($dateOfBirth);
        if (empty($dateOfBirth)) {
            throw new InvalidArgumentException("Date of birth cannot be empty");
        }
        
        // Set registration timestamp if not provided
        if ($registrationTimestamp === null) {
            $registrationTimestamp = date('Y-m-d H:i:s');
        }
        
        // Get elections directory (creates if needed)
        $electionsDir = getElectionsDirectory();
        
        // Generate filename
        $filename = generateElectionCsvFilename($electionId);
        $csvPath = $electionsDir . DIRECTORY_SEPARATOR . $filename;
        
        // Check if file exists (to determine if we need to add header)
        $isNewFile = !file_exists($csvPath);
        
        // Open file in append mode (creates file if it doesn't exist)
        $fileHandle = fopen($csvPath, 'a');
        if ($fileHandle === false) {
            throw new Exception("Could not open CSV file for writing: {$csvPath}");
        }
        
        // Add header row if this is a new file
        if ($isNewFile) {
            $header = ['Name', 'Email', 'Date of Birth', 'Registration Timestamp'];
            fputcsv($fileHandle, $header);
        }
        
        // Prepare voter data row
        $voterData = [
            $name,
            $email,
            $dateOfBirth,
            $registrationTimestamp
        ];
        
        // Write voter data as a new row
        fputcsv($fileHandle, $voterData);
        
        // Close file handle
        fclose($fileHandle);
        
        // Return success response
        return [
            'success' => true,
            'message' => $isNewFile ? 'CSV file created and voter added' : 'Voter added to existing CSV file',
            'file_path' => $csvPath,
            'created' => $isNewFile,
            'filename' => $filename
        ];
        
    } catch (InvalidArgumentException $e) {
        // Re-throw validation errors
        throw $e;
    } catch (Exception $e) {
        // Wrap other exceptions with context
        throw new Exception("Failed to write to CSV file: " . $e->getMessage(), 0, $e);
    }
}

/**
 * Check if a CSV file exists for a given election
 * 
 * @param int $electionId The election ID
 * @return bool True if file exists, false otherwise
 */
function electionCsvExists($electionId) {
    try {
        $electionsDir = getElectionsDirectory();
        $filename = generateElectionCsvFilename($electionId);
        $csvPath = $electionsDir . DIRECTORY_SEPARATOR . $filename;
        
        return file_exists($csvPath);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get the full path to an election's CSV file
 * 
 * @param int $electionId The election ID
 * @return string|null The full path to the CSV file, or null if directory doesn't exist
 */
function getElectionCsvPath($electionId) {
    try {
        $electionsDir = getElectionsDirectory();
        $filename = generateElectionCsvFilename($electionId);
        return $electionsDir . DIRECTORY_SEPARATOR . $filename;
    } catch (Exception $e) {
        return null;
    }
}

?>

