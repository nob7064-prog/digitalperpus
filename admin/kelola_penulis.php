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
        if (empty($data) || $data === null) return '';
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$page_title = 'Kelola Penulis';
$page_icon = 'fas fa-user-edit';

$conn = getConnection();
$message = '';
$message_type = '';
$current_file = basename(__FILE__);

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Cek struktur tabel untuk kolom tahun_lahir dan tahun_wafat
$check_columns = false;
$has_year_columns = false;
try {
    $result = $conn->query("DESCRIBE penulis");
    if ($result) {
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        $has_year_columns = in_array('tahun_lahir', $columns) && in_array('tahun_wafat', $columns);
        $check_columns = true;
    }
} catch (Exception $e) {
    $check_columns = false;
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $nama_penulis = clean_input($_POST['nama_penulis'] ?? '');
        $nama_penulis = preg_replace('/\s+/', ' ', trim($nama_penulis));
        $deskripsi = clean_input($_POST['biografi'] ?? '');
        $asal_negara = clean_input($_POST['kebangsaan'] ?? '');
        
        // Hanya tambahkan tahun jika kolom ada di database
        if ($has_year_columns) {
            $tahun_lahir = !empty($_POST['tahun_lahir']) ? intval($_POST['tahun_lahir']) : null;
            $tahun_wafat = !empty($_POST['tahun_wafat']) ? intval($_POST['tahun_wafat']) : null;
        } else {
            $tahun_lahir = $tahun_wafat = null;
        }
        
        if (empty($nama_penulis)) {
            $message = 'Nama penulis tidak boleh kosong';
            $message_type = 'danger';
        } else {
            // Check for duplicate (case-insensitive)
            $stmt = $conn->prepare("SELECT id_penulis FROM penulis WHERE LOWER(TRIM(nama_penulis)) = LOWER(TRIM(?))");
            $stmt->bind_param("s", $nama_penulis);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = 'Penulis dengan nama tersebut sudah ada';
                $message_type = 'danger';
            } else {
                if ($has_year_columns) {
                    $sql = "INSERT INTO penulis (nama_penulis, deskripsi, asal_negara, tahun_lahir, tahun_wafat) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("sssii", $nama_penulis, $deskripsi, $asal_negara, $tahun_lahir, $tahun_wafat);
                    }
                } else {
                    $sql = "INSERT INTO penulis (nama_penulis, deskripsi, asal_negara) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("sss", $nama_penulis, $deskripsi, $asal_negara);
                    }
                }
                
                if ($stmt && $stmt->execute()) {
                    $message = 'Penulis berhasil ditambahkan';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menambahkan penulis: ' . ($stmt ? $stmt->error : $conn->error);
                    $message_type = 'danger';
                }
                if ($stmt) $stmt->close();
            }
        }
    }
    elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $nama_penulis = clean_input($_POST['nama_penulis'] ?? '');
        $nama_penulis = preg_replace('/\s+/', ' ', trim($nama_penulis));
        $deskripsi = clean_input($_POST['biografi'] ?? '');
        $asal_negara = clean_input($_POST['kebangsaan'] ?? '');
        
        // Hanya update tahun jika kolom ada di database
        if ($has_year_columns) {
            $tahun_lahir = !empty($_POST['tahun_lahir']) ? intval($_POST['tahun_lahir']) : null;
            $tahun_wafat = !empty($_POST['tahun_wafat']) ? intval($_POST['tahun_wafat']) : null;
        }
        
        if (empty($nama_penulis)) {
            $message = 'Nama penulis tidak boleh kosong';
            $message_type = 'danger';
        } elseif ($id <= 0) {
            $message = 'ID penulis tidak valid';
            $message_type = 'danger';
        } else {
            // Check for duplicate excluding current id
            $stmt = $conn->prepare("SELECT id_penulis FROM penulis WHERE LOWER(TRIM(nama_penulis)) = LOWER(TRIM(?)) AND id_penulis != ?");
            $stmt->bind_param("si", $nama_penulis, $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = 'Nama penulis sudah digunakan oleh penulis lain';
                $message_type = 'danger';
            } else {
                if ($has_year_columns) {
                    $sql = "UPDATE penulis SET nama_penulis = ?, deskripsi = ?, asal_negara = ?, tahun_lahir = ?, tahun_wafat = ? WHERE id_penulis = ?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("sssiii", $nama_penulis, $deskripsi, $asal_negara, $tahun_lahir, $tahun_wafat, $id);
                    }
                } else {
                    $sql = "UPDATE penulis SET nama_penulis = ?, deskripsi = ?, asal_negara = ? WHERE id_penulis = ?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("sssi", $nama_penulis, $deskripsi, $asal_negara, $id);
                    }
                }
                
                if ($stmt && $stmt->execute()) {
                    $message = 'Penulis berhasil diperbarui';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal memperbarui penulis: ' . ($stmt ? $stmt->error : $conn->error);
                    $message_type = 'danger';
                }
                if ($stmt) $stmt->close();
            }
        }
    }
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            $message = 'ID penulis tidak valid';
            $message_type = 'danger';
        } else {
            // Check if author is used
            $check = $conn->prepare("SELECT COUNT(*) as total FROM buku WHERE id_penulis = ?");
            if ($check) {
                $check->bind_param("i", $id);
                $check->execute();
                $result = $check->get_result()->fetch_assoc();
                $check->close();
                
                if ($result['total'] > 0) {
                    $message = 'Penulis tidak dapat dihapus karena digunakan oleh ' . $result['total'] . ' buku';
                    $message_type = 'danger';
                } else {
                    $sql = "DELETE FROM penulis WHERE id_penulis = ?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $id);
                        
                        if ($stmt->execute()) {
                            $message = 'Penulis berhasil dihapus';
                            $message_type = 'success';
                        } else {
                            $message = 'Gagal menghapus penulis: ' . $stmt->error;
                            $message_type = 'danger';
                        }
                        $stmt->close();
                    } else {
                        $message = 'Gagal menyiapkan query: ' . $conn->error;
                        $message_type = 'danger';
                    }
                }
            }
        }
    }
}

// Get all authors with book count
$authors = [];
$duplicate_groups = [];
$duplicate_map = [];

try {
    // Query yang aman untuk semua struktur tabel
    if ($has_year_columns) {
        $query = $conn->query("
            SELECT p.*, 
                   (SELECT COUNT(*) FROM buku WHERE id_penulis = p.id_penulis) as total_buku,
                   (SELECT COUNT(*) FROM peminjaman pm 
                    JOIN buku b ON pm.id_buku = b.id_buku 
                    WHERE b.id_penulis = p.id_penulis) as total_pinjam
            FROM penulis p
            ORDER BY p.nama_penulis
        ");
    } else {
        $query = $conn->query("
            SELECT p.id_penulis, p.nama_penulis, p.deskripsi, p.asal_negara,
                   (SELECT COUNT(*) FROM buku WHERE id_penulis = p.id_penulis) as total_buku,
                   (SELECT COUNT(*) FROM peminjaman pm 
                    JOIN buku b ON pm.id_buku = b.id_buku 
                    WHERE b.id_penulis = p.id_penulis) as total_pinjam
            FROM penulis p
            ORDER BY p.nama_penulis
        ");
    }
    
    if ($query) {
        $authors = $query->fetch_all(MYSQLI_ASSOC);
        
        // Pastikan semua field ada untuk setiap author
        foreach ($authors as &$author) {
            $default_fields = [
                'id_penulis' => 0,
                'nama_penulis' => '',
                'deskripsi' => '',
                'asal_negara' => '',
                'total_buku' => 0,
                'total_pinjam' => 0
            ];
            
            if ($has_year_columns) {
                $default_fields['tahun_lahir'] = null;
                $default_fields['tahun_wafat'] = null;
            }
            
            $author = array_merge($default_fields, $author);
        }
        
        // Detect duplicate author names (case-insensitive)
        if (!empty($authors)) {
            $name_map = [];
            foreach ($authors as $a) {
                if (!empty($a['nama_penulis'])) {
                    $norm = mb_strtolower(preg_replace('/\s+/', ' ', trim($a['nama_penulis'])));
                    $name_map[$norm][] = $a;
                }
            }

            // Identifikasi duplikat
            foreach ($name_map as $norm => $group) {
                if (count($group) > 1) {
                    $duplicate_groups[$norm] = $group;
                    foreach ($group as $g) {
                        $duplicate_ids = array_map(
                            function($x) { 
                                return intval($x['id_penulis']); 
                            }, 
                            $group
                        );
                        $duplicate_map[intval($g['id_penulis'])] = $duplicate_ids;
                    }
                }
            }
            
            // Hapus duplikat dari tampilan utama (hanya tampilkan satu)
            $unique_authors = [];
            $seen_names = [];
            
            foreach ($authors as $author) {
                $norm = mb_strtolower(preg_replace('/\s+/', ' ', trim($author['nama_penulis'])));
                
                if (!isset($seen_names[$norm])) {
                    $seen_names[$norm] = true;
                    $unique_authors[] = $author;
                }
            }
            
            $authors = $unique_authors;
        }
    } else {
        throw new Exception('Gagal mengambil data penulis: ' . $conn->error);
    }
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    $message_type = 'danger';
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
        
        /* Custom styles for kelola_penulis.php */
        .table-warning {
            background-color: rgba(255, 193, 7, 0.1) !important;
        }
        
        .table-warning:hover {
            background-color: rgba(255, 193, 7, 0.2) !important;
        }
        
        .badge-duplicate {
            background-color: #ffc107;
            color: #000;
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
                                <a class="dropdown-item" href="kelola_penerbit.php">
                                    <i class="fas fa-building me-2"></i>Penerbit
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item active" href="kelola_penulis.php">
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
                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Penulis</h5>
                                        <h2 class="mb-0"><?php echo count($authors); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-user-edit fa-2x opacity-75"></i>
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
                                                $total_buku = array_sum(array_column($authors, 'total_buku'));
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
                                                $total_pinjam = array_sum(array_column($authors, 'total_pinjam'));
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

                    <!-- Messages -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="<?php echo $message_type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if (!$check_columns): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Tidak dapat memeriksa struktur tabel. Beberapa fitur mungkin tidak tersedia.
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($duplicate_groups)): ?>
                    <div class="alert alert-warning">
                        <h6 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Duplikat Nama Penulis Ditemukan</h6>
                        <p class="mb-2">Berikut adalah nama-nama penulis yang terduplikasi:</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>Nama Penulis</th>
                                        <th>ID Duplikat</th>
                                        <th>Total Buku</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($duplicate_groups as $norm => $group): ?>
                                        <?php 
                                        // Hitung total buku untuk semua duplikat
                                        $total_buku = 0;
                                        foreach ($group as $g) {
                                            $total_buku += $g['total_buku'];
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($group[0]['nama_penulis']); ?></strong>
                                            </td>
                                            <td>
                                                <?php foreach ($group as $index => $g): ?>
                                                    <span class="badge bg-<?php echo $index === 0 ? 'primary' : 'warning text-dark'; ?>">
                                                        ID: <?php echo $g['id_penulis']; ?>
                                                    </span>
                                                    <?php if ($index < count($group) - 1): ?>, <?php endif; ?>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $total_buku; ?> buku</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-text mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Hanya menampilkan satu entri untuk setiap nama duplikat di tabel utama.
                            Gunakan tombol hapus atau edit untuk mengelola duplikat.
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Authors Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Daftar Penulis
                                <span class="badge bg-primary ms-2"><?php echo count($authors); ?></span>
                                <?php if (!empty($duplicate_groups)): ?>
                                <span class="badge bg-warning text-dark ms-1">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    <?php echo count($duplicate_groups); ?> duplikat
                                </span>
                                <?php endif; ?>
                            </h5>
                            <div class="d-flex gap-2">
                                <div class="input-group" style="width: 300px;">
                                    <input type="text" class="form-control" id="searchAuthor" placeholder="Cari penulis...">
                                    <button class="btn btn-outline-secondary" type="button" id="searchButton">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAuthorModal">
                                    <i class="fas fa-plus me-1"></i>Tambah Penulis
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="authorsTable">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Penulis</th>
                                            <th>Biografi</th>
                                            <th>Kebangsaan</th>
                                            <th>Statistik</th>
                                            <th width="150">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($authors)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5">
                                                <div class="empty-state">
                                                    <i class="fas fa-user-edit fa-3x text-muted mb-3"></i>
                                                    <h5 class="text-muted">Belum ada penulis</h5>
                                                    <p class="text-muted mb-0">Silakan tambahkan penulis baru</p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($authors as $index => $author): ?>
                                            <?php 
                                            $is_duplicate = !empty($duplicate_map) && isset($duplicate_map[$author['id_penulis']]);
                                            $duplicate_ids = $is_duplicate ? $duplicate_map[$author['id_penulis']] : [];
                                            ?>
                                            <tr id="author-<?php echo $author['id_penulis']; ?>" 
                                                class="<?php echo $is_duplicate ? 'table-warning' : ''; ?>"
                                                data-author-id="<?php echo $author['id_penulis']; ?>"
                                                data-author-name="<?php echo htmlspecialchars($author['nama_penulis']); ?>">
                                                <td>
                                                    <?php echo $index + 1; ?>
                                                    <?php if ($is_duplicate && $author['id_penulis'] === min($duplicate_ids)): ?>
                                                    <span class="badge bg-warning text-dark" title="Duplikat">
                                                        <i class="fas fa-copy"></i>
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($author['nama_penulis']); ?></div>
                                                    <?php if ($is_duplicate && $author['id_penulis'] === min($duplicate_ids)): ?>
                                                    <div class="mt-1">
                                                        <small class="text-warning">
                                                            <i class="fas fa-exclamation-circle me-1"></i>
                                                            Duplikat: 
                                                            <?php 
                                                            $other_ids = array_filter($duplicate_ids, function($id) use ($author) {
                                                                return $id != $author['id_penulis'];
                                                            });
                                                            echo 'ID ' . implode(', ', $other_ids);
                                                            ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($has_year_columns && $author['tahun_lahir']): ?>
                                                    <small class="text-muted">
                                                        (<?php echo $author['tahun_lahir']; ?>
                                                        <?php if ($author['tahun_wafat']): ?>
                                                        - <?php echo $author['tahun_wafat']; ?>
                                                        <?php endif; ?>
                                                        )
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($author['deskripsi']): ?>
                                                    <p class="mb-0 text-truncate" style="max-width: 200px;" 
                                                       title="<?php echo htmlspecialchars($author['deskripsi']); ?>">
                                                        <?php echo htmlspecialchars(substr($author['deskripsi'], 0, 100)); ?>
                                                        <?php if (strlen($author['deskripsi']) > 100): ?>...<?php endif; ?>
                                                    </p>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($author['asal_negara']): ?>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($author['asal_negara']); ?></span>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-3">
                                                        <div class="text-center">
                                                            <div class="fw-bold text-primary"><?php echo $author['total_buku']; ?></div>
                                                            <small class="text-muted">Buku</small>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="fw-bold text-success"><?php echo $author['total_pinjam']; ?></div>
                                                            <small class="text-muted">Pinjam</small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary edit-author-btn"
                                                            data-id="<?php echo $author['id_penulis']; ?>"
                                                            data-nama="<?php echo htmlspecialchars($author['nama_penulis'], ENT_QUOTES); ?>"
                                                            data-biografi="<?php echo htmlspecialchars($author['deskripsi'], ENT_QUOTES); ?>"
                                                            data-kebangsaan="<?php echo htmlspecialchars($author['asal_negara'], ENT_QUOTES); ?>"
                                                            <?php if ($has_year_columns): ?>
                                                            data-tahun_lahir="<?php echo $author['tahun_lahir']; ?>"
                                                            data-tahun_wafat="<?php echo $author['tahun_wafat']; ?>"
                                                            <?php endif; ?>>
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger delete-author-btn" 
                                                                data-id="<?php echo $author['id_penulis']; ?>" 
                                                                data-name="<?php echo htmlspecialchars($author['nama_penulis']); ?>"
                                                                <?php if ($is_duplicate): ?>
                                                                data-duplicate="true"
                                                                data-duplicate-ids="<?php echo htmlspecialchars(json_encode($duplicate_ids)); ?>"
                                                                <?php endif; ?>>
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

    <!-- Add Author Modal -->
    <div class="modal fade" id="addAuthorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tambah Penulis Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo $current_file; ?>" id="addAuthorForm">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Penulis <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_penulis" required 
                                       placeholder="Contoh: Andrea Hirata, Tere Liye">
                                <div class="invalid-feedback" id="addNameError"></div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Sistem akan memeriksa duplikat nama secara otomatis.
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kebangsaan</label>
                                <input type="text" class="form-control" name="kebangsaan" 
                                       placeholder="Contoh: Indonesia, Amerika">
                            </div>
                            <?php if ($has_year_columns): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tahun Lahir</label>
                                <input type="number" class="form-control" name="tahun_lahir" 
                                       min="1000" max="<?php echo date('Y'); ?>"
                                       placeholder="Contoh: 1967">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tahun Wafat</label>
                                <input type="number" class="form-control" name="tahun_wafat" 
                                       min="1000" max="<?php echo date('Y'); ?>"
                                       placeholder="Kosongkan jika masih hidup">
                            </div>
                            <?php endif; ?>
                            <div class="col-12 mb-3">
                                <label class="form-label">Biografi</label>
                                <textarea class="form-control" name="biografi" rows="4" 
                                          placeholder="Riwayat hidup dan karya penulis" maxlength="1000"></textarea>
                                <div class="form-text">Maksimal 1000 karakter</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Simpan Penulis
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Author Modal -->
    <div class="modal fade" id="editAuthorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Penulis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo $current_file; ?>" id="editAuthorModalForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editAuthorId">

                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Penulis <span class="text-danger">*</span></label>
                                <input id="edit_nama_penulis" type="text" class="form-control" name="nama_penulis" required>
                                <div class="invalid-feedback" id="editNameErrorModal"></div>
                                <div class="form-text" id="editDuplicateWarning" style="display: none;">
                                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                    <span class="text-warning">Nama ini sudah digunakan!</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kebangsaan</label>
                                <input id="edit_kebangsaan" type="text" class="form-control" name="kebangsaan" 
                                       placeholder="Contoh: Indonesia, Amerika">
                            </div>
                            <?php if ($has_year_columns): ?>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Tahun Lahir</label>
                                <input id="edit_tahun_lahir" type="number" class="form-control" name="tahun_lahir" 
                                       min="1000" max="<?php echo date('Y'); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Tahun Wafat</label>
                                <input id="edit_tahun_wafat" type="number" class="form-control" name="tahun_wafat" 
                                       min="1000" max="<?php echo date('Y'); ?>">
                            </div>
                            <?php endif; ?>
                            <div class="col-12 mb-3">
                                <label class="form-label">Biografi</label>
                                <textarea id="edit_biografi" class="form-control" name="biografi" rows="4" maxlength="1000"></textarea>
                                <div class="form-text">Maksimal 1000 karakter</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteAuthorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?php echo $current_file; ?>" id="deleteAuthorForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteAuthorId">
                    
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-trash-alt fa-3x text-danger mb-3"></i>
                        </div>
                        <p class="text-center">Anda yakin ingin menghapus penulis <strong id="deleteAuthorName"></strong>?</p>
                        
                        <div id="duplicateWarning" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <span id="duplicateWarningText"></span>
                        </div>
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Data yang dihapus tidak dapat dikembalikan!
                        </div>
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

        // Author search
        document.getElementById('searchAuthor').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#authorsTable tbody tr[data-author-id]');
            let found = false;
            
            rows.forEach(row => {
                const authorName = row.getAttribute('data-author-name').toLowerCase();
                if (authorName.includes(searchTerm)) {
                    row.style.display = '';
                    found = true;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const noResultsRow = document.querySelector('#authorsTable tbody tr:not([data-author-id])');
            if (searchTerm.length > 0 && !found) {
                if (noResultsRow) {
                    noResultsRow.innerHTML = `
                        <td colspan="6" class="text-center py-4">
                            <i class="fas fa-search fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">Tidak ditemukan penulis dengan nama "${searchTerm}"</p>
                        </td>
                    `;
                    noResultsRow.style.display = '';
                }
            } else if (noResultsRow && found) {
                noResultsRow.style.display = 'none';
            }
        });
        
        // Search button click
        document.getElementById('searchButton').addEventListener('click', function() {
            const searchInput = document.getElementById('searchAuthor');
            searchInput.focus();
            const event = new Event('input');
            searchInput.dispatchEvent(event);
        });
        
        // Delete author confirmation
        document.addEventListener('click', function(e) {
            if (e.target.closest('.delete-author-btn')) {
                const button = e.target.closest('.delete-author-btn');
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const isDuplicate = button.getAttribute('data-duplicate') === 'true';
                const duplicateIds = button.getAttribute('data-duplicate-ids') ? 
                    JSON.parse(button.getAttribute('data-duplicate-ids')) : [];
                
                document.getElementById('deleteAuthorId').value = id;
                document.getElementById('deleteAuthorName').textContent = name;
                
                // Show duplicate warning if applicable
                const duplicateWarning = document.getElementById('duplicateWarning');
                const duplicateWarningText = document.getElementById('duplicateWarningText');
                
                if (isDuplicate && duplicateIds.length > 1) {
                    const otherIds = duplicateIds.filter(dupId => dupId != id);
                    duplicateWarningText.textContent = `Penulis ini memiliki ${otherIds.length} duplikat lainnya (ID: ${otherIds.join(', ')}). Hapus semua duplikat untuk membersihkan data.`;
                    duplicateWarning.style.display = 'block';
                } else {
                    duplicateWarning.style.display = 'none';
                }
                
                const modal = new bootstrap.Modal(document.getElementById('deleteAuthorModal'));
                modal.show();
            }
        });

        // Edit author: open modal and populate fields
        document.addEventListener('click', function(e) {
            if (e.target.closest('.edit-author-btn')) {
                const btn = e.target.closest('.edit-author-btn');
                const id = btn.getAttribute('data-id');
                const nama = btn.getAttribute('data-nama') || '';
                const biografi = btn.getAttribute('data-biografi') || '';
                const kebangsaan = btn.getAttribute('data-kebangsaan') || '';
                const tahunLahir = btn.getAttribute('data-tahun_lahir') || '';
                const tahunWafat = btn.getAttribute('data-tahun_wafat') || '';

                document.getElementById('editAuthorId').value = id;
                document.getElementById('edit_nama_penulis').value = nama;
                document.getElementById('edit_biografi').value = biografi;
                document.getElementById('edit_kebangsaan').value = kebangsaan;
                
                <?php if ($has_year_columns): ?>
                document.getElementById('edit_tahun_lahir').value = tahunLahir;
                document.getElementById('edit_tahun_wafat').value = tahunWafat;
                <?php endif; ?>

                const modal = new bootstrap.Modal(document.getElementById('editAuthorModal'));
                modal.show();
            }
        });

        // Real-time duplicate check for edit modal
        document.addEventListener('DOMContentLoaded', function() {
            const editNameInput = document.getElementById('edit_nama_penulis');
            const editDuplicateWarning = document.getElementById('editDuplicateWarning');
            
            if (editNameInput) {
                editNameInput.addEventListener('input', function() {
                    checkDuplicateName(this.value, document.getElementById('editAuthorId').value);
                });
            }
            
            function checkDuplicateName(name, currentId) {
                if (!name.trim()) {
                    editDuplicateWarning.style.display = 'none';
                    return;
                }
                
                const normalizedName = name.toLowerCase().trim().replace(/\s+/g, ' ');
                const rows = document.querySelectorAll('#authorsTable tbody tr[data-author-id]');
                
                for (const row of rows) {
                    const rowId = row.getAttribute('data-author-id');
                    if (rowId === currentId) continue;
                    
                    const rowName = row.getAttribute('data-author-name').toLowerCase().trim().replace(/\s+/g, ' ');
                    
                    if (rowName === normalizedName) {
                        editDuplicateWarning.style.display = 'block';
                        return;
                    }
                }
                
                editDuplicateWarning.style.display = 'none';
            }
            
            // Validate form submissions
            const addForm = document.getElementById('addAuthorForm');
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    const nameInput = addForm.querySelector('input[name="nama_penulis"]');
                    if (!nameInput.value.trim()) {
                        e.preventDefault();
                        nameInput.classList.add('is-invalid');
                        document.getElementById('addNameError').textContent = 'Nama penulis tidak boleh kosong';
                        nameInput.focus();
                        return false;
                    }
                    
                    // Check duplicate in real-time
                    const normalizedName = nameInput.value.toLowerCase().trim().replace(/\s+/g, ' ');
                    const rows = document.querySelectorAll('#authorsTable tbody tr[data-author-id]');
                    
                    for (const row of rows) {
                        const rowName = row.getAttribute('data-author-name').toLowerCase().trim().replace(/\s+/g, ' ');
                        if (rowName === normalizedName) {
                            e.preventDefault();
                            nameInput.classList.add('is-invalid');
                            document.getElementById('addNameError').textContent = 'Nama penulis sudah ada';
                            nameInput.focus();
                            return false;
                        }
                    }
                });
            }
            
            // Edit form validation
            const editForm = document.getElementById('editAuthorModalForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const nameInput = document.getElementById('edit_nama_penulis');
                    if (!nameInput.value.trim()) {
                        e.preventDefault();
                        nameInput.classList.add('is-invalid');
                        document.getElementById('editNameErrorModal').textContent = 'Nama penulis tidak boleh kosong';
                        nameInput.focus();
                        return false;
                    }
                });
            }
            
            // Year validation
            function validateYears(lahirInput, wafatInput) {
                const lahir = parseInt(lahirInput.value) || null;
                const wafat = parseInt(wafatInput.value) || null;
                const currentYear = new Date().getFullYear();
                
                if (lahir && wafat && wafat < lahir) {
                    alert('Tahun wafat tidak boleh sebelum tahun lahir');
                    wafatInput.value = '';
                    wafatInput.focus();
                    return false;
                }
                
                if (lahir && lahir > currentYear) {
                    alert('Tahun lahir tidak boleh lebih besar dari tahun sekarang');
                    lahirInput.value = '';
                    lahirInput.focus();
                    return false;
                }
                
                if (wafat && wafat > currentYear) {
                    alert('Tahun wafat tidak boleh lebih besar dari tahun sekarang');
                    wafatInput.value = '';
                    wafatInput.focus();
                    return false;
                }
                
                return true;
            }
            
            // Add year validation
            const tahunLahirInputs = document.querySelectorAll('input[name="tahun_lahir"]');
            const tahunWafatInputs = document.querySelectorAll('input[name="tahun_wafat"]');
            
            tahunLahirInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    const form = this.closest('form');
                    const tahunWafatInput = form.querySelector('input[name="tahun_wafat"]');
                    validateYears(this, tahunWafatInput);
                });
            });
            
            tahunWafatInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    const form = this.closest('form');
                    const tahunLahirInput = form.querySelector('input[name="tahun_lahir"]');
                    validateYears(tahunLahirInput, this);
                });
            });
            
            // Auto-focus on name input when modals open
            const addModal = document.getElementById('addAuthorModal');
            if (addModal) {
                addModal.addEventListener('shown.bs.modal', function () {
                    document.querySelector('#addAuthorForm input[name="nama_penulis"]').focus();
                });
            }
            
            const editModal = document.getElementById('editAuthorModal');
            if (editModal) {
                editModal.addEventListener('shown.bs.modal', function () {
                    document.getElementById('edit_nama_penulis').focus();
                });
            }
            
            // Clear validation on modal hide
            const modals = [addModal, editModal];
            modals.forEach(modal => {
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', function () {
                        const invalidInputs = this.querySelectorAll('.is-invalid');
                        invalidInputs.forEach(input => {
                            input.classList.remove('is-invalid');
                        });
                    });
                }
            });
            
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
        });
    </script>
</body>
</html>