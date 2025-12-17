import React, { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import FaceVerification from "./FaceVerification";
import "./AuthPage.css";
import { authAPI, tokenManager } from "../utils/api";

const API_BASE = "http://localhost/final_votesecure/backend/api";

const AuthPage = () => {
  const navigate = useNavigate();
  const [role, setRole] = useState("voter");
  const [formState, setFormState] = useState({ email: "", password: "" });
  const [otpCode, setOtpCode] = useState("");
  const [loginStage, setLoginStage] = useState("credentials");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [info, setInfo] = useState("");
  const [pendingLoginToken, setPendingLoginToken] = useState("");
  const [pendingJwt, setPendingJwt] = useState("");
  const [pendingUser, setPendingUser] = useState(null);
  const [showPassword, setShowPassword] = useState(false);
  
  // COMMENTED OUT - Password change functionality removed
  // const [requiresPasswordChange, setRequiresPasswordChange] = useState(false);
  // const [passwordChangeToken, setPasswordChangeToken] = useState("");
  // const [newPassword, setNewPassword] = useState("");
  // const [confirmPassword, setConfirmPassword] = useState("");
  const [showFaceModal, setShowFaceModal] = useState(false);

  // Prevent body scrolling when on auth page
  useEffect(() => {
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = 'unset';
    };
  }, []);

  const resetSession = () => {
    setLoginStage("credentials");
    setOtpCode("");
    setPendingLoginToken("");
    setPendingJwt("");
    setPendingUser(null);
    // COMMENTED OUT - Password change functionality removed
    // setRequiresPasswordChange(false);
    // setPasswordChangeToken("");
    // setNewPassword("");
    // setConfirmPassword("");
    setShowFaceModal(false);
    setInfo("");
    tokenManager.removeToken();
  };

  const finalizeSession = (token, user) => {
    if (!token || !user) {
      setError("Missing session details. Please log in again.");
      resetSession();
      return;
    }

    const normalizedRole = user.role?.toLowerCase() || role;

    tokenManager.setToken(token);
    localStorage.setItem("isLoggedIn", "true");
    localStorage.setItem("role", normalizedRole);
    localStorage.setItem("user", JSON.stringify(user));

    if (normalizedRole === "admin") {
      navigate("/admin");
    } else {
      navigate("/userdashboard");
    }
  };

  const handleRoleChange = (newRole) => {
    if (newRole === role) return;
    setRole(newRole);
    setFormState({ email: "", password: "" });
    setError("");
    setInfo("");
    resetSession();
  };

  const handleCredentialsSubmit = async (event) => {
    event.preventDefault();
    if (loading) return;

    setLoading(true);
    setError("");
    setInfo("");

    try {
      const data = await authAPI.login(
        formState.email.trim(),
        formState.password,
        role
      );

      if (!data.success) {
        setError(data.message || "Login failed. Please check your credentials.");
        return;
      }

      if (role === "admin") {
        if (!data.login_token) {
          setError("Missing OTP token. Please try logging in again.");
          return;
        }
        setPendingUser(data.user || null);
        setPendingLoginToken(data.login_token);
        setLoginStage("admin-otp");
        setInfo("Password verified. Enter the OTP sent to your email.");
        return;
      }

      // VOTER LOGIN FLOW: Email/Password → Face Verification
      if (role === "voter") {
        // After password validation, store JWT and user data, then show face verification
        if (!data.token || !data.user) {
          setError("Missing session details. Please try logging in again.");
          return;
        }
        
        setPendingJwt(data.token);
        setPendingUser(data.user);
        setShowFaceModal(true);
        setInfo("Password verified. Please complete face verification to continue.");
        return;
      }
    } catch (err) {
      console.error("Login error details:", err);
      const errorMessage = err.message || err.toString() || "Login error. Please try again.";
      setError(errorMessage);
      
      // If it's a network error, provide helpful message
      if (err.message && (err.message.includes('fetch') || err.message.includes('network'))) {
        setError("Network error. Please check your connection and try again.");
      }
    } finally {
      setLoading(false);
    }
  };

  const handleAdminOtpSubmit = async (event) => {
    event.preventDefault();
    if (loading) return;
    if (!pendingLoginToken) {
      setError("OTP token missing. Please restart the login process.");
      resetSession();
      return;
    }

    setLoading(true);
    setError("");
    try {
      const data = await authAPI.verifyOTP(
        formState.email.trim(),
        otpCode.trim(),
        pendingLoginToken,
        "admin"
      );

      if (!data.success) {
        setError(data.message || "Invalid OTP. Please try again.");
        return;
      }

      finalizeSession(data.token, data.user);
    } catch (err) {
      setError(err.message || "OTP verification failed. Please try again.");
    } finally {
      setLoading(false);
    }
  };


  const handleFaceVerified = async (faceData) => {
    if (loading) return;
    
    setLoading(true);
    setError("");
    setInfo("Verifying face with server...");

    if (!pendingJwt || !pendingUser) {
      setError("Session expired. Please log in again.");
      resetSession();
      setLoading(false);
      return;
    }

    try {
      // Send face image to backend for verification
      const response = await fetch(`${API_BASE}/auth/verify-face.php`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Authorization": `Bearer ${pendingJwt}`
        },
        body: JSON.stringify({
          email: pendingUser.email,
          face_image: faceData.imageBase64 || faceData
        }),
      });

      const data = await response.json();

      if (!data.success) {
        setError(data.message || "Face verification failed. Please try again.");
        setShowFaceModal(true); // Keep modal open for retry
        setLoading(false);
        return;
      }

      // Face verified successfully
      setShowFaceModal(false);
      setInfo("Face verified successfully! Redirecting to dashboard...");

      // Update user data to mark account as active (password change not required)
      // Ensure role is preserved correctly
      const updatedUser = {
        ...pendingUser,
        role: pendingUser.role || 'voter', // Ensure role is set
        account_status: 'ACTIVE',
        password_changed: true // Mark as changed since face verification is sufficient
      };

      // Finalize session and redirect to user dashboard
      // This will save the token, user data, and navigate to /userdashboard
      finalizeSession(pendingJwt, updatedUser);
    } catch (err) {
      setError(err.message || "Face verification error. Please try again.");
      setShowFaceModal(true); // Keep modal open for retry
    } finally {
      setLoading(false);
    }
  };

  const handleFaceCancelled = () => {
    setShowFaceModal(false);
    setError("Face verification cancelled. Please log in again.");
    resetSession();
  };

  // COMMENTED OUT - Password change functionality removed
  // const handlePasswordChangeSubmit = async (event) => {
  //   event.preventDefault();
  //   if (loading) return;

  //   if (!passwordChangeToken) {
  //     console.error("❌ Password change token missing. Current state:", {
  //       passwordChangeToken,
  //       requiresPasswordChange,
  //       loginStage
  //     });
  //     setError("Password change token missing. Please log in again.");
  //     resetSession();
  //     return;
  //   }

  //   if (newPassword.length < 8) {
  //     setError("New password must be at least 8 characters long.");
  //     return;
  //   }

  //   if (newPassword !== confirmPassword) {
  //     setError("Passwords do not match.");
  //     return;
  //   }

  //   setLoading(true);
  //   setError("");
  //   setInfo("");

  //   console.log("🔐 Submitting password change with token:", passwordChangeToken ? "Token exists" : "Token missing");

  //   try {
  //     const response = await fetch(`${API_BASE}/auth/change-password.php`, {
  //       method: "POST",
  //       headers: { "Content-Type": "application/json" },
  //       body: JSON.stringify({
  //         token: passwordChangeToken,
  //         new_password: newPassword,
  //         confirm_password: confirmPassword,
  //       }),
  //     });
      
  //     console.log("🔐 Password change response status:", response.status);
      
  //     // Check if response has content
  //     const contentType = response.headers.get("content-type");
  //     console.log("🔐 Response content-type:", contentType);
      
  //     let data;
  //     const responseText = await response.text();
  //     console.log("🔐 Response text (first 500 chars):", responseText.substring(0, 500));
      
  //     if (contentType && contentType.includes("application/json")) {
  //       try {
  //         data = JSON.parse(responseText);
  //       } catch (parseError) {
  //         console.error("❌ JSON parse error:", parseError);
  //         console.error("❌ Response text:", responseText);
  //         setError("Invalid response from server. Please try again.");
  //         return;
  //       }
  //     } else {
  //       console.error("❌ Non-JSON response:", responseText);
  //       setError("Server returned an invalid response. Please try again.");
  //       return;
  //     }
      
  //     console.log("🔐 Password change response:", data);

  //     if (!data.success) {
  //       console.error("❌ Password change failed:", data.message);
  //       setError(data.message || "Unable to update password. Please try again.");
  //       return;
  //     }

  //     // Use updated user from backend response if available, otherwise merge with pendingUser
  //     const updatedUser = data.user ? {
  //       ...data.user,
  //       password_changed: 1,
  //       account_status: data.account_status || 'ACTIVE'
  //     } : {
  //       ...(pendingUser || {}),
  //       password_changed: 1,
  //       temp_password: null,
  //       account_status: 'ACTIVE'
  //     };
      
  //     // Ensure account_status is set
  //     if (!updatedUser.account_status) {
  //       updatedUser.account_status = 'ACTIVE';
  //     }

  //     const freshToken = data.token || pendingJwt;
  //     // Update localStorage with new user data
  //     localStorage.setItem("user", JSON.stringify(updatedUser));
  //     finalizeSession(freshToken, updatedUser);
  //   } catch (err) {
  //     setError(err.message || "Password change failed. Please try again.");
  //   } finally {
  //     setLoading(false);
  //   }
  // };

  const renderCredentialForm = () => (
    <form className="auth-form" onSubmit={handleCredentialsSubmit}>
      <div className="form-group">
        <label>Email</label>
        <input
          type="email"
          value={formState.email}
          onChange={(e) =>
            setFormState((prev) => ({ ...prev, email: e.target.value }))
          }
          required
        />
      </div>

      <div className="form-group">
        <label>{role === "admin" ? "Admin Password" : "Temporary Password"}</label>
        <div className="password-input-wrapper">
          <input
            type={showPassword ? "text" : "password"}
            value={formState.password}
            onChange={(e) =>
              setFormState((prev) => ({ ...prev, password: e.target.value }))
            }
            required
          />
          <button
            type="button"
            className="password-toggle-btn"
            onClick={() => setShowPassword(!showPassword)}
            aria-label={showPassword ? "Hide password" : "Show password"}
          >
            <i className={`fas ${showPassword ? "fa-eye-slash" : "fa-eye"}`}></i>
          </button>
        </div>
      </div>

      {role === "voter" && (
        <div className="form-group" style={{ fontSize: 13, color: "#555" }}>
          <strong>What happens next?</strong>
          <ol style={{ paddingLeft: 18, marginTop: 8 }}>
            <li>Enter your email and password</li>
            <li>Complete face verification</li>
            <li>Access the election page and vote</li>
          </ol>
        </div>
      )}

      <button type="submit" className="auth-button" disabled={loading}>
        {loading ? "Processing..." : role === "admin" ? "Continue" : "Login & Verify"}
      </button>
    </form>
  );

  const renderOtpForm = () => {
    // Only for admin OTP
    return (
      <form className="auth-form" onSubmit={handleAdminOtpSubmit}>
        <div className="form-group">
          <label>OTP</label>
          <input
            type="text"
            maxLength={6}
            value={otpCode}
            onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, ""))}
            required
          />
          <span className="error-text">Enter the 6-digit code emailed to you.</span>
        </div>

        <button type="submit" className="auth-button" disabled={loading}>
          {loading ? "Verifying..." : "Verify & Continue"}
        </button>

        <button
          type="button"
          className="auth-button"
          style={{ backgroundColor: "#e0e0e0", color: "#333" }}
          onClick={resetSession}
          disabled={loading}
        >
          Start Over
        </button>
      </form>
    );
  };

  // COMMENTED OUT - Password change functionality removed
  // const renderPasswordChangeForm = () => (
  //   <form className="auth-form" onSubmit={handlePasswordChangeSubmit}>
  //     <div className="form-group">
  //       <label>New Password</label>
  //       <input
  //         type="password"
  //         value={newPassword}
  //         onChange={(e) => setNewPassword(e.target.value)}
  //         required
  //       />
  //     </div>
  //     <div className="form-group">
  //       <label>Confirm Password</label>
  //       <input
  //         type="password"
  //         value={confirmPassword}
  //         onChange={(e) => setConfirmPassword(e.target.value)}
  //         required
  //       />
  //     </div>

  //     <button type="submit" className="auth-button" disabled={loading}>
  //       {loading ? "Updating..." : "Save Password & Continue"}
  //     </button>
  //   </form>
  // );

  const renderActiveStage = () => {
    // Only show OTP form for admin
    if (loginStage === "admin-otp") {
      return renderOtpForm();
    }

    // COMMENTED OUT - Password change functionality removed
    // if (loginStage === "password-change") {
    //   return renderPasswordChangeForm();
    // }

    return renderCredentialForm();
  };

  return (
    <div className="auth-page-wrapper">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h2>
              {role === "admin" ? "Admin Secure Login" : "Voter Multi-Step Login"}
            </h2>
            <p>
              {role === "admin"
                ? "Enter your credentials and complete OTP verification to reach the dashboard."
                : "Log in with your email and password, then complete face verification to access the voting dashboard."}
            </p>
          </div>

          <div className="auth-switch" style={{ marginBottom: 16 }}>
            <span
              className="auth-link"
              style={{ marginRight: 12, fontWeight: role === "voter" ? 700 : 500 }}
              onClick={() => handleRoleChange("voter")}
            >
              Voter Login
            </span>
            <span
              className="auth-link"
              style={{ fontWeight: role === "admin" ? 700 : 500 }}
              onClick={() => handleRoleChange("admin")}
            >
              Admin Login
            </span>
          </div>

          {error && (
            <div
              style={{
                background: "#fdecea",
                color: "#e74c3c",
                padding: "10px 14px",
                borderRadius: 10,
                marginBottom: 16,
                fontSize: 14,
              }}
            >
              {error}
            </div>
          )}

          {info && (
            <div
              style={{
                background: "#edf3ff",
                color: "#1b4db1",
                padding: "10px 14px",
                borderRadius: 10,
                marginBottom: 16,
                fontSize: 14,
              }}
            >
              {info}
            </div>
          )}

          {renderActiveStage()}
        </div>
      </div>

      {showFaceModal && pendingUser && (
        <FaceVerification
          user={pendingUser}
          onVerify={handleFaceVerified}
          onCancel={handleFaceCancelled}
          title="Face Verification Required"
          subtitle="Capture your face live so we can match it with the image saved during registration."
        />
      )}
    </div>
  );
};

export default AuthPage;

