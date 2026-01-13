<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Only admin can access this
requireAdmin();

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Aksi tidak valid'];

switch ($action) {
    case 'detail_aktivitas':
        $id = intval($_GET['id']);
        $conn = getConnection();
        
        $sql = "SELECT a.*, 
                       CASE a.user_type 
                           WHEN 'anggota' THEN (SELECT username FROM anggota WHERE id_anggota = a.id_user)
                           WHEN 'petugas' THEN (SELECT username FROM petugas WHERE id_petugas = a.id_user)
                       END as username,
                       CASE a.user_type 
                           WHEN 'anggota' THEN (SELECT email FROM anggota WHERE id_anggota = a.id_user)
                           WHEN 'petugas' THEN (SELECT email FROM petugas WHERE id_petugas = a.id_user)
                       END as email
                FROM aktivitas a
                WHERE a.id_aktivitas = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $activity = $result->fetch_assoc();
            echo '<div class="activity-detail">';
            echo '<p><strong>Username:</strong> ' . htmlspecialchars($activity['username']) . '</p>';
            echo '<p><strong>Email:</strong> ' . htmlspecialchars($activity['email']) . '</p>';
            echo '<p><strong>Tipe User:</strong> ' . ucfirst($activity['user_type']) . '</p>';
            echo '<p><strong>Aktivitas:</strong> ' . htmlspecialchars($activity['aktivitas']) . '</p>';
            echo '<p><strong>Waktu:</strong> ' . formatDate($activity['created_at'], 'd/m/Y H:i:s') . '</p>';
            echo '<p><strong>Status:</strong> ' . ($activity['dibaca'] == 'no' ? 'Belum dibaca' : 'Sudah dibaca') . '</p>';
            echo '</div>';
        } else {
            echo '<p class="text-muted">Data aktivitas tidak ditemukan</p>';
        }
        
        $stmt->close();
        $conn->close();
        exit;
        
    case 'baca_aktivitas':
        $id = intval($_GET['id']);
        $conn = getConnection();
        
        $sql = "UPDATE aktivitas SET dibaca = 'yes' WHERE id_aktivitas = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        $stmt->close();
        $conn->close();
        exit;
        
    case 'get_buku':
        $id_buku = intval($_GET['id']);
        $conn = getConnection();

        // Get book data
        $sql = "SELECT b.*, k.nama_kategori, p.nama_penulis, pb.nama_penerbit
                FROM buku b
                LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
                LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
                LEFT JOIN penerbit pb ON b.id_penerbit = pb.id_penerbit
                WHERE b.id_buku = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_buku);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $book = $result->fetch_assoc();

            // Get categories, authors, publishers for dropdowns
            $categories = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori")->fetch_all(MYSQLI_ASSOC);
            $authors = $conn->query("SELECT * FROM penulis ORDER BY nama_penulis")->fetch_all(MYSQLI_ASSOC);
            $publishers = $conn->query("SELECT * FROM penerbit ORDER BY nama_penerbit")->fetch_all(MYSQLI_ASSOC);

            // Generate edit form HTML
            echo '<input type="hidden" name="id_buku" value="' . $book['id_buku'] . '">';
            echo '<div class="row">';
            echo '<div class="col-md-8">';
            echo '<div class="mb-3">';
            echo '<label class="form-label">Judul Buku <span class="text-danger">*</span></label>';
            echo '<input type="text" class="form-control" name="judul_buku" value="' . htmlspecialchars($book['judul_buku']) . '" required>';
            echo '</div>';
            echo '<div class="row mb-3">';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Kategori <span class="text-danger">*</span></label>';
            echo '<select class="form-select" name="id_kategori" required>';
            echo '<option value="">Pilih Kategori</option>';
            foreach ($categories as $cat) {
                $selected = $cat['id_kategori'] == $book['id_kategori'] ? 'selected' : '';
                echo '<option value="' . $cat['id_kategori'] . '" ' . $selected . '>' . htmlspecialchars($cat['nama_kategori']) . '</option>';
            }
            echo '</select>';
            echo '</div>';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Stok <span class="text-danger">*</span></label>';
            echo '<input type="number" class="form-control" name="stok" value="' . $book['stok'] . '" min="1" required>';
            echo '</div>';
            echo '</div>';
            echo '<div class="row mb-3">';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Penulis</label>';
            echo '<select class="form-select" name="id_penulis">';
            echo '<option value="">Pilih Penulis</option>';
            foreach ($authors as $author) {
                $selected = $author['id_penulis'] == $book['id_penulis'] ? 'selected' : '';
                echo '<option value="' . $author['id_penulis'] . '" ' . $selected . '>' . htmlspecialchars($author['nama_penulis']) . '</option>';
            }
            echo '</select>';
            echo '<div class="form-text">';
            echo '<a href="#" onclick="openModal(\'penulis\')">Tambah penulis baru</a>';
            echo '</div>';
            echo '</div>';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Penerbit</label>';
            echo '<select class="form-select" name="id_penerbit">';
            echo '<option value="">Pilih Penerbit</option>';
            foreach ($publishers as $publisher) {
                $selected = $publisher['id_penerbit'] == $book['id_penerbit'] ? 'selected' : '';
                echo '<option value="' . $publisher['id_penerbit'] . '" ' . $selected . '>' . htmlspecialchars($publisher['nama_penerbit']) . '</option>';
            }
            echo '</select>';
            echo '<div class="form-text">';
            echo '<a href="#" onclick="openModal(\'penerbit\')">Tambah penerbit baru</a>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="row mb-3">';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Tahun Terbit</label>';
            echo '<input type="number" class="form-control" name="tahun_terbit" min="1900" max="' . date('Y') . '" value="' . $book['tahun_terbit'] . '">';
            echo '</div>';
            echo '<div class="col-md-6">';
            echo '<label class="form-label">Jumlah Halaman</label>';
            echo '<input type="number" class="form-control" name="jumlah_halaman" min="1" value="' . $book['jumlah_halaman'] . '">';
            echo '</div>';
            echo '</div>';
            echo '<div class="mb-3">';
            echo '<label class="form-label">Deskripsi</label>';
            echo '<textarea class="form-control" name="deskripsi" rows="3">' . htmlspecialchars($book['deskripsi']) . '</textarea>';
            echo '</div>';
            echo '</div>';
            echo '<div class="col-md-4">';
            echo '<div class="mb-3">';
            echo '<label class="form-label">Cover Buku</label>';
            echo '<div class="cover-upload">';
            echo '<div class="cover-preview mb-3">';
            $coverSrc = $book['cover_buku'] ? SITE_URL . 'uploads/covers/' . $book['cover_buku'] : SITE_URL . 'uploads/covers/default.jpg';
            echo '<img id="editCoverPreview" src="' . $coverSrc . '" alt="Preview" class="img-fluid rounded">';
            echo '</div>';
            echo '<input type="file" class="form-control" name="cover_buku" accept="image/*" onchange="previewEditCover(this)">';
            echo '<div class="form-text">Ukuran maksimal 2MB. Format: JPG, PNG</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="mb-3">';
            echo '<label class="form-label">File PDF Buku</label>';
            echo '<input type="file" class="form-control" name="file_pdf" accept=".pdf">';
            if ($book['file_pdf']) {
                echo '<div class="form-text">File PDF saat ini: ' . htmlspecialchars($book['file_pdf']) . '</div>';
            }
            echo '<div class="form-text">Ukuran maksimal 10MB. Format: PDF</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<p class="text-muted">Data buku tidak ditemukan</p>';
        }

        $stmt->close();
        $conn->close();
        exit;

    case 'tambah_buku':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $judul_buku = sanitize($_POST['judul_buku']);
            $id_kategori = intval($_POST['id_kategori']);
            $id_penulis = intval($_POST['id_penulis']);
            $id_penerbit = intval($_POST['id_penerbit']);
            $tahun_terbit = intval($_POST['tahun_terbit']);
            $jumlah_halaman = intval($_POST['jumlah_halaman']);
            $deskripsi = sanitize($_POST['deskripsi']);
            $stok = intval($_POST['stok']);

            $conn = getConnection();

            // Handle cover upload
            $cover_buku = null;
            if (isset($_FILES['cover_buku']) && $_FILES['cover_buku']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['cover_buku'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                if (in_array($ext, $allowed)) {
                    $filename = 'cover_' . time() . '_' . uniqid() . '.' . $ext;
                    $destination = COVER_PATH . $filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $cover_buku = $filename;
                    }
                }
            }

            // Handle PDF upload
            $file_pdf = null;
            if (isset($_FILES['file_pdf']) && $_FILES['file_pdf']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file_pdf'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if ($ext === 'pdf') {
                    $filename = 'book_' . time() . '_' . uniqid() . '.pdf';
                    $destination = PDF_PATH . $filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $file_pdf = $filename;
                    }
                }
            }

            // Insert book
            $sql = "INSERT INTO buku (judul_buku, id_kategori, id_penulis, id_penerbit,
                     tahun_terbit, jumlah_halaman, deskripsi, cover_buku, file_pdf, stok)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("siiiissssi", $judul_buku, $id_kategori, $id_penulis, $id_penerbit,
                             $tahun_terbit, $jumlah_halaman, $deskripsi, $cover_buku, $file_pdf, $stok);

            if ($stmt->execute()) {
                $id_buku = $stmt->insert_id;

                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menambahkan buku baru: ' . $judul_buku);

                $response['success'] = true;
                $response['message'] = 'Buku berhasil ditambahkan';
                $response['id_buku'] = $id_buku;
            } else {
                $response['message'] = 'Gagal menambahkan buku: ' . $stmt->error;
            }

            $stmt->close();
            $conn->close();
        }
        break;
        
    case 'update_buku':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_buku = intval($_POST['id_buku']);
            $judul_buku = sanitize($_POST['judul_buku']);
            $id_kategori = intval($_POST['id_kategori']);
            $id_penulis = intval($_POST['id_penulis']);
            $id_penerbit = intval($_POST['id_penerbit']);
            $tahun_terbit = intval($_POST['tahun_terbit']);
            $jumlah_halaman = intval($_POST['jumlah_halaman']);
            $deskripsi = sanitize($_POST['deskripsi']);
            $stok = intval($_POST['stok']);
            
            $conn = getConnection();
            
            // Get current book data
            $currentSql = "SELECT cover_buku, file_pdf FROM buku WHERE id_buku = ?";
            $currentStmt = $conn->prepare($currentSql);
            $currentStmt->bind_param("i", $id_buku);
            $currentStmt->execute();
            $currentResult = $currentStmt->get_result();
            $currentBook = $currentResult->fetch_assoc();
            
            $cover_buku = $currentBook['cover_buku'];
            $file_pdf = $currentBook['file_pdf'];
            
            // Handle cover upload
            if (isset($_FILES['cover_buku']) && $_FILES['cover_buku']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['cover_buku'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($ext, $allowed)) {
                    $filename = 'cover_' . time() . '_' . uniqid() . '.' . $ext;
                    $destination = COVER_PATH . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        // Delete old cover if exists and not default
                        if ($cover_buku && $cover_buku !== 'default.jpg') {
                            $oldPath = COVER_PATH . $cover_buku;
                            if (file_exists($oldPath)) {
                                unlink($oldPath);
                            }
                        }
                        $cover_buku = $filename;
                    }
                }
            }
            
            // Handle PDF upload
            if (isset($_FILES['file_pdf']) && $_FILES['file_pdf']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file_pdf'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if ($ext === 'pdf') {
                    $filename = 'book_' . time() . '_' . uniqid() . '.pdf';
                    $destination = PDF_PATH . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        // Delete old PDF if exists
                        if ($file_pdf) {
                            $oldPath = PDF_PATH . $file_pdf;
                            if (file_exists($oldPath)) {
                                unlink($oldPath);
                            }
                        }
                        $file_pdf = $filename;
                    }
                }
            }
            
            // Update book
            $sql = "UPDATE buku SET judul_buku = ?, id_kategori = ?, id_penulis = ?, id_penerbit = ?,
                     tahun_terbit = ?, jumlah_halaman = ?, deskripsi = ?, 
                     cover_buku = ?, file_pdf = ?, stok = ?, updated_at = NOW()
                     WHERE id_buku = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("siiiissssii", $judul_buku, $id_kategori, $id_penulis, $id_penerbit,
                             $tahun_terbit, $jumlah_halaman, $deskripsi, $cover_buku, $file_pdf, $stok, $id_buku);
            
            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Memperbarui buku: ' . $judul_buku);
                
                $response['success'] = true;
                $response['message'] = 'Buku berhasil diperbarui';
            } else {
                $response['message'] = 'Gagal memperbarui buku: ' . $stmt->error;
            }
            
            $currentStmt->close();
            $stmt->close();
            $conn->close();
        }
        break;
        
    case 'hapus_buku':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_buku = intval($_POST['id_buku']);
            
            $conn = getConnection();
            
            // Get book data for file deletion
            $sql = "SELECT cover_buku, file_pdf FROM buku WHERE id_buku = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id_buku);
            $stmt->execute();
            $result = $stmt->get_result();
            $book = $result->fetch_assoc();
            
            // Delete files
            if ($book['cover_buku'] && $book['cover_buku'] !== 'default.jpg') {
                $coverPath = COVER_PATH . $book['cover_buku'];
                if (file_exists($coverPath)) {
                    unlink($coverPath);
                }
            }
            
            if ($book['file_pdf']) {
                $pdfPath = PDF_PATH . $book['file_pdf'];
                if (file_exists($pdfPath)) {
                    unlink($pdfPath);
                }
            }
            
            // Delete book
            $deleteSql = "DELETE FROM buku WHERE id_buku = ?";
            $deleteStmt = $conn->prepare($deleteSql);
            $deleteStmt->bind_param("i", $id_buku);
            
            if ($deleteStmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menghapus buku ID: ' . $id_buku);
                
                $response['success'] = true;
                $response['message'] = 'Buku berhasil dihapus';
            } else {
                $response['message'] = 'Gagal menghapus buku';
            }
            
            $stmt->close();
            $deleteStmt->close();
            $conn->close();
        }
        break;
        
    case 'update_status_anggota':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_anggota = intval($_POST['id_anggota']);
            $status = sanitize($_POST['status']);
            
            $conn = getConnection();
            
            $sql = "UPDATE anggota SET status = ? WHERE id_anggota = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $status, $id_anggota);
            
            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Mengubah status anggota ID: ' . $id_anggota . ' menjadi ' . $status);
                
                $response['success'] = true;
                $response['message'] = 'Status anggota berhasil diperbarui';
            } else {
                $response['message'] = 'Gagal memperbarui status anggota';
            }
            
            $stmt->close();
            $conn->close();
        }
        break;
        
    case 'approve_denda':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_denda = intval($_POST['id_denda']);
            $action_type = sanitize($_POST['action']); // 'approve' or 'reject'

            if (!in_array($action_type, ['approve', 'reject'])) {
                $response['message'] = 'Aksi tidak valid';
                break;
            }

            $conn = getConnection();

            try {
                // Get denda details
                $dendaSql = "SELECT d.*, pg.id_peminjaman, pg.denda as jumlah_denda_pengembalian,
                                    b.judul_buku, a.username, a.email, a.total_denda,
                                    pg.id_buku, pg.id_anggota
                             FROM denda d
                             JOIN pengembalian pg ON d.id_pengembalian = pg.id_pengembalian
                             JOIN buku b ON pg.id_buku = b.id_buku
                             JOIN anggota a ON d.id_anggota = a.id_anggota
                             WHERE d.id_denda = ? AND d.status = 'menunggu_approval'";

                $dendaStmt = $conn->prepare($dendaSql);
                if (!$dendaStmt) {
                    throw new Exception('Failed to prepare denda query: ' . $conn->error);
                }

                $dendaStmt->bind_param("i", $id_denda);
                if (!$dendaStmt->execute()) {
                    throw new Exception('Failed to execute denda query: ' . $dendaStmt->error);
                }

                $dendaResult = $dendaStmt->get_result();

                if ($dendaResult->num_rows === 0) {
                    $response['message'] = 'Data denda tidak ditemukan atau sudah diproses';
                    $dendaStmt->close();
                    $conn->close();
                    break;
                }

                $dendaData = $dendaResult->fetch_assoc();
                $id_anggota = $dendaData['id_anggota'];
                $id_peminjaman = $dendaData['id_peminjaman'];
                $judul_buku = $dendaData['judul_buku'];
                $username = $dendaData['username'];
                $jumlah_denda = $dendaData['jumlah_denda'];
                $id_pengembalian = $dendaData['id_pengembalian'];
                $id_buku = $dendaData['id_buku'];

                // Start transaction
                $conn->begin_transaction();

                if ($action_type === 'approve') {
                    // Update denda status to lunas
                    $updateDendaSql = "UPDATE denda SET status = 'lunas', tanggal_approve = NOW() WHERE id_denda = ?";
                    $updateDendaStmt = $conn->prepare($updateDendaSql);
                    if (!$updateDendaStmt) {
                        throw new Exception('Failed to prepare denda update: ' . $conn->error);
                    }
                    $updateDendaStmt->bind_param("i", $id_denda);
                    if (!$updateDendaStmt->execute()) {
                        throw new Exception('Failed to update denda status: ' . $updateDendaStmt->error);
                    }

                    // Update pengembalian status_denda to lunas
                    $updatePengembalianSql = "UPDATE pengembalian SET status_denda = 'lunas' WHERE id_pengembalian = ?";
                    $updatePengembalianStmt = $conn->prepare($updatePengembalianSql);
                    if (!$updatePengembalianStmt) {
                        throw new Exception('Failed to prepare pengembalian update: ' . $conn->error);
                    }
                    $updatePengembalianStmt->bind_param("i", $id_pengembalian);
                    if (!$updatePengembalianStmt->execute()) {
                        throw new Exception('Failed to update pengembalian status: ' . $updatePengembalianStmt->error);
                    }

                    // Update peminjaman status to dikembalikan if it was terlambat
                    $updatePeminjamanSql = "UPDATE peminjaman SET status = 'dikembalikan' WHERE id_peminjaman = ? AND status = 'terlambat'";
                    $updatePeminjamanStmt = $conn->prepare($updatePeminjamanSql);
                    if (!$updatePeminjamanStmt) {
                        throw new Exception('Failed to prepare peminjaman update: ' . $conn->error);
                    }
                    $updatePeminjamanStmt->bind_param("i", $id_peminjaman);
                    if (!$updatePeminjamanStmt->execute()) {
                        throw new Exception('Failed to update peminjaman status: ' . $updatePeminjamanStmt->error);
                    }

                    // Update book stock (return the book)
                    $updateStockSql = "UPDATE buku SET stok = stok + 1 WHERE id_buku = ?";
                    $updateStockStmt = $conn->prepare($updateStockSql);
                    if (!$updateStockStmt) {
                        throw new Exception('Failed to prepare stock update: ' . $conn->error);
                    }
                    $updateStockStmt->bind_param("i", $id_buku);
                    if (!$updateStockStmt->execute()) {
                        throw new Exception('Failed to update book stock: ' . $updateStockStmt->error);
                    }

                    // Reduce member's total denda
                    $updateMemberDendaSql = "UPDATE anggota SET total_denda = GREATEST(0, total_denda - ?) WHERE id_anggota = ?";
                    $updateMemberDendaStmt = $conn->prepare($updateMemberDendaSql);
                    if (!$updateMemberDendaStmt) {
                        throw new Exception('Failed to prepare member denda update: ' . $conn->error);
                    }
                    $updateMemberDendaStmt->bind_param("di", $jumlah_denda, $id_anggota);
                    if (!$updateMemberDendaStmt->execute()) {
                        throw new Exception('Failed to update member denda: ' . $updateMemberDendaStmt->error);
                    }

                    // Create notification for member
                    $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) VALUES (?, ?, ?, 'info')";
                    $notifStmt = $conn->prepare($notifSql);
                    $notifJudul = 'Pembayaran Denda Disetujui';
                    $notifPesan = 'Pembayaran denda sebesar ' . formatCurrency($jumlah_denda) . ' untuk buku "' . $judul_buku . '" telah disetujui. Terima kasih.';
                    $notifStmt->bind_param("iss", $id_anggota, $notifJudul, $notifPesan);
                    if (!$notifStmt->execute()) {
                        throw new Exception('Failed to create notification: ' . $notifStmt->error);
                    }

                    $activityMessage = 'Menyetujui pembayaran denda sebesar ' . formatCurrency($jumlah_denda) . ' untuk anggota: ' . $username . ' (Buku: ' . $judul_buku . ')';

                    $updateDendaStmt->close();
                    $updatePengembalianStmt->close();
                    $updatePeminjamanStmt->close();
                    $updateStockStmt->close();
                    $updateMemberDendaStmt->close();
                    $notifStmt->close();
                } else {
                    // Reject payment - update status to ditolak
                    $rejectSql = "UPDATE denda SET status = 'ditolak' WHERE id_denda = ?";
                    $rejectStmt = $conn->prepare($rejectSql);
                    if (!$rejectStmt) {
                        throw new Exception('Failed to prepare reject query: ' . $conn->error);
                    }
                    $rejectStmt->bind_param("i", $id_denda);
                    if (!$rejectStmt->execute()) {
                        throw new Exception('Failed to reject payment: ' . $rejectStmt->error);
                    }

                    // Create notification for member
                    $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) VALUES (?, ?, ?, 'rejection')";
                    $notifStmt = $conn->prepare($notifSql);
                    $notifJudul = 'Pembayaran Denda Ditolak';
                    $notifPesan = 'Pembayaran denda sebesar ' . formatCurrency($jumlah_denda) . ' untuk buku "' . $judul_buku . '" ditolak. Silakan hubungi admin untuk informasi lebih lanjut.';
                    $notifStmt->bind_param("iss", $id_anggota, $notifJudul, $notifPesan);
                    if (!$notifStmt->execute()) {
                        throw new Exception('Failed to create notification: ' . $notifStmt->error);
                    }

                    $activityMessage = 'Menolak pembayaran denda sebesar ' . formatCurrency($jumlah_denda) . ' untuk anggota: ' . $username . ' (Buku: ' . $judul_buku . ')';

                    $rejectStmt->close();
                    $notifStmt->close();
                }

                // Log admin activity
                logActivity($_SESSION['user_id'], 'petugas', $activityMessage);

                $conn->commit();

                $response['success'] = true;
                $response['message'] = $action_type === 'approve' ? 'Pembayaran denda berhasil disetujui' : 'Pembayaran denda berhasil ditolak';

            } catch (Exception $e) {
                if ($conn->connect_errno === 0) {
                    $conn->rollback();
                }
                error_log('Denda approval error: ' . $e->getMessage());
                $response['message'] = 'Gagal memproses pembayaran denda: ' . $e->getMessage();
            }

            $dendaStmt->close();
            $conn->close();
        }
        break;

    case 'approve_loan':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_peminjaman = intval($_POST['id_peminjaman']);
            $id_admin = $_SESSION['user_id'];

            $conn = getConnection();

            try {
                // Start transaction
                $conn->begin_transaction();

                // Get loan details with FOR UPDATE to lock the row
                $sql = "SELECT p.*, b.judul_buku, b.stok, a.username, a.email, a.id_anggota
                        FROM peminjaman p
                        JOIN buku b ON p.id_buku = b.id_buku
                        JOIN anggota a ON p.id_anggota = a.id_anggota
                        WHERE p.id_peminjaman = ? AND p.status = 'pending' 
                        FOR UPDATE";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare loan query: ' . $conn->error);
                }
                
                $stmt->bind_param("i", $id_peminjaman);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute loan query: ' . $stmt->error);
                }
                
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    throw new Exception("Data peminjaman tidak ditemukan atau sudah diproses");
                }

                $loan = $result->fetch_assoc();
                $id_anggota = $loan['id_anggota'];
                $id_buku = $loan['id_buku'];
                $judul_buku = $loan['judul_buku'];
                $username = $loan['username'];

                // Check book stock
                if ($loan['stok'] <= 0) {
                    throw new Exception("Stok buku tidak mencukupi");
                }

                // Update loan status to 'dipinjam'
                $updateSql = "UPDATE peminjaman 
                             SET status = 'dipinjam', 
                                 tanggal_pinjam = NOW(), 
                                 tanggal_jatuh_tempo = DATE_ADD(NOW(), INTERVAL 7 DAY)
                             WHERE id_peminjaman = ?";
                
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Failed to prepare update query: ' . $conn->error);
                }
                
                $updateStmt->bind_param("i", $id_peminjaman);
                if (!$updateStmt->execute()) {
                    throw new Exception('Failed to update loan status: ' . $updateStmt->error);
                }

                // Decrease book stock and increase loan count
                $stockSql = "UPDATE buku 
                            SET stok = stok - 1, 
                                jumlah_pinjam = jumlah_pinjam + 1 
                            WHERE id_buku = ?";
                
                $stockStmt = $conn->prepare($stockSql);
                if (!$stockStmt) {
                    throw new Exception('Failed to prepare stock update: ' . $conn->error);
                }
                
                $stockStmt->bind_param("i", $id_buku);
                if (!$stockStmt->execute()) {
                    throw new Exception('Failed to update book stock: ' . $stockStmt->error);
                }

                // Create notification for member
                $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) 
                            VALUES (?, ?, ?, 'approval')";
                
                $notifStmt = $conn->prepare($notifSql);
                if (!$notifStmt) {
                    throw new Exception('Failed to prepare notification query: ' . $conn->error);
                }
                
                $judul = 'Peminjaman Disetujui';
                $pesan = 'Selamat! Permintaan peminjaman buku "' . $judul_buku . '" telah disetujui. Buku dapat diambil di perpustakaan. Batas waktu pengembalian: ' . date('d/m/Y', strtotime('+7 days'));
                $notifStmt->bind_param("iss", $id_anggota, $judul, $pesan);
                if (!$notifStmt->execute()) {
                    throw new Exception('Failed to create notification: ' . $notifStmt->error);
                }

                // Log activity
                logActivity($id_admin, 'petugas', 'Menyetujui peminjaman buku "' . $judul_buku . '" untuk anggota: ' . $username);

                // Commit transaction
                $conn->commit();

                $response['success'] = true;
                $response['message'] = 'Peminjaman berhasil disetujui';
                $response['data'] = [
                    'id_peminjaman' => $id_peminjaman,
                    'status' => 'dipinjam',
                    'tanggal_pinjam' => date('Y-m-d'),
                    'tanggal_jatuh_tempo' => date('Y-m-d', strtotime('+7 days'))
                ];

                // Close statements
                $stmt->close();
                $updateStmt->close();
                $stockStmt->close();
                $notifStmt->close();

            } catch (Exception $e) {
                // Rollback transaction on error
                if ($conn->connect_errno === 0) {
                    $conn->rollback();
                }
                $response['message'] = 'Gagal menyetujui peminjaman: ' . $e->getMessage();
                error_log('Approve loan error: ' . $e->getMessage());
            }

            $conn->close();
        }
        break;

    case 'reject_loan':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_peminjaman = intval($_POST['id_peminjaman']);
            $id_admin = $_SESSION['user_id'];
            $alasan = sanitize($_POST['alasan'] ?? 'Permintaan ditolak oleh admin');

            $conn = getConnection();

            try {
                // Start transaction
                $conn->begin_transaction();

                // Get loan details with FOR UPDATE to lock the row
                $sql = "SELECT p.*, b.judul_buku, a.username, a.id_anggota
                        FROM peminjaman p
                        JOIN buku b ON p.id_buku = b.id_buku
                        JOIN anggota a ON p.id_anggota = a.id_anggota
                        WHERE p.id_peminjaman = ? AND p.status = 'pending' 
                        FOR UPDATE";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare loan query: ' . $conn->error);
                }
                
                $stmt->bind_param("i", $id_peminjaman);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute loan query: ' . $stmt->error);
                }
                
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    throw new Exception("Data peminjaman tidak ditemukan atau sudah diproses");
                }

                $loan = $result->fetch_assoc();
                $id_anggota = $loan['id_anggota'];
                $judul_buku = $loan['judul_buku'];
                $username = $loan['username'];

                // Update loan status to 'rejected'
                $updateSql = "UPDATE peminjaman 
                             SET status = 'rejected', 
                                 updated_at = NOW()
                             WHERE id_peminjaman = ?";
                
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new Exception('Failed to prepare update query: ' . $conn->error);
                }
                
                $updateStmt->bind_param("i", $id_peminjaman);
                if (!$updateStmt->execute()) {
                    throw new Exception('Failed to update loan status: ' . $updateStmt->error);
                }

                // Create notification for member
                $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) 
                            VALUES (?, ?, ?, 'rejection')";
                
                $notifStmt = $conn->prepare($notifSql);
                if (!$notifStmt) {
                    throw new Exception('Failed to prepare notification query: ' . $conn->error);
                }
                
                $judul = 'Peminjaman Ditolak';
                $pesan = 'Maaf, permintaan peminjaman buku "' . $judul_buku . '" telah ditolak oleh admin.';
                if (!empty($alasan)) {
                    $pesan .= ' Alasan: ' . $alasan;
                }
                $pesan .= ' Silakan ajukan permintaan untuk buku lainnya.';
                $notifStmt->bind_param("iss", $id_anggota, $judul, $pesan);
                if (!$notifStmt->execute()) {
                    throw new Exception('Failed to create notification: ' . $notifStmt->error);
                }

                // Log activity
                logActivity($id_admin, 'petugas', 'Menolak peminjaman buku "' . $judul_buku . '" untuk anggota: ' . $username . '. Alasan: ' . $alasan);

                // Commit transaction
                $conn->commit();

                $response['success'] = true;
                $response['message'] = 'Peminjaman berhasil ditolak';
                $response['data'] = [
                    'id_peminjaman' => $id_peminjaman,
                    'status' => 'rejected'
                ];

                // Close statements
                $stmt->close();
                $updateStmt->close();
                $notifStmt->close();

            } catch (Exception $e) {
                // Rollback transaction on error
                if ($conn->connect_errno === 0) {
                    $conn->rollback();
                }
                $response['message'] = 'Gagal menolak peminjaman: ' . $e->getMessage();
                error_log('Reject loan error: ' . $e->getMessage());
            }

            $conn->close();
        }
        break;

    case 'get_pending_loan_details':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $id_peminjaman = intval($_GET['id'] ?? 0);
            
            if ($id_peminjaman <= 0) {
                $response['message'] = 'ID peminjaman tidak valid';
                break;
            }
            
            $conn = getConnection();
            
            $sql = "SELECT p.*, 
                    b.judul_buku, b.cover_buku, b.stok, b.deskripsi,
                    a.username, a.email, a.no_telepon, a.alamat,
                    k.nama_kategori,
                    pen.nama_penulis,
                    pub.nama_penerbit,
                    DATEDIFF(CURDATE(), p.tanggal_pinjam) as hari_menunggu,
                    DATE_FORMAT(p.tanggal_pinjam, '%d/%m/%Y %H:%i') as tanggal_pinjam_formatted,
                    DATE_FORMAT(p.created_at, '%d/%m/%Y %H:%i') as tanggal_request_formatted
                    FROM peminjaman p
                    LEFT JOIN buku b ON p.id_buku = b.id_buku
                    LEFT JOIN anggota a ON p.id_anggota = a.id_anggota
                    LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
                    LEFT JOIN penulis pen ON b.id_penulis = pen.id_penulis
                    LEFT JOIN penerbit pub ON b.id_penerbit = pub.id_penerbit
                    WHERE p.id_peminjaman = ? AND p.status = 'pending'";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $response['message'] = 'Failed to prepare query';
                $conn->close();
                break;
            }
            
            $stmt->bind_param("i", $id_peminjaman);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to execute query';
                $stmt->close();
                $conn->close();
                break;
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $response['message'] = 'Data peminjaman tidak ditemukan';
                $stmt->close();
                $conn->close();
                break;
            }
            
            $loan = $result->fetch_assoc();
            
            // Get member's current borrowed books
            $memberSql = "SELECT COUNT(*) as total_dipinjam 
                         FROM peminjaman 
                         WHERE id_anggota = ? AND status IN ('dipinjam', 'terlambat')";
            
            $memberStmt = $conn->prepare($memberSql);
            $memberStmt->bind_param("i", $loan['id_anggota']);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();
            $memberData = $memberResult->fetch_assoc();
            $loan['total_dipinjam'] = $memberData['total_dipinjam'];
            
            // Get member's borrowing history
            $historySql = "SELECT COUNT(*) as total_peminjaman 
                          FROM peminjaman 
                          WHERE id_anggota = ? AND status IN ('dikembalikan', 'rejected')";
            
            $historyStmt = $conn->prepare($historySql);
            $historyStmt->bind_param("i", $loan['id_anggota']);
            $historyStmt->execute();
            $historyResult = $historyStmt->get_result();
            $historyData = $historyResult->fetch_assoc();
            $loan['total_peminjaman'] = $historyData['total_peminjaman'];
            
            // Get member's total denda
            $dendaSql = "SELECT COALESCE(SUM(jumlah_denda), 0) as total_denda 
                        FROM denda 
                        WHERE id_anggota = ? AND status = 'lunas'";
            
            $dendaStmt = $conn->prepare($dendaSql);
            $dendaStmt->bind_param("i", $loan['id_anggota']);
            $dendaStmt->execute();
            $dendaResult = $dendaStmt->get_result();
            $dendaData = $dendaResult->fetch_assoc();
            $loan['total_denda_lunas'] = $dendaData['total_denda'];
            
            $response['success'] = true;
            $response['data'] = $loan;
            
            $stmt->close();
            $memberStmt->close();
            $historyStmt->close();
            $dendaStmt->close();
            $conn->close();
        }
        break;

    case 'get_pending_loans':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $conn = getConnection();
            
            $sql = "SELECT p.*, 
                    b.judul_buku, b.cover_buku, b.stok, 
                    a.username, a.email,
                    k.nama_kategori,
                    DATEDIFF(CURDATE(), p.tanggal_pinjam) as hari_menunggu,
                    DATE_FORMAT(p.tanggal_pinjam, '%d/%m/%Y %H:%i') as tanggal_pinjam_formatted,
                    DATE_FORMAT(p.created_at, '%d/%m/%Y %H:%i') as tanggal_request_formatted
                    FROM peminjaman p
                    LEFT JOIN buku b ON p.id_buku = b.id_buku
                    LEFT JOIN anggota a ON p.id_anggota = a.id_anggota
                    LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
                    WHERE p.status = 'pending'
                    ORDER BY p.tanggal_pinjam ASC";
            
            $result = $conn->query($sql);
            
            if (!$result) {
                $response['message'] = 'Error query: ' . $conn->error;
                $conn->close();
                break;
            }
            
            $loans = [];
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $loans[] = $row;
                }
            }
            
            $response['success'] = true;
            $response['data'] = $loans;
            $response['total'] = count($loans);
            
            $conn->close();
        }
        break;

    case 'confirm_return':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_pengembalian = intval($_POST['id_pengembalian']);
            $id_admin = $_SESSION['user_id'];

            $conn = getConnection();

            // Start transaction
            $conn->begin_transaction();

            try {
                // Get return details
                $sql = "SELECT pg.*, p.id_buku, p.id_anggota, p.tanggal_jatuh_tempo,
                        b.judul_buku, b.stok, a.username, a.total_denda
                        FROM pengembalian pg
                        JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                        JOIN buku b ON p.id_buku = b.id_buku
                        JOIN anggota a ON p.id_anggota = a.id_anggota
                        WHERE pg.id_pengembalian = ? AND pg.status_denda = 'belum_lunas'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id_pengembalian);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    throw new Exception("Data pengembalian tidak ditemukan atau sudah diproses");
                }

                $return = $result->fetch_assoc();

                // Update return status to 'lunas'
                $updateReturnSql = "UPDATE pengembalian SET status_denda = 'lunas' WHERE id_pengembalian = ?";
                $updateReturnStmt = $conn->prepare($updateReturnSql);
                $updateReturnStmt->bind_param("i", $id_pengembalian);
                $updateReturnStmt->execute();

                // Update loan status to 'dikembalikan'
                $updateLoanSql = "UPDATE peminjaman SET status = 'dikembalikan' WHERE id_peminjaman = ?";
                $updateLoanStmt = $conn->prepare($updateLoanSql);
                $updateLoanStmt->bind_param("i", $return['id_peminjaman']);
                $updateLoanStmt->execute();

                // Increase book stock
                $stockSql = "UPDATE buku SET stok = stok + 1 WHERE id_buku = ?";
                $stockStmt = $conn->prepare($stockSql);
                $stockStmt->bind_param("i", $return['id_buku']);
                $stockStmt->execute();

                // If there's a fine, update member's total fine
                if ($return['denda'] > 0) {
                    $dendaSql = "UPDATE anggota SET total_denda = total_denda + ? WHERE id_anggota = ?";
                    $dendaStmt = $conn->prepare($dendaSql);
                    $dendaStmt->bind_param("di", $return['denda'], $return['id_anggota']);
                    $dendaStmt->execute();
                    $dendaStmt->close();
                }

                // Create notification for member
                $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) VALUES (?, ?, ?, 'return_confirmed')";
                $notifStmt = $conn->prepare($notifSql);
                $judul = 'Pengembalian Dikonfirmasi';
                $pesan = 'Pengembalian buku "' . $return['judul_buku'] . '" telah dikonfirmasi oleh admin.' . 
                         ($return['denda'] > 0 ? ' Denda: ' . formatCurrency($return['denda']) : '');
                $notifStmt->bind_param("iss", $return['id_anggota'], $judul, $pesan);
                $notifStmt->execute();

                // Log activity
                $activityMsg = 'Mengkonfirmasi pengembalian buku "' . $return['judul_buku'] . '" dari anggota: ' . $return['username'];
                if ($return['denda'] > 0) {
                    $activityMsg .= ' (Denda: ' . formatCurrency($return['denda']) . ')';
                }
                logActivity($id_admin, 'petugas', $activityMsg);

                $conn->commit();

                $response['success'] = true;
                $response['message'] = 'Pengembalian berhasil dikonfirmasi' . 
                                       ($return['denda'] > 0 ? '. Denda telah ditambahkan ke akun anggota.' : '');

                $stmt->close();
                $updateReturnStmt->close();
                $updateLoanStmt->close();
                $stockStmt->close();
                $notifStmt->close();

            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Gagal mengkonfirmasi pengembalian: ' . $e->getMessage();
            }

            $conn->close();
        }
        break;

    case 'reject_return':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_pengembalian = intval($_POST['id_pengembalian']);
            $id_admin = $_SESSION['user_id'];

            $conn = getConnection();

            try {
                // Get return details
                $sql = "SELECT pg.*, b.judul_buku, a.username, a.id_anggota
                        FROM pengembalian pg
                        JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                        JOIN buku b ON p.id_buku = b.id_buku
                        JOIN anggota a ON p.id_anggota = a.id_anggota
                        WHERE pg.id_pengembalian = ? AND pg.status_denda = 'belum_lunas'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id_pengembalian);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    throw new Exception("Data pengembalian tidak ditemukan atau sudah diproses");
                }

                $return = $result->fetch_assoc();

                // Update return status to 'ditolak'
                $updateSql = "UPDATE pengembalian SET status_denda = 'ditolak' WHERE id_pengembalian = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("i", $id_pengembalian);
                $updateStmt->execute();

                // Create notification for member
                $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) VALUES (?, ?, ?, 'return_rejected')";
                $notifStmt = $conn->prepare($notifSql);
                $judul = 'Pengembalian Ditolak';
                $pesan = 'Pengembalian buku "' . $return['judul_buku'] . '" telah ditolak oleh admin. Silakan hubungi admin untuk informasi lebih lanjut.';
                $notifStmt->bind_param("iss", $return['id_anggota'], $judul, $pesan);
                $notifStmt->execute();

                // Log activity
                logActivity($id_admin, 'petugas', 'Menolak pengembalian buku "' . $return['judul_buku'] . '" dari anggota: ' . $return['username']);

                $response['success'] = true;
                $response['message'] = 'Pengembalian berhasil ditolak';

                $stmt->close();
                $updateStmt->close();
                $notifStmt->close();

            } catch (Exception $e) {
                $response['message'] = 'Gagal menolak pengembalian: ' . $e->getMessage();
            }

            $conn->close();
        }
        break;

    case 'export_laporan':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $type = sanitize($_POST['type']); // pdf or excel
            $report_type = sanitize($_POST['report_type']);
            $start_date = sanitize($_POST['start_date']);
            $end_date = sanitize($_POST['end_date']);
            $filter = $_POST['filter'] ?? '';

            // Generate report based on type
            $filename = 'laporan_' . $report_type . '_' . date('Ymd_His');

            if ($type === 'pdf') {
                // For PDF export, we would use TCPDF or similar
                // This is a simplified version
                $response['success'] = true;
                $response['message'] = 'PDF export functionality requires TCPDF library';
                $response['filename'] = $filename . '.pdf';
            } else {
                // For Excel export
                header('Content-Type: application/vnd.ms-excel');
                header('Content-Disposition: attachment; filename="' . $filename . '.xls"');

                $conn = getConnection();

                switch ($report_type) {
                    case 'buku':
                        $sql = "SELECT b.*, k.nama_kategori, p.nama_penulis, pb.nama_penerbit,
                                COALESCE(AVG(r.rating), 0) as rating_avg
                                FROM buku b
                                LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
                                LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
                                LEFT JOIN penerbit pb ON b.id_penerbit = pb.id_penerbit
                                LEFT JOIN rating r ON b.id_buku = r.id_buku";

                        if ($filter) {
                            $sql .= " WHERE k.nama_kategori = '" . $conn->real_escape_string($filter) . "'";
                        }

                        $sql .= " GROUP BY b.id_buku ORDER BY b.judul_buku";
                        $result = $conn->query($sql);

                        echo "No\tJudul Buku\tPenulis\tPenerbit\tKategori\tTahun Terbit\tStok\tRating\n";

                        $no = 1;
                        while ($row = $result->fetch_assoc()) {
                            echo $no++ . "\t";
                            echo $row['judul_buku'] . "\t";
                            echo $row['nama_penulis'] . "\t";
                            echo $row['nama_penerbit'] . "\t";
                            echo $row['nama_kategori'] . "\t";
                            echo $row['tahun_terbit'] . "\t";
                            echo $row['stok'] . "\t";
                            echo number_format($row['rating_avg'], 1) . "\n";
                        }
                        break;

                    case 'peminjaman':
                        $sql = "SELECT p.*, b.judul_buku, a.username,
                                DATE_FORMAT(p.tanggal_pinjam, '%d/%m/%Y') as tgl_pinjam,
                                DATE_FORMAT(p.tanggal_jatuh_tempo, '%d/%m/%Y') as tgl_jatuh_tempo
                                FROM peminjaman p
                                JOIN buku b ON p.id_buku = b.id_buku
                                JOIN anggota a ON p.id_anggota = a.id_anggota
                                WHERE 1=1";

                        if ($start_date && $end_date) {
                            $sql .= " AND DATE(p.tanggal_pinjam) BETWEEN '$start_date' AND '$end_date'";
                        }

                        if ($filter) {
                            $sql .= " AND p.status = '" . $conn->real_escape_string($filter) . "'";
                        }

                        $sql .= " ORDER BY p.tanggal_pinjam DESC";
                        $result = $conn->query($sql);

                        echo "No\tID\tJudul Buku\tAnggota\tTanggal Pinjam\tJatuh Tempo\tStatus\n";

                        $no = 1;
                        while ($row = $result->fetch_assoc()) {
                            echo $no++ . "\t";
                            echo $row['id_peminjaman'] . "\t";
                            echo $row['judul_buku'] . "\t";
                            echo $row['username'] . "\t";
                            echo $row['tgl_pinjam'] . "\t";
                            echo $row['tgl_jatuh_tempo'] . "\t";
                            echo $row['status'] . "\n";
                        }
                        break;

                    case 'anggota':
                        $sql = "SELECT a.*, 
                                (SELECT COUNT(*) FROM peminjaman WHERE id_anggota = a.id_anggota) as total_pinjam,
                                (SELECT COUNT(*) FROM peminjaman WHERE id_anggota = a.id_anggota AND status = 'dipinjam') as sedang_dipinjam
                                FROM anggota a
                                WHERE 1=1";

                        if ($filter) {
                            $sql .= " AND a.status = '" . $conn->real_escape_string($filter) . "'";
                        }

                        $sql .= " ORDER BY a.username";
                        $result = $conn->query($sql);

                        echo "No\tUsername\tEmail\tTotal Pinjam\tSedang Dipinjam\tStatus\tDenda\n";

                        $no = 1;
                        while ($row = $result->fetch_assoc()) {
                            echo $no++ . "\t";
                            echo $row['username'] . "\t";
                            echo $row['email'] . "\t";
                            echo $row['total_pinjam'] . "\t";
                            echo $row['sedang_dipinjam'] . "\t";
                            echo $row['status'] . "\t";
                            echo formatCurrency($row['total_denda']) . "\n";
                        }
                        break;

                    case 'denda':
                        $sql = "SELECT d.*, a.username, b.judul_buku,
                                DATE_FORMAT(d.tanggal_bayar, '%d/%m/%Y') as tgl_bayar,
                                DATE_FORMAT(d.tanggal_approve, '%d/%m/%Y') as tgl_approve
                                FROM denda d
                                JOIN anggota a ON d.id_anggota = a.id_anggota
                                JOIN pengembalian pg ON d.id_pengembalian = pg.id_pengembalian
                                JOIN buku b ON pg.id_buku = b.id_buku
                                WHERE 1=1";

                        if ($start_date && $end_date) {
                            $sql .= " AND DATE(d.tanggal_bayar) BETWEEN '$start_date' AND '$end_date'";
                        }

                        if ($filter) {
                            $sql .= " AND d.status = '" . $conn->real_escape_string($filter) . "'";
                        }

                        $sql .= " ORDER BY d.tanggal_bayar DESC";
                        $result = $conn->query($sql);

                        echo "No\tID\tAnggota\tBuku\tJumlah Denda\tMetode Bayar\tStatus\tTanggal Bayar\n";

                        $no = 1;
                        while ($row = $result->fetch_assoc()) {
                            echo $no++ . "\t";
                            echo $row['id_denda'] . "\t";
                            echo $row['username'] . "\t";
                            echo $row['judul_buku'] . "\t";
                            echo formatCurrency($row['jumlah_denda']) . "\t";
                            echo $row['metode_bayar'] . "\t";
                            echo $row['status'] . "\t";
                            echo $row['tgl_bayar'] . "\n";
                        }
                        break;
                }

                $conn->close();
                exit;
            }
        }
        break;

    case 'tambah_kategori':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nama_kategori = sanitize($_POST['nama_kategori']);
            $deskripsi = sanitize($_POST['deskripsi']);

            $conn = getConnection();

            // Check if category already exists
            $checkSql = "SELECT id_kategori FROM kategori WHERE nama_kategori = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("s", $nama_kategori);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $response['message'] = 'Kategori sudah ada';
                $checkStmt->close();
                $conn->close();
                break;
            }

            // Insert category
            $sql = "INSERT INTO kategori (nama_kategori, deskripsi) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $nama_kategori, $deskripsi);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menambahkan kategori baru: ' . $nama_kategori);

                $response['success'] = true;
                $response['message'] = 'Kategori berhasil ditambahkan';
                $response['id_kategori'] = $stmt->insert_id;
            } else {
                $response['message'] = 'Gagal menambahkan kategori: ' . $stmt->error;
            }

            $checkStmt->close();
            $stmt->close();
            $conn->close();
        }
        break;

    case 'tambah_penulis':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nama_penulis = sanitize($_POST['nama_penulis']);
            $biografi = sanitize($_POST['biografi']);

            $conn = getConnection();

            // Check if author already exists
            $checkSql = "SELECT id_penulis FROM penulis WHERE nama_penulis = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("s", $nama_penulis);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $response['message'] = 'Penulis sudah ada';
                $checkStmt->close();
                $conn->close();
                break;
            }

            // Insert author
            $sql = "INSERT INTO penulis (nama_penulis, biografi) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $nama_penulis, $biografi);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menambahkan penulis baru: ' . $nama_penulis);

                $response['success'] = true;
                $response['message'] = 'Penulis berhasil ditambahkan';
                $response['id_penulis'] = $stmt->insert_id;
            } else {
                $response['message'] = 'Gagal menambahkan penulis: ' . $stmt->error;
            }

            $checkStmt->close();
            $stmt->close();
            $conn->close();
        }
        break;

    case 'tambah_penerbit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nama_penerbit = sanitize($_POST['nama_penerbit']);
            $alamat = sanitize($_POST['alamat']);
            $telepon = sanitize($_POST['telepon']);

            $conn = getConnection();

            // Check if publisher already exists
            $checkSql = "SELECT id_penerbit FROM penerbit WHERE nama_penerbit = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("s", $nama_penerbit);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $response['message'] = 'Penerbit sudah ada';
                $checkStmt->close();
                $conn->close();
                break;
            }

            // Insert publisher
            $sql = "INSERT INTO penerbit (nama_penerbit, alamat, telepon) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $nama_penerbit, $alamat, $telepon);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menambahkan penerbit baru: ' . $nama_penerbit);

                $response['success'] = true;
                $response['message'] = 'Penerbit berhasil ditambahkan';
                $response['id_penerbit'] = $stmt->insert_id;
            } else {
                $response['message'] = 'Gagal menambahkan penerbit: ' . $stmt->error;
            }

            $checkStmt->close();
            $stmt->close();
            $conn->close();
        }
        break;

    case 'tambah_petugas':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = sanitize($_POST['username']);
            $email = sanitize($_POST['email']);
            $password = $_POST['password'];
            $level = sanitize($_POST['level']);

            $conn = getConnection();

            // Check if username or email already exists
            $checkSql = "SELECT id_petugas FROM petugas WHERE username = ? OR email = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ss", $username, $email);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $response['message'] = 'Username atau email sudah digunakan';
                $checkStmt->close();
                $conn->close();
                break;
            }

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert staff
            $sql = "INSERT INTO petugas (username, email, password, level, status) VALUES (?, ?, ?, ?, 'aktif')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $username, $email, $hashed_password, $level);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menambahkan petugas baru: ' . $username);

                $response['success'] = true;
                $response['message'] = 'Petugas berhasil ditambahkan';
                $response['id_petugas'] = $stmt->insert_id;
            } else {
                $response['message'] = 'Gagal menambahkan petugas: ' . $stmt->error;
            }

            $checkStmt->close();
            $stmt->close();
            $conn->close();
        }
        break;

    case 'update_petugas':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_petugas = intval($_POST['id_petugas']);
            $username = sanitize($_POST['username']);
            $email = sanitize($_POST['email']);
            $level = sanitize($_POST['level']);
            $status = sanitize($_POST['status']);

            $conn = getConnection();

            // Check if username or email already exists (excluding current user)
            $checkSql = "SELECT id_petugas FROM petugas WHERE (username = ? OR email = ?) AND id_petugas != ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ssi", $username, $email, $id_petugas);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $response['message'] = 'Username atau email sudah digunakan';
                $checkStmt->close();
                $conn->close();
                break;
            }

            // Update staff
            $sql = "UPDATE petugas SET username = ?, email = ?, level = ?, status = ?, updated_at = NOW() WHERE id_petugas = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssi", $username, $email, $level, $status, $id_petugas);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Memperbarui data petugas: ' . $username);

                $response['success'] = true;
                $response['message'] = 'Data petugas berhasil diperbarui';
            } else {
                $response['message'] = 'Gagal memperbarui data petugas: ' . $stmt->error;
            }

            $checkStmt->close();
            $stmt->close();
            $conn->close();
        }
        break;

    case 'hapus_petugas':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_petugas = intval($_POST['id_petugas']);

            $conn = getConnection();

            // Check if staff is trying to delete themselves
            if ($id_petugas == $_SESSION['user_id']) {
                $response['message'] = 'Anda tidak dapat menghapus akun sendiri';
                $conn->close();
                break;
            }

            // Delete staff
            $sql = "DELETE FROM petugas WHERE id_petugas = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id_petugas);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menghapus petugas ID: ' . $id_petugas);

                $response['success'] = true;
                $response['message'] = 'Petugas berhasil dihapus';
            } else {
                $response['message'] = 'Gagal menghapus petugas';
            }

            $stmt->close();
            $conn->close();
        }
        break;

    case 'reset_password_petugas':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_petugas = intval($_POST['id_petugas']);
            $new_password = $_POST['new_password'];

            $conn = getConnection();

            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update password
            $sql = "UPDATE petugas SET password = ?, updated_at = NOW() WHERE id_petugas = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $hashed_password, $id_petugas);

            if ($stmt->execute()) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Reset password petugas ID: ' . $id_petugas);

                $response['success'] = true;
                $response['message'] = 'Password berhasil direset';
            } else {
                $response['message'] = 'Gagal reset password';
            }

            $stmt->close();
            $conn->close();
        }
        break;

    case 'update_profile_admin':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_admin = $_SESSION['user_id'];
            $username = sanitize($_POST['username']);
            $email = sanitize($_POST['email']);

            $conn = getConnection();

            // Check if username or email already exists (excluding current user)
            $checkSql = "SELECT id_admin FROM admin WHERE (username = ? OR email = ?) AND id_admin != ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ssi", $username, $email, $id_admin);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $response['message'] = 'Username atau email sudah digunakan';
                break;
            }

            // Handle profile picture upload
            $foto_profil = null;
            if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['foto_profil'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                if (in_array($ext, $allowed)) {
                    $filename = 'admin_' . $id_admin . '_' . time() . '.' . $ext;
                    $destination = PROFILE_PATH . $filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $foto_profil = $filename;

                        // Delete old profile picture if not default
                        $oldSql = "SELECT foto_profil FROM admin WHERE id_admin = ?";
                        $oldStmt = $conn->prepare($oldSql);
                        $oldStmt->bind_param("i", $id_admin);
                        $oldStmt->execute();
                        $oldResult = $oldStmt->get_result();
                        $oldFoto = $oldResult->fetch_assoc()['foto_profil'];

                        if ($oldFoto !== 'default.png') {
                            $oldPath = PROFILE_PATH . $oldFoto;
                            if (file_exists($oldPath)) {
                                unlink($oldPath);
                            }
                        }
                        $oldStmt->close();
                    }
                }
            }

            // Update profile
            $updateSql = "UPDATE admin SET username = ?, email = ?";
            $params = [$username, $email];
            $types = "ss";

            if ($foto_profil) {
                $updateSql .= ", foto_profil = ?";
                $params[] = $foto_profil;
                $types .= "s";
            }

            $updateSql .= " WHERE id_admin = ?";
            $params[] = $id_admin;
            $types .= "i";

            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param($types, ...$params);

            if ($updateStmt->execute()) {
                // Update session
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;

                // Log activity
                logActivity($id_admin, 'petugas', 'Memperbarui profil admin');

                $response['success'] = true;
                $response['message'] = 'Profil berhasil diperbarui';
            } else {
                $response['message'] = 'Gagal memperbarui profil';
            }

            $checkStmt->close();
            $updateStmt->close();
            $conn->close();
        }
        break;

    case 'ubah_password_admin':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_admin = $_SESSION['user_id'];
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if ($new_password !== $confirm_password) {
                $response['message'] = 'Password baru dan konfirmasi password tidak cocok';
                break;
            }

            $conn = getConnection();

            // Verify current password
            $checkSql = "SELECT password FROM admin WHERE id_admin = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $id_admin);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows === 0) {
                $response['message'] = 'User tidak ditemukan';
                break;
            }

            $user = $checkResult->fetch_assoc();

            if (!password_verify($current_password, $user['password'])) {
                $response['message'] = 'Password saat ini salah';
                break;
            }

            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $updateSql = "UPDATE admin SET password = ? WHERE id_admin = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $hashed_password, $id_admin);

            if ($updateStmt->execute()) {
                // Log activity
                logActivity($id_admin, 'petugas', 'Mengubah password admin');

                $response['success'] = true;
                $response['message'] = 'Password berhasil diubah';
            } else {
                $response['message'] = 'Gagal mengubah password';
            }

            $checkStmt->close();
            $updateStmt->close();
            $conn->close();
        }
        break;

    case 'mark_notification_read_admin':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_notif = intval($_POST['id'] ?? 0);
            $id_admin = $_SESSION['user_id'];

            if ($id_notif <= 0) {
                $response['message'] = 'ID notifikasi tidak valid';
                break;
            }

            $conn = getConnection();

            // Check if admin_notifications table exists
            $result = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
            if ($result->num_rows === 0) {
                $response['message'] = 'Tabel notifikasi admin tidak ditemukan';
                $conn->close();
                break;
            }

            // Update notification as read
            $sql = "UPDATE admin_notifications SET dibaca = 'yes' WHERE id_notif = ? AND id_admin = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $id_notif, $id_admin);

            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Notifikasi berhasil ditandai sebagai dibaca';
            } else {
                $response['message'] = 'Gagal menandai notifikasi sebagai dibaca';
            }

            $stmt->close();
            $conn->close();
        }
        break;

    case 'clear_all_notifications_admin':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_admin = $_SESSION['user_id'];

            $conn = getConnection();

            // Check if admin_notifications table exists
            $result = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
            if ($result->num_rows === 0) {
                $response['message'] = 'Tabel notifikasi admin tidak ditemukan';
                $conn->close();
                break;
            }

            // Mark all notifications as read
            $sql = "UPDATE admin_notifications SET dibaca = 'yes' WHERE id_admin = ? AND dibaca = 'no'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id_admin);

            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Semua notifikasi berhasil ditandai sebagai dibaca';
            } else {
                $response['message'] = 'Gagal menandai notifikasi sebagai dibaca';
            }

            $stmt->close();
            $conn->close();
        }
        break;

    case 'get_statistics':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $conn = getConnection();

            // Get today's date
            $today = date('Y-m-d');

            // Get statistics
            $stats = [];

            // Total books
            $result = $conn->query("SELECT COUNT(*) as total FROM buku");
            $stats['total_books'] = $result->fetch_assoc()['total'];

            // Total available books
            $result = $conn->query("SELECT COUNT(*) as total FROM buku WHERE stok > 0");
            $stats['available_books'] = $result->fetch_assoc()['total'];

            // Total members
            $result = $conn->query("SELECT COUNT(*) as total FROM anggota WHERE status = 'aktif'");
            $stats['total_members'] = $result->fetch_assoc()['total'];

            // Total staff
            $result = $conn->query("SELECT COUNT(*) as total FROM petugas WHERE status = 'aktif'");
            $stats['total_staff'] = $result->fetch_assoc()['total'];

            // Today's loans
            $result = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE DATE(tanggal_pinjam) = '$today'");
            $stats['today_loans'] = $result->fetch_assoc()['total'];

            // Today's returns
            $result = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE DATE(tanggal_kembali) = '$today'");
            $stats['today_returns'] = $result->fetch_assoc()['total'];

            // Pending loans
            $result = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'");
            $stats['pending_loans'] = $result->fetch_assoc()['total'];

            // Overdue loans
            $result = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'terlambat'");
            $stats['overdue_loans'] = $result->fetch_assoc()['total'];

            // Total denda
            $result = $conn->query("SELECT COALESCE(SUM(jumlah_denda), 0) as total FROM denda WHERE status = 'lunas' AND DATE(tanggal_approve) = '$today'");
            $stats['today_fines'] = $result->fetch_assoc()['total'];

            // Monthly loans (last 30 days)
            $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
            $result = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE tanggal_pinjam >= '$thirty_days_ago'");
            $stats['monthly_loans'] = $result->fetch_assoc()['total'];

            // Monthly returns (last 30 days)
            $result = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE tanggal_kembali >= '$thirty_days_ago'");
            $stats['monthly_returns'] = $result->fetch_assoc()['total'];

            $conn->close();

            $response['success'] = true;
            $response['data'] = $stats;
        }
        break;

    case 'get_chart_data':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $type = $_GET['type'] ?? 'loans';
            $period = $_GET['period'] ?? 'month';

            $conn = getConnection();

            $chart_data = [];

            if ($type === 'loans') {
                if ($period === 'week') {
                    // Last 7 days
                    for ($i = 6; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $result = $conn->query("SELECT COUNT(*) as count FROM peminjaman WHERE DATE(tanggal_pinjam) = '$date'");
                        $row = $result->fetch_assoc();
                        $chart_data[] = [
                            'date' => date('d/m', strtotime($date)),
                            'count' => (int)$row['count']
                        ];
                    }
                } else {
                    // Last 30 days
                    for ($i = 29; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $result = $conn->query("SELECT COUNT(*) as count FROM peminjaman WHERE DATE(tanggal_pinjam) = '$date'");
                        $row = $result->fetch_assoc();
                        $chart_data[] = [
                            'date' => date('d/m', strtotime($date)),
                            'count' => (int)$row['count']
                        ];
                    }
                }
            } elseif ($type === 'returns') {
                if ($period === 'week') {
                    // Last 7 days
                    for ($i = 6; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $result = $conn->query("SELECT COUNT(*) as count FROM pengembalian WHERE DATE(tanggal_kembali) = '$date'");
                        $row = $result->fetch_assoc();
                        $chart_data[] = [
                            'date' => date('d/m', strtotime($date)),
                            'count' => (int)$row['count']
                        ];
                    }
                } else {
                    // Last 30 days
                    for ($i = 29; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $result = $conn->query("SELECT COUNT(*) as count FROM pengembalian WHERE DATE(tanggal_kembali) = '$date'");
                        $row = $result->fetch_assoc();
                        $chart_data[] = [
                            'date' => date('d/m', strtotime($date)),
                            'count' => (int)$row['count']
                        ];
                    }
                }
            }

            $conn->close();

            $response['success'] = true;
            $response['data'] = $chart_data;
        }
        break;

    case 'backup_database':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Create backup directory if not exists
            $backup_dir = '../backups/';
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0777, true);
            }

            // Generate backup filename
            $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';

            // Get database configuration
            require_once '../config/database.php';
            $config = include('../config/database.php');

            // Create backup command
            $command = "mysqldump --user={$config['username']} --password={$config['password']} --host={$config['host']} {$config['database']} > {$backup_file}";

            // Execute backup
            system($command, $output);

            if ($output === 0 && file_exists($backup_file)) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Melakukan backup database');

                $response['success'] = true;
                $response['message'] = 'Backup database berhasil dibuat';
                $response['filename'] = basename($backup_file);
                $response['filesize'] = filesize($backup_file);
                $response['filepath'] = $backup_file;
            } else {
                $response['message'] = 'Gagal membuat backup database';
            }
        }
        break;

    case 'restore_database':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $filename = sanitize($_POST['filename']);

            if (empty($filename)) {
                $response['message'] = 'Nama file backup tidak valid';
                break;
            }

            $backup_file = '../backups/' . $filename;

            if (!file_exists($backup_file)) {
                $response['message'] = 'File backup tidak ditemukan';
                break;
            }

            // Get database configuration
            require_once '../config/database.php';
            $config = include('../config/database.php');

            // Create restore command
            $command = "mysql --user={$config['username']} --password={$config['password']} --host={$config['host']} {$config['database']} < {$backup_file}";

            // Execute restore
            system($command, $output);

            if ($output === 0) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Melakukan restore database dari: ' . $filename);

                $response['success'] = true;
                $response['message'] = 'Restore database berhasil';
            } else {
                $response['message'] = 'Gagal melakukan restore database';
            }
        }
        break;

    case 'delete_backup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $filename = sanitize($_POST['filename']);

            if (empty($filename)) {
                $response['message'] = 'Nama file backup tidak valid';
                break;
            }

            $backup_file = '../backups/' . $filename;

            if (!file_exists($backup_file)) {
                $response['message'] = 'File backup tidak ditemukan';
                break;
            }

            if (unlink($backup_file)) {
                // Log activity
                logActivity($_SESSION['user_id'], 'petugas', 'Menghapus backup database: ' . $filename);

                $response['success'] = true;
                $response['message'] = 'File backup berhasil dihapus';
            } else {
                $response['message'] = 'Gagal menghapus file backup';
            }
        }
        break;

    case 'get_backup_list':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $backup_dir = '../backups/';
            
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0777, true);
            }

            $backups = [];
            $files = scandir($backup_dir);
            
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $filepath = $backup_dir . $file;
                    $backups[] = [
                        'filename' => $file,
                        'filesize' => filesize($filepath),
                        'filetime' => filemtime($filepath),
                        'filedate' => date('d/m/Y H:i:s', filemtime($filepath))
                    ];
                }
            }

            // Sort by newest first
            usort($backups, function($a, $b) {
                return $b['filetime'] - $a['filetime'];
            });

            $response['success'] = true;
            $response['data'] = $backups;
        }
        break;

    // New cases for pengembalian and denda management
    case 'get_pending_returns':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $conn = getConnection();
            
            $sql = "SELECT pg.*, 
                    p.tanggal_pinjam, p.tanggal_jatuh_tempo, p.status as status_peminjaman,
                    b.judul_buku, b.cover_buku,
                    a.username, a.email,
                    DATEDIFF(CURDATE(), pg.tanggal_kembali) as hari_menunggu_konfirmasi,
                    DATE_FORMAT(pg.tanggal_kembali, '%d/%m/%Y') as tanggal_kembali_formatted,
                    DATE_FORMAT(pg.created_at, '%d/%m/%Y %H:%i') as tanggal_request_formatted
                    FROM pengembalian pg
                    LEFT JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                    LEFT JOIN buku b ON pg.id_buku = b.id_buku
                    LEFT JOIN anggota a ON pg.id_anggota = a.id_anggota
                    WHERE pg.status_denda = 'belum_lunas'
                    AND p.status IN ('dipinjam', 'terlambat')
                    ORDER BY pg.tanggal_kembali ASC";
            
            $result = $conn->query($sql);
            
            if (!$result) {
                $response['message'] = 'Error query: ' . $conn->error;
                $conn->close();
                break;
            }
            
            $returns = [];
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $returns[] = $row;
                }
            }
            
            $response['success'] = true;
            $response['data'] = $returns;
            $response['total'] = count($returns);
            
            $conn->close();
        }
        break;

    case 'get_pending_fines':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $conn = getConnection();
            
            $sql = "SELECT d.*, 
                    a.username, a.email,
                    b.judul_buku, b.cover_buku,
                    pg.id_peminjaman, pg.tanggal_kembali, pg.terlambat_hari, pg.denda as denda_pengembalian,
                    DATE_FORMAT(d.tanggal_bayar, '%d/%m/%Y %H:%i') as tanggal_bayar_formatted,
                    DATE_FORMAT(d.created_at, '%d/%m/%Y %H:%i') as tanggal_request_formatted
                    FROM denda d
                    LEFT JOIN anggota a ON d.id_anggota = a.id_anggota
                    LEFT JOIN pengembalian pg ON d.id_pengembalian = pg.id_pengembalian
                    LEFT JOIN buku b ON pg.id_buku = b.id_buku
                    WHERE d.status = 'menunggu_approval'
                    ORDER BY d.tanggal_bayar ASC";
            
            $result = $conn->query($sql);
            
            if (!$result) {
                $response['message'] = 'Error query: ' . $conn->error;
                $conn->close();
                break;
            }
            
            $fines = [];
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $fines[] = $row;
                }
            }
            
            $response['success'] = true;
            $response['data'] = $fines;
            $response['total'] = count($fines);
            
            $conn->close();
        }
        break;

    case 'get_return_details':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $id_pengembalian = intval($_GET['id'] ?? 0);
            
            if ($id_pengembalian <= 0) {
                $response['message'] = 'ID pengembalian tidak valid';
                break;
            }
            
            $conn = getConnection();
            
            $sql = "SELECT pg.*, 
                    p.tanggal_pinjam, p.tanggal_jatuh_tempo, p.status as status_peminjaman,
                    b.judul_buku, b.cover_buku, b.deskripsi,
                    a.username, a.email, a.no_telepon, a.alamat,
                    k.nama_kategori,
                    pen.nama_penulis,
                    pub.nama_penerbit,
                    DATEDIFF(pg.tanggal_kembali, p.tanggal_jatuh_tempo) as hari_terlambat,
                    DATE_FORMAT(pg.tanggal_kembali, '%d/%m/%Y') as tanggal_kembali_formatted,
                    DATE_FORMAT(p.tanggal_pinjam, '%d/%m/%Y') as tanggal_pinjam_formatted,
                    DATE_FORMAT(p.tanggal_jatuh_tempo, '%d/%m/%Y') as tanggal_jatuh_tempo_formatted
                    FROM pengembalian pg
                    LEFT JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                    LEFT JOIN buku b ON pg.id_buku = b.id_buku
                    LEFT JOIN anggota a ON pg.id_anggota = a.id_anggota
                    LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
                    LEFT JOIN penulis pen ON b.id_penulis = pen.id_penulis
                    LEFT JOIN penerbit pub ON b.id_penerbit = pub.id_penerbit
                    WHERE pg.id_pengembalian = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $response['message'] = 'Failed to prepare query';
                $conn->close();
                break;
            }
            
            $stmt->bind_param("i", $id_pengembalian);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to execute query';
                $stmt->close();
                $conn->close();
                break;
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $response['message'] = 'Data pengembalian tidak ditemukan';
                $stmt->close();
                $conn->close();
                break;
            }
            
            $return_data = $result->fetch_assoc();
            
            // Calculate fine details
            if ($return_data['terlambat_hari'] > 0) {
                $return_data['denda_calculated'] = calculateDenda($return_data['terlambat_hari']);
            } else {
                $return_data['denda_calculated'] = 0;
            }
            
            $response['success'] = true;
            $response['data'] = $return_data;
            
            $stmt->close();
            $conn->close();
        }
        break;

    case 'get_fine_details':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $id_denda = intval($_GET['id'] ?? 0);
            
            if ($id_denda <= 0) {
                $response['message'] = 'ID denda tidak valid';
                break;
            }
            
            $conn = getConnection();
            
            $sql = "SELECT d.*, 
                    a.username, a.email, a.no_telepon,
                    b.judul_buku, b.cover_buku,
                    pg.id_peminjaman, pg.tanggal_kembali, pg.terlambat_hari, pg.denda as denda_pengembalian,
                    p.tanggal_pinjam, p.tanggal_jatuh_tempo,
                    DATE_FORMAT(d.tanggal_bayar, '%d/%m/%Y %H:%i') as tanggal_bayar_formatted,
                    DATE_FORMAT(d.created_at, '%d/%m/%Y %H:%i') as tanggal_request_formatted
                    FROM denda d
                    LEFT JOIN anggota a ON d.id_anggota = a.id_anggota
                    LEFT JOIN pengembalian pg ON d.id_pengembalian = pg.id_pengembalian
                    LEFT JOIN buku b ON pg.id_buku = b.id_buku
                    LEFT JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                    WHERE d.id_denda = ? AND d.status = 'menunggu_approval'";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $response['message'] = 'Failed to prepare query';
                $conn->close();
                break;
            }
            
            $stmt->bind_param("i", $id_denda);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to execute query';
                $stmt->close();
                $conn->close();
                break;
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $response['message'] = 'Data denda tidak ditemukan';
                $stmt->close();
                $conn->close();
                break;
            }
            
            $fine_data = $result->fetch_assoc();
            
            // Check if payment proof exists
            if ($fine_data['bukti_bayar']) {
                $fine_data['bukti_url'] = SITE_URL . 'uploads/denda/' . $fine_data['bukti_bayar'];
            }
            
            $response['success'] = true;
            $response['data'] = $fine_data;
            
            $stmt->close();
            $conn->close();
        }
        break;

    case 'bulk_approve_loans':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $loan_ids = $_POST['loan_ids'] ?? [];
            $id_admin = $_SESSION['user_id'];
            
            if (empty($loan_ids)) {
                $response['message'] = 'Tidak ada peminjaman yang dipilih';
                break;
            }
            
            // Convert to array if not already
            if (!is_array($loan_ids)) {
                $loan_ids = [$loan_ids];
            }
            
            $conn = getConnection();
            
            try {
                $conn->begin_transaction();
                
                $approved = 0;
                $failed = 0;
                $failed_ids = [];
                
                foreach ($loan_ids as $id_peminjaman) {
                    $id_peminjaman = intval($id_peminjaman);
                    
                    if ($id_peminjaman <= 0) continue;
                    
                    try {
                        // Check if loan exists and is pending
                        $checkSql = "SELECT p.*, b.stok, a.id_anggota, b.judul_buku, a.username
                                    FROM peminjaman p
                                    JOIN buku b ON p.id_buku = b.id_buku
                                    JOIN anggota a ON p.id_anggota = a.id_anggota
                                    WHERE p.id_peminjaman = ? AND p.status = 'pending'";
                        
                        $checkStmt = $conn->prepare($checkSql);
                        $checkStmt->bind_param("i", $id_peminjaman);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        
                        if ($checkResult->num_rows === 0) {
                            $failed++;
                            $failed_ids[] = $id_peminjaman;
                            continue;
                        }
                        
                        $loan = $checkResult->fetch_assoc();
                        
                        // Check stock
                        if ($loan['stok'] <= 0) {
                            $failed++;
                            $failed_ids[] = $id_peminjaman;
                            continue;
                        }
                        
                        // Approve the loan
                        $updateSql = "UPDATE peminjaman 
                                     SET status = 'dipinjam', 
                                         tanggal_pinjam = NOW(), 
                                         tanggal_jatuh_tempo = DATE_ADD(NOW(), INTERVAL 7 DAY)
                                     WHERE id_peminjaman = ?";
                        
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->bind_param("i", $id_peminjaman);
                        $updateStmt->execute();
                        
                        // Update stock
                        $stockSql = "UPDATE buku SET stok = stok - 1, jumlah_pinjam = jumlah_pinjam + 1 WHERE id_buku = ?";
                        $stockStmt = $conn->prepare($stockSql);
                        $stockStmt->bind_param("i", $loan['id_buku']);
                        $stockStmt->execute();
                        
                        // Create notification
                        $notifSql = "INSERT INTO notifikasi (id_anggota, judul, pesan, tipe) 
                                    VALUES (?, ?, ?, 'approval')";
                        $notifStmt = $conn->prepare($notifSql);
                        $judul = 'Peminjaman Disetujui';
                        $pesan = 'Selamat! Permintaan peminjaman buku "' . $loan['judul_buku'] . '" telah disetujui.';
                        $notifStmt->bind_param("iss", $loan['id_anggota'], $judul, $pesan);
                        $notifStmt->execute();
                        
                        // Log activity
                        logActivity($id_admin, 'petugas', 'Bulk approve peminjaman ID: ' . $id_peminjaman);
                        
                        $approved++;
                        
                        // Close statements
                        $checkStmt->close();
                        $updateStmt->close();
                        $stockStmt->close();
                        $notifStmt->close();
                        
                    } catch (Exception $e) {
                        $failed++;
                        $failed_ids[] = $id_peminjaman;
                        error_log("Bulk approve error for loan $id_peminjaman: " . $e->getMessage());
                    }
                }
                
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = "Berhasil menyetujui $approved peminjaman. Gagal: $failed.";
                if (!empty($failed_ids)) {
                    $response['failed_ids'] = $failed_ids;
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Gagal memproses bulk approve: ' . $e->getMessage();
            }
            
            $conn->close();
        }
        break;

    default:
        $response['message'] = 'Aksi tidak dikenali';
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
?>