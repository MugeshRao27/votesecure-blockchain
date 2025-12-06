<?php
require_once 'config.php';

class BlockchainHelper {
    private $pdo;
    private $web3Provider;
    private $contractAddress;
    private $contractAbi;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Polygon Mumbai Testnet configuration
        // Load from environment variables or config file
        $this->web3Provider = getenv('POLYGON_RPC_URL') ?: 'https://rpc-mumbai.maticvigil.com';
        $this->contractAddress = getenv('POLYGON_CONTRACT_ADDRESS') ?: '0x123...'; // Replace with your deployed contract address
        $this->contractAbi = '[...]'; // Your smart contract ABI (get from Remix after deployment)
    }
    
    /**
     * Record a vote on the blockchain
     */
    public function recordVote($userId, $electionId, $candidateId) {
        try {
            // Get user's blockchain address
            $stmt = $this->pdo->prepare("SELECT blockchain_address FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || empty($user['blockchain_address'])) {
                throw new Exception('User does not have a valid blockchain address');
            }
            
            // In a real implementation, you would interact with the blockchain here
            // This is a placeholder for the actual blockchain interaction
            $transactionHash = $this->callSmartContract(
                'castVote', 
                [$electionId, $candidateId, $user['blockchain_address']]
            );
            
            if (!$transactionHash) {
                throw new Exception('Failed to record vote on blockchain');
            }
            
            // Return transaction details
            return [
                'success' => true,
                'transaction_hash' => $transactionHash,
                'blockchain_address' => $user['blockchain_address']
            ];
            
        } catch (Exception $e) {
            error_log("Blockchain error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify if a vote was recorded on the blockchain
     */
    public function verifyVote($userId, $electionId) {
        try {
            // In a real implementation, you would query the blockchain here
            // This is a placeholder for the actual blockchain interaction
            $voteRecorded = $this->callSmartContract(
                'hasVoted', 
                [$electionId, $userId]
            );
            
            return [
                'success' => true,
                'vote_recorded' => (bool)$voteRecorded
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
     * Get election results from the blockchain
     */
    public function getElectionResults($electionId) {
        try {
            // In a real implementation, you would query the blockchain here
            // This is a placeholder for the actual blockchain interaction
            $results = $this->callSmartContract(
                'getElectionResults', 
                [$electionId]
            );
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            error_log("Blockchain results error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Helper method to interact with the smart contract
     */
    private function callSmartContract($method, $params = []) {
        // In a real implementation, you would use a Web3 PHP library like web3.php
        // This is a simplified example
        
        // Example using cURL to interact with a local Ethereum node
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'eth_call',
            'params' => [
                [
                    'to' => $this->contractAddress,
                    'data' => $this->encodeFunctionCall($method, $params)
                ],
                'latest'
            ],
            'id' => 1
        ];
        
        $ch = curl_init($this->web3Provider);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Blockchain connection error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Blockchain RPC error: HTTP ' . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            throw new Exception('Blockchain error: ' . $result['error']['message']);
        }
        
        return $result['result'];
    }
    
    /**
     * Helper method to encode function calls for the smart contract
     */
    private function encodeFunctionCall($method, $params) {
        // In a real implementation, you would use a proper ABI encoder
        // This is a simplified example
        $methodSignature = $method . '(' . implode(',', array_fill(0, count($params), 'uint256')) . ')';
        $methodId = substr(hash('sha256', $methodSignature), 0, 8);
        
        $encodedParams = '';
        foreach ($params as $param) {
            $encodedParams .= str_pad(dechex($param), 64, '0', STR_PAD_LEFT);
        }
        
        return '0x' . $methodId . $encodedParams;
    }
}
