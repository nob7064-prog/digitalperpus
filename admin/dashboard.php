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
$page_title = 'Dashboard Admin';
$page_icon = 'fas fa-tachometer-alt';
$current_page = 'dashboard.php';

// Get statistics
$conn = getConnection();

// Total counts
$stats = [
    'total_anggota' => $conn->query("SELECT COUNT(*) as total FROM anggota WHERE status='aktif'")->fetch_assoc()['total'],
    'total_petugas' => $conn->query("SELECT COUNT(*) as total FROM petugas WHERE status='aktif'")->fetch_assoc()['total'],
    'total_buku' => $conn->query("SELECT COUNT(*) as total FROM buku")->fetch_assoc()['total'],
    'total_kategori' => $conn->query("SELECT COUNT(*) as total FROM kategori")->fetch_assoc()['total'],
    'total_peminjaman' => $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE DATE(tanggal_pinjam) = CURDATE()")->fetch_assoc()['total'],
    'total_pengembalian' => $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE DATE(tanggal_kembali) = CURDATE()")->fetch_assoc()['total'],
    'total_denda' => $conn->query("SELECT COALESCE(SUM(jumlah_denda), 0) as total FROM denda WHERE status='belum_lunas'")->fetch_assoc()['total'],
    'total_rating' => $conn->query("SELECT COUNT(*) as total FROM rating WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['total']
];

// Get denda statistics (same as kelola_denda.php)
$start_date = date('Y-m-01');
$end_date = date('Y-m-d');
$statsSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'menunggu_approval' THEN 1 ELSE 0 END) as menunggu_approval,
                SUM(CASE WHEN status = 'lunas' THEN 1 ELSE 0 END) as lunas,
                SUM(CASE WHEN status = 'belum_lunas' THEN 1 ELSE 0 END) as belum_lunas,
                SUM(jumlah_denda) as total_denda
             FROM denda
             WHERE DATE(tanggal_bayar) BETWEEN '$start_date' AND '$end_date'";
$statsResult = $conn->query($statsSql);
$denda_stats = $statsResult->fetch_assoc();

// Initialize stats if empty
if (!$denda_stats) {
    $denda_stats = [
        'total' => 0,
        'menunggu_approval' => 0,
        'lunas' => 0,
        'belum_lunas' => 0,
        'total_denda' => 0
    ];
}

// Get pending loans count
$result = $conn->query("SHOW COLUMNS FROM peminjaman LIKE 'status_approval'");
$has_status_approval = ($result && $result->num_rows > 0);
$where_condition = $has_status_approval ? "status_approval = 'pending'" : "status = 'pending'";
$stats['total_pending'] = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE $where_condition")->fetch_assoc()['total'];

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Recent activities
$activities = $conn->query("
    SELECT a.*, 
           CASE a.user_type 
               WHEN 'anggota' THEN (SELECT username FROM anggota WHERE id_anggota = a.id_user)
               WHEN 'petugas' THEN (SELECT username FROM petugas WHERE id_petugas = a.id_user)
           END as username,
           CASE a.user_type 
               WHEN 'anggota' THEN (SELECT email FROM anggota WHERE id_anggota = a.id_user)
               WHEN 'petugas' THEN (SELECT email FROM petugas WHERE id_petugas = a.id_user)
           END as email
    FROM aktivitas a
    ORDER BY created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Monthly borrowing data for chart
$monthly_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $sql = "SELECT COUNT(*) as total FROM peminjaman WHERE DATE_FORMAT(tanggal_pinjam, '%Y-%m') = '$month'";
    $result = $conn->query($sql);
    $monthly_data[] = [
        'month' => date('M Y', strtotime($month . '-01')),
        'count' => $result->fetch_assoc()['total']
    ];
}

// Popular categories
$popular_categories = $conn->query("
    SELECT k.nama_kategori, COUNT(b.id_buku) as total_buku
    FROM kategori k
    LEFT JOIN buku b ON k.id_kategori = b.id_kategori
    GROUP BY k.id_kategori
    ORDER BY total_buku DESC
    LIMIT 5
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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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

        /* Dashboard Specific Styles */
        .welcome-banner {
            background: linear-gradient(135deg, #4361ee, #3a56d4);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }

        .welcome-banner h1 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .welcome-banner p {
            opacity: 0.9;
            font-size: 0.85rem;
            margin-bottom: 0;
        }

        .date-display {
            background: rgba(255, 255, 255, 0.1);
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            font-size: 0.8rem;
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
        
        /* Denda Stats Cards */
        .denda-stats-row .col-md-3 {
            padding: 0 5px;
        }
        
        .denda-stats-row {
            margin: 0 -5px;
        }
        
        .denda-total-card {
            background: linear-gradient(135deg, #6c757d, #495057);
            padding: 15px;
            border-radius: 10px;
            color: white;
            margin-bottom: 15px;
        }
        
        /* Categories list */
        .categories-list {
            max-height: 250px;
            overflow-y: auto;
        }
        
        .category-item {
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-item:hover {
            background-color: var(--gray-100);
            padding-left: 10px;
            padding-right: 10px;
            margin: 0 -10px;
            border-radius: 8px;
        }
        
        .category-name {
            font-weight: 500;
            color: var(--gray-800);
            font-size: 0.9rem;
        }
        
        .category-count {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .category-percentage {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.85rem;
        }
        
        /* Activities Timeline */
        .activities-timeline {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item:hover {
            background-color: var(--gray-100);
            padding-left: 10px;
            padding-right: 10px;
            margin: 0 -10px;
            border-radius: 8px;
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }
        
        .activity-login .activity-icon { background-color: #2ecc71; }
        .activity-register .activity-icon { background-color: #3498db; }
        .activity-borrow .activity-icon { background-color: #f39c12; }
        .activity-return .activity-icon { background-color: #9b59b6; }
        .activity-fine .activity-icon { background-color: #e74c3c; }
        .activity-default .activity-icon { background-color: #95a5a6; }
        
        .activity-content {
            flex-grow: 1;
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3px;
        }
        
        .activity-user {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 0.9rem;
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--gray-600);
        }
        
        .activity-text {
            font-size: 0.85rem;
            color: var(--gray-700);
            margin-bottom: 3px;
        }
        
        .activity-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--gray-600);
        }
        
        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .quick-action {
            display: block;
            text-align: center;
            padding: 1rem 0.75rem;
            border-radius: 8px;
            background: white;
            border: 1px solid var(--gray-200);
            transition: var(--transition);
            text-decoration: none;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            border-color: var(--primary-color);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .quick-action-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            color: white;
            font-size: 1.1rem;
        }
        
        .bg-purple { background-color: #6f42c1; }
        .bg-info { background-color: #17a2b8; }
        
        .quick-action-text {
            font-weight: 500;
            color: var(--gray-800);
            font-size: 0.85rem;
            margin: 0;
        }

        /* Progress bar */
        .progress {
            border-radius: 8px;
            background-color: var(--gray-200);
            height: 6px;
        }

        .progress-bar {
            border-radius: 8px;
        }

        /* Currency Format */
        .currency {
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            :root {
                --sidebar-width: 200px;
            }
            
            .stat-card h2 {
                font-size: 1.3rem;
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
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .main-content {
                padding: 10px;
            }
            
            .card-header {
                padding: 0.75rem;
            }
            
            .welcome-banner {
                padding: 1rem;
            }
            
            .welcome-banner h1 {
                font-size: 1.2rem;
            }
            
            .date-display {
                padding: 0.5rem;
                font-size: 0.75rem;
            }
            
            .denda-total-card h1 {
                font-size: 1.5rem;
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
            
            .denda-stats-row .col-md-3 {
                margin-bottom: 10px;
            }
            
            .denda-total-card {
                padding: 12px;
            }
            
            .denda-total-card h1 {
                font-size: 1.2rem;
            }
        }
        
        /* Container adjustments */
        .container-fluid {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        /* Ensure content fits */
        .col-md-3, .col-md-4, .col-md-6, .col-md-8, .col-md-12 {
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
    </style>
</head>
<body>
    <div class="main-wrapper">
        <!-- Sidebar (same as kelola_denda.php) -->
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
            <!-- Topbar (same as kelola_denda.php) -->
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
                    <!-- Welcome Banner -->
                    <div class="welcome-banner mb-3">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h1 class="mb-2">Selamat Datang, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                                <p class="mb-0">Dashboard Admin - Digital Library Management System</p>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="date-display">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    <span><?php echo date('l, d F Y'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Anggota Aktif</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['total_anggota']); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-users opacity-75"></i>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex align-items-center">
                                    <small class="opacity-75">
                                        <i class="fas fa-exchange-alt me-1"></i>
                                        <?php echo $stats['total_peminjaman']; ?> peminjaman hari ini
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Buku</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['total_buku']); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-book opacity-75"></i>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex align-items-center">
                                    <small class="opacity-75">
                                        <i class="fas fa-tags me-1"></i>
                                        <?php echo $stats['total_kategori']; ?> kategori
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-warning">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Peminjaman Hari Ini</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['total_peminjaman']); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exchange-alt opacity-75"></i>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex align-items-center">
                                    <small class="opacity-75">
                                        <i class="fas fa-undo me-1"></i>
                                        <?php echo $stats['total_pengembalian']; ?> pengembalian hari ini
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-danger">
                                <div class="d-flex align-items-center">
                                    <div class="col-md-9">
                                        <h5 class="mb-2">Total Nilai Denda</h5>
                                        <h2 class="mb-0 currency">Rp <?php echo number_format($denda_stats['total_denda'] ?? 0, 0, ',', '.'); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-clock opacity-75"></i>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex align-items-center">
                                    <small class="opacity-75">
                                        <i class="fas fa-exclamation-circle me-1"></i>
                                        Perlu persetujuan
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <!-- Chart Section -->
                        <div class="col-lg-8 mb-3">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-chart-line me-2"></i>Statistik Peminjaman 6 Bulan Terakhir
                                    </h5>

                                </div>
                                <div class="card-body">
                                    <canvas id="borrowingChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Popular Categories -->
                        <div class="col-lg-4 mb-3">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-tags me-2"></i>Kategori Populer
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="categories-list">
                                        <?php foreach ($popular_categories as $category): 
                                            $percentage = $stats['total_buku'] > 0 ? ($category['total_buku'] / $stats['total_buku']) * 100 : 0;
                                        ?>
                                        <div class="category-item">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <div>
                                                    <div class="category-name"><?php echo htmlspecialchars($category['nama_kategori']); ?></div>
                                                    <div class="category-count">
                                                        <?php echo $category['total_buku']; ?> buku
                                                    </div>
                                                </div>
                                                <div class="category-percentage">
                                                    <?php echo round($percentage, 1); ?>%
                                                </div>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar bg-primary" role="progressbar" 
                                                     style="width: <?php echo min($percentage, 100); ?>%">
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activities & Quick Actions -->
                    <div class="row">
                        <!-- Recent Activities -->
                        <div class="col-lg-8 mb-3">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-history me-2"></i>Aktivitas Terbaru
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="activities-timeline">
                                        <?php foreach ($activities as $activity): 
                                            $activity_class = '';
                                            $activity_icon = '';
                                            
                                            if (strpos($activity['aktivitas'], 'Login') !== false) {
                                                $activity_class = 'activity-login';
                                                $activity_icon = 'fas fa-sign-in-alt';
                                            } elseif (strpos($activity['aktivitas'], 'Pendaftaran') !== false) {
                                                $activity_class = 'activity-register';
                                                $activity_icon = 'fas fa-user-plus';
                                            } elseif (strpos($activity['aktivitas'], 'meminjam') !== false) {
                                                $activity_class = 'activity-borrow';
                                                $activity_icon = 'fas fa-book-reader';
                                            } elseif (strpos($activity['aktivitas'], 'mengembalikan') !== false) {
                                                $activity_class = 'activity-return';
                                                $activity_icon = 'fas fa-undo';
                                            } elseif (strpos($activity['aktivitas'], 'denda') !== false) {
                                                $activity_class = 'activity-fine';
                                                $activity_icon = 'fas fa-money-bill-wave';
                                            } else {
                                                $activity_class = 'activity-default';
                                                $activity_icon = 'fas fa-info-circle';
                                            }
                                        ?>
                                        <div class="activity-item <?php echo $activity_class; ?>">
                                            <div class="activity-icon">
                                                <i class="<?php echo $activity_icon; ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-header">
                                                    <span class="activity-user"><?php echo htmlspecialchars($activity['username']); ?></span>
                                                    <span class="activity-time"><?php echo formatDate($activity['created_at'], 'H:i'); ?></span>
                                                </div>
                                                <div class="activity-text"><?php echo htmlspecialchars($activity['aktivitas']); ?></div>
                                                <div class="activity-meta">
                                                    <span class="activity-type"><?php echo ucfirst($activity['user_type']); ?></span>
                                                    <span class="activity-date"><?php echo formatDate($activity['created_at'], 'd/m/Y'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="col-lg-4 mb-3">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-bolt me-2"></i>Aksi Cepat
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="quick-actions-grid">
                                        <a href="kelola_buku.php" class="quick-action">
                                            <div class="quick-action-icon bg-primary">
                                                <i class="fas fa-plus"></i>
                                            </div>
                                            <div class="quick-action-text">Tambah Buku</div>
                                        </a>
                                        <a href="kelola_user.php" class="quick-action">
                                            <div class="quick-action-icon bg-success">
                                                <i class="fas fa-user-plus"></i>
                                            </div>
                                            <div class="quick-action-text">Tambah User</div>
                                        </a>
                                        <a href="laporan_buku.php" class="quick-action">
                                            <div class="quick-action-icon bg-info">
                                                <i class="fas fa-file-pdf"></i>
                                            </div>
                                            <div class="quick-action-text">Buat Laporan</div>
                                        </a>
                                        <a href="kelola_kategori.php" class="quick-action">
                                            <div class="quick-action-icon bg-warning">
                                                <i class="fas fa-tags"></i>
                                            </div>
                                            <div class="quick-action-text">Kelola Kategori</div>
                                        </a>
                                        <a href="laporan_peminjaman.php" class="quick-action">
                                            <div class="quick-action-icon bg-danger">
                                                <i class="fas fa-exchange-alt"></i>
                                            </div>
                                            <div class="quick-action-text">Lihat Peminjaman</div>
                                        </a>
                                        <a href="kelola_denda.php" class="quick-action">
                                            <div class="quick-action-icon bg-purple">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </div>
                                            <div class="quick-action-text">Kelola Denda</div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Logout Confirmation Modal (same as kelola_denda.php) -->
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

        // Logout confirmation function
        function confirmLogout() {
            const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
            modal.show();
        }

        // Borrowing Chart
        const ctx = document.getElementById('borrowingChart').getContext('2d');
        const borrowingChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($monthly_data, 'month')); ?>,
                datasets: [{
                    label: 'Jumlah Peminjaman',
                    data: <?php echo json_encode(array_column($monthly_data, 'count')); ?>,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4361ee',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [5, 5]
                        },
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

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