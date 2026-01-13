<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function registerUser($data) {
    $conn = getConnection();
    
    $username = sanitize($data['username']);
    $email = sanitize($data['email']);
    $password = password_hash($data['password'], PASSWORD_DEFAULT);
    $role = sanitize($data['role']);
    $verification_token = generateToken();
    
    if ($role === 'anggota') {
        $sql = "INSERT INTO anggota (username, email, password, verification_token, token_expires) 
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))";
        $table = 'anggota';
        $id_field = 'id_anggota';
    } else {
        $sql = "INSERT INTO petugas (username, email, password, verification_token, token_expires) 
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))";
        $table = 'petugas';
        $id_field = 'id_petugas';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $username, $email, $password, $verification_token);
    
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        
        // For testing, auto verify email
        // In production, uncomment the next line and remove the auto-verify code
        // sendVerificationEmail($email, $verification_token);
        
        // Auto verify for testing (remove in production)
        $verify_sql = "UPDATE $table SET status = 'aktif', verification_token = NULL, token_expires = NULL 
                      WHERE $id_field = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("i", $user_id);
        $verify_stmt->execute();
        $verify_stmt->close();
        
        // Log activity
        logActivity($user_id, $role, 'Pendaftaran akun baru');
        
        $stmt->close();
        $conn->close();
        
        return ['success' => true, 'message' => 'Registrasi berhasil! Akun Anda sudah aktif. Silakan login.'];
    } else {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        
        if (strpos($error, 'Duplicate entry') !== false) {
            if (strpos($error, 'username') !== false) {
                return ['success' => false, 'message' => 'Username sudah digunakan.'];
            } elseif (strpos($error, 'email') !== false) {
                return ['success' => false, 'message' => 'Email sudah digunakan.'];
            }
        }
        
        return ['success' => false, 'message' => 'Registrasi gagal: ' . $error];
    }
}

function loginUser($email, $password, $role) {
    $conn = getConnection();
    
    if ($role === 'anggota') {
        $sql = "SELECT * FROM anggota WHERE email = ?";
        $table = 'anggota';
        $id_field = 'id_anggota';
    } else {
        $sql = "SELECT * FROM petugas WHERE email = ?";
        $table = 'petugas';
        $id_field = 'id_petugas';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Check if account is active
        if ($user['status'] !== 'aktif') {
            return ['success' => false, 'message' => 'Akun belum aktif. Silakan verifikasi email atau hubungi admin.'];
        }
        
        // Check if account is blocked
        if (isset($user['status']) && $user['status'] === 'terblokir') {
            return ['success' => false, 'message' => 'Akun Anda diblokir. Silakan hubungi admin.'];
        }
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user[$id_field];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['user_type'] = $role;
            
            if ($role === 'petugas') {
                $_SESSION['user_level'] = $user['level'];
            }
            
            // Update last login
            $update_sql = "UPDATE $table SET last_login = NOW() WHERE $id_field = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $user[$id_field]);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Log activity
            logActivity($user[$id_field], $role, 'Login ke sistem');
            
            $stmt->close();
            $conn->close();
            
            return ['success' => true, 'message' => 'Login berhasil'];
        } else {
            $stmt->close();
            $conn->close();
            return ['success' => false, 'message' => 'Password salah.'];
        }
    }
    
    $stmt->close();
    $conn->close();
    
    return ['success' => false, 'message' => 'Email tidak ditemukan.'];
}

function verifyEmail($token) {
    $conn = getConnection();
    
    // Check anggota
    $sql = "SELECT id_anggota FROM anggota WHERE verification_token = ? AND token_expires > NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $update_sql = "UPDATE anggota SET status = 'aktif', verification_token = NULL, token_expires = NULL WHERE id_anggota = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $user['id_anggota']);
        $update_stmt->execute();
        $update_stmt->close();
        
        logActivity($user['id_anggota'], 'anggota', 'Verifikasi email berhasil');
        return ['success' => true, 'message' => 'Email berhasil diverifikasi. Akun sekarang aktif.'];
    }
    
    $stmt->close();
    
    // Check petugas
    $sql = "SELECT id_petugas FROM petugas WHERE verification_token = ? AND token_expires > NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $update_sql = "UPDATE petugas SET status = 'aktif', verification_token = NULL, token_expires = NULL WHERE id_petugas = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $user['id_petugas']);
        $update_stmt->execute();
        $update_stmt->close();
        
        logActivity($user['id_petugas'], 'petugas', 'Verifikasi email berhasil');
        return ['success' => true, 'message' => 'Email berhasil diverifikasi. Akun sekarang aktif.'];
    }
    
    $stmt->close();
    $conn->close();
    
    return ['success' => false, 'message' => 'Token verifikasi tidak valid atau telah kadaluarsa'];
}

function forgotPassword($email) {
    $conn = getConnection();
    
    // Check if email exists in anggota
    $sql = "SELECT id_anggota FROM anggota WHERE email = ? AND status = 'aktif'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $reset_token = generateToken();
        $update_sql = "UPDATE anggota SET verification_token = ?, token_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id_anggota = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $reset_token, $user['id_anggota']);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Send reset email (simulated for now)
        $reset_link = SITE_URL . "auth/reset_password.php?token=" . $reset_token;
        error_log("Reset password link for $email: $reset_link");
        
        $stmt->close();
        $conn->close();
        return ['success' => true, 'message' => 'Instruksi reset password telah dikirim ke email Anda.'];
    }
    
    $stmt->close();
    
    // Check if email exists in petugas
    $sql = "SELECT id_petugas FROM petugas WHERE email = ? AND status = 'aktif'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $reset_token = generateToken();
        $update_sql = "UPDATE petugas SET verification_token = ?, token_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id_petugas = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $reset_token, $user['id_petugas']);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Send reset email (simulated for now)
        $reset_link = SITE_URL . "auth/reset_password.php?token=" . $reset_token;
        error_log("Reset password link for $email: $reset_link");
        
        $stmt->close();
        $conn->close();
        return ['success' => true, 'message' => 'Instruksi reset password telah dikirim ke email Anda.'];
    }
    
    $stmt->close();
    $conn->close();
    
    return ['success' => false, 'message' => 'Email tidak ditemukan atau akun tidak aktif.'];
}
?>