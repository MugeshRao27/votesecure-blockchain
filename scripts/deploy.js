const hre = require("hardhat");

async function main() {
  const VoteContract = await hre.ethers.getContractFactory("VoteContract");
  const vote = await VoteContract.deploy();
  await vote.waitForDeployment();

  console.log("VoteContract deployed to:", await vote.getAddress());
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

