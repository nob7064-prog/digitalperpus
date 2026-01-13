<?php
require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect(SITE_URL . 'admin/dashboard.php');
    } else {
        redirect(SITE_URL . 'anggota/dashboard.php');
    }
}

$error = '';
$success = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate password
    if ($_POST['password'] !== $_POST['confirm_password']) {
        $error = 'Password dan konfirmasi password tidak cocok';
    } else {
        $result = registerUser($_POST);
        
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .register-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            border: 1px solid #eee;
        }
        
        .register-header {
            background: var(--dark-color);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .register-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.8rem;
        }
        
        .register-header p {
            opacity: 0.9;
            margin-bottom: 0;
            margin-top: 0.5rem;
            font-size: 0.95rem;
        }
        
        .register-body {
            padding: 2rem;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            padding: 12px 15px;
            transition: all 0.3s;
            font-size: 0.95rem;
        }
        
        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-right: none;
        }
        
        .form-select {
            border-radius: 5px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            font-size: 0.95rem;
        }
        
        .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-register {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 5px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .btn-register:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .alert {
            border-radius: 5px;
            border: none;
            font-size: 0.95rem;
        }
        
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .strength-0 { width: 0%; background: #dc3545; }
        .strength-1 { width: 25%; background: #dc3545; }
        .strength-2 { width: 50%; background: #f39c12; }
        .strength-3 { width: 75%; background: #27ae60; }
        .strength-4 { width: 100%; background: #27ae60; }
        
        .link-login {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .link-login:hover {
            color: var(--dark-color);
            text-decoration: underline;
        }
        
        .logo {
            font-size: 2.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .form-text {
            font-size: 0.875rem;
            color: #666;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .btn-outline-secondary {
            border: 2px solid #e0e0e0;
            border-left: none;
            transition: all 0.3s;
        }
        
        .btn-outline-secondary:hover {
            background-color: #f8f9fa;
            border-color: #ddd;
        }
        
        @media (max-width: 576px) {
            .register-container {
                margin: 1rem;
            }
            
            .register-header {
                padding: 1.5rem;
            }
            
            .register-body {
                padding: 1.5rem;
            }
            
            .register-header h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <div class="logo">
                <i class="fas fa-user-plus"></i>
            </div>
            <h2>Buat Akun Baru</h2>
            <p>Bergabung dengan komunitas pembaca kami</p>
        </div>
        
        <div class="register-body">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="registerForm">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" required 
                               placeholder="Masukkan username">
                    </div>
                    <div class="form-text">Minimal 3 karakter, hanya huruf, angka, dan underscore</div>
                </div>
                
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" required 
                               placeholder="Masukkan email">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required 
                               placeholder="Masukkan password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                    <div class="form-text">Minimal 8 karakter, harus mengandung huruf besar, kecil, dan angka</div>
                </div>
                
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Konfirmasi Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                               placeholder="Konfirmasi password">
                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-text" id="passwordMatch"></div>
                </div>
                
                <div class="mb-3">
                    <label for="role" class="form-label">Daftar Sebagai</label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="">Pilih Role</option>
                        <option value="anggota">Anggota</option>
                        <option value="petugas">Admin/Petugas</option>
                    </select>
                    <div class="form-text">Pilih "Admin/Petugas" hanya jika Anda adalah staf perpustakaan</div>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="terms" required>
                    <label class="form-check-label" for="terms">
                        Saya setuju dengan <a href="#" class="link-login">Syarat dan Ketentuan</a>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-register mb-3" id="submitBtn">
                    <i class="fas fa-user-plus me-2"></i>Daftar
                </button>
                
                <div class="text-center">
                    <p class="mb-0">Sudah punya akun? 
                        <a href="<?php echo SITE_URL; ?>auth/login.php" class="link-login">
                            Login di sini
                        </a>
                    </p>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Check password strength
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength++;
            
            // Contains lowercase
            if (/[a-z]/.test(password)) strength++;
            
            // Contains uppercase
            if (/[A-Z]/.test(password)) strength++;
            
            // Contains numbers
            if (/[0-9]/.test(password)) strength++;
            
            // Contains special characters
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            strengthBar.className = 'password-strength strength-' + Math.min(strength, 4);
        });
        
        // Check password match
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchText = document.getElementById('passwordMatch');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
                matchText.className = 'form-text';
            } else if (password === confirmPassword) {
                matchText.textContent = '✓ Password cocok';
                matchText.className = 'form-text text-success';
            } else {
                matchText.textContent = '✗ Password tidak cocok';
                matchText.className = 'form-text text-danger';
            }
        });
        
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms');
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Password dan konfirmasi password harus sama!');
                return false;
            }
            
            if (!terms.checked) {
                e.preventDefault();
                alert('Anda harus menyetujui syarat dan ketentuan!');
                return false;
            }
            
            // Disable submit button to prevent double submission
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Mendaftarkan...';
        });
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>