import React, { useState, useRef, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import * as faceapi from "face-api.js";
import "./RegisterVoter.css";
import { tokenManager } from "../../utils/api";

const API_BASE = "http://localhost/final_votesecure/backend/api";

// CDN fallback for face-api models
const CDN_MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';

function RegisterVoter() {
  const [formData, setFormData] = useState({
    name: "",
    email: "",
    date_of_birth: "",
    election_id: "",
  });
  const [faceImage, setFaceImage] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [cameraActive, setCameraActive] = useState(false);
  const [modelsLoaded, setModelsLoaded] = useState(false);
  const [loadingModels, setLoadingModels] = useState(true);
  const [faceDetected, setFaceDetected] = useState(false);
  const [detectionStatus, setDetectionStatus] = useState("");
  const [elections, setElections] = useState([]);
  const [loadingElections, setLoadingElections] = useState(false);
  const videoRef = useRef(null);
  const canvasRef = useRef(null);
  const overlayCanvasRef = useRef(null);
  const detectionIntervalRef = useRef(null);
  const navigate = useNavigate();

  // Fetch elections list
  useEffect(() => {
    const fetchElections = async () => {
      setLoadingElections(true);
      try {
        const response = await fetch(`${API_BASE}/get-elections.php`);
        const data = await response.json();
        if (data.success && data.elections) {
          setElections(data.elections);
        } else {
          console.error("Failed to fetch elections:", data.message);
        }
      } catch (err) {
        console.error("Error fetching elections:", err);
      } finally {
        setLoadingElections(false);
      }
    };
    fetchElections();
  }, []);

  // Load face detection models with CDN fallback
  useEffect(() => {
    const loadModels = async () => {
      setLoadingModels(true);
      setError("");
      
      let MODEL_URL = '/models';
      let usingCDN = false;

      try {
        // Try loading from local first
        console.log("📦 Loading face detection models from local /models...");
        
        const timeoutDuration = 15000;
        
        try {
          const loadPromise = Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
          ]);

          const timeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error(`Model loading timeout. Trying CDN fallback...`)), timeoutDuration)
          );

          await Promise.race([loadPromise, timeoutPromise]);
          console.log("✅ Models loaded from local");
        } catch (loadErr) {
          console.warn("⚠️ Local models failed, trying CDN...");
          MODEL_URL = CDN_MODEL_URL;
          usingCDN = true;

          const retryLoadPromise = Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
          ]);

          const retryTimeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error("CDN model loading timeout. Check your internet connection.")), 20000)
          );

          await Promise.race([retryLoadPromise, retryTimeoutPromise]);
          console.log("✅ Models loaded from CDN");
        }

        setModelsLoaded(true);
        setError("");
        console.log("✅ Face recognition models loaded successfully!");
      } catch (err) {
        console.error("❌ Error loading face models:", err);
        setError("Error loading face detection models. Please refresh the page. " + (err.message || ""));
        setModelsLoaded(false);
      } finally {
        setLoadingModels(false);
      }
    };

    loadModels();
    
    // Cleanup on unmount
    return () => {
      if (detectionIntervalRef.current) {
        clearInterval(detectionIntervalRef.current);
      }
      if (videoRef.current && videoRef.current.srcObject) {
        videoRef.current.srcObject.getTracks().forEach(track => track.stop());
      }
    };
  }, []);

  const startCamera = async () => {
    if (!modelsLoaded) {
      setError("Face detection models are still loading. Please wait...");
      return;
    }

    try {
      // Set camera active first so the modal and video element are rendered
      setCameraActive(true);
      setError("");
      setDetectionStatus("Camera starting... Please wait a moment.");
      
      // Wait for React to render the modal and video element
      await new Promise(resolve => setTimeout(resolve, 100));
      
      // Now get the video element after it's been rendered
      let video = videoRef.current;
      if (!video) {
        // Wait a bit more if needed
        await new Promise(resolve => setTimeout(resolve, 100));
        video = videoRef.current;
      }
      
      if (!video) {
        setError("Video element not found. Please refresh the page.");
        setCameraActive(false);
        return;
      }

      const stream = await navigator.mediaDevices.getUserMedia({
        video: { 
          width: { ideal: 1080 },
          height: { ideal: 1080 },
          facingMode: 'user' 
        },
        audio: false
      });
      
      // Ensure video is visible
      video.style.display = 'block';
      video.style.visibility = 'visible';
      video.style.opacity = '1';
      
      // Set the stream
      video.srcObject = stream;
      
      // Wait for video metadata to load, then start playing
      const handleLoadedMetadata = async () => {
        console.log("📹 Video metadata loaded. Starting playback...");
        console.log("📹 Video dimensions:", video.videoWidth, "x", video.videoHeight);
        
        try {
          await video.play();
          console.log("✅ Video playing successfully");
          setDetectionStatus("Camera ready! Position your face in front of the camera.");
          startRealTimeDetection();
        } catch (err) {
          console.error("❌ Error playing video:", err);
          setError("Error starting video: " + err.message);
          setCameraActive(false);
        }
        
        // Remove listener after first load
        video.removeEventListener('loadedmetadata', handleLoadedMetadata);
      };

      const handleCanPlay = async () => {
        console.log("📹 Video can play. Starting playback...");
        try {
          await video.play();
          console.log("✅ Video playing successfully");
          if (!detectionStatus || detectionStatus.includes("starting")) {
            setDetectionStatus("Camera ready! Position your face in front of the camera.");
          }
          startRealTimeDetection();
        } catch (err) {
          console.error("❌ Error playing video:", err);
        }
        video.removeEventListener('canplay', handleCanPlay);
      };

      // Add multiple event listeners to ensure video starts
      video.addEventListener('loadedmetadata', handleLoadedMetadata);
      video.addEventListener('canplay', handleCanPlay);
      
      // Also try to play immediately if video is already ready
      if (video.readyState >= 2) {
        console.log("📹 Video already ready, starting immediately");
        handleLoadedMetadata();
      } else if (video.readyState >= 1) {
        console.log("📹 Video has metadata, waiting for canplay...");
        video.addEventListener('canplay', handleCanPlay);
      }
      
      // Force play after a short delay as backup
      setTimeout(() => {
        if (video.readyState >= 2 && video.paused) {
          console.log("📹 Force playing video after timeout...");
          video.play().catch(err => {
            console.error("❌ Force play failed:", err);
          });
        }
      }, 500);

    } catch (err) {
      console.error("Error accessing camera:", err);
      let errorMsg = "Could not access camera. ";
      if (err.name === 'NotAllowedError') {
        errorMsg += "Please allow camera access in your browser settings.";
      } else if (err.name === 'NotFoundError') {
        errorMsg += "No camera found. Please connect a camera.";
      } else {
        errorMsg += err.message || "Please check permissions.";
      }
      setError(errorMsg);
      setCameraActive(false);
    }
  };

  // Real-time face detection
  const startRealTimeDetection = () => {
    if (detectionIntervalRef.current) {
      clearInterval(detectionIntervalRef.current);
    }

    detectionIntervalRef.current = setInterval(async () => {
      if (!videoRef.current || !overlayCanvasRef.current || !modelsLoaded) {
        return;
      }

      const video = videoRef.current;
      const overlayCanvas = overlayCanvasRef.current;

      if (video.readyState !== video.HAVE_ENOUGH_DATA) {
        return;
      }

      try {
        // Match canvas dimensions to video
        overlayCanvas.width = video.videoWidth;
        overlayCanvas.height = video.videoHeight;

        // Detect faces in real-time
        const detections = await faceapi
          .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
          .withFaceLandmarks();

        const ctx = overlayCanvas.getContext('2d');
        ctx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

        if (detections.length === 0) {
          setFaceDetected(false);
          setDetectionStatus("No face detected. Please position your face in front of the camera.");
        } else if (detections.length > 1) {
          setFaceDetected(false);
          setDetectionStatus("Multiple faces detected. Please ensure only one person is in the frame.");
          // Draw red boxes for multiple faces
          detections.forEach(detection => {
            faceapi.draw.drawDetections(overlayCanvas, [detection], { withScore: false });
          });
        } else {
          // One face detected
          setFaceDetected(true);
          setDetectionStatus("✓ Face detected! Click 'Capture Face' button when ready.");
          
          // Draw green detection box
          const resizedDetections = faceapi.resizeResults(detections, {
            width: overlayCanvas.width,
            height: overlayCanvas.height
          });
          
          ctx.strokeStyle = '#4CAF50';
          ctx.lineWidth = 3;
          faceapi.draw.drawDetections(overlayCanvas, resizedDetections);
          faceapi.draw.drawFaceLandmarks(overlayCanvas, resizedDetections);
        }
      } catch (err) {
        console.error("Detection error:", err);
        // Continue detection even if one frame fails
      }
    }, 200); // Check every 200ms
  };

  const stopCamera = () => {
    // Clear detection interval
    if (detectionIntervalRef.current) {
      clearInterval(detectionIntervalRef.current);
      detectionIntervalRef.current = null;
    }

    if (videoRef.current && videoRef.current.srcObject) {
      videoRef.current.srcObject.getTracks().forEach(track => track.stop());
      videoRef.current.srcObject = null;
      setCameraActive(false);
      setFaceDetected(false);
      setDetectionStatus("");
    }
  };

  const captureFace = async () => {
    if (!cameraActive || !modelsLoaded) {
      setError("Camera is not active or models are not loaded.");
      return;
    }
    
    if (!faceDetected) {
      setError("No face detected. Please ensure your face is clearly visible in the camera.");
      return;
    }
    
    const video = videoRef.current;
    
    if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) {
      setError("Video is not ready. Please wait a moment and try again.");
      return;
    }
    
    try {
      const displaySize = { width: video.videoWidth, height: video.videoHeight };
      
      // Detect face one more time to ensure we have the latest detection
      const detections = await faceapi
        .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
        .withFaceLandmarks();
      
      if (detections.length === 0) {
        setError("No face detected. Please ensure your face is clearly visible and try again.");
        return;
      }
      
      if (detections.length > 1) {
        setError("Multiple faces detected. Please ensure only one person is in the frame.");
        return;
      }
      
      // Create a temporary canvas to capture the image
      const captureCanvas = document.createElement('canvas');
      captureCanvas.width = displaySize.width;
      captureCanvas.height = displaySize.height;
      const captureCtx = captureCanvas.getContext('2d');
      
      // Draw video frame to canvas (flipped to match what user sees)
      captureCtx.translate(displaySize.width, 0);
      captureCtx.scale(-1, 1);
      captureCtx.drawImage(video, 0, 0, displaySize.width, displaySize.height);
      
      // Get face image as base64 (high quality)
      const capturedImage = captureCanvas.toDataURL('image/jpeg', 0.9);
      
      // Validate image size (should not be empty)
      if (!capturedImage || capturedImage.length < 100) {
        setError("Failed to capture image. Please try again.");
        return;
      }
      
      console.log("Face captured successfully. Image size:", capturedImage.length, "bytes");
      setFaceImage(capturedImage);
      setError("");
      setSuccess("✓ Face captured successfully! You can proceed to register the voter.");
      
      // Stop camera and detection after capture
      stopCamera();
      
    } catch (err) {
      console.error("Error capturing face:", err);
      setError("Error capturing face: " + (err.message || "Please try again."));
    }
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
    
    // Clear error when user starts typing
    if (error) setError("");
  };

  const validateForm = () => {
    const errors = {};
    
    // Validate name
    if (!formData.name.trim()) {
      errors.name = "Name is required";
    }

    // Validate email
    if (!formData.email.trim()) {
      errors.email = "Email is required";
    } else if (!formData.email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
      errors.email = "Valid email is required";
    }
    
    // Validate date of birth and check age (must be 18 or older)
    if (!formData.date_of_birth) {
      errors.date_of_birth = "Date of birth is required";
    } else {
      const birthDate = new Date(formData.date_of_birth);
      const today = new Date();
      let age = today.getFullYear() - birthDate.getFullYear();
      const monthDiff = today.getMonth() - birthDate.getMonth();
      
      if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
        age--;
      }
      
      if (age < 18) {
        errors.date_of_birth = "Voter must be at least 18 years old to be eligible to vote";
      }
    }
    
    // Validate election selection
    if (!formData.election_id) {
      errors.election_id = "Please select an election";
    }
    
    // Validate face image
    if (!faceImage) {
      errors.face_image = "Face capture is required";
    }
    
    return errors;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    const errors = validateForm();
    if (Object.keys(errors).length > 0) {
      setError(Object.values(errors)[0]);
      return;
    }
    
    setLoading(true);
    setError("");
    setSuccess("");
    
    try {
      const token = tokenManager.getToken();
      if (!token) {
        throw new Error("Not authenticated. Please log in again.");
      }
      
      // Validate face image format
      if (!faceImage || !faceImage.startsWith('data:image/')) {
        throw new Error("Invalid face image. Please capture your face again.");
      }
      
      console.log("Submitting registration with:", {
        name: formData.name,
        email: formData.email,
        date_of_birth: formData.date_of_birth,
        election_id: formData.election_id,
        face_image_length: faceImage ? faceImage.length : 0
      });
      
      const response = await fetch(`${API_BASE}/admin/register-voter.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          name: formData.name,
          email: formData.email,
          date_of_birth: formData.date_of_birth,
          election_id: formData.election_id,
          face_image: faceImage
        })
      });
      
      // Check if response is JSON
      const contentType = response.headers.get("content-type");
      let data;
      
      if (contentType && contentType.includes("application/json")) {
        data = await response.json();
      } else {
        const text = await response.text();
        console.error("Non-JSON response:", text);
        throw new Error(`Server error: ${response.status} ${response.statusText}`);
      }
      
      console.log("Registration response:", data);
      
      if (!response.ok || !data.success) {
        // Check both 'message' and 'error' fields for error details
        const errorMessage = data.error || data.message || 'Failed to register voter';
        console.error("Registration failed:", errorMessage);
        console.error("Full error response:", data);
        throw new Error(errorMessage);
      }
      
      setSuccess("Voter registered successfully! Login credentials have been sent to the voter's email.");
      setFormData({
        name: "",
        email: "",
        date_of_birth: "",
        election_id: "",
      });
      setFaceImage("");
      
      // Stop camera after successful registration
      stopCamera();
      
    } catch (err) {
      console.error("Registration error:", err);
      console.error("Error details:", {
        message: err.message,
        stack: err.stack,
        name: err.name
      });
      setError(err.message || "Failed to register voter. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="register-voter-container">
      <h2>Register New Voter</h2>
      
      {loadingModels && (
        <div className="info-message" style={{ backgroundColor: '#e3f2fd', color: '#1976d2', padding: '10px', borderRadius: '4px', marginBottom: '20px' }}>
          Loading face detection models... Please wait.
        </div>
      )}
      
      {error && <div className="error-message">{error}</div>}
      {success && <div className="success-message">{success}</div>}
      
      <form onSubmit={handleSubmit} className="voter-form">
        <div className="form-group">
          <label>Name *</label>
          <input
            type="text"
            name="name"
            value={formData.name}
            onChange={handleChange}
            placeholder="Full name"
            required
          />
        </div>

        <div className="form-group">
          <label>Email *</label>
          <input
            type="email"
            name="email"
            value={formData.email}
            onChange={handleChange}
            placeholder="voter@example.com"
            required
          />
        </div>
        
        <div className="form-group">
          <label>Date of Birth *</label>
          <input
            type="date"
            name="date_of_birth"
            value={formData.date_of_birth}
            onChange={handleChange}
            max={new Date().toISOString().split('T')[0]}
            required
          />
          <small style={{ display: 'block', marginTop: '5px', color: '#666' }}>
            Voter must be 18 years or older to be eligible to vote
          </small>
        </div>
        
        <div className="form-group">
          <label>Election *</label>
          {loadingElections ? (
            <p style={{ fontSize: '14px', color: '#666', margin: '5px 0' }}>
              Loading elections...
            </p>
          ) : (
            <>
              <select
                name="election_id"
                value={formData.election_id}
                onChange={handleChange}
                required
                style={{
                  width: '100%',
                  padding: '10px',
                  fontSize: '16px',
                  border: '1px solid #ddd',
                  borderRadius: '4px',
                  backgroundColor: '#fff'
                }}
              >
                <option value="">-- Select an Election --</option>
                {elections.map((election) => (
                  <option key={election.id} value={election.id}>
                    {election.title} {election.status && `(${election.status})`}
                  </option>
                ))}
              </select>
              {elections.length === 0 && !loadingElections && (
                <small style={{ display: 'block', marginTop: '5px', color: '#f44336' }}>
                  No elections available. Please create an election first.
                </small>
              )}
            </>
          )}
        </div>
        
        <div className="form-group">
          <label style={{ display: 'block', marginBottom: '10px' }}>
            <strong>Temporary Password</strong>
          </label>
          <p style={{ fontSize: '14px', color: '#666', margin: 0 }}>
            A random temporary password will be automatically generated and sent to the voter's email address.
          </p>
        </div>
        
        <div className="face-capture">
          <h3>Face Capture *</h3>
          <p style={{ fontSize: '14px', color: '#666', marginBottom: '15px' }}>
            Capture the voter's face using the camera. This will be used for identity verification during login.
          </p>
          
          <div style={{ textAlign: 'center', width: '100%' }}>
            <div style={{ padding: '20px' }}>
              <button 
                type="button" 
                onClick={startCamera}
                className="btn btn-secondary"
                disabled={loadingModels || !modelsLoaded}
                style={{ fontSize: '16px', padding: '12px 24px' }}
              >
                {loadingModels ? 'Loading Models...' : '📷 Start Camera'}
              </button>
              {!modelsLoaded && !loadingModels && (
                <p style={{ color: '#f44336', marginTop: '10px' }}>
                  Face detection models failed to load. Please refresh the page.
                </p>
              )}
              {modelsLoaded && (
                <p style={{ color: '#666', marginTop: '10px', fontSize: '14px' }}>
                  Click the button above to start your camera
                </p>
              )}
            </div>
          </div>
          
          {/* Camera Modal Popup */}
          {cameraActive && (
            <div className="camera-modal-overlay" onClick={(e) => {
              // Close modal if clicking on overlay (but not on the modal content)
              if (e.target.classList.contains('camera-modal-overlay')) {
                stopCamera();
              }
            }}>
              <div className="camera-modal-content" onClick={(e) => e.stopPropagation()}>
                <div className="camera-modal-header">
                  <h3 style={{ margin: 0, color: '#2c3e50', fontSize: '1.25rem' }}>Face Capture</h3>
                  <button 
                    type="button"
                    onClick={stopCamera}
                    className="camera-modal-close"
                    style={{
                      background: 'none',
                      border: 'none',
                      fontSize: '24px',
                      cursor: 'pointer',
                      color: '#666',
                      padding: '0',
                      width: '30px',
                      height: '30px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center'
                    }}
                  >
                    ×
                  </button>
                </div>
                
                <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0, overflow: 'hidden' }}>
                <div className="video-container-square" style={{ 
                  position: 'relative', 
                  margin: '10px auto',
                  width: '100%',
                  maxWidth: '450px',
                  aspectRatio: '1 / 1',
                  backgroundColor: '#000',
                  borderRadius: '10px',
                  overflow: 'hidden',
                  boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
                  border: '2px solid #ddd',
                  flexShrink: 0
                }}>
                  <video 
                    ref={videoRef} 
                    autoPlay 
                    playsInline 
                    muted
                    className="camera-feed"
                    style={{ 
                      display: 'block',
                      width: '100%',
                      height: '100%',
                      objectFit: 'cover',
                      transform: 'scaleX(-1)',
                      backgroundColor: '#000',
                      border: 'none',
                      position: 'absolute',
                      top: 0,
                      left: 0,
                      zIndex: 1
                    }}
                    onLoadedMetadata={(e) => {
                      const video = e.target;
                      console.log("✅ Video metadata loaded. Dimensions:", video.videoWidth, "x", video.videoHeight);
                    }}
                    onPlaying={(e) => {
                      const video = e.target;
                      console.log("✅ Video is now playing!");
                      console.log("✅ Video dimensions:", video.videoWidth, "x", video.videoHeight);
                      setDetectionStatus("Camera active! Position your face in the frame.");
                    }}
                    onError={(e) => {
                      console.error("❌ Video error:", e);
                      setError("Error loading video stream. Please try again.");
                    }}
                  />
                  <canvas 
                    ref={overlayCanvasRef}
                    style={{
                      position: 'absolute',
                      top: 0,
                      left: 0,
                      width: '100%',
                      height: '100%',
                      pointerEvents: 'none',
                      transform: 'scaleX(-1)',
                      borderRadius: '10px',
                      zIndex: 2
                    }}
                  />
                  {(!videoRef.current?.srcObject?.active || videoRef.current?.readyState < 2) && (
                    <div style={{
                      position: 'absolute',
                      top: '50%',
                      left: '50%',
                      transform: 'translate(-50%, -50%)',
                      color: '#fff',
                      textAlign: 'center',
                      backgroundColor: 'rgba(0,0,0,0.8)',
                      padding: '20px',
                      borderRadius: '8px',
                      zIndex: 10
                    }}>
                      <p style={{ margin: 0 }}>📷 Starting camera...</p>
                      <p style={{ margin: '10px 0 0 0', fontSize: '12px' }}>Please wait</p>
                    </div>
                  )}
                </div>
                
                {detectionStatus && (
                  <div style={{ 
                    margin: '10px 0',
                    padding: '8px 10px',
                    backgroundColor: faceDetected ? '#d4edda' : '#fff3cd',
                    color: faceDetected ? '#155724' : '#856404',
                    borderRadius: '6px',
                    textAlign: 'center',
                    fontWeight: '500',
                    fontSize: '14px',
                    flexShrink: 0
                  }}>
                    {detectionStatus}
                  </div>
                )}
                
                <div className="camera-controls" style={{ marginTop: '10px', flexShrink: 0 }}>
                  <button 
                    type="button" 
                    onClick={captureFace}
                    className="btn btn-primary"
                    disabled={!cameraActive || !modelsLoaded || !faceDetected}
                    style={{
                      backgroundColor: faceDetected ? '#28a745' : '#6c757d',
                      fontSize: '16px',
                      padding: '12px 24px',
                      fontWeight: 'bold',
                      marginRight: '10px'
                    }}
                  >
                    {faceDetected ? '✓ Capture Face' : 'Waiting for Face...'}
                  </button>
                  <button 
                    type="button" 
                    onClick={stopCamera}
                    className="btn btn-secondary"
                  >
                    Cancel
                  </button>
                </div>
                </div>
              </div>
            </div>
          )}
          
          {faceImage && (
            <div className="face-preview" style={{ 
              marginTop: '20px', 
              padding: '15px', 
              backgroundColor: '#d4edda',
              borderRadius: '8px',
              border: '2px solid #28a745'
            }}>
              <p style={{ fontWeight: 'bold', marginBottom: '10px', color: '#155724', fontSize: '16px' }}>
                ✓ Face Captured Successfully
              </p>
              <img 
                src={faceImage} 
                alt="Captured face" 
                className="captured-face"
                style={{ 
                  maxWidth: '300px', 
                  width: '100%',
                  borderRadius: '8px', 
                  marginTop: '10px',
                  border: '3px solid #28a745',
                  boxShadow: '0 4px 8px rgba(0,0,0,0.2)'
                }}
              />
              <button 
                type="button"
                onClick={() => {
                  setFaceImage("");
                  setSuccess("");
                }}
                className="btn btn-secondary"
                style={{ marginTop: '10px' }}
              >
                Retake Photo
              </button>
            </div>
          )}
        </div>
        
        <div className="form-actions">
          <button 
            type="submit" 
            className="btn btn-primary"
            disabled={loading || loadingModels || !modelsLoaded}
          >
            {loading ? 'Registering...' : 'Register Voter'}
          </button>
        </div>
      </form>
    </div>
  );
}

export default RegisterVoter;
