# Blockchain Integration Guide - Step by Step

## 📍 Your Project Location
**Current Location:** `D:\Final\final_votesecure`

This is NOT in Laragon (which is typically in `C:\laragon`). Your project is on the D drive.

---

## STEP 1: Set Up Git and GitHub

### 1.1 Check if Git is configured
```bash
git config --global user.name "Your Name"
git config --global user.email "your.email@example.com"
```

### 1.2 Create a GitHub Repository
1. Go to https://github.com
2. Click the "+" icon → "New repository"
3. Name it: `votesecure-blockchain` (or any name you prefer)
4. **DO NOT** initialize with README, .gitignore, or license (we already have files)
5. Click "Create repository"

### 1.3 Push Your Code to GitHub
Run these commands in your terminal (in the project folder):

```bash
# Add all files
git add .

# Create first commit
git commit -m "Initial commit: VoteSecure project with blockchain integration"

# Add GitHub remote (replace YOUR_USERNAME with your GitHub username)
git remote add origin https://github.com/YOUR_USERNAME/votesecure-blockchain.git

# Push to GitHub
git branch -M main
git push -u origin main
```

---

## STEP 2: Install MetaMask and Connect to Polygon Testnet

### 2.1 Install MetaMask
1. Go to https://metamask.io
2. Click "Download" → Choose your browser (Chrome/Edge/Firefox)
3. Install the extension
4. Create a new wallet (or import existing)
5. **IMPORTANT:** Save your seed phrase securely!

### 2.2 Add Polygon Mumbai Testnet
1. Open MetaMask
2. Click the network dropdown (top left, shows "Ethereum Mainnet")
3. Click "Add Network" → "Add a network manually"
4. Enter these details:
   - **Network Name:** Polygon Mumbai
   - **RPC URL:** https://rpc-mumbai.maticvigil.com
   - **Chain ID:** 80001
   - **Currency Symbol:** MATIC
   - **Block Explorer URL:** https://mumbai.polygonscan.com
5. Click "Save"

### 2.3 Get Test MATIC (Free)
1. Go to https://faucet.polygon.technology
2. Select "Mumbai" network
3. Enter your MetaMask wallet address
4. Request test MATIC (you'll need this to deploy contracts)

---

## STEP 3: Create Smart Contract in Remix IDE

### 3.1 Open Remix IDE
1. Go to https://remix.ethereum.org
2. Create a new file: `VoteContract.sol` in the `contracts` folder

### 3.2 Smart Contract Code
Copy the contract code from `contracts/VoteContract.sol` (will be created below)

### 3.3 Compile
1. Go to "Solidity Compiler" tab (left sidebar)
2. Select compiler version: `0.8.19` or higher
3. Click "Compile VoteContract.sol"

### 3.4 Deploy to Polygon Mumbai
1. Go to "Deploy & Run Transactions" tab
2. Select environment: "Injected Provider - MetaMask"
3. Select network: "Polygon Mumbai" (should show in MetaMask popup)
4. Select contract: "VoteContract"
5. Click "Deploy"
6. **IMPORTANT:** Copy the contract address after deployment!

---

## STEP 4: Connect React Frontend to Smart Contract

### 4.1 Install ethers.js
Already added to package.json - run: `npm install`

### 4.2 Create Environment File
Create `.env` in project root:
```
REACT_APP_POLYGON_RPC=https://rpc-mumbai.maticvigil.com
REACT_APP_CONTRACT_ADDRESS=YOUR_DEPLOYED_CONTRACT_ADDRESS
REACT_APP_CHAIN_ID=80001
```

### 4.3 Use the Blockchain Components
- `src/utils/blockchain.js` - Blockchain utilities
- `src/components/BlockchainVote.js` - Vote component with blockchain

---

## STEP 5: Update Backend (PHP)

The `blockchain-helper.php` will be updated to work with Polygon testnet.

---

## 📝 Notes
- Always use Polygon Mumbai for testing
- Never share your private keys or seed phrase
- Test MATIC is free from faucets
- Contract address is needed in frontend after deployment

