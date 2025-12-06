import { ethers } from 'ethers';

// Contract ABI - This will be generated after you deploy the contract in Remix
// For now, this is the ABI for the VoteContract
export const VOTE_CONTRACT_ABI = [
  "function recordVote(uint256 electionId, bytes32 voteHash) public",
  "function getVote(uint256 electionId, address voter) public view returns (bytes32 voteHash, uint256 timestamp)",
  "function checkIfVoted(uint256 electionId, address voter) public view returns (bool)",
  "function getVoteCount(uint256 electionId) public view returns (uint256)",
  "function getElectionVotes(uint256 electionId) public view returns (bytes32[])",
  "event VoteRecorded(uint256 indexed electionId, address indexed voter, bytes32 voteHash, uint256 timestamp)"
];

// Contract address - Replace with your deployed contract address
const CONTRACT_ADDRESS = process.env.REACT_APP_CONTRACT_ADDRESS || '';

// Polygon Mumbai Testnet RPC
const POLYGON_RPC = process.env.REACT_APP_POLYGON_RPC || 'https://rpc-mumbai.maticvigil.com';
const CHAIN_ID = parseInt(process.env.REACT_APP_CHAIN_ID || '80001');

/**
 * Check if MetaMask is installed
 */
export const isMetaMaskInstalled = () => {
  return typeof window.ethereum !== 'undefined';
};

/**
 * Request account access from MetaMask
 */
export const connectWallet = async () => {
  if (!isMetaMaskInstalled()) {
    throw new Error('MetaMask is not installed. Please install MetaMask extension.');
  }

  try {
    // Request account access
    const accounts = await window.ethereum.request({
      method: 'eth_requestAccounts'
    });

    if (accounts.length === 0) {
      throw new Error('No accounts found. Please unlock MetaMask.');
    }

    return accounts[0];
  } catch (error) {
    if (error.code === 4001) {
      throw new Error('User rejected the connection request.');
    }
    throw error;
  }
};

/**
 * Switch to Polygon Mumbai network
 */
export const switchToPolygonMumbai = async () => {
  if (!isMetaMaskInstalled()) {
    throw new Error('MetaMask is not installed.');
  }

  try {
    await window.ethereum.request({
      method: 'wallet_switchEthereumChain',
      params: [{ chainId: `0x${CHAIN_ID.toString(16)}` }], // 0x13881 for Mumbai
    });
  } catch (switchError) {
    // This error code indicates that the chain has not been added to MetaMask
    if (switchError.code === 4902) {
      try {
        await window.ethereum.request({
          method: 'wallet_addEthereumChain',
          params: [
            {
              chainId: `0x${CHAIN_ID.toString(16)}`,
              chainName: 'Polygon Mumbai',
              nativeCurrency: {
                name: 'MATIC',
                symbol: 'MATIC',
                decimals: 18
              },
              rpcUrls: [POLYGON_RPC],
              blockExplorerUrls: ['https://mumbai.polygonscan.com']
            }
          ]
        });
      } catch (addError) {
        throw new Error('Failed to add Polygon Mumbai network to MetaMask.');
      }
    } else {
      throw switchError;
    }
  }
};

/**
 * Get the current connected account
 */
export const getCurrentAccount = async () => {
  if (!isMetaMaskInstalled()) {
    return null;
  }

  try {
    const accounts = await window.ethereum.request({
      method: 'eth_accounts'
    });
    return accounts.length > 0 ? accounts[0] : null;
  } catch (error) {
    console.error('Error getting current account:', error);
    return null;
  }
};

/**
 * Get provider (MetaMask)
 */
export const getProvider = () => {
  if (!isMetaMaskInstalled()) {
    throw new Error('MetaMask is not installed.');
  }
  return new ethers.BrowserProvider(window.ethereum);
};

/**
 * Get signer (connected wallet)
 */
export const getSigner = async () => {
  const provider = getProvider();
  return await provider.getSigner();
};

/**
 * Get contract instance
 */
export const getContract = async () => {
  if (!CONTRACT_ADDRESS) {
    throw new Error('Contract address not set. Please set REACT_APP_CONTRACT_ADDRESS in .env file.');
  }

  const signer = await getSigner();
  return new ethers.Contract(CONTRACT_ADDRESS, VOTE_CONTRACT_ABI, signer);
};

/**
 * Generate vote hash from election ID, candidate ID, and user ID
 * This creates a unique hash for each vote
 */
export const generateVoteHash = (electionId, candidateId, userId) => {
  // Combine all vote data into a single string
  const voteData = `${electionId}-${candidateId}-${userId}-${Date.now()}`;
  
  // Convert to bytes and hash using ethers
  const messageBytes = ethers.toUtf8Bytes(voteData);
  return ethers.keccak256(messageBytes);
};

/**
 * Record a vote on the blockchain
 */
export const recordVoteOnBlockchain = async (electionId, candidateId, userId) => {
  try {
    // Ensure we're on Polygon Mumbai
    await switchToPolygonMumbai();

    // Generate vote hash
    const voteHash = generateVoteHash(electionId, candidateId, userId);

    // Get contract instance
    const contract = await getContract();

    // Call the recordVote function
    const tx = await contract.recordVote(electionId, voteHash);

    // Wait for transaction to be mined
    const receipt = await tx.wait();

    return {
      success: true,
      transactionHash: receipt.hash,
      voteHash: voteHash,
      blockNumber: receipt.blockNumber
    };
  } catch (error) {
    console.error('Error recording vote on blockchain:', error);
    
    // Handle specific errors
    if (error.code === 'ACTION_REJECTED') {
      throw new Error('Transaction was rejected by user.');
    } else if (error.message?.includes('Already voted')) {
      throw new Error('You have already voted in this election.');
    } else if (error.message?.includes('insufficient funds')) {
      throw new Error('Insufficient MATIC for transaction. Please get test MATIC from faucet.');
    }
    
    throw new Error(`Failed to record vote on blockchain: ${error.message}`);
  }
};

/**
 * Check if a user has voted in an election
 */
export const checkIfVoted = async (electionId, voterAddress) => {
  try {
    const contract = await getContract();
    const hasVoted = await contract.checkIfVoted(electionId, voterAddress);
    return hasVoted;
  } catch (error) {
    console.error('Error checking vote status:', error);
    return false;
  }
};

/**
 * Get vote count for an election
 */
export const getVoteCount = async (electionId) => {
  try {
    const contract = await getContract();
    const count = await contract.getVoteCount(electionId);
    return parseInt(count.toString());
  } catch (error) {
    console.error('Error getting vote count:', error);
    return 0;
  }
};

/**
 * Get vote details for a specific voter
 */
export const getVoteDetails = async (electionId, voterAddress) => {
  try {
    const contract = await getContract();
    const [voteHash, timestamp] = await contract.getVote(electionId, voterAddress);
    return {
      voteHash: voteHash,
      timestamp: parseInt(timestamp.toString()) * 1000, // Convert to milliseconds
    };
  } catch (error) {
    console.error('Error getting vote details:', error);
    return null;
  }
};

