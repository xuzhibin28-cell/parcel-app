<?php
// Application configuration
define('BASE_URL', 'http://localhost/parcel/');    // 改成与你文件夹名一致
define('UPLOAD_DIR', __DIR__ . '/../uploads/photos/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png']);

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'parcel_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}