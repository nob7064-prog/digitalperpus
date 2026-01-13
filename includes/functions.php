<?php
require_once __DIR__ . '/../config/database.php';

// Tambahkan ini untuk mencegah error redeclaration
if (!function_exists('sanitize')) {
    function sanitize($data) {
        $conn = getConnection();
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = sanitize($value);
            }
            return $data;
        }
        return mysqli_real_escape_string($conn, htmlspecialchars(trim($data)));
    }
}

if (!function_exists('clean_input')) {
    function clean_input($data) {
        if (empty($data) || $data === null) {
            return '';
        }
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit();
    }
}

if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        return $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
    }
}

if (!function_exists('generateToken')) {
    function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'd/m/Y') {
        if (empty($date) || $date === null || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '-';
        }
        return date($format, strtotime($date));
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime, $format = 'd/m/Y H:i') {
        if (empty($datetime) || $datetime === null || $datetime === '0000-00-00 00:00:00') {
            return '-';
        }
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        if ($amount === null || $amount === '' || $amount === 0) {
            return 'Rp 0';
        }
        return 'Rp ' . number_format((float)$amount, 0, ',', '.');
    }
}

if (!function_exists('calculateDenda')) {
    function calculateDenda($terlambat_hari) {
        // Sistem denda perpustakaan:
        // - Masa pinjam: 7 hari (1 minggu)
        // - Denda: 5.000 rupiah per hari
        // - Denda mulai terhitung dari hari ke-8 (hari setelah jatuh tempo)
        // - Denda berlanjut sampai buku dikembalikan

        $denda_per_hari = 5000;
        return max(0, $terlambat_hari) * $denda_per_hari;
    }
}

if (!function_exists('getBookStatus')) {
    function getBookStatus($id_buku, $id_anggota = null) {
        $conn = getConnection();
        $status = 'kosong';
        
        if ($id_anggota) {
            // Check if book is borrowed by this member
            $sql = "SELECT status FROM peminjaman 
                    WHERE id_buku = ? AND id_anggota = ? 
                    AND status IN ('pending', 'dipinjam', 'terlambat', 'ditolak')
                    ORDER BY created_at DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $id_buku, $id_anggota);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $status = $row['status'];
                
                // Check if there's a pending return request
                if ($status === 'dipinjam' || $status === 'terlambat') {
                    $returnCheckSql = "SELECT id_pengembalian FROM pengembalian 
                                       WHERE id_peminjaman IN (
                                           SELECT id_peminjaman FROM peminjaman 
                                           WHERE id_buku = ? AND id_anggota = ? 
                                           AND status IN ('dipinjam', 'terlambat')
                                       ) AND status_denda = 'belum_lunas'";
                    $returnCheckStmt = $conn->prepare($returnCheckSql);
                    $returnCheckStmt->bind_param("ii", $id_buku, $id_anggota);
                    $returnCheckStmt->execute();
                    $res = $returnCheckStmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $status = 'return_pending';
                    }
                    $returnCheckStmt->close();
                }
            }
            $stmt->close();
        }
        
        $conn->close();
        return $status;
    }
}

if (!function_exists('logActivity')) {
    function logActivity($id_user, $user_type, $aktivitas) {
        $conn = getConnection();
        $sql = "INSERT INTO aktivitas (id_user, user_type, aktivitas) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $id_user, $user_type, $aktivitas);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

if (!function_exists('sendVerificationEmail')) {
    function sendVerificationEmail($email, $token) {
        $verification_link = SITE_URL . "auth/verify.php?token=" . $token;
        
        // For now, we'll just log it. In production, implement SMTP
        error_log("Verification email sent to: $email");
        error_log("Verification link: $verification_link");
        
        return true; // In production, return actual email send status
    }
}

// BOOK FUNCTIONS
if (!function_exists('getBookCoverPath')) {
    function getBookCoverPath($cover_buku) {
        if (empty($cover_buku) || $cover_buku === 'default.jpg') {
            return SITE_URL . 'uploads/covers/default.jpg';
        }
        return SITE_URL . 'uploads/covers/' . $cover_buku;
    }
}

if (!function_exists('getProfileImagePath')) {
    function getProfileImagePath($foto_profil) {
        if (empty($foto_profil) || $foto_profil === 'default.png') {
            return SITE_URL . 'uploads/profiles/default.png';
        }
        return SITE_URL . 'uploads/profiles/' . $foto_profil;
    }
}

// NOTIFICATION FUNCTIONS
if (!function_exists('createMemberNotification')) {
    function createMemberNotification($id_anggota, $judul, $pesan, $tipe = 'info') {
        $conn = getConnection();
        $sql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isss", $id_anggota, $judul, $pesan, $tipe);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    }
}

if (!function_exists('createAdminNotification')) {
    function createAdminNotification($tipe, $judul, $pesan, $id_referensi = null) {
        $conn = getConnection();
        
        // Get first admin ID
        $adminSql = "SELECT id_admin FROM admin LIMIT 1";
        $adminResult = $conn->query($adminSql);
        
        if ($adminResult->num_rows > 0) {
            $admin = $adminResult->fetch_assoc();
            $id_admin = $admin['id_admin'];
            
            // Check if admin_notifications table exists
            $checkTable = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
            if ($checkTable->num_rows === 0) {
                // Create table if not exists
                $createTable = "CREATE TABLE admin_notifications (
                    id_notif INT AUTO_INCREMENT PRIMARY KEY,
                    id_admin INT NOT NULL,
                    tipe VARCHAR(50),
                    judul VARCHAR(255),
                    pesan TEXT,
                    id_referensi INT,
                    dibaca ENUM('no', 'yes') DEFAULT 'no',
                    dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP
                )";
                $conn->query($createTable);
            }
            
            $sql = "INSERT INTO admin_notifications (id_admin, tipe, judul, pesan, id_referensi) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssi", $id_admin, $tipe, $judul, $pesan, $id_referensi);
            $result = $stmt->execute();
            $stmt->close();
        } else {
            $result = false;
        }
        
        $conn->close();
        return $result;
    }
}

// VALIDATION FUNCTIONS
if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('validatePhone')) {
    function validatePhone($phone) {
        // Indonesian phone number validation
        return preg_match('/^(\+62|62|0)8[1-9][0-9]{6,9}$/', $phone);
    }
}

if (!function_exists('validatePassword')) {
    function validatePassword($password) {
        // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d]{8,}$/', $password);
    }
}

// DATE FUNCTIONS
if (!function_exists('calculateDaysBetween')) {
    function calculateDaysBetween($date1, $date2) {
        $datetime1 = new DateTime($date1);
        $datetime2 = new DateTime($date2);
        $interval = $datetime1->diff($datetime2);
        return $interval->days;
    }
}

if (!function_exists('isDatePast')) {
    function isDatePast($date) {
        $today = new DateTime();
        $targetDate = new DateTime($date);
        return $targetDate < $today;
    }
}

if (!function_exists('addDaysToDate')) {
    function addDaysToDate($date, $days) {
        $dateObj = new DateTime($date);
        $dateObj->modify("+$days days");
        return $dateObj->format('Y-m-d');
    }
}

// FILE UPLOAD FUNCTIONS
if (!function_exists('uploadFile')) {
    function uploadFile($file, $allowed_types, $max_size, $upload_path) {
        $errors = [];
        
        // Check if file was uploaded
        if (!isset($file['error']) || is_array($file['error'])) {
            $errors[] = 'Invalid file upload.';
            return ['success' => false, 'errors' => $errors];
        }
        
        // Check for upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = 'No file uploaded.';
                return ['success' => false, 'errors' => $errors];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = 'File size too large.';
                return ['success' => false, 'errors' => $errors];
            default:
                $errors[] = 'Unknown upload error.';
                return ['success' => false, 'errors' => $errors];
        }
        
        // Check file size
        if ($file['size'] > $max_size) {
            $errors[] = 'File size exceeds maximum allowed size.';
        }
        
        // Check file type
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_types)) {
            $errors[] = 'File type not allowed.';
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // Create upload directory if it doesn't exist
        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0777, true);
        }
        
        // Generate unique filename
        $filename = uniqid() . '_' . time() . '.' . $file_ext;
        $destination = $upload_path . '/' . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => true, 'filename' => $filename];
        } else {
            $errors[] = 'Failed to move uploaded file.';
            return ['success' => false, 'errors' => $errors];
        }
    }
}

// PAGINATION FUNCTION
if (!function_exists('getPagination')) {
    function getPagination($total_items, $items_per_page, $current_page) {
        $total_pages = ceil($total_items / $items_per_page);
        $offset = ($current_page - 1) * $items_per_page;
        
        return [
            'total_pages' => $total_pages,
            'current_page' => $current_page,
            'offset' => $offset,
            'items_per_page' => $items_per_page,
            'total_items' => $total_items,
            'has_previous' => $current_page > 1,
            'has_next' => $current_page < $total_pages,
            'previous_page' => max(1, $current_page - 1),
            'next_page' => min($total_pages, $current_page + 1)
        ];
    }
}

// DEBUG FUNCTIONS (for development only)
if (!function_exists('debug')) {
    function debug($data) {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
    }
}

if (!function_exists('debugToLog')) {
    function debugToLog($data) {
        error_log(print_r($data, true));
    }
}

// STRING FUNCTIONS
if (!function_exists('truncateText')) {
    function truncateText($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . $suffix;
    }
}

if (!function_exists('slugify')) {
    function slugify($text) {
        // Replace non-letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        
        // Transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        
        // Remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);
        
        // Trim
        $text = trim($text, '-');
        
        // Remove duplicate -
        $text = preg_replace('~-+~', '-', $text);
        
        // Lowercase
        $text = strtolower($text);
        
        if (empty($text)) {
            return 'n-a';
        }
        
        return $text;
    }
}

// DATABASE HELPER FUNCTIONS
if (!function_exists('getSingleValue')) {
    function getSingleValue($sql, $params = []) {
        $conn = getConnection();
        $stmt = $conn->prepare($sql);
        
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $value = $result->fetch_array()[0] ?? null;
        
        $stmt->close();
        $conn->close();
        
        return $value;
    }
}

if (!function_exists('executeQuery')) {
    function executeQuery($sql, $params = []) {
        $conn = getConnection();
        $stmt = $conn->prepare($sql);
        
        if (!empty($params)) {
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }
            $stmt->bind_param($types, ...$params);
        }
        
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        
        return $result;
    }
}

// MEMBER STATUS CHECK
if (!function_exists('canMemberBorrow')) {
    function canMemberBorrow($id_anggota) {
        $conn = getConnection();
        
        // Check if member has unpaid fines
        $sql = "SELECT total_denda FROM anggota WHERE id_anggota = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_anggota);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        
        $can_borrow = true;
        $reason = '';
        
        if ($member['total_denda'] > 0) {
            $can_borrow = false;
            $reason = 'Anda memiliki denda yang belum dibayar sebesar ' . formatCurrency($member['total_denda']);
        }
        
        // Check if member has reached borrowing limit
        $borrowLimit = 3; // Maximum books a member can borrow at once
        $sql = "SELECT COUNT(*) as borrowed_count FROM peminjaman 
                WHERE id_anggota = ? AND status IN ('dipinjam', 'terlambat')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_anggota);
        $stmt->execute();
        $result = $stmt->get_result();
        $borrowed = $result->fetch_assoc();
        
        if ($borrowed['borrowed_count'] >= $borrowLimit) {
            $can_borrow = false;
            $reason = 'Anda telah mencapai batas peminjaman maksimal (' . $borrowLimit . ' buku)';
        }
        
        $stmt->close();
        $conn->close();
        
        return ['can_borrow' => $can_borrow, 'reason' => $reason];
    }
}

// BOOK AVAILABILITY CHECK
if (!function_exists('isBookAvailable')) {
    function isBookAvailable($id_buku) {
        $conn = getConnection();
        $sql = "SELECT stok FROM buku WHERE id_buku = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_buku);
        $stmt->execute();
        $result = $stmt->get_result();
        $book = $result->fetch_assoc();
        
        $available = ($book['stok'] > 0);
        $stmt->close();
        $conn->close();
        
        return $available;
    }
}

// GET MEMBER BORROWING HISTORY
if (!function_exists('getMemberBorrowingHistory')) {
    function getMemberBorrowingHistory($id_anggota, $limit = 10) {
        $conn = getConnection();
        $sql = "SELECT p.*, b.judul_buku, b.cover_buku,
                CASE 
                    WHEN p.status = 'dipinjam' AND p.tanggal_jatuh_tempo < CURDATE() THEN 'terlambat'
                    ELSE p.status
                END as actual_status
                FROM peminjaman p
                JOIN buku b ON p.id_buku = b.id_buku
                WHERE p.id_anggota = ?
                ORDER BY p.created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id_anggota, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = $result->fetch_all(MYSQLI_ASSOC);
        
        $stmt->close();
        $conn->close();
        
        return $history;
    }
}

// GET OVERDUE BOOKS
if (!function_exists('getOverdueBooks')) {
    function getOverdueBooks() {
        $conn = getConnection();
        $sql = "SELECT p.*, b.judul_buku, a.username, a.email,
                DATEDIFF(CURDATE(), p.tanggal_jatuh_tempo) as terlambat_hari
                FROM peminjaman p
                JOIN buku b ON p.id_buku = b.id_buku
                JOIN anggota a ON p.id_anggota = a.id_anggota
                WHERE p.status = 'dipinjam' 
                AND p.tanggal_jatuh_tempo < CURDATE()
                ORDER BY p.tanggal_jatuh_tempo ASC";
        
        $result = $conn->query($sql);
        $overdue = $result->fetch_all(MYSQLI_ASSOC);
        
        $conn->close();
        return $overdue;
    }
}

// GET PENDING ITEMS COUNT (for admin dashboard)
if (!function_exists('getPendingItemsCount')) {
    function getPendingItemsCount() {
        $conn = getConnection();
        $counts = [
            'pending_loans' => 0,
            'pending_returns' => 0,
            'pending_fines' => 0
        ];
        
        // Pending loans
        $sql = "SELECT COUNT(*) as count FROM peminjaman WHERE status = 'pending'";
        $result = $conn->query($sql);
        $counts['pending_loans'] = $result->fetch_assoc()['count'];
        
        // Pending returns
        $sql = "SELECT COUNT(*) as count FROM pengembalian pg
                JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                WHERE pg.status_denda = 'belum_lunas' 
                AND p.status IN ('dipinjam', 'terlambat')";
        $result = $conn->query($sql);
        $counts['pending_returns'] = $result->fetch_assoc()['count'];
        
        // Pending fines
        $sql = "SELECT COUNT(*) as count FROM denda WHERE status = 'menunggu_approval'";
        $result = $conn->query($sql);
        $counts['pending_fines'] = $result->fetch_assoc()['count'];
        
        $conn->close();
        return $counts;
    }
}

// GENERATE RANDOM STRING
if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}

// VALIDATE IMAGE FILE
if (!function_exists('validateImageFile')) {
    function validateImageFile($file) {
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $result = uploadFile($file, $allowed_types, $max_size, '../uploads/temp');
        
        if ($result['success']) {
            // Delete temp file after validation
            unlink('../uploads/temp/' . $result['filename']);
        }
        
        return $result;
    }
}

// VALIDATE PDF FILE
if (!function_exists('validatePdfFile')) {
    function validatePdfFile($file) {
        $allowed_types = ['pdf'];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        $result = uploadFile($file, $allowed_types, $max_size, '../uploads/temp');
        
        if ($result['success']) {
            // Delete temp file after validation
            unlink('../uploads/temp/' . $result['filename']);
        }
        
        return $result;
    }
}

?>