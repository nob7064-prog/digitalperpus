<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/excel_export.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$type = $_GET['type'] ?? 'buku';
$excel = null;

switch ($type) {
    case 'buku':
        $excel = exportBookReport();
        break;
    case 'pengguna':
        $excel = exportUserReport();
        break;
    case 'peminjaman':
        $excel = exportLoanReport();
        break;
    case 'pengembalian':
        $excel = exportReturnReport();
        break;
    case 'keseluruhan':
        $excel = exportOverallReport();
        break;
    default:
        die('Invalid report type');
}

if ($excel) {
    $excel->download();
}

function exportBookReport() {
    $kategori = $_GET['kategori'] ?? '';
    $status_stok = $_GET['status_stok'] ?? '';
    $sort = $_GET['sort'] ?? 'terbaru';
    $tahun = $_GET['tahun'] ?? '';
    $rating_min = $_GET['rating_min'] ?? '';

    $conn = getConnection();

    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";

    if ($kategori) {
        $whereClause .= " AND k.id_kategori = ?";
        $params[] = $kategori;
        $types .= "i";
    }

    if ($status_stok === 'tersedia') $whereClause .= " AND b.stok > 0";
    elseif ($status_stok === 'habis') $whereClause .= " AND b.stok = 0";

    if ($tahun) { $whereClause .= " AND b.tahun_terbit = ?"; $params[] = $tahun; $types .= "s"; }
    if ($rating_min) { $whereClause .= " AND b.rata_rating >= ?"; $params[] = $rating_min; $types .= "d"; }

    $orderBy = "ORDER BY ";
    switch ($sort) {
        case 'rating': $orderBy .= "b.rata_rating DESC"; break;
        case 'populer': $orderBy .= "b.jumlah_pinjam DESC"; break;
        case 'stok': $orderBy .= "b.stok DESC"; break;
        case 'abjad': $orderBy .= "b.judul_buku ASC"; break;
        case 'terbaru':
        default: $orderBy .= "b.created_at DESC"; break;
    }

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
            $orderBy";

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stats = $conn->query("SELECT
            COUNT(*) as total_buku,
            SUM(stok) as total_stok,
            SUM(jumlah_pinjam) as total_pinjam,
            AVG(rata_rating) as avg_rating,
            COUNT(CASE WHEN stok = 0 THEN 1 END) as habis,
            COUNT(CASE WHEN stok > 0 THEN 1 END) as tersedia
        FROM buku")->fetch_assoc();

    $conn->close();

    $excel = createExcelExport('laporan_buku_' . date('Y-m-d'), 'Laporan Buku - Digital Library', 'Tanggal: ' . date('d/m/Y'));

    $data = [];
    foreach ($books as $book) {
        $data[] = [
            $book['judul_buku'],
            number_format($book['rating_avg'], 1),
            $book['jumlah_pinjam']
        ];
    }

    $excel->addSheet('Data Buku', ['Judul Buku','Rating Buku','Jumlah Pinjam'], $data);

    $summaryData = [
        ['Total Buku', number_format($stats['total_buku'] ?? 0)],
        ['Total Stok', number_format($stats['total_stok'] ?? 0)],
        ['Buku Tersedia', number_format($stats['tersedia'] ?? 0)],
        ['Buku Habis', number_format($stats['habis'] ?? 0)],
        ['Total Dipinjam', number_format($stats['total_pinjam'] ?? 0)],
        ['Rating Rata-rata', number_format($stats['avg_rating'] ?? 0,1)],
        ['Tanggal Laporan', date('d/m/Y H:i')],
        ['Filter Aktif', getActiveFilters()]
    ];

    $excel->addSummarySheet('Ringkasan', $summaryData);
    return $excel;
}

function exportUserReport() {
    $conn = getConnection();

    $user_type = $_GET['user_type'] ?? 'anggota';
    $status = $_GET['status'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    if ($user_type === 'anggota') {
        $whereClause = "WHERE 1=1";
        $params = [];
        $types = "";

        if ($status) { $whereClause .= " AND a.status = ?"; $params[] = $status; $types .= "s"; }
        if ($start_date) { $whereClause .= " AND DATE(a.created_at) >= ?"; $params[] = $start_date; $types .= "s"; }
        if ($end_date) { $whereClause .= " AND DATE(a.created_at) <= ?"; $params[] = $end_date; $types .= "s"; }

        $sql = "SELECT a.*, 
                       COUNT(p.id_peminjaman) as total_pinjam,
                       COUNT(CASE WHEN p.status = 'dipinjam' THEN 1 END) as sedang_pinjam,
                       SUM(CASE WHEN pg.denda > 0 THEN pg.denda ELSE 0 END) as total_denda
                FROM anggota a
                LEFT JOIN peminjaman p ON a.id_anggota = p.id_anggota
                LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                $whereClause
                GROUP BY a.id_anggota
                ORDER BY a.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $statsSql = "SELECT COUNT(*) as total, SUM(CASE WHEN status='aktif' THEN 1 ELSE 0 END) as aktif, SUM(CASE WHEN status='nonaktif' THEN 1 ELSE 0 END) as nonaktif, SUM((SELECT SUM(denda) FROM pengembalian WHERE id_anggota = anggota.id_anggota)) as total_denda FROM anggota ";
        $statsSql .= $whereClause;
        $statsStmt = $conn->prepare($statsSql);
        if ($types) $statsStmt->bind_param($types, ...$params);
        $statsStmt->execute();
        $stats = $statsStmt->get_result()->fetch_assoc();
        $statsStmt->close();

        $memberData = [];
        foreach ($users as $user) {
            $memberData[] = [
                $user['username'],
                $user['email'],
                $user['status'],
                formatDate($user['created_at']),
                $user['total_pinjam']
            ];
        }

        $excel = createExcelExport('laporan_pengguna_anggota_' . date('Y-m-d'), 'Laporan Pengguna - Anggota', 'Tanggal: ' . date('d/m/Y'));
        $excel->addSheet('Data Anggota', ['Nama Pengguna','Email','Status','Tanggal Daftar','Total Pinjam'], $memberData);
        $summaryData = [
            ['Total Anggota', number_format($stats['total'] ?? 0)],
            ['Anggota Aktif', number_format($stats['aktif'] ?? 0)],
            ['Anggota Nonaktif', number_format($stats['nonaktif'] ?? 0)],
            ['Total Denda', formatCurrency($stats['total_denda'] ?? 0)],
            ['Tanggal Laporan', date('d/m/Y H:i')]
        ];
        $excel->addSummarySheet('Ringkasan', $summaryData);
        $conn->close();
        return $excel;
    }

    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";
    if ($status) { $whereClause .= " AND p.status = ?"; $params[] = $status; $types .= "s"; }
    if ($start_date) { $whereClause .= " AND DATE(p.created_at) >= ?"; $params[] = $start_date; $types .= "s"; }
    if ($end_date) { $whereClause .= " AND DATE(p.created_at) <= ?"; $params[] = $end_date; $types .= "s"; }

    $sql = "SELECT p.*, (SELECT COUNT(*) FROM aktivitas WHERE id_user = p.id_petugas AND user_type = 'petugas') as total_aktivitas FROM petugas p $whereClause ORDER BY p.created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $statsSql = "SELECT COUNT(*) as total, SUM(CASE WHEN status='aktif' THEN 1 ELSE 0 END) as aktif, SUM(CASE WHEN status='nonaktif' THEN 1 ELSE 0 END) as nonaktif, SUM(CASE WHEN level='admin' THEN 1 ELSE 0 END) as admin, SUM(CASE WHEN level='petugas' THEN 1 ELSE 0 END) as petugas FROM petugas ";
    $statsSql .= $whereClause;
    $statsStmt = $conn->prepare($statsSql);
    if ($types) $statsStmt->bind_param($types, ...$params);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    $staffData = [];
    foreach ($staff as $person) {
        $staffData[] = [
            $person['username'],
            $person['email'],
            $person['status'],
            formatDate($person['created_at']),
            $person['total_aktivitas']
        ];
    }

    $excel = createExcelExport('laporan_pengguna_petugas_' . date('Y-m-d'), 'Laporan Pengguna - Petugas', 'Tanggal: ' . date('d/m/Y'));
    $excel->addSheet('Data Petugas', ['Nama Pengguna','Email','Status','Tanggal Daftar','Total Aktivitas'], $staffData);
    $summaryData = [
        ['Total Petugas', number_format($stats['total'] ?? 0)],
        ['Petugas Aktif', number_format($stats['aktif'] ?? 0)],
        ['Petugas Nonaktif', number_format($stats['nonaktif'] ?? 0)],
        ['Admin', number_format($stats['admin'] ?? 0)],
        ['Petugas', number_format($stats['petugas'] ?? 0)],
        ['Tanggal Laporan', date('d/m/Y H:i')]
    ];
    $excel->addSummarySheet('Ringkasan', $summaryData);
    $conn->close();
    return $excel;
}

function exportLoanReport() {
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? '';
    $anggota = $_GET['anggota'] ?? '';
    $kategori = $_GET['kategori'] ?? '';

    $conn = getConnection();
    $colCheck = $conn->query("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='peminjaman' AND COLUMN_NAME='id_petugas'")->fetch_assoc();
    $hasIdPetugas = !empty($colCheck) && intval($colCheck['c']) > 0;

    $whereClause = "WHERE DATE(p.tanggal_pinjam) BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    $types = "ss";
    if ($status) { $whereClause .= " AND p.status = ?"; $params[] = $status; $types .= "s"; }
    if ($anggota) { $whereClause .= " AND a.id_anggota = ?"; $params[] = $anggota; $types .= "i"; }
    if ($kategori) { $whereClause .= " AND k.nama_kategori = ?"; $params[] = $kategori; $types .= "s"; }

    $petugasSelect = $hasIdPetugas ? "pt.username as nama_petugas," : "NULL as nama_petugas,";
    $petugasJoin = $hasIdPetugas ? "LEFT JOIN petugas pt ON p.id_petugas = pt.id_petugas" : "";

    $sql = "SELECT p.*, b.judul_buku, b.cover_buku, k.nama_kategori, a.username as nama_anggota, a.email as email_anggota, $petugasSelect DATEDIFF(p.tanggal_jatuh_tempo, CURDATE()) as sisa_hari, COALESCE(pg.denda, 0) as denda FROM peminjaman p JOIN buku b ON p.id_buku = b.id_buku JOIN kategori k ON b.id_kategori = k.id_kategori JOIN anggota a ON p.id_anggota = a.id_anggota LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman $petugasJoin $whereClause ORDER BY p.tanggal_pinjam DESC";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $statsSql = "SELECT COUNT(*) as total, 
        COUNT(CASE WHEN p.status='dipinjam' THEN 1 END) as sedang_dipinjam, 
        COUNT(CASE WHEN p.status='dikembalikan' THEN 1 END) as dikembalikan, 
        COUNT(CASE WHEN p.status='terlambat' THEN 1 END) as terlambat, 
        COUNT(DISTINCT p.id_anggota) as total_anggota, 
        COUNT(DISTINCT p.id_buku) as total_buku 
        FROM peminjaman p 
        JOIN buku b ON p.id_buku = b.id_buku 
        JOIN kategori k ON b.id_kategori = k.id_kategori $whereClause";
    $statsStmt = $conn->prepare($statsSql);
    if ($types) $statsStmt->bind_param($types, ...$params);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    $conn->close();

    $excel = createExcelExport('laporan_peminjaman_' . date('Y-m-d'), 'Laporan Peminjaman - Digital Library', 'Periode: ' . formatDate($start_date) . ' - ' . formatDate($end_date));
    $data = [];
    foreach ($loans as $loan) {
        $data[] = [
            $loan['judul_buku'],
            $loan['nama_anggota'],
            formatDate($loan['tanggal_pinjam']),
            $loan['status'],
            $loan['denda']
        ];
    }
    $excel->addSheet('Data Peminjaman', ['Judul Buku','Anggota','Tanggal Pinjam','Status','Denda'], $data);
    $summaryData = [['Periode Laporan', formatDate($start_date) . ' - ' . formatDate($end_date)], ['Total Peminjaman', number_format($stats['total'] ?? 0)], ['Sedang Dipinjam', number_format($stats['sedang_dipinjam'] ?? 0)], ['Sudah Dikembalikan', number_format($stats['dikembalikan'] ?? 0)], ['Terlambat', number_format($stats['terlambat'] ?? 0)], ['Total Anggota', number_format($stats['total_anggota'] ?? 0)], ['Total Buku', number_format($stats['total_buku'] ?? 0)], ['Tanggal Laporan', date('d/m/Y H:i')]];
    $excel->addSummarySheet('Ringkasan', $summaryData);
    return $excel;
}

function exportReturnReport() {
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $status_denda = $_GET['status_denda'] ?? '';
    $anggota = $_GET['anggota'] ?? '';

    $conn = getConnection();
    $whereClause = "WHERE DATE(pg.tanggal_kembali) BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    $types = "ss";
    if ($status_denda === 'ada') $whereClause .= " AND pg.denda > 0";
    elseif ($status_denda === 'tidak') $whereClause .= " AND pg.denda = 0";
    if ($anggota) { $whereClause .= " AND a.id_anggota = ?"; $params[] = $anggota; $types .= "i"; }

    $sql = "SELECT pg.*, p.tanggal_pinjam, p.tanggal_jatuh_tempo, p.status as status_peminjaman, b.judul_buku, b.cover_buku, k.nama_kategori, a.username as nama_anggota, a.email as email_anggota, CASE WHEN pg.terlambat_hari > 0 THEN 'Terlambat' ELSE 'Tepat Waktu' END as status_keterlambatan FROM pengembalian pg JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman JOIN buku b ON pg.id_buku = b.id_buku JOIN kategori k ON b.id_kategori = k.id_kategori JOIN anggota a ON p.id_anggota = a.id_anggota $whereClause ORDER BY pg.tanggal_kembali DESC";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $returns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $statsSql = "SELECT COUNT(*) as total, SUM(pg.denda) as total_denda, SUM(CASE WHEN pg.terlambat_hari>0 THEN 1 ELSE 0 END) as total_terlambat, SUM(pg.terlambat_hari) as total_hari_terlambat, AVG(pg.terlambat_hari) as avg_hari_terlambat, COUNT(DISTINCT p.id_anggota) as total_anggota, COUNT(DISTINCT b.id_kategori) as total_kategori FROM pengembalian pg JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman JOIN buku b ON pg.id_buku = b.id_buku $whereClause";
    $statsStmt = $conn->prepare($statsSql);
    if ($types) $statsStmt->bind_param($types, ...$params);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();

    $conn->close();

    $excel = createExcelExport('laporan_pengembalian_' . date('Y-m-d'), 'Laporan Pengembalian - Digital Library', 'Periode: ' . formatDate($start_date) . ' - ' . formatDate($end_date));
    $data = [];
    foreach ($returns as $index => $return) {
        $data[] = [$index + 1, $return['judul_buku'], $return['nama_kategori'], $return['nama_anggota'], $return['email_anggota'], formatDate($return['tanggal_pinjam']), formatDate($return['tanggal_jatuh_tempo']), formatDate($return['tanggal_kembali']), $return['terlambat_hari'], $return['denda'], $return['status_keterlambatan']];
    }
    $excel->addSheet('Data Pengembalian', ['No','Judul Buku','Kategori','Anggota','Email Anggota','Tanggal Pinjam','Jatuh Tempo','Tanggal Kembali','Terlambat (hari)','Denda','Status Keterlambatan'], $data);
    $summaryData = [['Periode Laporan', formatDate($start_date) . ' - ' . formatDate($end_date)], ['Total Pengembalian', number_format($stats['total'] ?? 0)], ['Total Denda', formatCurrency($stats['total_denda'] ?? 0)], ['Pengembalian Terlambat', number_format($stats['total_terlambat'] ?? 0)], ['Total Hari Terlambat', number_format($stats['total_hari_terlambat'] ?? 0)], ['Rata-rata Terlambat (hari)', number_format($stats['avg_hari_terlambat'] ?? 0,1)], ['Total Anggota', number_format($stats['total_anggota'] ?? 0)], ['Total Kategori', number_format($stats['total_kategori'] ?? 0)], ['Tanggal Laporan', date('d/m/Y H:i')]];
    $excel->addSummarySheet('Ringkasan', $summaryData);
    return $excel;
}

function exportOverallReport() {
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $conn = getConnection();

    // Overall stats
    $overallStats = $conn->query("SELECT (SELECT COUNT(*) FROM buku) as total_buku, (SELECT SUM(stok) FROM buku) as total_stok, (SELECT COUNT(*) FROM anggota) as total_anggota, (SELECT COUNT(*) FROM petugas) as total_petugas, (SELECT COUNT(*) FROM peminjaman WHERE DATE(tanggal_pinjam) BETWEEN '$start_date' AND '$end_date') as total_peminjaman, (SELECT COUNT(*) FROM pengembalian WHERE DATE(tanggal_kembali) BETWEEN '$start_date' AND '$end_date') as total_pengembalian, (SELECT SUM(denda) FROM pengembalian WHERE DATE(tanggal_kembali) BETWEEN '$start_date' AND '$end_date') as total_denda, (SELECT COUNT(*) FROM peminjaman WHERE status = 'dipinjam') as sedang_dipinjam, (SELECT COUNT(*) FROM peminjaman WHERE status = 'terlambat') as terlambat")->fetch_assoc();

    // Combined data from books, users, and loans
    $combinedData = $conn->query("SELECT b.judul_buku, COALESCE(AVG(r.rating), 0) as rating, a.username as nama_anggota, a.email, a.status, p.tanggal_pinjam, ANY_VALUE(pg.tanggal_kembali) as tanggal_kembali, ANY_VALUE(COALESCE(pg.denda, 0)) as denda, CASE WHEN ANY_VALUE(pg.id_pengembalian) IS NOT NULL THEN 'Dikembalikan' ELSE 'Belum Dikembalikan' END as status_pengembalian FROM peminjaman p JOIN buku b ON p.id_buku = b.id_buku JOIN anggota a ON p.id_anggota = a.id_anggota LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman LEFT JOIN rating r ON b.id_buku = r.id_buku WHERE DATE(p.tanggal_pinjam) BETWEEN '$start_date' AND '$end_date' GROUP BY p.id_peminjaman, b.judul_buku, a.username, a.email, a.status, p.tanggal_pinjam ORDER BY p.tanggal_pinjam DESC")->fetch_all(MYSQLI_ASSOC);

    $conn->close();

    $excel = createExcelExport('laporan_keseluruhan_' . date('Y-m-d'), 'Laporan Keseluruhan - Digital Library', 'Periode: ' . formatDate($start_date) . ' - ' . formatDate($end_date));

    // Summary sheet
    $summaryData = [['Periode Laporan', formatDate($start_date) . ' - ' . formatDate($end_date)], ['Total Buku', number_format($overallStats['total_buku'])], ['Total Stok', number_format($overallStats['total_stok'])], ['Total Anggota', number_format($overallStats['total_anggota'])], ['Total Petugas', number_format($overallStats['total_petugas'])], ['Total Peminjaman', number_format($overallStats['total_peminjaman'])], ['Total Pengembalian', number_format($overallStats['total_pengembalian'])], ['Total Denda', formatCurrency($overallStats['total_denda'])], ['Sedang Dipinjam', number_format($overallStats['sedang_dipinjam'])], ['Terlambat', number_format($overallStats['terlambat'])], ['Tanggal Laporan', date('d/m/Y H:i')]];
    $excel->addSummarySheet('Ringkasan', $summaryData);

    // Combined data sheet
    $data = [];
    foreach ($combinedData as $row) {
        $data[] = [
            $row['judul_buku'],
            number_format($row['rating'], 1),
            $row['nama_anggota'],
            $row['email'],
            $row['status'],
            formatDate($row['tanggal_pinjam']),
            $row['tanggal_kembali'] ? formatDate($row['tanggal_kembali']) : '-',
            $row['denda'],
            $row['status_pengembalian']
        ];
    }
    $excel->addSheet('Data Keseluruhan', ['Judul Buku','Rating','Nama Anggota','Email','Status','Tanggal Pinjam','Tanggal Kembali','Denda','Status Pengembalian'], $data);

    return $excel;
}

function getActiveFilters() {
    $filters = [];
    if (isset($_GET['kategori']) && $_GET['kategori']) $filters[] = "Kategori";
    if (isset($_GET['status_stok']) && $_GET['status_stok']) $filters[] = "Status Stok";
    if (isset($_GET['tahun']) && $_GET['tahun']) $filters[] = "Tahun";
    if (isset($_GET['rating_min']) && $_GET['rating_min']) $filters[] = "Rating Min";
    return $filters ? implode(', ', $filters) : 'Tidak ada filter';
}
?>
