// SPDX-License-Identifier: MIT
pragma solidity >=0.8.19 <0.9.0;

/**
 * @title VoteContract
 * @dev Simple smart contract to store vote hashes on Polygon blockchain
 * This contract stores a hash of each vote to ensure immutability
 */
contract VoteContract {
    // Events
    event VoteRecorded(
        uint256 indexed electionId,
        address indexed voter,
        bytes32 voteHash,
        uint256 timestamp
    );

    // Struct to store vote information
    struct Vote {
        bytes32 voteHash;
        uint256 timestamp;
        address voter;
    }

    // Mapping: electionId => voter address => Vote
    mapping(uint256 => mapping(address => Vote)) public votes;

    // Mapping: electionId => array of vote hashes
    mapping(uint256 => bytes32[]) public electionVotes;

    // Mapping to check if a voter has already voted in an election
    mapping(uint256 => mapping(address => bool)) public hasVoted;

    /**
     * @dev Record a vote hash on the blockchain
     * @param electionId The ID of the election
     * @param voteHash The hash of the vote data (generated from electionId, candidateId, voterId)
     */
    function recordVote(uint256 electionId, bytes32 voteHash) public {
        require(electionId > 0, "Invalid election ID");
        require(voteHash != bytes32(0), "Invalid vote hash");
        // Removed hasVoted check to allow same wallet to vote multiple times
        // Database enforces one vote per user_id, blockchain allows multiple votes per wallet
        // This allows different users to use the same wallet address

        // Record the vote (overwrites previous vote from same wallet for this election)
        votes[electionId][msg.sender] = Vote({
            voteHash: voteHash,
            timestamp: block.timestamp,
            voter: msg.sender
        });

        // Always add to election votes array (allows multiple votes from same wallet)
        electionVotes[electionId].push(voteHash);

        // Emit event
        emit VoteRecorded(electionId, msg.sender, voteHash, block.timestamp);
    }

    /**
     * @dev Get vote hash for a specific voter in an election
     * @param electionId The ID of the election
     * @param voter The address of the voter
     * @return voteHash The hash of the vote
     * @return timestamp When the vote was recorded
     */
    function getVote(uint256 electionId, address voter) 
        public 
        view 
        returns (bytes32 voteHash, uint256 timestamp) 
    {
        Vote memory vote = votes[electionId][voter];
        return (vote.voteHash, vote.timestamp);
    }

    /**
     * @dev Check if a voter has voted in an election
     * @param electionId The ID of the election
     * @param voter The address of the voter
     * @return True if the voter has voted
     */
    function checkIfVoted(uint256 electionId, address voter) 
        public 
        view 
        returns (bool) 
    {
        return hasVoted[electionId][voter];
    }

    /**
     * @dev Get total number of votes for an election
     * @param electionId The ID of the election
     * @return Total number of votes
     */
    function getVoteCount(uint256 electionId) public view returns (uint256) {
        return electionVotes[electionId].length;
    }

    /**
     * @dev Get all vote hashes for an election
     * @param electionId The ID of the election
     * @return Array of vote hashes
     */
    function getElectionVotes(uint256 electionId) 
        public 
        view 
        returns (bytes32[] memory) 
    {
        return electionVotes[electionId];
    }
}

