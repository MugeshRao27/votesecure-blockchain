import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import FaceVerification from "./FaceVerification";
import "./UserDashboard.css";
import { tokenManager } from "../utils/api";

const API_BASE = "http://localhost/final_votesecure/backend/api";

const authFetch = (url, options = {}) => {
  const token = tokenManager.getToken();
  const headers = { ...(options.headers || {}) };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  return fetch(url, { ...options, headers });
};

// Helper function to format datetime for display (12-hour format)
const formatDateTime = (datetimeString) => {
  if (!datetimeString) return 'N/A';
  
  try {
    // Handle MySQL datetime format: "YYYY-MM-DD HH:MM:SS"
    let date;
    if (typeof datetimeString === 'string') {
      // Parse MySQL datetime format directly to avoid timezone issues
      if (datetimeString.includes(' ')) {
        const [datePart, timePart] = datetimeString.split(' ');
        const [year, month, day] = datePart.split('-').map(Number);
        const [hours, minutes, seconds] = timePart.split(':').map(Number);
        
        // Create date object in local timezone (not UTC)
        date = new Date(year, month - 1, day, hours || 0, minutes || 0, seconds || 0);
      } else {
        date = new Date(datetimeString);
      }
    } else {
      date = new Date(datetimeString);
    }
    
    if (isNaN(date.getTime())) {
      console.warn('Failed to parse datetime:', datetimeString);
      return datetimeString; // Return original if parsing fails
    }
    
    // Format: "DD/MM/YYYY, HH:MM:SS AM/PM"
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    
    // Convert to 12-hour format
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // the hour '0' should be '12'
    const hours12 = String(hours).padStart(2, '0');
    
    return `${day}/${month}/${year}, ${hours12}:${minutes}:${seconds} ${ampm}`;
  } catch (e) {
    console.error('Error formatting datetime:', e, datetimeString);
    return datetimeString;
  }
};

const UserDashboard = () => {
  const navigate = useNavigate();
  const [user, setUser] = useState({});
  const [elections, setElections] = useState([]);
  const [candidates, setCandidates] = useState([]);
  const [selectedElection, setSelectedElection] = useState(null);
  const [loading, setLoading] = useState(false);
  const [hasVoted, setHasVoted] = useState(false);
  const [showFaceVerification, setShowFaceVerification] = useState(false);
  const [pendingCandidateId, setPendingCandidateId] = useState(null);
  const [activeView, setActiveView] = useState("elections"); // "elections", "history", "results"
  const [electionResults, setElectionResults] = useState(null);
  const [voteHistory, setVoteHistory] = useState([]);
  const [loadingResults, setLoadingResults] = useState(false);
  const [loadingHistory, setLoadingHistory] = useState(false);

  // Load user from localStorage
  useEffect(() => {
    const storedUser = JSON.parse(localStorage.getItem("user") || "{}");
    const isLoggedIn = localStorage.getItem("isLoggedIn");
    const role = localStorage.getItem("role");

    if (!isLoggedIn || role?.toLowerCase() !== "voter") {
      navigate("/auth");
      return;
    }
    
    // Password change check removed - face verification is sufficient
    setUser(storedUser);
  }, [navigate]);

  // Fetch authorized elections (mapping-based)
  useEffect(() => {
    const fetchElections = async () => {
      if (!user.id) return;
      
      try {
        console.log("🔍 Fetching authorized elections for voter ID:", user.id);
        const res = await authFetch(`${API_BASE}/get-authorized-elections.php?user_id=${user.id}`);
        const text = await res.text();
        console.log("📥 Raw response:", text);
        
        let data;
        try {
          data = JSON.parse(text);
        } catch (parseErr) {
          console.error("❌ Failed to parse response:", parseErr);
          console.error("Response was:", text);
          return;
        }
        
        console.log("📊 Parsed data:", data);
        
        if (data.success) { 
          // API returns elections with fields: authorized, has_voted
          const electionsList = data.elections || [];
          console.log("✅ Elections received:", electionsList.length, "elections");
          setElections(electionsList);
          
          // Log debug info if available
          if (data.debug) {
            console.log("🔍 Debug info:", data.debug);
            console.log("📋 Election IDs found:", data.debug.election_ids_found);
            console.log("📊 Tables checked:", data.debug.tables_checked);
          }
          
          if (electionsList.length === 0) {
            console.warn("⚠️ No elections found for this voter");
            if (data.debug) {
              console.warn("Debug info:", data.debug);
            }
          }
          
          if (data.message) {
            console.log("ℹ️ API message:", data.message);
            // Show message but don't use alert for authorization messages
            if (data.message.includes("not authorized")) {
              // This will be shown in the UI
            } else {
              setTimeout(() => alert(data.message), 500);
            }
          }
        } else {
          console.error("❌ API returned error:", data.message);
          console.error("Full error response:", data);
          alert(data.message || "Failed to fetch elections");
        }
      } catch (err) {
        console.error("❌ Error fetching elections:", err);
        console.error("Error details:", err.message, err.stack);
        alert("Error connecting to server. Please try again.");
      }
    };
    
    // Also fetch debug info in development
    const fetchDebugInfo = async () => {
      if (!user.id) return;
      
      try {
        const debugRes = await authFetch(`${API_BASE}/debug-voter-elections.php?user_id=${user.id}`);
        const debugData = await debugRes.json();
        if (debugData.success) {
          console.log("🔍 Debug Info:", debugData.debug);
        }
      } catch (err) {
        console.error("Could not fetch debug info:", err);
      }
    };
    
    fetchElections();
    fetchDebugInfo(); // Fetch debug info to help troubleshoot
  }, [user.id]);

  // Check if election is completed
  const isElectionCompleted = (election) => {
    if (!election || !election.end_date) return false;
    
    const now = new Date();
    const endDate = new Date(election.end_date);
    
    // If end_date is just a date (YYYY-MM-DD), set time to end of day
    if (election.end_date.length === 10) {
      endDate.setHours(23, 59, 59, 999);
    }
    
    return now > endDate;
  };

  // Check if election is active (can vote)
  const isElectionActive = (election) => {
    if (!election || !election.start_date || !election.end_date) return false;
    
    const now = new Date();
    const startDate = new Date(election.start_date);
    const endDate = new Date(election.end_date);
    
    // If dates are just dates (YYYY-MM-DD), set appropriate times
    if (election.start_date.length === 10) {
      startDate.setHours(0, 0, 0, 0);
    }
    if (election.end_date.length === 10) {
      endDate.setHours(23, 59, 59, 999);
    }
    
    // Active: current time >= start_date AND current time <= end_date
    return now >= startDate && now <= endDate;
  };

  const getElectionStatusLabel = (election) => {
    if (!election) return "UPCOMING";
    if (isElectionActive(election)) return "ACTIVE";
    if (isElectionCompleted(election)) return "COMPLETED";
    return "UPCOMING";
  };

  // Fetch candidates for selected election
  const fetchCandidates = async (electionId) => {
    setLoading(true);
    try {
      // Check election status
      const selectedElectionData = elections.find(e => e.id === electionId);
      if (selectedElectionData) {
        // Check if election is completed
        if (isElectionCompleted(selectedElectionData)) {
          setHasVoted(false); // Set to false so we show completed message instead
          setCandidates([]);
          setLoading(false);
          return;
        }
        
        // Check if election is not active (upcoming)
        if (!isElectionActive(selectedElectionData)) {
          setHasVoted(false); // Set to false so we show upcoming message instead
          setCandidates([]);
          setLoading(false);
          return;
        }
      }

      // Check if user has already voted (from mapping in loaded elections)
      const fromList = elections.find(e => e.id === electionId);
      if (fromList && fromList.has_voted) {
        setHasVoted(true);
        setLoading(false);
        return;
      }

      const res = await authFetch(`${API_BASE}/get-candidates.php`);
      const data = await res.json();
      if (data.success) {
        const filtered = (data.candidates || []).filter(
          (c) => c.election_id === electionId
        );
        setCandidates(filtered);
        setHasVoted(false);
      }
    } catch (err) {
      console.error("Error fetching candidates:", err);
    } finally {
      setLoading(false);
    }
  };

  // Handle Vote - Show face verification first
  const handleVote = async (candidateId) => {
    if (!user.id || !selectedElection) {
      alert("Invalid user or election information.");
      return;
    }

    // Check if election is completed
    if (isElectionCompleted(selectedElection)) {
      alert("⚠️ This election has ended. Voting is no longer allowed.");
      return;
    }

    // Check if election is not active (upcoming)
    if (!isElectionActive(selectedElection)) {
      alert("⚠️ This election has not started yet. Voting will be available when the election becomes active.");
      return;
    }

    // Note: Face image check is now handled by the backend (cast-vote.php)
    // The backend will provide detailed error messages if face image is missing
    // We'll proceed to face verification, and the backend will catch any issues

    // Store candidate ID and show face verification
    setPendingCandidateId(candidateId);
    setShowFaceVerification(true);
  };

  // Handle face verification success
  const handleFaceVerified = async (verificationPayload) => {
    setShowFaceVerification(false);
    
    if (!pendingCandidateId) {
      return;
    }

    // Verify face with backend
    setLoading(true);
    try {
      // Prepare payload (support legacy string param for backward compatibility)
      let capturedFaceImage = "";
      let distance = null;
      if (typeof verificationPayload === "string") {
        capturedFaceImage = verificationPayload;
      } else if (verificationPayload && typeof verificationPayload === "object") {
        capturedFaceImage = verificationPayload.imageBase64 || "";
        distance = typeof verificationPayload.distance === "number" ? verificationPayload.distance : null;
      }

      if (!capturedFaceImage) {
        alert("Face capture missing. Please try again.");
        setLoading(false);
        return;
      }

      // Verify face with backend
      const verifyResponse = await authFetch(`${API_BASE}/verify-face.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          voter_id: user.id,
          captured_face: capturedFaceImage,
          distance,
          election_id: selectedElection?.id || null,
        }),
      });

      const verifyData = await verifyResponse.json();
      
      if (!verifyData.success) {
        alert(`⚠️ Face verification failed: ${verifyData.message}`);
        setLoading(false);
        return;
      }

      // If face verified, proceed with voting (mapping-aware endpoint)
      const response = await authFetch(`${API_BASE}/cast-vote.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          user_id: user.id,
          candidate_id: pendingCandidateId,
          election_id: selectedElection.id,
        }),
      });

      const text = await response.text();
      
      // Check if error is related to missing face image
      if (!response.ok) {
        try {
          const errorData = JSON.parse(text);
          if (errorData.error_code === 'FACE_IMAGE_MISSING' || errorData.error_code === 'FACE_IMAGE_FILE_MISSING') {
            alert(`⚠️ ${errorData.message}\n\nPlease contact your administrator to add your face image to your account.`);
            setLoading(false);
            return;
          }
        } catch (e) {
          // Not JSON, continue with normal error handling
        }
      }
      console.log("Vote response:", text);
      
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        alert("Invalid response from server!");
        setLoading(false);
        return;
      }

      if (data.success) {
        alert("✅ Match found! Vote submitted successfully. Thank you for voting.");
        setHasVoted(true);
        setSelectedElection(null);
        setCandidates([]);
        setPendingCandidateId(null);
        
        // Refresh elections list
        const res = await authFetch(`${API_BASE}/get-authorized-elections.php?user_id=${user.id}`);
        const electionData = await res.json();
        if (electionData.success) {
          setElections(electionData.elections || []);
          if (electionData.message) {
            setTimeout(() => alert(electionData.message), 500);
          }
        }
      } else {
        // Show detailed error message from backend
        const errorMsg = data.message || "Error submitting vote.";
        const errorDetails = data.error ? `\n\nTechnical details: ${data.error}` : '';
        const debugInfo = data.debug ? `\n\nDebug info: ${JSON.stringify(data.debug, null, 2)}` : '';
        alert(`⚠️ ${errorMsg}${errorDetails}${debugInfo}`);
      }
    } catch (err) {
      console.error("Error submitting vote:", err);
      console.error("Error details:", err.message, err.stack);
      alert(`Error submitting vote: ${err.message || "Please try again."}`);
    } finally {
      setLoading(false);
      setPendingCandidateId(null);
    }
  };

  // Handle face verification cancel
  const handleFaceVerificationCancel = () => {
    setShowFaceVerification(false);
    setPendingCandidateId(null);
  };

  const handleLogout = () => {
    tokenManager.removeToken();
    navigate("/auth");
  };

  const handleBackToElections = () => {
    setSelectedElection(null);
    setCandidates([]);
    setHasVoted(false);
    setElectionResults(null);
  };

  // Fetch vote history
  const fetchVoteHistory = async () => {
    if (!user.id) return;
    setLoadingHistory(true);
    try {
      const res = await authFetch(`${API_BASE}/get-vote-history.php?user_id=${user.id}`);
      const data = await res.json();
      if (data.success) {
        setVoteHistory(data.votes || []);
      } else {
        alert(data.message || "Failed to fetch vote history");
      }
    } catch (err) {
      console.error("Error fetching vote history:", err);
      alert("Error loading vote history");
    } finally {
      setLoadingHistory(false);
    }
  };

  // Fetch election results
  const fetchElectionResults = async (electionId) => {
    setLoadingResults(true);
    try {
      const res = await authFetch(`${API_BASE}/get-election-results.php?election_id=${electionId}`);
      const data = await res.json();
      if (data.success) {
        setElectionResults(data);
        setActiveView("results");
      } else {
        alert(data.message || "Failed to fetch results");
      }
    } catch (err) {
      console.error("Error fetching results:", err);
      alert("Error loading results");
    } finally {
      setLoadingResults(false);
    }
  };

  return (
    <div className="user-dashboard">
      {/* Sidebar */}
      <aside className="user-sidebar">
        <div>
          <div className="user-info">
            <div className="avatar">{user.name?.[0]?.toUpperCase() || "U"}</div>
            <div>
              <strong>{user.name || "Voter"}</strong>
              <p>{user.email}</p>
            </div>
          </div>
          <button
            className={`menu-btn ${activeView === "elections" && !selectedElection ? "active" : ""}`}
            onClick={() => {
              setActiveView("elections");
              setSelectedElection(null);
              setCandidates([]);
              setElectionResults(null);
            }}
          >
            🗳️ Elections
          </button>
          <button
            className={`menu-btn ${activeView === "history" ? "active" : ""}`}
            onClick={() => {
              setActiveView("history");
              setSelectedElection(null);
              setCandidates([]);
              setElectionResults(null);
              fetchVoteHistory();
            }}
          >
            📊 My Votes
          </button>
        </div>
        <button className="logout-btn" onClick={handleLogout}>
          🚪 Logout
        </button>
      </aside>

      {/* Main Content */}
      <main className="user-main">
        {activeView === "history" ? (
          <div className="vote-history-section">
            <div className="section-header">
              <h2>My Vote History</h2>
              <p>View all your past votes</p>
            </div>
            {loadingHistory ? (
              <div className="loading-state">
                <div className="spinner"></div>
                <p>Loading vote history...</p>
              </div>
            ) : voteHistory.length === 0 ? (
              <div className="empty-state">
                <div className="empty-icon">📝</div>
                <p>No vote history found.</p>
                <p className="empty-subtitle">You haven't voted in any elections yet.</p>
              </div>
            ) : (
              <div className="history-grid">
                {voteHistory.map((vote) => (
                  <div key={vote.vote_id} className="history-card">
                    <div className="history-header">
                      <h3>{vote.election_title}</h3>
                      <span className={`status-badge ${vote.election_status?.toLowerCase()}`}>
                        {vote.election_status}
                      </span>
                    </div>
                    <p className="history-description">{vote.election_description}</p>
                    <div className="history-candidate">
                      <div className="candidate-mini-info">
                        {vote.candidate_photo && (
                          <img
                            src={`http://localhost/final_votesecure/backend/${vote.candidate_photo}`}
                            alt={vote.candidate_name}
                            className="candidate-mini-photo"
                            onError={(e) => (e.target.src = "https://via.placeholder.com/50")}
                          />
                        )}
                        <div>
                          <strong>{vote.candidate_name}</strong>
                          <p>{vote.candidate_position}</p>
                          {vote.candidate_party && <p className="party-tag">{vote.candidate_party}</p>}
                        </div>
                      </div>
                    </div>
                    <div className="history-footer">
                      <span className="vote-date">Voted on: {formatDateTime(vote.voted_at)}</span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : activeView === "results" && electionResults ? (
          <div className="results-section">
            <button className="back-btn" onClick={() => {
              setActiveView("elections");
              setElectionResults(null);
            }}>
              ← Back to Elections
            </button>
            <div className="section-header">
              <h2>{electionResults.election.title}</h2>
              <p>{electionResults.election.description}</p>
            </div>
            
            <div className="results-stats">
              <div className="stat-card">
                <div className="stat-icon">📊</div>
                <div className="stat-value">{electionResults.statistics.total_votes}</div>
                <div className="stat-label">Total Votes</div>
              </div>
              <div className="stat-card">
                <div className="stat-icon">👥</div>
                <div className="stat-value">{electionResults.statistics.total_authorized_voters}</div>
                <div className="stat-label">Authorized Voters</div>
              </div>
              <div className="stat-card">
                <div className="stat-icon">📈</div>
                <div className="stat-value">{electionResults.statistics.turnout_percentage}%</div>
                <div className="stat-label">Voter Turnout</div>
              </div>
            </div>

            {electionResults.winners.length > 0 && (
              <div className="winner-banner">
                <div className="winner-icon">🏆</div>
                <div>
                  <h3>{electionResults.statistics.is_tie ? "Tie!" : "Winner"}</h3>
                  <p>
                    {electionResults.winners.map(w => w.name).join(", ")} 
                    {electionResults.statistics.is_tie ? " are tied" : " won"} with {electionResults.statistics.winner_vote_count} vote{electionResults.statistics.winner_vote_count !== 1 ? 's' : ''}
                  </p>
                </div>
              </div>
            )}

            <div className="results-candidates">
              <h3>Results by Candidate</h3>
              <div className="results-list">
                {electionResults.candidates.map((candidate, index) => {
                  const percentage = electionResults.statistics.total_votes > 0 
                    ? ((candidate.vote_count / electionResults.statistics.total_votes) * 100).toFixed(1)
                    : 0;
                  const isWinner = electionResults.winners.some(w => w.id === candidate.id);
                  
                  return (
                    <div key={candidate.id} className={`result-item ${isWinner ? 'winner' : ''}`}>
                      <div className="result-rank">#{index + 1}</div>
                      <div className="result-candidate-info">
                        {candidate.photo && (
                          <img
                            src={`http://localhost/final_votesecure/backend/${candidate.photo}`}
                            alt={candidate.name}
                            className="result-candidate-photo"
                            onError={(e) => (e.target.src = "https://via.placeholder.com/60")}
                          />
                        )}
                        <div className="result-candidate-details">
                          <h4>{candidate.name}</h4>
                          <p>{candidate.position}</p>
                          {candidate.party && <span className="party-badge">{candidate.party}</span>}
                        </div>
                      </div>
                      <div className="result-votes">
                        <div className="vote-count">{candidate.vote_count} votes</div>
                        <div className="vote-percentage">{percentage}%</div>
                        <div className="vote-bar-container">
                          <div 
                            className="vote-bar" 
                            style={{ width: `${percentage}%` }}
                          ></div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        ) : !selectedElection ? (
          <div className="elections-section">
            <div className="section-header">
              <h2>Available Elections</h2>
              <p>Select an election to cast your vote</p>
            </div>
            {elections.length === 0 ? (
              <div className="empty-state">
                <div className="empty-icon">🗳️</div>
                <p>No active elections right now.</p>
                <p className="empty-subtitle">
                  Possible reasons:
                  <br />• You may not be authorized to vote (contact admin)
                  <br />• No elections are currently active
                  <br />• Election dates haven't started yet
                </p>
                <p style={{ marginTop: "10px", fontSize: "12px", color: "#666" }}>
                  💡 Check browser console (F12) for detailed information
                </p>
              </div>
            ) : (
              <div className="election-grid">
                {elections.map((election) => {
                  const isCompleted = isElectionCompleted(election);
                  const isActive = isElectionActive(election);
                  return (
                    <div
                      key={election.id}
                      className="election-card"
                      onClick={() => {
                        setSelectedElection(election);
                        fetchCandidates(election.id);
                      }}
                    >
                      <div className="election-header">
                        <h3>{election.title}</h3>
                        {(() => {
                          const derivedStatus = getElectionStatusLabel(election);
                          return (
                            <span className={`status-badge ${derivedStatus.toLowerCase()}`}>
                              {derivedStatus}
                            </span>
                          );
                        })()}
                      </div>
                      <p className="election-description">{election.description}</p>
                      <div className="election-dates">
                        <div className="date-item">
                          <span className="date-label">Start:</span>
                          <span className="date-value">
                            {formatDateTime(election.start_date)}
                          </span>
                        </div>
                        <div className="date-item">
                          <span className="date-label">End:</span>
                          <span className="date-value">
                            {formatDateTime(election.end_date)}
                          </span>
                        </div>
                      </div>
                      <button 
                        className="view-btn"
                        onClick={(e) => {
                          e.stopPropagation();
                          if (isCompleted) {
                            fetchElectionResults(election.id);
                          } else if (isActive && !election.has_voted) {
                            setSelectedElection(election);
                            fetchCandidates(election.id);
                          }
                        }}
                      >
                        {election.has_voted ? "Already Voted" :
                          (isCompleted ? "View Results →" : 
                           !isActive ? "Coming Soon →" : 
                           "View Candidates →")}
                      </button>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        ) : (
          <div className="candidates-section">
            <button className="back-btn" onClick={handleBackToElections}>
              ← Back to Elections
            </button>
            
            <div className="section-header">
              <h2>{selectedElection.title}</h2>
              <p>{selectedElection.description}</p>
            </div>

            {isElectionCompleted(selectedElection) ? (
              <div className="voted-message">
                <div className="voted-icon">🔒</div>
                <h3>This election has ended</h3>
                <p>Voting is no longer available for this election. The election ended on {formatDateTime(selectedElection.end_date)}.</p>
                <button className="btn-primary" onClick={handleBackToElections}>
                  View Other Elections
                </button>
              </div>
            ) : !isElectionActive(selectedElection) ? (
              <div className="voted-message">
                <div className="voted-icon">⏰</div>
                <h3>This election has not started yet</h3>
                <p>Voting is not available yet. This election will start on {formatDateTime(selectedElection.start_date)}.</p>
                <button className="btn-primary" onClick={handleBackToElections}>
                  View Other Elections
                </button>
              </div>
            ) : hasVoted ? (
              <div className="voted-message">
                <div className="voted-icon">✅</div>
                <h3>You have already voted in this election!</h3>
                <p>Thank you for participating. Your vote has been recorded.</p>
                <button className="btn-primary" onClick={handleBackToElections}>
                  View Other Elections
                </button>
              </div>
            ) : loading && candidates.length === 0 ? (
              <div className="loading-state">
                <div className="spinner"></div>
                <p>Loading candidates...</p>
              </div>
            ) : candidates.length === 0 ? (
              <div className="empty-state">
                <p>No candidates available for this election.</p>
              </div>
            ) : (
              <>
                <div className="vote-instructions">
                  <p>📌 Please review all candidates and select your choice. This action cannot be undone.</p>
                </div>
                <div className="candidate-grid">
                  {candidates.map((c) => (
                    <div key={c.id} className="candidate-card">
                      <div className="candidate-image-container">
                        <img
                          src={`http://localhost/final_votesecure/backend/${c.photo}`}
                          alt={c.name}
                          className="candidate-image"
                          onError={(e) => (e.target.src = "https://via.placeholder.com/200")}
                        />
                      </div>
                      <div className="candidate-info">
                        <h3>{c.name}</h3>
                        <p className="candidate-position">
                          <strong>{c.position}</strong>
                        </p>
                        {c.party && (
                          <p className="candidate-party">Party: {c.party}</p>
                        )}
                        {c.bio && (
                          <p className="candidate-bio">{c.bio}</p>
                        )}
                      </div>
                      <button
                        className="vote-btn"
                        onClick={() => handleVote(c.id)}
                        disabled={loading || isElectionCompleted(selectedElection) || !isElectionActive(selectedElection) || elections.find(e => e.id === selectedElection.id)?.has_voted}
                      >
                        {loading ? "Submitting..." : 
                         isElectionCompleted(selectedElection) ? "Election Ended" : 
                         !isElectionActive(selectedElection) ? "Not Started" :
                         (elections.find(e => e.id === selectedElection.id)?.has_voted ? "Already Voted" : "Vote for " + c.name.split(" ")[0])}
                      </button>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        )}
      </main>

      {/* Face Verification Modal */}
      {showFaceVerification && (
        <FaceVerification
          onVerify={handleFaceVerified}
          onCancel={handleFaceVerificationCancel}
          user={user}
        />
      )}
    </div>
  );
};

export default UserDashboard;

