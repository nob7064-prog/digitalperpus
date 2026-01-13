<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Serve PDF securely using one-time token stored in session

if (!isLoggedIn() || !isAnggota()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$token = '';
// accept token via POST or GET for compatibility
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
} else {
    $token = $_GET['token'] ?? '';
}
if (empty($token) || !isset($_SESSION['pdf_tokens'][$token])) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}

// first try session store
$entry = null;
if (isset($_SESSION['pdf_tokens'][$token])) {
    $entry = $_SESSION['pdf_tokens'][$token];
}

// fallback: check temp file mapping if session not available
if ($entry === null) {
    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dl_pdf_token_' . $token;
    if (file_exists($tmpFile)) {
        $content = @file_get_contents($tmpFile);
        $data = $content ? json_decode($content, true) : null;
        if ($data && isset($data['path'])) {
            $entry = $data;
            // remove temp token to make it single-use
            @unlink($tmpFile);
        }
    }
}

// validate entry
if (!isset($entry['path']) || !file_exists($entry['path'])) {
    unset($_SESSION['pdf_tokens'][$token]);
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}

if (isset($entry['exp']) && time() > intval($entry['exp'])) {
    unset($_SESSION['pdf_tokens'][$token]);
    header('HTTP/1.1 403 Forbidden');
    exit('Token expired');
}

// ensure file is inside uploads/pdfs
$baseDir = realpath(__DIR__ . '/../uploads/pdfs');
$realFile = realpath($entry['path']);
if ($realFile === false || strpos($realFile, $baseDir) !== 0) {
    unset($_SESSION['pdf_tokens'][$token]);
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// make token single-use (remove from session if present)
if (isset($_SESSION['pdf_tokens'][$token])) unset($_SESSION['pdf_tokens'][$token]);

// send headers and stream file in chunks with security restrictions
$filesize = filesize($realFile);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($realFile) . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Security headers to prevent downloading, printing, and sharing
header('X-Download-Options: noopen'); // Prevent download in IE
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; script-src \'none\'; object-src \'none\'; frame-ancestors \'self\'; img-src \'none\'; media-src \'none\'; font-src \'none\'; connect-src \'none\'; style-src \'none\';');

// Additional headers to prevent printing and copying
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, nocache');
header('X-Permitted-Cross-Domain-Policies: none');
header('Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Additional security headers
header('X-Content-Security-Policy: default-src \'none\''); // For older browsers
header('X-WebKit-CSP: default-src \'none\''); // For WebKit browsers

$fp = fopen($realFile, 'rb');
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
    }
    fclose($fp);
    exit;
} else {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Failed to open file');
}
