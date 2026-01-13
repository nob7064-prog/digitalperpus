<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$page_title = 'Kelola User';
$page_icon = 'fas fa-users';

// Get connection and pending counts for sidebar
$conn = getConnection();

// Get pending count for sidebar badge
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;

// Handle user status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $user_id = intval($_POST['user_id']);
    $status = sanitize($_POST['status']);

    $sql = "UPDATE anggota SET status = ? WHERE id_anggota = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $user_id);

    if ($stmt->execute()) {
        $message = "Status user berhasil diperbarui";
        $message_type = "success";
        logActivity($_SESSION['user_id'], 'petugas', "Memperbarui status anggota ID $user_id menjadi $status");
    } else {
        $message = "Gagal memperbarui status user";
        $message_type = "error";
    }
}

// Handle AJAX requests for dynamic updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        $action = $_POST['ajax_action'];

        if ($action === 'update_status') {
            $user_id = intval($_POST['user_id']);
            $status = sanitize($_POST['status']);

            $sql = "UPDATE anggota SET status = ? WHERE id_anggota = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $status, $user_id);

            if ($stmt->execute()) {
                logActivity($_SESSION['user_id'], 'petugas', "Memperbarui status anggota ID $user_id menjadi $status");
                $response = ['success' => true, 'message' => 'Status berhasil diperbarui'];
            } else {
                $response = ['success' => false, 'message' => 'Gagal memperbarui status'];
            }
        }

        echo json_encode($response);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
        exit;
    }
}

// Handle user update (full update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $user_id = intval($_POST['user_id']);
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $status = sanitize($_POST['status']);

    $sql = "UPDATE anggota SET
            username = ?,
            email = ?,
            status = ?
            WHERE id_anggota = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $username, $email, $status, $user_id);

    if ($stmt->execute()) {
        $message = "Data anggota berhasil diperbarui";
        $message_type = "success";
        logActivity($_SESSION['user_id'], 'petugas', "Memperbarui data anggota ID $user_id");
    } else {
        $message = "Gagal memperbarui data anggota";
        $message_type = "error";
    }
}

// Handle fine payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_fine'])) {
    $denda_id = intval($_POST['denda_id']);
    $metode = sanitize($_POST['metode_bayar']);
    $user_id = intval($_POST['user_id'] ?? 0);
    
    // Handle file upload for transfer proof
    $bukti_bayar = null;
    if ($metode === 'transfer' && isset($_FILES['bukti_bayar']) && $_FILES['bukti_bayar']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['bukti_bayar'], 'payment');
        if (isset($upload['filename'])) {
            $bukti_bayar = $upload['filename'];
        }
    }
    
    // Get fine amount first
    $sql_fine = "SELECT jumlah_denda, id_anggota FROM denda WHERE id_denda = ?";
    $stmt_fine = $conn->prepare($sql_fine);
    $stmt_fine->bind_param("i", $denda_id);
    $stmt_fine->execute();
    $fine_result = $stmt_fine->get_result();
    $fine_data = $fine_result->fetch_assoc();
    
    if ($fine_data) {
        $jumlah_denda = $fine_data['jumlah_denda'];
        $id_anggota_fine = $fine_data['id_anggota'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update fine status
            $sql = "UPDATE denda SET 
                    status = 'lunas',
                    metode_bayar = ?,
                    bukti_bayar = ?,
                    tanggal_bayar = NOW()
                    WHERE id_denda = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $metode, $bukti_bayar, $denda_id);
            $stmt->execute();
            
            // Update user's total fine
            $sql_update = "UPDATE anggota 
                          SET total_denda = GREATEST(total_denda - ?, 0)
                          WHERE id_anggota = ?";
            
            $stmt2 = $conn->prepare($sql_update);
            $stmt2->bind_param("di", $jumlah_denda, $id_anggota_fine);
            $stmt2->execute();
            
            // Commit transaction
            $conn->commit();
            
            $message = "Pembayaran denda berhasil";
            $message_type = "success";
            logActivity($_SESSION['user_id'], 'petugas', "Menerima pembayaran denda ID $denda_id sebesar " . formatCurrency($jumlah_denda));
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $message = "Gagal memproses pembayaran: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "Data denda tidak ditemukan";
        $message_type = "error";
    }
}

// Handle user deletion (soft delete - update status to nonaktif)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    
    // Check if user has active borrowings or fines
    $check_sql = "SELECT 
                  (SELECT COUNT(*) FROM peminjaman WHERE id_anggota = ? AND status = 'dipinjam') as aktif_pinjam,
                  (SELECT COUNT(*) FROM denda WHERE id_anggota = ? AND status = 'belum_lunas') as belum_lunas";
    
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $user_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['aktif_pinjam'] > 0) {
        $message = "Tidak dapat menonaktifkan anggota yang masih memiliki peminjaman aktif";
        $message_type = "error";
    } elseif ($check_result['belum_lunas'] > 0) {
        $message = "Tidak dapat menonaktifkan anggota yang masih memiliki denda belum lunas";
        $message_type = "error";
    } else {
        // Update user status to nonaktif instead of deleting
        $sql = "UPDATE anggota SET status = 'nonaktif' WHERE id_anggota = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $message = "Anggota berhasil dinonaktifkan";
            $message_type = "success";
            logActivity($_SESSION['user_id'], 'petugas', "Menonaktifkan anggota ID $user_id");
        } else {
            $message = "Gagal menonaktifkan anggota";
            $message_type = "error";
        }
    }
}

// Handle add new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $nama_lengkap = sanitize($_POST['nama_lengkap'] ?? '');
    $no_telepon = sanitize($_POST['no_telepon'] ?? '');
    $role = sanitize($_POST['role'] ?? 'anggota');
    $status = sanitize($_POST['status'] ?? 'aktif');
    $alamat = sanitize($_POST['alamat'] ?? '');
    
    $sql = "INSERT INTO anggota (username, email, password, nama_lengkap, no_telepon, role, status, alamat, tanggal_daftar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssss", $username, $email, $password, $nama_lengkap, $no_telepon, $role, $status, $alamat);
    
    if ($stmt->execute()) {
        $message = "Anggota baru berhasil ditambahkan";
        $message_type = "success";
        logActivity($_SESSION['user_id'], 'petugas', "Menambahkan anggota baru: $username");
    } else {
        $message = "Gagal menambahkan anggota baru";
        $message_type = "error";
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$has_fine = $_GET['has_fine'] ?? '';

// Build query - PERBAIKAN: Menghapus reference ke a.nama_lengkap karena tidak ada di tabel
$query = "SELECT a.*,
          (SELECT COUNT(*) FROM peminjaman p WHERE p.id_anggota = a.id_anggota) as total_pinjam,
          (SELECT COUNT(*) FROM peminjaman p WHERE p.id_anggota = a.id_anggota AND p.status = 'dipinjam') as sedang_dipinjam,
          (SELECT COUNT(*) FROM peminjaman p WHERE p.id_anggota = a.id_anggota AND p.status = 'terlambat') as terlambat,
          (SELECT COUNT(*) FROM denda d WHERE d.id_anggota = a.id_anggota AND d.status = 'belum_lunas') as jumlah_denda,
          (SELECT MAX(tanggal_pinjam) FROM peminjaman WHERE id_anggota = a.id_anggota) as last_borrowed
          FROM anggota a WHERE 1=1";

$params = [];
$types = "";

if ($search) {
    // PERBAIKAN: Menghapus reference ke a.nama_lengkap
    $query .= " AND (a.username LIKE ? OR a.email LIKE ? OR a.id_anggota = ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search;
    $types .= "sss";
}

if ($status_filter) {
    $query .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($date_from) {
    $query .= " AND DATE(a.tanggal_daftar) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $query .= " AND DATE(a.tanggal_daftar) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($has_fine === 'yes') {
    $query .= " AND a.total_denda > 0";
} elseif ($has_fine === 'no') {
    $query .= " AND a.total_denda = 0";
}

$query .= " ORDER BY a.tanggal_daftar DESC";

// Get users
$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users_result = $stmt->get_result();
$users = $users_result->fetch_all(MYSQLI_ASSOC);
$total_users = count($users);

// Get statistics for charts
$stats_query = "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif,
                SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) as nonaktif,
                SUM(CASE WHEN status = 'terblokir' THEN 1 ELSE 0 END) as terblokir,
                SUM(total_denda) as total_denda_all,
                AVG((SELECT COUNT(*) FROM peminjaman WHERE id_anggota = anggota.id_anggota)) as avg_pinjam
                FROM anggota";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get petugas count from petugas table
$petugas_query = "SELECT COUNT(*) as jumlah_petugas FROM petugas";
$petugas_result = $conn->query($petugas_query);
$petugas_data = $petugas_result->fetch_assoc();
$stats['jumlah_petugas'] = $petugas_data['jumlah_petugas'];

// Users with fines
$users_with_fines = $conn->query("
    SELECT a.id_anggota, a.username, a.email, 
           SUM(d.jumlah_denda) as total_denda_pending,
           COUNT(d.id_denda) as jumlah_denda_pending
    FROM anggota a
    JOIN denda d ON a.id_anggota = d.id_anggota 
    WHERE d.status = 'belum_lunas'
    GROUP BY a.id_anggota, a.username, a.email
    ORDER BY total_denda_pending DESC
")->fetch_all(MYSQLI_ASSOC);

// Get all fines for users
$all_fines = [];
$fines_result = $conn->query("
    SELECT d.*, a.username, a.email
    FROM denda d
    JOIN anggota a ON d.id_anggota = a.id_anggota
    WHERE d.status = 'belum_lunas'
    ORDER BY d.created_at DESC
");

while ($fine = $fines_result->fetch_assoc()) {
    $all_fines[$fine['id_anggota']][] = $fine;
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

        /* Stat Cards */
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
        
        .stat-card-info {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }
        
        .stat-card-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
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

        /* User Table Styles */
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            font-weight: 600;
            color: #4b5563;
            background-color: #f8fafc;
            border-bottom: 2px solid #e5e7eb;
            padding: 1rem;
        }

        .table td {
            vertical-align: middle;
            border-color: #e5e7eb;
            padding: 1rem;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* User Avatar */
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

        /* Badges */
        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .bg-aktif {
            background-color: rgba(39, 174, 96, 0.1);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .bg-nonaktif {
            background-color: rgba(243, 156, 18, 0.1);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.3);
        }

        .bg-terblokir {
            background-color: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        /* Empty State */
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

        /* Progress Bars */
        .progress-stacked {
            border-radius: 6px;
            overflow: hidden;
        }

        /* Summary Items */
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

        /* Button Group */
        .btn-group .btn {
            border-radius: 6px !important;
        }

        .btn-group .btn:not(:last-child) {
            margin-right: 4px;
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

        /* Responsive */
        @media (max-width: 768px) {
            .stat-card h2 {
                font-size: 1.5rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                margin-bottom: 5px;
                width: 100%;
            }
            
            .table-responsive {
                font-size: 0.9rem;
            }
            
            .table th, .table td {
                padding: 0.75rem 0.5rem;
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
                                <a class="dropdown-item active" href="kelola_user.php">
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
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <div class="container-fluid py-4">
                    <!-- Messages -->
                    <?php if (isset($message) && !empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
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
                                        <h5 class="mb-2">Total Anggota</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['total'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-users fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Anggota Aktif</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['aktif'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-user-check fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-info">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Anggota Nonaktif</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['nonaktif'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-user-shield fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-danger">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Anggota Diblokir</h5>
                                        <h2 class="mb-0"><?php echo number_format($stats['terblokir'] ?? 0); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-money-bill-wave fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Filter Data
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3" id="filterForm">
                                <div class="col-md-3">
                                    <label class="form-label">Pencarian</label>
                                    <input type="text" class="form-control" name="search" 
                                           value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Username, Email, atau ID">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">Semua Status</option>
                                        <option value="aktif" <?php echo $status_filter === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                        <option value="nonaktif" <?php echo $status_filter === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                                        <option value="terblokir" <?php echo $status_filter === 'terblokir' ? 'selected' : ''; ?>>Terblokir</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tanggal Mulai</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tanggal Akhir</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Denda</label>
                                    <select class="form-select" name="has_fine">
                                        <option value="">Semua</option>
                                        <option value="yes" <?php echo $has_fine === 'yes' ? 'selected' : ''; ?>>Ada</option>
                                        <option value="no" <?php echo $has_fine === 'no' ? 'selected' : ''; ?>>Tidak</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-filter me-2"></i> Terapkan Filter
                                            </button>
                                            <a href="kelola_user.php" class="btn btn-outline-secondary">
                                                <i class="fas fa-redo me-2"></i> Reset
                                            </a>
                                        </div>
                                        <div class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <?php echo number_format($total_users); ?> data ditemukan
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Daftar Pengguna
                                <span class="badge bg-primary ms-2"><?php echo $total_users; ?></span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive" id="userTable">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Pengguna</th>
                                            <th>Kontak</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Tanggal Daftar</th>
                                            <th>Statistik</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($total_users > 0): 
                                            foreach ($users as $index => $user):
                                                $fines = $all_fines[$user['id_anggota']] ?? [];
                                                $user_role = isset($user['role']) ? $user['role'] : 'anggota';
                                        ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="user-avatar me-3">
                                                        <img src="../uploads/profiles/<?php echo !empty($user['foto_profil']) ? $user['foto_profil'] : 'default.png'; ?>" 
                                                             alt="<?php echo htmlspecialchars($user['username']); ?>" 
                                                             class="avatar-img">
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($user['username']); ?></div>
                                                        <small class="text-muted">
                                                            ID: ANG-<?php echo str_pad($user['id_anggota'], 5, '0', STR_PAD_LEFT); ?>
                                                        </small>
                                                        <?php if ($user['last_borrowed']): ?>
                                                        <br><small class="text-muted">Terakhir pinjam: <?php echo formatDate($user['last_borrowed'], 'd/m/Y'); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($user['email']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($user['no_telepon'] ?? '-'); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $user_role === 'petugas' ? 'bg-info' : 'bg-secondary'; ?>">
                                                    <?php echo $user_role === 'petugas' ? 'Petugas' : 'Anggota'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge-status bg-<?php echo $user['status']; ?>"
                                                      data-user-id="<?php echo $user['id_anggota']; ?>"
                                                      data-status="<?php echo $user['status']; ?>"
                                                      style="cursor: pointer;"
                                                      onclick="updateUserStatus(<?php echo $user['id_anggota']; ?>, '<?php echo $user['status']; ?>')">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatDate($user['tanggal_daftar'], 'd/m/Y'); ?></td>
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
                                                    <?php if ($user['total_denda'] > 0): ?>
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-danger">Denda:</span>
                                                        <span><?php echo formatCurrency($user['total_denda']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                            data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id_anggota']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($user['total_denda'] > 0 && !empty($fines)): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                                            data-bs-toggle="modal" data-bs-target="#payFineModal<?php echo $user['id_anggota']; ?>">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                                            data-bs-toggle="modal" data-bs-target="#deleteUserModal<?php echo $user['id_anggota']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <div class="empty-state">
                                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                                    <h5>Tidak ada data anggota</h5>
                                                    <p class="text-muted">Belum ada anggota yang terdaftar</p>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                                        <i class="fas fa-user-plus me-2"></i>Tambah Anggota
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="text-muted">
                                        Menampilkan <?php echo min($total_users, 10); ?> dari <?php echo $total_users; ?> entri
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination justify-content-end mb-0">
                                            <li class="page-item <?php echo ($total_users <= 10) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="#" tabindex="-1">Sebelumnya</a>
                                            </li>
                                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                            <?php if ($total_users > 20): ?><li class="page-item"><a class="page-link" href="#">2</a></li><?php endif; ?>
                                            <?php if ($total_users > 30): ?><li class="page-item"><a class="page-link" href="#">3</a></li><?php endif; ?>
                                            <li class="page-item <?php echo ($total_users <= 10) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="#">Selanjutnya</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
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
                                    <div class="progress-stacked mb-3" style="height: 30px;">
                                        <div class="progress" style="width: <?php echo (($stats['aktif'] ?? 0) / max(($stats['total'] ?? 1), 1)) * 100; ?>%">
                                            <div class="progress-bar bg-success" role="progressbar">
                                                Aktif (<?php echo $stats['aktif'] ?? 0; ?>)
                                            </div>
                                        </div>
                                        <div class="progress" style="width: <?php echo (($stats['nonaktif'] ?? 0) / max(($stats['total'] ?? 1), 1)) * 100; ?>%">
                                            <div class="progress-bar bg-warning" role="progressbar">
                                                Nonaktif (<?php echo $stats['nonaktif'] ?? 0; ?>)
                                            </div>
                                        </div>
                                        <div class="progress" style="width: <?php echo (($stats['terblokir'] ?? 0) / max(($stats['total'] ?? 1), 1)) * 100; ?>%">
                                            <div class="progress-bar bg-danger" role="progressbar">
                                                Terblokir (<?php echo $stats['terblokir'] ?? 0; ?>)
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h6 class="mt-4">Distribusi Role</h6>
                                    <div class="progress-stacked mb-3" style="height: 30px;">
                                        <div class="progress" style="width: <?php echo ((($stats['total'] ?? 0) - ($stats['jumlah_petugas'] ?? 0)) / max(($stats['total'] ?? 1), 1)) * 100; ?>%">
                                            <div class="progress-bar bg-secondary" role="progressbar">
                                                Anggota (<?php echo ($stats['total'] ?? 0) - ($stats['jumlah_petugas'] ?? 0); ?>)
                                            </div>
                                        </div>
                                        <div class="progress" style="width: <?php echo (($stats['jumlah_petugas'] ?? 0) / max(($stats['total'] ?? 1), 1)) * 100; ?>%">
                                            <div class="progress-bar bg-info" role="progressbar">
                                                Petugas (<?php echo $stats['jumlah_petugas'] ?? 0; ?>)
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h6>Ringkasan Laporan</h6>
                                    <div class="summary-list">
                                        <div class="summary-item">
                                            <span class="summary-label">Periode:</span>
                                            <span class="summary-value">
                                                <?php echo $date_from ? formatDate($date_from) : 'Semua' ?> 
                                                - <?php echo $date_to ? formatDate($date_to) : 'Semua' ?>
                                            </span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Status Filter:</span>
                                            <span class="summary-value"><?php echo $status_filter ? ucfirst($status_filter) : 'Semua'; ?></span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Tanggal Laporan:</span>
                                            <span class="summary-value"><?php echo date('d/m/Y H:i'); ?></span>
                                        </div>
                                        <div class="summary-item">
                                            <span class="summary-label">Jumlah Data:</span>
                                            <span class="summary-value"><?php echo $total_users; ?> dari <?php echo $stats['total'] ?? 0; ?> pengguna</span>
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

    <!-- Modals -->
    <?php foreach ($users as $user): 
        $fines = $all_fines[$user['id_anggota']] ?? [];
        $user_role = isset($user['role']) ? $user['role'] : 'anggota';
    ?>
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal<?php echo $user['id_anggota']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Data Anggota</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" value="<?php echo $user['id_anggota']; ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" name="username" 
                                       value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="anggota" <?php echo $user_role === 'anggota' ? 'selected' : ''; ?>>Anggota</option>
                                    <option value="petugas" <?php echo $user_role === 'petugas' ? 'selected' : ''; ?>>Petugas</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status *</label>
                                <select class="form-select" name="status" required>
                                    <option value="aktif" <?php echo $user['status'] === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                    <option value="nonaktif" <?php echo $user['status'] === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                                    <option value="terblokir" <?php echo $user['status'] === 'terblokir' ? 'selected' : ''; ?>>Terblokir</option>
                                </select>
                            </div>
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="update_user" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pay Fine Modal -->
    <?php if (!empty($fines)): ?>
    <div class="modal fade" id="payFineModal<?php echo $user['id_anggota']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bayar Denda</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" value="<?php echo $user['id_anggota']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Pilih Denda</label>
                            <select class="form-select" name="denda_id" required>
                                <option value="">Pilih denda...</option>
                                <?php foreach ($fines as $fine): ?>
                                    <?php if ($fine['status'] === 'belum_lunas'): ?>
                                    <option value="<?php echo $fine['id_denda']; ?>">
                                        <?php echo htmlspecialchars($fine['keterangan']); ?> - <?php echo formatCurrency($fine['jumlah_denda']); ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Metode Bayar</label>
                            <select class="form-select" name="metode_bayar" id="metodeBayar<?php echo $user['id_anggota']; ?>" required>
                                <option value="tunai">Tunai</option>
                                <option value="transfer">Transfer</option>
                            </select>
                        </div>
                        <div class="mb-3" id="buktiTransfer<?php echo $user['id_anggota']; ?>" style="display: none;">
                            <label class="form-label">Bukti Transfer</label>
                            <input type="file" class="form-control" name="bukti_bayar" accept="image/*">
                            <small class="text-muted">Upload bukti transfer (JPG, PNG, max 5MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="pay_fine" class="btn btn-primary">Konfirmasi Pembayaran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal<?php echo $user['id_anggota']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Konfirmasi Penonaktifan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="user_id" value="<?php echo $user['id_anggota']; ?>">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Peringatan!</strong> Tindakan ini akan menonaktifkan akun anggota.
                        </div>
                        <p>Apakah Anda yakin ingin menonaktifkan akun <strong><?php echo htmlspecialchars($user['username']); ?></strong>?</p>
                        <p class="text-muted">Data peminjaman dan transaksi tetap tersimpan, tetapi anggota tidak dapat login.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete_user" class="btn btn-danger">Ya, Nonaktifkan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Anggota Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="addUserForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" class="form-control" name="password" required minlength="6" id="passwordField">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Konfirmasi Password *</label>
                                <input type="password" class="form-control" name="confirm_password" required minlength="6" id="confirmPasswordField">
                                <div class="invalid-feedback" id="passwordError">Password tidak cocok</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="anggota">Anggota</option>
                                    <option value="petugas">Petugas</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="aktif">Aktif</option>
                                    <option value="nonaktif">Nonaktif</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Simpan</button>
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

        // Show/hide transfer proof field
        document.querySelectorAll('[id^="metodeBayar"]').forEach(select => {
            select.addEventListener('change', function() {
                const userId = this.id.replace('metodeBayar', '');
                const proofField = document.getElementById('buktiTransfer' + userId);
                if (this.value === 'transfer') {
                    proofField.style.display = 'block';
                } else {
                    proofField.style.display = 'none';
                }
            });
        });

        // Logout confirmation function
        function confirmLogout() {
            const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
            modal.show();
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

        // Update user status via AJAX
        function updateUserStatus(userId, currentStatus) {
            const newStatus = prompt('Masukkan status baru (aktif/nonaktif/terblokir):', currentStatus);
            
            if (newStatus && ['aktif', 'nonaktif', 'terblokir'].includes(newStatus.toLowerCase())) {
                const formData = new FormData();
                formData.append('ajax_action', 'update_status');
                formData.append('user_id', userId);
                formData.append('status', newStatus.toLowerCase());

                showLoading('Memperbarui status...');
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        // Reload page to show updated status
                        location.reload();
                    } else {
                        showError(data.message || 'Gagal memperbarui status');
                    }
                })
                .catch(error => {
                    Swal.close();
                    showError('Terjadi kesalahan: ' + error.message);
                });
            } else if (newStatus) {
                showError('Status tidak valid. Harus: aktif, nonaktif, atau terblokir');
            }
        }

        // Export to Excel
        function exportToExcel() {
            showLoading('Mempersiapkan laporan Excel...');
            
            // Create CSV content
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Laporan Anggota - Digital Library\n";
            csvContent += "Tanggal: " + new Date().toLocaleDateString() + "\n\n";
            
            // Headers
            csvContent += "No,Username,Nama Lengkap,Email,No Telepon,Role,Status,Total Pinjam,Dipinjam,Terlambat,Total Denda,Tanggal Daftar\n";

            // Data rows
            <?php foreach ($users as $index => $user): 
                $user_role = isset($user['role']) ? $user['role'] : 'anggota';
            ?>
            csvContent += "<?php echo ($index + 1) . ',' .
                addslashes($user['username']) . ',' .
                addslashes($user['nama_lengkap'] ?? '') . ',' .
                addslashes($user['email']) . ',' .
                addslashes($user['no_telepon'] ?? '') . ',' .
                $user_role . ',' .
                $user['status'] . ',' .
                $user['total_pinjam'] . ',' .
                $user['sedang_dipinjam'] . ',' .
                $user['terlambat'] . ',' .
                ($user['total_denda'] ?? 0) . ',' .
                $user['tanggal_daftar']; ?>\n";
            <?php endforeach; ?>
            
            // Summary
            csvContent += "\n\nSUMMARY\n";
            csvContent += "Total Anggota,<?php echo $stats['total'] ?? 0; ?>\n";
            csvContent += "Aktif,<?php echo $stats['aktif'] ?? 0; ?>\n";
            csvContent += "Nonaktif,<?php echo $stats['nonaktif'] ?? 0; ?>\n";
            csvContent += "Terblokir,<?php echo $stats['terblokir'] ?? 0; ?>\n";
            csvContent += "Petugas,<?php echo $stats['jumlah_petugas'] ?? 0; ?>\n";
            csvContent += "Rata-rata Pinjam,<?php echo isset($stats['avg_pinjam']) ? number_format($stats['avg_pinjam'], 1) : '0.0'; ?>\n";
            csvContent += "Total Denda,<?php echo formatCurrency($stats['total_denda_all'] ?? 0); ?>\n";
            
            setTimeout(() => {
                Swal.close();
                
                // Create download link
                const encodedUri = encodeURI(csvContent);
                const link = document.createElement("a");
                link.setAttribute("href", encodedUri);
                link.setAttribute("download", "laporan_anggota_<?php echo date('Y-m-d'); ?>.csv");
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                showSuccess('Laporan berhasil diexport');
            }, 1500);
        }

        // Print user table
        function printUserTable() {
            const printContent = document.getElementById('userTable').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <html>
                    <head>
                        <title>Laporan Anggota - Digital Library</title>
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
                            .bg-warning { background: #fef3c7; color: #92400e; }
                            .bg-danger { background: #fee2e2; color: #991b1b; }
                            .bg-info { background: #dbeafe; color: #1e40af; }
                            .bg-secondary { background: #e5e7eb; color: #374151; }
                        </style>
                    </head>
                    <body>
                        <div class="print-header">
                            <h2>Laporan Anggota - Digital Library</h2>
                            <p>Sistem Manajemen Perpustakaan Digital</p>
                        </div>
                        
                        <div class="print-date">
                            <p>Tanggal Cetak: <?php echo date('d/m/Y H:i'); ?></p>
                            <p>Total Data: <?php echo $total_users; ?> anggota</p>
                        </div>
                        
                        ${printContent}
                        
                        <div class="summary">
                            <h4>Ringkasan:</h4>
                            <p>Total Anggota: <?php echo number_format($stats['total'] ?? 0); ?></p>
                            <p>Aktif: <?php echo number_format($stats['aktif'] ?? 0); ?> (<?php echo isset($stats['total']) && $stats['total'] > 0 ? round(($stats['aktif'] / $stats['total']) * 100, 1) : 0; ?>%)</p>
                            <p>Nonaktif: <?php echo number_format($stats['nonaktif'] ?? 0); ?> (<?php echo isset($stats['total']) && $stats['total'] > 0 ? round(($stats['nonaktif'] / $stats['total']) * 100, 1) : 0; ?>%)</p>
                            <p>Terblokir: <?php echo number_format($stats['terblokir'] ?? 0); ?> (<?php echo isset($stats['total']) && $stats['total'] > 0 ? round(($stats['terblokir'] / $stats['total']) * 100, 1) : 0; ?>%)</p>
                            <p>Petugas: <?php echo number_format($stats['jumlah_petugas'] ?? 0); ?></p>
                            <p>Rata-rata Pinjam: <?php echo isset($stats['avg_pinjam']) ? number_format($stats['avg_pinjam'], 1) : '0.0'; ?></p>
                            <?php if (isset($stats['total_denda_all']) && $stats['total_denda_all']): ?>
                            <p>Total Denda: <?php echo formatCurrency($stats['total_denda_all']); ?></p>
                            <?php endif; ?>
                        </div>
                    </body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContent;
            location.reload();
        }

        // Add loading states to forms
        document.addEventListener('DOMContentLoaded', function() {
            // Password validation for add user form
            const passwordField = document.getElementById('passwordField');
            const confirmPasswordField = document.getElementById('confirmPasswordField');
            const passwordError = document.getElementById('passwordError');
            
            if (passwordField && confirmPasswordField) {
                function validatePasswords() {
                    if (passwordField.value !== confirmPasswordField.value) {
                        confirmPasswordField.classList.add('is-invalid');
                        passwordError.style.display = 'block';
                        return false;
                    } else {
                        confirmPasswordField.classList.remove('is-invalid');
                        passwordError.style.display = 'none';
                        return true;
                    }
                }
                
                confirmPasswordField.addEventListener('input', validatePasswords);
                passwordField.addEventListener('input', validatePasswords);
                
                // Add form validation for add user form
                document.getElementById('addUserForm').addEventListener('submit', function(e) {
                    if (!validatePasswords()) {
                        e.preventDefault();
                        showError('Password dan konfirmasi password tidak cocok');
                    }
                });
            }
            
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
            
            // Prevent form submission from reloading with parameters that cause auto-refresh
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    // Submit normally - no auto-refresh meta tag will be added
                });
            }
            
        });
        
        // Prevent form buttons from causing page reload with refresh parameters
        document.querySelectorAll('form button[type="submit"]').forEach(button => {
            button.addEventListener('click', function() {
                // Remove any existing meta refresh tags
                const metaRefresh = document.querySelector('meta[http-equiv="refresh"]');
                if (metaRefresh) {
                    metaRefresh.remove();
                }
            });
        });
    </script>
</body>
</html>