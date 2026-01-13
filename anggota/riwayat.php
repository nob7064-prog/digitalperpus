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
$page_title = 'Riwayat Peminjaman';
$page_icon = 'fas fa-history';

// Get filter parameters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$conn = getConnection();
$userId = $_SESSION['user_id'];

// Build query
$whereClause = "WHERE p.id_anggota = ?";
$params = [$userId];
$types = "i";

if ($status) {
    $whereClause .= " AND p.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($search) {
    $whereClause .= " AND (b.judul_buku LIKE ? OR b.judul_buku LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

if ($start_date) {
    $whereClause .= " AND p.tanggal_pinjam >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if ($end_date) {
    $whereClause .= " AND p.tanggal_pinjam <= ?";
    $params[] = $end_date;
    $types .= "s";
}

// Get borrowing history
$sql = "SELECT p.*, b.judul_buku, b.cover_buku, k.nama_kategori,
        (SELECT COUNT(*) FROM pengembalian pg WHERE pg.id_peminjaman = p.id_peminjaman) as sudah_kembali,
        CASE
            WHEN p.status = 'terlambat' AND (SELECT denda FROM pengembalian pg WHERE pg.id_peminjaman = p.id_peminjaman) IS NOT NULL
                THEN (SELECT denda FROM pengembalian pg WHERE pg.id_peminjaman = p.id_peminjaman)
            WHEN CURDATE() > p.tanggal_jatuh_tempo
                THEN DATEDIFF(CURDATE(), p.tanggal_jatuh_tempo) * 5000
            ELSE 0
        END as denda
        FROM peminjaman p
        JOIN buku b ON p.id_buku = b.id_buku
        LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
        $whereClause
        ORDER BY p.created_at DESC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>

<?php include '../templates/header.php'; ?>

<?php include '../templates/anggota_sidebar.php'; ?>
<?php include '../templates/anggota_topbar.php'; ?>

<main class="main-content">
    <div class="container-fluid py-4">

        <!-- Filter Card -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">Semua Status</option>
                            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Menunggu Persetujuan</option>
                            <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Disetujui</option>
                            <option value="dipinjam" <?php echo $status == 'dipinjam' ? 'selected' : ''; ?>>Dipinjam</option>
                            <option value="dikembalikan" <?php echo $status == 'dikembalikan' ? 'selected' : ''; ?>>Dikembalikan</option>
                            <option value="terlambat" <?php echo $status == 'terlambat' ? 'selected' : ''; ?>>Terlambat</option>
                            <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Ditolak</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Mulai</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Akhir</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cari Buku</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" placeholder="Cari judul buku..." value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                            <a href="riwayat.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- History Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list me-2"></i>Daftar Riwayat
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="50">#</th>
                                <th>Buku</th>
                                <th>Tanggal Pinjam</th>
                                <th>Jatuh Tempo</th>
                                <th>Status</th>
                                <th>Denda</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="fas fa-history fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">Belum ada riwayat peminjaman</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($history as $index => $record):
                                    $status_class = '';
                                    $status_text = '';
                                    $denda_text = '-';

                                    switch ($record['status']) {
                                        case 'pending':
                                            $status_class = 'warning';
                                            $status_text = 'Menunggu Persetujuan';
                                            break;
                                        case 'approved':
                                            $status_class = 'info';
                                            $status_text = 'Disetujui';
                                            break;
                                        case 'dipinjam':
                                            $status_class = 'warning';
                                            $status_text = 'Dipinjam';
                                            break;
                                        case 'dikembalikan':
                                            $status_class = 'success';
                                            $status_text = 'Dikembalikan';
                                            break;
                                        case 'terlambat':
                                            $status_class = 'danger';
                                            $status_text = 'Terlambat';
                                            break;
                                        case 'rejected':
                                            $status_class = 'danger';
                                            $status_text = 'Ditolak';
                                            break;
                                        default:
                                            $status_class = 'secondary';
                                            $status_text = ucfirst($record['status']);
                                    }

                                    if ($record['denda'] && $record['denda'] > 0) {
                                        $denda_text = formatCurrency($record['denda']);
                                        if ($record['status'] === 'terlambat') {
                                            $status_text .= ' (Denda)';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="book-cover-mini me-3">
                                                <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $record['cover_buku'] ?: 'default.jpg'; ?>" 
                                                     alt="<?php echo htmlspecialchars($record['judul_buku']); ?>">
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($record['judul_buku']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($record['nama_kategori'] ?? 'Umum'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo formatDate($record['tanggal_pinjam']); ?></td>
                                    <td><?php echo formatDate($record['tanggal_jatuh_tempo']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($record['denda'] && $record['denda'] > 0): ?>
                                        <span class="text-danger fw-bold"><?php echo $denda_text; ?></span>
                                        <?php else: ?>
                                        <span class="text-muted"><?php echo $denda_text; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="detail_buku.php?id=<?php echo $record['id_buku']; ?>" class="btn btn-sm btn-outline-primary" title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($record['status'] === 'dipinjam'): ?>
                                            
                                            <?php endif; ?>
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

        <!-- Statistics -->
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-number text-primary">
                            <?php echo count(array_filter($history, function($h) { return $h['status'] === 'dipinjam'; })); ?>
                        </div>
                        <div class="stat-label">Sedang Dipinjam</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-number text-success">
                            <?php echo count(array_filter($history, function($h) { return $h['status'] === 'dikembalikan'; })); ?>
                        </div>
                        <div class="stat-label">Sudah Dikembalikan</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <div class="stat-number text-danger">
                            <?php echo count(array_filter($history, function($h) { return $h['status'] === 'terlambat'; })); ?>
                        </div>
                        <div class="stat-label">Terlambat</div>
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
    </div>
</main>

<style>
    /* Main content layout */
    .main-content {
        margin-left: 250px; /* Same as sidebar width */
        margin-top: 70px; /* Same as topbar height */
        padding: 2rem;
        min-height: calc(100vh - 70px);
        width: calc(100% - 250px);
        background-color: #f8fafc;
    }
    
    /* Container fluid full width */
    .container-fluid {
        max-width: 100%;
        padding-left: 0;
        padding-right: 0;
    }
    
    /* Cards full width */
    .card {
        width: 100%;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        margin-bottom: 1.5rem;
    }
    
    /* Table responsive */
    .table-responsive {
        overflow-x: auto;
        border-radius: 8px;
    }
    
    .table {
        margin-bottom: 0;
        background: white;
    }
    
    .table thead th {
        background-color: #f9fafb;
        border-bottom: 2px solid #e5e7eb;
        font-weight: 600;
        color: #374151;
        padding: 1rem;
    }
    
    .table tbody td {
        padding: 1rem;
        vertical-align: middle;
        border-color: #e5e7eb;
    }
    
    /* Book cover mini in table */
    .book-cover-mini {
        width: 50px;
        height: 65px;
        border-radius: 5px;
        overflow: hidden;
        background: #f3f4f6;
    }
    
    .book-cover-mini img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    /* Badge styles */
    .badge {
        padding: 0.4em 0.8em;
        font-weight: 500;
        font-size: 0.85em;
    }
    
    .badge-warning {
        background-color: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    
    .badge-success {
        background-color: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .badge-danger {
        background-color: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    /* Statistics cards */
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 1rem;
        color: #6b7280;
        font-weight: 500;
    }
    
    /* Responsive design */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 1rem;
        }
        
        .card {
            margin-bottom: 1rem;
        }
        
        .table-responsive {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
    }
    
    @media (max-width: 768px) {
        .main-content {
            padding: 0.75rem;
        }
        
        .d-flex.justify-content-between.align-items-center {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .col-md-3, .col-md-4 {
            margin-bottom: 1rem;
        }
        
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .btn-group .btn {
            flex: 1;
            min-width: 40px;
        }
    }
    
    /* Button styles */
    .btn {
        border-radius: 8px;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .btn-primary {
        background-color: #4361ee;
        border-color: #4361ee;
    }
    
    .btn-primary:hover {
        background-color: #3a56d4;
        border-color: #3a56d4;
    }
    
    .btn-outline-primary {
        color: #4361ee;
        border-color: #4361ee;
    }
    
    .btn-outline-primary:hover {
        background-color: #4361ee;
        color: white;
    }
    
    /* Page title */
    .page-title {
        color: #1f2937;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }
    
    /* Form controls */
    .form-control, .form-select {
        border-radius: 8px;
        border: 1px solid #d1d5db;
        padding: 0.625rem 0.875rem;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #4361ee;
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }

    /* Modal styles */
    .modal-content {
        border-radius: 15px;
        border: none;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    .modal-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 15px 15px 0 0;
        border-bottom: none;
        padding: 1.5rem;
    }

    .modal-title {
        font-weight: 600;
    }

    .btn-close {
        filter: invert(1);
    }

    .modal-body {
        padding: 2rem;
    }

    .denda-info-card {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .denda-info-card .denda-amount {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .denda-info-card .denda-book {
        font-size: 1.1rem;
        opacity: 0.9;
    }
</style>

<script>
function openBayarDendaModal(idPeminjaman, judulBuku, jumlahDenda) {
    // Set modal data
    document.getElementById('modalIdPeminjaman').value = idPeminjaman;

    // Update modal title and info
    document.getElementById('bayarDendaModalLabel').innerHTML =
        '<i class="fas fa-money-bill-wave me-2"></i>Bayar Denda Buku';

    document.getElementById('dendaInfo').innerHTML = `
        <div class="denda-info-card">
            <div class="denda-amount">Rp ${new Intl.NumberFormat('id-ID').format(jumlahDenda)}</div>
            <div class="denda-book">${judulBuku}</div>
        </div>
        <p class="text-muted mb-0">Silakan pilih metode pembayaran dan kirim bukti pembayaran jika diperlukan.</p>
    `;

    // Reset form
    document.getElementById('bayarDendaForm').reset();
    document.getElementById('buktiTransferDiv').style.display = 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('bayarDendaModal'));
    modal.show();
}

// Handle payment method change
document.querySelectorAll('input[name="metode"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const buktiDiv = document.getElementById('buktiTransferDiv');
        if (this.value === 'transfer') {
            buktiDiv.style.display = 'block';
        } else {
            buktiDiv.style.display = 'none';
        }
    });
});

// Handle form submission
document.getElementById('submitBayarDenda').addEventListener('click', function() {
    const form = document.getElementById('bayarDendaForm');
    const formData = new FormData(form);

    // Validate form
    const metode = formData.get('metode');
    if (!metode) {
        alert('Silakan pilih metode pembayaran');
        return;
    }

    if (metode === 'transfer') {
        const buktiFile = formData.get('bukti_transfer');
        if (!buktiFile || buktiFile.size === 0) {
            alert('Silakan upload bukti transfer');
            return;
        }

        // Check file size (2MB max)
        if (buktiFile.size > 2 * 1024 * 1024) {
            alert('Ukuran file maksimal 2MB');
            return;
        }
    }

    // Disable button and show loading
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Mengirim...';

    // Submit form via AJAX
    fetch('proses.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Pembayaran denda berhasil dikirim! Menunggu persetujuan admin.');
            bootstrap.Modal.getInstance(document.getElementById('bayarDendaModal')).hide();
            location.reload();
        } else {
            alert('Gagal mengirim pembayaran: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat mengirim pembayaran');
    })
    .finally(() => {
        // Re-enable button
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Kirim Pembayaran';
    });
});
</script>

<?php include '../templates/footer.php'; ?>