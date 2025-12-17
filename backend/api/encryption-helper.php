<?php
/**
 * Encryption Helper for Vote Data
 * Provides secure encryption/decryption for vote records
 */

class EncryptionHelper {
    private $encryptionKey;
    private $cipher = 'AES-256-GCM';
    
    public function __construct() {
        // Get encryption key from environment or use default (should be set in production)
        $this->encryptionKey = getenv('VOTE_ENCRYPTION_KEY') ?: $this->generateDefaultKey();
        
        // Ensure key is 32 bytes (256 bits) for AES-256
        if (strlen($this->encryptionKey) !== 32) {
            $this->encryptionKey = hash('sha256', $this->encryptionKey, true);
        }
    }
    
    /**
     * Generate a default encryption key (for development only)
     * In production, this should be set via environment variable
     */
    private function generateDefaultKey() {
        // Use a combination of server-specific data for default key
        $defaultKey = hash('sha256', 
            (getenv('APP_SECRET') ?: 'votesecure_default_secret') . 
            (getenv('DB_NAME') ?: 'votesecure_db') .
            'vote_encryption_salt_2024'
        );
        return $defaultKey;
    }
    
    /**
     * Encrypt vote data
     * @param array $voteData Array containing election_id, candidate_id, user_id, timestamp
     * @return array Encrypted data with iv, tag, and ciphertext
     */
    public function encryptVote($voteData) {
        try {
            // Convert vote data to JSON string
            $plaintext = json_encode($voteData);
            
            // Generate random IV (initialization vector)
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = openssl_random_pseudo_bytes($ivLength);
            
            // Encrypt the data
            $ciphertext = openssl_encrypt(
                $plaintext,
                $this->cipher,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($ciphertext === false) {
                throw new Exception('Encryption failed: ' . openssl_error_string());
            }
            
            // Return encrypted data as base64 encoded strings for database storage
            return [
                'encrypted_data' => base64_encode($ciphertext),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'algorithm' => $this->cipher
            ];
            
        } catch (Exception $e) {
            error_log("Encryption error: " . $e->getMessage());
            throw new Exception('Failed to encrypt vote data: ' . $e->getMessage());
        }
    }
    
    /**
     * Decrypt vote data
     * @param array $encryptedData Array containing encrypted_data, iv, tag
     * @return array Decrypted vote data
     */
    public function decryptVote($encryptedData) {
        try {
            // Decode base64 strings
            $ciphertext = base64_decode($encryptedData['encrypted_data']);
            $iv = base64_decode($encryptedData['iv']);
            $tag = base64_decode($encryptedData['tag']);
            
            // Decrypt the data
            $plaintext = openssl_decrypt(
                $ciphertext,
                $this->cipher,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($plaintext === false) {
                throw new Exception('Decryption failed: ' . openssl_error_string());
            }
            
            // Decode JSON and return vote data
            $voteData = json_decode($plaintext, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to decode vote data: ' . json_last_error_msg());
            }
            
            return $voteData;
            
        } catch (Exception $e) {
            error_log("Decryption error: " . $e->getMessage());
            throw new Exception('Failed to decrypt vote data: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate vote hash for blockchain storage
     * Creates a unique hash from vote data that can be stored on blockchain
     * @param int $electionId
     * @param int $candidateId
     * @param int $userId
     * @param string $timestamp Optional timestamp
     * @return string Hexadecimal hash (32 bytes, 64 hex characters)
     */
    public function generateVoteHash($electionId, $candidateId, $userId, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        // Create vote data string
        $voteData = sprintf(
            '%d-%d-%d-%d',
            $electionId,
            $candidateId,
            $userId,
            $timestamp
        );
        
        // Generate SHA-256 hash (32 bytes)
        $hash = hash('sha256', $voteData);
        
        return $hash;
    }
    
    /**
     * Verify vote hash matches vote data
     * @param string $hash The hash to verify
     * @param int $electionId
     * @param int $candidateId
     * @param int $userId
     * @param string $timestamp
     * @return bool True if hash matches
     */
    public function verifyVoteHash($hash, $electionId, $candidateId, $userId, $timestamp) {
        $expectedHash = $this->generateVoteHash($electionId, $candidateId, $userId, $timestamp);
        return hash_equals($expectedHash, $hash);
    }
}

