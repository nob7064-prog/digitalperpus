<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$page_title = 'Laporan Pengguna';
$page_icon = 'fas fa-users';

// Get filter parameters
$user_type = $_GET['user_type'] ?? 'anggota';
$status = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$conn = getConnection();

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Build query based on user type
if ($user_type === 'anggota') {
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($status) {
        $whereClause .= " AND a.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($start_date) {
        $whereClause .= " AND DATE(a.created_at) >= ?";
        $params[] = $start_date;
        $types .= "s";
    }
    
    if ($end_date) {
        $whereClause .= " AND DATE(a.created_at) <= ?";
        $params[] = $end_date;
        $types .= "s";
    }
    
    $sql = "SELECT a.*, 
                   (SELECT COUNT(*) FROM peminjaman WHERE id_anggota = a.id_anggota) as total_pinjam,
                   (SELECT COUNT(*) FROM peminjaman WHERE id_anggota = a.id_anggota AND status = 'dipinjam') as sedang_dipinjam,
                   (SELECT COUNT(*) FROM peminjaman WHERE id_anggota = a.id_anggota AND status = 'terlambat') as terlambat,
                   (SELECT SUM(denda) FROM pengembalian WHERE id_anggota = a.id_anggota) as total_denda
            FROM anggota a
            $whereClause
            ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get statistics
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif,
            SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) as nonaktif,
            AVG((SELECT COUNT(*) FROM peminjaman WHERE id_anggota = anggota.id_anggota)) as avg_pinjam,
            SUM((SELECT SUM(denda) FROM pengembalian WHERE id_anggota = anggota.id_anggota)) as total_denda
        FROM anggota
    ")->fetch_assoc();
} else {
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($status) {
        $whereClause .= " AND p.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($start_date) {
        $whereClause .= " AND DATE(p.created_at) >= ?";
        $params[] = $start_date;
        $types .= "s";
    }
    
    if ($end_date) {
        $whereClause .= " AND DATE(p.created_at) <= ?";
        $params[] = $end_date;
        $types .= "s";
    }
    
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM aktivitas WHERE id_user = p.id_petugas AND user_type = 'petugas') as total_aktivitas
            FROM petugas p
            $whereClause
            ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get statistics for petugas
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif,
            SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) as nonaktif,
            SUM(CASE WHEN level = 'admin' THEN 1 ELSE 0 END) as admin,
            SUM(CASE WHEN level = 'petugas' THEN 1 ELSE 0 END) as petugas
        FROM petugas
    ")->fetch_assoc();
}

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
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #e5e7eb;
        }

        .user-avatar img {
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
                                <a class="dropdown-item" href="laporan_peminjaman.php">
                                    <i class="fas fa-file-alt me-2"></i>Pinjam & Kembali
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item active" href="laporan_pengguna.php">
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
                            <button type="button" class="btn btn-success" onclick="exportUserReport()">
                                <i class="fas fa-file-excel me-2"></i>Export Excel
                            </button>
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
                                    <label class="form-label">Tipe Pengguna</label>
                                    <select class="form-select" name="user_type" id="userTypeSelect">
                                        <option value="anggota" <?php echo $user_type == 'anggota' ? 'selected' : ''; ?>>Anggota</option>
                                        <option value="petugas" <?php echo $user_type == 'petugas' ? 'selected' : ''; ?>>Petugas</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">Semua Status</option>
                                        <option value="aktif" <?php echo $status == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                        <option value="nonaktif" <?php echo $status == 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                                    </select>
                                </div>
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
                                <div class="col-12">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-2"></i>Terapkan Filter
                                        </button>
                                        <a href="laporan_pengguna.php" class="btn btn-outline-secondary">
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
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                                    <div class="stat-label">Total <?php echo $user_type === 'anggota' ? 'Anggota' : 'Petugas'; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-success">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['aktif']); ?></div>
                                    <div class="stat-label">Aktif</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-warning">
                                        <i class="fas fa-times-circle"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['nonaktif']); ?></div>
                                    <div class="stat-label">Nonaktif</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-info">
                                        <i class="fas <?php echo $user_type === 'anggota' ? 'fa-book-reader' : 'fa-user-tie'; ?>"></i>
                                    </div>
                                    <div class="stat-number">
                                        <?php if ($user_type === 'anggota'): ?>
                                        <?php echo number_format($stats['avg_pinjam'], 1); ?>
                                        <?php else: ?>
                                        <?php echo $stats['admin']; ?> / <?php echo $stats['petugas']; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-label">
                                        <?php if ($user_type === 'anggota'): ?>
                                        Rata-rata Pinjam
                                        <?php else: ?>
                                        Admin / Petugas
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                Data <?php echo $user_type === 'anggota' ? 'Anggota' : 'Petugas'; ?>
                                <span class="badge bg-primary ms-2"><?php echo count($users); ?></span>
                            </h5>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="printUserTable()">
                                    <i class="fas fa-print me-2"></i>Print
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive" id="userTable">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Pengguna</th>
                                            <th>Email</th>
                                            <?php if ($user_type === 'petugas'): ?>
                                            <th>Level</th>
                                            <?php endif; ?>
                                            <th>Status</th>
                                            <th>Tanggal Daftar</th>
                                            <?php if ($user_type === 'anggota'): ?>
                                            <th>Statistik</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <i class="fas fa-users fa-2x text-muted mb-3"></i>
                                                <p class="text-muted mb-0">Tidak ada data <?php echo $user_type === 'anggota' ? 'anggota' : 'petugas'; ?></p>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($users as $index => $user): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-3">
                                                            <img src="<?php echo SITE_URL; ?>uploads/profiles/<?php echo htmlspecialchars($user['foto_profil'] ?? 'default.png'); ?>" 
                                                                 alt="<?php echo htmlspecialchars($user['username']); ?>">
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($user['username']); ?></div>
                                                            <small class="text-muted">
                                                                <?php echo $user_type === 'anggota' ? 'ID: ' . $user['id_anggota'] : 'ID: ' . $user['id_petugas']; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>

                                                <?php if ($user_type === 'petugas'): ?>
                                                <td>
                                                    <span class="badge <?php echo $user['level'] === 'admin' ? 'bg-danger' : 'bg-info'; ?>">
                                                        <?php echo ucfirst($user['level']); ?>
                                                    </span>
                                                </td>
                                                <?php endif; ?>
                                                
                                                <td>
                                                    <span class="badge <?php echo $user['status'] === 'aktif' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo ucfirst($user['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatDate($user['created_at']); ?></td>
                                                
                                                <?php if ($user_type === 'anggota'): ?>
                                                <td>
                                                    <div class="small">
                                                        <div class="d-flex justify-content-between">
                                                            <span>Pinjam:</span>
                                                            <span class="fw-bold"><?php echo $user['total_pinjam']; ?></span>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <span class="text-warning">Dipinjam:</span>
                                                            <span><?php echo $user['sedang_dipinjam']; ?></span>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <span class="text-danger">Terlambat:</span>
                                                            <span><?php echo $user['terlambat']; ?></span>
                                                        </div>
                                                        <?php if ($user['total_denda']): ?>
                                                        <div class="d-flex justify-content-between">
                                                            <span class="text-danger">Denda:</span>
                                                            <span><?php echo formatCurrency($user['total_denda']); ?></span>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Report -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-bar me-2"></i>Statistik Detail
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Distribusi Status</h6>
                                    <div class="progress-stacked mb-3">
                                        <div class="progress" style="width: <?php echo ($stats['aktif'] / max($stats['total'], 1)) * 100; ?>%">
                                            <div class="progress-bar bg-success" role="progressbar">
                                                Aktif (<?php echo $stats['aktif']; ?>)
                                            </div>
                                        </div>
                                        <div class="progress" style="width: <?php echo ($stats['nonaktif'] / max($stats['total'], 1)) * 100; ?>%">
                                            <div class="progress-bar bg-warning" role="progressbar">
                                                Nonaktif (<?php echo $stats['nonaktif']; ?>)
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($user_type === 'petugas'): ?>
                                    <h6 class="mt-4">Distribusi Level</h6>
                                    <div class="progress-stacked mb-3">
                                        <div class="progress" style="width: <?php echo ($stats['admin'] / max($stats['total'], 1)) * 100; ?>%">
                                            <div class="progress-bar bg-danger" role="progressbar">
                                                Admin (<?php echo $stats['admin']; ?>)
                                            </div>
                                        </div>
                                        <div class="progress" style="width: <?php echo ($stats['petugas'] / max($stats['total'], 1)) * 100; ?>%">
                                            <div class="progress-bar bg-info" role="progressbar">
                                                Petugas (<?php echo $stats['petugas']; ?>)
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h6>Ringkasan Laporan</h6>
                                    <div class="summary-list">
                                        <div class="summary-item">
                                            <span class="summary-label">Tipe Pengguna:</span>
                                            <span class="summary-value"><?php echo $user_type === 'anggota' ? 'Anggota' : 'Petugas'; ?></span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Periode:</span>
                                            <span class="summary-value">
                                                <?php echo $start_date ? formatDate($start_date) : 'Semua' ?> 
                                                - <?php echo $end_date ? formatDate($end_date) : 'Semua' ?>
                                            </span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Status Filter:</span>
                                            <span class="summary-value"><?php echo $status ? ucfirst($status) : 'Semua'; ?></span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Tanggal Laporan:</span>
                                            <span class="summary-value"><?php echo date('d/m/Y H:i'); ?></span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Jumlah Data:</span>
                                            <span class="summary-value"><?php echo count($users); ?> dari <?php echo $stats['total']; ?> <?php echo $user_type === 'anggota' ? 'anggota' : 'petugas'; ?></span>
                                        </div>
                                    </div>
                                </div>
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

        // Auto update user type in form
        document.getElementById('userTypeSelect').addEventListener('change', function() {
            this.closest('form').submit();
        });
        
        // Export user report to Excel (server-side)
        function exportUserReport() {
            // Redirect to server export endpoint with current filters
            window.location.href = 'export_excel.php?type=pengguna&' + new URLSearchParams(window.location.search).toString();
        }
        
        // Print user table
        function printUserTable() {
            const printContent = document.getElementById('userTable').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <html>
                    <head>
                        <title>Laporan <?php echo $user_type === 'anggota' ? 'Anggota' : 'Petugas'; ?></title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                            th { background-color: #f8f9fa; font-weight: bold; }
                            .print-header { text-align: center; margin-bottom: 30px; }
                            .print-header h2 { margin: 0; color: #333; }
                            .print-header p { margin: 5px 0; color: #666; }
                            .print-date { text-align: right; margin-bottom: 20px; font-size: 14px; }
                            .summary { margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 5px; }
                            .summary h4 { margin-top: 0; }
                            .badge { padding: 3px 8px; border-radius: 4px; font-size: 12px; }
                            .bg-success { background: #d1fae5; color: #065f46; }
                            .bg-secondary { background: #e5e7eb; color: #374151; }
                            .bg-danger { background: #fee2e2; color: #991b1b; }
                            .bg-info { background: #dbeafe; color: #1e40af; }
                        </style>
                    </head>
                    <body>
                        <div class="print-header">
                            <h2>Laporan <?php echo $user_type === 'anggota' ? 'Anggota' : 'Petugas'; ?> - Digital Library</h2>
                            <p>Sistem Manajemen Perpustakaan Digital</p>
                        </div>
                        
                        <div class="print-date">
                            <p>Tanggal Cetak: <?php echo date('d/m/Y H:i'); ?></p>
                            <p>Total Data: <?php echo count($users); ?> <?php echo $user_type === 'anggota' ? 'anggota' : 'petugas'; ?></p>
                        </div>
                        
                        ${printContent}
                        
                        <div class="summary">
                            <h4>Ringkasan:</h4>
                            <p>Total <?php echo $user_type === 'anggota' ? 'Anggota' : 'Petugas'; ?>: <?php echo number_format($stats['total']); ?></p>
                            <p>Aktif: <?php echo number_format($stats['aktif']); ?> (<?php echo round(($stats['aktif'] / max($stats['total'], 1)) * 100, 1); ?>%)</p>
                            <p>Nonaktif: <?php echo number_format($stats['nonaktif']); ?> (<?php echo round(($stats['nonaktif'] / max($stats['total'], 1)) * 100, 1); ?>%)</p>
                            <?php if ($user_type === 'anggota'): ?>
                            <p>Rata-rata Pinjam per Anggota: <?php echo number_format($stats['avg_pinjam'], 1); ?></p>
                            <?php if ($stats['total_denda']): ?>
                            <p>Total Denda: <?php echo formatCurrency($stats['total_denda']); ?></p>
                            <?php endif; ?>
                            <?php else: ?>
                            <p>Admin: <?php echo $stats['admin']; ?> | Petugas: <?php echo $stats['petugas']; ?></p>
                            <?php endif; ?>
                        </div>
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