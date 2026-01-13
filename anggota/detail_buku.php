<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn() || !isAnggota()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

// Set page variables
$page_title = 'Detail Buku';
$page_icon = 'fas fa-book-open';

// Get book ID
$id_buku = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_buku <= 0) {
    header('Location: daftar_buku.php');
    exit();
}

// Get book details
$conn = getConnection();
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
        WHERE b.id_buku = ?
        GROUP BY b.id_buku";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_buku);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    header('Location: daftar_buku.php');
    exit();
}

$book = $result->fetch_assoc();
$stmt->close();

// Get user's rating for this book
$userId = $_SESSION['user_id'];
$ratingSql = "SELECT rating FROM rating WHERE id_anggota = ? AND id_buku = ?";
$ratingStmt = $conn->prepare($ratingSql);
$ratingStmt->bind_param("ii", $userId, $id_buku);
$ratingStmt->execute();
$ratingResult = $ratingStmt->get_result();
$userRating = $ratingResult->num_rows > 0 ? $ratingResult->fetch_assoc()['rating'] : 0;
$ratingStmt->close();

// Get borrowing status
$status = 'kosong';
$borrowData = null;
$id_peminjaman = null;
$tanggal_jatuh_tempo = null;
$borrowSql = "SELECT * FROM peminjaman WHERE id_buku = ? AND id_anggota = ? AND status IN ('pending', 'dipinjam', 'terlambat', 'ditolak')";
$borrowStmt = $conn->prepare($borrowSql);
$borrowStmt->bind_param("ii", $id_buku, $userId);
$borrowStmt->execute();
$borrowResult = $borrowStmt->get_result();

if ($borrowResult->num_rows > 0) {
    $borrowData = $borrowResult->fetch_assoc();
    $status = $borrowData['status'];
    $id_peminjaman = $borrowData['id_peminjaman'];
    $tanggal_jatuh_tempo = $borrowData['tanggal_jatuh_tempo'];

    // Check if book is overdue
    if ($status === 'dipinjam' && strtotime(date('Y-m-d')) > strtotime($tanggal_jatuh_tempo)) {
        // Update status to terlambat
        $updateSql = "UPDATE peminjaman SET status = 'terlambat' WHERE id_peminjaman = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $id_peminjaman);
        $updateStmt->execute();
        $updateStmt->close();
        $status = 'terlambat';
    }

    // Check if there's a pending return request
    if ($status === 'dipinjam' || $status === 'terlambat') {
        $returnCheckSql = "SELECT id_pengembalian FROM pengembalian WHERE id_peminjaman = ? AND id_anggota = ? AND status_denda = 'belum_lunas'";
        $returnCheckStmt = $conn->prepare($returnCheckSql);
        if ($returnCheckStmt) {
            $returnCheckStmt->bind_param("ii", $id_peminjaman, $userId);
            $returnCheckStmt->execute();
            $res = $returnCheckStmt->get_result();
            if ($res && $res->num_rows > 0) {
                $status = 'return_pending';
            }
            $returnCheckStmt->close();
        }
    }
}
$borrowStmt->close();

// Get book reviews
$reviewsSql = "SELECT r.*, a.username, a.foto_profil 
               FROM rating r 
               JOIN anggota a ON r.id_anggota = a.id_anggota 
               WHERE r.id_buku = ? 
               ORDER BY r.created_at DESC 
               LIMIT 5";
$reviewsStmt = $conn->prepare($reviewsSql);
$reviewsStmt->bind_param("i", $id_buku);
$reviewsStmt->execute();
$reviews = $reviewsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reviewsStmt->close();

// Get similar books
$similarSql = "SELECT b.*, k.nama_kategori 
               FROM buku b 
               LEFT JOIN kategori k ON b.id_kategori = k.id_kategori 
               WHERE b.id_kategori = ? AND b.id_buku != ? AND b.stok > 0 
               LIMIT 4";
$similarStmt = $conn->prepare($similarSql);
$similarStmt->bind_param("ii", $book['id_kategori'], $id_buku);
$similarStmt->execute();
$similarBooks = $similarStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$similarStmt->close();

$conn->close();

// Handle rating action
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($action === 'rating') {
    // Submit rating
    $rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
    if ($rating >= 1 && $rating <= 5) {
        $conn = getConnection();
        
        // Check if already rated
        $checkSql = "SELECT id_rating FROM rating WHERE id_anggota = ? AND id_buku = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("ii", $userId, $id_buku);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing rating
            $ratingRow = $checkResult->fetch_assoc();
            $updateSql = "UPDATE rating SET rating = ? WHERE id_rating = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("ii", $rating, $ratingRow['id_rating']);
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            // Insert new rating
            $insertSql = "INSERT INTO rating (id_anggota, id_buku, rating) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("iii", $userId, $id_buku, $rating);
            $insertStmt->execute();
            $insertStmt->close();
        }
        
        // Recalculate average rating
        $calcSql = "SELECT AVG(rating) as avg_rating, COUNT(*) as count FROM rating WHERE id_buku = ?";
        $calcStmt = $conn->prepare($calcSql);
        $calcStmt->bind_param("i", $id_buku);
        $calcStmt->execute();
        $calcResult = $calcStmt->get_result();
        $calcData = $calcResult->fetch_assoc();

        // Update book rating
        $updateBookSql = "UPDATE buku SET rata_rating = ?, jumlah_rating = ? WHERE id_buku = ?";
        $updateBookStmt = $conn->prepare($updateBookSql);
        $updateBookStmt->bind_param("dii", $calcData['avg_rating'], $calcData['count'], $id_buku);
        $updateBookStmt->execute();

        // Update book data
        $book['rating_avg'] = $calcData['avg_rating'];
        $book['rating_count'] = $calcData['count'];
        $userRating = $rating;

        $message = 'Rating berhasil disimpan';
        $messageType = 'success';

        $checkStmt->close();
        $calcStmt->close();
        $updateBookStmt->close();
        $conn->close();
    }
}
?>

<?php include '../templates/header.php'; ?>
<?php include '../templates/anggota_sidebar.php'; ?>
<?php include '../templates/anggota_topbar.php'; ?>

<main class="main-content">
    <div class="container-fluid py-4">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($status === 'terlambat'): ?>
        <div class="alert alert-warning alert-persistent mb-3" style="display: block !important;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Perhatian!</strong> Buku ini terlambat dikembalikan. Segera bayar denda untuk mengakses fitur peminjaman buku lainnya.
        </div>
        <?php endif; ?>

        <?php if ($status === 'ditolak'): ?>
        <div class="alert alert-danger alert-persistent mb-3" style="display: block !important;">
            <i class="fas fa-times-circle me-2"></i>
            <strong>Permintaan Ditolak!</strong> Permintaan peminjaman buku ini telah ditolak oleh admin. Silakan ajukan permintaan untuk buku lainnya.
        </div>
        <?php endif; ?>

        <?php if ($status === 'return_pending'): ?>
        <div class="alert alert-info alert-persistent mb-3" style="display: block !important;">
            <i class="fas fa-clock me-2"></i>
            <strong>Permintaan Pengembalian Menunggu Persetujuan!</strong> Permintaan pengembalian buku ini sedang menunggu persetujuan admin. Anda akan diberitahu setelah admin memproses permintaan Anda.
        </div>
        <?php endif; ?>

        <!-- Book Detail -->
        <div class="row">
            <!-- Left Column - Book Info -->
            <div class="col-lg-8">
                <!-- Book Header -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <!-- Book Cover -->
                            <div class="col-md-6">
                                <div class="book-cover-large">
                                    <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo htmlspecialchars($book['cover_buku']); ?>"
                                         alt="<?php echo htmlspecialchars($book['judul_buku']); ?>"
                                         onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                </div>

                                <!-- Book Actions -->
                                <div class="mt-3">
                                    <div class="action-buttons d-flex gap-2 justify-content-center">
                                        <?php if ($status === 'kosong' && $book['stok'] > 0): ?>
                                            <button type="button" class="btn btn-primary btn-lg px-4" id="pinjamBtn">
                                                <i class="fas fa-book-reader me-2"></i>Pinjam Buku
                                            </button>
                                        <?php elseif ($status === 'pending'): ?>
                                            <button class="btn btn-warning btn-lg px-4" disabled>
                                                <i class="fas fa-clock me-2"></i>Menunggu Persetujuan Admin
                                            </button>
                                        <?php elseif ($status === 'dipinjam'): ?>
                                            <button type="button" class="btn btn-success" id="kembalikanBtn">
                                                <i class="fas fa-undo me-2"></i>Ajukan Pengembalian
                                            </button>
                                        <?php elseif ($status === 'return_pending'): ?>
                                            <button class="btn btn-info btn-lg px-4" disabled>
                                                <i class="fas fa-clock me-2"></i>Menunggu Persetujuan Pengembalian
                                            </button>
                                        <?php elseif ($status === 'terlambat'): ?>
                                            <button class="btn btn-danger btn-lg px-4" disabled>
                                                <i class="fas fa-exclamation-triangle me-2"></i>Buku Terlambat
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-lg px-4" disabled>
                                                <i class="fas fa-ban me-2"></i>Tidak Tersedia
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($book['file_pdf']) && ($status === 'dipinjam' || $status === 'terlambat')): ?>
                                            <a href="read_pdf.php?id=<?php echo $id_buku; ?>" class="btn btn-outline-primary">
                                                <i class="fas fa-book-open me-2"></i>Baca Buku
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Book Details -->
                            <div class="col-md-6">
                                <h1 class="book-title-main mb-3"><?php echo htmlspecialchars($book['judul_buku']); ?></h1>

                                <!-- Author Box -->
                                <div class="book-author-section mb-4">
                                    <h6 class="text-muted mb-2">Penulis</h6>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-edit me-2 text-primary"></i>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($book['nama_penulis'] ?? 'Penulis Tidak Diketahui'); ?></h5>
                                    </div>
                                </div>

                                <!-- Rating -->
                                <div class="book-rating-section mb-4">
                                    <h6 class="text-muted mb-2">Rating</h6>
                                    <div class="rating-container d-flex align-items-center">
                                        <!-- Rating Display -->
                                        <div class="rating-display">
                                            <div class="stars-medium me-2">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?php if ($i <= floor($book['rating_avg'])): ?>
                                                        <i class="fas fa-star"></i>
                                                    <?php elseif ($i <= ceil($book['rating_avg']) && $book['rating_avg'] - floor($book['rating_avg']) >= 0.5): ?>
                                                        <i class="fas fa-star-half-alt"></i>
                                                    <?php else: ?>
                                                        <i class="far fa-star"></i>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </div>
                                            <div class="rating-value">
                                                <span class="fw-bold">
                                                    <span class="number-value"><?php echo number_format($book['rating_avg'], 1); ?></span>
                                                </span>
                                                <span class="text-muted">/5.0 (<span class="number-value"><?php echo $book['rating_count']; ?></span> ulasan)</span>
                                            </div>
                                        </div>

                                        <!-- Divider -->
                                        <div class="mx-3 rating-divider"></div>

                                        <!-- Rate This Book -->
                                        <div class="rate-book d-flex align-items-center">
                                            <h6 class="text-muted mb-0 me-2">Beri Rating</h6>
                                            <div class="rating-stars-select" id="ratingStars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?php if ($i <= $userRating): ?>
                                                        <i class="fas fa-star star" data-rating="<?php echo $i; ?>"></i>
                                                    <?php else: ?>
                                                        <i class="far fa-star star" data-rating="<?php echo $i; ?>"></i>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </div>
                                            <small class="text-muted ms-2">Klik bintang</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Book Metadata -->
                                <div class="row book-meta-grid mb-4">
                                    <div class="col-md-12">
                                        <div class="meta-item">
                                            <i class="fas fa-tag text-primary"></i>
                                            <div>
                                                <small class="text-muted">Kategori</small>
                                                <div class="fw-bold"><?php echo htmlspecialchars($book['nama_kategori'] ?? 'Umum'); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="meta-item">
                                            <i class="fas fa-building text-primary"></i>
                                            <div>
                                                <small class="text-muted">Penerbit</small>
                                                <div class="fw-bold"><?php echo htmlspecialchars($book['nama_penerbit'] ?? 'Penerbit Tidak Diketahui'); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="meta-item">
                                            <i class="fas fa-calendar text-primary"></i>
                                            <div>
                                                <small class="text-muted">Tahun Terbit</small>
                                                <div class="fw-bold"><span class="number-value"><?php echo $book['tahun_terbit']; ?></span></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="meta-item">
                                            <i class="fas fa-copy text-primary"></i>
                                            <div>
                                                <small class="text-muted">Jumlah Halaman</small>
                                                <div class="fw-bold"><span class="number-value"><?php echo $book['jumlah_halaman']; ?></span> halaman</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Description Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-align-left me-2"></i>Deskripsi Buku
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="book-description">
                            <?php if (!empty($book['deskripsi'])): ?>
                                <?php echo nl2br(htmlspecialchars($book['deskripsi'])); ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-4">
                                    <i class="fas fa-info-circle fa-2x mb-3"></i><br>
                                    Tidak ada deskripsi tersedia untuk buku ini.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Reviews Card -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-comments me-2"></i>Ulasan Pembaca
                            <span class="badge bg-primary ms-2"><?php echo $book['rating_count']; ?></span>
                        </h5>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reviewModal">
                            <i class="fas fa-plus me-1"></i>Tambah Ulasan
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reviews)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-comment-slash fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">Belum ada ulasan untuk buku ini.</p>
                            <p class="text-muted">Jadilah yang pertama memberikan ulasan!</p>
                        </div>
                        <?php else: ?>
                            <div class="reviews-list">
                                <?php foreach ($reviews as $review): ?>
                                <div class="review-item mb-4 pb-4 border-bottom">
                                    <div class="d-flex align-items-start mb-2">
                                        <div class="reviewer-avatar me-3">
                                            <img src="<?php echo SITE_URL; ?>uploads/profiles/<?php echo $review['foto_profil'] ?? 'default.png'; ?>" 
                                                 alt="<?php echo htmlspecialchars($review['username']); ?>">
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($review['username']); ?></h6>
                                                <small class="text-muted"><?php echo formatDate($review['created_at'], 'd/m/Y'); ?></small>
                                            </div>
                                            <div class="stars-small my-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?php if ($i <= $review['rating']): ?>
                                                        <i class="fas fa-star text-warning"></i>
                                                    <?php else: ?>
                                                        <i class="far fa-star text-warning"></i>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($review['komentar'])): ?>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($review['komentar'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($book['rating_count'] > 5): ?>
                            <div class="text-center mt-3">
                                <a href="#" class="btn btn-outline-primary">
                                    <i class="fas fa-list me-2"></i>Lihat Semua Ulasan
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Additional Info -->
            <div class="col-lg-4">
                <!-- Borrowing Info Card -->
                <?php if (($status === 'dipinjam' || $status === 'terlambat') && $borrowData !== null): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle me-2"></i>Informasi Peminjaman
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="borrowing-info">
                            <div class="info-item">
                                <i class="fas fa-calendar-check text-primary"></i>
                                <div>
                                    <small class="text-muted">Tanggal Pinjam</small>
                                    <div class="fw-bold"><?php echo formatDate($borrowData['tanggal_pinjam']); ?></div>
                                </div>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar-times text-primary"></i>
                                <div>
                                    <small class="text-muted">Jatuh Tempo</small>
                                    <div class="fw-bold"><?php echo formatDate($tanggal_jatuh_tempo); ?></div>
                                </div>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-flag text-primary"></i>
                                <div>
                                    <small class="text-muted">Status</small>
                                    <div>
                                        <span class="badge <?php echo $status === 'dipinjam' ? 'bg-warning' : 'bg-danger'; ?>">
                                            <?php echo $status === 'dipinjam' ? 'Sedang Dipinjam' : 'Terlambat'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($status === 'terlambat'): 
                                $terlambat_hari = floor((strtotime(date('Y-m-d')) - strtotime($tanggal_jatuh_tempo)) / (60 * 60 * 24));
                                $denda = calculateDenda($terlambat_hari);
                            ?>
                            <div class="info-item">
                                <i class="fas fa-clock text-danger"></i>
                                <div>
                                    <small class="text-muted">Terlambat</small>
                                    <div class="fw-bold text-danger"><span class="number-value"><?php echo $terlambat_hari; ?></span> hari</div>
                                </div>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-money-bill-wave text-danger"></i>
                                <div>
                                    <small class="text-muted">Denda</small>
                                    <div class="fw-bold text-danger"><span class="number-value"><?php echo formatCurrency($denda); ?></span></div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-center align-items-center mt-3">
                                <button type="button" class="btn btn-warning btn-sm"
                                        onclick="openBayarDendaModal(<?php echo $id_peminjaman; ?>, '<?php echo htmlspecialchars($book['judul_buku']); ?>', <?php echo $denda; ?>)">
                                    <i class="fas fa-money-bill-wave me-2"></i>Bayar Denda
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Book Statistics Card - DIPERBAIKI -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Statistik Buku
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="book-stats-grid">
                            <div class="stat-item">
                                <div class="stat-icon-container bg-primary">
                                    <i class="fas fa-book-reader"></i>
                                </div>
                                <div class="stat-content">
                                    <!-- PERUBAHAN DI SINI: Menggunakan approved_pinjam_count dari query -->
                                    <div class="stat-number"><?php echo $book['approved_pinjam_count'] ?? 0; ?></div>
                                    <div class="stat-label">Total Dipinjam</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon-container bg-success">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo number_format($book['rating_avg'], 1); ?></div>
                                    <div class="stat-label">Rating Rata-rata</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon-container bg-info">
                                    <i class="fas fa-copy"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo $book['stok']; ?></div>
                                    <div class="stat-label">Stok Tersedia</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon-container bg-warning">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number"><?php echo $book['rating_count']; ?></div>
                                    <div class="stat-label">Jumlah Ulasan</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Similar Books Card -->
                <?php if (!empty($similarBooks)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-bookmark me-2"></i>Buku Serupa
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="similar-books">
                            <?php foreach ($similarBooks as $similarBook): ?>
                            <a href="detail_buku.php?id=<?php echo $similarBook['id_buku']; ?>" class="similar-book-item">
                                <div class="d-flex align-items-center">
                                    <div class="similar-book-cover me-3">
                                        <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $similarBook['cover_buku'] ?: 'default.jpg'; ?>" 
                                             alt="<?php echo htmlspecialchars($similarBook['judul_buku']); ?>">
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="similar-book-title"><?php echo htmlspecialchars($similarBook['judul_buku']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($similarBook['nama_kategori'] ?? 'Umum'); ?></small>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Ulasan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="reviewForm">
                    <input type="hidden" name="id_buku" value="<?php echo $id_buku; ?>">
                    <div class="mb-3">
                        <label class="form-label">Rating</label>
                        <div class="rating-input" id="reviewRating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="far fa-star star-review" data-rating="<?php echo $i; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="selectedRating" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Komentar (Opsional)</label>
                        <textarea class="form-control" name="komentar" rows="3" placeholder="Bagikan pengalaman Anda membaca buku ini..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-paper-plane me-2"></i>Kirim Ulasan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Bayar Denda -->
<div class="modal fade" id="bayarDendaModal" tabindex="-1" aria-labelledby="bayarDendaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bayarDendaModalLabel">
                    <i class="fas fa-money-bill-wave me-2"></i>Bayar Denda Buku
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="dendaInfo" class="mb-4">
                    <!-- Info denda akan diisi oleh JavaScript -->
                </div>

                <form id="bayarDendaForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="bayar_denda">
                    <input type="hidden" name="id_peminjaman" id="modalIdPeminjaman">
                    <input type="hidden" name="jumlah_denda" id="modalJumlahDenda">

                    <div class="mb-3">
                        <label class="form-label">Metode Pembayaran <span class="text-danger">*</span></label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="metode" id="metodeTunai" value="tunai" checked>
                                    <label class="form-check-label" for="metodeTunai">
                                        <i class="fas fa-money-bill-wave me-2"></i>Tunai
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="metode" id="metodeTransfer" value="transfer">
                                    <label class="form-check-label" for="metodeTransfer">
                                        <i class="fas fa-credit-card me-2"></i>Transfer Bank
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="buktiTransferDiv" style="display: none;">
                        <label class="form-label">Bukti Transfer <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="bukti_transfer" id="buktiTransfer" accept="image/*,.pdf">
                        <div class="form-text">Upload bukti transfer (JPG, PNG, PDF). Maksimal 2MB.</div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Informasi:</strong> Pembayaran denda memerlukan persetujuan dari admin. Setelah mengirimkan pembayaran, tunggu konfirmasi dari admin untuk menyelesaikan proses.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Batal
                </button>
                <button type="button" class="btn btn-primary" id="submitBayarDenda">
                    <i class="fas fa-paper-plane me-2"></i>Kirim Pembayaran
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Book Detail Styles */
    .book-cover-large {
        width: 100%;
        height: 350px;
        border-radius: 15px;
        overflow: hidden;
        background: var(--gray-200);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }
    
    .book-cover-large img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    
    .book-cover-large:hover img {
        transform: scale(1.05);
    }
    
    .book-title-main {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--gray-900);
        line-height: 1.3;
    }
    
    .book-author-section {
        padding: 1rem;
        background: rgba(67, 97, 238, 0.05);
        border-radius: 10px;
        border-left: 4px solid var(--primary-color);
    }
    
    /* Rating Styles */
    .book-rating-section {
        margin-bottom: 1.5rem;
    }

    .rating-container {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 15px;
        padding: 10px;
        background: rgba(67, 97, 238, 0.03);
        border-radius: 8px;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    /* Rating Display - Updated */
    .rating-display {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
        flex-shrink: 0;
    }

    .stars-medium {
        font-size: 0.95rem;
        color: #ffc107;
        line-height: 1;
        display: flex;
        align-items: center;
    }

    .rating-value {
        font-size: 0.75rem;
        white-space: nowrap;
        margin-left: 0;
        text-align: left;
        width: 100%;
    }

    .rating-value .fw-bold {
        font-size: 0.8rem;
    }

    /* Divider */
    .rating-divider {
        width: 1px;
        height: 40px;
        background: #dee2e6;
        flex-shrink: 0;
        margin: 0 10px;
    }

    /* Rate Book Section - Updated */
    .rate-book {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 3px;
        flex-shrink: 0;
    }

    .rate-book h6 {
        font-size: 0.75rem;
        margin-bottom: 0;
        color: #6c757d;
        text-align: left;
        white-space: nowrap;
    }

    .rating-stars-select {
        font-size: 0.95rem;
        color: #ffc107;
        cursor: pointer;
        line-height: 1;
        display: flex;
        align-items: center;
    }

    .rating-stars-select .star {
        transition: all 0.2s ease;
        margin: 0 1px;
    }

    .rating-stars-select .star:hover {
        transform: scale(1.2);
    }

    .rate-book small {
        font-size: 0.7rem;
        color: #6c757d;
        text-align: left;
        white-space: nowrap;
    }
    
    /* Book Metadata */
    .book-meta-grid {
        display: grid;
        gap: 1rem;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px;
        background: var(--gray-100);
        border-radius: 8px;
        transition: all 0.3s ease;
        overflow-wrap: break-word;
    }
    
    .meta-item:hover {
        background: var(--primary-light);
        transform: translateY(-2px);
    }
    
    .meta-item i {
        font-size: 1.25rem;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .book-status-badge {
        display: inline-block;
        padding: 8px 20px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.95rem;
    }
    
    .status-kosong {
        background: rgba(75, 181, 67, 0.1);
        color: #4bb543;
        border: 2px solid rgba(75, 181, 67, 0.3);
    }
    
    .status-dipinjam {
        background: rgba(240, 173, 78, 0.1);
        color: #f0ad4e;
        border: 2px solid rgba(240, 173, 78, 0.3);
    }
    
    .status-terlambat {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
        border: 2px solid rgba(220, 53, 69, 0.3);
    }
    
    .book-description {
        line-height: 1.8;
        color: var(--gray-700);
        font-size: 0.85rem;
    }
    
    .book-description p {
        margin-bottom: 1.5rem;
    }
    
    /* Reviews Styles */
    .reviewer-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        overflow: hidden;
        border: 2px solid var(--gray-300);
    }
    
    .reviewer-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .stars-small {
        color: #ffc107;
        font-size: 0.8rem;
    }
    
    .review-item:last-child {
        border-bottom: none !important;
        margin-bottom: 0 !important;
        padding-bottom: 0 !important;
    }

    .number-value {
        font-size: 0.8rem !important;
    }
    
    /* Borrowing Info Styles */
    .borrowing-info {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .info-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px;
        background: var(--gray-100);
        border-radius: 8px;
        overflow-wrap: break-word;
    }
    
    .info-item i {
        font-size: 1.25rem;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    /* Book Statistics Styles - DIPERBAIKI */
    .book-stats-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1px;
        background-color: #f8f9fa;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .stat-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1.25rem 0.75rem;
        background-color: white;
        text-align: center;
        min-height: 110px;
        transition: all 0.3s ease;
        border-right: 1px solid #f8f9fa;
        border-bottom: 1px solid #f8f9fa;
    }
    
    .stat-item:hover {
        background-color: #f8f9fa;
        transform: translateY(-2px);
    }
    
    .stat-item:nth-child(2n) {
        border-right: none;
    }
    
    .stat-item:nth-child(3),
    .stat-item:nth-child(4) {
        border-bottom: none;
    }
    
    .stat-icon-container {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.75rem;
        color: white;
        font-size: 1.25rem;
    }
    
    .stat-content {
        width: 100%;
    }
    
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        color: var(--gray-900);
        line-height: 1.2;
        word-break: break-word;
        overflow-wrap: break-word;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: var(--gray-600);
        font-weight: 500;
        line-height: 1.3;
        word-break: break-word;
        overflow-wrap: break-word;
        max-width: 100%;
    }
    
    /* Similar Books Styles */
    .similar-books {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .similar-book-item {
        display: block;
        padding: 12px;
        border-radius: 8px;
        border: 1px solid var(--gray-300);
        text-decoration: none;
        color: var(--gray-800);
        transition: all 0.3s ease;
    }
    
    .similar-book-item:hover {
        background: var(--primary-light);
        border-color: var(--primary-color);
        transform: translateY(-2px);
    }
    
    .similar-book-cover {
        width: 50px;
        height: 65px;
        border-radius: 5px;
        overflow: hidden;
        background: var(--gray-200);
    }
    
    .similar-book-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .similar-book-title {
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    /* Review Modal Styles */
    .rating-input {
        font-size: 2rem;
        color: #ffc107;
        cursor: pointer;
        text-align: center;
    }
    
    .star-review {
        margin: 0 5px;
        transition: all 0.2s ease;
    }
    
    .star-review:hover {
        transform: scale(1.2);
    }
    
    /* Responsive Styles */
    @media (max-width: 992px) {
        .rating-container {
            flex-wrap: wrap;
            justify-content: flex-start;
            align-items: flex-start;
        }
        
        .rating-divider {
            display: none;
        }
        
        .rate-book {
            margin-top: 5px;
        }
        
        /* Responsive untuk statistik buku */
        .book-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .stat-item {
            min-height: 100px;
            padding: 1rem 0.5rem;
        }
        
        .stat-number {
            font-size: 1.25rem;
        }
        
        .stat-icon-container {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }
    }

    @media (max-width: 768px) {
        .rating-container {
            flex-direction: row;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        
        .rating-display {
            width: 100%;
            margin-bottom: 8px;
            flex-direction: row;
            align-items: center;
            gap: 10px;
        }
        
        .rating-value {
            width: auto;
            margin-left: 0;
        }
        
        .rate-book {
            width: 100%;
            align-items: flex-start;
        }
        
        /* Responsive untuk statistik buku */
        .book-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .stat-item {
            padding: 0.75rem 0.5rem;
        }
        
        .stat-number {
            font-size: 1.1rem;
        }
        
        .stat-label {
            font-size: 0.7rem;
        }
    }

    @media (max-width: 576px) {
        .rating-display {
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
        }
        
        .stars-medium {
            font-size: 0.9rem;
        }
        
        .rating-stars-select {
            font-size: 0.9rem;
        }
        
        .rating-value {
            font-size: 0.7rem;
        }
        
        .rate-book h6 {
            font-size: 0.7rem;
        }
        
        /* Responsive untuk statistik buku */
        .book-stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5px;
        }
        
        .stat-item {
            min-height: 90px;
            padding: 0.75rem 0.25rem;
        }
        
        .stat-icon-container {
            width: 36px;
            height: 36px;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-number {
            font-size: 1rem;
        }
        
        .stat-label {
            font-size: 0.65rem;
            line-height: 1.2;
        }
    }

    @media (max-width: 360px) {
        .book-stats-grid {
            grid-template-columns: 1fr;
        }
        
        .stat-item {
            border-right: none !important;
            border-bottom: 1px solid #f8f9fa !important;
            min-height: 85px;
            padding: 0.5rem;
        }
        
        .stat-item:last-child {
            border-bottom: none !important;
        }
        
        .stat-icon-container {
            width: 32px;
            height: 32px;
            font-size: 0.85rem;
            margin-bottom: 0.4rem;
        }
        
        .stat-number {
            font-size: 0.9rem;
        }
        
        .stat-label {
            font-size: 0.6rem;
        }
    }
</style>

<script>
    // Rating stars interaction
    const stars = document.querySelectorAll('#ratingStars .star');
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            confirmAction('Beri rating ' + rating + ' bintang untuk buku ini?', function() {
                window.location.href = 'detail_buku.php?id=<?php echo $id_buku; ?>&action=rating&rating=' + rating;
            });
        });

        star.addEventListener('mouseover', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            stars.forEach((s, index) => {
                if (index < rating) {
                    s.classList.remove('far');
                    s.classList.add('fas');
                }
            });
        });

        star.addEventListener('mouseout', function() {
            const userRating = <?php echo $userRating; ?>;
            stars.forEach((s, index) => {
                if (index >= userRating) {
                    s.classList.remove('fas');
                    s.classList.add('far');
                }
            });
        });
    });
    
    // Review rating stars
    const reviewStars = document.querySelectorAll('.star-review');
    const selectedRatingInput = document.getElementById('selectedRating');
    
    reviewStars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            selectedRatingInput.value = rating;
            
            reviewStars.forEach((s, index) => {
                if (index < rating) {
                    s.classList.remove('far');
                    s.classList.add('fas');
                } else {
                    s.classList.remove('fas');
                    s.classList.add('far');
                }
            });
        });
        
        star.addEventListener('mouseover', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            reviewStars.forEach((s, index) => {
                if (index < rating) {
                    s.classList.remove('far');
                    s.classList.add('fas');
                }
            });
        });
        
        star.addEventListener('mouseout', function() {
            const currentRating = parseInt(selectedRatingInput.value);
            reviewStars.forEach((s, index) => {
                if (index < currentRating) {
                    s.classList.remove('far');
                    s.classList.add('fas');
                } else {
                    s.classList.remove('fas');
                    s.classList.add('far');
                }
            });
        });
    });
    
    // Review form submission
    document.getElementById('reviewForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const rating = formData.get('rating');
        
        if (rating === '0' || rating === 0) {
            showError('Silakan berikan rating terlebih dahulu');
            return;
        }
        
        showLoading();
        
        fetch('proses.php?action=submit_review', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                showSuccess(data.message);
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            Swal.close();
            showError('Terjadi kesalahan. Silakan coba lagi.');
        });
    });
    
    // Auto-dismiss alerts (exclude persistent alerts)
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            if (alert.classList.contains('show') && !alert.classList.contains('alert-persistent')) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        });
    }, 5000);
    
    // Function to format currency in JavaScript
    function formatCurrency(amount) {
        return 'Rp ' + amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // Function to open bayar denda modal
    function openBayarDendaModal(idPeminjaman, judulBuku, jumlahDenda) {
        document.getElementById('modalIdPeminjaman').value = idPeminjaman;
        document.getElementById('modalJumlahDenda').value = jumlahDenda;

        const dendaInfo = document.getElementById('dendaInfo');
        dendaInfo.innerHTML = `
            <div class="card border-warning">
                <div class="card-body">
                    <h6 class="card-title text-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Informasi Denda
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Buku:</strong> ${judulBuku}</p>
                            <p class="mb-0"><strong>Jumlah Denda:</strong> <span class="text-danger fw-bold">${formatCurrency(jumlahDenda)}</span></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>ID Peminjaman:</strong> ${idPeminjaman}</p>
                            <p class="mb-0"><strong>Status:</strong> <span class="badge bg-danger">Terlambat</span></p>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const modal = new bootstrap.Modal(document.getElementById('bayarDendaModal'));
        modal.show();
    }

    // Handle payment method change
    document.getElementById('metodeTunai').addEventListener('change', function() {
        document.getElementById('buktiTransferDiv').style.display = 'none';
        document.getElementById('buktiTransfer').required = false;
    });

    document.getElementById('metodeTransfer').addEventListener('change', function() {
        document.getElementById('buktiTransferDiv').style.display = 'block';
        document.getElementById('buktiTransfer').required = true;
    });

    // Handle form submission
    document.getElementById('submitBayarDenda').addEventListener('click', function() {
        const form = document.getElementById('bayarDendaForm');
        const formData = new FormData(form);

        // Validate form
        const metode = formData.get('metode');
        if (!metode) {
            showError('Silakan pilih metode pembayaran');
            return;
        }

        if (metode === 'transfer') {
            const buktiTransfer = formData.get('bukti_transfer');
            if (!buktiTransfer || buktiTransfer.size === 0) {
                showError('Silakan upload bukti transfer');
                return;
            }

            // Check file size (2MB max)
            if (buktiTransfer.size > 2 * 1024 * 1024) {
                showError('Ukuran file maksimal 2MB');
                return;
            }
        }

        showLoading('Mengirim pembayaran...');

        fetch('proses.php?action=bayar_denda', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                showSuccess(data.message);
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            Swal.close();
            showError('Terjadi kesalahan. Silakan coba lagi.');
        });
    });

    // Event listeners for buttons
    document.addEventListener('DOMContentLoaded', function() {
        const pinjamBtn = document.getElementById('pinjamBtn');
        const kembalikanBtn = document.getElementById('kembalikanBtn');

        if (pinjamBtn) {
            pinjamBtn.addEventListener('click', function() {
                confirmAction('Apakah Anda yakin ingin meminjam buku ini?', function() {
                    pinjamBuku();
                });
            });
        }

        if (kembalikanBtn) {
            kembalikanBtn.addEventListener('click', function() {
                confirmAction('Apakah Anda yakin ingin mengajukan pengembalian buku ini?', function() {
                    kembalikanBuku();
                });
            });
        }
    });

    // AJAX function to handle book borrowing
    function pinjamBuku() {
        showLoading('Mengajukan peminjaman...');
        
        const formData = new FormData();
        formData.append('id_buku', <?php echo $id_buku; ?>);
        
        fetch('proses.php?action=pinjam_buku', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                showSuccess(data.message);
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            Swal.close();
            showError('Terjadi kesalahan. Silakan coba lagi.');
        });
    }

    // AJAX function to handle book return
    function kembalikanBuku() {
        showLoading('Mengajukan pengembalian...');
        
        const formData = new FormData();
        formData.append('id_peminjaman', <?php echo $id_peminjaman ?? 0; ?>);
        
        fetch('proses.php?action=kembalikan_buku', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                showSuccess(data.message);
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                showError(data.message);
            }
        })
        .catch(error => {
            Swal.close();
            showError('Terjadi kesalahan. Silakan coba lagi.');
        });
    }

    // Utility functions for SweetAlert
    function confirmAction(message, callback, html = false) {
        Swal.fire({
            title: 'Konfirmasi',
            html: html ? message : null,
            text: !html ? message : null,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Lanjutkan',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                callback();
            }
        });
    }

    function showLoading(text = 'Memproses...') {
        Swal.fire({
            title: text,
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading();
            }
        });
    }

    function showSuccess(message) {
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: message,
            timer: 2000,
            showConfirmButton: false
        });
    }

    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Gagal!',
            text: message
        });
    }
</script>

<?php include '../templates/footer.php'; ?>