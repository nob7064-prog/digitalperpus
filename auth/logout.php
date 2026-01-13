<?php
require_once '../includes/session.php';

// Log activity before destroying session
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    logActivity($_SESSION['user_id'], $_SESSION['user_type'], 'Logout dari sistem');
}

// Destroy all session data
session_destroy();

// Redirect to home page
header('Location: ' . SITE_URL);
exit();