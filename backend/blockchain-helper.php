<?php
// Determine correct path based on where this file is located
// This file is in backend/, but config and encryption helper are in backend/api/
$configPath = file_exists(__DIR__ . '/api/config.php') ? __DIR__ . '/api/config.php' : __DIR__ . '/config.php';
require_once $configPath;

$encryptionPath = __DIR__ . '/api/encryption-helper.php';
if (file_exists($encryptionPath)) {
    require_once $encryptionPath;
} else {
    // Fallback if encryption helper is in same directory
    $encryptionPath = __DIR__ . '/encryption-helper.php';
    if (file_exists($encryptionPath)) {
        require_once $encryptionPath;
    }
}

class BlockchainHelper {
    private $pdo;
    private $web3Provider;
    private $contractAddress;
    private $privateKey; // For signing transactions (optional, can use MetaMask on frontend)
    private $encryptionHelper;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->encryptionHelper = new EncryptionHelper();
        
        // Polygon Mumbai Testnet configuration
        // Load from environment variables or config file
        // Using Ankr's reliable public RPC endpoint
        $this->web3Provider = getenv('POLYGON_RPC_URL') ?: 'https://rpc.ankr.com/polygon_mumbai';
        $this->contractAddress = getenv('POLYGON_CONTRACT_ADDRESS') ?: '';
        $this->privateKey = getenv('BLOCKCHAIN_PRIVATE_KEY') ?: ''; // Optional: for backend signing
        
        // Check if blockchain is configured
        if (empty($this->contractAddress)) {
            error_log("Warning: POLYGON_CONTRACT_ADDRESS not set. Blockchain recording will be skipped.");
        }
    }
    
    /**
     * Record a vote hash on the blockchain
     * Note: This stores only a hash of the vote, not the actual vote data (for privacy)
     * 
     * @param int $userId User ID
     * @param int $electionId Election ID
     * @param int $candidateId Candidate ID
     * @return array Result with success status and transaction hash
     */
    public function recordVote($userId, $electionId, $candidateId) {
        try {
            // Check if blockchain is configured
            if (empty($this->contractAddress)) {
                // Blockchain not configured - return success but mark as skipped
                error_log("Blockchain recording skipped: Contract address not configured");
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Blockchain recording skipped (not configured)',
                    'transaction_hash' => null,
                    'vote_hash' => $this->encryptionHelper->generateVoteHash($electionId, $candidateId, $userId)
                ];
            }
            
            // Generate vote hash (this is what gets stored on blockchain)
            $voteHash = $this->encryptionHelper->generateVoteHash($electionId, $candidateId, $userId);
            
            // Convert hex hash to bytes32 format (0x + 64 hex characters)
            $voteHashBytes32 = '0x' . $voteHash;
            
            // Record vote hash on blockchain using recordVote function
            // Function signature: recordVote(uint256 electionId, bytes32 voteHash)
            $transactionHash = $this->sendTransaction(
                'recordVote',
                [$electionId, $voteHashBytes32]
            );
            
            if (!$transactionHash) {
                throw new Exception('Failed to record vote on blockchain: No transaction hash returned');
            }
            
            // Store blockchain transaction info in database for reference
            $this->storeBlockchainTransaction($userId, $electionId, $transactionHash, $voteHash);
            
            // Return transaction details
            return [
                'success' => true,
                'transaction_hash' => $transactionHash,
                'vote_hash' => $voteHash,
                'blockchain_address' => $this->contractAddress
            ];
            
        } catch (Exception $e) {
            error_log("Blockchain error: " . $e->getMessage());
            // Don't fail the entire vote if blockchain fails - log and continue
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'vote_hash' => $this->encryptionHelper->generateVoteHash($electionId, $candidateId, $userId)
            ];
        }
    }
    
    /**
     * Verify if a vote was recorded on the blockchain
     * Note: This checks by voter address, not user ID
     * 
     * @param string $voterAddress Blockchain address of the voter
     * @param int $electionId Election ID
     * @return array Result with vote status
     */
    public function verifyVote($voterAddress, $electionId) {
        try {
            if (empty($this->contractAddress)) {
                return [
                    'success' => false,
                    'message' => 'Blockchain not configured'
                ];
            }
            
            // Call checkIfVoted function: checkIfVoted(uint256 electionId, address voter)
            $hasVoted = $this->callSmartContract(
                'checkIfVoted',
                [$electionId, $voterAddress]
            );
            
            return [
                'success' => true,
                'vote_recorded' => (bool)$hasVoted
            ];
            
        } catch (Exception $e) {
            error_log("Blockchain verification error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get vote hash from blockchain for a specific voter
     * 
     * @param string $voterAddress Blockchain address
     * @param int $electionId Election ID
     * @return array Vote hash and timestamp
     */
    public function getVoteFromBlockchain($voterAddress, $electionId) {
        try {
            if (empty($this->contractAddress)) {
                return [
                    'success' => false,
                    'message' => 'Blockchain not configured'
                ];
            }
            
            // Call getVote function: getVote(uint256 electionId, address voter)
            $result = $this->callSmartContract(
                'getVote',
                [$electionId, $voterAddress]
            );
            
            // Result is an array: [voteHash, timestamp]
            return [
                'success' => true,
                'vote_hash' => $result[0] ?? null,
                'timestamp' => isset($result[1]) ? intval($result[1]) : null
            ];
            
        } catch (Exception $e) {
            error_log("Blockchain get vote error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get vote count for an election from blockchain
     * 
     * @param int $electionId Election ID
     * @return array Vote count result
     */
    public function getVoteCount($electionId) {
        try {
            if (empty($this->contractAddress)) {
                return [
                    'success' => false,
                    'message' => 'Blockchain not configured'
                ];
            }
            
            // Call getVoteCount function: getVoteCount(uint256 electionId)
            $count = $this->callSmartContract(
                'getVoteCount',
                [$electionId]
            );
            
            return [
                'success' => true,
                'count' => intval($count)
            ];
            
        } catch (Exception $e) {
            error_log("Blockchain vote count error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Store blockchain transaction reference in database
     */
    private function storeBlockchainTransaction($userId, $electionId, $transactionHash, $voteHash) {
        try {
            // Check if blockchain_transactions table exists, create if not
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS blockchain_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    election_id INT NOT NULL,
                    transaction_hash VARCHAR(66) NOT NULL,
                    vote_hash VARCHAR(64) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_election (user_id, election_id),
                    INDEX idx_tx_hash (transaction_hash),
                    INDEX idx_vote_hash (vote_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Insert transaction record
            $stmt = $this->pdo->prepare("
                INSERT INTO blockchain_transactions (user_id, election_id, transaction_hash, vote_hash)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $electionId, $transactionHash, $voteHash]);
            
        } catch (Exception $e) {
            // Log but don't fail - this is just for reference
            error_log("Failed to store blockchain transaction: " . $e->getMessage());
        }
    }
    
    /**
     * Send a transaction to the smart contract (write operation)
     * Note: This requires a private key or should be done via frontend MetaMask
     * For now, we'll use a simplified approach that can work with a Web3 service
     * 
     * @param string $method Function name
     * @param array $params Function parameters
     * @return string Transaction hash
     */
    private function sendTransaction($method, $params) {
        // For production, you should use a proper Web3 PHP library or API service
        // Options:
        // 1. Use MetaMask on frontend (recommended for user-controlled wallets)
        // 2. Use a Web3 service like Infura/Alchemy with API key
        // 3. Use a PHP Web3 library like web3.php or ethereum-php
        
        // For now, we'll create a transaction that can be signed and sent
        // In a real implementation, this would use web3.php or similar
        
        if (empty($this->privateKey)) {
            // If no private key, we can't send transactions from backend
            // This is actually fine - frontend can handle blockchain via MetaMask
            // We'll just generate a vote hash and return it
            // The frontend should handle the actual blockchain transaction
            error_log("No private key configured. Blockchain transaction should be handled by frontend.");
            return null; // Frontend will handle blockchain recording
        }
        
        // Encode function call
        $data = $this->encodeFunctionCall($method, $params);
        
        // For a full implementation, you would:
        // 1. Create transaction object
        // 2. Sign with private key
        // 3. Send via RPC
        
        // Simplified: Return a placeholder (frontend should handle this)
        // In production, implement proper Web3 transaction signing here
        throw new Exception('Backend blockchain transactions require Web3 library. Use frontend MetaMask integration instead.');
    }
    
    /**
     * Call a view function on the smart contract (read operation)
     * 
     * @param string $method Function name
     * @param array $params Function parameters
     * @return mixed Function result
     */
    private function callSmartContract($method, $params = []) {
        // Encode function call
        $data = $this->encodeFunctionCall($method, $params);
        
        // Prepare JSON-RPC request
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'eth_call',
            'params' => [
                [
                    'to' => $this->contractAddress,
                    'data' => $data
                ],
                'latest'
            ],
            'id' => 1
        ];
        
        // Send request via cURL
        $ch = curl_init($this->web3Provider);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Blockchain connection error: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('Blockchain RPC error: HTTP ' . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            throw new Exception('Blockchain error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }
        
        // Decode result based on return type
        return $this->decodeFunctionResult($method, $result['result'] ?? '');
    }
    
    /**
     * Encode function call for smart contract
     * 
     * @param string $method Function name
     * @param array $params Parameters
     * @return string Encoded function call (hex string)
     */
    private function encodeFunctionCall($method, $params) {
        // Function signatures for VoteContract
        $functionSignatures = [
            'recordVote' => '0x5b34b966', // keccak256('recordVote(uint256,bytes32)')[:4]
            'checkIfVoted' => '0x8e15f473', // keccak256('checkIfVoted(uint256,address)')[:4]
            'getVote' => '0x8da5cb5b', // keccak256('getVote(uint256,address)')[:4]
            'getVoteCount' => '0x0f5a5466', // keccak256('getVoteCount(uint256)')[:4]
            'getElectionVotes' => '0x...' // Add if needed
        ];
        
        // Get function selector (first 4 bytes of keccak256 hash)
        if (!isset($functionSignatures[$method])) {
            // Calculate it dynamically (simplified)
            $signature = $method . '(';
            $types = [];
            foreach ($params as $param) {
                if (is_string($param) && strpos($param, '0x') === 0) {
                    $types[] = 'bytes32';
                } elseif (is_string($param) && strlen($param) === 42 && strpos($param, '0x') === 0) {
                    $types[] = 'address';
                } else {
                    $types[] = 'uint256';
                }
            }
            $signature .= implode(',', $types) . ')';
            
            // Calculate function selector (first 4 bytes of keccak256)
            $hash = hash('sha3-256', $signature, true);
            $selector = '0x' . bin2hex(substr($hash, 0, 4));
        } else {
            $selector = $functionSignatures[$method];
        }
        
        // Encode parameters
        $encodedParams = '';
        foreach ($params as $param) {
            if (is_string($param) && strpos($param, '0x') === 0) {
                // Already hex string (bytes32 or address)
                $encodedParams .= substr($param, 2); // Remove 0x
            } else {
                // uint256 - pad to 64 hex characters
                $hex = dechex(intval($param));
                $encodedParams .= str_pad($hex, 64, '0', STR_PAD_LEFT);
            }
        }
        
        return $selector . $encodedParams;
    }
    
    /**
     * Decode function result
     * 
     * @param string $method Function name
     * @param string $result Hex-encoded result
     * @return mixed Decoded result
     */
    private function decodeFunctionResult($method, $result) {
        if (empty($result) || $result === '0x') {
            return null;
        }
        
        // Remove 0x prefix
        $hex = substr($result, 2);
        
        // Decode based on return type
        switch ($method) {
            case 'checkIfVoted':
                // Returns bool (uint256: 0 or 1)
                return hexdec(substr($hex, 0, 64)) > 0;
                
            case 'getVoteCount':
                // Returns uint256
                return hexdec(substr($hex, 0, 64));
                
            case 'getVote':
                // Returns (bytes32, uint256)
                return [
                    '0x' . substr($hex, 0, 64), // voteHash
                    hexdec(substr($hex, 64, 64)) // timestamp
                ];
                
            default:
                return $result;
        }
    }
}
