<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Only admin can access this page
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

// Set page variables
$page_title = 'Kelola Denda';
$page_icon = 'fas fa-money-bill-wave';
$current_page = 'kelola_denda.php';

// Initialize variables
$denda_list = [];
$stats = [];
$error_message = '';
$success_message = '';

// Handle POST actions for denda approval
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'approve_denda':
            $id_denda = intval($_POST['id_denda'] ?? 0);
            if ($id_denda > 0) {
                // Process denda approval
                $result = approveDenda($id_denda, 'lunas');
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
            
        case 'reject_denda':
            $id_denda = intval($_POST['id_denda'] ?? 0);
            if ($id_denda > 0) {
                // Process denda rejection
                $result = approveDenda($id_denda, 'belum_lunas');
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
    }
}

// Function to approve/reject denda
function approveDenda($id_denda, $status) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Check if denda exists
        $sql = "SELECT d.*, a.email as email_anggota 
                FROM denda d
                JOIN anggota a ON d.id_anggota = a.id_anggota
                WHERE d.id_denda = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_denda);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Data denda tidak ditemukan");
        }
        
        $denda = $result->fetch_assoc();
        $stmt->close();
        
        // Update denda status
        $updateSql = "UPDATE denda SET status = ? WHERE id_denda = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $status, $id_denda);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Gagal mengupdate status denda: " . $updateStmt->error);
        }
        
        // If approved, also update pengembalian status
        if ($status === 'lunas') {
            $updatePengembalianSql = "UPDATE pengembalian SET status_denda = 'lunas' 
                                      WHERE id_pengembalian = ?";
            $updatePengembalianStmt = $conn->prepare($updatePengembalianSql);
            $updatePengembalianStmt->bind_param("i", $denda['id_pengembalian']);
            
            if (!$updatePengembalianStmt->execute()) {
                throw new Exception("Gagal mengupdate status pengembalian: " . $updatePengembalianStmt->error);
            }
            $updatePengembalianStmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = $status === 'lunas' 
            ? 'Pembayaran denda berhasil disetujui' 
            : 'Pembayaran denda ditolak';
        
        $updateStmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal memproses denda: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Get data for filters and denda
try {
    $conn = getConnection();
    
    if (!$conn) {
        throw new Exception("Koneksi database gagal: Tidak dapat membuat koneksi");
    }
    
    if ($conn->connect_error) {
        throw new Exception("Koneksi database gagal: " . $conn->connect_error);
    }

    // Build query for denda
    $whereClause = "WHERE DATE(d.tanggal_bayar) BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    $types = "ss";
    
    if ($status !== 'all') {
        $whereClause .= " AND d.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($search) {
        $whereClause .= " AND (a.username LIKE ? OR a.email LIKE ? OR b.judul_buku LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "sss";
    }
    
    // Get denda data with detailed information
    $sql = "SELECT d.*,
                   pg.id_peminjaman, pg.tanggal_kembali, pg.terlambat_hari,
                   p.tanggal_pinjam, p.tanggal_jatuh_tempo,
                   b.judul_buku, b.cover_buku,
                   a.username as nama_anggota, a.email as email_anggota,
                   CASE
                       WHEN pg.terlambat_hari > 0 THEN 'Terlambat'
                       ELSE 'Tepat Waktu'
                   END as status_keterlambatan
            FROM denda d
            JOIN pengembalian pg ON d.id_pengembalian = pg.id_pengembalian
            JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
            JOIN buku b ON pg.id_buku = b.id_buku
            JOIN anggota a ON d.id_anggota = a.id_anggota
            $whereClause
            ORDER BY d.tanggal_bayar DESC";
    
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result === false) {
        throw new Exception("Error query denda: " . $conn->error);
    }
    
    if ($result->num_rows > 0) {
        while ($denda = $result->fetch_assoc()) {
            $denda_list[] = $denda;
        }
    }
    
    // Get statistics
    $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'menunggu_approval' THEN 1 ELSE 0 END) as menunggu_approval,
                    SUM(CASE WHEN status = 'lunas' THEN 1 ELSE 0 END) as lunas,
                    SUM(CASE WHEN status = 'belum_lunas' THEN 1 ELSE 0 END) as belum_lunas,
                    SUM(jumlah_denda) as total_denda
                 FROM denda
                 WHERE DATE(tanggal_bayar) BETWEEN ? AND ?";
    
    $statsStmt = $conn->prepare($statsSql);
    $statsStmt->bind_param("ss", $start_date, $end_date);
    $statsStmt->execute();
    $statsResult = $statsStmt->get_result();
    $stats = $statsResult->fetch_assoc();
    
    // Initialize stats if empty
    if (!$stats) {
        $stats = [
            'total' => 0,
            'menunggu_approval' => 0,
            'lunas' => 0,
            'belum_lunas' => 0,
            'total_denda' => 0
        ];
    }
    
    $stmt->close();
    $statsStmt->close();
    $conn->close();
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    $denda_list = [];
    $stats = [
        'total' => 0,
        'menunggu_approval' => 0,
        'lunas' => 0,
        'belum_lunas' => 0,
        'total_denda' => 0
    ];
}

// Get pending count for sidebar badge
$conn = getConnection();

// Get pending count for sidebar badge
$pending_loans_count = 0;
$pending_returns_count = 0;
$total_pending_for_sidebar = 0;

$pending_loans_result = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'");
if ($pending_loans_result) {
    $pending_loans_count = $pending_loans_result->fetch_assoc()['total'];
    $pending_loans_result->free();
}

$pending_returns_result = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'");
if ($pending_returns_result) {
    $pending_returns_count = $pending_returns_result->fetch_assoc()['total'];
    $pending_returns_result->free();
}

// TAMBAHKAN QUERY INI UNTUK MENGHITUNG PEMBAYARAN DENDA PENDING
// HITUNG DENDA YANG MENUNGGU APPROVAL (SIMPLE VERSION)
$pending_denda_result = $conn->query("SELECT COUNT(*) as total FROM denda WHERE status = 'menunggu_approval'");
$pending_denda_count = $pending_denda_result ? $pending_denda_result->fetch_assoc()['total'] : 0;

// UPDATE TOTAL SEMUA REQUEST
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count + $pending_denda_count;

// Update total pending count to include denda payments
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count + $pending_denda_count;

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Digital Library Admin</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #4e73df;
            --primary-light: #e3ebf7;
            --sidebar-width: 220px;
            --topbar-height: 60px;
            --transition: all 0.3s ease;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            font-size: 0.9rem;
        }

        /* Main Layout */
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .content-wrapper {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            max-width: calc(100vw - var(--sidebar-width));
        }

        @media (max-width: 992px) {
            .content-wrapper {
                margin-left: 0;
                max-width: 100vw;
            }
            
            .content-wrapper.mobile-open {
                margin-left: var(--sidebar-width);
                max-width: calc(100vw - var(--sidebar-width));
            }
        }

        /* Topbar */
        .topbar {
            height: var(--topbar-height);
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-toggle {
            background: none;
            border: none;
            color: var(--gray-600);
            font-size: 1.1rem;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .sidebar-toggle:hover {
            background: var(--gray-200);
            color: var(--primary-color);
        }

        .page-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .page-title i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-icon {
            background: none;
            border: none;
            color: var(--gray-600);
            font-size: 1.1rem;
            padding: 6px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .btn-icon:hover {
            background: var(--gray-200);
            color: var(--primary-color);
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: white;
            box-shadow: 3px 0 20px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .sidebar-logo i {
            font-size: 1.3rem;
        }

        .sidebar-menu {
            flex: 1;
            padding: 0.5rem 0;
            overflow-y: auto;
        }

        .sidebar-menu .nav-link {
            display: flex;
            align-items: center;
            padding: 10px 1rem;
            color: var(--gray-700);
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: var(--transition);
            font-size: 0.85rem;
        }

        .sidebar-menu .nav-link i {
            width: 18px;
            margin-right: 10px;
            font-size: 1rem;
            color: var(--gray-600);
        }

        .sidebar-menu .nav-link:hover {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }

        .sidebar-menu .nav-link:hover i {
            color: var(--primary-color);
        }

        .sidebar-menu .nav-link.active {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            font-weight: 600;
        }

        .sidebar-menu .nav-link.active i {
            color: var(--primary-color);
        }

        .sidebar-menu .badge {
            font-size: 0.7rem;
            padding: 3px 6px;
        }

        .sidebar-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--gray-200);
            text-align: center;
            font-size: 0.8rem;
        }

        /* Dropdown menu in sidebar */
        .nav-item.dropdown .dropdown-menu {
            position: relative;
            float: none;
            width: 100%;
            border: none;
            box-shadow: none;
            margin: 0;
            padding: 0;
            background: var(--gray-100);
        }

        .nav-item.dropdown .dropdown-item {
            padding: 8px 1rem 8px 2.2rem;
            color: var(--gray-700);
            font-weight: 500;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .nav-item.dropdown .dropdown-item:hover {
            background-color: var(--primary-light);
            color: var(--primary-color);
        }

        .nav-item.dropdown .dropdown-item.active {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }

        .nav-item.dropdown .dropdown-toggle::after {
            margin-left: auto;
            transition: transform 0.3s;
        }

        .nav-item.dropdown.show .dropdown-toggle::after {
            transform: rotate(180deg);
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }
        }

        /* Main Content */
        .main-content {
            padding: 15px;
            min-height: calc(100vh - var(--topbar-height));
            max-width: 100%;
            box-sizing: border-box;
        }

        /* Card Styles */
        .card {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 10px;
            transition: transform 0.2s;
            height: 100%;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            border-radius: 10px 10px 0 0 !important;
            border: none;
            font-weight: 600;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
        }

        .alert {
            border-radius: 8px;
            border: none;
            font-size: 0.85rem;
            padding: 0.75rem 1rem;
        }
        
        /* Stats Cards */
        .stat-card {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 15px;
            color: white;
            border: none;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .stat-card-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }
        
        .stat-card-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .stat-card-info {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }
        
        .stat-card-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .stat-card h5 {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .stat-card h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0;
        }
        
        .stat-card i {
            font-size: 1.5rem !important;
        }
        
        /* Denda Table Styles */
        .book-cover-mini {
            width: 35px;
            height: 50px;
            border-radius: 6px;
            overflow: hidden;
            background: var(--gray-200);
            margin-right: 8px;
            flex-shrink: 0;
        }
        
        .book-cover-mini img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }
        
        .empty-state h4 {
            font-size: 1rem;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            font-size: 0.85rem;
        }
        
        /* Table Styles */
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            margin: 0 -0.5rem;
            width: calc(100% + 1rem);
        }
        
        .table {
            margin-bottom: 0;
            font-size: 0.85rem;
            width: 100%;
            table-layout: fixed;
        }
        
        .table thead th {
            background-color: var(--primary-light);
            border-bottom: 2px solid var(--primary-color);
            color: var(--gray-800);
            font-weight: 600;
            padding: 12px 8px;
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 10px 8px;
            vertical-align: middle;
            border-color: var(--gray-200);
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .table tbody tr:hover {
            background-color: rgba(78, 115, 223, 0.05);
        }
        
        /* Badges */
        .badge-status {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }
        
        .badge-warning {
            background: rgba(241, 196, 15, 0.1);
            color: #f1c40f;
            border: 1px solid rgba(241, 196, 15, 0.3);
        }
        
        .badge-danger {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }
        
        .badge-info {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }
        
        /* Button Actions */
        .btn-group .btn {
            padding: 5px 10px;
            font-size: 0.8rem;
        }
        
        .btn {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        /* Currency Format */
        .currency {
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        
        /* Modal Styles */
        .modal-xl {
            max-width: 90%;
        }
        
        /* Bukti Pembayaran Styles */
        #buktiImageContainer img {
            max-height: 60vh;
            object-fit: contain;
        }
        
        #buktiPdf {
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            :root {
                --sidebar-width: 200px;
            }
            
            .stat-card h2 {
                font-size: 1.3rem;
            }
            
            .table {
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 992px) {
            .table-responsive {
                margin: 0;
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .stat-card h2 {
                font-size: 1.2rem;
            }
            
            .stat-card i {
                font-size: 1.3rem !important;
            }
            
            .table thead th, 
            .table tbody td {
                padding: 8px 6px;
                font-size: 0.8rem;
            }
            
            .book-cover-mini {
                width: 30px;
                height: 40px;
            }
            
            .btn-group {
                flex-direction: column;
                gap: 3px;
            }
            
            .btn-group .btn {
                margin-bottom: 3px;
                width: 100%;
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            
            .btn {
                padding: 5px 10px;
                font-size: 0.8rem;
            }
            
            .main-content {
                padding: 10px;
            }
            
            .card-header {
                padding: 0.75rem;
            }
        }
        
        @media (max-width: 576px) {
            :root {
                --sidebar-width: 180px;
                --topbar-height: 55px;
            }
            
            .topbar {
                padding: 0 0.75rem;
            }
            
            .main-content {
                padding: 8px;
            }
            
            .page-title {
                font-size: 0.95rem;
            }
            
            .stat-card {
                padding: 10px;
            }
            
            .stat-card h2 {
                font-size: 1rem;
            }
            
            .stat-card i {
                font-size: 1.2rem !important;
            }
            
            .book-cover-mini {
                width: 25px;
                height: 35px;
            }
            
            .table thead th, 
            .table tbody td {
                padding: 6px 4px;
                font-size: 0.75rem;
            }
            
            .badge-status {
                font-size: 0.65rem;
                padding: 2px 6px;
            }
        }
        
        /* Compact form controls */
        .form-control, .form-select {
            padding: 6px 12px;
            font-size: 0.85rem;
            border-radius: 6px;
        }
        
        .form-label {
            font-size: 0.85rem;
            margin-bottom: 5px;
        }
        
        .row.g-3 > div {
            padding: 0 5px;
        }
        
        .row.g-3 {
            margin: 0 -5px;
        }
        
        /* Container adjustments */
        .container-fluid {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        /* Ensure content fits */
        .col-md-3, .col-md-4, .col-md-6, .col-md-9 {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        .row {
            margin-left: -0.5rem;
            margin-right: -0.5rem;
        }
        
        /* Card body padding */
        .card-body {
            padding: 1rem;
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 0.75rem;
            }
        }
        
        /* Adjust column widths for table */
        .table th:nth-child(1) { width: 40px; } /* # */
        .table th:nth-child(2) { width: 150px; } /* Anggota */
        .table th:nth-child(3) { width: 200px; } /* Buku */
        .table th:nth-child(4) { width: 120px; } /* Jumlah Denda */
        .table th:nth-child(5) { width: 100px; } /* Keterlambatan */
        .table th:nth-child(6) { width: 100px; } /* Metode Bayar */
        .table th:nth-child(7) { width: 120px; } /* Tanggal Bayar */
        .table th:nth-child(8) { width: 100px; } /* Status */
        .table th:nth-child(9) { width: 180px; } /* Aksi */
        
        @media (max-width: 1200px) {
            .table th:nth-child(2) { width: 130px; }
            .table th:nth-child(3) { width: 180px; }
            .table th:nth-child(9) { width: 160px; }
        }
        
        @media (max-width: 992px) {
            .table th:nth-child(2) { width: 120px; }
            .table th:nth-child(3) { width: 150px; }
            .table th:nth-child(9) { width: 140px; }
        }
        
        @media (max-width: 768px) {
            .table th, .table td {
                width: auto !important;
            }
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-book-reader"></i>
                    <span>Digital Library</span>
                </div>
            </div>

            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    
                    <!-- Dropdown Kelola -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex justify-content-between align-items-center <?php echo strpos($current_page, 'kelola') !== false ? 'active' : ''; ?>" 
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div>
                                <i class="fas fa-cog me-2"></i>
                                <span>Kelola</span>
                            </div>
                            <?php if ($total_pending_for_sidebar > 0): ?>
                            <span class="badge bg-danger"><?php echo $total_pending_for_sidebar; ?></span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'kelola_buku.php' ? 'active' : ''; ?>" href="kelola_buku.php">
                                    <i class="fas fa-book me-2"></i>Buku
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'kelola_user.php' ? 'active' : ''; ?>" href="kelola_user.php">
                                    <i class="fas fa-users me-2"></i>User
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'kelola_kategori.php' ? 'active' : ''; ?>" href="kelola_kategori.php">
                                    <i class="fas fa-tags me-2"></i>Kategori
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'kelola_penerbit.php' ? 'active' : ''; ?>" href="kelola_penerbit.php">
                                    <i class="fas fa-building me-2"></i>Penerbit
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'kelola_penulis.php' ? 'active' : ''; ?>" href="kelola_penulis.php">
                                    <i class="fas fa-user-edit me-2"></i>Penulis
                                </a>
                            </li>
                            <li>
            <a class="dropdown-item <?php echo $current_page == 'kelola_denda.php' ? 'active' : ''; ?>" href="kelola_denda.php">
                <i class="fas fa-money-bill-wave me-2"></i>Denda
                <?php if ($pending_denda_count > 0): ?>
                <span class="badge bg-danger float-end"><?php echo $pending_denda_count; ?></span>
                <?php endif; ?>
            </a>
        </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'kelola_peminjaman_pending.php' ? 'active' : ''; ?>" href="kelola_peminjaman_pending.php">
                                    <i class="fas fa-clock me-2"></i>Request
                                    <?php if ($total_pending_for_sidebar > 0): ?>
                                    <span class="badge bg-danger float-end"><?php echo $total_pending_for_sidebar; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </li>
                    
                    <!-- Dropdown Laporan -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo strpos($current_page, 'laporan') !== false ? 'active' : ''; ?>" 
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-chart-bar"></i>
                            <span>Laporan</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'laporan_buku.php' ? 'active' : ''; ?>" href="laporan_buku.php">
                                    <i class="fas fa-book me-2"></i>Buku
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'laporan_peminjaman.php' ? 'active' : ''; ?>" href="laporan_peminjaman.php">
                                    <i class="fas fa-file-alt me-2"></i>Peminjaman
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'laporan_pengguna.php' ? 'active' : ''; ?>" href="laporan_pengguna.php">
                                    <i class="fas fa-users-cog me-2"></i>Pengguna
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo $current_page == 'laporan_keseluruhan.php' ? 'active' : ''; ?>" href="laporan_keseluruhan.php">
                                    <i class="fas fa-chart-line me-2"></i>Keseluruhan
                                </a>
                            </li>
                        </ul>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="confirmLogout()">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-footer">
                <small class="text-muted">Digital Library © <?php echo date('Y'); ?></small>
            </div>
        </div>

        <!-- Content Wrapper -->
        <div class="content-wrapper" id="contentWrapper">
            <!-- Topbar -->
            <nav class="topbar">
                <div class="topbar-left">
                    <button class="btn sidebar-toggle d-lg-none" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title">
                        <i class="<?php echo $page_icon; ?>"></i>
                        <?php echo $page_title; ?>
                    </h1>
                </div>
                
                <div class="topbar-right">
                    <!-- Removed notifications icon as requested -->
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <div class="container-fluid py-3">
                    <!-- Messages -->
                    <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Sukses!</strong> <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Error!</strong> <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Stats Cards -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Denda</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['total'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-money-bill-wave opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-warning">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Menunggu Approval</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['menunggu_approval'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-clock opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Sudah Lunas</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['lunas'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-check-circle opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-danger">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Belum Lunas</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['belum_lunas'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-times-circle opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Denda Amount -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="stat-card stat-card-info">
                                <div class="row align-items-center">
                                    <div class="col-md-9">
                                        <h5 class="mb-2">Total Nilai Denda</h5>
                                        <h1 class="mb-0 currency">Rp <?php echo number_format($stats['total_denda'] ?? 0, 0, ',', '.'); ?></h1>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <i class="fas fa-coins fa-3x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Filter Data
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label">Tanggal Mulai</label>
                                    <input type="date" class="form-control" name="start_date"
                                           value="<?php echo htmlspecialchars($start_date); ?>" id="startDate">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tanggal Akhir</label>
                                    <input type="date" class="form-control" name="end_date"
                                           value="<?php echo htmlspecialchars($end_date); ?>" id="endDate">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>Semua Status</option>
                                        <option value="menunggu_approval" <?php echo $status == 'menunggu_approval' ? 'selected' : ''; ?>>Menunggu Approval</option>
                                        <option value="lunas" <?php echo $status == 'lunas' ? 'selected' : ''; ?>>Lunas</option>
                                        <option value="belum_lunas" <?php echo $status == 'belum_lunas' ? 'selected' : ''; ?>>Belum Lunas</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Pencarian</label>
                                    <input type="text" class="form-control" name="search"
                                           placeholder="Nama anggota, email, judul buku..."
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-12">
                                    <div class="d-flex gap-2 justify-content-end mt-2">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-filter me-2"></i>Terapkan Filter
                                        </button>
                                        <a href="kelola_denda.php" class="btn btn-outline-secondary btn-sm">
                                            <i class="fas fa-redo me-2"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Denda Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Data Denda
                                <span class="badge bg-primary ms-2"><?php echo count($denda_list); ?> transaksi</span>
                            </h5>
                        </div>
                        <div class="card-body p-2">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="40">#</th>
                                            <th>Anggota</th>
                                            <th>Buku</th>
                                            <th>Jumlah Denda</th>
                                            <th>Keterlambatan</th>
                                            <th>Metode Bayar</th>
                                            <th>Tanggal Bayar</th>
                                            <th>Status</th>
                                            <th width="180">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($denda_list)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">
                                                <div class="empty-state">
                                                    <i class="fas fa-money-bill-wave fa-2x text-muted mb-3"></i>
                                                    <h5 class="text-muted">Tidak ada data denda</h5>
                                                    <p class="text-muted mb-0">Silakan perbaiki filter pencarian atau tanggal</p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($denda_list as $index => $denda):
                                                $status_class = '';
                                                $status_text = '';

                                                switch ($denda['status']) {
                                                    case 'menunggu_approval':
                                                        $status_class = 'warning';
                                                        $status_text = 'Menunggu Approval';
                                                        break;
                                                    case 'lunas':
                                                        $status_class = 'success';
                                                        $status_text = 'Lunas';
                                                        break;
                                                    case 'belum_lunas':
                                                        $status_class = 'danger';
                                                        $status_text = 'Belum Lunas';
                                                        break;
                                                }
                                            ?>
                                            <tr>
                                                <td class="text-center"><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="fw-bold text-truncate" style="max-width: 130px;"><?php echo htmlspecialchars($denda['nama_anggota']); ?></div>
                                                    <small class="text-muted text-truncate d-block" style="max-width: 130px;"><?php echo htmlspecialchars($denda['email_anggota']); ?></small>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="book-cover-mini">
                                                            <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $denda['cover_buku'] ?: 'default.jpg'; ?>"
                                                                 alt="<?php echo htmlspecialchars($denda['judul_buku']); ?>"
                                                                 onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                                        </div>
                                                        <div class="text-truncate" style="max-width: 150px;">
                                                            <div class="fw-bold text-truncate"><?php echo htmlspecialchars($denda['judul_buku']); ?></div>
                                                            <small class="text-muted text-truncate d-block">Kembali: <?php echo formatDate($denda['tanggal_kembali']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-danger fw-bold currency">Rp <?php echo number_format($denda['jumlah_denda'], 0, ',', '.'); ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($denda['terlambat_hari'] > 0): ?>
                                                    <span class="badge-status badge-danger">
                                                        <?php echo $denda['terlambat_hari']; ?> hari
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="badge-status badge-success">Tepat Waktu</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($denda['metode_bayar']): ?>
                                                    <span class="badge-status badge-info"><?php echo ucfirst($denda['metode_bayar']); ?></span>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div><?php echo formatDate($denda['tanggal_bayar']); ?></div>
                                                    <small class="text-muted"><?php echo formatDate($denda['tanggal_bayar'], 'H:i'); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge-status badge-<?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($denda['status'] === 'menunggu_approval'): ?>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-success"
                                                                onclick="processDenda(<?php echo $denda['id_denda']; ?>, 'approve', '<?php echo addslashes($denda['nama_anggota']); ?>')"
                                                                title="Setujui Pembayaran">
                                                            <i class="fas fa-check me-1"></i>Setujui
                                                        </button>
                                                        <button type="button" class="btn btn-danger"
                                                                onclick="processDenda(<?php echo $denda['id_denda']; ?>, 'reject', '<?php echo addslashes($denda['nama_anggota']); ?>')"
                                                                title="Tolak Pembayaran">
                                                            <i class="fas fa-times me-1"></i>Tolak
                                                        </button>
                                                        <?php if ($denda['bukti_bayar']): ?>
                                                        <button type="button" class="btn btn-info"
                                                                onclick="viewBukti('<?php echo SITE_URL; ?>uploads/denda/<?php echo $denda['bukti_bayar']; ?>')"
                                                                title="Lihat Bukti Pembayaran">
                                                            <i class="fas fa-eye me-1"></i>Bukti
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php elseif ($denda['bukti_bayar']): ?>
                                                    <button type="button" class="btn btn-sm btn-info"
                                                            onclick="viewBukti('<?php echo SITE_URL; ?>uploads/denda/<?php echo $denda['bukti_bayar']; ?>')"
                                                            title="Lihat Bukti Pembayaran">
                                                        <i class="fas fa-eye me-1"></i>Lihat Bukti
                                                    </button>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-gradient-primary text-white">
                    <h5 class="modal-title" id="logoutModalLabel">
                        <i class="fas fa-sign-out-alt me-2"></i>Konfirmasi Logout
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-question-circle fa-3x text-warning mb-2"></i>
                        <h5 class="fw-bold">Apakah Anda yakin ingin logout?</h5>
                        <p class="text-muted mb-0">Anda akan keluar dari akun admin dan kembali ke halaman utama.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Batal
                    </button>
                    <a href="<?php echo SITE_URL; ?>auth/logout.php" class="btn btn-danger btn-sm">
                        <i class="fas fa-sign-out-alt me-2"></i>Ya, Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for viewing payment proof -->
    <div class="modal fade" id="buktiModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-receipt me-2"></i>Bukti Pembayaran
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="buktiImageContainer" style="display: none;">
                        <img id="buktiImage" src="" alt="Bukti Pembayaran" class="img-fluid rounded shadow">
                    </div>
                    <div id="buktiPdfContainer" style="display: none;">
                        <iframe id="buktiPdf" src="" width="100%" height="500px" style="border: none; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></iframe>
                    </div>
                    <div id="buktiLoading" class="text-muted py-4">
                        <i class="fas fa-spinner fa-spin fa-2x me-2"></i><br>
                        <span class="mt-2">Memuat bukti pembayaran...</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Sidebar toggle for mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
            document.getElementById('contentWrapper').classList.toggle('mobile-open');
        });

        // Date validation
        document.addEventListener('DOMContentLoaded', function() {
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');

            startDate.addEventListener('change', function() {
                endDate.min = this.value;
            });

            endDate.addEventListener('change', function() {
                if (this.value < startDate.value) {
                    alert('Tanggal akhir tidak boleh sebelum tanggal mulai');
                    this.value = startDate.value;
                }
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    if (alert.classList.contains('show')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Initialize dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
        });
        
        // Show loading animation
        function showLoading(message = 'Memproses...') {
            Swal.fire({
                title: message,
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });
        }
        
        // Show success message
        function showSuccess(message) {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: message,
                timer: 2000,
                showConfirmButton: false
            });
        }
        
        // Show error message
        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal!',
                text: message
            });
        }
        
        // Confirm action
        function confirmAction(message, callback) {
            Swal.fire({
                title: 'Konfirmasi',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Lanjutkan',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    callback();
                }
            });
        }
        
        // Process denda approval/rejection
        function processDenda(id_denda, action, nama_anggota) {
            const actionText = action === 'approve' ? 'menyetujui' : 'menolak';
            const confirmText = `Apakah Anda yakin ingin ${actionText} pembayaran denda dari "${nama_anggota}"?`;
            
            confirmAction(confirmText, function() {
                showLoading('Memproses pembayaran denda...');
                
                const formData = new FormData();
                formData.append('action', action === 'approve' ? 'approve_denda' : 'reject_denda');
                formData.append('id_denda', id_denda);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                    }
                    return response.text();
                })
                .then(() => {
                    // Reload page to show changes
                    location.reload();
                })
                .catch(error => {
                    Swal.close();
                    showError('Terjadi kesalahan. Silakan coba lagi.');
                });
            });
        }

        // View payment proof
        function viewBukti(fileUrl) {
            // Reset all containers
            document.getElementById('buktiImageContainer').style.display = 'none';
            document.getElementById('buktiPdfContainer').style.display = 'none';
            document.getElementById('buktiLoading').style.display = 'block';

            // Show loading modal
            const modal = new bootstrap.Modal(document.getElementById('buktiModal'));
            modal.show();

            // Check file extension to determine type
            const fileExtension = fileUrl.split('.').pop().toLowerCase();

            if (fileExtension === 'pdf') {
                // Handle PDF files
                document.getElementById('buktiPdf').src = fileUrl;
                document.getElementById('buktiLoading').style.display = 'none';
                document.getElementById('buktiPdfContainer').style.display = 'block';
            } else {
                // Handle image files
                const img = document.getElementById('buktiImage');
                img.onload = function() {
                    document.getElementById('buktiLoading').style.display = 'none';
                    document.getElementById('buktiImageContainer').style.display = 'block';
                };
                img.onerror = function() {
                    document.getElementById('buktiLoading').innerHTML = 
                        '<i class="fas fa-exclamation-triangle text-danger fa-2x me-2"></i><br>' +
                        '<span class="mt-2">Gagal memuat bukti pembayaran</span>';
                };
                img.src = fileUrl;
            }
        }
        
        // Logout confirmation function
        function confirmLogout() {
            const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
            modal.show();
        }

        // Format date function
        function formatDate(dateString, format = 'd/m/Y') {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            const day = date.getDate().toString().padStart(2, '0');
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const year = date.getFullYear();
            const hours = date.getHours().toString().padStart(2, '0');
            const minutes = date.getMinutes().toString().padStart(2, '0');
            
            if (format === 'H:i') return `${hours}:${minutes}`;
            return `${day}/${month}/${year}`;
        }
    </script>
</body>
</html>