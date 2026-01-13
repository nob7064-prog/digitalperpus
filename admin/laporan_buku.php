<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$page_title = 'Laporan Buku';
$page_icon = 'fas fa-book';

// Get filter parameters dengan validasi
$kategori = isset($_GET['kategori']) ? trim($_GET['kategori']) : '';
$status_stok = isset($_GET['status_stok']) ? trim($_GET['status_stok']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'terbaru';
$tahun = isset($_GET['tahun']) ? trim($_GET['tahun']) : '';
$rating_min = isset($_GET['rating_min']) ? trim($_GET['rating_min']) : '';

// Validasi nilai sort
$allowed_sorts = ['terbaru', 'rating', 'populer', 'abjad'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'terbaru';
}

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

$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Build query
$whereClause = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($kategori)) {
    $whereClause .= " AND k.id_kategori = ?";
    $params[] = $kategori;
    $types .= "i";
}

if ($status_stok === 'tersedia') {
    $whereClause .= " AND b.stok > 0";
} elseif ($status_stok === 'habis') {
    $whereClause .= " AND b.stok = 0";
}

if (!empty($tahun)) {
    $whereClause .= " AND b.tahun_terbit = ?";
    $params[] = $tahun;
    $types .= "s";
}

if (!empty($rating_min) && is_numeric($rating_min)) {
    $whereClause .= " AND b.rata_rating >= ?";
    $params[] = floatval($rating_min);
    $types .= "d";
}

// Sort order
$orderBy = "ORDER BY ";
switch ($sort) {
    case 'rating':
        $orderBy .= "b.rata_rating DESC";
        break;
    case 'populer':
        $orderBy .= "approved_pinjam_count DESC";
        break;
    case 'abjad':
        $orderBy .= "b.judul_buku ASC";
        break;
    case 'terbaru':
    default:
        $orderBy .= "b.created_at DESC";
        break;
}

// Get books for report with approved loan count
$sql = "SELECT b.*, k.nama_kategori, p.nama_penulis, pb.nama_penerbit,
        COALESCE(AVG(r.rating), 0) as rating_avg,
        COUNT(r.id_rating) as rating_count,
        COUNT(DISTINCT CASE WHEN pm.status IN ('dipinjam', 'dikembalikan') THEN pm.id_peminjaman END) as approved_pinjam_count
        FROM buku b
        LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
        LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
        LEFT JOIN penerbit pb ON b.id_penerbit = pb.id_penerbit
        LEFT JOIN rating r ON b.id_buku = r.id_buku
        LEFT JOIN peminjaman pm ON b.id_buku = pm.id_buku 
            AND pm.status IN ('dipinjam', 'dikembalikan')
        $whereClause
        GROUP BY b.id_buku, k.nama_kategori, p.nama_penulis, pb.nama_penerbit
        $orderBy";

$books = [];
if ($stmt = $conn->prepare($sql)) {
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $books = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
    }
    $stmt->close();
}

// Get categories for filter
$categories = [];
$categories_result = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori");
if ($categories_result) {
    $categories = $categories_result->fetch_all(MYSQLI_ASSOC);
    $categories_result->free();
}

// Get years for filter
$years = [];
$years_result = $conn->query("SELECT DISTINCT tahun_terbit FROM buku ORDER BY tahun_terbit DESC");
if ($years_result) {
    $years = $years_result->fetch_all(MYSQLI_ASSOC);
    $years_result->free();
}

// Get statistics with approved loan count
$stats = [
    'total_buku' => 0,
    'total_pinjam' => 0,
    'avg_rating' => 0
];

$stats_sql = "SELECT 
        COUNT(*) as total_buku,
        COALESCE(SUM(approved_pinjam), 0) as total_pinjam,
        COALESCE(AVG(rata_rating), 0) as avg_rating
    FROM (
        SELECT 
            b.id_buku,
            b.rata_rating,
            COUNT(DISTINCT CASE WHEN pm.status IN ('dipinjam', 'dikembalikan') THEN pm.id_peminjaman END) as approved_pinjam
        FROM buku b
        LEFT JOIN peminjaman pm ON b.id_buku = pm.id_buku 
            AND pm.status IN ('dipinjam', 'dikembalikan')
        GROUP BY b.id_buku, b.rata_rating
    ) as book_stats";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $stats_result->free();
    
    // Ensure numeric values
    $stats['total_buku'] = intval($stats['total_buku'] ?? 0);
    $stats['total_pinjam'] = intval($stats['total_pinjam'] ?? 0);
    $stats['avg_rating'] = floatval($stats['avg_rating'] ?? 0);
}

// Get popular books (30 hari terakhir, only approved loans)
$popular_books = [];
$popular_sql = "
    SELECT b.*, k.nama_kategori, p.nama_penulis,
           COUNT(CASE WHEN pm.status IN ('dipinjam', 'dikembalikan') AND pm.tanggal_pinjam >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                      THEN pm.id_peminjaman END) as jumlah_pinjam_bulan
    FROM buku b
    LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
    LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
    LEFT JOIN peminjaman pm ON b.id_buku = pm.id_buku 
        AND pm.status IN ('dipinjam', 'dikembalikan')
    GROUP BY b.id_buku, k.nama_kategori, p.nama_penulis
    ORDER BY jumlah_pinjam_bulan DESC, (
        SELECT COUNT(*) 
        FROM peminjaman pm2 
        WHERE pm2.id_buku = b.id_buku 
        AND pm2.status IN ('dipinjam', 'dikembalikan')
    ) DESC
    LIMIT 5";

$popular_result = $conn->query($popular_sql);
if ($popular_result) {
    $popular_books = $popular_result->fetch_all(MYSQLI_ASSOC);
    $popular_result->free();
}

$conn->close();

// Prepare category mapping for display
$category_map = [];
foreach ($categories as $cat) {
    $category_map[$cat['id_kategori']] = $cat['nama_kategori'];
}

// Helper function untuk escape output dengan aman
function safe_html($value, $default = '') {
    if (is_null($value) || $value === '') {
        return htmlspecialchars($default ?: '', ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Prepare filter display text
$filter_display = [];
if (!empty($kategori)) {
    $category_name = isset($category_map[$kategori]) ? $category_map[$kategori] : 'Tidak diketahui';
    $filter_display[] = "Kategori: " . safe_html($category_name);
}
if ($status_stok === 'tersedia') {
    $filter_display[] = "Stok: Tersedia";
} elseif ($status_stok === 'habis') {
    $filter_display[] = "Stok: Habis";
}
if (!empty($tahun)) {
    $filter_display[] = "Tahun: " . safe_html($tahun);
}
if (!empty($rating_min)) {
    $filter_display[] = "Rating: " . safe_html($rating_min) . "+";
}
$filter_text = !empty($filter_display) ? implode(', ', $filter_display) : 'Tidak ada filter';

// Define SITE_URL if not defined
if (!defined('SITE_URL')) {
    define('SITE_URL', '/');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safe_html($page_title); ?> - Digital Library Admin</title>
    
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
        
        /* Custom CSS from laporan_buku.php */
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
        
        .book-cover-small {
            width: 50px;
            height: 65px;
            border-radius: 5px;
            overflow: hidden;
            background: #f3f4f6;
        }
        
        .book-cover-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .rank-badge {
            position: absolute;
            top: -8px;
            left: -8px;
            width: 20px;
            height: 20px;
            background: #3b82f6;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
            z-index: 1;
        }
        
        .popular-book-item {
            padding: 10px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .popular-book-item:hover {
            background-color: #f9fafb;
        }
        
        .stars-small {
            display: inline-flex;
            align-items: center;
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
            text-align: right;
        }
        
        /* Main content layout for laporan_buku.php */
        .main-content-laporan {
            padding: 2rem;
            min-height: calc(100vh - 70px);
            background-color: #f8fafc;
        }
        
        @media (max-width: 992px) {
            .main-content-laporan {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
        }
        
        /* Status badge for loan count */
        .loan-status-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .loan-status-badge i {
            font-size: 0.7rem;
        }

        /* NEW STYLES FOR SCROLLABLE TABLE */
        .scrollable-table-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .scrollable-table-container table {
            margin-bottom: 0;
        }

        .scrollable-table-container thead th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 10;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }

        .scrollable-table-container::-webkit-scrollbar {
            width: 8px;
        }

        .scrollable-table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .scrollable-table-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .scrollable-table-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* CENTER STATISTICS CARDS */
        .centered-stats {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 2rem;
        }

        .centered-stats .col-xl-3 {
            flex: 0 0 auto;
            width: 30%;
            max-width: 280px;
        }

        @media (max-width: 1200px) {
            .centered-stats {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .centered-stats .col-xl-3 {
                width: 45%;
            }
        }

        @media (max-width: 768px) {
            .centered-stats {
                flex-direction: column;
                align-items: center;
            }
            
            .centered-stats .col-xl-3 {
                width: 100%;
                max-width: 300px;
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
                            <span class="badge bg-danger"><?php echo safe_html($total_pending_for_sidebar); ?></span>
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
                                    <span class="badge bg-danger float-end"><?php echo safe_html($total_pending_for_sidebar); ?></span>
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
                                <a class="dropdown-item active" href="laporan_buku.php">
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
                        <i class="<?php echo safe_html($page_icon); ?>"></i>
                        <?php echo safe_html($page_title); ?>
                    </h1>
                </div>
                
                <div class="topbar-right">
                    <!-- Removed notifications icon as requested -->
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content-laporan">
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <button type="button" class="btn btn-success" onclick="exportBookReport()">
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
                                    <label class="form-label">Kategori</label>
                                    <select class="form-select" name="kategori">
                                        <option value="">Semua Kategori</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo safe_html($cat['id_kategori']); ?>"
                                            <?php echo $kategori == $cat['id_kategori'] ? 'selected' : ''; ?>>
                                            <?php echo safe_html($cat['nama_kategori']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status Stok</label>
                                    <select class="form-select" name="status_stok">
                                        <option value="">Semua Status</option>
                                        <option value="tersedia" <?php echo $status_stok == 'tersedia' ? 'selected' : ''; ?>>Tersedia</option>
                                        <option value="habis" <?php echo $status_stok == 'habis' ? 'selected' : ''; ?>>Habis</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tahun Terbit</label>
                                    <select class="form-select" name="tahun">
                                        <option value="">Semua Tahun</option>
                                        <?php foreach ($years as $year): ?>
                                        <option value="<?php echo safe_html($year['tahun_terbit'] ?? ''); ?>"
                                            <?php echo $tahun == ($year['tahun_terbit'] ?? '') ? 'selected' : ''; ?>>
                                            <?php echo safe_html($year['tahun_terbit'] ?? ''); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Min Rating</label>
                                    <select class="form-select" name="rating_min">
                                        <option value="">Semua Rating</option>
                                        <option value="1" <?php echo $rating_min == '1' ? 'selected' : ''; ?>>1+ Bintang</option>
                                        <option value="2" <?php echo $rating_min == '2' ? 'selected' : ''; ?>>2+ Bintang</option>
                                        <option value="3" <?php echo $rating_min == '3' ? 'selected' : ''; ?>>3+ Bintang</option>
                                        <option value="4" <?php echo $rating_min == '4' ? 'selected' : ''; ?>>4+ Bintang</option>
                                        <option value="5" <?php echo $rating_min == '5' ? 'selected' : ''; ?>>5 Bintang</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Urutkan</label>
                                    <select class="form-select" name="sort">
                                        <option value="terbaru" <?php echo $sort == 'terbaru' ? 'selected' : ''; ?>>Terbaru</option>
                                        <option value="rating" <?php echo $sort == 'rating' ? 'selected' : ''; ?>>Rating Tertinggi</option>
                                        <option value="populer" <?php echo $sort == 'populer' ? 'selected' : ''; ?>>Paling Populer</option>
                                        <option value="abjad" <?php echo $sort == 'abjad' ? 'selected' : ''; ?>>A-Z</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-2"></i>Terapkan Filter
                                        </button>
                                        <a href="laporan_buku.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-redo me-2"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Statistics Cards - CENTERED -->
                    <div class="centered-stats">
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-primary">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['total_buku']); ?></div>
                                    <div class="stat-label">Total Buku</div>
                                </div>
                            </div>
                        </div>
                        <!-- Stock statistics removed per request -->
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-warning">
                                        <i class="fas fa-book-reader"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['total_pinjam']); ?></div>
                                    <div class="stat-label">Total Disetujui</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <div class="stat-icon bg-info">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="stat-number"><?php echo number_format($stats['avg_rating'], 1); ?></div>
                                    <div class="stat-label">Rating Rata-rata</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <!-- Books Table with Scroll -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        Data Buku
                                        <span class="badge bg-primary ms-2"><?php echo count($books); ?> buku</span>
                                    </h5>
                                    <div class="d-flex gap-2">
                                        <!-- Print button removed per request -->
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Scrollable Table Container -->
                                    <div class="scrollable-table-container" id="bookTable">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Buku</th>
                                                    <th>Kategori</th>
                                                    <th>Penulis</th>
                                                    <th>Stok</th>
                                                    <th>Rating</th>
                                                    <th>Pinjam</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($books)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center py-4">
                                                        <i class="fas fa-book-open fa-2x text-muted mb-3"></i>
                                                        <p class="text-muted mb-0">Tidak ada data buku</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($books as $index => $book): 
                                                        $cover = isset($book['cover_buku']) && !empty($book['cover_buku']) ? $book['cover_buku'] : 'default.jpg';
                                                        $book_rating = floatval($book['rating_avg'] ?? 0);
                                                        $rating_count = intval($book['rating_count'] ?? 0);
                                                        $approved_count = intval($book['approved_pinjam_count'] ?? 0);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="book-cover-mini me-3">
                                                                    <img src="<?php echo safe_html(SITE_URL . 'uploads/covers/' . $cover); ?>" 
                                                                         alt="<?php echo safe_html($book['judul_buku'] ?? 'Buku Tanpa Judul'); ?>"
                                                                         onerror="this.src='<?php echo safe_html(SITE_URL); ?>uploads/covers/default.jpg'">
                                                                </div>
                                                                <div>
                                                                    <div class="fw-bold"><?php echo safe_html($book['judul_buku'] ?? 'Buku Tanpa Judul'); ?></div>
                                                                    <small class="text-muted"><?php echo safe_html($book['tahun_terbit'] ?? '-'); ?></small>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td><?php echo safe_html($book['nama_kategori'] ?? '-'); ?></td>
                                                        <td><?php echo safe_html($book['nama_penulis'] ?? '-'); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo ($book['stok'] ?? 0) > 0 ? 'bg-success' : 'bg-danger'; ?>">
                                                                <?php echo safe_html($book['stok'] ?? 0); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="stars-small">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <?php if ($i <= floor($book_rating)): ?>
                                                                        <i class="fas fa-star text-warning" style="font-size: 0.8rem;"></i>
                                                                    <?php elseif ($i <= ceil($book_rating) && $book_rating - floor($book_rating) >= 0.5): ?>
                                                                        <i class="fas fa-star-half-alt text-warning" style="font-size: 0.8rem;"></i>
                                                                    <?php else: ?>
                                                                        <i class="far fa-star text-warning" style="font-size: 0.8rem;"></i>
                                                                    <?php endif; ?>
                                                                <?php endfor; ?>
                                                                <small class="text-muted">(<?php echo safe_html($rating_count); ?>)</small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="text-center">
                                                                <div class="fw-bold"><?php echo safe_html($approved_count); ?></div>
                                                                <small class="text-muted">
                                                                    <span class="loan-status-badge">
                                                                        <i class="fas fa-check-circle"></i> Disetujui
                                                                    </span>
                                                                </small>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Table info -->
                                    <div class="mt-3 text-center text-muted small">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Tabel dapat di-scroll vertikal untuk melihat lebih banyak data
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Popular Books & Statistics -->
                        <div class="col-lg-4">
                            <!-- Popular Books -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-fire me-2"></i>Buku Populer (30 Hari)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="popular-books-list">
                                        <?php foreach ($popular_books as $index => $book): 
                                            $cover = isset($book['cover_buku']) && !empty($book['cover_buku']) ? $book['cover_buku'] : 'default.jpg';
                                            $loan_count = intval($book['jumlah_pinjam_bulan'] ?? 0);
                                            $rating = floatval($book['rata_rating'] ?? 0);
                                        ?>
                                        <div class="popular-book-item mb-3">
                                            <div class="d-flex align-items-center">
                                                <div class="position-relative me-3">
                                                    <span class="rank-badge"><?php echo $index + 1; ?></span>
                                                    <div class="book-cover-small">
                                                        <img src="<?php echo safe_html(SITE_URL . 'uploads/covers/' . $cover); ?>" 
                                                             alt="<?php echo safe_html($book['judul_buku'] ?? 'Buku Tanpa Judul'); ?>"
                                                             onerror="this.src='<?php echo safe_html(SITE_URL); ?>uploads/covers/default.jpg'">
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold" style="font-size: 0.9rem;"><?php echo safe_html($book['judul_buku'] ?? 'Buku Tanpa Judul'); ?></div>
                                                    <div class="text-muted" style="font-size: 0.8rem;"><?php echo safe_html($book['nama_penulis'] ?? '-'); ?></div>
                                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                                        <small class="text-primary">
                                                            <i class="fas fa-book-reader me-1"></i>
                                                            <?php echo safe_html($loan_count); ?>x
                                                            <span class="loan-status-badge ms-1" style="font-size: 0.6rem; padding: 1px 4px;">
                                                                <i class="fas fa-check-circle"></i>
                                                            </span>
                                                        </small>
                                                        <small class="text-warning">
                                                            <i class="fas fa-star me-1"></i>
                                                            <?php echo number_format($rating, 1); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Stock statistics removed -->
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
                                        <span class="summary-label">Total Buku</span>
                                        <span class="summary-value"><?php echo number_format($stats['total_buku']); ?></span>
                                    </div>
                                    <!-- Stock summary removed -->
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-item">
                                        <span class="summary-label">Total Disetujui</span>
                                        <span class="summary-value"><?php echo number_format($stats['total_pinjam']); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Rating Rata-rata</span>
                                        <span class="summary-value"><?php echo number_format($stats['avg_rating'], 1); ?>/5.0</span>
                                    </div>
                                    <!-- Buku Habis summary removed -->
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-item">
                                        <span class="summary-label">Tanggal Laporan</span>
                                        <span class="summary-value"><?php echo date('d/m/Y H:i'); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Filter Aktif</span>
                                        <span class="summary-value"><?php echo safe_html($filter_text); ?></span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Jumlah Data</span>
                                        <span class="summary-value"><?php echo count($books); ?> dari <?php echo $stats['total_buku']; ?> buku</span>
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
                    <a href="<?php echo safe_html(SITE_URL); ?>auth/logout.php" class="btn btn-danger">
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

        // Stock chart removed
        
        // Export book report to Excel
        function exportBookReport() {
            // Redirect to export endpoint
            window.location.href = 'export_excel.php?type=buku&' + new URLSearchParams(window.location.search).toString();
        }
        
        // Print functionality removed

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
            
            // Add error handling for images
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('error', function() {
                    this.src = '<?php echo safe_html(SITE_URL); ?>uploads/covers/default.jpg';
                });
            });
        });
    </script>
</body>
</html>