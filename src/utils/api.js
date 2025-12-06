/**
 * API Utility Functions
 * Handles JWT token management and API calls with authentication
 */

const API_BASE = "http://localhost/final_votesecure/backend/api";

// Token management
export const tokenManager = {
  setToken: (token) => {
    localStorage.setItem('jwt_token', token);
  },
  
  getToken: () => {
    return localStorage.getItem('jwt_token');
  },
  
  removeToken: () => {
    localStorage.removeItem('jwt_token');
    localStorage.removeItem('user');
    localStorage.removeItem('isLoggedIn');
    localStorage.removeItem('role');
  },
  
  hasToken: () => {
    return !!localStorage.getItem('jwt_token');
  }
};

// API request with automatic JWT injection
export const apiRequest = async (url, options = {}) => {
  const token = tokenManager.getToken();
  
  const headers = {
    'Content-Type': 'application/json',
    ...options.headers,
  };
  
  // Add JWT token if available
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  
  const config = {
    ...options,
    headers,
  };
  
  try {
    const response = await fetch(`${API_BASE}${url}`, config);
    
    // Check if response is JSON
    let data;
    const contentType = response.headers.get('content-type');
    if (contentType && contentType.includes('application/json')) {
      data = await response.json();
    } else {
      // If not JSON, get text and try to parse
      const text = await response.text();
      console.error('Non-JSON response:', text);
      throw new Error(`Server returned non-JSON response: ${text.substring(0, 100)}`);
    }
    
    // If token expired or unauthorized, clear token
    if (response.status === 401) {
      tokenManager.removeToken();
      window.location.href = '/auth';
    }
    
    return { response, data };
  } catch (error) {
    console.error('API request error:', error);
    throw error;
  }
};

// Login functions
export const authAPI = {
  // Login with email and password
  login: async (email, password, role = 'voter') => {
    const { data } = await apiRequest('/login.php', {
      method: 'POST',
      body: JSON.stringify({ email, password, role, login_type: 'password' }),
      headers: { 'Content-Type': 'application/json' }
    });
    return data;
  },
  
  // Request OTP
  sendOTP: async (email, loginType = 'otp') => {
    const { data } = await apiRequest('/send-otp.php', {
      method: 'POST',
      body: JSON.stringify({ email, login_type: loginType }),
      headers: { 'Content-Type': 'application/json' }
    });
    return data;
  },
  
  // Verify OTP and complete login
  verifyOTP: async (email, otp, token, role = 'voter') => {
    const { data } = await apiRequest('/verify-login-otp.php', {
      method: 'POST',
      body: JSON.stringify({ email, otp, login_token: token, role }),
      headers: { 'Content-Type': 'application/json' }
    });
    return data;
  },
  
  // Register new user
  register: async (userData) => {
    const { data } = await apiRequest('/register.php', {
      method: 'POST',
      body: JSON.stringify(userData),
      headers: { 'Content-Type': 'application/json' }
    });
    return data;
  },
  
  // Logout
  logout: () => {
    tokenManager.removeToken();
    window.location.href = '/auth';
  }
};

export default { apiRequest, tokenManager, authAPI };

