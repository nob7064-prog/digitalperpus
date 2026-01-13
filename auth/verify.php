<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if ($token) {
    $result = verifyEmail($token);
    $success = $result['success'];
    $message = $result['message'];
} else {
    $message = 'Token verifikasi tidak ditemukan';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: #4bb543;
            --danger-color: #dc3545;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .verify-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            text-align: center;
            padding: 3rem;
        }
        
        .verify-icon {
            font-size: 5rem;
            margin-bottom: 2rem;
        }
        
        .success {
            color: var(--success-color);
        }
        
        .error {
            color: var(--danger-color);
        }
        
        .verify-container h2 {
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .verify-container p {
            margin-bottom: 2rem;
            color: #666;
            line-height: 1.6;
        }
        
        .btn-home {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-home:hover {
            background: #3a56d4;
            transform: translateY(-2px);
            color: white;
        }
        
        .spinner {
            display: inline-block;
            width: 50px;
            height: 50px;
            border: 3px solid rgba(67, 97, 238, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 2rem;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <?php if ($success): ?>
            <div class="verify-icon success">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2>Verifikasi Berhasil!</h2>
            <p><?php echo $message; ?></p>
            <p>Akun Anda sekarang aktif dan dapat digunakan untuk login.</p>
            <a href="<?php echo SITE_URL; ?>auth/login.php" class="btn-home">
                <i class="fas fa-sign-in-alt me-2"></i>Login Sekarang
            </a>
        <?php elseif ($token): ?>
            <div class="spinner"></div>
            <h2>Memverifikasi...</h2>
            <p>Sedang memproses verifikasi email Anda.</p>
            <script>
                setTimeout(function() {
                    window.location.href = "<?php echo SITE_URL; ?>auth/verify.php?token=<?php echo $token; ?>";
                }, 2000);
            </script>
        <?php else: ?>
            <div class="verify-icon error">
                <i class="fas fa-times-circle"></i>
            </div>
            <h2>Verifikasi Gagal</h2>
            <p><?php echo $message; ?></p>
            <a href="<?php echo SITE_URL; ?>auth/login.php" class="btn-home">
                <i class="fas fa-arrow-left me-2"></i>Kembali ke Login
            </a>
        <?php endif; ?>
    </div>
</body>
</html>