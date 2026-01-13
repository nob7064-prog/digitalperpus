<?php
// Initialize variables if not set
$page_title = $page_title ?? 'Admin Panel';
$page_icon = $page_icon ?? 'fas fa-file-alt';
?>

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

<script>
    // Mobile sidebar toggle
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('mobile-open');
                document.querySelector('.content-wrapper').classList.toggle('mobile-open');
            });
        }
    });
</script>

<style>
    /* Topbar Styles */
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
</style>