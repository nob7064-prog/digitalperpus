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
$page_title = 'Profile Anggota';
$page_icon = 'fas fa-user';

$success = '';
$error = '';

// Get user data
$conn = getConnection();
$userId = $_SESSION['user_id'];
$sql = "SELECT * FROM anggota WHERE id_anggota = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = sanitize($_POST['username']);
        $email = sanitize($_POST['email']);

        // Check if username or email already exists
        $checkSql = "SELECT id_anggota FROM anggota WHERE (username = ? OR email = ?) AND id_anggota != ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("ssi", $username, $email, $userId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $error = 'Username atau email sudah digunakan';
        } else {
            // Handle profile picture upload
            $foto_profil = $user['foto_profil'];
            if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['foto_profil'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                if (in_array($ext, $allowed)) {
                    $filename = 'profile_' . $userId . '_' . time() . '.' . $ext;
                    $destination = PROFILE_PATH . $filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        // Delete old profile picture if not default
                        if ($foto_profil !== 'default.png') {
                            $oldPath = PROFILE_PATH . $foto_profil;
                            if (file_exists($oldPath)) {
                                unlink($oldPath);
                            }
                        }
                        $foto_profil = $filename;
                    }
                }
            }

            // Update profile
            $updateSql = "UPDATE anggota SET username = ?, email = ?, foto_profil = ? WHERE id_anggota = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("sssi", $username, $email, $foto_profil, $userId);

            if ($updateStmt->execute()) {
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $success = 'Profil berhasil diperbarui';

                // Update user data
                $user['username'] = $username;
                $user['email'] = $email;
                $user['foto_profil'] = $foto_profil;
            } else {
                $error = 'Gagal memperbarui profil';
            }
            $updateStmt->close();
        }
        $checkStmt->close();
    }

    if ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = 'Password baru dan konfirmasi password tidak cocok';
        } elseif (!password_verify($current_password, $user['password'])) {
            $error = 'Password saat ini salah';
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $updateSql = "UPDATE anggota SET password = ? WHERE id_anggota = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $hashed_password, $userId);

            if ($updateStmt->execute()) {
                $success = 'Password berhasil diubah';
            } else {
                $error = 'Gagal mengubah password';
            }
            $updateStmt->close();
        }
    }
}

$conn->close();
?>

<?php include '../templates/header.php'; ?>

<!-- Include Sidebar -->
<?php include '../templates/anggota_sidebar.php'; ?>

<!-- Include Topbar -->
<?php include '../templates/anggota_topbar.php'; ?>

<!-- Main Content -->
<main class="main-content">
    <div class="container-fluid py-4">
        <div class="profile-container">
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="profile-header text-center">
                <div class="profile-avatar">
                    <img src="<?php echo SITE_URL; ?>uploads/profiles/<?php echo $user['foto_profil']; ?>"
                         alt="Profile"
                         onerror="this.src='<?php echo SITE_URL; ?>uploads/profiles/default.png'">
                </div>
                <h2><?php echo htmlspecialchars($user['username']); ?></h2>
                <p class="mb-0"><?php echo htmlspecialchars($user['email']); ?></p>
            </div>

            <ul class="nav nav-pills mb-3 justify-content-center" id="profileTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#editProfile">
                        <i class="fas fa-user-edit me-2"></i>Edit Profile
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#changePassword">
                        <i class="fas fa-lock me-2"></i>Ubah Password
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Edit Profile Tab -->
                <div class="tab-pane fade show active" id="editProfile">
                    <div class="profile-card">
                        <h4 class="mb-4">Edit Profile</h4>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="username" name="username"
                                           value="<?php echo htmlspecialchars($user['username']); ?>" required
                                           placeholder="Masukkan username">
                                </div>
                                <div class="form-text">Minimal 3 karakter, hanya huruf, angka, dan underscore</div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required
                                           placeholder="Masukkan email">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Foto Profil</label>
                                <input type="file" class="form-control" name="foto_profil" accept="image/*">
                                <div class="form-text">Ukuran maksimal 2MB. Format: JPG, PNG, GIF</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Tanggal Daftar</label>
                                <input type="text" class="form-control"
                                       value="<?php echo formatDate($user['tanggal_daftar']); ?>" readonly>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <input type="text" class="form-control"
                                       value="<?php echo ucfirst($user['status']); ?>" readonly>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Simpan Perubahan
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Change Password Tab -->
                <div class="tab-pane fade" id="changePassword">
                    <div class="profile-card">
                        <h4 class="mb-4">Ubah Password</h4>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_password">

                            <div class="mb-3">
                                <label for="current_password" class="form-label">Password Saat Ini</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required placeholder="Masukkan password saat ini">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleCurrentPassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">Password Baru</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required placeholder="Masukkan password baru">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleNewPassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength" id="passwordStrength"></div>
                                <div class="form-text">Minimal 8 karakter, harus mengandung huruf besar, kecil, dan angka</div>
                            </div>

                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Konfirmasi Password Baru</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Konfirmasi password baru">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text" id="passwordMatch"></div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-2"></i>Ubah Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .main-content {
        margin-left: var(--sidebar-width);
        margin-top: var(--topbar-height);
        padding: 2rem;
        min-height: calc(100vh - var(--topbar-height));
        transition: var(--transition);
        width: calc(100% - var(--sidebar-width));
    }

    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100%;
            padding: 1.5rem;
        }
    }

    .container-fluid {
        max-width: 100%;
        padding-left: 0;
        padding-right: 0;
    }

    .profile-container {
        max-width: 800px;
        margin: 0 auto;
    }

    .profile-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 2rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        position: relative;
    }

    .profile-avatar {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        border: 5px solid white;
        overflow: hidden;
        margin: 0 auto 1rem;
        background: #f0f0f0;
    }

    .profile-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-card {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        margin-bottom: 2rem;
    }

    .form-control, .form-select {
        border-radius: 10px;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
    }

    .btn-primary {
        background: var(--primary-color);
        border: none;
        padding: 12px 30px;
        border-radius: 10px;
        font-weight: 600;
    }

    .btn-primary:hover {
        background: var(--secondary-color);
    }

    .nav-pills .nav-link {
        color: var(--primary-color);
        border-radius: 10px;
        padding: 12px 20px;
        margin-right: 10px;
    }

    .nav-pills .nav-link.active {
        background: var(--primary-color);
        color: white;
    }

    .password-strength {
        height: 5px;
        margin-top: 5px;
        border-radius: 5px;
        transition: all 0.3s;
    }

    .strength-0 { width: 0%; background: #dc3545; }
    .strength-1 { width: 25%; background: #dc3545; }
    .strength-2 { width: 50%; background: #ffc107; }
    .strength-3 { width: 75%; background: #198754; }
    .strength-4 { width: 100%; background: #198754; }

    .input-group-text {
        background: #f8f9fa;
        border: 2px solid #e0e0e0;
        border-right: none;
    }

    .btn-outline-secondary {
        border: 2px solid #e0e0e0;
        border-left: none;
    }

    .btn-outline-secondary:hover {
        background: #e9ecef;
    }
</style>

<script>
    // Tab switching
    const profileTabs = document.querySelectorAll('#profileTabs button');
    profileTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            profileTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Auto-dismiss alerts
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            if (alert.classList.contains('show')) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        });
    }, 5000);

    // Password visibility toggle
    function togglePasswordVisibility(buttonId, inputId) {
        const button = document.getElementById(buttonId);
        const input = document.getElementById(inputId);

        button.addEventListener('click', function() {
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });
    }

    // Initialize password toggles
    togglePasswordVisibility('toggleCurrentPassword', 'current_password');
    togglePasswordVisibility('toggleNewPassword', 'new_password');
    togglePasswordVisibility('toggleConfirmPassword', 'confirm_password');

    // Password strength checker
    function checkPasswordStrength(password) {
        let strength = 0;
        const strengthBar = document.getElementById('passwordStrength');

        // Remove previous strength classes
        strengthBar.className = 'password-strength';

        if (password.length >= 8) strength++;
        if (/[a-z]/.test(password)) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;

        strengthBar.classList.add(`strength-${strength}`);
        return strength;
    }

    // Password match checker
    function checkPasswordMatch() {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const matchText = document.getElementById('passwordMatch');

        if (confirmPassword === '') {
            matchText.textContent = '';
            return;
        }

        if (newPassword === confirmPassword) {
            matchText.textContent = 'Password cocok';
            matchText.style.color = '#198754';
        } else {
            matchText.textContent = 'Password tidak cocok';
            matchText.style.color = '#dc3545';
        }
    }

    // Event listeners for password fields
    document.getElementById('new_password').addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
    });

    document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
</script>

<?php include '../templates/footer.php'; ?>
