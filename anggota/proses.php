<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Only anggota can access this
requireAnggota();

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Aksi tidak valid'];

switch ($action) {
    case 'pinjam_buku':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_buku = intval($_POST['id_buku']);
            $id_anggota = $_SESSION['user_id'];

            $conn = getConnection();

            // Check if member has unpaid fines
            $checkDenda = $conn->query("SELECT total_denda FROM anggota WHERE id_anggota = $id_anggota AND total_denda > 0");
            if ($checkDenda->num_rows > 0) {
                $response['message'] = 'Anda memiliki denda yang belum dibayar. Silakan lunasi terlebih dahulu.';
                $conn->close();
                break;
            }

            // Check if book is available
            $checkBook = $conn->query("SELECT stok, judul_buku FROM buku WHERE id_buku = $id_buku AND stok > 0");
            if ($checkBook->num_rows === 0) {
                $response['message'] = 'Buku tidak tersedia untuk dipinjam';
                $conn->close();
                break;
            }
            
            $bookData = $checkBook->fetch_assoc();
            $bookTitle = $bookData['judul_buku'];

            // Check if member already has a pending request for this book
            $checkPending = $conn->prepare("SELECT id_peminjaman FROM peminjaman WHERE id_anggota = ? AND id_buku = ? AND status = 'pending'");
            $checkPending->bind_param("ii", $id_anggota, $id_buku);
            $checkPending->execute();
            if ($checkPending->get_result()->num_rows > 0) {
                $response['message'] = 'Anda sudah mengajukan permintaan peminjaman untuk buku ini. Tunggu konfirmasi admin.';
                $checkPending->close();
                $conn->close();
                break;
            }
            $checkPending->close();

            // Calculate due date (7 days from now)
            $tanggal_pinjam = date('Y-m-d');
            $tanggal_jatuh_tempo = date('Y-m-d', strtotime('+7 days'));

            // Insert peminjaman record with pending status
            $sql = "INSERT INTO peminjaman (id_anggota, id_buku, tanggal_pinjam, tanggal_jatuh_tempo, status) 
                    VALUES (?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiss", $id_anggota, $id_buku, $tanggal_pinjam, $tanggal_jatuh_tempo);

            if ($stmt->execute()) {
                $id_peminjaman = $conn->insert_id;

                // Create notification for admin
                $admin_result = $conn->query("SELECT id_admin FROM admin LIMIT 1");
                if ($admin_result->num_rows > 0) {
                    $admin = $admin_result->fetch_assoc();

                    // Get member name
                    $member_result = $conn->query("SELECT username FROM anggota WHERE id_anggota = $id_anggota");
                    $member_name = "Member";
                    if ($member_result->num_rows > 0) {
                        $member = $member_result->fetch_assoc();
                        $member_name = $member['username'];
                    }

                    // Check if admin_notifications table exists, create if not
                    $checkTable = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
                    if ($checkTable->num_rows === 0) {
                        $conn->query("CREATE TABLE admin_notifications (
                            id_notif INT AUTO_INCREMENT PRIMARY KEY,
                            id_admin INT NOT NULL,
                            tipe VARCHAR(50),
                            judul VARCHAR(255),
                            pesan TEXT,
                            id_referensi INT,
                            dibaca ENUM('no', 'yes') DEFAULT 'no',
                            dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP
                        )");
                    }

                    $notif_sql = "INSERT INTO admin_notifications (id_admin, tipe, judul, pesan, id_referensi, dibuat_pada)
                                 VALUES (?, 'peminjaman_pending', 'Permintaan Peminjaman Baru', ?, ?, NOW())";
                    $notif_stmt = $conn->prepare($notif_sql);
                    $message = "Member $member_name mengajukan permintaan peminjaman buku '$bookTitle'";
                    $notif_stmt->bind_param("isi", $admin['id_admin'], $message, $id_peminjaman);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }

                // Log activity
                logActivity($id_anggota, 'anggota', 'Mengajukan permintaan peminjaman buku: ' . $bookTitle . ' (ID: ' . $id_peminjaman . ')');

                $response['success'] = true;
                $response['message'] = 'Permintaan peminjaman berhasil diajukan. Tunggu konfirmasi dari admin.';
                $response['id_peminjaman'] = $id_peminjaman;
            } else {
                $response['message'] = 'Gagal mengajukan permintaan peminjaman: ' . $stmt->error;
            }

            $stmt->close();
            $conn->close();
        }
        break;
        
    case 'kembalikan_buku':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_peminjaman = intval($_POST['id_peminjaman']);
            $id_anggota = $_SESSION['user_id'];

            $conn = getConnection();

            // Check if member already has a pending return request for this loan
            $checkPending = $conn->prepare("SELECT id_pengembalian FROM pengembalian WHERE id_peminjaman = ? AND id_anggota = ? AND status_denda = 'belum_lunas'");
            $checkPending->bind_param("ii", $id_peminjaman, $id_anggota);
            $checkPending->execute();
            if ($checkPending->get_result()->num_rows > 0) {
                $response['message'] = 'Anda sudah mengajukan permintaan pengembalian untuk peminjaman ini. Tunggu konfirmasi admin.';
                $checkPending->close();
                $conn->close();
                break;
            }
            $checkPending->close();

            // Get peminjaman details
            $sql = "SELECT p.*, b.id_buku, b.judul_buku, b.stok
                    FROM peminjaman p
                    JOIN buku b ON p.id_buku = b.id_buku
                    WHERE p.id_peminjaman = ? AND p.id_anggota = ? AND p.status = 'dipinjam'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $id_peminjaman, $id_anggota);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $response['message'] = 'Data peminjaman tidak ditemukan';
                $stmt->close();
                $conn->close();
                break;
            }

            $peminjaman = $result->fetch_assoc();
            $tanggal_kembali = date('Y-m-d');

            // Calculate late days
            $terlambat_hari = 0;
            $denda = 0;

            if (strtotime($tanggal_kembali) > strtotime($peminjaman['tanggal_jatuh_tempo'])) {
                $terlambat_hari = floor((strtotime($tanggal_kembali) - strtotime($peminjaman['tanggal_jatuh_tempo'])) / (60 * 60 * 24));
                $denda = calculateDenda($terlambat_hari);
            }

            // Insert pengembalian record with pending status
            $returnSql = "INSERT INTO pengembalian (id_peminjaman, id_anggota, id_buku, tanggal_kembali, terlambat_hari, denda, status_denda)
                         VALUES (?, ?, ?, ?, ?, ?, 'belum_lunas')";
            $returnStmt = $conn->prepare($returnSql);
            $returnStmt->bind_param("iiisii", $id_peminjaman, $id_anggota, $peminjaman['id_buku'], $tanggal_kembali, $terlambat_hari, $denda);

            if ($returnStmt->execute()) {
                $id_pengembalian = $conn->insert_id;

                // Create notification for admin
                $admin_result = $conn->query("SELECT id_admin FROM admin LIMIT 1");
                if ($admin_result->num_rows > 0) {
                    $admin = $admin_result->fetch_assoc();

                    // Get member name
                    $member_result = $conn->query("SELECT username FROM anggota WHERE id_anggota = $id_anggota");
                    $member_name = "Member";
                    if ($member_result->num_rows > 0) {
                        $member = $member_result->fetch_assoc();
                        $member_name = $member['username'];
                    }

                    // Check if admin_notifications table exists
                    $checkTable = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
                    if ($checkTable->num_rows === 0) {
                        $conn->query("CREATE TABLE admin_notifications (
                            id_notif INT AUTO_INCREMENT PRIMARY KEY,
                            id_admin INT NOT NULL,
                            tipe VARCHAR(50),
                            judul VARCHAR(255),
                            pesan TEXT,
                            id_referensi INT,
                            dibaca ENUM('no', 'yes') DEFAULT 'no',
                            dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP
                        )");
                    }

                    $notif_sql = "INSERT INTO admin_notifications (id_admin, tipe, judul, pesan, id_referensi, dibuat_pada)
                                 VALUES (?, 'pengembalian_pending', 'Permintaan Pengembalian Baru', ?, ?, NOW())";
                    $notif_stmt = $conn->prepare($notif_sql);
                    $message = "Member $member_name mengajukan permintaan pengembalian buku '{$peminjaman['judul_buku']}'" . ($denda > 0 ? " dengan denda Rp " . number_format($denda) : "");
                    $notif_stmt->bind_param("isi", $admin['id_admin'], $message, $id_pengembalian);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }

                // Log activity
                logActivity($id_anggota, 'anggota', 'Mengajukan permintaan pengembalian buku "' . $peminjaman['judul_buku'] . '"' . ($denda > 0 ? ' dengan denda Rp ' . number_format($denda) : ''));

                $response['success'] = true;
                $response['message'] = 'Permintaan pengembalian berhasil diajukan. Tunggu konfirmasi dari admin.' . ($denda > 0 ? ' Denda: Rp ' . number_format($denda) : '');
                $response['id_pengembalian'] = $id_pengembalian;
            } else {
                $response['message'] = 'Gagal mengajukan permintaan pengembalian: ' . $returnStmt->error;
            }

            $stmt->close();
            $returnStmt->close();
            $conn->close();
        }
        break;

    case 'bayar_denda':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_anggota = $_SESSION['user_id'];
            $id_peminjaman = intval($_POST['id_peminjaman']);
            $metode = sanitize($_POST['metode']);
            $jumlah_denda = intval($_POST['jumlah_denda']);

            $conn = getConnection();

            // Get peminjaman details
            $peminjamanSql = "SELECT p.*, b.judul_buku, b.id_buku
                             FROM peminjaman p
                             JOIN buku b ON p.id_buku = b.id_buku
                             WHERE p.id_peminjaman = ? AND p.id_anggota = ? AND p.status = 'terlambat'";
            $peminjamanStmt = $conn->prepare($peminjamanSql);
            $peminjamanStmt->bind_param("ii", $id_peminjaman, $id_anggota);
            $peminjamanStmt->execute();
            $peminjamanResult = $peminjamanStmt->get_result();

            if ($peminjamanResult->num_rows === 0) {
                $response['message'] = 'Data peminjaman terlambat tidak ditemukan';
                $peminjamanStmt->close();
                $conn->close();
                break;
            }

            $peminjamanData = $peminjamanResult->fetch_assoc();
            $judul_buku = $peminjamanData['judul_buku'];
            $id_buku = $peminjamanData['id_buku'];

            // Get pengembalian record or create one
            $pengembalianSql = "SELECT id_pengembalian FROM pengembalian WHERE id_peminjaman = ?";
            $pengembalianStmt = $conn->prepare($pengembalianSql);
            $pengembalianStmt->bind_param("i", $id_peminjaman);
            $pengembalianStmt->execute();
            $pengembalianResult = $pengembalianStmt->get_result();

            $id_pengembalian = null;
            if ($pengembalianResult->num_rows === 0) {
                // Create pengembalian record
                $terlambat_hari = floor((strtotime(date('Y-m-d')) - strtotime($peminjamanData['tanggal_jatuh_tempo'])) / (60 * 60 * 24));
                $denda_amount = calculateDenda($terlambat_hari);
                $tanggal_kembali = date('Y-m-d');

                $insertPengembalianSql = "INSERT INTO pengembalian (id_peminjaman, id_anggota, id_buku, tanggal_kembali, terlambat_hari, denda, status_denda)
                                         VALUES (?, ?, ?, ?, ?, ?, 'belum_lunas')";
                $insertPengembalianStmt = $conn->prepare($insertPengembalianSql);
                $insertPengembalianStmt->bind_param("iiisid", $id_peminjaman, $id_anggota, $id_buku, $tanggal_kembali, $terlambat_hari, $denda_amount);
                $insertPengembalianStmt->execute();
                $id_pengembalian = $conn->insert_id;
                $insertPengembalianStmt->close();
            } else {
                $pengembalianData = $pengembalianResult->fetch_assoc();
                $id_pengembalian = $pengembalianData['id_pengembalian'];
            }

            // Handle bukti transfer upload
            $bukti_bayar = null;
            if ($metode === 'transfer') {
                if (isset($_FILES['bukti_transfer']) && $_FILES['bukti_transfer']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['bukti_transfer'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];

                    if (in_array($ext, $allowed)) {
                        $filename = 'denda_' . $id_anggota . '_' . $id_peminjaman . '_' . time() . '.' . $ext;
                        $destination = '../uploads/denda/' . $filename;

                        // Create directory if not exists
                        if (!file_exists('../uploads/denda')) {
                            mkdir('../uploads/denda', 0777, true);
                        }

                        if (move_uploaded_file($file['tmp_name'], $destination)) {
                            $bukti_bayar = $filename;
                        }
                    }
                }

                if (!$bukti_bayar) {
                    $response['message'] = 'Bukti transfer harus diupload untuk metode transfer';
                    $peminjamanStmt->close();
                    $pengembalianStmt->close();
                    $conn->close();
                    break;
                }
            }

            // Insert denda record with pending approval status
            $insertDendaSql = "INSERT INTO denda (id_anggota, id_pengembalian, jumlah_denda, status, metode_bayar, bukti_bayar, tanggal_bayar)
                              VALUES (?, ?, ?, 'menunggu_approval', ?, ?, NOW())";
            $insertDendaStmt = $conn->prepare($insertDendaSql);
            $insertDendaStmt->bind_param("iiiss", $id_anggota, $id_pengembalian, $jumlah_denda, $metode, $bukti_bayar);

            if ($insertDendaStmt->execute()) {
                $id_denda = $conn->insert_id;

                // Create notification for admin
                $admin_result = $conn->query("SELECT id_admin FROM admin LIMIT 1");
                if ($admin_result->num_rows > 0) {
                    $admin = $admin_result->fetch_assoc();

                    // Check if admin_notifications table exists
                    $checkTable = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
                    if ($checkTable->num_rows === 0) {
                        $conn->query("CREATE TABLE admin_notifications (
                            id_notif INT AUTO_INCREMENT PRIMARY KEY,
                            id_admin INT NOT NULL,
                            tipe VARCHAR(50),
                            judul VARCHAR(255),
                            pesan TEXT,
                            id_referensi INT,
                            dibaca ENUM('no', 'yes') DEFAULT 'no',
                            dibuat_pada DATETIME DEFAULT CURRENT_TIMESTAMP
                        )");
                    }

                    $member_result = $conn->query("SELECT username FROM anggota WHERE id_anggota = $id_anggota");
                    $member_name = "Member";
                    if ($member_result->num_rows > 0) {
                        $member = $member_result->fetch_assoc();
                        $member_name = $member['username'];
                    }

                    $notif_sql = "INSERT INTO admin_notifications (id_admin, tipe, judul, pesan, id_referensi, dibuat_pada)
                                 VALUES (?, 'denda_pending', 'Permintaan Pembayaran Denda', ?, ?, NOW())";
                    $notif_stmt = $conn->prepare($notif_sql);
                    $message = "Member $member_name mengajukan pembayaran denda sebesar Rp " . number_format($jumlah_denda) . " untuk buku '$judul_buku'";
                    $notif_stmt->bind_param("isi", $admin['id_admin'], $message, $id_denda);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }

                // Log activity
                logActivity($id_anggota, 'anggota', 'Mengajukan pembayaran denda sebesar ' . formatCurrency($jumlah_denda) . ' untuk buku: ' . $judul_buku);

                $response['success'] = true;
                $response['message'] = 'Pembayaran denda berhasil diajukan. Menunggu persetujuan admin.';
                $response['id_denda'] = $id_denda;
            } else {
                $response['message'] = 'Gagal mengajukan pembayaran denda: ' . $insertDendaStmt->error;
            }

            $peminjamanStmt->close();
            $pengembalianStmt->close();
            $insertDendaStmt->close();
            $conn->close();
        }
        break;
        
    case 'beri_rating':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_buku = intval($_POST['id_buku']);
            $rating = intval($_POST['rating']);
            $id_anggota = $_SESSION['user_id'];
            
            if ($rating < 1 || $rating > 5) {
                $response['message'] = 'Rating harus antara 1-5';
                break;
            }
            
            $conn = getConnection();
            
            // Check if already rated
            $checkSql = "SELECT id_rating FROM rating WHERE id_anggota = ? AND id_buku = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ii", $id_anggota, $id_buku);
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
                $insertStmt->bind_param("iii", $id_anggota, $id_buku, $rating);
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
            
            // Log activity
            logActivity($id_anggota, 'anggota', 'Memberikan rating ' . $rating . ' bintang untuk buku ID: ' . $id_buku);
            
            $response['success'] = true;
            $response['message'] = 'Rating berhasil disimpan';
            $response['avg_rating'] = number_format($calcData['avg_rating'], 1);
            $response['count'] = $calcData['count'];
            
            $checkStmt->close();
            $calcStmt->close();
            $updateBookStmt->close();
            $conn->close();
        }
        break;
        
    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_anggota = $_SESSION['user_id'];
            $username = sanitize($_POST['username']);
            $email = sanitize($_POST['email']);
            
            $conn = getConnection();
            
            // Check if username or email already exists (excluding current user)
            $checkSql = "SELECT id_anggota FROM anggota WHERE (username = ? OR email = ?) AND id_anggota != ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ssi", $username, $email, $id_anggota);
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
                    $filename = 'profile_' . $id_anggota . '_' . time() . '.' . $ext;
                    $destination = PROFILE_PATH . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $foto_profil = $filename;
                        
                        // Delete old profile picture if not default
                        $oldSql = "SELECT foto_profil FROM anggota WHERE id_anggota = ?";
                        $oldStmt = $conn->prepare($oldSql);
                        $oldStmt->bind_param("i", $id_anggota);
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
            $updateSql = "UPDATE anggota SET username = ?, email = ?";
            $params = [$username, $email];
            $types = "ss";
            
            if ($foto_profil) {
                $updateSql .= ", foto_profil = ?";
                $params[] = $foto_profil;
                $types .= "s";
            }
            
            $updateSql .= " WHERE id_anggota = ?";
            $params[] = $id_anggota;
            $types .= "i";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param($types, ...$params);
            
            if ($updateStmt->execute()) {
                // Update session
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                
                // Log activity
                logActivity($id_anggota, 'anggota', 'Memperbarui profil');
                
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
    
    case 'submit_review':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_buku = intval($_POST['id_buku'] ?? 0);
            $rating = intval($_POST['rating'] ?? 0);
            $komentar = sanitize($_POST['komentar'] ?? '');
            $id_anggota = $_SESSION['user_id'];

            // Validate input
            if ($id_buku <= 0) {
                $response['message'] = 'ID buku tidak valid';
                break;
            }

            if ($rating < 1 || $rating > 5) {
                $response['message'] = 'Rating harus antara 1-5';
                break;
            }

            if (strlen($komentar) > 500) {
                $response['message'] = 'Komentar maksimal 500 karakter';
                break;
            }

            $conn = getConnection();

            // Check if book exists
            $bookCheckSql = "SELECT id_buku FROM buku WHERE id_buku = ?";
            $bookCheckStmt = $conn->prepare($bookCheckSql);
            $bookCheckStmt->bind_param("i", $id_buku);
            $bookCheckStmt->execute();
            $bookCheckResult = $bookCheckStmt->get_result();

            if ($bookCheckResult->num_rows === 0) {
                $response['message'] = 'Buku tidak ditemukan';
                $bookCheckStmt->close();
                $conn->close();
                break;
            }
            $bookCheckStmt->close();

            // Start transaction for data consistency
            $conn->begin_transaction();

            try {
                // Check if already reviewed
                $checkSql = "SELECT id_rating FROM rating WHERE id_anggota = ? AND id_buku = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("ii", $id_anggota, $id_buku);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();

                $isUpdate = $checkResult->num_rows > 0;

                if ($isUpdate) {
                    // Update existing review
                    $ratingRow = $checkResult->fetch_assoc();
                    $updateSql = "UPDATE rating SET rating = ?, komentar = ?, created_at = NOW() WHERE id_rating = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("isi", $rating, $komentar, $ratingRow['id_rating']);
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    // Insert new review
                    $insertSql = "INSERT INTO rating (id_anggota, id_buku, rating, komentar) VALUES (?, ?, ?, ?)";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param("iiis", $id_anggota, $id_buku, $rating, $komentar);
                    $insertStmt->execute();
                    $insertStmt->close();
                }

                // Recalculate average rating
                $calcSql = "SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as count FROM rating WHERE id_buku = ?";
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

                // Log activity
                $activityMessage = $isUpdate ? 'Memperbarui ulasan' : 'Memberikan ulasan baru';
                logActivity($id_anggota, 'anggota', $activityMessage . ' untuk buku ID: ' . $id_buku);

                $conn->commit();

                $response['success'] = true;
                $response['message'] = 'Ulasan berhasil disimpan';
                $response['is_update'] = $isUpdate;

                $checkStmt->close();
                $calcStmt->close();
                $updateBookStmt->close();

            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Gagal menyimpan ulasan: ' . $e->getMessage();
            }

            $conn->close();
        }
        break;

    case 'ubah_password':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_anggota = $_SESSION['user_id'];
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if ($new_password !== $confirm_password) {
                $response['message'] = 'Password baru dan konfirmasi password tidak cocok';
                break;
            }
            
            $conn = getConnection();
            
            // Verify current password
            $checkSql = "SELECT password FROM anggota WHERE id_anggota = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $id_anggota);
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
            $updateSql = "UPDATE anggota SET password = ? WHERE id_anggota = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $hashed_password, $id_anggota);
            
            if ($updateStmt->execute()) {
                // Log activity
                logActivity($id_anggota, 'anggota', 'Mengubah password');
                
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
        
    case 'mark_notification_read':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id_notifikasi = intval($_POST['id'] ?? 0);
            $id_anggota = $_SESSION['user_id'];

            if ($id_notifikasi <= 0) {
                $response['message'] = 'ID notifikasi tidak valid';
                break;
            }

            $conn = getConnection();

            // Update notification as read
            $sql = "UPDATE notifikasi SET dibaca = TRUE WHERE id_notifikasi = ? AND id_anggota = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $id_notifikasi, $id_anggota);

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

    default:
        $response['message'] = 'Aksi tidak dikenali';
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
?>