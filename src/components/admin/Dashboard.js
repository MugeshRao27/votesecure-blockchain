import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import './Dashboard.css';

const API_BASE = 'http://localhost/final_votesecure/backend/api';

const Dashboard = () => {
  const [stats, setStats] = useState({
    totalVoters: 0,
    activeElections: 0,
    totalCandidates: 0,
    votesCast: 0
  });
  const [loading, setLoading] = useState(true);
  const [recentVoters, setRecentVoters] = useState([]);
  const [recentElections, setRecentElections] = useState([]);

  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true);
        // In a real app, you would fetch this data from your API
        // const [statsRes, votersRes, electionsRes] = await Promise.all([
        //   fetch(`${API_BASE}/admin/stats`),
        //   fetch(`${API_BASE}/admin/recent-voters`),
        //   fetch(`${API_BASE}/admin/active-elections`)
        // ]);
        
        // Mock data for now
        setTimeout(() => {
          setStats({
            totalVoters: 1245,
            activeElections: 3,
            totalCandidates: 28,
            votesCast: 856
          });
          
          setRecentVoters([
            { id: 1, name: 'John Doe', email: 'john@example.com', registered: '2023-05-15' },
            { id: 2, name: 'Jane Smith', email: 'jane@example.com', registered: '2023-05-14' },
            { id: 3, name: 'Robert Johnson', email: 'robert@example.com', registered: '2023-05-13' },
            { id: 4, name: 'Emily Davis', email: 'emily@example.com', registered: '2023-05-12' },
            { id: 5, name: 'Michael Wilson', email: 'michael@example.com', registered: '2023-05-11' }
          ]);
          
          setRecentElections([
            { id: 1, title: 'Student Council 2023', status: 'active', startDate: '2023-05-01', endDate: '2023-05-31' },
            { id: 2, title: 'Class Representative', status: 'upcoming', startDate: '2023-06-01', endDate: '2023-06-30' },
            { id: 3, title: 'Faculty Board', status: 'completed', startDate: '2023-04-01', endDate: '2023-04-30' }
          ]);
          
          setLoading(false);
        }, 500);
      } catch (error) {
        console.error('Error fetching dashboard data:', error);
        setLoading(false);
      }
    };

    fetchDashboardData();
  }, []);

  const formatDate = (dateString) => {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString(undefined, options);
  };

  const getStatusBadge = (status) => {
    const statusMap = {
      active: 'success',
      upcoming: 'info',
      completed: 'secondary'
    };
    
    return <span className={`badge bg-${statusMap[status] || 'secondary'}`}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>;
  };

  if (loading) {
    return (
      <div className="text-center py-5">
        <div className="spinner-border text-primary" role="status">
          <span className="visually-hidden">Loading...</span>
        </div>
        <p className="mt-2">Loading dashboard...</p>
      </div>
    );
  }

  return (
    <div className="dashboard-container">
      <h1 className="page-title">Dashboard</h1>
      
      {/* Stats Cards */}
      <div className="row g-4 mb-4">
        <div className="col-md-3 col-sm-6">
          <div className="card stat-card">
            <div className="card-body">
              <div className="d-flex justify-content-between align-items-center">
                <div>
                  <h6 className="stat-title">Total Voters</h6>
                  <h2 className="stat-number">{stats.totalVoters}</h2>
                </div>
                <div className="stat-icon bg-primary-light">
                  <i className="fas fa-users text-primary"></i>
                </div>
              </div>
              <div className="stat-footer">
                <span className="text-success">
                  <i className="fas fa-arrow-up"></i> 12.5% from last month
                </span>
              </div>
            </div>
          </div>
        </div>
        
        <div className="col-md-3 col-sm-6">
          <div className="card stat-card">
            <div className="card-body">
              <div className="d-flex justify-content-between align-items-center">
                <div>
                  <h6 className="stat-title">Active Elections</h6>
                  <h2 className="stat-number">{stats.activeElections}</h2>
                </div>
                <div className="stat-icon bg-success-light">
                  <i className="fas fa-vote-yea text-success"></i>
                </div>
              </div>
              <div className="stat-footer">
                <span className="text-muted">
                  {stats.activeElections > 0 ? 'Ongoing' : 'No active elections'}
                </span>
              </div>
            </div>
          </div>
        </div>
        
        <div className="col-md-3 col-sm-6">
          <div className="card stat-card">
            <div className="card-body">
              <div className="d-flex justify-content-between align-items-center">
                <div>
                  <h6 className="stat-title">Candidates</h6>
                  <h2 className="stat-number">{stats.totalCandidates}</h2>
                </div>
                <div className="stat-icon bg-warning-light">
                  <i className="fas fa-user-tie text-warning"></i>
                </div>
              </div>
              <div className="stat-footer">
                <Link to="/admin/candidates" className="text-primary">
                  View all candidates <i className="fas fa-arrow-right"></i>
                </Link>
              </div>
            </div>
          </div>
        </div>
        
        <div className="col-md-3 col-sm-6">
          <div className="card stat-card">
            <div className="card-body">
              <div className="d-flex justify-content-between align-items-center">
                <div>
                  <h6 className="stat-title">Votes Cast</h6>
                  <h2 className="stat-number">{stats.votesCast}</h2>
                </div>
                <div className="stat-icon bg-info-light">
                  <i className="fas fa-check-circle text-info"></i>
                </div>
              </div>
              <div className="stat-footer">
                <span className="text-muted">
                  {stats.votesCast > 0 ? 'Total votes recorded' : 'No votes yet'}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div className="row">
        {/* Recent Voters */}
        <div className="col-lg-6 mb-4">
          <div className="card h-100">
            <div className="card-header d-flex justify-content-between align-items-center">
              <h5 className="mb-0">Recent Voters</h5>
              <Link to="/admin/voters" className="btn btn-sm btn-outline-primary">
                View All
              </Link>
            </div>
            <div className="card-body p-0">
              <div className="table-responsive">
                <table className="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Email</th>
                      <th>Registered</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentVoters.map((voter) => (
                      <tr key={voter.id}>
                        <td>{voter.name}</td>
                        <td>{voter.email}</td>
                        <td>{formatDate(voter.registered)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        
        {/* Recent Elections */}
        <div className="col-lg-6 mb-4">
          <div className="card h-100">
            <div className="card-header d-flex justify-content-between align-items-center">
              <h5 className="mb-0">Recent Elections</h5>
              <Link to="/admin/elections" className="btn btn-sm btn-outline-primary">
                View All
              </Link>
            </div>
            <div className="card-body p-0">
              <div className="list-group list-group-flush">
                {recentElections.map((election) => (
                  <div key={election.id} className="list-group-item">
                    <div className="d-flex justify-content-between align-items-center">
                      <div>
                        <h6 className="mb-1">{election.title}</h6>
                        <small className="text-muted">
                          {formatDate(election.startDate)} - {formatDate(election.endDate)}
                        </small>
                      </div>
                      <div>
                        {getStatusBadge(election.status)}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
      
      {/* Quick Actions */}
      <div className="row mt-4">
        <div className="col-12">
          <div className="card">
            <div className="card-header">
              <h5 className="mb-0">Quick Actions</h5>
            </div>
            <div className="card-body">
              <div className="d-flex flex-wrap gap-3">
                <Link to="/admin/register-voter" className="btn btn-primary">
                  <i className="fas fa-user-plus me-2"></i> Register New Voter
                </Link>
                <Link to="/admin/elections/create" className="btn btn-success">
                  <i className="fas fa-plus-circle me-2"></i> Create Election
                </Link>
                <Link to="/admin/candidates/add" className="btn btn-info text-white">
                  <i className="fas fa-user-tie me-2"></i> Add Candidate
                </Link>
                <Link to="/admin/settings" className="btn btn-outline-secondary">
                  <i className="fas fa-cog me-2"></i> System Settings
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
