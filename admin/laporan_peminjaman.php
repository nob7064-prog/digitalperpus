<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Only admin can access this page
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

// Set page variables
$page_title = 'Laporan Peminjaman & Pengembalian';
$page_icon = 'fas fa-chart-bar';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$kategori = $_GET['kategori'] ?? '';

// Database connection
$conn = getConnection();

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Build query
$whereClause = "WHERE DATE(p.tanggal_pinjam) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
$types = "ss";

if ($status) {
    $whereClause .= " AND p.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($kategori) {
    $whereClause .= " AND k.nama_kategori = ?";
    $params[] = $kategori;
    $types .= "s";
}

// Get borrowing data
$sql = "SELECT p.*, b.judul_buku, b.cover_buku, k.nama_kategori,
               a.username as anggota,
               (SELECT COUNT(*) FROM pengembalian pg WHERE pg.id_peminjaman = p.id_peminjaman) as sudah_kembali,
               (SELECT denda FROM pengembalian pg WHERE pg.id_peminjaman = p.id_peminjaman) as denda
        FROM peminjaman p
        JOIN buku b ON p.id_buku = b.id_buku
        JOIN kategori k ON b.id_kategori = k.id_kategori
        JOIN anggota a ON p.id_anggota = a.id_anggota
        $whereClause
        ORDER BY p.tanggal_pinjam DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$borrowings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$statsSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'dipinjam' THEN 1 ELSE 0 END) as sedang_dipinjam,
                SUM(CASE WHEN status = 'dikembalikan' THEN 1 ELSE 0 END) as sudah_dikembalikan,
                SUM(CASE WHEN status = 'terlambat' THEN 1 ELSE 0 END) as terlambat
             FROM peminjaman p
             JOIN buku b ON p.id_buku = b.id_buku
             JOIN kategori k ON b.id_kategori = k.id_kategori
             $whereClause";

$statsStmt = $conn->prepare($statsSql);
$statsStmt->bind_param($types, ...$params);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// Get categories for filter
$categories = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori")->fetch_all(MYSQLI_ASSOC);

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

        /* Stats Cards for Report */
        .stat-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.5rem;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .book-cover-mini {
            width: 40px;
            height: 55px;
            border-radius: 5px;
            overflow: hidden;
            background: #f3f4f6;
        }
        
        .book-cover-mini img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-weight: 500;
            color: #4b5563;
        }
        
        .summary-value {
            font-weight: 600;
            color: #1f2937;
        }
        
        .progress-stacked {
            height: 25px;
            border-radius: 6px;
            overflow: hidden;
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
        
        .badge-warning {
            background: rgba(241, 196, 15, 0.1);
            color: #f1c40f;
            border: 1px solid rgba(241, 196, 15, 0.3);
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
                        <a class="nav-link dropdown-toggle d-flex justify-content-between align-items-center" 
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
                                <a class="dropdown-item" href="kelola_penerbit.php">
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
                        <a class="nav-link dropdown-toggle active" 
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
                                <a class="dropdown-item active" href="laporan_peminjaman.php">
                                    <i class="fas fa-file-alt me-2"></i>Pinjam & Kembali
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
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-success" onclick="exportLoanReport()">
                                    <i class="fas fa-file-excel me-2"></i>Export Excel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Filter Laporan
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Tanggal Mulai</label>
                                    <input type="date" class="form-control" name="start_date" 
                                           value="<?php echo $start_date; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tanggal Akhir</label>
                                    <input type="date" class="form-control" name="end_date" 
                                           value="<?php echo $end_date; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">Semua Status</option>
                                        <option value="dipinjam" <?php echo $status == 'dipinjam' ? 'selected' : ''; ?>>Dipinjam</option>
                                        <option value="dikembalikan" <?php echo $status == 'dikembalikan' ? 'selected' : ''; ?>>Dikembalikan</option>
                                        <option value="terlambat" <?php echo $status == 'terlambat' ? 'selected' : ''; ?>>Terlambat</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kategori</label>
                                    <select class="form-select" name="kategori">
                                        <option value="">Semua Kategori</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['nama_kategori']); ?>"
                                            <?php echo $kategori == $cat['nama_kategori'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['nama_kategori']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-2"></i>Filter Data
                                        </button>
                                        <a href="laporan_peminjaman.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-redo me-2"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-primary">
                                        <i class="fas fa-exchange-alt"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['total'] ?? 0); ?></div>
                                    <div class="stat-label">Total Peminjaman</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-warning">
                                        <i class="fas fa-book-reader"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['sedang_dipinjam'] ?? 0); ?></div>
                                    <div class="stat-label">Sedang Dipinjam</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-success">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['sudah_dikembalikan'] ?? 0); ?></div>
                                    <div class="stat-label">Sudah Dikembalikan</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-danger">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['terlambat'] ?? 0); ?></div>
                                    <div class="stat-label">Terlambat</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                Data Peminjaman & Pengembalian
                                <span class="badge bg-primary ms-2"><?php echo count($borrowings); ?> transaksi</span>
                            </h5>
                            
                        </div>
                        <div class="card-body">
                            <div class="table-responsive" id="reportTable">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Buku</th>
                                            <th>Anggota</th>
                                            <th>Tanggal Pinjam</th>
                                            <th>Jatuh Tempo</th>
                                            <th>Status</th>
                                            <th>Denda</th>
                                            <th>Petugas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($borrowings)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <div class="empty-state">
                                                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                                                    <p class="text-muted mb-0">Tidak ada data peminjaman</p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($borrowings as $index => $borrow): 
                                                $status_class = '';
                                                $status_text = '';
                                                
                                                if ($borrow['status'] === 'dipinjam') {
                                                    $status_class = 'warning';
                                                    $status_text = 'Dipinjam';
                                                } elseif ($borrow['status'] === 'dikembalikan') {
                                                    $status_class = 'success';
                                                    $status_text = 'Dikembalikan';
                                                } else {
                                                    $status_class = 'danger';
                                                    $status_text = 'Terlambat';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="book-cover-mini me-3">
                                                            <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $borrow['cover_buku'] ?: 'default.jpg'; ?>" 
                                                                 alt="<?php echo htmlspecialchars($borrow['judul_buku']); ?>">
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($borrow['judul_buku']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($borrow['nama_kategori']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($borrow['anggota']); ?></div>
                                                </td>
                                                <td><?php echo formatDate($borrow['tanggal_pinjam']); ?></td>
                                                <td><?php echo formatDate($borrow['tanggal_jatuh_tempo']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($borrow['denda'] && $borrow['denda'] > 0): ?>
                                                    <span class="text-danger fw-bold"><?php echo formatCurrency($borrow['denda']); ?></span>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">Auto System</small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Summary -->
                            <?php if (!empty($borrowings)): ?>
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">Ringkasan Periode</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="summary-item">
                                                <span class="summary-label">Periode Laporan:</span>
                                                <span class="summary-value"><?php echo formatDate($start_date); ?> - <?php echo formatDate($end_date); ?></span>
                                            </div>
                                            <div class="summary-item">
                                                <span class="summary-label">Total Transaksi:</span>
                                                <span class="summary-value"><?php echo number_format($stats['total'] ?? 0); ?></span>
                                            </div>
                                            <div class="summary-item">
                                                <span class="summary-label">Status Filter:</span>
                                                <span class="summary-value"><?php echo $status ? ucfirst($status) : 'Semua Status'; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">Statistik Status</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="progress-stacked mb-3">
                                                <div class="progress" style="width: <?php echo (($stats['sedang_dipinjam'] ?? 0) / max(($stats['total'] ?? 0), 1)) * 100; ?>%">
                                                    <div class="progress-bar bg-warning" role="progressbar">Dipinjam</div>
                                                </div>
                                                <div class="progress" style="width: <?php echo (($stats['sudah_dikembalikan'] ?? 0) / max(($stats['total'] ?? 0), 1)) * 100; ?>%">
                                                    <div class="progress-bar bg-success" role="progressbar">Dikembalikan</div>
                                                </div>
                                                <div class="progress" style="width: <?php echo (($stats['terlambat'] ?? 0) / max(($stats['total'] ?? 0), 1)) * 100; ?>%">
                                                    <div class="progress-bar bg-danger" role="progressbar">Terlambat</div>
                                                </div>
                                            </div>
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <div class="text-warning fw-bold"><?php echo $stats['sedang_dipinjam']; ?></div>
                                                    <small class="text-muted">Dipinjam</small>
                                                </div>
                                                <div class="col-4">
                                                    <div class="text-success fw-bold"><?php echo $stats['sudah_dikembalikan']; ?></div>
                                                    <small class="text-muted">Dikembalikan</small>
                                                </div>
                                                <div class="col-4">
                                                    <div class="text-danger fw-bold"><?php echo $stats['terlambat']; ?></div>
                                                    <small class="text-muted">Terlambat</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
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

        // Export loan report to Excel
        function exportLoanReport() {
            // Redirect to export endpoint
            window.location.href = 'export_excel.php?type=peminjaman&' + new URLSearchParams(window.location.search).toString();
        }

        // Export to PDF
        function exportToPDF() {
            Swal.fire({
                title: 'Export PDF',
                text: 'Sedang mempersiapkan laporan PDF...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            setTimeout(() => {
                Swal.close();
                
                // Create PDF content
                const table = document.getElementById('reportTable').innerHTML;
                const title = 'Laporan Peminjaman Buku';
                const period = '<?php echo formatDate($start_date) . " - " . formatDate($end_date); ?>';
                
                // In a real app, you would use a PDF library like jsPDF
                // For now, we'll show a success message
                Swal.fire({
                    title: 'PDF Siap!',
                    text: 'Laporan PDF berhasil dibuat',
                    icon: 'success',
                    showCancelButton: true,
                    confirmButtonText: 'Download',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Simulate download
                        window.open('generate_pdf.php?type=peminjaman&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>', '_blank');
                    }
                });
            }, 2000);
        }
        
        // Print table
        function printTable() {
            const printContent = document.getElementById('reportTable').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <html>
                    <head>
                        <title>Laporan Peminjaman</title>
                        <style>
                            body { font-family: Arial, sans-serif; }
                            table { width: 100%; border-collapse: collapse; }
                            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                            th { background-color: #f8f9fa; }
                            .print-header { text-align: center; margin-bottom: 20px; }
                            .print-date { text-align: right; margin-bottom: 20px; }
                        </style>
                    </head>
                    <body>
                        <div class="print-header">
                            <h2>Laporan Peminjaman Buku</h2>
                            <p>Digital Library System</p>
                        </div>
                        <div class="print-date">
                            <p>Periode: <?php echo formatDate($start_date) . " - " . formatDate($end_date); ?></p>
                            <p>Tanggal Cetak: <?php echo date('d/m/Y H:i'); ?></p>
                        </div>
                        ${printContent}
                    </body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }
        
        // Date range validation
        document.addEventListener('DOMContentLoaded', function() {
            const startDate = document.querySelector('input[name="start_date"]');
            const endDate = document.querySelector('input[name="end_date"]');
            
            if (startDate && endDate) {
                startDate.addEventListener('change', function() {
                    endDate.min = this.value;
                });
                
                endDate.addEventListener('change', function() {
                    if (this.value < startDate.value) {
                        Swal.fire('Error', 'Tanggal akhir tidak boleh sebelum tanggal mulai', 'error');
                        this.value = startDate.value;
                    }
                });
            }
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