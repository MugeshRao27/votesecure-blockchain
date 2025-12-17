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

// Network defaults to Polygon Amoy
const POLYGON_RPC =
  process.env.REACT_APP_POLYGON_RPC ||
  'https://polygon-amoy-bor-rpc.publicnode.com';
const CHAIN_ID = parseInt(process.env.REACT_APP_CHAIN_ID || '80002');
const CHAIN_NAME = process.env.REACT_APP_CHAIN_NAME || 'Polygon Amoy';
const NATIVE_CURRENCY = {
  name: process.env.REACT_APP_CHAIN_CURRENCY_NAME || 'POL',
  symbol: process.env.REACT_APP_CHAIN_CURRENCY_SYMBOL || 'POL',
  decimals: 18,
};
const BLOCK_EXPLORER_URL =
  process.env.REACT_APP_BLOCK_EXPLORER ||
  'https://amoy.polygonscan.com';

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
 * Switch to the configured network (env-driven; defaults to Amoy)
 */
export const switchToConfiguredNetwork = async () => {
  if (!isMetaMaskInstalled()) {
    throw new Error('MetaMask is not installed.');
  }

  try {
    await window.ethereum.request({
      method: 'wallet_switchEthereumChain',
      params: [{ chainId: `0x${CHAIN_ID.toString(16)}` }],
    });
  } catch (switchError) {
    // If the chain is not added, add it
    if (switchError.code === 4902) {
      try {
        await window.ethereum.request({
          method: 'wallet_addEthereumChain',
          params: [
            {
              chainId: `0x${CHAIN_ID.toString(16)}`,
              chainName: CHAIN_NAME,
              nativeCurrency: NATIVE_CURRENCY,
              rpcUrls: [POLYGON_RPC],
              blockExplorerUrls: BLOCK_EXPLORER_URL ? [BLOCK_EXPLORER_URL] : [],
            },
          ],
        });
      } catch (addError) {
        throw new Error(
          `Failed to add network ${CHAIN_NAME} to MetaMask. Please add it manually. RPC: ${POLYGON_RPC}, Chain ID: ${CHAIN_ID}`
        );
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
 * Get contract instance (with signer for transactions)
 */
export const getContract = async () => {
  if (!CONTRACT_ADDRESS) {
    throw new Error('Contract address not set. Please set REACT_APP_CONTRACT_ADDRESS in .env file.');
  }

  const signer = await getSigner();
  return new ethers.Contract(CONTRACT_ADDRESS, VOTE_CONTRACT_ABI, signer);
};

/**
 * Get read-only contract instance (for view functions, doesn't trigger MetaMask)
 */
export const getReadOnlyContract = async () => {
  if (!CONTRACT_ADDRESS) {
    throw new Error('Contract address not set. Please set REACT_APP_CONTRACT_ADDRESS in .env file.');
  }

  const provider = getProvider();
  return new ethers.Contract(CONTRACT_ADDRESS, VOTE_CONTRACT_ABI, provider);
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
    // Check if MetaMask is installed
    if (!isMetaMaskInstalled()) {
      throw new Error('MetaMask is not installed. Please install MetaMask extension.');
    }

    // Ensure we're on the correct network (env-driven, defaults to Amoy)
    await switchToConfiguredNetwork();

    // Check if wallet is connected, if not connect it (this will trigger MetaMask popup)
    const currentAccount = await getCurrentAccount();
    if (!currentAccount) {
      // Connect wallet - this will show MetaMask popup for connection
      await connectWallet();
      // Get the account again after connecting
      const newAccount = await getCurrentAccount();
      if (!newAccount) {
        throw new Error('Failed to get wallet address after connection.');
      }
    }

    // Generate vote hash
    const voteHash = generateVoteHash(electionId, candidateId, userId);

    // Validate contract address
    if (!CONTRACT_ADDRESS || CONTRACT_ADDRESS === '') {
      throw new Error('Contract address not configured. Please set REACT_APP_CONTRACT_ADDRESS in .env file.');
    }

    // Validate inputs
    if (!electionId || electionId <= 0) {
      throw new Error('Invalid election ID');
    }
    if (!voteHash || voteHash === '0x' || voteHash.length !== 66) {
      throw new Error(`Invalid vote hash: ${voteHash}`);
    }
    
    // Verify contract exists at address
    const provider = getProvider();
    const code = await provider.getCode(CONTRACT_ADDRESS);
    if (!code || code === '0x') {
      throw new Error(`❌ No contract found at address ${CONTRACT_ADDRESS}.\n\nPlease verify:\n1. The contract address is correct\n2. The contract is deployed on the correct network (Amoy testnet)\n3. You copied the address correctly from Remix`);
    }
    console.log('✅ Contract code found at address');
    
    // Get contract instance and signer
    const contract = await getContract();
    const signer = await getSigner();
    const walletAddress = await signer.getAddress();
    
    console.log('📝 Transaction details:', {
      contractAddress: CONTRACT_ADDRESS,
      walletAddress,
      electionId: electionId.toString(),
      voteHash,
    });
    
    // Warn if using old contract address
    if (CONTRACT_ADDRESS.toLowerCase() === '0xa9ade7c396b4fc5d340d7a10d34a84a3906471cb') {
      console.warn('⚠️ WARNING: You are using the OLD contract address!');
      console.warn('⚠️ Please update REACT_APP_CONTRACT_ADDRESS in .env file with the NEW contract address from Remix');
      console.warn('⚠️ Then restart your React app (stop with Ctrl+C and run npm start again)');
    }
    
    // Use contract method directly - this handles encoding automatically
    // Use populateTransaction to get the transaction data, then send with manual gas
    // This ensures MetaMask popup shows even if gas estimation would fail
    let tx;
    try {
      // Populate the transaction (this prepares it)
      const populatedTx = await contract.recordVote.populateTransaction(electionId, voteHash);
      
      console.log('📦 Populated transaction:', {
        to: populatedTx.to,
        data: populatedTx.data?.substring(0, 20) + '...',
        dataLength: populatedTx.data?.length
      });
      
      // Validate populated transaction has data
      if (!populatedTx.data || populatedTx.data === '0x') {
        throw new Error('Failed to populate transaction - no data generated');
      }
      
      // Send with manual gas limit to force MetaMask popup
      tx = await signer.sendTransaction({
        to: populatedTx.to,
        data: populatedTx.data,
        gasLimit: 300000
      });
      
      console.log('📤 Transaction sent:', tx.hash);
    } catch (populateError) {
      // If populateTransaction fails, try direct contract call
      console.warn('PopulateTransaction failed, trying direct call:', populateError.message);
      tx = await contract.recordVote(electionId, voteHash, { gasLimit: 300000 });
      console.log('📤 Transaction sent (direct):', tx.hash);
    }

    // Wait for transaction to be mined
    // Note: Contract no longer has hasVoted check, so transactions should succeed
    let receipt;
    try {
      receipt = await tx.wait();
      
      // Check if transaction reverted (status 0 = reverted, 1 = success)
      if (receipt && receipt.status === 0) {
        // Transaction was confirmed in MetaMask but reverted on-chain
        throw new Error(`Transaction reverted on-chain.\n\n⚠️ Most likely cause: The contract address (${CONTRACT_ADDRESS}) still points to the OLD contract.\n\n✅ Please verify:\n1. You copied the ENTIRE updated VoteContract.sol code to Remix\n2. You COMPILED the contract in Remix (compile button)\n3. You DEPLOYED the NEW contract (not the old one)\n4. You copied the NEW contract address\n5. You updated REACT_APP_CONTRACT_ADDRESS in your .env file with the NEW address\n6. You restarted your React app after updating .env\n\nIf you're using the old contract address, it still has the hasVoted check which causes reverts.`);
      }
    } catch (waitError) {
      // If wait() throws, check if it's because transaction reverted
      if (waitError.receipt && waitError.receipt.status === 0) {
        const revertReason = waitError.reason || waitError.message || 'Unknown reason';
        throw new Error(`Transaction reverted: ${revertReason}. Please verify the contract is deployed correctly.`);
      }
      // Re-throw other wait errors
      throw waitError;
    }

    return {
      success: true,
      transactionHash: receipt.hash,
      voteHash: voteHash,
      blockNumber: receipt.blockNumber
    };
  } catch (error) {
    console.error('Error recording vote on blockchain:', error);
    
    // Handle user rejection
    if (error.code === 'ACTION_REJECTED' || error.code === 4001) {
      throw new Error('Transaction was rejected. Please click "Confirm" or "Approve" in MetaMask to complete your vote. If you clicked "Cancel", your vote will not be saved.');
    }
    
    // Handle transaction revert (status 0 in receipt) - transaction was mined but reverted
    if (error.receipt && error.receipt.status === 0) {
      throw new Error('Transaction was confirmed but reverted on-chain. Please try again.');
    }
    
    // Handle CALL_EXCEPTION with receipt (transaction reverted on-chain)
    if (error.code === 'CALL_EXCEPTION' && error.receipt && error.receipt.status === 0) {
      const revertReason = error.reason || error.data || 'Unknown revert reason';
      throw new Error(`Transaction reverted: ${revertReason}\n\n⚠️ Most likely cause: Contract address (${CONTRACT_ADDRESS}) points to OLD contract.\n\n✅ Solution:\n1. Deploy the NEW contract in Remix (with hasVoted check removed)\n2. Copy the NEW contract address\n3. Update REACT_APP_CONTRACT_ADDRESS in .env file\n4. Restart your React app`);
    }
    
    // Handle other CALL_EXCEPTION errors
    if (error.code === 'CALL_EXCEPTION') {
      const reason = error.reason || error.data || error.message || 'Unknown reason';
      throw new Error(`Transaction failed: ${reason}. Please check the contract address and deployment.`);
    }
    
    // Handle insufficient funds
    if (error.message?.includes('insufficient funds')) {
      throw new Error('Insufficient MATIC for transaction. Please get test MATIC from faucet.');
    }
    
    // Handle other errors
    throw new Error(`Failed to record vote on blockchain: ${error.message || error.reason || 'Unknown error'}`);
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