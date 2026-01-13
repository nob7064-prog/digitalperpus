<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Password kosong untuk Laragon default
define('DB_NAME', 'digital_library');

// Site configuration
define('SITE_URL', 'http://localhost/digital-library/');
define('SITE_NAME', 'Digital Library');

// Email configuration (for verification)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');
define('SMTP_FROM', 'noreply@digitallibrary.com');

// File upload paths
define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');
define('COVER_PATH', UPLOAD_PATH . 'covers/');
define('PDF_PATH', UPLOAD_PATH . 'pdfs/');
define('PROFILE_PATH', UPLOAD_PATH . 'profiles/');

// Create upload directories if they don't exist
if (!file_exists(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0777, true);
if (!file_exists(COVER_PATH)) mkdir(COVER_PATH, 0777, true);
if (!file_exists(PDF_PATH)) mkdir(PDF_PATH, 0777, true);
if (!file_exists(PROFILE_PATH)) mkdir(PROFILE_PATH, 0777, true);

// Database connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}
?>