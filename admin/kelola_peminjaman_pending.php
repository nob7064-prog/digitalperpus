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
$page_title = 'Kelola Request';
$page_icon = 'fas fa-clock';

// Initialize variables
$pending_loans = [];
$pending_returns = [];
$error_message = '';
$success_message = '';
$total_pending_loans = 0;
$total_pending_returns = 0;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'approve_loan':
            $id_peminjaman = intval($_POST['id_peminjaman'] ?? 0);
            if ($id_peminjaman > 0) {
                // Process approval
                $result = approveLoanRequest($id_peminjaman, $_SESSION['user_id']);
                if ($result['success']) {
                    $success_message = $result['message'];
                    
                    // Update notification count by decreasing pending loans
                    updateNotificationCount('peminjaman_pending', -1);
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
            
        case 'reject_loan':
            $id_peminjaman = intval($_POST['id_peminjaman'] ?? 0);
            if ($id_peminjaman > 0) {
                // Process rejection
                $result = rejectLoanRequest($id_peminjaman, $_SESSION['user_id']);
                if ($result['success']) {
                    $success_message = $result['message'];
                    
                    // Update notification count by decreasing pending loans
                    updateNotificationCount('peminjaman_pending', -1);
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
            
        case 'approve_return':
            $id_pengembalian = intval($_POST['id_pengembalian'] ?? 0);
            if ($id_pengembalian > 0) {
                // Process return approval
                $result = approveReturnRequest($id_pengembalian, $_SESSION['user_id']);
                if ($result['success']) {
                    $success_message = $result['message'];
                    
                    // Update notification count by decreasing pending returns
                    updateNotificationCount('pengembalian_pending', -1);
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
            
        case 'reject_return':
            $id_pengembalian = intval($_POST['id_pengembalian'] ?? 0);
            if ($id_pengembalian > 0) {
                // Process return rejection
                $result = rejectReturnRequest($id_pengembalian, $_SESSION['user_id']);
                if ($result['success']) {
                    $success_message = $result['message'];
                    
                    // Update notification count by decreasing pending returns
                    updateNotificationCount('pengembalian_pending', -1);
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
    }
}

// Function to update notification count in database
function updateNotificationCount($type, $change) {
    $conn = getConnection();
    if (!$conn) return false;
    
    try {
        // Check if notification table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'notifikasi_admin'");
        if ($table_check->num_rows === 0) {
            $conn->close();
            return false;
        }
        
        // Get current notification for this type
        $sql = "SELECT * FROM notifikasi_admin WHERE tipe = ? ORDER BY id_notifikasi DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $type);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $notif = $result->fetch_assoc();
            $current_count = 0;
            
            // Extract count from message
            preg_match('/\d+/', $notif['pesan'], $matches);
            if (!empty($matches)) {
                $current_count = intval($matches[0]);
            }
            
            $new_count = max(0, $current_count + $change);
            
            if ($new_count > 0) {
                // Update existing notification
                $updateSql = "UPDATE notifikasi_admin 
                             SET pesan = ?, updated_at = NOW(), dibaca = FALSE
                             WHERE id_notifikasi = ?";
                $updateStmt = $conn->prepare($updateSql);
                
                $messages = [
                    'peminjaman_pending' => "Ada $new_count pengajuan peminjaman yang menunggu approval",
                    'pengembalian_pending' => "Ada $new_count pengajuan pengembalian yang menunggu approval",
                    'denda_pending' => "Ada $new_count pengajuan pembayaran denda yang menunggu approval"
                ];
                
                $new_message = $messages[$type] ?? "Ada $new_count notifikasi";
                $updateStmt->bind_param("si", $new_message, $notif['id_notifikasi']);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Mark notification as read
                $readSql = "UPDATE notifikasi_admin SET dibaca = TRUE WHERE id_notifikasi = ?";
                $readStmt = $conn->prepare($readSql);
                $readStmt->bind_param("i", $notif['id_notifikasi']);
                $readStmt->execute();
                $readStmt->close();
            }
        }
        
        $stmt->close();
        $conn->close();
        return true;
        
    } catch (Exception $e) {
        $conn->close();
        return false;
    }
}

// Function to approve loan request
function approveLoanRequest($id_peminjaman, $id_admin) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Get loan details
        $sql = "SELECT p.*, b.judul_buku, b.stok, b.id_buku, a.id_anggota, a.username, a.email 
                FROM peminjaman p
                LEFT JOIN buku b ON p.id_buku = b.id_buku
                LEFT JOIN anggota a ON p.id_anggota = a.id_anggota
                WHERE p.id_peminjaman = ? AND p.status = 'pending'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_peminjaman);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Data peminjaman tidak ditemukan atau sudah diproses");
        }
        
        $loan = $result->fetch_assoc();
        $id_buku = $loan['id_buku'];
        $id_anggota = $loan['id_anggota'];
        
        // Check if book is available
        if ($loan['stok'] <= 0) {
            throw new Exception("Stok buku tidak mencukupi");
        }
        
        // Update loan status to 'dipinjam'
        $updateSql = "UPDATE peminjaman 
                     SET status = 'dipinjam', 
                         tanggal_pinjam = CURDATE(),
                         tanggal_jatuh_tempo = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     WHERE id_peminjaman = ?";
        
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $id_peminjaman);
        $updateStmt->execute();
        
        // Decrease book stock
        $stockSql = "UPDATE buku SET stok = stok - 1 WHERE id_buku = ?";
        $stockStmt = $conn->prepare($stockSql);
        $stockStmt->bind_param("i", $id_buku);
        $stockStmt->execute();
        
        // Create notification for member
        $notifSql = "INSERT INTO notifikasi_anggota (id_anggota, judul, pesan, link, tipe) 
                     VALUES (?, 'Peminjaman Disetujui', 'Peminjaman buku " . $loan['judul_buku'] . " telah disetujui', 'riwayat_peminjaman.php', 'success')";
        $notifStmt = $conn->prepare($notifSql);
        $notifStmt->bind_param("i", $id_anggota);
        $notifStmt->execute();
        $notifStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Peminjaman berhasil disetujui';
        
        // Close statements
        $stmt->close();
        $updateStmt->close();
        $stockStmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal menyetujui peminjaman: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Function to reject loan request
function rejectLoanRequest($id_peminjaman, $id_admin) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Get loan details
        $sql = "SELECT p.*, b.judul_buku, a.id_anggota 
                FROM peminjaman p
                LEFT JOIN buku b ON p.id_buku = b.id_buku
                LEFT JOIN anggota a ON p.id_anggota = a.id_anggota
                WHERE p.id_peminjaman = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_peminjaman);
        $stmt->execute();
        $result = $stmt->get_result();
        $loan = $result->fetch_assoc();
        $id_anggota = $loan['id_anggota'];
        
        // Update loan status to 'rejected'
        $updateSql = "UPDATE peminjaman 
                     SET status = 'rejected'
                     WHERE id_peminjaman = ?";
        
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $id_peminjaman);
        $updateStmt->execute();
        
        // Create notification for member
        $notifSql = "INSERT INTO notifikasi_anggota (id_anggota, judul, pesan, link, tipe) 
                     VALUES (?, 'Peminjaman Ditolak', 'Peminjaman buku " . $loan['judul_buku'] . " ditolak.', 'riwayat_peminjaman.php', 'danger')";
        $notifStmt = $conn->prepare($notifSql);
        $notifStmt->bind_param("i", $id_anggota);
        $notifStmt->execute();
        $notifStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Peminjaman berhasil ditolak';
        
        // Close statements
        $stmt->close();
        $updateStmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal menolak peminjaman: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Function to approve return request
function approveReturnRequest($id_pengembalian, $id_admin) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Get return details
        $sql = "SELECT pg.*, p.id_buku, p.id_peminjaman, p.id_anggota, b.judul_buku 
                FROM pengembalian pg
                LEFT JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                LEFT JOIN buku b ON p.id_buku = b.id_buku
                WHERE pg.id_pengembalian = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_pengembalian);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Data pengembalian tidak ditemukan");
        }
        
        $return = $result->fetch_assoc();
        $id_buku = $return['id_buku'];
        $id_peminjaman = $return['id_peminjaman'];
        $id_anggota = $return['id_anggota'];
        
        // Update return status
        $updateReturnSql = "UPDATE pengembalian SET status_denda = 'lunas' WHERE id_pengembalian = ?";
        $updateReturnStmt = $conn->prepare($updateReturnSql);
        $updateReturnStmt->bind_param("i", $id_pengembalian);
        $updateReturnStmt->execute();
        
        // Update loan status to 'dikembalikan'
        $updateLoanSql = "UPDATE peminjaman SET status = 'dikembalikan' WHERE id_peminjaman = ?";
        $updateLoanStmt = $conn->prepare($updateLoanSql);
        $updateLoanStmt->bind_param("i", $id_peminjaman);
        $updateLoanStmt->execute();
        
        // Increase book stock
        $stockSql = "UPDATE buku SET stok = stok + 1 WHERE id_buku = ?";
        $stockStmt = $conn->prepare($stockSql);
        $stockStmt->bind_param("i", $id_buku);
        $stockStmt->execute();
        
        // Create notification for member
        $notifSql = "INSERT INTO notifikasi_anggota (id_anggota, judul, pesan, link, tipe) 
                     VALUES (?, 'Pengembalian Disetujui', 'Pengembalian buku " . $return['judul_buku'] . " telah dikonfirmasi', 'riwayat_peminjaman.php', 'success')";
        $notifStmt = $conn->prepare($notifSql);
        $notifStmt->bind_param("i", $id_anggota);
        $notifStmt->execute();
        $notifStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Pengembalian berhasil dikonfirmasi';
        
        // Close statements
        $stmt->close();
        $updateReturnStmt->close();
        $updateLoanStmt->close();
        $stockStmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal mengkonfirmasi pengembalian: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Function to reject return request
function rejectReturnRequest($id_pengembalian, $id_admin) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Get return details
        $sql = "SELECT pg.*, p.id_anggota, b.judul_buku 
                FROM pengembalian pg
                LEFT JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                LEFT JOIN buku b ON p.id_buku = b.id_buku
                WHERE pg.id_pengembalian = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_pengembalian);
        $stmt->execute();
        $result = $stmt->get_result();
        $return = $result->fetch_assoc();
        $id_anggota = $return['id_anggota'];
        
        // Delete the return request
        $deleteSql = "DELETE FROM pengembalian WHERE id_pengembalian = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $id_pengembalian);
        $deleteStmt->execute();
        
        // Create notification for member
        $notifSql = "INSERT INTO notifikasi_anggota (id_anggota, judul, pesan, link, tipe) 
                     VALUES (?, 'Pengembalian Ditolak', 'Pengembalian buku " . $return['judul_buku'] . " ditolak.', 'riwayat_peminjaman.php', 'danger')";
        $notifStmt = $conn->prepare($notifSql);
        $notifStmt->bind_param("i", $id_anggota);
        $notifStmt->execute();
        $notifStmt->close();
        
        $response['success'] = true;
        $response['message'] = 'Pengembalian berhasil ditolak';
        
        // Close statements
        $stmt->close();
        $deleteStmt->close();
        
    } catch (Exception $e) {
        $response['message'] = 'Gagal menolak pengembalian: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Get pending loans
try {
    $conn = getConnection();
    
    if (!$conn) {
        throw new Exception("Koneksi database gagal: Tidak dapat membuat koneksi");
    }
    
    if ($conn->connect_error) {
        throw new Exception("Koneksi database gagal: " . $conn->connect_error);
    }

    // 1. Get pending loans (status = 'pending')
    $sql_loans = "SELECT p.*, b.judul_buku, b.cover_buku, b.stok, a.username, a.email,
                  k.nama_kategori,
                  DATE_FORMAT(p.created_at, '%d/%m/%Y %H:%i') as tanggal_request_formatted
                  FROM peminjaman p
                  LEFT JOIN buku b ON p.id_buku = b.id_buku
                  LEFT JOIN anggota a ON p.id_anggota = a.id_anggota
                  LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
                  WHERE p.status = 'pending'
                  ORDER BY p.created_at ASC";
    
    $result_loans = $conn->query($sql_loans);
    
    if ($result_loans === false) {
        throw new Exception("Error query peminjaman: " . $conn->error);
    }
    
    if ($result_loans->num_rows > 0) {
        while ($loan = $result_loans->fetch_assoc()) {
            $pending_loans[] = $loan;
        }
    }
    
    // 2. Get pending returns (status_denda = 'belum_lunas')
    $sql_returns = "SELECT pg.*, p.tanggal_pinjam, p.tanggal_jatuh_tempo, p.status as status_peminjaman,
                    b.judul_buku, b.cover_buku, a.username, a.email,
                    DATE_FORMAT(pg.tanggal_kembali, '%d/%m/%Y') as tanggal_kembali_formatted,
                    DATEDIFF(CURDATE(), p.tanggal_jatuh_tempo) as terlambat_hari
                    FROM pengembalian pg
                    LEFT JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                    LEFT JOIN buku b ON pg.id_buku = b.id_buku
                    LEFT JOIN anggota a ON pg.id_anggota = a.id_anggota
                    WHERE pg.status_denda = 'belum_lunas'
                    ORDER BY pg.tanggal_kembali ASC";
    
    $result_returns = $conn->query($sql_returns);
    
    if ($result_returns === false) {
        throw new Exception("Error query pengembalian: " . $conn->error);
    }
    
    if ($result_returns->num_rows > 0) {
        while ($return = $result_returns->fetch_assoc()) {
            $pending_returns[] = $return;
        }
    }
    
    // Update notification counts in database
    updateAdminNotifications();
    
    $conn->close();
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    $pending_loans = [];
    $pending_returns = [];
}

// Function to update admin notifications based on current counts
function updateAdminNotifications() {
    $conn = getConnection();
    if (!$conn) return;
    
    try {
        // Check if notification table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'notifikasi_admin'");
        if ($table_check->num_rows === 0) {
            // Create notification table if it doesn't exist
            $createTableSql = "CREATE TABLE notifikasi_admin (
                id_notifikasi INT PRIMARY KEY AUTO_INCREMENT,
                judul VARCHAR(255) NOT NULL,
                pesan TEXT NOT NULL,
                link VARCHAR(255),
                tipe ENUM('peminjaman_pending', 'pengembalian_pending', 'denda_pending') NOT NULL,
                dibaca BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $conn->query($createTableSql);
        }
        
        // Get current counts
        $pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
        $pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
        $pending_fines_count = $conn->query("SELECT COUNT(*) as total FROM denda WHERE status = 'menunggu_approval'")->fetch_assoc()['total'];
        
        // Update or insert notifications
        $types = [
            'peminjaman_pending' => [
                'judul' => 'Peminjaman Pending',
                'count' => $pending_loans_count,
                'link' => 'kelola_peminjaman_pending.php'
            ],
            'pengembalian_pending' => [
                'judul' => 'Pengembalian Pending',
                'count' => $pending_returns_count,
                'link' => 'kelola_peminjaman_pending.php#returns'
            ],
            'denda_pending' => [
                'judul' => 'Denda Pending',
                'count' => $pending_fines_count,
                'link' => 'kelola_denda.php'
            ]
        ];
        
        foreach ($types as $type => $data) {
            $pesan = "Ada {$data['count']} " . str_replace('_', ' ', $type) . " yang menunggu approval";
            $dibaca = $data['count'] > 0 ? 0 : 1;
            
            // Check if notification exists
            $checkSql = "SELECT id_notifikasi FROM notifikasi_admin WHERE tipe = ? ORDER BY id_notifikasi DESC LIMIT 1";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("s", $type);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                // Update existing notification
                $updateSql = "UPDATE notifikasi_admin 
                             SET pesan = ?, dibaca = ?, updated_at = NOW()
                             WHERE id_notifikasi = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("sii", $pesan, $dibaca, $row['id_notifikasi']);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new notification
                $insertSql = "INSERT INTO notifikasi_admin (judul, pesan, link, tipe, dibaca) 
                             VALUES (?, ?, ?, ?, ?)";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->bind_param("ssssi", $data['judul'], $pesan, $data['link'], $type, $dibaca);
                $insertStmt->execute();
                $insertStmt->close();
            }
            
            $checkStmt->close();
        }
        
    } catch (Exception $e) {
        // Silently fail - notifications are not critical
        error_log("Error updating notifications: " . $e->getMessage());
    }
    
    $conn->close();
}

// Hitung total
$total_pending_loans = count($pending_loans);
$total_pending_returns = count($pending_returns);
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
        
        /* Request Card Styles */
        .request-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            height: 100%;
            transition: all 0.3s ease;
        }
        
        .request-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }
        
        .request-card .card-body {
            padding: 1.25rem;
        }
        
        .request-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        
        /* Status Badges */
        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-pending {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.3);
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
        
        .badge-info {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }
        
        /* Book Cover */
        .book-cover-card {
            width: 70px;
            height: 90px;
            border-radius: 8px;
            overflow: hidden;
            background: #f8f9fa;
            flex-shrink: 0;
            margin-right: 15px;
            border: 1px solid #e9ecef;
        }
        
        .book-cover-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Request Header */
        .request-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .request-title {
            flex: 1;
        }
        
        .request-title h6 {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
            line-height: 1.3;
        }
        
        .request-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .request-meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Actions */
        .request-actions {
            margin-top: 1rem;
        }

        .btn-action {
            padding: 8px 12px;
            font-size: 0.9rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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
        
        /* Nav tabs */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 0;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background-color: white;
            border-bottom: 3px solid var(--primary-color);
        }
        
        /* Tab content */
        .tab-pane {
            background: white;
            border-radius: 0 0 12px 12px;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-top: none;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .request-header {
                flex-direction: column;
            }
            
            .book-cover-card {
                width: 100%;
                height: 150px;
                margin-right: 0;
                margin-bottom: 15px;
            }
            
            .request-actions .d-flex {
                flex-direction: column;
            }
            
            .request-actions .btn-action {
                width: 100% !important;
                margin-bottom: 8px;
            }
            
            .stat-card h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <!-- Sidebar -->
        <?php
        // Include sidebar logic here
        $current_page = 'kelola_peminjaman_pending.php';
        
        // Get pending count for sidebar badge
        $conn = getConnection();
        $pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
        $pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
        $total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;
        $conn->close();
        ?>
        
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
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Peminjaman Pending</h5>
                                        <h2 class="mb-0"><?php echo $total_pending_loans; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-book-open fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Pengembalian Pending</h5>
                                        <h2 class="mb-0"><?php echo $total_pending_returns; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-undo fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="card">
                        <div class="card-header p-0 border-0">
                            <ul class="nav nav-tabs" id="pendingTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="loans-tab" data-bs-toggle="tab" data-bs-target="#loans" type="button" role="tab">
                                        <i class="fas fa-book-open me-2"></i>Peminjaman
                                        <span class="badge bg-light text-primary ms-2"><?php echo $total_pending_loans; ?></span>
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="returns-tab" data-bs-toggle="tab" data-bs-target="#returns" type="button" role="tab">
                                        <i class="fas fa-undo me-2"></i>Pengembalian
                                        <span class="badge bg-light text-success ms-2"><?php echo $total_pending_returns; ?></span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="tab-content" id="pendingTabsContent">
                            <!-- Tab 1: Peminjaman Pending -->
                            <div class="tab-pane fade show active" id="loans" role="tabpanel">
                                <?php if ($total_pending_loans === 0): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h4 class="text-muted">Tidak ada peminjaman pending</h4>
                                    <p class="text-muted">Semua permintaan peminjaman telah diproses</p>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($pending_loans as $loan): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card request-card">
                                            <div class="card-body">
                                                <!-- Card Header with Dropdown -->
                                                <div class="d-flex justify-content-between align-items-start mb-3">
                                                    <div>
                                                        <span class="badge-status badge-pending">
                                                            <i class="fas fa-clock me-1"></i>Pending
                                                        </span>
                                                        <small class="text-muted ms-2">
                                                            ID: #<?php echo str_pad($loan['id_peminjaman'], 6, '0', STR_PAD_LEFT); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                
                                                <!-- Request Content -->
                                                <div class="request-header">
                                                    <div class="book-cover-card">
                                                        <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo htmlspecialchars($loan['cover_buku'] ?: 'default.jpg'); ?>"
                                                             alt="<?php echo htmlspecialchars($loan['judul_buku']); ?>"
                                                             onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                                    </div>
                                                    
                                                    <div class="request-title">
                                                        <h6 title="<?php echo htmlspecialchars($loan['judul_buku']); ?>">
                                                            <?php echo htmlspecialchars($loan['judul_buku']); ?>
                                                        </h6>
                                                        
                                                        <div class="request-meta">
                                                            <div class="request-meta-item">
                                                                <i class="fas fa-user text-primary"></i>
                                                                <span><?php echo htmlspecialchars($loan['username']); ?></span>
                                                            </div>
                                                            <div class="request-meta-item">
                                                                <i class="fas fa-tag text-info"></i>
                                                                <span><?php echo htmlspecialchars($loan['nama_kategori'] ?: 'Umum'); ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Request Details -->
                                                <div class="request-stats">
                                                    <div class="text-center">
                                                        <small class="text-muted d-block">Stok Tersedia</small>
                                                        <span class="badge bg-<?php echo $loan['stok'] > 0 ? 'success' : 'danger'; ?>">
                                                            <?php echo $loan['stok']; ?> buku
                                                        </span>
                                                    </div>
                                                    <div class="text-center">
                                                        <small class="text-muted d-block">Tanggal Request</small>
                                                        <small><?php echo $loan['tanggal_request_formatted']; ?></small>
                                                    </div>
                                                </div>
                                                
                                                <!-- Actions -->
                                                <div class="request-actions mt-3">
                                                    <div class="d-grid gap-2 d-flex">
                                                        <?php if ($loan['stok'] > 0): ?>
                                                        <form method="POST" class="flex-fill" 
                                                              id="approveLoanFormBtn-<?php echo $loan['id_peminjaman']; ?>">
                                                            <input type="hidden" name="action" value="approve_loan">
                                                            <input type="hidden" name="id_peminjaman" value="<?php echo $loan['id_peminjaman']; ?>">
                                                            <button type="button" 
                                                                    class="btn btn-success btn-action w-100"
                                                                    onclick="confirmApprove(<?php echo $loan['id_peminjaman']; ?>, '<?php echo addslashes($loan['judul_buku']); ?>', 'loan')">
                                                                <i class="fas fa-check me-1"></i>Setujui
                                                            </button>
                                                        </form>
                                                        <?php else: ?>
                                                        <button class="btn btn-secondary w-100" disabled>
                                                            <i class="fas fa-times me-1"></i>Stok Habis
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <button type="button" 
                                                                class="btn btn-danger btn-action flex-fill"
                                                                onclick="confirmReject(<?php echo $loan['id_peminjaman']; ?>, '<?php echo addslashes($loan['judul_buku']); ?>', 'loan')">
                                                            <i class="fas fa-times me-1"></i>Tolak
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Tab 2: Pengembalian Pending -->
                            <div class="tab-pane fade" id="returns" role="tabpanel">
                                <?php if ($total_pending_returns === 0): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h4 class="text-muted">Tidak ada pengembalian pending</h4>
                                    <p class="text-muted">Semua permintaan pengembalian telah diproses</p>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach ($pending_returns as $return): 
                                        $terlambat_hari = $return['terlambat_hari'] > 0 ? $return['terlambat_hari'] : 0;
                                    ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card request-card">
                                            <div class="card-body">
                                                <!-- Card Header with Dropdown -->
                                                <div class="d-flex justify-content-between align-items-start mb-3">
                                                    <div>
                                                        <span class="badge-status badge-pending">
                                                            <i class="fas fa-clock me-1"></i>Pending
                                                        </span>
                                                        <small class="text-muted ms-2">
                                                            ID: #<?php echo str_pad($return['id_pengembalian'], 6, '0', STR_PAD_LEFT); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                
                                                <!-- Request Content -->
                                                <div class="request-header">
                                                    <div class="book-cover-card">
                                                        <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo htmlspecialchars($return['cover_buku'] ?: 'default.jpg'); ?>"
                                                             alt="<?php echo htmlspecialchars($return['judul_buku']); ?>"
                                                             onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                                    </div>
                                                    
                                                    <div class="request-title">
                                                        <h6 title="<?php echo htmlspecialchars($return['judul_buku']); ?>">
                                                            <?php echo htmlspecialchars($return['judul_buku']); ?>
                                                        </h6>
                                                        
                                                        <div class="request-meta">
                                                            <div class="request-meta-item">
                                                                <i class="fas fa-user text-primary"></i>
                                                                <span><?php echo htmlspecialchars($return['username']); ?></span>
                                                            </div>
                                                            <div class="request-meta-item">
                                                                <i class="fas fa-calendar text-success"></i>
                                                                <span><?php echo date('d/m/Y', strtotime($return['tanggal_pinjam'])); ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Request Details -->
                                                <div class="request-stats">
                                                    <div class="text-center">
                                                        <small class="text-muted d-block">Jatuh Tempo</small>
                                                        <small><?php echo date('d/m/Y', strtotime($return['tanggal_jatuh_tempo'])); ?></small>
                                                    </div>
                                                    <div class="text-center">
                                                        <small class="text-muted d-block">Tanggal Kembali</small>
                                                        <small><?php echo $return['tanggal_kembali_formatted']; ?></small>
                                                    </div>
                                                    <?php if ($terlambat_hari > 0): ?>
                                                    <div class="text-center">
                                                        <small class="text-muted d-block">Keterlambatan</small>
                                                        <span class="badge bg-warning">
                                                            <?php echo $terlambat_hari; ?> hari
                                                        </span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- Actions -->
                                                <div class="request-actions mt-3">
                                                    <div class="d-grid gap-2 d-flex">
                                                        <form method="POST" class="flex-fill" 
                                                              id="approveReturnFormBtn-<?php echo $return['id_pengembalian']; ?>">
                                                            <input type="hidden" name="action" value="approve_return">
                                                            <input type="hidden" name="id_pengembalian" value="<?php echo $return['id_pengembalian']; ?>">
                                                            <button type="button" 
                                                                    class="btn btn-success btn-action w-100"
                                                                    onclick="confirmApprove(<?php echo $return['id_pengembalian']; ?>, '<?php echo addslashes($return['judul_buku']); ?>', 'return')">
                                                                <i class="fas fa-check me-1"></i>Setujui
                                                            </button>
                                                        </form>
                                                        
                                                        <button type="button" 
                                                                class="btn btn-danger btn-action flex-fill"
                                                                onclick="confirmReject(<?php echo $return['id_pengembalian']; ?>, '<?php echo addslashes($return['judul_buku']); ?>', 'return')">
                                                            <i class="fas fa-times me-1"></i>Tolak
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
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

        // Confirm approve action
        function confirmApprove(id, title, type) {
            let actionText = '';
            let confirmButtonText = '';
            let formId = '';
            
            if (type === 'loan') {
                actionText = 'menyetujui peminjaman';
                confirmButtonText = 'Ya, Setujui';
                formId = `approveLoanFormBtn-${id}`;
            } else if (type === 'return') {
                actionText = 'mengkonfirmasi pengembalian';
                confirmButtonText = 'Ya, Konfirmasi';
                formId = `approveReturnFormBtn-${id}`;
            }
            
            Swal.fire({
                title: 'Konfirmasi',
                html: `Apakah Anda yakin ingin <strong>${actionText}</strong><br><strong>"${title}"</strong>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: confirmButtonText,
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Submit the form
                    document.getElementById(formId).submit();
                }
            });
        }

        // Confirm reject action
        function confirmReject(id, title, type) {
            let actionText = '';
            let actionValue = '';
            
            if (type === 'loan') {
                actionText = 'menolak peminjaman';
                actionValue = 'reject_loan';
            } else if (type === 'return') {
                actionText = 'menolak pengembalian';
                actionValue = 'reject_return';
            }
            
            Swal.fire({
                title: 'Tolak Permintaan',
                html: `Apakah Anda yakin ingin <strong>${actionText}</strong><br><strong>"${title}"</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Tolak',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create and submit reject form
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = actionValue;
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    if (type === 'loan') {
                        idInput.name = 'id_peminjaman';
                    } else {
                        idInput.name = 'id_pengembalian';
                    }
                    idInput.value = id;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
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
            
            // Show success/error messages from PHP
            <?php if (!empty($success_message)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: '<?php echo addslashes($success_message); ?>',
                timer: 2000,
                showConfirmButton: false
            });
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal!',
                text: '<?php echo addslashes($error_message); ?>'
            });
            <?php endif; ?>
            
            // Initialize dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
            
            // Initialize tabs
            var tabEl = document.querySelectorAll('button[data-bs-toggle="tab"]');
            tabEl.forEach(function(tab) {
                new bootstrap.Tab(tab);
            });
        });
    </script>
</body>
</html>