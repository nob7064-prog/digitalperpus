<?php
// Get user profile data
$conn = getConnection();
$userId = $_SESSION['user_id'];
$sql = "SELECT foto_profil, total_denda FROM anggota WHERE id_anggota = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$foto_profil = $userData['foto_profil'] ?? 'default.png';
$total_denda = $userData['total_denda'] ?? 0;
$stmt->close();
$conn->close();

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <i class="fas fa-book-reader"></i>
            <span>Digital Library</span>
        </div>
        <div class="sidebar-user">
            <div class="user-avatar">
                <img src="<?php echo SITE_URL; ?>uploads/profiles/<?php echo $foto_profil; ?>" 
                     alt="<?php echo $_SESSION['username']; ?>"
                     onerror="this.src='<?php echo SITE_URL; ?>uploads/profiles/default.png'">
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                <div class="user-role">Anggota</div>
            </div>
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
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'daftar_buku.php' ? 'active' : ''; ?>" href="daftar_buku.php">
                    <i class="fas fa-book"></i>
                    <span>Daftar Buku</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'riwayat.php' ? 'active' : ''; ?>" href="riwayat.php">
                    <i class="fas fa-history"></i>
                    <span>Riwayat</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </li>
            <!-- 'Bayar Denda' menu removed per request -->
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

<!-- Denda Modal -->
<div class="modal fade" id="dendaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Bayar Denda</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Anda Memiliki Denda!</h6>
                    <p class="mb-1">Total denda: <strong><?php echo formatCurrency($total_denda); ?></strong></p>
                    <p class="mb-0">Silakan lakukan pembayaran untuk mengakses semua fitur.</p>
                </div>
                
                <form id="formBayarDenda">
                    <div class="mb-3">
                        <label class="form-label">Metode Pembayaran</label>
                        <select class="form-select" name="metode" id="metodePembayaran" required>
                            <option value="">Pilih Metode</option>
                            <option value="tunai">Tunai</option>
                            <option value="transfer">Transfer Bank</option>
                        </select>
                    </div>
                    
                    <div id="buktiTransfer" class="mb-3" style="display: none;">
                        <label class="form-label">Bukti Transfer</label>
                        <input type="file" class="form-control" name="bukti_transfer" accept="image/*,.pdf">
                        <div class="form-text">Upload bukti transfer (jpg, png, pdf)</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-check me-2"></i>Bayar Sekarang
                    </button>
                </form>
            </div>
        </div>
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
                    <p class="text-muted mb-0">Anda akan keluar dari akun anggota dan kembali ke halaman utama.</p>
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
    
    .sidebar-user {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .user-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        overflow: hidden;
        border: 2px solid var(--primary-light);
    }
    
    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .user-info {
        flex: 1;
    }
    
    .user-name {
        font-weight: 600;
        color: var(--gray-800);
        font-size: 0.95rem;
    }
    
    .user-role {
        font-size: 0.85rem;
        color: var(--gray-600);
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
    
    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
        }
        
        .sidebar.mobile-open {
            transform: translateX(0);
        }
    }
</style>

<script>
    // Denda Modal
    document.getElementById('metodePembayaran').addEventListener('change', function() {
        const buktiTransfer = document.getElementById('buktiTransfer');
        buktiTransfer.style.display = this.value === 'transfer' ? 'block' : 'none';
    });
    
    document.getElementById('formBayarDenda').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        showLoading();
        
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
</script>