<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Only anggota can access this page
if (!isLoggedIn() || !isAnggota()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

// Set page variables
$page_title = 'Dashboard Anggota';
$page_icon = 'fas fa-home';

// Get statistics
$conn = getConnection();
$userId = $_SESSION['user_id'];

// Total books borrowed
$sql = "SELECT COUNT(*) as total FROM peminjaman WHERE id_anggota = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$total_borrowed = $result->fetch_assoc()['total'];
$stmt->close();

// Currently borrowed
$sql = "SELECT COUNT(*) as total FROM peminjaman WHERE id_anggota = ? AND status = 'dipinjam'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$currently_borrowed = $result->fetch_assoc()['total'];
$stmt->close();

// Overdue books
$sql = "SELECT COUNT(*) as total FROM peminjaman WHERE id_anggota = ? AND status = 'terlambat'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$overdue_books = $result->fetch_assoc()['total'];
$stmt->close();

// Books to return soon (within 3 days)
$sql = "SELECT COUNT(*) as total FROM peminjaman 
        WHERE id_anggota = ? 
        AND status = 'dipinjam' 
        AND tanggal_jatuh_tempo <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        AND tanggal_jatuh_tempo >= CURDATE()";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$return_soon = $result->fetch_assoc()['total'];
$stmt->close();

// Recent activities
$sql = "SELECT p.*, b.judul_buku, b.cover_buku 
        FROM peminjaman p 
        JOIN buku b ON p.id_buku = b.id_buku 
        WHERE p.id_anggota = ? 
        ORDER BY p.created_at DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Popular books
$sql = "SELECT b.*, k.nama_kategori, 
        (SELECT COUNT(*) FROM peminjaman p WHERE p.id_buku = b.id_buku) as popularity
        FROM buku b 
        LEFT JOIN kategori k ON b.id_kategori = k.id_kategori 
        ORDER BY popularity DESC, b.jumlah_pinjam DESC 
        LIMIT 6";
$popular_books = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Total available books
$total_books = $conn->query("SELECT COUNT(*) as total FROM buku WHERE stok > 0")->fetch_assoc()['total'];

$conn->close();
?>

<?php include '../templates/header.php'; ?>

<!-- Include Sidebar -->
<?php include '../templates/anggota_sidebar.php'; ?>

<!-- Include Topbar -->
<?php include '../templates/anggota_topbar.php'; ?>

<!-- Main Content -->
<main class="main-content">
    <div class="container-fluid py-4">
        <!-- Welcome Banner -->
        <div class="welcome-banner mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">Selamat Datang, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                    <p class="mb-0" style="color: white;">Ada <?php echo number_format($total_books); ?> buku tersedia untuk Anda jelajahi.</p>
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
        <div class="row mb-4 justify-content-center">
            <div class="col-xl-3 col-md-4 mb-4">
                <div class="stat-card">
                    <div class="stat-icon bg-primary-light">
                        <i class="fas fa-book text-primary"></i>
                    </div>
                    <div class="stat-number"><?php echo $currently_borrowed; ?></div>
                    <div class="stat-label">Sedang Dipinjam</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-4 mb-4">
                <div class="stat-card">
                    <div class="stat-icon bg-success-light">
                        <i class="fas fa-history text-success"></i>
                    </div>
                    <div class="stat-number"><?php echo $total_borrowed; ?></div>
                    <div class="stat-label">Total Dipinjam</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-4 mb-4">
                <div class="stat-card">
                    <div class="stat-icon bg-danger-light">
                        <i class="fas fa-exclamation-triangle text-danger"></i>
                    </div>
                    <div class="stat-number"><?php echo $overdue_books; ?></div>
                    <div class="stat-label">Terlambat</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Recent Activities -->
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history me-2"></i>Aktivitas Terbaru
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Buku</th>
                                        <th>Tanggal Pinjam</th>
                                        <th>Jatuh Tempo</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_activities)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <i class="fas fa-book-open fa-2x text-muted mb-3"></i>
                                            <p class="text-muted mb-0">Belum ada aktivitas</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_activities as $activity):
                                            $status_class = '';
                                            $status_text = '';

                                            if ($activity['status'] === 'dipinjam') {
                                                $status_class = 'warning';
                                                $status_text = 'Dipinjam';
                                            } elseif ($activity['status'] === 'rejected') {
                                                $status_class = 'danger';
                                                $status_text = 'Ditolak';
                                            } elseif ($activity['status'] === 'pending') {
                                                $status_class = 'warning';
                                                $status_text = 'Menunggu Persetujuan';
                                            } elseif ($activity['status'] === 'dikembalikan') {
                                                $status_class = 'success';
                                                $status_text = 'Dikembalikan';
                                            } else {
                                                $status_class = 'danger';
                                                $status_text = 'Terlambat';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="book-cover-mini me-3">
                                                        <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $activity['cover_buku'] ?: 'default.jpg'; ?>"
                                                             alt="<?php echo htmlspecialchars($activity['judul_buku']); ?>">
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($activity['judul_buku']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo formatDate($activity['tanggal_pinjam']); ?></td>
                                            <td><?php echo formatDate($activity['tanggal_jatuh_tempo']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="detail_buku.php?id=<?php echo $activity['id_buku']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="riwayat.php" class="btn btn-primary">
                                <i class="fas fa-list me-2"></i>Lihat Semua Riwayat
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        
    </div>
</main>

<style>
    .main-content {
        margin-left: var(--sidebar-width);
        margin-top: var(--topbar-height);
        padding: 2rem;
        min-height: calc(100vh - var(--topbar-height));
        transition: var(--transition);
        width: calc(100% - var(--sidebar-width));
    }
    
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 1.5rem;
        }
    }
    
    .container-fluid {
        max-width: 100%;
        padding-left: 0;
        padding-right: 0;
    }
    
    .welcome-banner {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 2rem;
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        margin-left: 0;
        margin-right: 0;
    }
    
    .welcome-banner h1 {
        color: white;
        font-size: 1.75rem;
        font-weight: 700;
    }
    
    .welcome-banner .date-display {
        background: rgba(255, 255, 255, 0.1);
        padding: 10px 15px;
        border-radius: 8px;
        display: inline-block;
        font-size: 0.95rem;
    }
    
    /* Pastikan semua card mengambil lebar penuh */
    .card {
        width: 100%;
    }
    
    .row {
        margin-left: 0;
        margin-right: 0;
    }
    
    .col-lg-8, .col-lg-4, .col-xl-3, .col-md-4, .col-12 {
        padding-left: 15px;
        padding-right: 15px;
    }
    
    .bg-primary-light { background-color: rgba(67, 97, 238, 0.1); }
    .bg-success-light { background-color: rgba(75, 181, 67, 0.1); }
    .bg-warning-light { background-color: rgba(240, 173, 78, 0.1); }
    .bg-danger-light { background-color: rgba(220, 53, 69, 0.1); }
    .bg-info-light { background-color: rgba(76, 201, 240, 0.1); }
    
    .book-cover-mini {
        width: 50px;
        height: 65px;
        border-radius: 5px;
        overflow: hidden;
        background: var(--gray-200);
    }
    
    .book-cover-mini img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .book-cover-small {
        width: 40px;
        height: 55px;
        border-radius: 5px;
        overflow: hidden;
        background: var(--gray-200);
    }
    
    .book-cover-small img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .popular-book-item {
        padding: 10px;
        border-radius: 8px;
        transition: var(--transition);
        border: 1px solid transparent;
        min-width: 250px;
    }
    
    .popular-book-item:hover {
        border-color: var(--primary-color);
        background-color: var(--primary-light);
    }

    .popular-books-scroll {
        display: flex;
        overflow-x: auto;
        gap: 15px;
        padding: 10px 0;
        scrollbar-width: thin;
        scrollbar-color: var(--gray-400) var(--gray-200);
    }

    .popular-books-scroll::-webkit-scrollbar {
        height: 6px;
    }

    .popular-books-scroll::-webkit-scrollbar-track {
        background: var(--gray-200);
        border-radius: 3px;
    }

    .popular-books-scroll::-webkit-scrollbar-thumb {
        background: var(--gray-400);
        border-radius: 3px;
    }

    .popular-books-scroll::-webkit-scrollbar-thumb:hover {
        background: var(--gray-500);
    }
    
    .quick-action-card {
        display: block;
        text-align: center;
        padding: 1.5rem 1rem;
        border-radius: var(--border-radius);
        background: white;
        border: 2px solid var(--gray-200);
        transition: var(--transition);
        text-decoration: none;
    }
    
    .quick-action-card:hover {
        transform: translateY(-5px);
        border-color: var(--primary-color);
        box-shadow: var(--box-shadow);
    }
    
    .quick-action-icon {
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
    
    .quick-action-text {
        font-weight: 600;
        color: var(--gray-800);
        font-size: 0.95rem;
    }
    
    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .main-content {
            padding: 1.5rem;
        }
        
        .welcome-banner {
            padding: 1.5rem;
        }
        
        .welcome-banner h1 {
            font-size: 1.5rem;
        }
    }
    
    @media (max-width: 768px) {
        .main-content {
            padding: 1rem;
        }
        
        .welcome-banner {
            padding: 1.25rem;
        }
        
        .welcome-banner h1 {
            font-size: 1.25rem;
        }
        
        .stat-card {
            padding: 1rem;
        }
    }
</style>

<?php include '../templates/footer.php'; ?>