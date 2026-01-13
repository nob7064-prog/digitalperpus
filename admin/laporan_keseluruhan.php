<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$page_title = 'Laporan Keseluruhan';
$page_icon = 'fas fa-chart-pie';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$conn = getConnection();

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Get overall statistics
$overallStats = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM buku) as total_buku,
        (SELECT SUM(stok) FROM buku) as total_stok,
        (SELECT COUNT(*) FROM anggota) as total_anggota,
        (SELECT COUNT(*) FROM petugas) as total_petugas,
        (SELECT COUNT(*) FROM peminjaman WHERE DATE(tanggal_pinjam) BETWEEN '$start_date' AND '$end_date') as total_peminjaman,
        (SELECT COUNT(*) FROM pengembalian WHERE DATE(tanggal_kembali) BETWEEN '$start_date' AND '$end_date') as total_pengembalian,
        (SELECT SUM(denda) FROM pengembalian WHERE DATE(tanggal_kembali) BETWEEN '$start_date' AND '$end_date') as total_denda,
        (SELECT COUNT(*) FROM peminjaman WHERE status = 'dipinjam') as sedang_dipinjam,
        (SELECT COUNT(*) FROM peminjaman WHERE status = 'terlambat') as terlambat
")->fetch_assoc();

// Get monthly data for charts
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthSql = "SELECT
                    COUNT(DISTINCT p.id_peminjaman) as peminjaman,
                    COUNT(DISTINCT pg.id_pengembalian) as pengembalian,
                    COALESCE(SUM(pg.denda), 0) as denda
                 FROM peminjaman p
                 LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                 WHERE DATE_FORMAT(p.tanggal_pinjam, '%Y-%m') = ?";
    $monthStmt = $conn->prepare($monthSql);
    $monthStmt->bind_param("s", $month);
    $monthStmt->execute();
    $monthResult = $monthStmt->get_result()->fetch_assoc();
    $monthStmt->close();

    $monthlyData[] = [
        'month' => date('M Y', strtotime($month . '-01')),
        'peminjaman' => $monthResult['peminjaman'] ?? 0,
        'pengembalian' => $monthResult['pengembalian'] ?? 0,
        'denda' => $monthResult['denda'] ?? 0
    ];
}

// Get top categories
$topCategories = $conn->query("
    SELECT k.nama_kategori, COUNT(p.id_peminjaman) as total_pinjam
    FROM kategori k
    JOIN buku b ON k.id_kategori = b.id_kategori
    JOIN peminjaman p ON b.id_buku = p.id_buku
    WHERE DATE(p.tanggal_pinjam) BETWEEN '$start_date' AND '$end_date'
    GROUP BY k.id_kategori
    ORDER BY total_pinjam DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get top books
$topBooks = $conn->query("
    SELECT b.judul_buku, k.nama_kategori, COUNT(p.id_peminjaman) as total_pinjam
    FROM buku b
    JOIN kategori k ON b.id_kategori = k.id_kategori
    JOIN peminjaman p ON b.id_buku = p.id_buku
    WHERE DATE(p.tanggal_pinjam) BETWEEN '$start_date' AND '$end_date'
    GROUP BY b.id_buku
    ORDER BY total_pinjam DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get top members
$topMembers = $conn->query("
    SELECT a.username, a.email, COUNT(p.id_peminjaman) as total_pinjam
    FROM anggota a
    JOIN peminjaman p ON a.id_anggota = p.id_anggota
    WHERE DATE(p.tanggal_pinjam) BETWEEN '$start_date' AND '$end_date'
    GROUP BY a.id_anggota
    ORDER BY total_pinjam DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get status distribution
$statusStats = $conn->query("
    SELECT type,
        SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif,
        SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) as nonaktif
    FROM (
        SELECT status, 'anggota' as type FROM anggota
        UNION ALL
        SELECT status, 'petugas' as type FROM petugas
    ) as combined
    GROUP BY type
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

        /* Custom CSS for statistics cards */
        .stat-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 10px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 1.5rem;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .stat-trend {
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 500;
            color: #495057;
        }

        .summary-value {
            font-weight: 600;
            color: #212529;
        }

        .top-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .top-item {
            padding: 10px;
            border-radius: 8px;
            background: #f8f9fa;
            transition: background-color 0.3s ease;
        }

        .top-item:hover {
            background: #e9ecef;
        }

        .rank {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
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
                                <a class="dropdown-item" href="laporan_pengguna.php">
                                    <i class="fas fa-users-cog me-2"></i>Pengguna
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item active" href="laporan_keseluruhan.php">
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
                            <button type="button" class="btn btn-success" onclick="exportOverallReport()">
                                <i class="fas fa-file-excel me-2"></i>Export Excel
                            </button>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Filter Periode Laporan
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Tanggal Mulai</label>
                                    <input type="date" class="form-control" name="start_date"
                                           value="<?php echo $start_date; ?>" id="startDate">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Tanggal Akhir</label>
                                    <input type="date" class="form-control" name="end_date"
                                           value="<?php echo $end_date; ?>" id="endDate">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-2"></i>Terapkan Filter
                                        </button>
                                        <a href="laporan_keseluruhan.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-redo me-2"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Main Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-primary">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($overallStats['total_buku']); ?></div>
                                    <div class="stat-label">Total Buku</div>
                                    <div class="stat-trend text-success">
                                        <i class="fas fa-warehouse"></i> <?php echo number_format($overallStats['total_stok']); ?> stok
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-success">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($overallStats['total_anggota'] + $overallStats['total_petugas']); ?></div>
                                    <div class="stat-label">Total Pengguna</div>
                                    <div class="stat-trend text-info">
                                        <i class="fas fa-user-friends"></i> <?php echo $overallStats['total_anggota']; ?> anggota
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-warning">
                                        <i class="fas fa-exchange-alt"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($overallStats['total_peminjaman']); ?></div>
                                    <div class="stat-label">Total Peminjaman</div>
                                    <div class="stat-trend text-primary">
                                        <i class="fas fa-clock"></i> <?php echo $overallStats['sedang_dipinjam']; ?> aktif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-danger">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <div class="stat-number"><?php echo formatCurrency($overallStats['total_denda']); ?></div>
                                    <div class="stat-label">Total Denda</div>
                                    <div class="stat-trend text-warning">
                                        <i class="fas fa-exclamation-triangle"></i> <?php echo $overallStats['terlambat']; ?> terlambat
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row mb-4">
                        <!-- Chart Section -->
                        <div class="col-lg-8">
                            <!-- 1. Trend Pengembalian & Denda 6 Bulan Terakhir -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-chart-line me-2"></i>Trend Pengembalian & Denda 6 Bulan Terakhir
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="overallTrendChart" height="250"></canvas>
                                </div>
                            </div>

                            <!-- 2. Distribusi Status Anggota -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-chart-pie me-2"></i>Distribusi Status Anggota
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="statusChart" height="200"></canvas>
                                </div>
                            </div>

                            <!-- 3. Anggota Teraktif -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-user-friends me-2"></i>Anggota Teraktif
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="top-list">
                                        <?php foreach ($topMembers as $index => $member): ?>
                                        <div class="top-item mb-3">
                                            <div class="d-flex align-items-center">
                                                <div class="rank me-3">#<?php echo $index + 1; ?></div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($member['username']); ?></div>
                                                    <div class="text-muted" style="font-size: 0.8rem;"><?php echo htmlspecialchars($member['email']); ?></div>
                                                </div>
                                                <div class="text-primary fw-bold"><?php echo number_format($member['total_pinjam']); ?></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top Statistics Sidebar -->
                        <div class="col-lg-4">
                            <!-- Top Categories -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-tags me-2"></i>Kategori Terpopuler
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="top-list">
                                        <?php foreach ($topCategories as $index => $category): ?>
                                        <div class="top-item mb-3">
                                            <div class="d-flex align-items-center">
                                                <div class="rank me-3">#<?php echo $index + 1; ?></div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($category['nama_kategori']); ?></div>
                                                </div>
                                                <div class="text-primary fw-bold"><?php echo number_format($category['total_pinjam']); ?></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Top Books -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-book me-2"></i>Buku Terpopuler
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="top-list">
                                        <?php foreach ($topBooks as $index => $book): ?>
                                        <div class="top-item mb-3">
                                            <div class="d-flex align-items-center">
                                                <div class="rank me-3">#<?php echo $index + 1; ?></div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($book['judul_buku']); ?></div>
                                                    <div class="text-muted" style="font-size: 0.8rem;"><?php echo htmlspecialchars($book['nama_kategori']); ?></div>
                                                </div>
                                                <div class="text-primary fw-bold"><?php echo number_format($book['total_pinjam']); ?></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Report -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-file-alt me-2"></i>Ringkasan Laporan
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="summary-item">
                                        <span class="summary-label">Periode Laporan</span>
                                        <span class="summary-value"><?php echo formatDate($start_date); ?> - <?php echo formatDate($end_date); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Total Buku</span>
                                        <span class="summary-value"><?php echo number_format($overallStats['total_buku']); ?> (<?php echo number_format($overallStats['total_stok']); ?> stok)</span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Total Pengguna</span>
                                        <span class="summary-value"><?php echo number_format($overallStats['total_anggota'] + $overallStats['total_petugas']); ?> (<?php echo $overallStats['total_anggota']; ?> anggota, <?php echo $overallStats['total_petugas']; ?> petugas)</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-item">
                                        <span class="summary-label">Total Peminjaman</span>
                                        <span class="summary-value"><?php echo number_format($overallStats['total_peminjaman']); ?> (<?php echo $overallStats['sedang_dipinjam']; ?> aktif)</span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Total Pengembalian</span>
                                        <span class="summary-value"><?php echo number_format($overallStats['total_pengembalian']); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Total Denda</span>
                                        <span class="summary-value"><?php echo formatCurrency($overallStats['total_denda']); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-item">
                                        <span class="summary-label">Tanggal Laporan</span>
                                        <span class="summary-value"><?php echo date('d/m/Y H:i'); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Peminjaman Terlambat</span>
                                        <span class="summary-value"><?php echo number_format($overallStats['terlambat']); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Status</span>
                                        <span class="summary-value">Laporan berhasil dibuat</span>
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
    
    <script>
        // Sidebar toggle for mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
            document.getElementById('contentWrapper').classList.toggle('mobile-open');
        });

        // Overall Trend Chart
        const overallCtx = document.getElementById('overallTrendChart').getContext('2d');
        const overallTrendChart = new Chart(overallCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($monthlyData, 'month')); ?>,
                datasets: [
                    {
                        label: 'Pengembalian',
                        data: <?php echo json_encode(array_column($monthlyData, 'pengembalian')); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Total Denda (Rp)',
                        data: <?php echo json_encode(array_column($monthlyData, 'denda')); ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Jumlah Pengembalian'
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Total Denda (Rp)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return `Pengembalian: ${context.raw}`;
                                } else {
                                    return `Denda: Rp${context.raw.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Anggota Aktif', 'Anggota Nonaktif', 'Petugas Aktif', 'Petugas Nonaktif'],
                datasets: [{
                    data: [
                        <?php
                        $anggotaAktif = 0;
                        $anggotaNonaktif = 0;
                        $petugasAktif = 0;
                        $petugasNonaktif = 0;
                        foreach ($statusStats as $stat) {
                            if ($stat['type'] == 'anggota') {
                                $anggotaAktif = $stat['aktif'];
                                $anggotaNonaktif = $stat['nonaktif'];
                            } elseif ($stat['type'] == 'petugas') {
                                $petugasAktif = $stat['aktif'];
                                $petugasNonaktif = $stat['nonaktif'];
                            }
                        }
                        echo $anggotaAktif . ',' . $anggotaNonaktif . ',' . $petugasAktif . ',' . $petugasNonaktif;
                        ?>
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#ef4444',
                        '#3b82f6',
                        '#f59e0b'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
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
        });

        // Export overall report to Excel
        function exportOverallReport() {
            // Redirect to export endpoint
            window.location.href = 'export_excel.php?type=keseluruhan&' + new URLSearchParams(window.location.search).toString();
        }
        
        // Confirm logout
        function confirmLogout() {
            const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
            modal.show();
        }
    </script>
</body>
</html>