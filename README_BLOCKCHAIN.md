# VoteSecure - Blockchain Integration Guide

## 📍 Project Location
**Your project is located at:** `D:\Final\final_votesecure`

This is on your **D drive**, NOT in Laragon (which would be in `C:\laragon\www`).

---

## 🚀 Quick Start Guide

### Step 1: Set Up GitHub
Follow the instructions in `GITHUB_SETUP.md` to push your code to GitHub.

### Step 2: Install Dependencies
```bash
npm install
```
This will install `ethers.js` which is needed for blockchain interaction.

### Step 3: Install MetaMask
1. Go to https://metamask.io/download/
2. Install the browser extension
3. Create a wallet (save your seed phrase!)
4. Add Polygon Mumbai Testnet:
   - Network Name: **Polygon Mumbai**
   - RPC URL: `https://rpc-mumbai.maticvigil.com`
   - Chain ID: `80001`
   - Currency Symbol: `MATIC`
   - Block Explorer: `https://mumbai.polygonscan.com`

### Step 4: Get Test MATIC
1. Go to https://faucet.polygon.technology
2. Select "Mumbai" network
3. Enter your MetaMask wallet address
4. Request test MATIC (free!)

### Step 5: Deploy Smart Contract
1. Go to https://remix.ethereum.org
2. Create new file: `contracts/VoteContract.sol`
3. Copy the code from `contracts/VoteContract.sol` in this project
4. Compile (Solidity Compiler tab, version 0.8.19+)
5. Deploy:
   - Environment: "Injected Provider - MetaMask"
   - Network: Polygon Mumbai
   - Click "Deploy"
6. **Copy the contract address** after deployment!

### Step 6: Configure Environment
1. Create `.env` file in project root:
```env
REACT_APP_POLYGON_RPC=https://rpc-mumbai.maticvigil.com
REACT_APP_CONTRACT_ADDRESS=YOUR_DEPLOYED_CONTRACT_ADDRESS_HERE
REACT_APP_CHAIN_ID=80001
```
2. Replace `YOUR_DEPLOYED_CONTRACT_ADDRESS_HERE` with the address from Step 5

### Step 7: Use Blockchain Component
Import and use the `BlockchainVote` component in your voting pages:

```javascript
import BlockchainVote from './components/BlockchainVote';

// In your component:
<BlockchainVote
  electionId={electionId}
  candidateId={candidateId}
  userId={userId}
  onVoteSuccess={(result) => {
    console.log('Vote recorded:', result.transactionHash);
  }}
  onVoteError={(error) => {
    console.error('Vote error:', error);
  }}
/>
```

---

## 📁 Files Created

### Smart Contract
- `contracts/VoteContract.sol` - Solidity smart contract for Remix IDE

### Frontend (React)
- `src/utils/blockchain.js` - Blockchain utilities (MetaMask connection, contract interaction)
- `src/components/BlockchainVote.js` - React component for blockchain voting
- `src/components/BlockchainVote.css` - Styles for blockchain component

### Backend (PHP)
- `backend/blockchain-helper.php` - Updated to use Polygon Mumbai testnet

### Configuration
- `.env.example` - Environment variables template
- `.gitignore` - Updated to exclude sensitive files

### Documentation
- `BLOCKCHAIN_SETUP_GUIDE.md` - Complete setup guide
- `GITHUB_SETUP.md` - GitHub setup instructions
- `README_BLOCKCHAIN.md` - This file

---

## 🔧 How It Works

1. **User connects MetaMask** → Wallet connects to Polygon Mumbai
2. **User casts vote** → Frontend generates a vote hash
3. **Hash recorded on blockchain** → Smart contract stores the hash
4. **Transaction confirmed** → Vote is immutable on blockchain
5. **Verification** → Anyone can verify votes on PolygonScan

---

## 📝 Smart Contract Functions

- `recordVote(electionId, voteHash)` - Record a vote hash
- `checkIfVoted(electionId, voter)` - Check if voter has voted
- `getVoteCount(electionId)` - Get total votes for an election
- `getVote(electionId, voter)` - Get vote details for a voter

---

## 🔗 Useful Links

- **Remix IDE**: https://remix.ethereum.org
- **MetaMask**: https://metamask.io
- **Polygon Mumbai Faucet**: https://faucet.polygon.technology
- **PolygonScan Mumbai**: https://mumbai.polygonscan.com
- **ethers.js Docs**: https://docs.ethers.org

---

## ⚠️ Important Notes

1. **Always use Polygon Mumbai for testing** (not mainnet)
2. **Never share your private keys or seed phrase**
3. **Test MATIC is free** - get it from faucets
4. **Contract address is needed** in `.env` after deployment
5. **Each vote costs a small amount of MATIC** (gas fees)

---

## 🐛 Troubleshooting

### MetaMask not connecting?
- Make sure MetaMask is installed and unlocked
- Check that you're on Polygon Mumbai network
- Refresh the page

### Transaction failing?
- Check you have enough MATIC for gas
- Verify contract address is correct in `.env`
- Make sure you haven't already voted

### Contract not found?
- Verify contract address in `.env` matches deployed address
- Check you're on Polygon Mumbai network
- Ensure contract was deployed successfully

---

## 📞 Need Help?

Check the detailed guides:
- `BLOCKCHAIN_SETUP_GUIDE.md` - Step-by-step setup
- `GITHUB_SETUP.md` - GitHub instructions

