// Example: How to integrate BlockchainVote component in your existing voting page
// This is just an example - adapt it to your actual component structure

import React, { useState } from 'react';
import BlockchainVote from './components/BlockchainVote';

const VotingPage = () => {
  const [electionId, setElectionId] = useState(1);
  const [candidateId, setCandidateId] = useState(null);
  const [userId, setUserId] = useState(1); // Get from your auth system
  const [voteRecorded, setVoteRecorded] = useState(false);

  const handleVoteSuccess = (result) => {
    console.log('Blockchain vote recorded:', result);
    setVoteRecorded(true);
    
    // You can also save this to your database
    // Example: Save transaction hash to your votes table
    /*
    fetch('/api/votes/save-blockchain-tx', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        electionId: electionId,
        candidateId: candidateId,
        userId: userId,
        transactionHash: result.transactionHash,
        voteHash: result.voteHash,
        blockNumber: result.blockNumber
      })
    });
    */
  };

  const handleVoteError = (error) => {
    console.error('Blockchain vote error:', error);
    alert('Failed to record vote on blockchain: ' + error.message);
  };

  return (
    <div className="voting-page">
      <h1>Cast Your Vote</h1>
      
      {/* Your existing voting UI */}
      <div className="candidate-selection">
        {/* Candidate selection buttons */}
        <button onClick={() => setCandidateId(1)}>Candidate 1</button>
        <button onClick={() => setCandidateId(2)}>Candidate 2</button>
      </div>

      {/* Blockchain Voting Component */}
      {candidateId && (
        <BlockchainVote
          electionId={electionId}
          candidateId={candidateId}
          userId={userId}
          onVoteSuccess={handleVoteSuccess}
          onVoteError={handleVoteError}
        />
      )}

      {/* Show success message */}
      {voteRecorded && (
        <div className="success-message">
          ✅ Your vote has been recorded on the blockchain!
        </div>
      )}
    </div>
  );
};

export default VotingPage;

