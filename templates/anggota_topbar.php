<?php
// Get notifications
$conn = getConnection();
$userId = $_SESSION['user_id'];

// Get due date notifications
$sql = "SELECT COUNT(*) as total FROM peminjaman WHERE id_anggota = ? AND status = 'dipinjam' AND tanggal_jatuh_tempo <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$due_date_count = $result->fetch_assoc()['total'];
$stmt->close();

// Get unread notifications (check if table exists first)
$unread_notif_count = 0;
$tableExists = $conn->query("SHOW TABLES LIKE 'notifikasi'")->num_rows > 0;
if ($tableExists) {
    $sql = "SELECT COUNT(*) as total FROM notifikasi WHERE id_anggota = ? AND dibaca = FALSE";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $unread_notif_count = $result->fetch_assoc()['total'];
    $stmt->close();
}

// Total notification count
$notif_count = $due_date_count + $unread_notif_count;

// Get borrowing info
$sql = "SELECT COUNT(*) as total FROM peminjaman WHERE id_anggota = ? AND status = 'dipinjam'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$borrowing_count = $result->fetch_assoc()['total'];
$stmt->close();

$conn->close();
?>
<!-- Topbar -->
<nav class="topbar">
    <div class="topbar-left">
        <button class="btn sidebar-toggle d-lg-none">
            <i class="fas fa-bars"></i>
        </button>
        <div class="page-title">
            <i class="<?php echo isset($page_icon) ? $page_icon : 'fas fa-file-alt'; ?>"></i>
            <span><?php echo $page_title; ?></span>
        </div>
    </div>
</nav>

<style>

    <style>
    .topbar {
        position: fixed;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        height: var(--topbar-height);
        background: white;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        z-index: 999;
        display: flex;
        align-items: center;
        padding: 0 2rem;
        transition: var(--transition);
    }
    
    .topbar-left {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .sidebar-toggle {
        background: var(--primary-light);
        border: none;
        width: 40px;
        height: 40px;
        border-radius: 8px;
        color: var(--primary-color);
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
    }
    
    .sidebar-toggle:hover {
        background: var(--primary-color);
        color: white;
        transform: rotate(90deg);
    }
    
    .topbar-right {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .btn-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: var(--primary-light);
        border: none;
        color: var(--primary-color);
        font-size: 1.4rem;
        position: relative;
        overflow: visible;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
        padding: 0;
    }

    /* Larger blue notification box when there are notifications */
    .btn-icon.has-notif {
        width: 56px;
        height: 56px;
        border-radius: 11px;
        background: var(--primary-color);
        color: #fff;
        font-size: 1.5rem;
    }
    
    .btn-icon:hover {
        background: var(--primary-color);
        color: white;
        transform: translateY(-2px);
    }
    
    .notification-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        background: #dc3545;
        color: #fff;
        font-size: 0.72rem;
        padding: 2px 6px;
        border-radius: 999px;
        line-height: 1;
        min-width: 20px;
        text-align: center;
        box-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }
    
    .user-menu {
        display: flex;
        align-items: center;
        background: none;
        border: none;
        padding: 5px 12px;
        border-radius: 8px;
        transition: var(--transition);
    }
    
    .user-menu:hover {
        background: var(--primary-light);
    }
    
    .user-avatar-small {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        overflow: hidden;
        margin-right: 10px;
        border: 2px solid var(--primary-light);
    }
    
    .user-avatar-small img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .user-name-small {
        font-weight: 500;
        color: var(--gray-800);
        font-size: 0.95rem;
    }
    
    .dropdown-menu {
        border: none;
        box-shadow: var(--box-shadow);
        border-radius: var(--border-radius);
        padding: 0.5rem;
        min-width: 250px;
    }
    
    .dropdown-header {
        font-weight: 600;
        color: var(--gray-700);
        font-size: 0.9rem;
        padding: 0.75rem 1rem;
    }
    
    .dropdown-item {
        padding: 0.75rem 1rem;
        border-radius: 8px;
        margin-bottom: 2px;
        font-weight: 500;
        color: var(--gray-700);
        transition: var(--transition);
    }
    
    .dropdown-item:hover {
        background-color: var(--primary-light);
        color: var(--primary-color);
    }
    
    .dropdown-item i {
        width: 20px;
        color: var(--gray-600);
    }
    
    .dropdown-item:hover i {
        color: var(--primary-color);
    }
    
    .notification-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: white;
    }

    /* Scrollable notification list when there are more than 4 items */
    .dropdown-menu .notif-list {
        max-height: 320px; /* approx 4 items */
        overflow-y: auto;
        padding-right: 6px;
    }

    .dropdown-menu .notif-list .dropdown-item {
        margin-bottom: 6px;
    }
    
    .notification-title {
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 2px;
    }
    
    .notification-text {
        font-size: 0.85rem;
        color: var(--gray-600);
    }
    
    @media (max-width: 992px) {
        .topbar {
            left: 0;
        }
        
        .user-name-small {
            display: none;
        }
    }
</style>

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
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <small>Pastikan semua peminjaman telah dikembalikan sebelum logout.</small>
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
    // Mobile sidebar toggle
    document.querySelector('.sidebar-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('mobile-open');
    });

    // Logout confirmation function
    function confirmLogout() {
        const modal = new bootstrap.Modal(document.getElementById('logoutModal'));
        modal.show();
    }

    // Mark notification as read
    function markAsRead(notificationId) {
        fetch('proses.php?action=mark_notification_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + notificationId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update notification count and UI
                location.reload();
            }
        })
        .catch(error => console.error('Error:', error));
    }

    // Prevent dismissing the persistent fine alert
    document.addEventListener('DOMContentLoaded', function() {
        const persistentAlert = document.querySelector('.persistent-fine-alert');
        if (persistentAlert) {
            const closeBtn = persistentAlert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Instead of closing, just hide temporarily
                    persistentAlert.style.display = 'none';
                    // Show again after 5 seconds
                    setTimeout(function() {
                        persistentAlert.style.display = 'block';
                    }, 5000);
                });
            }
        }
    });
</script>

<style>
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
