# GitHub Setup Instructions

## Your Project Location
**Current Location:** `D:\Final\final_votesecure`

This is on your **D drive**, NOT in Laragon (which is typically in `C:\laragon\www`).

---

## Step-by-Step: Push to GitHub

### Step 1: Configure Git (if not done)
Open PowerShell or Command Prompt in your project folder and run:

```bash
git config --global user.name "Your Name"
git config --global user.email "your.email@example.com"
```

### Step 2: Create GitHub Repository

1. **Go to GitHub**: https://github.com
2. **Sign in** to your account (or create one if you don't have it)
3. **Click the "+" icon** (top right) → Select **"New repository"**
4. **Repository name**: `votesecure-blockchain` (or any name you like)
5. **Description**: "Secure voting system with blockchain integration"
6. **Visibility**: Choose Public or Private
7. **IMPORTANT**: 
   - ❌ **DO NOT** check "Add a README file"
   - ❌ **DO NOT** check "Add .gitignore"
   - ❌ **DO NOT** check "Choose a license"
8. **Click "Create repository"**

### Step 3: Push Your Code

After creating the repository, GitHub will show you commands. But here's what to run:

```bash
# Make sure you're in the project folder
cd D:\Final\final_votesecure

# Add all files to Git
git add .

# Create your first commit
git commit -m "Initial commit: VoteSecure project with blockchain integration"

# Add GitHub as remote (replace YOUR_USERNAME with your actual GitHub username)
git remote add origin https://github.com/YOUR_USERNAME/votesecure-blockchain.git

# Rename branch to main (if needed)
git branch -M main

# Push to GitHub
git push -u origin main
```

**Note**: If you get an error about authentication, you may need to:
- Use a Personal Access Token instead of password
- Or set up SSH keys

### Step 4: Verify

1. Go to your GitHub repository page
2. You should see all your files there
3. The blue "U" symbols in Cursor will change to normal colors after you commit

---

## Troubleshooting

### If you get "remote origin already exists":
```bash
git remote remove origin
git remote add origin https://github.com/YOUR_USERNAME/votesecure-blockchain.git
```

### If you need to update your GitHub username in the URL:
Replace `YOUR_USERNAME` with your actual GitHub username in the commands above.

### If authentication fails:
1. Go to GitHub → Settings → Developer settings → Personal access tokens
2. Generate a new token with `repo` permissions
3. Use the token as your password when pushing

---

## Next Steps

After pushing to GitHub, continue with:
1. ✅ Install MetaMask
2. ✅ Deploy Smart Contract on Remix
3. ✅ Connect React Frontend

See `BLOCKCHAIN_SETUP_GUIDE.md` for the complete guide.

