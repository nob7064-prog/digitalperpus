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
$page_title = 'Daftar Buku';
$page_icon = 'fas fa-book';

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$kategori = $_GET['kategori'] ?? '';
$sort = $_GET['sort'] ?? 'terbaru';
$page = $_GET['page'] ?? 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$conn = getConnection();
$whereClause = "WHERE b.stok > 0";
$params = [];
$types = "";

if ($search) {
    $whereClause .= " AND (b.judul_buku LIKE ? OR p.nama_penulis LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

if ($kategori) {
    $whereClause .= " AND k.nama_kategori = ?";
    $params[] = $kategori;
    $types .= "s";
}

// Sort order
$orderBy = "ORDER BY ";
switch ($sort) {
    case 'rating':
        $orderBy .= "b.rata_rating DESC";
        break;
    case 'populer':
        $orderBy .= "b.jumlah_pinjam DESC";
        break;
    case 'abjad':
        $orderBy .= "b.judul_buku ASC";
        break;
    case 'terbaru':
    default:
        $orderBy .= "b.created_at DESC";
        break;
}

// Get categories for filter
$categories = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori")->fetch_all(MYSQLI_ASSOC);

// Get books
$sql = "SELECT b.*, k.nama_kategori, p.nama_penulis, 
        COALESCE(AVG(r.rating), 0) as rating_avg,
        COUNT(r.id_rating) as rating_count
        FROM buku b
        LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
        LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
        LEFT JOIN rating r ON b.id_buku = r.id_buku
        $whereClause
        GROUP BY b.id_buku
        $orderBy
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total books for pagination
$countSql = "SELECT COUNT(DISTINCT b.id_buku) as total FROM buku b
             LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
             LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
             $whereClause";
             
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countTypes = substr($types, 0, -2);
    $countParams = array_slice($params, 0, -2);
    if ($countTypes) {
        $countStmt->bind_param($countTypes, ...$countParams);
    }
}
$countStmt->execute();
$totalResult = $countStmt->get_result();
$totalBooks = $totalResult->fetch_assoc()['total'];
$totalPages = ceil($totalBooks / $limit);

$conn->close();
?>

<?php include '../templates/header.php'; ?>
<?php include '../templates/anggota_sidebar.php'; ?>
<?php include '../templates/anggota_topbar.php'; ?>

<main class="main-content">
    <div class="container-fluid py-4">

        <!-- Search and Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" 
                                   name="search" placeholder="Cari buku berdasarkan judul atau penulis..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="kategori">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['nama_kategori']); ?>"
                                <?php echo $kategori == $cat['nama_kategori'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['nama_kategori']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="sort">
                            <option value="terbaru" <?php echo $sort == 'terbaru' ? 'selected' : ''; ?>>Terbaru</option>
                            <option value="rating" <?php echo $sort == 'rating' ? 'selected' : ''; ?>>Rating Tertinggi</option>
                            <option value="populer" <?php echo $sort == 'populer' ? 'selected' : ''; ?>>Paling Populer</option>
                            <option value="abjad" <?php echo $sort == 'abjad' ? 'selected' : ''; ?>>A-Z</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                            <a href="daftar_buku.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results Info -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-0">Menampilkan <?php echo count($books); ?> dari <?php echo $totalBooks; ?> buku</h5>
            </div>
            <div>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary active" id="gridView">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="listView">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Books Grid -->
        <div id="booksGrid" class="row">
            <?php
            // Check if user has overdue loans or unpaid fines; if so, block some actions
            $userHasOverdue = false;
            $userHasUnpaidDenda = false;
            $connCheck = getConnection();
            $userIdCheck = $_SESSION['user_id'];
            $overdueStmt = $connCheck->prepare("SELECT COUNT(*) as c FROM peminjaman WHERE id_anggota = ? AND status = 'terlambat'");
            $overdueStmt->bind_param("i", $userIdCheck);
            $overdueStmt->execute();
            $overdueRes = $overdueStmt->get_result()->fetch_assoc();
            $userHasOverdue = intval($overdueRes['c']) > 0;
            $overdueStmt->close();

            $dendaStmt = $connCheck->prepare("SELECT COUNT(*) as c FROM denda WHERE id_anggota = ? AND status = 'belum_lunas'");
            $dendaStmt->bind_param("i", $userIdCheck);
            $dendaStmt->execute();
            $dendaRes = $dendaStmt->get_result()->fetch_assoc();
            $userHasUnpaidDenda = intval($dendaRes['c']) > 0;
            $dendaStmt->close();
            $connCheck->close();
            ?>
            <?php if (empty($books)): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted mb-3">Tidak ada buku ditemukan</h4>
                        <p class="text-muted mb-0">Coba gunakan kata kunci pencarian yang berbeda</p>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($books as $book): 
                    // Get book status
                    $status = 'kosong';
                    $conn = getConnection();
                    $statusSql = "SELECT status FROM peminjaman 
                                 WHERE id_buku = ? AND id_anggota = ? 
                                 AND status IN ('dipinjam', 'terlambat')
                                 ORDER BY created_at DESC LIMIT 1";
                    $statusStmt = $conn->prepare($statusSql);
                    $statusStmt->bind_param("ii", $book['id_buku'], $_SESSION['user_id']);
                    $statusStmt->execute();
                    $statusResult = $statusStmt->get_result();
                    
                    if ($statusResult->num_rows > 0) {
                        $statusRow = $statusResult->fetch_assoc();
                        $status = $statusRow['status'];
                    }
                    $statusStmt->close();
                    $conn->close();
                    
                    $status_class = '';
                    $status_text = '';
                    
                    if ($status === 'kosong') {
                        $status_class = 'success';
                        $status_text = 'Tersedia';
                    } elseif ($status === 'dipinjam') {
                        $status_class = 'warning';
                        $status_text = 'Dipinjam';
                    } else {
                        $status_class = 'danger';
                        $status_text = 'Terlambat';
                    }
                    
                    $rating_avg = floatval($book['rating_avg']);
                    $rating_count = intval($book['rating_count']);
                ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="book-card">
                        <div class="book-card-header">
                            <div class="book-cover">
                                <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo htmlspecialchars($book['cover_buku']); ?>" 
                                     alt="<?php echo htmlspecialchars($book['judul_buku']); ?>"
                                     onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                <div class="book-status">
                                    <span class="badge badge-<?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                                <div class="book-actions">
                                    <a href="detail_buku.php?id=<?php echo $book['id_buku']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                </div>
                            </div>
                        </div>
                        <div class="book-card-body">
                            <h5 class="book-title"><?php echo htmlspecialchars($book['judul_buku']); ?></h5>
                            <p class="book-author text-muted">
                                <i class="fas fa-user-edit me-1"></i>
                                <?php echo htmlspecialchars($book['nama_penulis'] ?? 'Penulis Tidak Diketahui'); ?>
                            </p>
                            <div class="book-meta">
                                <span class="book-category">
                                    <i class="fas fa-tag me-1"></i>
                                    <?php echo htmlspecialchars($book['nama_kategori'] ?? 'Umum'); ?>
                                </span>
                            </div>
                            <div class="book-rating">
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= floor($rating_avg)): ?>
                                            <i class="fas fa-star text-warning"></i>
                                        <?php elseif ($i <= ceil($rating_avg) && $rating_avg - floor($rating_avg) >= 0.5): ?>
                                            <i class="fas fa-star-half-alt text-warning"></i>
                                        <?php else: ?>
                                            <i class="far fa-star text-warning"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <small class="text-muted ms-1">(<?php echo $rating_count; ?>)</small>
                                </div>
                                <!-- stock display removed for anggota view -->
                            </div>
                        </div>
                        <div class="book-card-footer">
                            <a href="detail_buku.php?id=<?php echo $book['id_buku']; ?>" class="btn btn-primary w-100">
                                <i class="fas fa-info-circle me-2"></i>Detail
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Books List (Hidden by default) -->
        <div id="booksList" class="d-none">
            <?php if (!empty($books)): ?>
                <?php foreach ($books as $book): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <div class="book-cover-list">
                                    <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo htmlspecialchars($book['cover_buku']); ?>" 
                                         alt="<?php echo htmlspecialchars($book['judul_buku']); ?>"
                                         onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 class="mb-1"><?php echo htmlspecialchars($book['judul_buku']); ?></h5>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-user-edit me-1"></i>
                                    <?php echo htmlspecialchars($book['nama_penulis'] ?? 'Penulis Tidak Diketahui'); ?>
                                </p>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-tag me-1"></i>
                                        <?php echo htmlspecialchars($book['nama_kategori'] ?? 'Umum'); ?>
                                    </span>
                                    <!-- year and stock badges removed for anggota view -->
                                </div>
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= floor($rating_avg)): ?>
                                            <i class="fas fa-star text-warning"></i>
                                        <?php elseif ($i <= ceil($rating_avg) && $rating_avg - floor($rating_avg) >= 0.5): ?>
                                            <i class="fas fa-star-half-alt text-warning"></i>
                                        <?php else: ?>
                                            <i class="far fa-star text-warning"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <small class="text-muted ms-1">(<?php echo $rating_count; ?> rating)</small>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                    <?php
                                    // Determine if user borrowed this book (for list view actions)
                                    $connList = getConnection();
                                    $statusSqlList = "SELECT status FROM peminjaman WHERE id_buku = ? AND id_anggota = ? AND status IN ('dipinjam','terlambat') ORDER BY created_at DESC LIMIT 1";
                                    $statusStmtList = $connList->prepare($statusSqlList);
                                    $statusStmtList->bind_param("ii", $book['id_buku'], $_SESSION['user_id']);
                                    $statusStmtList->execute();
                                    $statusResList = $statusStmtList->get_result();
                                    $listStatus = 'kosong';
                                    if ($statusResList->num_rows > 0) {
                                        $listStatus = $statusResList->fetch_assoc()['status'];
                                    }
                                    $statusStmtList->close();
                                    $connList->close();

                                    if ($userHasOverdue || $userHasUnpaidDenda): ?>
                                        <a href="#" class="btn btn-danger me-2" data-bs-toggle="modal" data-bs-target="#dendaModal">
                                            <i class="fas fa-exclamation-triangle me-2"></i>Bayar Denda
                                        </a>
                                    <?php else: ?>
                                        <?php if ($listStatus === 'dipinjam' && !empty($book['file_pdf'])): ?>
                                            <a href="read_pdf.php?id=<?php echo $book['id_buku']; ?>" target="_blank" class="btn btn-success me-2">
                                                <i class="fas fa-book-open me-2"></i>Baca Buku
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <a href="detail_buku.php?id=<?php echo $book['id_buku']; ?>" class="btn btn-primary">
                                        <i class="fas fa-eye me-2"></i>Detail Buku
                                    </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori); ?>&sort=<?php echo $sort; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori); ?>&sort=<?php echo $sort; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                    <li class="page-item disabled">
                        <span class="page-link">...</span>
                    </li>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&kategori=<?php echo urlencode($kategori); ?>&sort=<?php echo $sort; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</main>

<!-- Tambahkan CSS khusus untuk tata letak -->
<style>
    /* Atur lebar konten utama */
    .main-content {
        margin-left: var(--sidebar-width);
        margin-top: var(--topbar-height);
        padding: 2rem;
        min-height: calc(100vh - var(--topbar-height));
        transition: var(--transition);
        width: calc(100% - var(--sidebar-width));
    }
    
    /* Responsif untuk layar kecil */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 1.5rem;
        }
    }
    
    /* Atur container agar tidak terpotong */
    .container-fluid {
        max-width: 100%;
        padding-left: 0;
        padding-right: 0;
    }
    
    /* Atur row dan column untuk lebar penuh */
    .row {
        margin-left: 0;
        margin-right: 0;
    }
    
    .col-xl-3, .col-lg-4, .col-md-6, .col-12 {
        padding-left: 15px;
        padding-right: 15px;
    }
    
    /* Pastikan semua card mengambil lebar penuh */
    .card {
        width: 100%;
        box-shadow: var(--box-shadow);
    }
    
    /* Responsif untuk book cards */
    @media (max-width: 768px) {
        .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        .book-card-header {
            height: 180px;
        }
    }
    
    @media (min-width: 769px) and (max-width: 992px) {
        .col-lg-4 {
            flex: 0 0 50%;
            max-width: 50%;
        }
    }
    
    /* Book card yang sudah ada, tambahkan untuk memastikan lebar konsisten */
    .book-card {
        background: white;
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: var(--box-shadow);
        transition: var(--transition);
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    
    /* Pagination responsive */
    @media (max-width: 576px) {
        .pagination {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .pagination .page-item {
            margin-bottom: 5px;
        }
    }
    
    /* Search form responsive */
    @media (max-width: 768px) {
        .col-md-3, .col-md-6 {
            margin-bottom: 1rem;
        }
        
        .d-flex.justify-content-between.align-items-center {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .d-flex.justify-content-between.align-items-center > div {
            width: 100%;
            margin-bottom: 1rem;
        }
        
        .btn-group {
            align-self: flex-end;
        }
    }
</style>

<script>
    // Pastikan fungsi view toggle tetap bekerja
    document.addEventListener('DOMContentLoaded', function() {
        const gridView = document.getElementById('gridView');
        const listView = document.getElementById('listView');
        const booksGrid = document.getElementById('booksGrid');
        const booksList = document.getElementById('booksList');
        
        if (gridView && listView) {
            gridView.addEventListener('click', function() {
                this.classList.add('active');
                listView.classList.remove('active');
                booksGrid.classList.remove('d-none');
                booksList.classList.add('d-none');
            });
            
            listView.addEventListener('click', function() {
                this.classList.add('active');
                gridView.classList.remove('active');
                booksGrid.classList.add('d-none');
                booksList.classList.remove('d-none');
            });
        }
    });
</script>

<script>
    // View toggle
    document.getElementById('gridView').addEventListener('click', function() {
        this.classList.add('active');
        document.getElementById('listView').classList.remove('active');
        document.getElementById('booksGrid').classList.remove('d-none');
        document.getElementById('booksList').classList.add('d-none');
    });
    
    document.getElementById('listView').addEventListener('click', function() {
        this.classList.add('active');
        document.getElementById('gridView').classList.remove('active');
        document.getElementById('booksGrid').classList.add('d-none');
        document.getElementById('booksList').classList.remove('d-none');
    });
</script>

<!-- Tambahkan CSS khusus untuk tata letak -->
<style>
    /* Reset dan layout dasar */
    :root {
        --sidebar-width: 250px;
        --topbar-height: 70px;
        --primary-color: #4361ee;
        --secondary-color: #3a0ca3;
        --border-radius: 10px;
        --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    * {
        box-sizing: border-box;
    }
    
    /* Main content layout - FIXED */
    .main-content {
        margin-left: var(--sidebar-width);
        margin-top: var(--topbar-height);
        padding: 2rem;
        min-height: calc(100vh - var(--topbar-height));
        width: calc(100% - var(--sidebar-width));
        background-color: #f8fafc;
    }
    
    /* Container fluid */
    .container-fluid {
        width: 100%;
        max-width: 100%;
        padding-left: 0;
        padding-right: 0;
    }
    
    /* Page header */
    .page-title {
        font-size: 1.75rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 0.25rem;
    }
    
    /* Cards styling */
    .card {
        border: 1px solid #e5e7eb;
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        margin-bottom: 1.5rem;
        background: white;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    /* Book cards grid - FIXED LAYOUT */
    .row {
        display: flex;
        flex-wrap: wrap;
        margin-left: -15px;
        margin-right: -15px;
    }
    
    .col-xl-3, .col-lg-4, .col-md-6 {
        padding-left: 15px;
        padding-right: 15px;
        margin-bottom: 2rem;
    }
    
    /* Book card styling - FIXED untuk tidak berantakan */
    .book-card {
        background: white;
        border-radius: var(--border-radius);
        border: 1px solid #e5e7eb;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    
    .book-card:hover {
        box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        transform: translateY(-5px);
    }
    
    .book-card-header {
        position: relative;
        height: 220px;
        overflow: hidden;
        background: #f3f4f6;
    }
    
    .book-cover {
        width: 100%;
        height: 100%;
    }
    
    .book-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    
    .book-card:hover .book-cover img {
        transform: scale(1.05);
    }
    
    .book-status {
        position: absolute;
        top: 10px;
        right: 10px;
    }
    
    .book-actions {
        position: absolute;
        top: 10px;
        left: 10px;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .book-card:hover .book-actions {
        opacity: 1;
    }
    
    .book-card-body {
        padding: 1.25rem;
        flex-grow: 1;
    }
    
    .book-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 0.5rem;
        line-height: 1.4;
        height: 3.2rem;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    
    .book-author {
        font-size: 0.9rem;
        color: #6b7280;
        margin-bottom: 1rem;
    }
    
    .book-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        font-size: 0.85rem;
        color: #6b7280;
    }
    
    .book-category, .book-year {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    
    .book-rating {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: auto;
    }
    
    .stars {
        color: #f59e0b;
        font-size: 0.9rem;
    }
    
    .book-stock {
        font-size: 0.85rem;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    
    .book-card-footer {
        padding: 1rem 1.25rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .book-card-footer .btn {
        width: 100%;
    }
    
    /* Badges */
    .badge {
        padding: 0.4em 0.8em;
        font-weight: 500;
        font-size: 0.85em;
        border-radius: 6px;
    }
    
    .badge-success {
        background-color: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .badge-warning {
        background-color: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    
    .badge-danger {
        background-color: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    /* Buttons */
    .btn {
        border-radius: 8px;
        font-weight: 500;
        padding: 0.5rem 1rem;
    }
    
    .btn-primary {
        background-color: #3b82f6;
        border-color: #3b82f6;
    }
    
    .btn-primary:hover {
        background-color: #2563eb;
        border-color: #2563eb;
    }
    
    .btn-outline-primary {
        color: #3b82f6;
        border-color: #3b82f6;
    }
    
    .btn-outline-primary:hover {
        background-color: #3b82f6;
        color: white;
    }
    
    /* Pagination */
    .pagination {
        margin-top: 2rem;
    }
    
    .page-link {
        border-radius: 8px;
        margin: 0 4px;
        border: 1px solid #e5e7eb;
        color: #6b7280;
    }
    
    .page-item.active .page-link {
        background-color: #3b82f6;
        border-color: #3b82f6;
    }
    
    /* List view styling */
    #booksList .card {
        margin-bottom: 1rem;
    }
    
    .book-cover-list {
        width: 80px;
        height: 110px;
        border-radius: 8px;
        overflow: hidden;
        background: #f3f4f6;
    }
    
    .book-cover-list img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    /* View toggle buttons */
    .btn-group .btn {
        padding: 0.5rem 1rem;
    }
    
    .btn-group .btn.active {
        background-color: #3b82f6;
        color: white;
    }
    
    /* Responsive design */
    @media (max-width: 1200px) {
        .col-xl-3 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
        }
    }
    
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 1rem;
        }
        
        .col-lg-4 {
            flex: 0 0 50%;
            max-width: 50%;
        }
        
        .book-card-header {
            height: 200px;
        }
    }
    
    @media (max-width: 768px) {
        .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        .book-card-header {
            height: 180px;
        }
        
        .d-flex.justify-content-between.align-items-center {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 1rem;
        }
        
        .btn-group {
            align-self: flex-end;
        }
        
        /* Search form responsive */
        .col-md-3, .col-md-6 {
            margin-bottom: 1rem;
        }
    }
    
    @media (max-width: 576px) {
        .main-content {
            padding: 0.75rem;
        }
        
        .book-card-header {
            height: 160px;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        .book-card-body {
            padding: 1rem;
        }
        
        .book-title {
            font-size: 1rem;
            height: 2.8rem;
        }
        
        .pagination {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .pagination .page-item {
            margin-bottom: 0.5rem;
        }
    }
</style>

<script>
    // View toggle
    document.addEventListener('DOMContentLoaded', function() {
        const gridView = document.getElementById('gridView');
        const listView = document.getElementById('listView');
        const booksGrid = document.getElementById('booksGrid');
        const booksList = document.getElementById('booksList');
        
        if (gridView && listView) {
            gridView.addEventListener('click', function() {
                this.classList.add('active');
                listView.classList.remove('active');
                booksGrid.classList.remove('d-none');
                booksList.classList.add('d-none');
            });
            
            listView.addEventListener('click', function() {
                this.classList.add('active');
                gridView.classList.remove('active');
                booksGrid.classList.add('d-none');
                booksList.classList.remove('d-none');
            });
        }
    });
</script>

<?php include '../templates/footer.php'; ?>