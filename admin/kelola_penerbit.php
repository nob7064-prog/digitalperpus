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
$page_title = 'Kelola Penerbit';
$page_icon = 'fas fa-building';

// Initialize variables
$conn = getConnection();
$message = '';
$message_type = '';
$edit_id = null;
$edit_data = null;

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $nama_penerbit = clean_input($_POST['nama_penerbit']);
        $deskripsi = clean_input($_POST['deskripsi'] ?? '');
        $alamat = clean_input($_POST['alamat'] ?? '');
        $telepon = clean_input($_POST['telepon'] ?? '');

        $sql = "INSERT INTO penerbit (nama_penerbit, deskripsi, alamat, telepon) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $nama_penerbit, $deskripsi, $alamat, $telepon);
        
        if ($stmt->execute()) {
            $message = 'Penerbit berhasil ditambahkan';
            $message_type = 'success';
        } else {
            $message = 'Gagal menambahkan penerbit';
            $message_type = 'danger';
        }
        $stmt->close();
    }
    elseif ($action === 'edit') {
        $id = $_POST['id'];
        $nama_penerbit = clean_input($_POST['nama_penerbit']);
        $deskripsi = clean_input($_POST['deskripsi'] ?? '');
        $alamat = clean_input($_POST['alamat'] ?? '');
        $telepon = clean_input($_POST['telepon'] ?? '');

        $sql = "UPDATE penerbit SET nama_penerbit = ?, deskripsi = ?, alamat = ?, telepon = ? 
                WHERE id_penerbit = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", $nama_penerbit, $deskripsi, $alamat, $telepon, $id);
        
        if ($stmt->execute()) {
            $message = 'Penerbit berhasil diperbarui';
            $message_type = 'success';
        } else {
            $message = 'Gagal memperbarui penerbit';
            $message_type = 'danger';
        }
        $stmt->close();
    }
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        
        // Check if publisher is used
        $check = $conn->prepare("SELECT COUNT(*) as total FROM buku WHERE id_penerbit = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result()->fetch_assoc();
        $check->close();
        
        if ($result['total'] > 0) {
            $message = 'Penerbit tidak dapat dihapus karena digunakan oleh ' . $result['total'] . ' buku';
            $message_type = 'danger';
        } else {
            $sql = "DELETE FROM penerbit WHERE id_penerbit = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $message = 'Penerbit berhasil dihapus';
                $message_type = 'success';
            } else {
                $message = 'Gagal menghapus penerbit';
                $message_type = 'danger';
            }
            $stmt->close();
        }
    }
}

// Check if edit mode
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM penerbit WHERE id_penerbit = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
    } else {
        header('Location: kelola_penerbit.php');
        exit();
    }
    $stmt->close();
}

// Get all publishers with book count
$publishers = $conn->query("
    SELECT p.*, 
           (SELECT COUNT(*) FROM buku WHERE id_penerbit = p.id_penerbit) as total_buku,
           (SELECT COUNT(*) FROM peminjaman pm 
            JOIN buku b ON pm.id_buku = b.id_buku 
            WHERE b.id_penerbit = p.id_penerbit) as total_pinjam
    FROM penerbit p
    ORDER BY p.nama_penerbit
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
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
            --sidebar-width: 250px;
            --topbar-height: 70px;
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
        }

        @media (max-width: 992px) {
            .content-wrapper {
                margin-left: 0;
            }
            
            .content-wrapper.mobile-open {
                margin-left: var(--sidebar-width);
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
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .sidebar-toggle {
            background: none;
            border: none;
            color: var(--gray-600);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 8px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .sidebar-toggle:hover {
            background: var(--gray-200);
            color: var(--primary-color);
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .page-title i {
            color: var(--primary-color);
            margin-right: 10px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-icon {
            background: none;
            border: none;
            color: var(--gray-600);
            font-size: 1.25rem;
            padding: 8px;
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
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        .sidebar-logo i {
            font-size: 1.5rem;
        }

        .sidebar-menu {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .sidebar-menu .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 1.5rem;
            color: var(--gray-700);
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: var(--transition);
        }

        .sidebar-menu .nav-link i {
            width: 20px;
            margin-right: 12px;
            font-size: 1.1rem;
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
            font-size: 0.75rem;
            padding: 4px 8px;
        }

        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--gray-200);
            text-align: center;
            font-size: 0.85rem;
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
            padding: 10px 1.5rem 10px 2.5rem;
            color: var(--gray-700);
            font-weight: 500;
            border-left: 3px solid transparent;
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
            padding: 20px;
            min-height: calc(100vh - var(--topbar-height));
        }

        /* Card Styles */
        .card {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 12px;
            transition: transform 0.2s;
            height: 100%;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            border-radius: 12px 12px 0 0 !important;
            border: none;
            font-weight: 600;
            padding: 1rem 1.25rem;
        }

        .alert {
            border-radius: 8px;
            border: none;
        }
        
        /* Stats Cards */
        .stat-card {
            padding: 15px;
            border-radius: 12px;
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
        
        .stat-card h5 {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .stat-card h2 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #dee2e6;
        }
        
        .empty-state h4 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            font-size: 0.95rem;
        }
        
        /* Table Styles */
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: var(--primary-light);
            border-bottom: 2px solid var(--primary-color);
            color: var(--gray-800);
            font-weight: 600;
            padding: 15px;
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-color: var(--gray-200);
        }
        
        .table tbody tr:hover {
            background-color: rgba(78, 115, 223, 0.05);
        }
        
        /* Badges */
        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }
        
        .badge-danger {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stat-card h2 {
                font-size: 1.5rem;
            }
            
            .table thead th, 
            .table tbody td {
                padding: 10px;
                font-size: 0.9rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                margin-bottom: 5px;
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .topbar {
                padding: 0 1rem;
            }
            
            .main-content {
                padding: 15px;
            }
            
            .page-title {
                font-size: 1.1rem;
            }
            
            .stat-card {
                padding: 12px;
            }
            
            .stat-card h2 {
                font-size: 1.3rem;
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    
                    <!-- Dropdown Kelola -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex justify-content-between align-items-center active" 
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
                                <a class="dropdown-item" href="kelola_buku.php">
                                    <i class="fas fa-book me-2"></i>Buku
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="kelola_user.php">
                                    <i class="fas fa-users me-2"></i>User
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="kelola_kategori.php">
                                    <i class="fas fa-tags me-2"></i>Kategori
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item active" href="kelola_penerbit.php">
                                    <i class="fas fa-building me-2"></i>Penerbit
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="kelola_penulis.php">
                                    <i class="fas fa-user-edit me-2"></i>Penulis
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="kelola_denda.php">
                                    <i class="fas fa-money-bill-wave me-2"></i>Denda
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="kelola_peminjaman_pending.php">
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
                        <a class="nav-link dropdown-toggle" 
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-chart-bar"></i>
                            <span>Laporan</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="laporan_buku.php">
                                    <i class="fas fa-book me-2"></i>Buku
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="laporan_peminjaman.php">
                                    <i class="fas fa-file-alt me-2"></i>Peminjaman
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="laporan_pengguna.php">
                                    <i class="fas fa-users-cog me-2"></i>Pengguna
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="laporan_keseluruhan.php">
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
                <div class="container-fluid py-4">
                    <!-- Messages -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="<?php echo $message_type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Penerbit</h5>
                                        <h2 class="mb-0"><?php echo count($publishers); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-building fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Buku</h5>
                                        <h2 class="mb-0">
                                            <?php 
                                                $total_buku = array_sum(array_column($publishers, 'total_buku'));
                                                echo $total_buku;
                                            ?>
                                        </h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-book fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-warning">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Dipinjam</h5>
                                        <h2 class="mb-0">
                                            <?php 
                                                $total_pinjam = array_sum(array_column($publishers, 'total_pinjam'));
                                                echo $total_pinjam;
                                            ?>
                                        </h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exchange-alt fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Publishers Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Daftar Penerbit
                                <span class="badge bg-primary ms-2"><?php echo count($publishers); ?></span>
                            </h5>
                            <div class="d-flex gap-2">
                                <div class="input-group" style="width: 300px;">
                                    <input type="text" class="form-control" id="searchPublisher" placeholder="Cari penerbit...">
                                    <button class="btn btn-outline-secondary" type="button">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPublisherModal">
                                    <i class="fas fa-plus me-1"></i>Tambah Penerbit
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Penerbit</th>
                                            <th>Kontak</th>
                                            <th>Alamat</th>
                                            <th>Statistik</th>
                                            <th width="150">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($publishers)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5">
                                                <div class="empty-state">
                                                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                                                    <h5 class="text-muted">Belum ada penerbit</h5>
                                                    <p class="text-muted mb-0">Silakan tambahkan penerbit baru</p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($publishers as $index => $pub): ?>
                                            <tr data-publisher-name="<?php echo strtolower($pub['nama_penerbit']); ?>">
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($pub['nama_penerbit']); ?></div>
                                                    <?php if (!empty($pub['website'])): ?>
                                                    <small>
                                                        <a href="<?php echo htmlspecialchars($pub['website']); ?>" target="_blank" class="text-primary">
                                                            <i class="fas fa-globe me-1"></i>Website
                                                        </a>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php if (!empty($pub['telepon'])): ?>
                                                        <div><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($pub['telepon']); ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($pub['deskripsi'])): ?>
                                                        <div class="text-muted small mt-1"><?php echo htmlspecialchars($pub['deskripsi']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($pub['alamat']): ?>
                                                    <p class="mb-0 text-truncate" style="max-width: 200px;" 
                                                       title="<?php echo htmlspecialchars($pub['alamat']); ?>">
                                                        <?php echo htmlspecialchars($pub['alamat']); ?>
                                                    </p>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-3">
                                                        <div class="text-center">
                                                            <div class="fw-bold text-primary"><?php echo $pub['total_buku']; ?></div>
                                                            <small class="text-muted">Buku</small>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="fw-bold text-success"><?php echo $pub['total_pinjam']; ?></div>
                                                            <small class="text-muted">Pinjam</small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary edit-publisher-btn"
                                                                data-id="<?php echo $pub['id_penerbit']; ?>"
                                                                data-nama="<?php echo htmlspecialchars($pub['nama_penerbit'], ENT_QUOTES); ?>"
                                                                data-deskripsi="<?php echo htmlspecialchars($pub['deskripsi'] ?? '', ENT_QUOTES); ?>"
                                                                data-alamat="<?php echo htmlspecialchars($pub['alamat'] ?? '', ENT_QUOTES); ?>"
                                                                data-telepon="<?php echo htmlspecialchars($pub['telepon'] ?? '', ENT_QUOTES); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger delete-publisher-btn" 
                                                                data-id="<?php echo $pub['id_penerbit']; ?>" 
                                                                data-name="<?php echo htmlspecialchars($pub['nama_penerbit']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
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
                    <div class="mb-4">
                        <i class="fas fa-question-circle fa-4x text-warning mb-3"></i>
                        <h5 class="fw-bold">Apakah Anda yakin ingin logout?</h5>
                        <p class="text-muted mb-0">Anda akan keluar dari akun admin dan kembali ke halaman utama.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Batal
                    </button>
                    <a href="<?php echo SITE_URL; ?>auth/logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Ya, Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Publisher Modal -->
    <div class="modal fade" id="addPublisherModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tambah Penerbit Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="addPublisherForm">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Penerbit <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_penerbit" required 
                                       placeholder="Contoh: Gramedia, Mizan, Erlangga">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telepon</label>
                                <input type="tel" class="form-control" name="telepon" 
                                       placeholder="(021) 12345678">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Deskripsi</label>
                                <input type="text" class="form-control" name="deskripsi" placeholder="Ringkasan tentang penerbit (opsional)">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Alamat</label>
                                <textarea class="form-control" name="alamat" rows="3" 
                                          placeholder="Alamat lengkap penerbit"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Penerbit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Publisher Modal -->
    <div class="modal fade" id="editPublisherModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Penerbit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="editPublisherModalForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editPublisherId">

                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Penerbit <span class="text-danger">*</span></label>
                                <input id="edit_nama_penerbit" type="text" class="form-control" name="nama_penerbit" required>
                                <div class="invalid-feedback" id="editPublisherNameError"></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telepon</label>
                                <input id="edit_telepon" type="tel" class="form-control" name="telepon" placeholder="(021) 12345678">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Deskripsi</label>
                                <input id="edit_deskripsi" type="text" class="form-control" name="deskripsi" placeholder="Ringkasan tentang penerbit (opsional)">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Alamat</label>
                                <textarea id="edit_alamat" class="form-control" name="alamat" rows="3" placeholder="Alamat lengkap penerbit"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deletePublisherModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="deletePublisherForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deletePublisherId">
                    
                    <div class="modal-body">
                        <p>Anda yakin ingin menghapus penerbit <strong id="deletePublisherName"></strong>?</p>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Data yang dihapus tidak dapat dikembalikan!
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
                    </div>
                </form>
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

        // Publisher search
        document.getElementById('searchPublisher').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr[data-publisher-name]');
            
            rows.forEach(row => {
                const publisherName = row.getAttribute('data-publisher-name');
                if (publisherName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Delete publisher confirmation
        document.querySelectorAll('.delete-publisher-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                
                document.getElementById('deletePublisherId').value = id;
                document.getElementById('deletePublisherName').textContent = name;
                
                const modal = new bootstrap.Modal(document.getElementById('deletePublisherModal'));
                modal.show();
            });
        });

        // Edit publisher: populate modal and show
        document.querySelectorAll('.edit-publisher-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const nama = this.getAttribute('data-nama') || '';
                const deskripsi = this.getAttribute('data-deskripsi') || '';
                const alamat = this.getAttribute('data-alamat') || '';
                const telepon = this.getAttribute('data-telepon') || '';

                document.getElementById('editPublisherId').value = id;
                document.getElementById('edit_nama_penerbit').value = nama;
                document.getElementById('edit_deskripsi').value = deskripsi;
                document.getElementById('edit_alamat').value = alamat;
                document.getElementById('edit_telepon').value = telepon;

                const modalEl = document.getElementById('editPublisherModal');
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            });
        });

        // Autofocus name input when edit modal shown and validate before submit
        const editModalEl = document.getElementById('editPublisherModal');
        if (editModalEl) {
            editModalEl.addEventListener('shown.bs.modal', function () {
                const nameInput = document.getElementById('edit_nama_penerbit');
                if (nameInput) nameInput.focus();
            });
        }

        const editForm = document.getElementById('editPublisherModalForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                const nameInput = document.getElementById('edit_nama_penerbit');
                if (!nameInput.value.trim()) {
                    e.preventDefault();
                    nameInput.classList.add('is-invalid');
                    return false;
                }
            });
        }
        
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
        
        // Logout confirmation function
        function confirmLogout() {
            const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
            modal.show();
        }

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
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
    </script>
</body>
</html>