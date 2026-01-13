<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Tambahkan fungsi clean_input jika belum ada
if (!function_exists('clean_input')) {
    function clean_input($data) {
        if (empty($data)) return '';
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

// Only admin can access this page
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

// Set page variables
$page_title = 'Kelola Kategori';
$page_icon = 'fas fa-tags';

// Initialize variables
$message = '';
$message_type = '';
$categories = [];
$total_categories = 0;

// Get pending count for sidebar badge
$conn = getConnection();
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $nama_kategori = clean_input($_POST['nama_kategori']);
        $deskripsi = clean_input($_POST['deskripsi']);
        
        // Validasi input
        if (empty($nama_kategori)) {
            $message = 'Nama kategori tidak boleh kosong';
            $message_type = 'danger';
        } else {
            $sql = "INSERT INTO kategori (nama_kategori, deskripsi) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $nama_kategori, $deskripsi);
            
            if ($stmt->execute()) {
                $message = 'Kategori berhasil ditambahkan';
                $message_type = 'success';
            } else {
                $message = 'Gagal menambahkan kategori';
                $message_type = 'danger';
            }
            $stmt->close();
        }
    }
    elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $nama_kategori = clean_input($_POST['nama_kategori']);
        $deskripsi = clean_input($_POST['deskripsi']);
        
        // Validasi input
        if (empty($nama_kategori)) {
            $message = 'Nama kategori tidak boleh kosong';
            $message_type = 'danger';
        } else {
            $sql = "UPDATE kategori SET nama_kategori = ?, deskripsi = ? WHERE id_kategori = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $nama_kategori, $deskripsi, $id);
            
            if ($stmt->execute()) {
                $message = 'Kategori berhasil diperbarui';
                $message_type = 'success';
            } else {
                $message = 'Gagal memperbarui kategori';
                $message_type = 'danger';
            }
            $stmt->close();
        }
    }
    elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Validasi ID
        if ($id <= 0) {
            $message = 'ID kategori tidak valid';
            $message_type = 'danger';
        } else {
            // Check if category is used
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM buku WHERE id_kategori = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result['total'] > 0) {
                $message = 'Kategori tidak dapat dihapus karena masih digunakan oleh ' . $result['total'] . ' buku';
                $message_type = 'danger';
            } else {
                $sql = "DELETE FROM kategori WHERE id_kategori = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $message = 'Kategori berhasil dihapus';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menghapus kategori';
                    $message_type = 'danger';
                }
                $stmt->close();
            }
        }
    }
}

// Get all categories
$query = $conn->query("
    SELECT k.*, 
           (SELECT COUNT(*) FROM buku WHERE id_kategori = k.id_kategori) as total_buku
    FROM kategori k
    ORDER BY k.nama_kategori
");

if ($query) {
    $categories = $query->fetch_all(MYSQLI_ASSOC);
    $total_categories = count($categories);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    
<?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)): ?>
    <meta http-equiv="refresh" content="2;url=<?php echo $_SERVER['PHP_SELF']; ?>">
<?php endif; ?>

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
        
        /* Category Card Styles */
        .category-card {
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            height: 100%;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .category-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
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
        
        /* Badges */
        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-primary {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }
        
        .badge-secondary {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }
        
        /* Dropdown menu actions */
        .dropdown-menu {
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .dropdown-item {
            padding: 8px 16px;
            font-size: 0.9rem;
        }
        
        .dropdown-item:hover {
            background-color: var(--primary-light);
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: none;
        }
        
        .modal-header {
            border-bottom: 1px solid var(--gray-200);
            padding: 1.25rem 1.5rem;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding: 1rem 1.5rem;
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .stat-card h2 {
                font-size: 1.5rem;
            }
            
            .category-card {
                margin-bottom: 1rem;
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
                                <a class="dropdown-item active" href="kelola_kategori.php">
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
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        
                        <div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                <i class="fas fa-plus me-2"></i>Tambah Kategori
                            </button>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Kategori</h5>
                                        <h2 class="mb-0"><?php echo $total_categories; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-tags fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Kategori Terpakai</h5>
                                        <h2 class="mb-0">
                                            <?php 
                                            $used_categories = 0;
                                            foreach ($categories as $cat) {
                                                if ($cat['total_buku'] > 0) {
                                                    $used_categories++;
                                                }
                                            }
                                            echo $used_categories;
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
                                        <h5 class="mb-2">Kategori Kosong</h5>
                                        <h2 class="mb-0"><?php echo $total_categories - $used_categories; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-folder fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-info">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Buku</h5>
                                        <h2 class="mb-0">
                                            <?php 
                                            $total_books = 0;
                                            foreach ($categories as $cat) {
                                                $total_books += $cat['total_buku'];
                                            }
                                            echo $total_books;
                                            ?>
                                        </h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-books fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Categories Grid -->
                    <div class="row">
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $category): ?>
                            <div class="col-md-4 mb-4">
                                <div class="card category-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($category['nama_kategori']); ?></h5>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary" type="button" 
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a class="dropdown-item" href="#" 
                                                           onclick="editCategory(<?php echo $category['id_kategori']; ?>, '<?php echo addslashes(htmlspecialchars($category['nama_kategori'])); ?>', '<?php echo addslashes(htmlspecialchars($category['deskripsi'] ?? '')); ?>')">
                                                            <i class="fas fa-edit me-2"></i>Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="#" 
                                                           onclick="deleteCategory(<?php echo $category['id_kategori']; ?>, '<?php echo addslashes(htmlspecialchars($category['nama_kategori'])); ?>')">
                                                            <i class="fas fa-trash me-2"></i>Hapus
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($category['deskripsi'])): ?>
                                        <p class="card-text text-muted mb-3"><?php echo htmlspecialchars($category['deskripsi']); ?></p>
                                        <?php endif; ?>
                                        
                                        <div class="category-stats">
                                            <span class="badge-status badge-<?php echo $category['total_buku'] > 0 ? 'primary' : 'secondary'; ?>">
                                                <i class="fas fa-book me-1"></i>
                                                <?php echo $category['total_buku']; ?> buku
                                            </span>
                                            <small class="text-muted ms-2">
                                                ID: <?php echo $category['id_kategori']; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <div class="empty-state">
                                        <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                        <h4 class="text-muted mb-3">Belum ada kategori</h4>
                                        <p class="text-muted mb-0">Mulai dengan menambahkan kategori pertama Anda</p>
                                        <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                            <i class="fas fa-plus me-2"></i>Tambah Kategori Pertama
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
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

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tambah Kategori Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" onsubmit="return validateAddForm()">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nama_kategori" id="addCategoryName" required 
                                   placeholder="Contoh: Fiksi, Sains, Teknologi" maxlength="100">
                            <div class="invalid-feedback" id="addCategoryNameError"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi (Opsional)</label>
                            <textarea class="form-control" name="deskripsi" rows="3" 
                                      placeholder="Deskripsi singkat tentang kategori ini" maxlength="255"></textarea>
                            <div class="form-text">Maksimal 255 karakter</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Kategori</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Kategori</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" onsubmit="return validateEditForm()">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editCategoryId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nama_kategori" 
                                   id="editCategoryName" required maxlength="100">
                            <div class="invalid-feedback" id="editCategoryNameError"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea class="form-control" name="deskripsi" rows="3" 
                                      id="editCategoryDesc" maxlength="255"></textarea>
                            <div class="form-text">Maksimal 255 karakter</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Perbarui Kategori</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Category Modal -->
    <div class="modal fade" id="deleteCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Hapus Kategori</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteCategoryId">
                    
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <i class="fas fa-exclamation-circle fa-3x text-danger mb-3"></i>
                        </div>
                        <p>Anda yakin ingin menghapus kategori <strong id="deleteCategoryName"></strong>?</p>
                        <p class="text-danger">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Perhatian: Tindakan ini tidak dapat dibatalkan!
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>Ya, Hapus
                        </button>
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

        // Edit category
        function editCategory(id, name, desc) {
            document.getElementById('editCategoryId').value = id;
            document.getElementById('editCategoryName').value = name;
            document.getElementById('editCategoryDesc').value = desc || '';
            
            // Reset validation
            document.getElementById('editCategoryName').classList.remove('is-invalid');
            document.getElementById('editCategoryNameError').textContent = '';
            
            const modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
            modal.show();
        }
        
        // Delete category
        function deleteCategory(id, name) {
            document.getElementById('deleteCategoryId').value = id;
            document.getElementById('deleteCategoryName').textContent = name;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
            modal.show();
        }
        
        // Form validation
        function validateAddForm() {
            const nameInput = document.getElementById('addCategoryName');
            const errorElement = document.getElementById('addCategoryNameError');
            
            if (nameInput.value.trim() === '') {
                nameInput.classList.add('is-invalid');
                errorElement.textContent = 'Nama kategori tidak boleh kosong';
                return false;
            }
            
            if (nameInput.value.trim().length < 3) {
                nameInput.classList.add('is-invalid');
                errorElement.textContent = 'Nama kategori minimal 3 karakter';
                return false;
            }
            
            nameInput.classList.remove('is-invalid');
            return true;
        }
        
        function validateEditForm() {
            const nameInput = document.getElementById('editCategoryName');
            const errorElement = document.getElementById('editCategoryNameError');
            
            if (nameInput.value.trim() === '') {
                nameInput.classList.add('is-invalid');
                errorElement.textContent = 'Nama kategori tidak boleh kosong';
                return false;
            }
            
            if (nameInput.value.trim().length < 3) {
                nameInput.classList.add('is-invalid');
                errorElement.textContent = 'Nama kategori minimal 3 karakter';
                return false;
            }
            
            nameInput.classList.remove('is-invalid');
            return true;
        }
        
        // Reset form validation when modal is closed
        document.getElementById('addCategoryModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('addCategoryName').classList.remove('is-invalid');
        });
        
        document.getElementById('editCategoryModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('editCategoryName').classList.remove('is-invalid');
        });
        
        // Auto-focus on input when modal opens
        document.getElementById('addCategoryModal').addEventListener('shown.bs.modal', function () {
            document.getElementById('addCategoryName').focus();
        });
        
        document.getElementById('editCategoryModal').addEventListener('shown.bs.modal', function () {
            document.getElementById('editCategoryName').focus();
        });

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