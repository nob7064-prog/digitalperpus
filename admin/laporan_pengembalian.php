<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$page_title = 'Laporan Pengembalian';
$page_icon = 'fas fa-undo';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_denda = $_GET['status_denda'] ?? '';
$anggota = $_GET['anggota'] ?? '';

$conn = getConnection();

// Build query
$whereClause = "WHERE DATE(pg.tanggal_kembali) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
$types = "ss";

if ($status_denda === 'ada') {
    $whereClause .= " AND pg.denda > 0";
} elseif ($status_denda === 'tidak') {
    $whereClause .= " AND pg.denda = 0";
}

if ($anggota) {
    $whereClause .= " AND p.id_anggota = ?";
    $params[] = $anggota;
    $types .= "i";
}

// Get return data with detailed information - FIXED QUERY
$sql = "SELECT pg.*, p.tanggal_pinjam, p.tanggal_jatuh_tempo, p.status as status_peminjaman,
               b.judul_buku, b.cover_buku, k.nama_kategori,
               a.username as nama_anggota,
               a.email as email_anggota,
               CASE
                   WHEN pg.terlambat_hari > 0 THEN 'Terlambat'
                   ELSE 'Tepat Waktu'
               END as status_keterlambatan
        FROM pengembalian pg
        JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
        JOIN buku b ON pg.id_buku = b.id_buku
        JOIN kategori k ON b.id_kategori = k.id_kategori
        JOIN anggota a ON p.id_anggota = a.id_anggota
        $whereClause
        ORDER BY pg.tanggal_kembali DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$returns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$statsSql = "SELECT 
                COUNT(*) as total,
                SUM(pg.denda) as total_denda,
                SUM(CASE WHEN pg.terlambat_hari > 0 THEN 1 ELSE 0 END) as total_terlambat,
                SUM(pg.terlambat_hari) as total_hari_terlambat,
                AVG(pg.terlambat_hari) as avg_hari_terlambat,
                COUNT(DISTINCT p.id_anggota) as total_anggota,
                COUNT(DISTINCT b.id_kategori) as total_kategori
             FROM pengembalian pg
             JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
             JOIN buku b ON pg.id_buku = b.id_buku
             $whereClause";

$statsStmt = $conn->prepare($statsSql);
$statsParams = array_merge([$start_date, $end_date], array_slice($params, 2));
$statsTypes = "ss" . substr($types, 2);
$statsStmt->bind_param($statsTypes, ...$statsParams);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// Get members for filter - FIXED: using username instead of nama_lengkap
$members = $conn->query("SELECT id_anggota, username, email FROM anggota ORDER BY username")->fetch_all(MYSQLI_ASSOC);

// Get monthly data for chart
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthSql = "SELECT 
                    COUNT(*) as total,
                    SUM(denda) as total_denda,
                    SUM(CASE WHEN terlambat_hari > 0 THEN 1 ELSE 0 END) as terlambat
                 FROM pengembalian 
                 WHERE DATE_FORMAT(tanggal_kembali, '%Y-%m') = ?";
    $monthStmt = $conn->prepare($monthSql);
    $monthStmt->bind_param("s", $month);
    $monthStmt->execute();
    $monthResult = $monthStmt->get_result()->fetch_assoc();
    $monthStmt->close();
    
    $monthlyData[] = [
        'month' => date('M Y', strtotime($month . '-01')),
        'total' => $monthResult['total'] ?? 0,
        'denda' => $monthResult['total_denda'] ?? 0,
        'terlambat' => $monthResult['terlambat'] ?? 0
    ];
}

// Get members with highest fines - FIXED: using username instead of nama_lengkap
$topDenda = $conn->query("
    SELECT a.username, a.email, SUM(pg.denda) as total_denda
    FROM pengembalian pg
    JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
    JOIN anggota a ON p.id_anggota = a.id_anggota
    WHERE DATE(pg.tanggal_kembali) BETWEEN '$start_date' AND '$end_date'
    GROUP BY a.id_anggota
    HAVING total_denda > 0
    ORDER BY total_denda DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get books with highest return fines
$topBooksDenda = $conn->query("
    SELECT b.judul_buku, k.nama_kategori, SUM(pg.denda) as total_denda
    FROM pengembalian pg
    JOIN buku b ON pg.id_buku = b.id_buku
    JOIN kategori k ON b.id_kategori = k.id_kategori
    WHERE DATE(pg.tanggal_kembali) BETWEEN '$start_date' AND '$end_date'
    GROUP BY b.id_buku
    HAVING total_denda > 0
    ORDER BY total_denda DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<?php include '../templates/header.php'; ?>
<?php include '../templates/admin_sidebar.php'; ?>
<?php include '../templates/admin_topbar.php'; ?>

<main class="main-content">
    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-undo me-2"></i>Laporan Pengembalian
                </h1>
                <p class="text-muted mb-0">Analisis data pengembalian buku</p>
            </div>
            <div>
                <button type="button" class="btn btn-success" onclick="exportReturnReport()">
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
                        <label class="form-label">Tanggal Mulai</label>
                        <input type="date" class="form-control" name="start_date" 
                               value="<?php echo $start_date; ?>" id="startDate">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Akhir</label>
                        <input type="date" class="form-control" name="end_date" 
                               value="<?php echo $end_date; ?>" id="endDate">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status Denda</label>
                        <select class="form-select" name="status_denda">
                            <option value="">Semua Status</option>
                            <option value="ada" <?php echo $status_denda == 'ada' ? 'selected' : ''; ?>>Ada Denda</option>
                            <option value="tidak" <?php echo $status_denda == 'tidak' ? 'selected' : ''; ?>>Tidak Ada Denda</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Anggota</label>
                        <select class="form-select" name="anggota">
                            <option value="">Semua Anggota</option>
                            <?php foreach ($members as $member): ?>
                            <option value="<?php echo $member['id_anggota']; ?>"
                                <?php echo $anggota == $member['id_anggota'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($member['username']); ?> (<?php echo htmlspecialchars($member['email']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Terapkan Filter
                            </button>
                            <a href="laporan_pengembalian.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-icon bg-primary">
                            <i class="fas fa-undo"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total'] ?? 0); ?></div>
                        <div class="stat-label">Total Pengembalian</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-icon bg-danger">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-number"><?php echo formatCurrency($stats['total_denda'] ?? 0); ?></div>
                        <div class="stat-label">Total Denda</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-icon bg-warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_terlambat'] ?? 0); ?></div>
                        <div class="stat-label">Pengembalian Terlambat</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-icon bg-info">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['avg_hari_terlambat'] ?? 0, 1); ?></div>
                        <div class="stat-label">Rata-rata Terlambat (hari)</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <!-- Chart Section -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Trend Pengembalian & Denda 6 Bulan Terakhir
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="returnTrendChart" height="250"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Statistics -->
            <div class="col-lg-4">
                <!-- Top Members with Fines -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-times me-2"></i>Anggota dengan Denda Tertinggi
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="top-list">
                            <?php foreach ($topDenda as $index => $member): ?>
                            <div class="top-item mb-3">
                                <div class="d-flex align-items-center">
                                    <div class="rank me-3">#<?php echo $index + 1; ?></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($member['username']); ?></div>
                                        <div class="text-muted" style="font-size: 0.8rem;"><?php echo htmlspecialchars($member['email']); ?></div>
                                    </div>
                                    <div class="text-danger fw-bold"><?php echo formatCurrency($member['total_denda']); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Books with Highest Fines -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-book me-2"></i>Buku dengan Denda Tertinggi
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="top-list">
                            <?php foreach ($topBooksDenda as $index => $book): ?>
                            <div class="top-item mb-3">
                                <div class="d-flex align-items-center">
                                    <div class="rank me-3">#<?php echo $index + 1; ?></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($book['judul_buku']); ?></div>
                                        <div class="text-muted" style="font-size: 0.8rem;"><?php echo htmlspecialchars($book['nama_kategori']); ?></div>
                                    </div>
                                    <div class="text-danger fw-bold"><?php echo formatCurrency($book['total_denda']); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    Detail Pengembalian
                    <span class="badge bg-primary ms-2"><?php echo count($returns); ?> transaksi</span>
                </h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="printReturnTable()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive" id="returnTable">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Buku</th>
                                <th>Anggota</th>
                                <th>Tanggal Pinjam</th>
                                <th>Jatuh Tempo</th>
                                <th>Tanggal Kembali</th>
                                <th>Terlambat</th>
                                <th>Denda</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returns)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <i class="fas fa-undo fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">Tidak ada data pengembalian</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($returns as $index => $return): 
                                    $status_class = $return['terlambat_hari'] > 0 ? 'danger' : 'success';
                                    $status_text = $return['status_keterlambatan'];
                                ?>
                                <tr class="<?php echo $return['terlambat_hari'] > 0 ? 'table-warning' : ''; ?>">
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="book-cover-mini me-3">
                                                <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $return['cover_buku'] ?: 'default.jpg'; ?>" 
                                                     alt="<?php echo htmlspecialchars($return['judul_buku']); ?>">
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($return['judul_buku']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($return['nama_kategori']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($return['nama_anggota']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($return['email_anggota']); ?></small>
                                    </td>
                                    <td><?php echo formatDate($return['tanggal_pinjam']); ?></td>
                                    <td><?php echo formatDate($return['tanggal_jatuh_tempo']); ?></td>
                                    <td>
                                        <div><?php echo formatDate($return['tanggal_kembali']); ?></div>
                                        <small class="text-muted"><?php echo formatDate($return['tanggal_kembali'], 'H:i'); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($return['terlambat_hari'] > 0): ?>
                                        <span class="text-danger fw-bold"><?php echo $return['terlambat_hari']; ?> hari</span>
                                        <?php else: ?>
                                        <span class="text-success">Tepat waktu</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($return['denda'] > 0): ?>
                                        <div class="text-danger fw-bold"><?php echo formatCurrency($return['denda']); ?></div>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Summary Report -->
        <div class="card mt-4">
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
                            <span class="summary-label">Total Pengembalian</span>
                            <span class="summary-value"><?php echo number_format($stats['total'] ?? 0); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Rata-rata per Hari</span>
                            <span class="summary-value">
                                <?php 
                                $days = max(1, (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1);
                                echo number_format(($stats['total'] ?? 0) / $days, 2); ?> transaksi/hari
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-item">
                            <span class="summary-label">Total Denda</span>
                            <span class="summary-value"><?php echo formatCurrency($stats['total_denda'] ?? 0); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Pengembalian Terlambat</span>
                            <span class="summary-value">
                                <?php echo number_format($stats['total_terlambat'] ?? 0); ?> 
                                (<?php echo ($stats['total'] ?? 0) > 0 ? round((($stats['total_terlambat'] ?? 0) / ($stats['total'] ?? 0)) * 100, 1) : 0; ?>%)
                            </span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Total Hari Terlambat</span>
                            <span class="summary-value"><?php echo number_format($stats['total_hari_terlambat'] ?? 0); ?> hari</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-item">
                            <span class="summary-label">Tanggal Laporan</span>
                            <span class="summary-value"><?php echo date('d/m/Y H:i'); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Filter Aktif</span>
                            <span class="summary-value">
                                <?php 
                                $filters = [];
                                if ($status_denda) $filters[] = "Denda: " . ($status_denda == 'ada' ? 'Ada' : 'Tidak Ada');
                                if ($anggota) {
                                    $anggota_name = '';
                                    foreach ($members as $member) {
                                        if ($member['id_anggota'] == $anggota) {
                                            $anggota_name = $member['username'];
                                            break;
                                        }
                                    }
                                    $filters[] = "Anggota: " . htmlspecialchars($anggota_name);
                                }
                                echo $filters ? implode(', ', $filters) : 'Tidak ada filter';
                                ?>
                            </span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Total Anggota & Kategori</span>
                            <span class="summary-value"><?php echo $stats['total_anggota'] ?? 0; ?> anggota, <?php echo $stats['total_kategori'] ?? 0; ?> kategori</span>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Statistics -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h6>Distribusi Keterlambatan</h6>
                        <div class="progress-stacked mb-3" style="height: 30px;">
                            <div class="progress" style="width: <?php echo ($stats['total'] ?? 0) > 0 ? ((($stats['total'] ?? 0) - ($stats['total_terlambat'] ?? 0)) / ($stats['total'] ?? 0)) * 100 : 0; ?>%">
                                <div class="progress-bar bg-success" role="progressbar">
                                    Tepat Waktu (<?php echo ($stats['total'] ?? 0) - ($stats['total_terlambat'] ?? 0); ?>)
                                </div>
                            </div>
                            <div class="progress" style="width: <?php echo ($stats['total'] ?? 0) > 0 ? (($stats['total_terlambat'] ?? 0) / ($stats['total'] ?? 0)) * 100 : 0; ?>%">
                                <div class="progress-bar bg-danger" role="progressbar">
                                    Terlambat (<?php echo $stats['total_terlambat'] ?? 0; ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>Distribusi Denda</h6>
                        <?php 
                        $with_fine = count(array_filter($returns, function($r) { 
                            return ($r['denda'] ?? 0) > 0; 
                        }));
                        $without_fine = count($returns) - $with_fine;
                        ?>
                        <div class="progress-stacked mb-3" style="height: 30px;">
                            <div class="progress" style="width: <?php echo count($returns) > 0 ? ($without_fine / count($returns)) * 100 : 0; ?>%">
                                <div class="progress-bar bg-info" role="progressbar">
                                    Tanpa Denda (<?php echo $without_fine; ?>)
                                </div>
                            </div>
                            <div class="progress" style="width: <?php echo count($returns) > 0 ? ($with_fine / count($returns)) * 100 : 0; ?>%">
                                <div class="progress-bar bg-warning" role="progressbar">
                                    Ada Denda (<?php echo $with_fine; ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    /* Main content layout */
    .main-content {
        margin-left: 250px;
        padding: 2rem;
        min-height: calc(100vh - 70px);
        width: calc(100% - 250px);
        background-color: #f8fafc;
    }
    
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 1rem;
        }
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
    
    .book-cover-mini {
        width: 40px;
        height: 60px;
        overflow: hidden;
        border-radius: 5px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .book-cover-mini img {
        width: 100%;
        height: 100%;
        object-fit: cover;
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
    
    .badge-danger {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .badge-success {
        background-color: #d1e7dd;
        color: #0f5132;
    }
    
    .badge-warning {
        background-color: #fff3cd;
        color: #856404;
    }
</style>

<script>
    // Return Trend Chart
    const returnCtx = document.getElementById('returnTrendChart').getContext('2d');
    const returnTrendChart = new Chart(returnCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($monthlyData, 'month')); ?>,
            datasets: [
                {
                    label: 'Total Pengembalian',
                    data: <?php echo json_encode(array_column($monthlyData, 'total')); ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                    borderColor: '#3b82f6',
                    borderWidth: 1
                },
                {
                    label: 'Total Denda (Rp)',
                    data: <?php echo json_encode(array_column($monthlyData, 'denda')); ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.7)',
                    borderColor: '#ef4444',
                    borderWidth: 1,
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
    
    // Export return report to Excel
    function exportReturnReport() {
        Swal.fire({
            title: 'Export Excel',
            text: 'Sedang mempersiapkan laporan Excel...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Create CSV content
        let csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "Laporan Pengembalian - Digital Library\n";
        csvContent += "Periode: <?php echo formatDate($start_date) . ' - ' . formatDate($end_date); ?>\n";
        csvContent += "Tanggal: " + new Date().toLocaleDateString() + "\n\n";
        
        // Headers
        csvContent += "No,Judul Buku,Kategori,Anggota,Email Anggota,Tanggal Pinjam,Jatuh Tempo,Tanggal Kembali,Terlambat (hari),Denda,Status Keterlambatan\n";
        
        // Data rows
        <?php foreach ($returns as $index => $return): ?>
        csvContent += "<?php echo ($index + 1) . ',' .
            '"' . addslashes($return['judul_buku']) . '",' .
            '"' . addslashes($return['nama_kategori']) . '",' .
            '"' . addslashes($return['nama_anggota']) . '",' .
            '"' . addslashes($return['email_anggota']) . '",' .
            $return['tanggal_pinjam'] . ',' .
            $return['tanggal_jatuh_tempo'] . ',' .
            $return['tanggal_kembali'] . ',' .
            $return['terlambat_hari'] . ',' .
            $return['denda'] . ',' .
            '"' . addslashes($return['status_keterlambatan']) . '"'; ?>\n";
        <?php endforeach; ?>
        
        // Summary
        csvContent += "\n\nSUMMARY\n";
        csvContent += "Total Pengembalian,<?php echo $stats['total'] ?? 0; ?>\n";
        csvContent += "Total Denda,<?php echo $stats['total_denda'] ?? 0; ?>\n";
        csvContent += "Pengembalian Terlambat,<?php echo $stats['total_terlambat'] ?? 0; ?>\n";
        csvContent += "Total Hari Terlambat,<?php echo $stats['total_hari_terlambat'] ?? 0; ?>\n";
        csvContent += "Rata-rata Terlambat (hari),<?php echo number_format($stats['avg_hari_terlambat'] ?? 0, 1); ?>\n";
        csvContent += "Total Anggota,<?php echo $stats['total_anggota'] ?? 0; ?>\n";
        
        setTimeout(() => {
            Swal.close();
            
            // Create download link
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "laporan_pengembalian_<?php echo date('Y-m-d'); ?>.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            Swal.fire('Success!', 'Laporan berhasil diexport', 'success');
        }, 1500);
    }
    
    // Print return table
    function printReturnTable() {
        const printContent = document.getElementById('returnTable').innerHTML;
        const originalContent = document.body.innerHTML;
        
        document.body.innerHTML = `
            <html>
                <head>
                    <title>Laporan Pengembalian</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 11px; }
                        th, td { border: 1px solid #ddd; padding: 5px; text-align: left; }
                        th { background-color: #f8f9fa; font-weight: bold; }
                        .print-header { text-align: center; margin-bottom: 20px; }
                        .print-header h2 { margin: 0; color: #333; font-size: 16px; }
                        .print-header p { margin: 5px 0; color: #666; font-size: 13px; }
                        .print-date { text-align: right; margin-bottom: 15px; font-size: 11px; }
                        .summary { margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 5px; font-size: 11px; }
                        .summary h4 { margin-top: 0; font-size: 13px; }
                        .badge { padding: 2px 6px; border-radius: 3px; font-size: 9px; }
                        .badge-success { background: #d1fae5; color: #065f46; }
                        .badge-danger { background: #fee2e2; color: #991b1b; }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h2>Laporan Pengembalian Buku - Digital Library</h2>
                        <p>Periode: <?php echo formatDate($start_date) . ' - ' . formatDate($end_date); ?></p>
                    </div>
                    
                    <div class="print-date">
                        <p>Tanggal Cetak: <?php echo date('d/m/Y H:i'); ?></p>
                        <p>Total Data: <?php echo count($returns); ?> transaksi</p>
                    </div>
                    
                    ${printContent}
                    
                    <div class="summary">
                        <h4>Ringkasan Statistik:</h4>
                        <p>Total Pengembalian: <?php echo number_format($stats['total'] ?? 0); ?></p>
                        <p>Total Denda: <?php echo formatCurrency($stats['total_denda'] ?? 0); ?></p>
                        <p>Pengembalian Terlambat: <?php echo number_format($stats['total_terlambat'] ?? 0); ?> (<?php echo ($stats['total'] ?? 0) > 0 ? round((($stats['total_terlambat'] ?? 0) / ($stats['total'] ?? 0)) * 100, 1) : 0; ?>%)</p>
                        <p>Total Hari Terlambat: <?php echo number_format($stats['total_hari_terlambat'] ?? 0); ?> hari</p>
                        <p>Rata-rata Terlambat: <?php echo number_format($stats['avg_hari_terlambat'] ?? 0, 1); ?> hari</p>
                    </div>
                </body>
            </html>
        `;
        
        window.print();
        document.body.innerHTML = originalContent;
    }
</script>

<?php include '../templates/footer.php'; ?>