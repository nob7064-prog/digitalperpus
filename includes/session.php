<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_level']) && in_array($_SESSION['user_level'], ['admin', 'petugas']);
}

function isAnggota() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'anggota';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'auth/login.php');
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . 'anggota/dashboard.php');
        exit();
    }
}

function requireAnggota() {
    requireLogin();
    if (!isAnggota()) {
        header('Location: ' . SITE_URL . 'admin/dashboard.php');
        exit();
    }
}

function checkDenda() {
    if (isset($_SESSION['user_id']) && $_SESSION['user_type'] === 'anggota') {
        $conn = getConnection();
        $sql = "SELECT total_denda FROM anggota WHERE id_anggota = ? AND total_denda > 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if ($user['total_denda'] > 0) {
                $_SESSION['has_denda'] = true;
                $_SESSION['total_denda'] = $user['total_denda'];
            }
        }
        $stmt->close();
        $conn->close();
    }
}
?>