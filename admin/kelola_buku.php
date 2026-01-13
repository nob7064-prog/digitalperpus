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
$page_title = 'Kelola Buku';
$page_icon = 'fas fa-book';

// Initialize variables
$books = [];
$categories = [];
$authors = [];
$publishers = [];
$error_message = '';
$success_message = '';
$total_books = 0;
$total_available = 0;
$total_out_of_stock = 0;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_book':
            // Process add book
            $result = addNewBook($_POST, $_FILES);
            if ($result['success']) {
                $success_message = $result['message'];
            } else {
                $error_message = $result['message'];
            }
            break;
            
        case 'update_book':
            // Process update book
            $result = updateBook($_POST, $_FILES);
            if ($result['success']) {
                $success_message = $result['message'];
            } else {
                $error_message = $result['message'];
            }
            break;
            
        case 'delete_book':
            $id_buku = intval($_POST['id_buku'] ?? 0);
            if ($id_buku > 0) {
                // Process delete book
                $result = deleteBook($id_buku);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
            }
            break;
    }
}

// Function to add new book
function addNewBook($data, $files) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Validate required fields
        $required = ['judul_buku', 'id_kategori', 'stok'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field $field harus diisi");
            }
        }
        
        // Handle cover upload
        $cover_filename = 'default.jpg';
        if (isset($files['cover_buku']) && $files['cover_buku']['error'] === UPLOAD_ERR_OK) {
            $cover_file = $files['cover_buku'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($cover_file['type'], $allowed_types)) {
                throw new Exception("Format file cover tidak didukung. Gunakan JPG, PNG, atau GIF");
            }
            
            if ($cover_file['size'] > $max_size) {
                throw new Exception("Ukuran file cover maksimal 2MB");
            }
            
            $cover_ext = pathinfo($cover_file['name'], PATHINFO_EXTENSION);
            $cover_filename = 'cover_' . time() . '_' . uniqid() . '.' . $cover_ext;
            $cover_path = '../uploads/covers/' . $cover_filename;
            
            if (!move_uploaded_file($cover_file['tmp_name'], $cover_path)) {
                throw new Exception("Gagal mengupload cover buku");
            }
        }
        
        // Handle PDF upload
        $pdf_filename = null;
        if (isset($files['file_pdf']) && $files['file_pdf']['error'] === UPLOAD_ERR_OK) {
            $pdf_file = $files['file_pdf'];
            $allowed_pdf_types = ['application/pdf'];
            $max_pdf_size = 10 * 1024 * 1024; // 10MB
            
            if (!in_array($pdf_file['type'], $allowed_pdf_types)) {
                throw new Exception("Format file harus PDF");
            }
            
            if ($pdf_file['size'] > $max_pdf_size) {
                throw new Exception("Ukuran file PDF maksimal 10MB");
            }
            
            $pdf_ext = pathinfo($pdf_file['name'], PATHINFO_EXTENSION);
            $pdf_filename = 'book_' . time() . '_' . uniqid() . '.' . $pdf_ext;
            $pdf_path = '../uploads/books/' . $pdf_filename;
            
            if (!move_uploaded_file($pdf_file['tmp_name'], $pdf_path)) {
                throw new Exception("Gagal mengupload file PDF");
            }
        }
        
        // Prepare data
        $judul_buku = trim($conn->real_escape_string($data['judul_buku']));
        $deskripsi = trim($conn->real_escape_string($data['deskripsi'] ?? ''));
        $id_kategori = intval($data['id_kategori']);
        $id_penulis = !empty($data['id_penulis']) ? intval($data['id_penulis']) : null;
        $id_penerbit = !empty($data['id_penerbit']) ? intval($data['id_penerbit']) : null;
        $tahun_terbit = !empty($data['tahun_terbit']) ? intval($data['tahun_terbit']) : date('Y');
        $jumlah_halaman = !empty($data['jumlah_halaman']) ? intval($data['jumlah_halaman']) : null;
        $stok = intval($data['stok']);
        
        // Insert book
        $sql = "INSERT INTO buku (judul_buku, deskripsi, id_kategori, id_penulis, id_penerbit, 
                tahun_terbit, jumlah_halaman, stok, cover_buku, file_pdf) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssiiiisiss",
            $judul_buku,
            $deskripsi,
            $id_kategori,
            $id_penulis,
            $id_penerbit,
            $tahun_terbit,
            $jumlah_halaman,
            $stok,
            $cover_filename,
            $pdf_filename
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data buku: " . $stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Buku berhasil ditambahkan';
        
        $stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal menambahkan buku: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Function to update book
function updateBook($data, $files) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        $id_buku = intval($data['id_buku']);
        if ($id_buku <= 0) {
            throw new Exception("ID buku tidak valid");
        }
        
        // Get current book data
        $sql = "SELECT cover_buku, file_pdf FROM buku WHERE id_buku = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_buku);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Buku tidak ditemukan");
        }
        
        $current_book = $result->fetch_assoc();
        $stmt->close();
        
        // Handle cover upload
        $cover_filename = $current_book['cover_buku'];
        if (isset($files['cover_buku']) && $files['cover_buku']['error'] === UPLOAD_ERR_OK) {
            $cover_file = $files['cover_buku'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($cover_file['type'], $allowed_types)) {
                throw new Exception("Format file cover tidak didukung. Gunakan JPG, PNG, atau GIF");
            }
            
            if ($cover_file['size'] > $max_size) {
                throw new Exception("Ukuran file cover maksimal 2MB");
            }
            
            $cover_ext = pathinfo($cover_file['name'], PATHINFO_EXTENSION);
            $cover_filename = 'cover_' . time() . '_' . uniqid() . '.' . $cover_ext;
            $cover_path = '../uploads/covers/' . $cover_filename;
            
            // Delete old cover if not default
            if ($current_book['cover_buku'] != 'default.jpg') {
                $old_cover_path = '../uploads/covers/' . $current_book['cover_buku'];
                if (file_exists($old_cover_path)) {
                    unlink($old_cover_path);
                }
            }
            
            if (!move_uploaded_file($cover_file['tmp_name'], $cover_path)) {
                throw new Exception("Gagal mengupload cover buku");
            }
        }
        
        // Handle PDF upload
        $pdf_filename = $current_book['file_pdf'];
        if (isset($files['file_pdf']) && $files['file_pdf']['error'] === UPLOAD_ERR_OK) {
            $pdf_file = $files['file_pdf'];
            $allowed_pdf_types = ['application/pdf'];
            $max_pdf_size = 10 * 1024 * 1024; // 10MB
            
            if (!in_array($pdf_file['type'], $allowed_pdf_types)) {
                throw new Exception("Format file harus PDF");
            }
            
            if ($pdf_file['size'] > $max_pdf_size) {
                throw new Exception("Ukuran file PDF maksimal 10MB");
            }
            
            $pdf_ext = pathinfo($pdf_file['name'], PATHINFO_EXTENSION);
            $pdf_filename = 'book_' . time() . '_' . uniqid() . '.' . $pdf_ext;
            $pdf_path = '../uploads/books/' . $pdf_filename;
            
            // Delete old PDF if exists
            if (!empty($current_book['file_pdf'])) {
                $old_pdf_path = '../uploads/books/' . $current_book['file_pdf'];
                if (file_exists($old_pdf_path)) {
                    unlink($old_pdf_path);
                }
            }
            
            if (!move_uploaded_file($pdf_file['tmp_name'], $pdf_path)) {
                throw new Exception("Gagal mengupload file PDF");
            }
        }
        
        // Prepare data
        $judul_buku = trim($conn->real_escape_string($data['judul_buku']));
        $deskripsi = trim($conn->real_escape_string($data['deskripsi'] ?? ''));
        $id_kategori = intval($data['id_kategori']);
        $id_penulis = !empty($data['id_penulis']) ? intval($data['id_penulis']) : null;
        $id_penerbit = !empty($data['id_penerbit']) ? intval($data['id_penerbit']) : null;
        $tahun_terbit = !empty($data['tahun_terbit']) ? intval($data['tahun_terbit']) : date('Y');
        $jumlah_halaman = !empty($data['jumlah_halaman']) ? intval($data['jumlah_halaman']) : null;
        $stok = intval($data['stok']);
        
        // Update book
        $sql = "UPDATE buku SET 
                judul_buku = ?, 
                deskripsi = ?, 
                id_kategori = ?, 
                id_penulis = ?, 
                id_penerbit = ?, 
                tahun_terbit = ?, 
                jumlah_halaman = ?, 
                stok = ?, 
                cover_buku = ?, 
                file_pdf = ? 
                WHERE id_buku = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssiiiisissi",
            $judul_buku,
            $deskripsi,
            $id_kategori,
            $id_penulis,
            $id_penerbit,
            $tahun_terbit,
            $jumlah_halaman,
            $stok,
            $cover_filename,
            $pdf_filename,
            $id_buku
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal mengupdate data buku: " . $stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Buku berhasil diupdate';
        
        $stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal mengupdate buku: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Function to delete book
function deleteBook($id_buku) {
    $conn = getConnection();
    $response = ['success' => false, 'message' => ''];
    
    if (!$conn) {
        $response['message'] = 'Koneksi database gagal';
        return $response;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Check if book exists
        $sql = "SELECT cover_buku, file_pdf FROM buku WHERE id_buku = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_buku);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Buku tidak ditemukan");
        }
        
        $book = $result->fetch_assoc();
        $stmt->close();
        
        // Delete cover file if not default
        if ($book['cover_buku'] != 'default.jpg') {
            $cover_path = '../uploads/covers/' . $book['cover_buku'];
            if (file_exists($cover_path)) {
                unlink($cover_path);
            }
        }
        
        // Delete PDF file if exists
        if (!empty($book['file_pdf'])) {
            $pdf_path = '../uploads/books/' . $book['file_pdf'];
            if (file_exists($pdf_path)) {
                unlink($pdf_path);
            }
        }
        
        // Delete book from database
        $deleteSql = "DELETE FROM buku WHERE id_buku = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $id_buku);
        
        if (!$deleteStmt->execute()) {
            throw new Exception("Gagal menghapus buku dari database");
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = 'Buku berhasil dihapus';
        
        $deleteStmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Gagal menghapus buku: ' . $e->getMessage();
    }
    
    $conn->close();
    return $response;
}

// Function to get book data for edit
function getBookData($id_buku) {
    $conn = getConnection();
    if (!$conn) return null;
    
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
    
    $book = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $book;
}

// Handle AJAX requests for getting book data
if (isset($_GET['action']) && $_GET['action'] == 'get_book' && isset($_GET['id'])) {
    $book_id = intval($_GET['id']);
    $book = getBookData($book_id);
    
    if ($book) {
        // Get categories, authors, publishers for dropdowns
        $conn = getConnection();
        $categories = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori")->fetch_all(MYSQLI_ASSOC);
        $authors = $conn->query("SELECT * FROM penulis ORDER BY nama_penulis")->fetch_all(MYSQLI_ASSOC);
        $publishers = $conn->query("SELECT * FROM penerbit ORDER BY nama_penerbit")->fetch_all(MYSQLI_ASSOC);
        $conn->close();
        
        // Generate HTML for edit form
        ob_start();
        ?>
        <div class="row">
            <div class="col-md-8">
                <input type="hidden" name="id_buku" value="<?php echo $book['id_buku']; ?>">
                <div class="mb-3">
                    <label class="form-label">Judul Buku <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="judul_buku" 
                           value="<?php echo htmlspecialchars($book['judul_buku']); ?>" required>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                        <select class="form-select" name="id_kategori" required>
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id_kategori']; ?>" 
                                <?php echo $book['id_kategori'] == $cat['id_kategori'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['nama_kategori']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Stok <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="stok" 
                               value="<?php echo $book['stok']; ?>" min="0" required>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Penulis</label>
                        <select class="form-select" name="id_penulis">
                            <option value="">Pilih Penulis</option>
                            <?php foreach ($authors as $author): ?>
                            <option value="<?php echo $author['id_penulis']; ?>"
                                <?php echo $book['id_penulis'] == $author['id_penulis'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($author['nama_penulis']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Penerbit</label>
                        <select class="form-select" name="id_penerbit">
                            <option value="">Pilih Penerbit</option>
                            <?php foreach ($publishers as $publisher): ?>
                            <option value="<?php echo $publisher['id_penerbit']; ?>"
                                <?php echo $book['id_penerbit'] == $publisher['id_penerbit'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($publisher['nama_penerbit']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Tahun Terbit</label>
                        <input type="number" class="form-control" name="tahun_terbit" 
                               value="<?php echo $book['tahun_terbit']; ?>"
                               min="1900" max="<?php echo date('Y'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Jumlah Halaman</label>
                        <input type="number" class="form-control" name="jumlah_halaman" 
                               value="<?php echo $book['jumlah_halaman']; ?>" min="1">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Deskripsi</label>
                    <textarea class="form-control" name="deskripsi" rows="3"><?php echo htmlspecialchars($book['deskripsi']); ?></textarea>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label">Cover Buku Saat Ini</label>
                    <div class="cover-preview mb-3">
                        <img id="editCoverPreview" 
                             src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $book['cover_buku']; ?>" 
                             alt="Preview" class="img-fluid rounded">
                    </div>
                    <label class="form-label">Ubah Cover</label>
                    <input type="file" class="form-control" name="cover_buku" 
                           accept="image/*" onchange="previewEditCover(this)">
                    <div class="form-text">Biarkan kosong jika tidak ingin mengubah cover</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">File PDF Saat Ini</label>
                    <?php if (!empty($book['file_pdf'])): ?>
                    <div class="alert alert-info p-2">
                        <i class="fas fa-file-pdf text-danger me-2"></i>
                        <small><?php echo htmlspecialchars($book['file_pdf']); ?></small>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning p-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <small>Tidak ada file PDF</small>
                    </div>
                    <?php endif; ?>
                    <label class="form-label">Ubah File PDF</label>
                    <input type="file" class="form-control" name="file_pdf" accept=".pdf">
                    <div class="form-text">Biarkan kosong jika tidak ingin mengubah file PDF</div>
                </div>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    } else {
        echo '<div class="alert alert-danger">Buku tidak ditemukan</div>';
    }
    exit();
}

// Get data for filters and books
try {
    $conn = getConnection();
    
    if (!$conn) {
        throw new Exception("Koneksi database gagal: Tidak dapat membuat koneksi");
    }
    
    if ($conn->connect_error) {
        throw new Exception("Koneksi database gagal: " . $conn->connect_error);
    }

    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $kategori = $_GET['kategori'] ?? '';
    $penulis = $_GET['penulis'] ?? '';
    $penerbit = $_GET['penerbit'] ?? '';
    $status = $_GET['status'] ?? '';
    
    // Get categories, authors, publishers for dropdowns
    $categories = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori")->fetch_all(MYSQLI_ASSOC);
    $authors = $conn->query("SELECT * FROM penulis ORDER BY nama_penulis")->fetch_all(MYSQLI_ASSOC);
    $publishers = $conn->query("SELECT * FROM penerbit ORDER BY nama_penerbit")->fetch_all(MYSQLI_ASSOC);
    
    // Build query for books
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($search) {
        $whereClause .= " AND (b.judul_buku LIKE ? OR b.deskripsi LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "ss";
    }
    
    if ($kategori) {
        $whereClause .= " AND k.id_kategori = ?";
        $params[] = $kategori;
        $types .= "i";
    }
    
    if ($penulis) {
        $whereClause .= " AND p.id_penulis = ?";
        $params[] = $penulis;
        $types .= "i";
    }
    
    if ($penerbit) {
        $whereClause .= " AND pb.id_penerbit = ?";
        $params[] = $penerbit;
        $types .= "i";
    }
    
    if ($status === 'tersedia') {
        $whereClause .= " AND b.stok > 0";
    } elseif ($status === 'habis') {
        $whereClause .= " AND b.stok = 0";
    }
    
    // Get books with rating
    $sql = "SELECT b.*, k.nama_kategori, p.nama_penulis, pb.nama_penerbit,
            COALESCE(AVG(r.rating), 0) as rating_avg,
            COUNT(r.id_rating) as rating_count
            FROM buku b
            LEFT JOIN kategori k ON b.id_kategori = k.id_kategori
            LEFT JOIN penulis p ON b.id_penulis = p.id_penulis
            LEFT JOIN penerbit pb ON b.id_penerbit = pb.id_penerbit
            LEFT JOIN rating r ON b.id_buku = r.id_buku
            $whereClause
            GROUP BY b.id_buku
            ORDER BY b.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result === false) {
        throw new Exception("Error query buku: " . $conn->error);
    }
    
    if ($result->num_rows > 0) {
        while ($book = $result->fetch_assoc()) {
            $books[] = $book;
        }
    }
    
    // Get stats
    $total_books = count($books);
    $total_available = $conn->query("SELECT COUNT(*) as total FROM buku WHERE stok > 0")->fetch_assoc()['total'];
    $total_out_of_stock = $conn->query("SELECT COUNT(*) as total FROM buku WHERE stok = 0")->fetch_assoc()['total'];
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    $books = [];
    $categories = [];
    $authors = [];
    $publishers = [];
}

// Get pending count for sidebar badge
$conn = getConnection();
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;
$conn->close();

// Set current page for sidebar
$current_page = 'kelola_buku.php';
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

        /* Main Content - PERKECIL UKURAN */
        .main-content {
            padding: 15px; /* Dikecilkan dari 20px */
            min-height: calc(100vh - var(--topbar-height));
        }

        /* Card Styles */
        .card {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); /* Dikecilkan shadow */
            border: none;
            border-radius: 8px; /* Dikecilkan radius */
            transition: transform 0.2s;
            height: 100%;
            font-size: 0.9rem; /* Perkecil font dalam card */
        }

        .card:hover {
            transform: translateY(-1px); /* Dikecilkan efek hover */
        }

        .card-header {
            border-radius: 8px 8px 0 0 !important; /* Sesuaikan radius */
            border: none;
            font-weight: 600;
            padding: 0.75rem 1rem; /* Dikecilkan padding */
            font-size: 0.95rem; /* Perkecil font header */
        }

        .alert {
            border-radius: 6px; /* Dikecilkan radius */
            border: none;
            font-size: 0.9rem; /* Perkecil font alert */
            padding: 0.75rem 1rem; /* Dikecilkan padding */
        }
        
        /* Book Table Styles - PERKECIL UKURAN */
        .book-cover-table {
            width: 40px; /* Dikecilkan dari 50px */
            height: 52px; /* Dikecilkan dari 65px */
            border-radius: 4px; /* Dikecilkan radius */
            overflow: hidden;
            background: var(--gray-200);
            margin-right: 8px; /* Dikecilkan margin */
        }
        
        .book-cover-table img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Stats Cards - PERKECIL UKURAN */
        .stat-card {
            padding: 12px; /* Dikecilkan dari 15px */
            border-radius: 8px; /* Dikecilkan dari 12px */
            margin-bottom: 10px; /* Dikecilkan dari 15px */
            color: white;
            border: none;
            transition: transform 0.3s;
            font-size: 0.85rem; /* Perkecil font stat card */
        }
        
        .stat-card:hover {
            transform: translateY(-2px); /* Dikecilkan efek hover */
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
            font-size: 0.8rem; /* Dikecilkan dari 0.9rem */
            opacity: 0.9;
            margin-bottom: 3px; /* Dikecilkan dari 5px */
        }
        
        .stat-card h2 {
            font-size: 1.5rem; /* Dikecilkan dari 1.8rem */
            font-weight: 600;
            margin-bottom: 0;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 15px; /* Dikecilkan dari 60px 20px */
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem; /* Dikecilkan dari 4rem */
            margin-bottom: 15px; /* Dikecilkan dari 20px */
            color: #dee2e6;
        }
        
        .empty-state h4 {
            font-size: 1.1rem; /* Dikecilkan dari 1.2rem */
            margin-bottom: 8px; /* Dikecilkan dari 10px */
        }
        
        .empty-state p {
            font-size: 0.85rem; /* Dikecilkan dari 0.95rem */
        }
        
        /* Table Styles - PERKECIL UKURAN */
        .table-responsive {
            border-radius: 6px; /* Dikecilkan dari 10px */
            overflow: hidden;
            font-size: 0.85rem; /* Perkecil font table */
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: var(--primary-light);
            border-bottom: 2px solid var(--primary-color);
            color: var(--gray-800);
            font-weight: 600;
            padding: 10px 12px; /* Dikecilkan dari 15px */
            white-space: nowrap;
            font-size: 0.85rem; /* Perkecil font header table */
        }
        
        .table tbody td {
            padding: 10px 12px; /* Dikecilkan dari 15px */
            vertical-align: middle;
            border-color: var(--gray-200);
            font-size: 0.85rem; /* Perkecil font data table */
        }
        
        .table tbody tr:hover {
            background-color: rgba(78, 115, 223, 0.05);
        }
        
        /* Badges - PERKECIL UKURAN */
        .badge-status {
            padding: 3px 8px; /* Dikecilkan dari 4px 10px */
            border-radius: 10px; /* Dikecilkan dari 12px */
            font-size: 0.7rem; /* Dikecilkan dari 0.75rem */
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
        
        /* Stars Rating - PERKECIL UKURAN */
        .stars {
            display: inline-flex;
            gap: 1px; /* Dikecilkan dari 2px */
        }
        
        .stars i {
            font-size: 0.8rem; /* Dikecilkan dari 0.9rem */
        }
        
        /* Modal Styles - PERKECIL UKURAN */
        .cover-upload .cover-preview {
            width: 100%;
            height: 160px; /* Dikecilkan dari 200px */
            border: 2px dashed var(--gray-300);
            border-radius: 6px; /* Dikecilkan dari 10px */
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            margin-bottom: 10px; /* Dikecilkan dari 15px */
        }
        
        .cover-upload .cover-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        /* Button Actions */
        .btn-group .btn {
            padding: 4px 8px; /* Dikecilkan dari 6px 12px */
            font-size: 0.8rem; /* Perkecil font button */
        }
        
        /* Form controls - PERKECIL UKURAN */
        .form-control, .form-select {
            font-size: 0.85rem; /* Perkecil font form */
            padding: 0.375rem 0.75rem; /* Dikecilkan sedikit */
        }
        
        .form-label {
            font-size: 0.85rem; /* Perkecil font label */
            margin-bottom: 0.25rem; /* Dikecilkan dari 0.5rem */
        }
        
        .form-text {
            font-size: 0.75rem; /* Perkecil font help text */
        }
        
        /* Filter form - PERKECIL UKURAN */
        .card-body {
            padding: 1rem; /* Dikecilkan dari default */
        }
        
        /* Button umum */
        .btn {
            font-size: 0.85rem; /* Perkecil font button umum */
            padding: 0.375rem 0.75rem; /* Sesuaikan padding */
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem; /* Sesuaikan padding */
            font-size: 0.8rem; /* Perkecil font button kecil */
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stat-card h2 {
                font-size: 1.2rem; /* Dikecilkan dari 1.5rem */
            }
            
            .table thead th, 
            .table tbody td {
                padding: 8px 10px; /* Dikecilkan lebih */
                font-size: 0.8rem; /* Lebih kecil di mobile */
            }
            
            .book-cover-table {
                width: 35px; /* Lebih kecil di mobile */
                height: 45px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                margin-bottom: 3px; /* Dikecilkan dari 5px */
                width: 100%;
            }
            
            .main-content {
                padding: 10px; /* Lebih kecil di mobile */
            }
        }
        
        @media (max-width: 576px) {
            .topbar {
                padding: 0 1rem;
            }
            
            .page-title {
                font-size: 1.1rem;
            }
            
            .stat-card {
                padding: 10px; /* Lebih kecil di mobile */
            }
            
            .stat-card h2 {
                font-size: 1.1rem; /* Lebih kecil di mobile */
            }
            
            .stat-card h5 {
                font-size: 0.75rem; /* Lebih kecil di mobile */
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
                                <a class="dropdown-item active" href="kelola_buku.php">
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
                    <!-- Removed notifications icon as requested -->
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <div class="container-fluid py-3"> <!-- Dikecilkan dari py-4 ke py-3 -->
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
                    <div class="row mb-3"> <!-- Dikecilkan dari mb-4 -->
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Buku</h5>
                                        <h2 class="mb-0"><?php echo $total_books; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-book fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Buku Tersedia</h5>
                                        <h2 class="mb-0"><?php echo $total_available; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-check-circle fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-warning">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Buku Habis</h5>
                                        <h2 class="mb-0"><?php echo $total_out_of_stock; ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-info">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Total Kategori</h5>
                                        <h2 class="mb-0"><?php echo count($categories); ?></h2>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-tags fa-2x opacity-75"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="card mb-3"> <!-- Dikecilkan dari mb-4 -->
                        <div class="card-body p-3"> <!-- Dikecilkan padding -->
                            <form method="GET" action="" class="row g-2"> <!-- Dikecilkan dari g-3 -->
                                <div class="col-md-3">
                                    <label class="form-label">Pencarian</label>
                                    <input type="text" class="form-control" name="search" 
                                           placeholder="Judul atau deskripsi..." 
                                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Kategori</label>
                                    <select class="form-select" name="kategori">
                                        <option value="">Semua</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id_kategori']; ?>"
                                            <?php echo ($_GET['kategori'] ?? '') == $cat['id_kategori'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['nama_kategori']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Penulis</label>
                                    <select class="form-select" name="penulis">
                                        <option value="">Semua</option>
                                        <?php foreach ($authors as $author): ?>
                                        <option value="<?php echo $author['id_penulis']; ?>"
                                            <?php echo ($_GET['penulis'] ?? '') == $author['id_penulis'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($author['nama_penulis']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Penerbit</label>
                                    <select class="form-select" name="penerbit">
                                        <option value="">Semua</option>
                                        <?php foreach ($publishers as $publisher): ?>
                                        <option value="<?php echo $publisher['id_penerbit']; ?>"
                                            <?php echo ($_GET['penerbit'] ?? '') == $publisher['id_penerbit'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($publisher['nama_penerbit']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="">Semua</option>
                                        <option value="tersedia" <?php echo ($_GET['status'] ?? '') == 'tersedia' ? 'selected' : ''; ?>>Tersedia</option>
                                        <option value="habis" <?php echo ($_GET['status'] ?? '') == 'habis' ? 'selected' : ''; ?>>Habis</option>
                                    </select>
                                </div>
                                <div class="col-md-1 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Books Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center py-2"> <!-- Dikecilkan padding -->
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Daftar Buku
                                <span class="badge bg-primary ms-2"><?php echo count($books); ?></span>
                            </h5>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tambahBukuModal">
                                <i class="fas fa-plus me-1"></i>Tambah Buku
                            </button>
                        </div>
                        <div class="card-body p-2"> <!-- Dikecilkan padding -->
                            <div class="table-responsive">
                                <table class="table table-hover mb-0"> <!-- Dikecilkan margin-bottom -->
                                    <thead>
                                        <tr>
                                            <th width="40">#</th> <!-- Dikecilkan dari 50 -->
                                            <th>Cover</th>
                                            <th>Judul Buku</th>
                                            <th>Kategori</th>
                                            <th>Penulis</th>
                                            <th>Penerbit</th>
                                            <th>Stok</th>
                                            <th>Rating</th>
                                            <th>Status</th>
                                            <th width="120">Aksi</th> <!-- Dikecilkan dari 150 -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($books)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4"> <!-- Dikecilkan padding -->
                                                <div class="empty-state">
                                                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                                                    <h5 class="text-muted">Tidak ada data buku</h5>
                                                    <p class="text-muted mb-0">Silakan tambahkan buku baru atau perbaiki filter pencarian</p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($books as $index => $book): 
                                                $status_class = $book['stok'] > 0 ? 'success' : 'danger';
                                                $status_text = $book['stok'] > 0 ? 'Tersedia' : 'Habis';
                                                $rating_avg = floatval($book['rating_avg']);
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="book-cover-table">
                                                        <img src="<?php echo SITE_URL; ?>uploads/covers/<?php echo $book['cover_buku'] ?: 'default.jpg'; ?>" 
                                                             alt="<?php echo htmlspecialchars($book['judul_buku']); ?>"
                                                             onerror="this.src='<?php echo SITE_URL; ?>uploads/covers/default.jpg'">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($book['judul_buku']); ?></div>
                                                    <small class="text-muted" style="font-size: 0.75rem;">
                                                        <?php echo $book['tahun_terbit']; ?> • 
                                                        <?php echo $book['jumlah_halaman'] ? $book['jumlah_halaman'] . ' hlm' : '-'; ?>
                                                    </small>
                                                </td>
                                                <td><?php echo htmlspecialchars($book['nama_kategori'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($book['nama_penulis'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($book['nama_penerbit'] ?? '-'); ?></td>
                                                <td>
                                                    <span class="fw-bold <?php echo $book['stok'] > 0 ? 'text-success' : 'text-danger'; ?>" style="font-size: 0.9rem;">
                                                        <?php echo $book['stok']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="stars me-1"> <!-- Dikecilkan margin -->
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <?php if ($i <= floor($rating_avg)): ?>
                                                                    <i class="fas fa-star text-warning"></i>
                                                                <?php elseif ($i <= ceil($rating_avg) && $rating_avg - floor($rating_avg) >= 0.5): ?>
                                                                    <i class="fas fa-star-half-alt text-warning"></i>
                                                                <?php else: ?>
                                                                    <i class="far fa-star text-warning"></i>
                                                                <?php endif; ?>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <small class="text-muted" style="font-size: 0.75rem;"><?php echo number_format($rating_avg, 1); ?></small>
                                                        <?php if ($book['rating_count'] > 0): ?>
                                                        <small class="text-muted ms-1" style="font-size: 0.75rem;">(<?php echo $book['rating_count']; ?>)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge-status badge-<?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group"> <!-- Ditambah btn-group-sm -->
                                                        <button class="btn btn-outline-primary"
                                                                onclick="editBuku(<?php echo $book['id_buku']; ?>)"
                                                                title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger"
                                                                onclick="deleteBuku(<?php echo $book['id_buku']; ?>, '<?php echo addslashes($book['judul_buku']); ?>')"
                                                                title="Hapus">
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

    <!-- Tambah Buku Modal -->
    <div class="modal fade" id="tambahBukuModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tambah Buku Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formTambahBuku" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_book">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">Judul Buku <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="judul_buku" required>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                                        <select class="form-select" name="id_kategori" required>
                                            <option value="">Pilih Kategori</option>
                                            <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id_kategori']; ?>">
                                                <?php echo htmlspecialchars($cat['nama_kategori']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Stok <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="stok" value="1" min="0" required>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Penulis</label>
                                        <select class="form-select" name="id_penulis">
                                            <option value="">Pilih Penulis</option>
                                            <?php foreach ($authors as $author): ?>
                                            <option value="<?php echo $author['id_penulis']; ?>">
                                                <?php echo htmlspecialchars($author['nama_penulis']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">
                                            <a href="kelola_penulis.php" target="_blank">Tambah penulis baru</a>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Penerbit</label>
                                        <select class="form-select" name="id_penerbit">
                                            <option value="">Pilih Penerbit</option>
                                            <?php foreach ($publishers as $publisher): ?>
                                            <option value="<?php echo $publisher['id_penerbit']; ?>">
                                                <?php echo htmlspecialchars($publisher['nama_penerbit']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">
                                            <a href="kelola_penerbit.php" target="_blank">Tambah penerbit baru</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Tahun Terbit</label>
                                        <input type="number" class="form-control" name="tahun_terbit" 
                                               min="1900" max="<?php echo date('Y'); ?>" 
                                               value="<?php echo date('Y'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Jumlah Halaman</label>
                                        <input type="number" class="form-control" name="jumlah_halaman" min="1">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Deskripsi</label>
                                    <textarea class="form-control" name="deskripsi" rows="3"></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Cover Buku</label>
                                    <div class="cover-upload">
                                        <div class="cover-preview">
                                            <img id="coverPreview" src="<?php echo SITE_URL; ?>uploads/covers/default.jpg" 
                                                 alt="Preview" class="img-fluid rounded">
                                        </div>
                                        <input type="file" class="form-control" name="cover_buku" 
                                               accept="image/*" onchange="previewCover(this)">
                                        <div class="form-text">Ukuran maksimal 2MB. Format: JPG, PNG</div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">File PDF Buku</label>
                                    <input type="file" class="form-control" name="file_pdf" accept=".pdf">
                                    <div class="form-text">Ukuran maksimal 10MB. Format: PDF</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Buku Modal -->
    <div class="modal fade" id="editBukuModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Buku</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formEditBuku" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_book">
                    <div class="modal-body" id="editBukuContent">
                        Loading...
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Simpan Perubahan
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
            const sidebar = document.getElementById('sidebar');
            const contentWrapper = document.getElementById('contentWrapper');
            
            sidebar.classList.toggle('mobile-open');
            contentWrapper.classList.toggle('mobile-open');
        });

        // Cover preview for add book
        function previewCover(input) {
            const preview = document.getElementById('coverPreview');
            const file = input.files[0];

            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        }

        // Cover preview for edit book
        function previewEditCover(input) {
            const preview = document.getElementById('editCoverPreview');
            const file = input.files[0];

            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
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
        
        // Tambah buku form
        document.getElementById('formTambahBuku').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            showLoading('Menambahkan buku...');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                }
                return response.text();
            })
            .then(() => {
                // Reload page to show changes
                location.reload();
            })
            .catch(error => {
                Swal.close();
                showError('Terjadi kesalahan. Silakan coba lagi.');
            });
        });
        
        // Edit buku
        function editBuku(id) {
            showLoading('Memuat data buku...');
            
            fetch('?action=get_book&id=' + id)
            .then(response => response.text())
            .then(html => {
                Swal.close();
                document.getElementById('editBukuContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('editBukuModal'));
                modal.show();
                
                // Handle edit form submission
                document.getElementById('formEditBuku').addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    showLoading('Mengupdate buku...');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (response.redirected) {
                            window.location.href = response.url;
                        }
                        return response.text();
                    })
                    .then(() => {
                        // Reload page to show changes
                        location.reload();
                    })
                    .catch(error => {
                        Swal.close();
                        showError('Terjadi kesalahan. Silakan coba lagi.');
                    });
                });
            })
            .catch(error => {
                Swal.close();
                showError('Gagal memuat data buku.');
            });
        }
        
        // View buku details
        function viewBuku(id) {
            window.open('../anggota/detail_buku.php?id=' + id, '_blank');
        }
        
        // Delete buku
        function deleteBuku(id, title) {
            confirmAction('Apakah Anda yakin ingin menghapus buku "' + title + '"?', function() {
                showLoading('Menghapus buku...');
                
                const formData = new FormData();
                formData.append('action', 'delete_book');
                formData.append('id_buku', id);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                    }
                    return response.text();
                })
                .then(() => {
                    // Reload page to show changes
                    location.reload();
                })
                .catch(error => {
                    Swal.close();
                    showError('Terjadi kesalahan. Silakan coba lagi.');
                });
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
            
            // Initialize dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
        });
    </script>
</body>
</html>