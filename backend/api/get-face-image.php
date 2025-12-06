<?php
require_once 'config.php';

// Get the image filename from query parameter
$filename = $_GET['file'] ?? '';

if (empty($filename)) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Filename is required']);
    exit;
}

// Security: Only allow alphanumeric, underscore, dash, and dot in filename
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

// Construct file path
// STANDARD PATH: backend/api/uploads/faces/ (new location)
// LEGACY PATH: backend/uploads/faces/ (old location for backward compatibility)
// get-face-image.php is in backend/api/, so __DIR__ points to backend/api/

// Try new location first (standard path)
$file_path = __DIR__ . '/uploads/faces/' . basename($filename);

// If not found, try old location (backward compatibility)
if (!file_exists($file_path)) {
    $legacy_path = dirname(__DIR__) . '/uploads/faces/' . basename($filename);
    if (file_exists($legacy_path)) {
        $file_path = $legacy_path;
        error_log("Face image found in legacy location: " . $legacy_path);
    }
}

// Debug: Log the path (remove in production)
// error_log("Looking for face image at: " . $file_path);

// If debug query param is set, return JSON diagnostics instead of the image.
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    $legacy_path = dirname(__DIR__) . '/uploads/faces/' . basename($filename);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'requested_file' => $filename,
        'file_path' => $file_path,
        'file_exists' => file_exists($file_path),
        'is_readable' => is_readable($file_path),
        'location' => file_exists($file_path) ? (strpos($file_path, '/api/') !== false ? 'new' : 'legacy') : 'not_found',
        'new_directory' => __DIR__ . '/uploads/faces/',
        'new_directory_exists' => is_dir(__DIR__ . '/uploads/faces/'),
        'legacy_directory' => dirname(__DIR__) . '/uploads/faces/',
        'legacy_directory_exists' => is_dir(dirname(__DIR__) . '/uploads/faces/'),
        'files_in_new_directory' => is_dir(__DIR__ . '/uploads/faces/') ? array_slice(scandir(__DIR__ . '/uploads/faces/'), 0, 20) : [],
        'files_in_legacy_directory' => is_dir(dirname(__DIR__) . '/uploads/faces/') ? array_slice(scandir(dirname(__DIR__) . '/uploads/faces/'), 0, 20) : [],
    ]);
    exit;
}

// Check if file exists (after checking both locations)
if (!file_exists($file_path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'error' => 'Image not found',
        'requested_file' => $filename,
        'checked_paths' => [
            'new_location' => __DIR__ . '/uploads/faces/' . basename($filename),
            'legacy_location' => dirname(__DIR__) . '/uploads/faces/' . basename($filename)
        ],
        'new_directory_exists' => is_dir(__DIR__ . '/uploads/faces/'),
        'legacy_directory_exists' => is_dir(dirname(__DIR__) . '/uploads/faces/')
    ]);
    exit;
}

// Verify it's an image file
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
$file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

if (!in_array($file_extension, $allowed_extensions)) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => 'Invalid file type', 'extension' => $file_extension]);
    exit;
}

// Get the image info
$image_info = @getimagesize($file_path);
if ($image_info === false) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'error' => 'Invalid image file', 
        'file_path' => $file_path,
        'file_exists' => file_exists($file_path),
        'is_readable' => is_readable($file_path),
        'file_size' => file_exists($file_path) ? filesize($file_path) : 0
    ]);
    exit;
}

// Verify it's actually a valid image by checking file size
$file_size = filesize($file_path);
if ($file_size === false || $file_size < 100) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'error' => 'Image file is too small or corrupted',
        'file_size' => $file_size,
        'file_path' => $file_path
    ]);
    exit;
}

// Set appropriate headers
header('Content-Type: ' . $image_info['mime']);
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *'); // Allow CORS

// Output the image
// Clean (turn off) output buffering to avoid any accidental text before image bytes
if (ob_get_level()) {
    ob_end_clean();
}
flush();
readfile($file_path);
exit;
?>

