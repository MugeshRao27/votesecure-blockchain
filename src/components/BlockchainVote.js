import React, { useState, useEffect } from 'react';
import {
  connectWallet,
  isMetaMaskInstalled,
  switchToPolygonMumbai,
  getCurrentAccount,
  recordVoteOnBlockchain,
  checkIfVoted,
  getVoteCount
} from '../utils/blockchain';
import './BlockchainVote.css';

const BlockchainVote = ({ electionId, candidateId, userId, onVoteSuccess, onVoteError }) => {
  const [walletConnected, setWalletConnected] = useState(false);
  const [walletAddress, setWalletAddress] = useState('');
  const [isRecording, setIsRecording] = useState(false);
  const [hasVoted, setHasVoted] = useState(false);
  const [voteCount, setVoteCount] = useState(0);
  const [error, setError] = useState('');

  // Check wallet connection on component mount
  useEffect(() => {
    checkWalletConnection();
    if (electionId && walletAddress) {
      checkVoteStatus();
      fetchVoteCount();
    }
  }, [electionId, walletAddress]);

  const checkWalletConnection = async () => {
    const account = await getCurrentAccount();
    if (account) {
      setWalletConnected(true);
      setWalletAddress(account);
    }
  };

  const checkVoteStatus = async () => {
    try {
      const voted = await checkIfVoted(electionId, walletAddress);
      setHasVoted(voted);
    } catch (err) {
      console.error('Error checking vote status:', err);
    }
  };

  const fetchVoteCount = async () => {
    try {
      const count = await getVoteCount(electionId);
      setVoteCount(count);
    } catch (err) {
      console.error('Error fetching vote count:', err);
    }
  };

  const handleConnectWallet = async () => {
    try {
      setError('');
      
      if (!isMetaMaskInstalled()) {
        setError('MetaMask is not installed. Please install MetaMask extension.');
        return;
      }

      // Switch to Polygon Mumbai
      await switchToPolygonMumbai();

      // Connect wallet
      const address = await connectWallet();
      setWalletConnected(true);
      setWalletAddress(address);

      // Check vote status after connecting
      if (electionId) {
        await checkVoteStatus();
        await fetchVoteCount();
      }
    } catch (err) {
      setError(err.message || 'Failed to connect wallet');
      console.error('Error connecting wallet:', err);
    }
  };

  const handleRecordVote = async () => {
    if (!electionId || !candidateId || !userId) {
      setError('Missing required vote information');
      return;
    }

    if (hasVoted) {
      setError('You have already voted in this election');
      return;
    }

    try {
      setIsRecording(true);
      setError('');

      const result = await recordVoteOnBlockchain(electionId, candidateId, userId);

      if (result.success) {
        setHasVoted(true);
        setVoteCount(prev => prev + 1);
        
        if (onVoteSuccess) {
          onVoteSuccess({
            transactionHash: result.transactionHash,
            voteHash: result.voteHash,
            blockNumber: result.blockNumber
          });
        }

        // Show success message
        alert(`Vote recorded successfully!\nTransaction: ${result.transactionHash}\nView on PolygonScan: https://mumbai.polygonscan.com/tx/${result.transactionHash}`);
      }
    } catch (err) {
      const errorMessage = err.message || 'Failed to record vote on blockchain';
      setError(errorMessage);
      
      if (onVoteError) {
        onVoteError(err);
      }
    } finally {
      setIsRecording(false);
    }
  };

  // Listen for account changes
  useEffect(() => {
    if (isMetaMaskInstalled()) {
      window.ethereum.on('accountsChanged', (accounts) => {
        if (accounts.length === 0) {
          setWalletConnected(false);
          setWalletAddress('');
        } else {
          setWalletAddress(accounts[0]);
          checkVoteStatus();
        }
      });

      window.ethereum.on('chainChanged', () => {
        window.location.reload();
      });

      return () => {
        window.ethereum.removeListener('accountsChanged', () => {});
        window.ethereum.removeListener('chainChanged', () => {});
      };
    }
  }, []);

  if (!isMetaMaskInstalled()) {
    return (
      <div className="blockchain-vote-container">
        <div className="blockchain-warning">
          <h3>MetaMask Required</h3>
          <p>Please install MetaMask to use blockchain voting.</p>
          <a 
            href="https://metamask.io/download/" 
            target="_blank" 
            rel="noopener noreferrer"
            className="install-metamask-btn"
          >
            Install MetaMask
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="blockchain-vote-container">
      <div className="blockchain-header">
        <h3>🔗 Blockchain Voting</h3>
        <p className="blockchain-subtitle">Your vote will be recorded on Polygon blockchain</p>
      </div>

      {!walletConnected ? (
        <div className="wallet-connect-section">
          <button 
            onClick={handleConnectWallet}
            className="connect-wallet-btn"
          >
            Connect MetaMask Wallet
          </button>
          <p className="wallet-hint">
            Connect your MetaMask wallet to record your vote on the blockchain
          </p>
        </div>
      ) : (
        <div className="wallet-connected-section">
          <div className="wallet-info">
            <span className="wallet-label">Connected:</span>
            <span className="wallet-address">
              {walletAddress.slice(0, 6)}...{walletAddress.slice(-4)}
            </span>
          </div>

          {hasVoted ? (
            <div className="vote-status voted">
              <p>✅ You have already voted in this election</p>
              <p className="vote-count">Total votes: {voteCount}</p>
            </div>
          ) : (
            <div className="vote-actions">
              <button
                onClick={handleRecordVote}
                disabled={isRecording || !electionId || !candidateId || !userId}
                className="record-vote-btn"
              >
                {isRecording ? 'Recording Vote...' : 'Record Vote on Blockchain'}
              </button>
              <p className="vote-count">Current votes: {voteCount}</p>
            </div>
          )}

          {error && (
            <div className="blockchain-error">
              <p>⚠️ {error}</p>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default BlockchainVote;

