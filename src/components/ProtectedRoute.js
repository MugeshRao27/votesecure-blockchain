import React from "react";
import { Navigate } from "react-router-dom";
import { tokenManager } from "../utils/api";

const ProtectedRoute = ({ children, requiredRole }) => {
  const isLoggedIn = localStorage.getItem("isLoggedIn");
  const role = localStorage.getItem("role");
  const hasToken = tokenManager.hasToken();

  if (!isLoggedIn || !hasToken) {
    return <Navigate to="/auth" replace />;
  }

  if (requiredRole && role?.toLowerCase() !== requiredRole.toLowerCase()) {
    const normalizedRole = role?.toLowerCase();
    if (normalizedRole === "admin") {
      return <Navigate to="/admin" replace />;
    }
    if (normalizedRole === "voter") {
      return <Navigate to="/userdashboard" replace />;
    }
    return <Navigate to="/auth" replace />;
  }

  return children;
};

export default ProtectedRoute;

