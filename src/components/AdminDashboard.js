import React, { useState, useEffect, useCallback } from "react";
import { useNavigate, Outlet } from "react-router-dom";
import "./Dashboard.css";
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

// Helper function to extract time from datetime string
const extractTimeFromDateTime = (datetimeString) => {
  if (!datetimeString) return '00:00';
  if (typeof datetimeString !== 'string') return '00:00';
  
  // Handle MySQL datetime format: "YYYY-MM-DD HH:MM:SS"
  if (datetimeString.includes(' ')) {
    const timePart = datetimeString.split(' ')[1];
    if (timePart) {
      return timePart.substring(0, 5); // Get HH:MM
    }
  }
  // Handle ISO format or other formats
  try {
    const date = new Date(datetimeString);
    if (!isNaN(date.getTime())) {
      const hours = String(date.getHours()).padStart(2, '0');
      const minutes = String(date.getMinutes()).padStart(2, '0');
      return `${hours}:${minutes}`;
    }
  } catch (e) {
    // Fallback
  }
  return '00:00';
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

// Helper function to calculate election status based on current time
const calculateElectionStatus = (election) => {
  if (!election || !election.start_date || !election.end_date) {
    return 'upcoming';
  }
  
  const now = new Date();
  let startDate, endDate;
  
  // Parse start date
  if (typeof election.start_date === 'string') {
    if (election.start_date.includes(' ')) {
      const [datePart, timePart] = election.start_date.split(' ');
      const [year, month, day] = datePart.split('-').map(Number);
      const [hours, minutes, seconds] = timePart.split(':').map(Number);
      startDate = new Date(year, month - 1, day, hours || 0, minutes || 0, seconds || 0);
    } else {
      startDate = new Date(election.start_date);
      startDate.setHours(0, 0, 0, 0);
    }
  } else {
    startDate = new Date(election.start_date);
  }
  
  // Parse end date
  if (typeof election.end_date === 'string') {
    if (election.end_date.includes(' ')) {
      const [datePart, timePart] = election.end_date.split(' ');
      const [year, month, day] = datePart.split('-').map(Number);
      const [hours, minutes, seconds] = timePart.split(':').map(Number);
      endDate = new Date(year, month - 1, day, hours || 0, minutes || 0, seconds || 0);
    } else {
      endDate = new Date(election.end_date);
      endDate.setHours(23, 59, 59, 999);
    }
  } else {
    endDate = new Date(election.end_date);
  }
  
  // Determine status
  if (now >= startDate && now <= endDate) {
    return 'active';
  } else if (now < startDate) {
    return 'upcoming';
  } else {
    return 'completed';
  }
};

// Admin sidebar navigation component
const AdminSidebar = ({ activeTab, onNavigate }) => {
  return (
    <div className="admin-sidebar">
      <div className="admin-sidebar-header">
        <h3>Admin Panel</h3>
      </div>
      <nav className="admin-nav">
        <ul>
          <li className={activeTab === 'dashboard' ? 'active' : ''}>
            <button onClick={() => onNavigate('dashboard')}>
              <i className="fas fa-tachometer-alt"></i> Dashboard
            </button>
          </li>
          <li className={activeTab === 'voters' ? 'active' : ''}>
            <button onClick={() => onNavigate('voters')}>
              <i className="fas fa-users"></i> Voters
            </button>
          </li>
          <li className={activeTab === 'register-voter' ? 'active' : ''}>
            <button onClick={() => onNavigate('register-voter')}>
              <i className="fas fa-user-plus"></i> Register Voter
            </button>
          </li>
          <li className={activeTab === 'elections' ? 'active' : ''}>
            <button onClick={() => onNavigate('elections')}>
              <i className="fas fa-vote-yea"></i> Elections
            </button>
          </li>
          <li className={activeTab === 'candidates' ? 'active' : ''}>
            <button onClick={() => onNavigate('candidates')}>
              <i className="fas fa-user-tie"></i> Candidates
            </button>
          </li>
          <li className={activeTab === 'settings' ? 'active' : ''}>
            <button onClick={() => onNavigate('settings')}>
              <i className="fas fa-cog"></i> Settings
            </button>
          </li>
        </ul>
      </nav>
    </div>
  );
};

const AdminDashboard = () => {
  const navigate = useNavigate();
  const location = window.location.pathname;
  const [activeTab, setActiveTab] = useState('dashboard');
  const [elections, setElections] = useState([]);
  const [candidates, setCandidates] = useState([]);
  const [voters, setVoters] = useState([]);
  const [user, setUser] = useState({});
  const [loading, setLoading] = useState(false);
  const [settings, setSettings] = useState({
    auto_authorize_enabled: '1',
    auto_authorize_face_required: '1'
  });
  const [analytics, setAnalytics] = useState(null);
  const [loadingAnalytics, setLoadingAnalytics] = useState(false);
  const [selectedElectionForAnalytics, setSelectedElectionForAnalytics] = useState(null);

  // Set active tab based on URL
  useEffect(() => {
    const path = location.split('/').pop();
    if (path === 'admin' || path === 'dashboard') {
      setActiveTab('overview'); // Use 'overview' as internal state
    } else if (path === 'register-voter') {
      setActiveTab('register-voter');
    } else {
      // For any unrecognized path, default to overview
      // Other tabs are state-based, not route-based
      setActiveTab('overview');
    }
  }, [location]);

  const handleNavigate = (tab) => {
    // Prevent navigation if not authenticated
    const isLoggedIn = localStorage.getItem("isLoggedIn");
    const role = localStorage.getItem("role");
    const hasToken = tokenManager.hasToken();
    
    if (!isLoggedIn || !hasToken || role?.toLowerCase() !== "admin") {
      navigate("/auth");
      return;
    }
    
    // Update active tab state
    setActiveTab(tab);
    
    // Only navigate for routes that actually exist in App.js
    // Routes that exist: 'dashboard' and 'register-voter'
    // All other tabs (overview, voters, elections, etc.) are state-based only
    if (tab === 'register-voter') {
      navigate('/admin/register-voter', { replace: false });
    } else {
      // For all other tabs (overview/dashboard, voters, elections, etc.)
      // Keep URL at /admin/dashboard and just change tab state
      // This prevents navigation to non-existent routes
      navigate('/admin/dashboard', { replace: false });
    }
  };

  // Modal visibility state
  const [showElectionModal, setShowElectionModal] = useState(false);
  const [showCandidateModal, setShowCandidateModal] = useState(false);
  const [showEditElectionModal, setShowEditElectionModal] = useState(false);
  const [showEditCandidateModal, setShowEditCandidateModal] = useState(false);
  const [showEditVoterModal, setShowEditVoterModal] = useState(false);
  const [selectedElection, setSelectedElection] = useState(null);
  const [selectedCandidate, setSelectedCandidate] = useState(null);
  const [selectedVoter, setSelectedVoter] = useState(null);
  // Import whitelist modal
  const [showImportWhitelistModal, setShowImportWhitelistModal] = useState(false);
  const [importing, setImporting] = useState(false);
  // Voter list upload modal
  const [showVoterListModal, setShowVoterListModal] = useState(false);
  const [uploadingVoterList, setUploadingVoterList] = useState(false);
  const [importFile, setImportFile] = useState(null);
  const [importDefaults, setImportDefaults] = useState({
    organization_id: "",
    election_id: "",
    active: "1",
  });
  const [importSummary, setImportSummary] = useState(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [eRes, cRes, vRes] = await Promise.all([
        authFetch(`${API_BASE}/get-elections.php`),
        authFetch(`${API_BASE}/get-candidates.php`),
        authFetch(`${API_BASE}/get-voters.php`),
      ]);

      const eData = await eRes.json();
      const cData = await cRes.json();
      const vData = await vRes.json();

      if (eData.success) setElections(eData.elections || []);
      if (cData.success) setCandidates(cData.candidates || []);
      if (vData.success) setVoters(vData.voters || []);
    } catch (err) {
      console.error("Error loading data:", err);
      alert("Error loading data. Please refresh.");
    } finally {
      setLoading(false);
    }
  }, []);

  const loadSettings = useCallback(async () => {
    try {
      const res = await authFetch(`${API_BASE}/get-settings.php`);
      const data = await res.json();
      if (data.success) {
        setSettings(data.settings);
      }
    } catch (err) {
      console.error("Error loading settings:", err);
    }
  }, []);

  const loadAnalytics = useCallback(async (electionId = null) => {
    setLoadingAnalytics(true);
    try {
      const url = electionId 
        ? `${API_BASE}/get-analytics.php?election_id=${electionId}`
        : `${API_BASE}/get-analytics.php`;
      const res = await authFetch(url);
      const data = await res.json();
      if (data.success) {
        setAnalytics(data.analytics);
      } else {
        alert(data.message || "Failed to load analytics");
      }
    } catch (err) {
      console.error("Error loading analytics:", err);
      alert("Error loading analytics");
    } finally {
      setLoadingAnalytics(false);
    }
  }, []);

  // Authentication check and initial data load - only run once on mount
  useEffect(() => {
    const storedUser = JSON.parse(localStorage.getItem("user") || "{}");
    const isLoggedIn = localStorage.getItem("isLoggedIn");
    const role = localStorage.getItem("role");
    const hasToken = tokenManager.hasToken();

    if (!isLoggedIn || !hasToken || role?.toLowerCase() !== "admin") {
      navigate("/auth");
      return;
    }
    
    // Set user and load data once on initial mount
    setUser(storedUser);
    loadData();
    loadSettings();
    loadAnalytics();
    
    // Auto-refresh election data every 30 seconds to update status
    const refreshInterval = setInterval(() => {
      loadData();
    }, 30000); // 30 seconds
    
    // Cleanup interval on unmount
    return () => clearInterval(refreshInterval);
  }, [loadData, loadSettings, loadAnalytics, navigate]); // Include dependencies

  const handleLogout = () => {
    tokenManager.removeToken();
    navigate("/auth");
  };

  // Handle form submissions
  const handleAddElection = async (e) => {
    e.preventDefault();
    const form = e.target;
    const storedUser = JSON.parse(localStorage.getItem("user") || "{}");

    const payload = {
      title: form.title.value,
      description: form.description.value,
      startDate: form.start_date.value,
      endDate: form.end_date.value,
      startTime: form.start_time.value || "00:00",
      endTime: form.end_time.value || "23:59",
      userId: storedUser?.id || 1,
    };

    try {
      // Step 1: Create election
      const res = await authFetch(`${API_BASE}/create-election.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await res.json();
      if (data.success) {
        const electionId = data.election.id;
        
        // Step 2: Upload voter list if file is provided
        const voterListFile = form.voter_list?.files?.[0];
        if (voterListFile) {
          try {
            const formData = new FormData();
            formData.append("voter_list", voterListFile);
            formData.append("election_id", electionId);
            formData.append("replace_existing", "false");

            const uploadRes = await authFetch(`${API_BASE}/admin/process-voter-list.php`, {
              method: "POST",
              body: formData,
            });

            const uploadData = await uploadRes.json();
            if (uploadData.success) {
              const stats = uploadData.stats || {};
              alert(`✅ Election created successfully!\n\nVoter list processed:\n- Total: ${stats.total}\n- Inserted: ${stats.inserted}\n- Updated: ${stats.updated}`);
            } else {
              alert(`✅ Election created successfully!\n\n⚠️ Warning: Voter list upload failed: ${uploadData.message}`);
            }
          } catch (uploadErr) {
            console.error("Error uploading voter list:", uploadErr);
            alert(`✅ Election created successfully!\n\n⚠️ Warning: Could not upload voter list. You can upload it later.`);
          }
        } else {
          alert("✅ Election created successfully!\n\n⚠️ Note: No voter list uploaded. You can upload it later.");
        }
        
        setShowElectionModal(false);
        form.reset();
        loadData();
      } else {
        alert(data.message || "Failed to create election");
      }
    } catch (err) {
      console.error("Error creating election:", err);
      alert("Error while creating election");
    }
  };

  const handleAddCandidate = async (e) => {
    e.preventDefault();
    const form = e.target;

    const formData = new FormData();
    formData.append("name", form.name.value);
    formData.append("position", form.position.value);
    formData.append("party", form.party.value || "");
    formData.append("bio", form.bio?.value || "");
    formData.append("electionId", form.election_id.value);

    if (form.photo?.files && form.photo.files[0]) {
      formData.append("photo", form.photo.files[0]);
    }

    try {
      const res = await authFetch(`${API_BASE}/add-candidate.php`, {
        method: "POST",
        body: formData,
      });

      const data = await res.json();
      if (data.success) {
        setShowCandidateModal(false);
        form.reset();
        loadData();
        alert("✅ Candidate added successfully!");
      } else {
        alert(data.message || "Failed to add candidate");
      }
    } catch (err) {
      console.error("Error creating candidate:", err);
      alert("Error while creating candidate");
    }
  };

  const downloadVoterListTemplate = () => {
    // Create sample CSV content
    const csvContent = `name,email,phone
John Doe,john.doe@example.com,1234567890
Jane Smith,jane.smith@example.com,0987654321
Bob Johnson,bob.johnson@example.com,5551234567
Alice Williams,alice.williams@example.com,
Charlie Brown,charlie.brown@example.com,5559876543`;

    // Create blob and download
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'voter_list_template.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const handleUploadVoterList = async (e) => {
    e.preventDefault();
    const form = e.target;
    const voterListFile = form.voter_list?.files?.[0];
    
    if (!voterListFile) {
      alert("Please select a file to upload");
      return;
    }
    
    if (!selectedElection) {
      alert("No election selected");
      return;
    }
    
    setUploadingVoterList(true);
    
    try {
      const formData = new FormData();
      formData.append("voter_list", voterListFile);
      formData.append("election_id", selectedElection.id);
      formData.append("replace_existing", "true");

      const res = await authFetch(`${API_BASE}/admin/process-voter-list.php`, {
        method: "POST",
        body: formData,
      });

      const data = await res.json();
      if (data.success) {
        const stats = data.stats || {};
        setShowVoterListModal(false);
        setSelectedElection(null);
        form.reset();
        alert(`✅ Voter list uploaded successfully!\n\n- Total: ${stats.total}\n- Inserted: ${stats.inserted}\n- Updated: ${stats.updated}`);
        if (data.errors && data.errors.length > 0) {
          console.warn("Some rows had errors:", data.errors);
        }
      } else {
        alert(data.message || "Failed to upload voter list");
      }
    } catch (err) {
      console.error("Error uploading voter list:", err);
      alert("Error while uploading voter list");
    } finally {
      setUploadingVoterList(false);
    }
  };

  const handleEditElection = async (e) => {
    e.preventDefault();
    const form = e.target;

    const payload = {
      id: selectedElection.id,
      title: form.title.value,
      description: form.description.value,
      startDate: form.start_date.value,
      endDate: form.end_date.value,
      startTime: form.start_time.value || "00:00",
      endTime: form.end_time.value || "23:59",
    };

    try {
      const res = await authFetch(`${API_BASE}/update-election.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await res.json();
      if (data.success) {
        setShowEditElectionModal(false);
        setSelectedElection(null);
        form.reset();
        loadData();
        alert("✅ Election updated successfully!");
      } else {
        alert(data.message || "Failed to update election");
      }
    } catch (err) {
      console.error("Error updating election:", err);
      alert("Error while updating election");
    }
  };

  const handleEditCandidate = async (e) => {
    e.preventDefault();
    const form = e.target;

    const formData = new FormData();
    formData.append("id", selectedCandidate.id);
    formData.append("name", form.name.value);
    formData.append("position", form.position.value);
    formData.append("party", form.party.value || "");
    formData.append("bio", form.bio?.value || "");
    formData.append("electionId", form.election_id.value);

    if (form.photo?.files && form.photo.files[0]) {
      formData.append("photo", form.photo.files[0]);
    }

    try {
      const res = await authFetch(`${API_BASE}/update-candidate.php`, {
        method: "POST",
        body: formData,
      });

      const data = await res.json();
      if (data.success) {
        setShowEditCandidateModal(false);
        setSelectedCandidate(null);
        form.reset();
        loadData();
        alert("✅ Candidate updated successfully!");
      } else {
        alert(data.message || "Failed to update candidate");
      }
    } catch (err) {
      console.error("Error updating candidate:", err);
      alert("Error while updating candidate");
    }
  };

  const handleDeleteElection = async (id) => {
    if (!window.confirm("Are you sure you want to delete this election?")) return;

    try {
      const res = await authFetch(`${API_BASE}/delete-election.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });

      const data = await res.json();
      if (data.success) {
        alert("✅ Election deleted successfully!");
        loadData();
      } else {
        alert(data.message || "Failed to delete election");
      }
    } catch (err) {
      console.error("Error deleting election:", err);
      alert("Error while deleting election");
    }
  };

  const handleDeleteCandidate = async (id) => {
    if (!window.confirm("Are you sure you want to delete this candidate?")) return;

    try {
      const res = await authFetch(`${API_BASE}/delete-candidate.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });

      const data = await res.json();
      if (data.success) {
        alert("✅ Candidate deleted successfully!");
        loadData();
      } else {
        alert(data.message || "Failed to delete candidate");
      }
    } catch (err) {
      console.error("Error deleting candidate:", err);
      alert("Error while deleting candidate");
    }
  };

  const handleToggleAuthorization = async (voterId, authorized) => {
    const action = authorized ? "authorize" : "revoke authorization from";
    if (!window.confirm(`Are you sure you want to ${action} this voter?`)) return;

    try {
      const res = await authFetch(`${API_BASE}/toggle-voter-authorization.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          voter_id: voterId,
          authorized: authorized,
        }),
      });

      const data = await res.json();
      if (data.success) {
        alert(`✅ ${data.message}`);
        loadData();
      } else {
        alert(data.message || "Failed to update authorization");
      }
    } catch (err) {
      console.error("Error updating authorization:", err);
      alert("Error while updating authorization");
    }
  };

  const handleDeleteVoter = async (id) => {
    if (!window.confirm("Are you sure you want to delete this voter? This action cannot be undone.")) return;

    try {
      const res = await authFetch(`${API_BASE}/admin/delete-voter.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ voter_id: id }),
      });

      const data = await res.json();
      if (data.success) {
        alert("✅ Voter deleted successfully!");
        loadData();
      } else {
        alert(data.message || "Failed to delete voter");
      }
    } catch (err) {
      console.error("Error deleting voter:", err);
      alert("Error while deleting voter");
    }
  };

  const handleEditVoter = async (e) => {
    e.preventDefault();
    const form = e.target;

    const payload = {
      voter_id: selectedVoter.id,
      name: form.name.value,
      email: form.email.value,
      phone: form.phone.value || null,
      date_of_birth: form.date_of_birth.value || null,
    };

    try {
      const res = await authFetch(`${API_BASE}/admin/update-voter.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await res.json();
      if (data.success) {
        setShowEditVoterModal(false);
        setSelectedVoter(null);
        form.reset();
        loadData();
        alert("✅ Voter updated successfully!");
      } else {
        alert(data.message || "Failed to update voter");
      }
    } catch (err) {
      console.error("Error updating voter:", err);
      alert("Error while updating voter");
    }
  };

  const handleDeleteAllVoters = async () => {
    // Double confirmation for safety
    const firstConfirm = window.confirm(
      "⚠️ WARNING: This will permanently delete ALL voters from the database!\n\n" +
      "This action will delete:\n" +
      "• All voter accounts\n" +
      "• All votes cast by voters\n" +
      "• All face images\n" +
      "• All authorization records\n" +
      "• All related data\n\n" +
      "This action CANNOT be undone!\n\n" +
      "Are you absolutely sure you want to proceed?"
    );
    
    if (!firstConfirm) return;
    
    const secondConfirm = window.confirm(
      "⚠️ FINAL CONFIRMATION\n\n" +
      "You are about to delete ALL voters. This is your last chance to cancel.\n\n" +
      "Type 'DELETE ALL' in the next prompt to confirm."
    );
    
    if (!secondConfirm) return;
    
    const finalConfirm = window.prompt(
      "Type 'DELETE ALL' (in uppercase) to confirm deletion of all voters:"
    );
    
    if (finalConfirm !== 'DELETE ALL') {
      alert("Deletion cancelled. You must type 'DELETE ALL' exactly to confirm.");
      return;
    }
    
    try {
      const res = await authFetch(`${API_BASE}/admin/delete-all-voters.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ confirm: true }),
      });

      const data = await res.json();
      if (data.success) {
        const stats = data.related_data_deleted || {};
        alert(
          `✅ Successfully deleted ${data.deleted_count} voter(s)!\n\n` +
          `Related data deleted:\n` +
          `• Votes: ${stats.votes || 0}\n` +
          `• Face Images: ${stats.face_images || 0}\n` +
          `• Authorization Records: ${stats.authorization_records || 0}\n` +
          `• Voter List Entries: ${stats.voter_list_entries || 0}`
        );
        loadData();
      } else {
        alert(data.message || "Failed to delete voters");
      }
    } catch (err) {
      console.error("Error deleting all voters:", err);
      alert("Error while deleting voters");
    }
  };

  const handleBulkAuthorize = async () => {
    const pendingVoters = voters.filter(v => !v.authorized && v.face_image);
    if (pendingVoters.length === 0) {
      alert("ℹ️ No pending voters to authorize. All voters with face images are already authorized.");
      return;
    }

    if (!window.confirm(`Are you sure you want to authorize ${pendingVoters.length} pending voter(s)?\n\nThis will authorize all voters who have registered with face images but are not yet authorized.`)) return;

    try {
      const res = await authFetch(`${API_BASE}/bulk-authorize-voters.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          authorize_all: true
        }),
      });

      const data = await res.json();
      if (data.success) {
        alert(`✅ ${data.message}`);
        loadData();
      } else {
        alert(data.message || "Failed to authorize voters");
      }
    } catch (err) {
      console.error("Error bulk authorizing:", err);
      alert("Error while authorizing voters");
    }
  };

  const updateSetting = async (settingKey, settingValue) => {
    try {
      const res = await authFetch(`${API_BASE}/update-settings.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          setting_key: settingKey,
          setting_value: settingValue
        }),
      });

      const data = await res.json();
      if (data.success) {
        setSettings(prev => ({ ...prev, [settingKey]: settingValue }));
        alert(`✅ Setting updated successfully!`);
      } else {
        alert(data.message || "Failed to update setting");
      }
    } catch (err) {
      console.error("Error updating setting:", err);
      alert("Error while updating setting");
    }
  };

  const runAutoAuthorize = async () => {
    if (!window.confirm("Run auto-authorization now? This will authorize all pending voters based on current settings.")) return;

    try {
      const res = await authFetch(`${API_BASE}/auto-authorize-voters.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });

      const data = await res.json();
      if (data.success) {
        alert(`✅ ${data.message}`);
        loadData();
      } else {
        alert(data.message || "Failed to auto-authorize voters");
      }
    } catch (err) {
      console.error("Error auto-authorizing:", err);
      alert("Error while auto-authorizing voters");
    }
  };

  const stats = {
    totalElections: elections.length,
    totalCandidates: candidates.length,
    totalVoters: voters.length,
    activeElections: elections.filter((e) => e.status === "active").length,
  };

  return (
    <div className="dashboard">
      {/* Sidebar */}
      <aside className="sidebar">
        <div>
          <div className="user-section">
            <div className="avatar">{user.name?.[0]?.toUpperCase() || "A"}</div>
            <div>
              <strong>{user.name || "Admin"}</strong>
              <p style={{ fontSize: "13px", color: "rgba(255,255,255,0.9)" }}>{user.email}</p>
            </div>
          </div>
          <div className="menu">
            {["overview", "register-voter", "elections", "candidates", "voters", "results", "analytics", "settings"].map((tab) => {
              const displayTab = tab === "overview" ? "dashboard" : tab;
              return (
                <button
                  key={tab}
                  className={activeTab === tab || activeTab === displayTab ? "active" : ""}
                  onClick={() => {
                    // Map "overview" to "dashboard" for routing
                    const routeTab = tab === "overview" ? "dashboard" : tab;
                    setActiveTab(tab);
                    handleNavigate(routeTab);
                    if (tab === "analytics") {
                      loadAnalytics();
                      setSelectedElectionForAnalytics(null);
                    }
                  }}
                >
                  {tab === "overview" && "📊 Overview"}
                  {tab === "register-voter" && "👤 Register Voter"}
                  {tab === "elections" && "🗳️ Elections"}
                  {tab === "candidates" && "👥 Candidates"}
                  {tab === "voters" && "🧾 Voters"}
                  {tab === "results" && "📈 Results"}
                  {tab === "analytics" && "📊 Analytics"}
                  {tab === "settings" && "⚙️ Settings"}
                </button>
              );
            })}
          </div>
        </div>
        <button onClick={handleLogout} className="logout-btn">
          🚪 Logout
        </button>
      </aside>

      {/* Main Content */}
      <main className="main-content">
        {/* Tab Header */}
        <div className="tab-header">
          <h1>
            {activeTab === "overview" && "Dashboard Overview"}
            {activeTab === "register-voter" && "Register New Voter"}
            {activeTab === "elections" && "Manage Elections"}
            {activeTab === "candidates" && "Manage Candidates"}
            {activeTab === "voters" && "Manage Voters"}
            {activeTab === "results" && "Election Results"}
            {activeTab === "analytics" && "Analytics Dashboard"}
            {activeTab === "settings" && "System Settings"}
          </h1>
          {(activeTab === "elections" || activeTab === "candidates" || activeTab === "voters") && (
            <div className="header-buttons">
              {activeTab === "elections" && (
                <button className="btn-primary" onClick={() => setShowElectionModal(true)}>
                  + Add Election
                </button>
              )}
              {activeTab === "candidates" && (
                <button className="btn-primary" onClick={() => setShowCandidateModal(true)}>
                  + Add Candidate
                </button>
              )}
              {activeTab === "voters" && (
                <>
                  <button 
                    className="btn-primary" 
                    onClick={() => setShowImportWhitelistModal(true)}
                    disabled={loading}
                  >
                    ⬆️ Upload Voter List
                  </button>
                  <button 
                    className="btn-primary" 
                    onClick={handleBulkAuthorize}
                    disabled={loading}
                  >
                    ✅ Authorize All Pending
                  </button>
                  <button 
                    className="btn-danger" 
                    onClick={handleDeleteAllVoters}
                    disabled={loading}
                    style={{
                      backgroundColor: "#dc3545",
                      color: "white"
                    }}
                  >
                    🗑️ Delete All Voters
                  </button>
                </>
              )}
              <button className="btn-secondary" onClick={loadData} disabled={loading}>
                {loading ? "⏳ Loading..." : "🔄 Refresh"}
              </button>
            </div>
          )}
        </div>

        {/* Overview */}
        {activeTab === "overview" && (
          <div>
            <div className="stats">
              <div className="card blue">
                <h2>{stats.totalElections}</h2>
                <p>Total Elections</p>
              </div>
              <div className="card purple">
                <h2>{stats.activeElections}</h2>
                <p>Active Elections</p>
              </div>
              <div className="card green">
                <h2>{stats.totalCandidates}</h2>
                <p>Total Candidates</p>
              </div>
              <div className="card orange">
                <h2>{stats.totalVoters}</h2>
                <p>Total Voters</p>
              </div>
            </div>
            {/* {analytics && (
              <div className="stats" style={{ marginTop: "20px" }}>
                <div className="card blue">
                  <h2>{analytics.total_votes || 0}</h2>
                  <p>Total Votes Cast</p>
                </div>
                <div className="card purple">
                  <h2>{analytics.recent_votes_24h || 0}</h2>
                  <p>Votes (Last 24h)</p>
                </div>
                <div className="card green">
                  <h2>{analytics.total_candidates || 0}</h2>
                  <p>Total Candidates</p>
                </div>
                <div className="card orange">
                  <h2>{analytics.total_voters || 0}</h2>
                  <p>Total Voters</p>
                </div>
              </div>
            )} */}
          </div>
        )}

        {/* Elections */}
        {activeTab === "elections" && (
          <div className="grid">
            {elections.length === 0 ? (
              <div className="empty-state">
                <p>No elections created yet.</p>
                <button className="btn-primary" onClick={() => setShowElectionModal(true)}>
                  Create Your First Election
                </button>
              </div>
            ) : (
              elections.map((e) => (
                <div key={e.id} className="card-item election-card">
                  <h3>{e.title}</h3>
                  <p className="description">{e.description}</p>
                  <div className="election-info">
                    <p>
                      <strong>Start:</strong> {formatDateTime(e.start_date)}
                    </p>
                    <p>
                      <strong>End:</strong> {formatDateTime(e.end_date)}
                    </p>
                    <p>
                      <strong>Status:</strong>{" "}
                      <span className={`status-badge ${calculateElectionStatus(e)}`}>
                        {calculateElectionStatus(e).toUpperCase()}
                      </span>
                    </p>
                  </div>
                  <div className="actions">
                    <button className="edit" onClick={() => {
                      setSelectedElection(e);
                      setShowVoterListModal(true);
                    }} title="Upload/Update Voter List">
                      📋 Voter List
                    </button>
                    <button className="edit" onClick={() => {
                      setSelectedElection(e);
                      setShowEditElectionModal(true);
                    }}>
                      ✏️ Edit
                    </button>
                    <button className="delete" onClick={() => handleDeleteElection(e.id)}>
                      🗑️ Delete
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        )}

        {/* Candidates */}
        {activeTab === "candidates" && (
          <div className="grid">
            {candidates.length === 0 ? (
              <div className="empty-state">
                <p>No candidates available.</p>
                <button className="btn-primary" onClick={() => setShowCandidateModal(true)}>
                  Add Your First Candidate
                </button>
              </div>
            ) : (
              candidates.map((c) => (
                <div key={c.id} className="card-item candidate-card">
                  <img
                    src={`http://localhost/final_votesecure/backend/${c.photo}`}
                    alt={c.name}
                    className="candidate-img"
                    onError={(e) => (e.target.src = "https://via.placeholder.com/120")}
                  />
                  <h3>{c.name}</h3>
                  <p>
                    <strong>Position:</strong> {c.position}
                  </p>
                  <p>
                    <strong>Party:</strong> {c.party || "Independent"}
                  </p>
                  <p>
                    <strong>Election:</strong> {c.election_title}
                  </p>
                  {c.bio && <p className="bio">{c.bio}</p>}
                  <div className="actions">
                    <button className="edit" onClick={() => {
                      setSelectedCandidate(c);
                      setShowEditCandidateModal(true);
                    }}>
                      ✏️ Edit
                    </button>
                    <button className="delete" onClick={() => handleDeleteCandidate(c.id)}>
                      🗑️ Delete
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        )}

        {/* Register Voter */}
        {activeTab === "register-voter" && (
          <div>
            <Outlet />
          </div>
        )}

        {/* Voters */}
        {activeTab === "voters" && (
          <div className="grid">
            {voters.length === 0 ? (
              <div className="empty-state">
                <p>No voters registered yet.</p>
              </div>
            ) : (
              voters.map((v) => (
                <div key={v.id} className="card-item voter-card">
                  <div className="voter-avatar">{v.name?.[0]?.toUpperCase() || "V"}</div>
                  <h3>{v.name}</h3>
                  <p>
                    <strong>Email:</strong> {v.email}
                  </p>
                  <p>
                    <strong>Verified:</strong>{" "}
                    <span className={v.verified ? "text-green" : "text-red"}>
                      {v.verified ? "✅ Yes" : "❌ No"}
                    </span>
                  </p>
                  <p>
                    <strong>Authorized:</strong>{" "}
                    <span className={v.authorized ? "text-green" : "text-red"}>
                      {v.authorized ? "✅ Yes" : "❌ No"}
                    </span>
                  </p>
                  <p>
                    <strong>Joined:</strong> {new Date(v.created_at).toLocaleString()}
                  </p>
                  <div className="actions">
                    <button
                      className="edit"
                      onClick={() => {
                        setSelectedVoter(v);
                        setShowEditVoterModal(true);
                      }}
                    >
                      ✏️ Edit
                    </button>
                    <button
                      className="delete"
                      onClick={() => handleDeleteVoter(v.id)}
                    >
                      🗑️ Delete
                    </button>
                    <button
                      className={v.authorized ? "btn-warning" : "btn-success"}
                      onClick={() => handleToggleAuthorization(v.id, !v.authorized)}
                      style={{ marginTop: "5px", width: "100%" }}
                    >
                      {v.authorized ? "🚫 Revoke Access" : "✅ Authorize"}
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        )}

        {/* Results */}
        {activeTab === "results" && (
          <div className="results-section">
            <h2>Election Results</h2>
            {elections.length === 0 ? (
              <p>No elections available to show results.</p>
            ) : (
              <div className="results-list">
                {elections.map((election) => (
                  <div key={election.id} className="result-card">
                    <h3>{election.title}</h3>
                    <p>Results will be displayed here after voting ends.</p>
                    <button 
                      className="btn-primary" 
                      style={{ marginTop: "10px" }}
                      onClick={async () => {
                        const res = await authFetch(`${API_BASE}/get-election-results.php?election_id=${election.id}`);
                        const data = await res.json();
                        if (data.success) {
                          alert(`Results for ${election.title}:\nTotal Votes: ${data.statistics.total_votes}\nTurnout: ${data.statistics.turnout_percentage}%\nWinner: ${data.winners.map(w => w.name).join(", ")}`);
                        }
                      }}
                    >
                      View Results
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Analytics */}
        {activeTab === "analytics" && (
          <div className="analytics-section">
            <div style={{ marginBottom: "20px" }}>
              <label style={{ display: "block", marginBottom: "10px", fontWeight: "600" }}>
                Select Election (or leave empty for overall analytics):
              </label>
              <select
                value={selectedElectionForAnalytics || ""}
                onChange={(e) => {
                  const electionId = e.target.value || null;
                  setSelectedElectionForAnalytics(electionId);
                  loadAnalytics(electionId);
                }}
                style={{
                  padding: "10px",
                  borderRadius: "8px",
                  border: "2px solid #e6e9ff",
                  fontSize: "14px",
                  minWidth: "300px"
                }}
              >
                <option value="">Overall Analytics</option>
                {elections.map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.title}
                  </option>
                ))}
              </select>
            </div>

            {loadingAnalytics ? (
              <div style={{ textAlign: "center", padding: "40px" }}>
                <div className="spinner" style={{ margin: "0 auto" }}></div>
                <p>Loading analytics...</p>
              </div>
            ) : analytics ? (
              <div>
                {selectedElectionForAnalytics ? (
                  // Election-specific analytics
                  <div>
                    <div className="stats">
                      <div className="card blue">
                        <h2>{analytics.total_votes || 0}</h2>
                        <p>Total Votes</p>
                      </div>
                      <div className="card purple">
                        <h2>{analytics.total_authorized || 0}</h2>
                        <p>Authorized Voters</p>
                      </div>
                      <div className="card green">
                        <h2>{analytics.turnout_percentage || 0}%</h2>
                        <p>Voter Turnout</p>
                      </div>
                      <div className="card orange">
                        <h2>{analytics.candidate_data?.length || 0}</h2>
                        <p>Candidates</p>
                      </div>
                    </div>

                    {analytics.candidate_data && analytics.candidate_data.length > 0 && (
                      <div style={{ marginTop: "30px", background: "white", padding: "25px", borderRadius: "15px", boxShadow: "0 5px 20px rgba(109, 93, 252, 0.1)" }}>
                        <h3 style={{ marginBottom: "20px", color: "#333" }}>Votes by Candidate</h3>
                        <div style={{ display: "flex", flexDirection: "column", gap: "15px" }}>
                          {analytics.candidate_data.map((candidate, index) => {
                            const percentage = analytics.total_votes > 0 
                              ? ((candidate.vote_count / analytics.total_votes) * 100).toFixed(1)
                              : 0;
                            return (
                              <div key={candidate.id} style={{ padding: "15px", background: "#f8f9fa", borderRadius: "10px" }}>
                                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "10px" }}>
                                  <div>
                                    <strong style={{ fontSize: "16px" }}>#{index + 1} {candidate.name}</strong>
                                    <p style={{ margin: "5px 0 0 0", color: "#666" }}>{candidate.position}</p>
                                  </div>
                                  <div style={{ textAlign: "right" }}>
                                    <div style={{ fontSize: "18px", fontWeight: "700", color: "#0056b3" }}>{candidate.vote_count} votes</div>
                                    <div style={{ fontSize: "14px", color: "#666" }}>{percentage}%</div>
                                  </div>
                                </div>
                                <div style={{ width: "100%", height: "8px", background: "#e6e9ff", borderRadius: "4px", overflow: "hidden" }}>
                                  <div style={{ width: `${percentage}%`, height: "100%", background: "linear-gradient(90deg, #0056b3 0%, #007bff 100%)", borderRadius: "4px", transition: "width 0.5s ease" }}></div>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    {analytics.hourly_data && analytics.hourly_data.length > 0 && (
                      <div style={{ marginTop: "30px", background: "white", padding: "25px", borderRadius: "15px", boxShadow: "0 5px 20px rgba(109, 93, 252, 0.1)" }}>
                        <h3 style={{ marginBottom: "20px", color: "#333" }}>Voting Activity Timeline</h3>
                        <div style={{ display: "flex", flexDirection: "column", gap: "10px" }}>
                          {analytics.hourly_data.map((hour, index) => (
                            <div key={index} style={{ display: "flex", justifyContent: "space-between", padding: "10px", background: "#f8f9fa", borderRadius: "8px" }}>
                              <span>{new Date(hour.hour).toLocaleString()}</span>
                              <strong>{hour.vote_count} votes</strong>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                ) : (
                  // Overall analytics
                  <div>
                    <div className="stats">
                      <div className="card blue">
                        <h2>{analytics.total_elections || 0}</h2>
                        <p>Total Elections</p>
                      </div>
                      <div className="card purple">
                        <h2>{analytics.total_votes || 0}</h2>
                        <p>Total Votes</p>
                      </div>
                      <div className="card green">
                        <h2>{analytics.total_voters || 0}</h2>
                        <p>Total Voters</p>
                      </div>
                      <div className="card orange">
                        <h2>{analytics.recent_votes_24h || 0}</h2>
                        <p>Votes (Last 24h)</p>
                      </div>
                    </div>

                    {analytics.elections_by_status && analytics.elections_by_status.length > 0 && (
                      <div style={{ marginTop: "30px", background: "white", padding: "25px", borderRadius: "15px", boxShadow: "0 5px 20px rgba(109, 93, 252, 0.1)" }}>
                        <h3 style={{ marginBottom: "20px", color: "#333" }}>Elections by Status</h3>
                        <div style={{ display: "flex", flexDirection: "column", gap: "10px" }}>
                          {analytics.elections_by_status.map((status, index) => (
                            <div key={index} style={{ display: "flex", justifyContent: "space-between", padding: "15px", background: "#f8f9fa", borderRadius: "10px" }}>
                              <span style={{ textTransform: "capitalize", fontWeight: "600" }}>{status.status}</span>
                              <strong style={{ color: "#0056b3", fontSize: "18px" }}>{status.count}</strong>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ) : (
              <p>No analytics data available.</p>
            )}
          </div>
        )}

{/*

     
        {activeTab === "settings" && (
          <div className="settings-section">
            <div className="settings-card">
              <h2>🔐 Auto-Authorization Settings</h2>
              <p className="settings-description">
                Automatically authorize voters when they register. This eliminates the need to manually approve each voter.
              </p>

              <div className="setting-item">
                <div className="setting-info">
                  <h3>Enable Auto-Authorization</h3>
                  <p>When enabled, voters are automatically authorized based on the rules below.</p>
                </div>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={settings.auto_authorize_enabled === '1'}
                    onChange={(e) => updateSetting('auto_authorize_enabled', e.target.checked ? '1' : '0')}
                  />
                  <span className="slider"></span>
                </label>
              </div>

              <div className="setting-item">
                <div className="setting-info">
                  <h3>Require Face Image for Auto-Authorization</h3>
                  <p>
                    When enabled, only voters who register with a face image will be auto-authorized.
                    This ensures biometric verification is completed before authorization.
                  </p>
                </div>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={settings.auto_authorize_face_required === '1'}
                    onChange={(e) => updateSetting('auto_authorize_face_required', e.target.checked ? '1' : '0')}
                    disabled={settings.auto_authorize_enabled !== '1'}
                  />
                  <span className="slider"></span>
                </label>
              </div>

              <div className="setting-actions">
                <button className="btn-primary" onClick={runAutoAuthorize}>
                  🔄 Run Auto-Authorization Now
                </button>
                <button className="btn-secondary" onClick={loadSettings}>
                  🔄 Refresh Settings
                </button>
              </div>

              <div className="settings-info-box">
                <h4>📋 How It Works:</h4>
                <ul>
                  <li>
                    <strong>Auto-Authorization Enabled + Face Required:</strong> Voters with face images are automatically authorized on registration.
                  </li>
                  <li>
                    <strong>Auto-Authorization Enabled + Face Not Required:</strong> All voters are automatically authorized on registration.
                  </li>
                  <li>
                    <strong>Auto-Authorization Disabled:</strong> All voters require manual approval by admin.
                  </li>
                </ul>
                <p style={{ marginTop: '15px', color: '#666' }}>
                  <strong>💡 Tip:</strong> You can also set up a cron job to run auto-authorization periodically.
                  See <code>backend/cron-auto-authorize.php</code> for details.
                </p>
              </div>
            </div>
          </div>
        )}

*/}

        {/* Add Election Modal */}
        {showElectionModal && (
          <div className="modal" onClick={() => setShowElectionModal(false)}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
              <form onSubmit={handleAddElection} className="modal-form">
                <h2>Add Election</h2>
                <input name="title" placeholder="Election Title" required />
                <textarea
                  name="description"
                  placeholder="Description"
                  rows="4"
                  required
                />
                <div style={{ display: "flex", gap: "10px" }}>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>Start Date</label>
                    <input name="start_date" type="date" required style={{ width: "100%" }} />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>Start Time</label>
                    <input name="start_time" type="time" defaultValue="00:00" required style={{ width: "100%" }} />
                  </div>
                </div>
                <div style={{ display: "flex", gap: "10px" }}>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>End Date</label>
                    <input name="end_date" type="date" required style={{ width: "100%" }} />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>End Time</label>
                    <input name="end_time" type="time" defaultValue="23:59" required style={{ width: "100%" }} />
                  </div>
                </div>
                <div style={{ marginTop: "15px" }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "8px" }}>
                    <label style={{ fontSize: "14px", fontWeight: "500", color: "#333" }}>
                      Voter List (CSV/Excel) <span style={{ color: "#666", fontWeight: "normal", fontSize: "12px" }}>(Optional - can upload later)</span>
                    </label>
                    <button
                      type="button"
                      onClick={downloadVoterListTemplate}
                      style={{
                        padding: "4px 10px",
                        fontSize: "11px",
                        backgroundColor: "#007bff",
                        color: "white",
                        border: "none",
                        borderRadius: "4px",
                        cursor: "pointer",
                        fontWeight: "500"
                      }}
                    >
                      ⬇️ Download Template
                    </button>
                  </div>
                  <input 
                    name="voter_list" 
                    type="file" 
                    accept=".csv,.xlsx,.xls" 
                    style={{ 
                      width: "100%", 
                      padding: "8px", 
                      border: "1px solid #ddd", 
                      borderRadius: "4px",
                      fontSize: "14px"
                    }} 
                  />
                  <div style={{ 
                    marginTop: "8px", 
                    padding: "8px", 
                    backgroundColor: "#f8f9fa", 
                    borderRadius: "4px",
                    fontSize: "11px",
                    color: "#666"
                  }}>
                    <strong>Format:</strong>
                    <pre style={{ 
                      margin: "4px 0 0 0", 
                      padding: "6px", 
                      backgroundColor: "#fff", 
                      borderRadius: "3px",
                      fontSize: "10px",
                      overflowX: "auto",
                      border: "1px solid #ddd"
                    }}>
{`name,email,phone
John Doe,john@example.com,1234567890`}
                    </pre>
                    <p style={{ margin: "4px 0 0 0" }}>
                      <strong>Required:</strong> name, email | <strong>Optional:</strong> phone
                    </p>
                  </div>
                </div>
                <div className="modal-buttons">
                  <button type="submit" className="btn-primary">
                    Create Election
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => setShowElectionModal(false)}
                  >
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Add Candidate Modal */}
        {showCandidateModal && (
          <div className="modal" onClick={() => setShowCandidateModal(false)}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
              <form onSubmit={handleAddCandidate} className="modal-form">
                <h2>Add Candidate</h2>
                <input name="name" placeholder="Candidate Name" required />
                <input name="position" placeholder="Position" required />
                <input name="party" placeholder="Party (optional)" />
                <textarea name="bio" placeholder="Biography (optional)" rows="3" />
                <select name="election_id" required>
                  <option value="">Select Election</option>
                  {elections.map((e) => (
                    <option key={e.id} value={e.id}>
                      {e.title}
                    </option>
                  ))}
                </select>
                <input name="photo" type="file" accept="image/*" />
                <div className="modal-buttons">
                  <button type="submit" className="btn-primary">
                    Create Candidate
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => setShowCandidateModal(false)}
                  >
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Upload Voter List Modal */}
        {showVoterListModal && selectedElection && (
          <div className="modal" onClick={() => {
            setShowVoterListModal(false);
            setSelectedElection(null);
          }}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
              <form onSubmit={handleUploadVoterList} className="modal-form">
                <h2>Upload Voter List - {selectedElection.title}</h2>
                <p style={{ fontSize: "14px", color: "#666", marginBottom: "15px" }}>
                  Upload a CSV or Excel file containing the voter list for this election.
                  <br />
                  <strong>Note:</strong> This will replace the existing voter list.
                </p>
                
                {/* Template Download Section */}
                <div style={{ 
                  marginBottom: "20px", 
                  padding: "12px", 
                  backgroundColor: "#f8f9fa", 
                  borderRadius: "6px",
                  border: "1px solid #e0e0e0"
                }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "8px" }}>
                    <label style={{ fontSize: "14px", fontWeight: "500", color: "#333" }}>
                      📋 Need a template?
                    </label>
                    <button
                      type="button"
                      onClick={downloadVoterListTemplate}
                      style={{
                        padding: "6px 12px",
                        fontSize: "12px",
                        backgroundColor: "#007bff",
                        color: "white",
                        border: "none",
                        borderRadius: "4px",
                        cursor: "pointer",
                        fontWeight: "500"
                      }}
                    >
                      ⬇️ Download Template
                    </button>
                  </div>
                  <div style={{ fontSize: "12px", color: "#666", marginTop: "8px" }}>
                    <strong>File Format:</strong>
                    <pre style={{ 
                      margin: "8px 0 0 0", 
                      padding: "8px", 
                      backgroundColor: "#fff", 
                      borderRadius: "4px",
                      fontSize: "11px",
                      overflowX: "auto",
                      border: "1px solid #ddd"
                    }}>
{`name,email,phone
John Doe,john.doe@example.com,1234567890
Jane Smith,jane.smith@example.com,0987654321
Bob Johnson,bob.johnson@example.com,5551234567`}
                    </pre>
                    <p style={{ margin: "8px 0 0 0", fontSize: "11px" }}>
                      <strong>Required:</strong> name, email | <strong>Optional:</strong> phone
                    </p>
                  </div>
                </div>

                <div style={{ marginBottom: "15px" }}>
                  <label style={{ display: "block", marginBottom: "8px", fontSize: "14px", fontWeight: "500", color: "#333" }}>
                    Voter List File (CSV/Excel)
                  </label>
                  <input 
                    name="voter_list" 
                    type="file" 
                    accept=".csv,.xlsx,.xls" 
                    required
                    style={{ 
                      width: "100%", 
                      padding: "8px", 
                      border: "1px solid #ddd", 
                      borderRadius: "4px",
                      fontSize: "14px"
                    }} 
                  />
                  <p style={{ marginTop: "5px", fontSize: "11px", color: "#666" }}>
                    Supported formats: CSV, Excel (.xlsx, .xls)
                  </p>
                </div>
                <div className="modal-buttons">
                  <button 
                    type="submit" 
                    className="btn-primary"
                    disabled={uploadingVoterList}
                  >
                    {uploadingVoterList ? "Uploading..." : "Upload Voter List"}
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => {
                      setShowVoterListModal(false);
                      setSelectedElection(null);
                    }}
                    disabled={uploadingVoterList}
                  >
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Edit Election Modal */}
        {showEditElectionModal && selectedElection && (
          <div className="modal" onClick={() => {
            setShowEditElectionModal(false);
            setSelectedElection(null);
          }}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
              <form onSubmit={handleEditElection} className="modal-form">
                <h2>Edit Election</h2>
                <input 
                  name="title" 
                  placeholder="Election Title" 
                  defaultValue={selectedElection.title}
                  required 
                />
                <textarea
                  name="description"
                  placeholder="Description"
                  rows="4"
                  defaultValue={selectedElection.description}
                  required
                />
                <div style={{ display: "flex", gap: "10px" }}>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>Start Date</label>
                    <input 
                      name="start_date" 
                      type="date" 
                      defaultValue={selectedElection.start_date ? (selectedElection.start_date.includes(' ') ? selectedElection.start_date.split(' ')[0] : selectedElection.start_date.split('T')[0]) : ''}
                      required 
                      style={{ width: "100%" }}
                    />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>Start Time</label>
                    <input 
                      name="start_time" 
                      type="time" 
                      defaultValue={extractTimeFromDateTime(selectedElection.start_date)}
                      required 
                      style={{ width: "100%" }}
                    />
                  </div>
                </div>
                <div style={{ display: "flex", gap: "10px" }}>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>End Date</label>
                    <input 
                      name="end_date" 
                      type="date" 
                      defaultValue={selectedElection.end_date ? (selectedElection.end_date.includes(' ') ? selectedElection.end_date.split(' ')[0] : selectedElection.end_date.split('T')[0]) : ''}
                      required 
                      style={{ width: "100%" }}
                    />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label style={{ display: "block", marginBottom: "5px", fontSize: "12px", color: "#666" }}>End Time</label>
                    <input 
                      name="end_time" 
                      type="time" 
                      defaultValue={extractTimeFromDateTime(selectedElection.end_date) || '23:59'}
                      required 
                      style={{ width: "100%" }}
                    />
                  </div>
                </div>
                <div className="modal-buttons">
                  <button type="submit" className="btn-primary">
                    Update Election
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => {
                      setShowEditElectionModal(false);
                      setSelectedElection(null);
                    }}
                  >
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Edit Voter Modal */}
        {showEditVoterModal && selectedVoter && (
          <div className="modal" onClick={() => {
            setShowEditVoterModal(false);
            setSelectedVoter(null);
          }}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
              <form onSubmit={handleEditVoter} className="modal-form">
                <h2>Edit Voter</h2>
                <input 
                  name="name" 
                  placeholder="Full Name" 
                  defaultValue={selectedVoter.name}
                  required 
                />
                <input 
                  name="email" 
                  type="email"
                  placeholder="Email Address" 
                  defaultValue={selectedVoter.email}
                  required 
                />
                <input 
                  name="phone" 
                  type="tel"
                  placeholder="Phone Number (optional)" 
                  defaultValue={selectedVoter.phone_number || ''}
                />
                <input 
                  name="date_of_birth" 
                  type="date"
                  placeholder="Date of Birth (optional)" 
                  defaultValue={selectedVoter.date_of_birth ? (selectedVoter.date_of_birth.includes(' ') ? selectedVoter.date_of_birth.split(' ')[0] : selectedVoter.date_of_birth.split('T')[0]) : ''}
                />
                <div style={{ 
                  padding: "10px", 
                  backgroundColor: "#f8f9fa", 
                  borderRadius: "4px",
                  fontSize: "12px",
                  color: "#666",
                  marginTop: "10px"
                }}>
                  <strong>Note:</strong> Changing email will require the voter to use the new email for login.
                </div>
                <div className="modal-buttons">
                  <button type="submit" className="btn-primary">
                    Update Voter
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => {
                      setShowEditVoterModal(false);
                      setSelectedVoter(null);
                    }}
                  >
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Edit Candidate Modal */}
        {showEditCandidateModal && selectedCandidate && (
          <div className="modal" onClick={() => {
            setShowEditCandidateModal(false);
            setSelectedCandidate(null);
          }}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
              <form onSubmit={handleEditCandidate} className="modal-form">
                <h2>Edit Candidate</h2>
                <input 
                  name="name" 
                  placeholder="Candidate Name" 
                  defaultValue={selectedCandidate.name}
                  required 
                />
                <input 
                  name="position" 
                  placeholder="Position" 
                  defaultValue={selectedCandidate.position}
                  required 
                />
                <input 
                  name="party" 
                  placeholder="Party (optional)" 
                  defaultValue={selectedCandidate.party || ""}
                />
                <textarea 
                  name="bio" 
                  placeholder="Biography (optional)" 
                  rows="3"
                  defaultValue={selectedCandidate.bio || ""}
                />
                <select name="election_id" defaultValue={selectedCandidate.election_id} required>
                  <option value="">Select Election</option>
                  {elections.map((e) => (
                    <option key={e.id} value={e.id}>
                      {e.title}
                    </option>
                  ))}
                </select>
                <div className="modal-buttons">
                  <button type="submit" className="btn-primary">Update Candidate</button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => {
                      setShowEditCandidateModal(false);
                      setSelectedCandidate(null);
                    }}
                  >
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

          {/* Note: Edit Election / Edit Candidate modals are implemented below (single instances). */}

          {/* Import Voter Whitelist Modal */}
          {showImportWhitelistModal && (
            <div className="modal" onClick={() => setShowImportWhitelistModal(false)}>
              <div className="modal-content" onClick={(e) => e.stopPropagation()}>
                <form
                  className="modal-form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    if (!importFile) {
                      alert("Please select a CSV file.");
                      return;
                    }
                    setImporting(true);
                    setImportSummary(null);
                    try {
                      const formData = new FormData();
                      formData.append("file", importFile);
                      if (importDefaults.organization_id !== "") {
                        formData.append("organization_id", importDefaults.organization_id);
                      }
                      if (importDefaults.election_id !== "") {
                        formData.append("election_id", importDefaults.election_id);
                      }
                      formData.append("active", importDefaults.active || "1");

                      const res = await authFetch(`${API_BASE}/import-voter-whitelist.php`, {
                        method: "POST",
                        body: formData,
                      });
                      const text = await res.text();
                      let data;
                      try {
                        data = JSON.parse(text);
                      } catch {
                        throw new Error("Invalid server response");
                      }
                      if (!data.success) {
                        throw new Error(data.message || "Import failed");
                      }
                      setImportSummary(data.summary || null);
                      alert("✅ Import completed");
                      // Refresh voters list so admins can see updates
                      loadData();
                    } catch (err) {
                      console.error("Import error:", err);
                      alert("❌ Import error: " + err.message);
                    } finally {
                      setImporting(false);
                    }
                  }}
                >
                  <h2>Upload Voter List (CSV)</h2>
                  <div style={{ marginBottom: 12 }}>
                    <input
                      type="file"
                      accept=".csv,text/csv"
                      onChange={(e) => setImportFile(e.target.files && e.target.files[0] ? e.target.files[0] : null)}
                    />
                  </div>
                  <p style={{ marginTop: 0, fontSize: 12 }}>
                    CSV headers supported: <code>email</code>, <code>organization_id</code>, <code>election_id</code>, <code>active</code>.
                    Missing fields can be set below as defaults.
                  </p>
                  <div className="form-row">
                    <input
                      type="number"
                      placeholder="Default Organization ID (optional)"
                      value={importDefaults.organization_id}
                      onChange={(e) => setImportDefaults(d => ({ ...d, organization_id: e.target.value }))}
                    />
                  </div>
                  <div className="form-row">
                    <select
                      value={importDefaults.election_id}
                      onChange={(e) => setImportDefaults(d => ({ ...d, election_id: e.target.value }))}
                    >
                      <option value="">Default Election (optional)</option>
                      {elections.map((e) => (
                        <option key={e.id} value={e.id}>{e.title}</option>
                      ))}
                    </select>
                  </div>
                  <div className="form-row">
                    <label style={{ display: "flex", gap: 8, alignItems: "center" }}>
                      <span>Default Active</span>
                      <select
                        value={importDefaults.active}
                        onChange={(e) => setImportDefaults(d => ({ ...d, active: e.target.value }))}
                      >
                        <option value="1">Active (1)</option>
                        <option value="0">Inactive (0)</option>
                      </select>
                    </label>
                  </div>
                  {importSummary && (
                    <div className="import-summary" style={{ marginTop: 12, fontSize: 14 }}>
                      <div>Total rows: {importSummary.total_rows}</div>
                      <div>Inserted: {importSummary.inserted}</div>
                      <div>Updated: {importSummary.updated}</div>
                      <div>Skipped: {importSummary.skipped}</div>
                    </div>
                  )}
                  <div className="modal-buttons">
                    <button type="submit" className="btn-primary" disabled={importing}>
                      {importing ? "⏳ Importing..." : "Upload CSV"}
                    </button>
                    <button
                      type="button"
                      className="btn-secondary"
                      onClick={() => setShowImportWhitelistModal(false)}
                      disabled={importing}
                    >
                      Close
                    </button>
                  </div>
                </form>
              </div>
            </div>
          )}
      </main>
    </div>
  );
};

export default AdminDashboard;
