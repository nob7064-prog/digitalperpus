<?php
// Get current page
$current_page = basename($_SERVER['PHP_SELF']);

// Get pending count for sidebar badge
$conn = getConnection();
$pending_loans_count = $conn->query("SELECT COUNT(*) as total FROM peminjaman WHERE status = 'pending'")->fetch_assoc()['total'];
$pending_returns_count = $conn->query("SELECT COUNT(*) as total FROM pengembalian WHERE status_denda = 'belum_lunas'")->fetch_assoc()['total'];
$total_pending_for_sidebar = $pending_loans_count + $pending_returns_count;
$conn->close();
?>

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

<script>
    // Logout confirmation function
    function confirmLogout() {
        const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
        modal.show();
    }
</script>

<style>
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

    .sidebar-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--gray-200);
        text-align: center;
        font-size: 0.85rem;
    }

    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.mobile-open {
            transform: translateX(0);
        }
    }

    .bg-gradient-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    .modal-content {
        border: none;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    .modal-header {
        border-radius: 15px 15px 0 0;
        border-bottom: none;
        padding: 1.5rem;
    }

    .modal-body {
        padding: 2rem;
    }

    .modal-footer {
        border-top: 1px solid #e9ecef;
        padding: 1.5rem;
        border-radius: 0 0 15px 15px;
    }

    .btn {
        border-radius: 8px;
        font-weight: 500;
        padding: 0.625rem 1.25rem;
        transition: all 0.2s;
    }

    .btn-danger {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        border: none;
    }

    .btn-danger:hover {
        background: linear-gradient(135deg, #ee5a52 0%, #dc4545 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(238, 90, 82, 0.3);
    }

    .btn-secondary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
</style>