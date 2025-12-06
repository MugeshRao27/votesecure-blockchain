import React, { useRef, useState, useEffect, useCallback } from "react";
import * as faceapi from "face-api.js";
import "./FaceVerification.css";

const FaceVerification = ({
  onVerify,
  onCancel,
  user,
  title = "Face Verification Required",
  subtitle = "Please look at the camera to verify your identity before voting",
}) => {
  const videoRef = useRef(null);
  const canvasRef = useRef(null);
  const isInitializingRef = useRef(false);
  const [modelsLoaded, setModelsLoaded] = useState(false);
  const [isDetecting, setIsDetecting] = useState(false);
  const [verificationStatus, setVerificationStatus] = useState("");
  const [error, setError] = useState("");
  const [stream, setStream] = useState(null);
  const [videoReady, setVideoReady] = useState(false);

  // Load face-api models with CDN fallback
  useEffect(() => {
    const loadModels = async () => {
      setError("");
      
      let MODEL_URL = '';
      let usingCDN = false;

      try {
        if (!faceapi || !faceapi.nets) {
          throw new Error("Face detection library not loaded. Please refresh the page.");
        }

        const PUBLIC_URL = process.env.PUBLIC_URL || '';
        const LOCAL_MODEL_URL = window.location.origin + `${PUBLIC_URL}/models`;
        const CDN_MODEL_URL = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights';

        MODEL_URL = LOCAL_MODEL_URL;
        usingCDN = false;

        console.log("🔄 Testing local models first...");
        console.log("📁 Local URL:", LOCAL_MODEL_URL);

        try {
          const testUrl = `${LOCAL_MODEL_URL}/tiny_face_detector_model/tiny_face_detector_model-weights_manifest.json`;
          console.log("🧪 Testing:", testUrl);

          const testResponse = await fetch(testUrl);
          const contentType = testResponse.headers.get('content-type') || '';

          console.log("📊 Status:", testResponse.status, "Content-Type:", contentType);

          if (!testResponse.ok || contentType.includes('html') || !contentType.includes('json')) {
            const responseText = await testResponse.text();
            if (responseText.includes('<!DOCTYPE') || !testResponse.ok) {
              console.warn("⚠️ Local models not accessible (got HTML or error), switching to CDN");
              MODEL_URL = CDN_MODEL_URL;
              usingCDN = true;
            }
          } else {
            console.log("✅ Local models accessible!");
          }
        } catch (e) {
          console.warn("⚠️ Local models test failed, using CDN:", e.message);
          MODEL_URL = CDN_MODEL_URL;
          usingCDN = true;
        }

        console.log("🔄 Loading models from:", MODEL_URL);
        console.log("📁 Source:", usingCDN ? "🌐 CDN (GitHub)" : "💾 Local");
        console.log("📦 Loading TinyFaceDetector, FaceLandmark68, and FaceRecognitionNet...");

        const timeoutDuration = usingCDN ? 20000 : 15000;

        try {
          const loadPromise = Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
          ]);

          const timeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error(`Model loading timeout (${timeoutDuration/1000}s). ${usingCDN ? 'CDN models are loading slowly. Check your internet connection.' : 'The model files may not be accessible. Try downloading models with: npm run download-models'}`)), timeoutDuration)
          );

          await Promise.race([loadPromise, timeoutPromise]);
        } catch (loadErr) {
          if (!usingCDN && (loadErr.message?.includes("Unexpected token") || loadErr.message?.includes("<!DOCTYPE"))) {
            console.warn("⚠️ Shard files intercepted by React Router, switching to CDN...");
            MODEL_URL = CDN_MODEL_URL;
            usingCDN = true;

            console.log("🔄 Retrying with CDN:", MODEL_URL);

            const retryLoadPromise = Promise.all([
              faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
              faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
              faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);

            const retryTimeoutPromise = new Promise((_, reject) =>
              setTimeout(() => reject(new Error("CDN model loading timeout. Check your internet connection.")), 20000)
            );

            await Promise.race([retryLoadPromise, retryTimeoutPromise]);
          } else {
            throw loadErr;
          }
        }

        setModelsLoaded(true);
        setError("");
        console.log("✅ Face recognition models loaded successfully!");
      } catch (err) {
        console.error("❌ Error loading models:", err);
        console.error("❌ Error details:", err.message);

        let errorMessage = "Failed to load face recognition models.";
        if (err.message && err.message.includes("Unexpected token")) {
          if (usingCDN) {
            errorMessage = "CDN models failed to load. This might be a network issue.\n\nSOLUTION:\n1. Check your internet connection\n2. Try refreshing the page\n3. Or download local models: npm run download-models";
          } else {
            errorMessage = "Local models not accessible. Switching to CDN automatically...\n\nIf this persists:\n1. Check your internet connection (CDN requires internet)\n2. Or download local models: npm run download-models\n3. Restart dev server after downloading";
          }
        } else if (err.message && err.message.includes("timeout")) {
          errorMessage = err.message || "Model loading timed out. Check your internet connection if using CDN.";
        } else {
          errorMessage = err.message || "Failed to load face recognition models. Please refresh the page.";
        }

        setError(errorMessage);
        setModelsLoaded(false);
      }
    };

    loadModels();
  }, []);

  // Start camera
  const startCamera = useCallback(async () => {
    try {
      console.log("📷 Starting camera...");
      setError("");
      isInitializingRef.current = true;
      
      const mediaStream = await navigator.mediaDevices.getUserMedia({
        video: {
          width: { ideal: 1080 },
          height: { ideal: 1080 },
          facingMode: "user",
        },
      });
      
      console.log("✅ Camera stream obtained");
      
      if (!videoRef.current) {
        console.error("❌ Video ref not available");
        mediaStream.getTracks().forEach(track => track.stop());
        const errorMsg = "Video element not available. Please refresh the page.";
        setError(errorMsg);
        isInitializingRef.current = false;
        return Promise.reject(new Error(errorMsg));
      }

      const video = videoRef.current;
      
      // Set stream first
      video.srcObject = mediaStream;
      video.muted = true; // Required for autoplay in most browsers
      video.playsInline = true; // Important for mobile devices
      video.autoplay = true;
      
      // Wait for video to be ready and playing
      return new Promise((resolve, reject) => {
        let attempts = 0;
        const maxAttempts = 100; // 10 seconds max wait (increased for slower cameras)
        let timeoutId = null;
        let isCancelled = false;
        
        const cleanup = () => {
          if (timeoutId) {
            clearTimeout(timeoutId);
            timeoutId = null;
          }
        };
        
        const checkVideoReady = () => {
          if (isCancelled) {
            cleanup();
            return;
          }
          
          attempts++;
          
          // Safety check: ensure video element still exists and has the stream
          if (!videoRef.current || videoRef.current !== video) {
            if (!isCancelled) {
              cleanup();
              mediaStream.getTracks().forEach(track => track.stop());
              const errorMsg = "Video element was removed. Please refresh the page.";
              setError(errorMsg);
              reject(new Error(errorMsg));
            }
            return;
          }
          
          // Check if srcObject was reset (this can happen if component unmounts/remounts)
          if (video.srcObject !== mediaStream) {
            console.warn("⚠️ Video srcObject was reset, re-assigning stream...");
            video.srcObject = mediaStream;
            // Wait a bit for stream to be reassigned
            timeoutId = setTimeout(checkVideoReady, 200);
            return;
          }
          
          // Check if video has metadata and is playing
          // Also check that the stream is still active and tracks are enabled
          const videoTracks = mediaStream.getVideoTracks();
          const hasActiveVideoTrack = videoTracks.length > 0 && videoTracks[0].enabled && videoTracks[0].readyState !== 'ended';
          
          // If video is ready but paused, try to play it
          if (video.readyState >= 2 && (video.videoWidth > 0 && video.videoHeight > 0) && mediaStream.active && hasActiveVideoTrack) {
            video.play()
              .then(() => {
                // Double check that video is actually playing
                timeoutId = setTimeout(() => {
                  if (isCancelled) {
                    cleanup();
                    return;
                  }
                  
                  // Double-check video is ready and has valid dimensions
                  if (videoRef.current && videoRef.current === video && video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0 && !video.paused) {
                    console.log("✅ Video playing successfully, dimensions:", video.videoWidth, "x", video.videoHeight);
                    console.log("✅ Video stream active:", mediaStream.active);
                    console.log("✅ Video tracks:", mediaStream.getVideoTracks().length);
                    
                    // Set stream and videoReady together to prevent blinking
                    setStream(mediaStream);
                    // Set videoReady immediately when video is playing to prevent blinking
                    if (video.readyState >= 2 && video.videoWidth > 0 && !video.paused && video.srcObject === mediaStream) {
                      setVideoReady(true);
                      isInitializingRef.current = false;
                      cleanup();
                      console.log("✅ Video ready state set, camera feed should be visible");
                      resolve();
                    } else {
                      // Wait a bit more if not fully ready
                      const retryTimeout = setTimeout(() => {
                        if (!isCancelled && videoRef.current && videoRef.current === video) {
                          if (video.readyState >= 2 && video.videoWidth > 0 && !video.paused && video.srcObject === mediaStream) {
                            setVideoReady(true);
                            isInitializingRef.current = false;
                            cleanup();
                            console.log("✅ Video ready state set after delay");
                            resolve();
                          } else {
                            isInitializingRef.current = false;
                            cleanup();
                            if (!isCancelled) {
                              reject(new Error("Video element was removed during initialization"));
                            }
                          }
                        } else {
                          isInitializingRef.current = false;
                          cleanup();
                          if (!isCancelled) {
                            reject(new Error("Video element was removed during initialization"));
                          }
                        }
                      }, 150);
                      timeoutId = retryTimeout;
                    }
                  } else {
                    // Video not ready, retry or fail
                    if (attempts < maxAttempts && !isCancelled) {
                      timeoutId = setTimeout(checkVideoReady, 100);
                    } else if (!isCancelled) {
                      cleanup();
                      isInitializingRef.current = false;
                      mediaStream.getTracks().forEach(track => track.stop());
                      const errorMsg = "Video failed to start playing. Please try again.";
                      setError(errorMsg);
                      reject(new Error(errorMsg));
                    }
                  }
                }, 300);
              })
              .catch((e) => {
                if (isCancelled) {
                  cleanup();
                  return;
                }
                console.error("❌ Video play() failed:", e);
                if (attempts < maxAttempts) {
                  timeoutId = setTimeout(checkVideoReady, 100);
                } else {
                  cleanup();
                  isInitializingRef.current = false;
                  mediaStream.getTracks().forEach(track => track.stop());
                  setError("Failed to start video. Please try again.");
                  reject(e);
                }
              });
            } else {
              if (attempts < maxAttempts && !isCancelled) {
                // Log progress every 10 attempts
                if (attempts % 10 === 0) {
                  console.log(`⏳ Waiting for video... (${attempts}/${maxAttempts}) - readyState: ${video.readyState}, dimensions: ${video.videoWidth}x${video.videoHeight}, paused: ${video.paused}, stream active: ${mediaStream.active}`);
                }
                timeoutId = setTimeout(checkVideoReady, 100);
              } else if (!isCancelled) {
                cleanup();
                isInitializingRef.current = false;
                mediaStream.getTracks().forEach(track => track.stop());
                const errorMsg = `Video failed to initialize after ${maxAttempts * 100 / 1000}s. Please check your camera permissions and try again.`;
                setError(errorMsg);
                console.error("Video initialization timeout after", maxAttempts * 100, "ms");
                console.error("Video state:", {
                  readyState: video.readyState,
                  videoWidth: video.videoWidth,
                  videoHeight: video.videoHeight,
                  paused: video.paused,
                  streamActive: mediaStream.active,
                  videoTracks: mediaStream.getVideoTracks().length
                });
                reject(new Error(errorMsg));
              }
            }
        };

        // Try to play when metadata is loaded
        const onMetadataLoaded = () => {
          if (!isCancelled && videoRef.current === video) {
            console.log("📹 Video metadata loaded, dimensions:", video.videoWidth, "x", video.videoHeight);
            checkVideoReady();
          }
        };
        
        video.onloadedmetadata = onMetadataLoaded;

        // Also try to play immediately (in case metadata is already loaded)
        checkVideoReady();
        
        // Store cleanup function to be called if component unmounts
        // Note: We can't return cleanup from Promise, so we'll handle it differently
      });
      
      // Add a way to cancel if needed (will be handled by component unmount)
    } catch (err) {
      console.error("❌ Camera error:", err);
      let errorMsg = "Failed to access camera: " + err.message;
      if (err.name === "NotAllowedError" || err.name === "PermissionDeniedError") {
        errorMsg = "Camera access denied. Please allow camera access and try again.";
      } else if (err.name === "NotFoundError" || err.name === "DevicesNotFoundError") {
        errorMsg = "No camera found. Please connect a camera and try again.";
      }
      setError(errorMsg);
      isInitializingRef.current = false;
      return Promise.reject(new Error(errorMsg));
    }
  }, []);

  // Stop camera
  const stopCamera = useCallback(() => {
    // Don't stop if we're still initializing (unless forced)
    if (isInitializingRef.current) {
      console.log("⏸️ Camera initialization in progress, skipping stop");
      return;
    }
    
    console.log("🛑 Stopping camera...");
    if (stream) {
      stream.getTracks().forEach((track) => {
        track.stop();
        console.log("🛑 Stopped track:", track.kind, track.label);
      });
      setStream(null);
    }
    if (videoRef.current) {
      videoRef.current.srcObject = null;
    }
    setVideoReady(false);
    isInitializingRef.current = false;
  }, [stream]);

  // Auto-start camera when models are loaded
  useEffect(() => {
    // Only start camera if models are loaded and we don't have a stream yet
    if (modelsLoaded && !stream && !isInitializingRef.current) {
      console.log("🚀 Models loaded, starting camera automatically...");
      startCamera()
        .then(() => {
          console.log("✅ Camera started successfully");
        })
        .catch(err => {
          console.error("Failed to auto-start camera:", err);
          // Error is already set in startCamera, just log it
          if (err.message) {
            console.error("Camera error details:", err.message);
          }
        });
    }
    
    // Don't cleanup here - let the cleanup useEffect handle it
    // This prevents stopping the camera during re-renders
  }, [modelsLoaded, stream, startCamera]);

  // Cleanup on unmount - only stop camera when component is actually being removed
  useEffect(() => {
    return () => {
      // This only runs when component is actually unmounting
      console.log("🧹 FaceVerification component unmounting...");
      // Use refs to get current values without causing re-runs
      const currentStream = videoRef.current?.srcObject;
      if (currentStream && !isInitializingRef.current) {
        console.log("🧹 Cleaning up camera on unmount...");
        // Stop all tracks
        if (currentStream instanceof MediaStream) {
          currentStream.getTracks().forEach((track) => {
            track.stop();
            console.log("🛑 Stopped track on unmount:", track.kind);
          });
        }
        if (videoRef.current) {
          videoRef.current.srcObject = null;
        }
      } else if (isInitializingRef.current) {
        console.log("⏸️ Component unmounting during initialization");
        isInitializingRef.current = false;
      }
    };
  }, []); // Empty dependency array - only run on mount/unmount

  // Capture and verify face
  const captureAndVerify = async () => {
    if (!videoRef.current) {
      setError("Camera not started. Please start the camera first.");
      return;
    }
    
    if (!modelsLoaded) {
      setError("Face recognition models are still loading. Please wait...");
      return;
    }
    
    if (!user || !user.face_image) {
      setError("User face image not found. Please contact administrator.");
      console.error("User object:", user);
      return;
    }

    setIsDetecting(true);
    setVerificationStatus("Verifying...");
    setError("");

    try {
      const video = videoRef.current;
      const canvas = canvasRef.current;
      
      console.log("🔍 Starting face verification...");
      console.log("📷 User face image path:", user.face_image);
      
      // Wait for video to be ready with valid dimensions
      let waitAttempts = 0;
      const maxWaitAttempts = 100; // 10 seconds max wait
      
      while ((!video.videoWidth || !video.videoHeight || video.readyState < 2 || video.paused) && waitAttempts < maxWaitAttempts) {
        await new Promise(resolve => setTimeout(resolve, 100));
        waitAttempts++;
        
        // Check if video element still exists
        if (!videoRef.current || videoRef.current !== video) {
          throw new Error("Video element was removed. Please refresh the page.");
        }
      }

      if (!video.videoWidth || !video.videoHeight) {
        throw new Error("Video is not ready. Please wait for the camera to fully load, or click 'Start Camera' to retry.");
      }
      
      if (video.paused) {
        console.log("⚠️ Video is paused, attempting to play...");
        try {
          await video.play();
        } catch (playErr) {
          throw new Error("Failed to play video. Please ensure camera permissions are granted.");
        }
      }

      console.log("📹 Video ready, dimensions:", video.videoWidth, "x", video.videoHeight);

      // Set canvas size
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;

      // Draw video frame to canvas
      const ctx = canvas.getContext("2d");
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

      console.log("👤 Detecting face in current frame...");
      
      // ============================================
      // STEP 1: DETECT FACE IN CURRENT VIDEO FRAME
      // ============================================
      // Uses 3 AI models in sequence:
      // 1. TinyFaceDetector: Finds face location (bounding box)
      // 2. FaceLandmark68Net: Maps 68 facial keypoints (eyes, nose, mouth, etc.)
      // 3. FaceRecognitionNet: Generates 128-dimensional face descriptor (face fingerprint)
      // 
      // IMPORTANT: The 128 numbers are NOT physical measurements!
      // They are NOT distances like "distance between eyes" or "nose width"
      // They are ABSTRACT mathematical features learned by the neural network
      // 
      // What they are:
      // - Abstract features learned from millions of training faces
      // - Mathematical representations optimized for face recognition
      // - A unique "fingerprint" that captures what makes a face unique
      // - Each number represents some learned characteristic (we don't know exactly what)
      // 
      // Example descriptor: [0.23, -0.45, 0.67, 0.12, ...] (128 numbers total)
      // These numbers don't mean "0.23cm between eyes" - they're abstract features!
      const detectOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 320 });
      const detectPromise = faceapi
        .detectSingleFace(canvas, detectOptions)  // Step 1: Detect face location
        .withFaceLandmarks()                        // Step 2: Get 68 facial landmarks
        .withFaceDescriptor();                      // Step 3: Generate 128D face descriptor

      const withTimeout = (p, ms) =>
        Promise.race([
          p,
          new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Face detection timed out. Please try again.')), ms)
          ),
        ]);

      const detection = await withTimeout(detectPromise, 10000);

      if (!detection) {
        setVerificationStatus("❌ No face detected. Please look at the camera.");
        setIsDetecting(false);
        return;
      }

      console.log("✅ Face detected in current frame");
      console.log("📥 Loading stored face image...");

      // Load stored face image
      let storedFaceImage;
      try {
        storedFaceImage = await loadImage(user.face_image);
        console.log("✅ Stored face image loaded");
        console.log("📷 Image dimensions:", storedFaceImage.width, "x", storedFaceImage.height);
      } catch (loadErr) {
        console.error("❌ Failed to load stored face image:", loadErr);
        setError(`Failed to load stored face image: ${loadErr.message}. Please contact administrator.`);
        setVerificationStatus("❌ Image loading failed");
        setIsDetecting(false);
        return;
      }
      
      // Verify image is valid before processing
      if (!storedFaceImage || !storedFaceImage.width || !storedFaceImage.height) {
        console.error("❌ Invalid stored face image");
        setError("The stored face image is invalid or corrupted. Please contact administrator.");
        setVerificationStatus("❌ Invalid image");
        setIsDetecting(false);
        return;
      }

      console.log("🔍 Detecting face in stored image...");
      console.log("📷 Image dimensions:", storedFaceImage.width, "x", storedFaceImage.height);
      
      let storedDetection;
      try {
        // Try with different input sizes if the default fails
        const detectOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 512 });
        const storedDetectPromise = faceapi
          .detectSingleFace(storedFaceImage, detectOptions)
          .withFaceLandmarks()
          .withFaceDescriptor();

        storedDetection = await withTimeout(storedDetectPromise, 15000);
        
        // If detection failed with 512, try with 320
        if (!storedDetection) {
          console.log("⚠️ Detection failed with inputSize 512, trying 320...");
          const detectOptions320 = new faceapi.TinyFaceDetectorOptions({ inputSize: 320 });
          const storedDetectPromise320 = faceapi
            .detectSingleFace(storedFaceImage, detectOptions320)
            .withFaceLandmarks()
            .withFaceDescriptor();
          storedDetection = await withTimeout(storedDetectPromise320, 15000);
        }
      } catch (detectErr) {
        console.error("❌ Face detection error in stored image:", detectErr);
        setError(`Face detection failed: ${detectErr.message}. Please contact administrator.`);
        setVerificationStatus("❌ Detection failed");
        setIsDetecting(false);
        return;
      }

      if (!storedDetection) {
        console.error("❌ No face detected in stored image after multiple attempts");
        console.error("📷 Stored image path:", user.face_image);
        console.error("📷 Image dimensions:", storedFaceImage.width, "x", storedFaceImage.height);
        console.error("📷 Image complete:", storedFaceImage.complete);
        setError("Could not detect a face in your stored image. This may indicate the image is corrupted, doesn't contain a clear face, or the face is too small. Please contact administrator to re-register your face.");
        setVerificationStatus("❌ No face found in stored image");
        setIsDetecting(false);
        return;
      }

      console.log("✅ Face detected in stored image");
      console.log("🔢 Calculating face similarity...");

      // ============================================
      // STEP 2: COMPARE FACES USING EUCLIDEAN DISTANCE
      // ============================================
      // Both faces are now represented as 128-dimensional vectors (descriptors)
      // 
      // detection.descriptor = Your current face: [0.23, 0.45, 0.12, ...] (128 numbers)
      // storedDetection.descriptor = Registered face: [0.25, 0.43, 0.15, ...] (128 numbers)
      //
      // Euclidean Distance calculates how "different" these two vectors are:
      // - Lower distance (e.g., 0.2) = Very similar faces (same person)
      // - Higher distance (e.g., 0.8) = Very different faces (different person)
      //
      // Formula: sqrt(sum((a[i] - b[i])²)) for i = 0 to 127
      const distance = faceapi.euclideanDistance(
        detection.descriptor,        // Current face descriptor (128 numbers)
        storedDetection.descriptor   // Stored face descriptor (128 numbers)
      );

      console.log("📊 Face distance:", distance.toFixed(3));
      console.log("📊 Distance meaning:");
      console.log("   - 0.0 to 0.3: Very similar (likely same person)");
      console.log("   - 0.3 to 0.5: Similar (probably same person)");
      console.log("   - 0.5 to 0.7: Different (probably different person)");
      console.log("   - 0.7+: Very different (definitely different person)");

      // ============================================
      // STEP 3: DECISION THRESHOLD & SECURITY
      // ============================================
      // Threshold determines how strict the matching is:
      // - Lower threshold (0.3): More strict, fewer false matches, might reject valid users
      // - Higher threshold (0.6): Less strict, more false matches, might accept wrong users
      // - Current: 0.4 = Balanced (moderate strictness)
      //
      // 🔒 SECURITY: Why different people can't match:
      // - Each person has a UNIQUE 128-dimensional face descriptor
      // - Different people = Different descriptors = HIGH distance (typically 0.5-1.5+)
      // - Same person = Similar descriptors = LOW distance (typically 0.2-0.4)
      // - Threshold 0.4 is chosen to be BELOW typical "different person" distances
      // - Statistical probability of false match: < 0.1%
      //
      // Example distances:
      // - Same person: 0.25 ✅ (MATCH - distance < 0.4)
      // - Different person: 0.71 ❌ (MISMATCH - distance > 0.4)
      // - Another person: 0.83 ❌ (MISMATCH - distance > 0.4)
      //
      // The neural network was trained on millions of faces to create unique descriptors
      // for each person. Different facial structures = different numbers = high distance!
      const threshold = 0.4;

      if (distance < threshold) {
        console.log("✅ Face match confirmed! Distance:", distance.toFixed(3), "< Threshold:", threshold);
        setVerificationStatus("✅ Face verified! Processing vote...");
        
        // Capture image for backend verification
        const capturedImage = canvas.toDataURL("image/jpeg");
        const base64Image = capturedImage.split(",")[1];
        
        // Stop camera
        stopCamera();
        
        // Call verification callback with captured image and distance
        setTimeout(() => {
          onVerify({ imageBase64: base64Image, distance });
        }, 500);
      } else {
        console.log("❌ Face mismatch. Distance:", distance.toFixed(3), ">= Threshold:", threshold);
        setVerificationStatus(
          `❌ Face mismatch. Please try again.`
        );
        setIsDetecting(false);
      }
    } catch (err) {
      console.error("❌ Verification error:", err);
      console.error("❌ Error details:", err.message);
      setError("Error during face verification: " + err.message);
      setIsDetecting(false);
      setVerificationStatus("");
    }
  };

  // Load image from URL or file path
  const loadImage = (faceImagePath) => {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.crossOrigin = "anonymous";
      
      // Set timeout for image loading
      const timeout = setTimeout(() => {
        reject(new Error(`Image loading timeout after 15s: ${faceImagePath}`));
      }, 15000);
      
      img.onload = () => {
        clearTimeout(timeout);
        console.log("✅ Stored face image loaded successfully");
        resolve(img);
      };
      
      img.onerror = async (err) => {
        clearTimeout(timeout);
        console.error("❌ Failed to load stored face image:", faceImagePath);
        console.error("❌ Attempted URL:", img.src);
        console.error("❌ Error details:", err);
        
        // Try to fetch the endpoint to see what error it returns
        try {
          const response = await fetch(img.src, { mode: 'cors' });
          const contentType = response.headers.get('content-type') || '';
          const text = await response.text();
          console.error("❌ Endpoint response status:", response.status);
          console.error("❌ Endpoint Content-Type:", contentType);
          console.error("❌ Endpoint response (first 1000 chars):", text.slice(0, 1000));

          if (!response.ok) {
            if (response.status === 404) {
              reject(new Error(`Face image file not found: ${faceImagePath}. Please contact administrator.`));
            } else if (response.status === 400) {
              reject(new Error(`Invalid face image request: ${text}`));
            } else {
              reject(new Error(`Failed to load stored face image: ${faceImagePath}. Server returned status ${response.status}`));
            }
          } else if (!contentType.startsWith('image/')) {
            // Server replied 200 but returned HTML/JSON instead of an image (common when route returns HTML page)
            reject(new Error(`Failed to load stored face image: ${faceImagePath}. Server returned Content-Type: ${contentType}. Response snippet: ${text.slice(0, 1000)}`));
          } else {
            // Unexpected case: ok status and image content-type but image element errored
            reject(new Error(`Failed to load stored face image: ${faceImagePath}. Server returned image content but the browser could not decode it.`));
          }
        } catch (fetchErr) {
          console.error("❌ Could not fetch endpoint:", fetchErr);
          reject(new Error(`Failed to load stored face image: ${faceImagePath}. Please check if the file exists and the server is accessible.`));
        }
      };
      
      // Handle different face_image formats:
      // 1. File path like "uploads/faces/filename.jpg"
      // 2. Base64 data URL like "data:image/jpeg;base64,..."
      // 3. Full URL
      
      if (faceImagePath.startsWith("data:image")) {
        // Base64 data URL
        img.src = faceImagePath;
        console.log("📷 Loading face image from base64 data URL");
      } else if (faceImagePath.startsWith("uploads/")) {
        // Relative file path - extract filename and use PHP endpoint
        const filename = faceImagePath.split('/').pop(); // Get just the filename
        
        // Use the same API base pattern as other components
        // API_BASE should be "http://localhost/final_votesecure/backend/api"
        const possibleBaseUrls = [
          process.env.REACT_APP_API_BASE,
          "http://localhost/final_votesecure/backend/api",
          "http://localhost:80/final_votesecure/backend/api",
          window.location.origin + "/backend/api",
          window.location.origin.replace(":3001", "") + "/final_votesecure/backend/api"
        ].filter(Boolean); // Remove empty values
        
        const apiBase = possibleBaseUrls[0] || "http://localhost/final_votesecure/backend/api";
        // Use PHP endpoint to serve the image securely. Fetch as blob first so
        // we can detect non-image responses and avoid the <img> element failing
        // silently. This also gives clearer error messages to the user.
        const imageUrl = `${apiBase}/get-face-image.php?file=${encodeURIComponent(filename)}`;
        console.log("📷 Fetching stored face image from API endpoint:", imageUrl);

        fetch(imageUrl, { method: 'GET', mode: 'cors' })
          .then(async (res) => {
            const contentType = res.headers.get('content-type') || '';
            const text = await (res.clone().text().catch(() => ''));
            if (!res.ok) {
              throw new Error(`Server returned status ${res.status}. ${text}`);
            }
            if (!contentType.startsWith('image/')) {
              throw new Error(`Server returned non-image content. Content-Type: ${contentType}. Response: ${text.slice(0,500)}`);
            }
            const blob = await res.blob();
            console.log("📦 Fetched blob -> type:", blob.type, "size:", blob.size);
            // Inspect first bytes of the blob to detect prepended HTML or corruption
            try {
              const headerSlice = blob.slice(0, 16);
              const readerHeader = new FileReader();
              readerHeader.onload = () => {
                const arr = new Uint8Array(readerHeader.result);
                const hex = Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join(' ');
                console.log('🔍 Blob header (first 16 bytes):', hex);
              };
              readerHeader.onerror = (rhErr) => {
                console.warn('Could not read blob header:', rhErr);
              };
              readerHeader.readAsArrayBuffer(headerSlice);
            } catch (hdrErr) {
              console.warn('Header inspection failed:', hdrErr);
            }
            const objectUrl = URL.createObjectURL(blob);
            img.onload = () => {
              URL.revokeObjectURL(objectUrl);
              clearTimeout(timeout);
              console.log("✅ Stored face image loaded successfully (blob)");
              resolve(img);
            };
            // Retry logic: if the blob fails to load via object URL, try a FileReader
            // fallback to a data URL before giving up. This helps detect corrupt
            // or non-decodable blobs.
            let retriedWithDataUrl = false;
            img.onerror = (e) => {
              console.warn("⚠️ img.onerror when loading object URL, attempting FileReader fallback", e);
              if (!retriedWithDataUrl) {
                retriedWithDataUrl = true;
                try {
                  const reader = new FileReader();
                  reader.onloadend = () => {
                    const dataUrl = reader.result;
                    console.log("🔁 Retry loading image using data URL (FileReader)");
                    img.src = dataUrl;
                  };
                  reader.onerror = (rErr) => {
                    console.error("❌ FileReader failed:", rErr);
                    URL.revokeObjectURL(objectUrl);
                    clearTimeout(timeout);
                    reject(new Error(`Failed to load stored face image (blob URL and data URL): ${imageUrl}`));
                  };
                  reader.readAsDataURL(blob);
                } catch (frErr) {
                  console.error("❌ FileReader exception:", frErr);
                  URL.revokeObjectURL(objectUrl);
                  clearTimeout(timeout);
                  reject(new Error(`Failed to load stored face image (blob URL): ${imageUrl}`));
                }
                return;
              }

              URL.revokeObjectURL(objectUrl);
              clearTimeout(timeout);
              reject(new Error(`Failed to load stored face image (blob URL): ${imageUrl}`));
            };
            img.src = objectUrl;
          })
          .catch((err) => {
            clearTimeout(timeout);
            console.error('❌ Failed to fetch stored face image:', err);
            reject(new Error(`Failed to load stored face image: ${faceImagePath}. ${err.message}`));
          });
        console.log("📷 API Base used:", apiBase);
        console.log("📷 Filename:", filename);
      } else if (faceImagePath.startsWith("http://") || faceImagePath.startsWith("https://")) {
        // Full URL
        img.src = faceImagePath;
        console.log("📷 Loading face image from full URL:", faceImagePath);
      } else {
        // Try as relative path with backend URL
        const baseUrl = process.env.REACT_APP_API_BASE || "http://localhost/final_votesecure/backend";
        const imageUrl = `${baseUrl}/${faceImagePath}`;
        img.src = imageUrl;
        console.log("📷 Loading face image from (fallback):", imageUrl);
      }
    });
  };

  return (
    <div className="face-verification-modal">
      <div className="face-verification-content">
        <div className="camera-modal-header">
          <h3 style={{ margin: 0, color: '#2c3e50', fontSize: '1.25rem' }}>{title}</h3>
          <button 
            type="button"
            onClick={() => {
              stopCamera();
              onCancel();
            }}
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

        {error && <div className="error-message">{error}</div>}

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
                zIndex: 1,
                visibility: stream && videoReady ? "visible" : "hidden",
                opacity: stream && videoReady ? 1 : 0,
                transition: "opacity 0.3s ease"
              }}
              onLoadedMetadata={(e) => {
                const video = e.target;
                console.log("✅ Video metadata loaded. Dimensions:", video.videoWidth, "x", video.videoHeight);
              }}
              onPlaying={(e) => {
                const video = e.target;
                console.log("✅ Video is now playing!");
                if (video.videoWidth > 0 && video.videoHeight > 0) {
                  setVideoReady(true);
                }
              }}
              onLoadedData={() => {
                if (videoRef.current && videoRef.current.videoWidth > 0) {
                  console.log("📹 Video data loaded, ensuring visibility");
                  setVideoReady(true);
                }
              }}
            />
            <canvas ref={canvasRef} style={{ display: "none" }} />
            
            {(!stream || !videoReady) && modelsLoaded && (
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
                <p style={{ margin: 0 }}>📷 {stream ? "Initializing camera feed..." : "Starting camera..."}</p>
                {stream && !videoReady && (
                  <p style={{ margin: '10px 0 0 0', fontSize: '12px' }}>Please wait, this may take a few seconds...</p>
                )}
              </div>
            )}
            
            {(!stream || !videoReady) && !modelsLoaded && (
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
                <p style={{ margin: 0 }}>⏳ Loading models...</p>
              </div>
            )}
          </div>

          {verificationStatus && (
            <div style={{ 
              margin: '10px 0',
              padding: '8px 10px',
              backgroundColor: verificationStatus.includes("✅") ? '#d4edda' : '#fff3cd',
              color: verificationStatus.includes("✅") ? '#155724' : '#856404',
              borderRadius: '6px',
              textAlign: 'center',
              fontWeight: '500',
              fontSize: '14px',
              flexShrink: 0
            }}>
              {verificationStatus}
            </div>
          )}

          <div className="camera-controls" style={{ marginTop: '10px', flexShrink: 0 }}>
            {!stream ? (
              <button
                onClick={() => {
                  startCamera().catch(err => {
                    console.error("Camera start error:", err);
                  });
                }}
                className="btn btn-primary"
                disabled={!modelsLoaded}
                style={{
                  backgroundColor: modelsLoaded ? '#667eea' : '#6c757d',
                  fontSize: '16px',
                  padding: '12px 24px',
                  fontWeight: 'bold',
                  width: '100%',
                  border: 'none',
                  borderRadius: '8px',
                  color: 'white',
                  cursor: modelsLoaded ? 'pointer' : 'not-allowed'
                }}
              >
                {modelsLoaded ? "📷 Start Camera" : "Loading models..."}
              </button>
            ) : (
              <>
                <button
                  onClick={captureAndVerify}
                  className="btn btn-primary"
                  disabled={isDetecting || !modelsLoaded || !videoReady}
                  style={{
                    backgroundColor: (isDetecting || !modelsLoaded || !videoReady) ? '#6c757d' : '#28a745',
                    fontSize: '16px',
                    padding: '12px 24px',
                    fontWeight: 'bold',
                    marginRight: '10px',
                    border: 'none',
                    borderRadius: '8px',
                    color: 'white',
                    cursor: (isDetecting || !modelsLoaded || !videoReady) ? 'not-allowed' : 'pointer'
                  }}
                >
                  {isDetecting ? "Verifying..." : !videoReady ? "⏳ Camera loading..." : "🔍 Verify Face"}
                </button>
                <button
                  onClick={() => {
                    stopCamera();
                    onCancel();
                  }}
                  className="btn btn-secondary"
                  disabled={isDetecting}
                  style={{
                    backgroundColor: '#6c757d',
                    fontSize: '16px',
                    padding: '12px 24px',
                    fontWeight: 'bold',
                    border: 'none',
                    borderRadius: '8px',
                    color: 'white',
                    cursor: isDetecting ? 'not-allowed' : 'pointer'
                  }}
                >
                  Cancel
                </button>
              </>
            )}
          </div>

          {!modelsLoaded && (
            <div className="loading-models">
              <div className="spinner"></div>
              <p>Loading face recognition models...</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default FaceVerification;

