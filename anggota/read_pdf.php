<?php
require_once '../config/database.php';
require_once '../includes/session.php';

if (!isLoggedIn() || !isAnggota()) {
    header('Location: ' . SITE_URL . 'auth/login.php');
    exit();
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    exit('Invalid book id');
}

$conn = getConnection();
$stmt = $conn->prepare("SELECT file_pdf FROM buku WHERE id_buku = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { $stmt->close(); $conn->close(); exit('Book not found'); }
$row = $res->fetch_assoc();
$stmt->close();
$conn->close();

$file = $row['file_pdf'];
if (empty($file)) exit('No PDF available for this book');

// Verify member currently has this book with status 'dipinjam' and no overdue/denda
$uid = $_SESSION['user_id'];
$conn2 = getConnection();
$loanStmt = $conn2->prepare("SELECT status FROM peminjaman WHERE id_anggota = ? AND id_buku = ? ORDER BY created_at DESC LIMIT 1");
$loanStmt->bind_param("ii", $uid, $id);
$loanStmt->execute();
$loanRes = $loanStmt->get_result();
$hasLoan = false; $loanStatus = null;
if ($loanRes->num_rows > 0) { $loanStatus = $loanRes->fetch_assoc()['status']; $hasLoan = true; }
$loanStmt->close();

$overdue = $conn2->query("SELECT COUNT(*) as c FROM peminjaman WHERE id_anggota = $uid AND status = 'terlambat'")->fetch_assoc()['c'];
$unpaid = $conn2->query("SELECT COUNT(*) as c FROM denda WHERE id_anggota = $uid AND status = 'belum_lunas'")->fetch_assoc()['c'];
$conn2->close();

if (!$hasLoan || $loanStatus !== 'dipinjam') {
    exit('Akses ditolak: Anda tidak sedang meminjam buku ini.');
}

if (intval($overdue) > 0 || intval($unpaid) > 0) {
    exit('Akses ditolak: lunasi denda atau selesaikan pengembalian yang terlambat terlebih dahulu.');
}

// generate short-lived token
$token = bin2hex(random_bytes(16));
if (!isset($_SESSION['pdf_tokens']) || !is_array($_SESSION['pdf_tokens'])) $_SESSION['pdf_tokens'] = [];
$path = realpath(__DIR__ . '/../uploads/pdfs/' . $file);
$_SESSION['pdf_tokens'][$token] = ['path' => $path, 'exp' => time() + 300]; // 5 minutes

// also write a fallback temp-file token mapping (expires same as session token)
$tmpDir = sys_get_temp_dir();
$tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'dl_pdf_token_' . $token;
$tmpData = json_encode(['path' => $path, 'exp' => time() + 300]);
@file_put_contents($tmpFile, $tmpData);

// Security headers for the viewer page
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self' https://cdnjs.cloudflare.com; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; object-src 'none'; frame-ancestors 'self';");

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Baca Buku</title>
    <style>
        body,html{height:100%;margin:0}
        .viewer{position:relative;height:100vh;background:#f5f7fb}
        iframe{width:100%;height:100%;border:0}
        /* watermark */
        .wm{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);opacity:0.08;font-size:64px;color:#000;pointer-events:none;user-select:none}

        /* Print prevention CSS */
        @media print {
            * { display: none !important; }
            body:before {
                content: "PRINTING DISABLED - This document cannot be printed";
                font-size: 24px;
                color: red;
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 9999;
            }
        }

        /* Allow basic interaction for scrolling and navigation */
        iframe {
            pointer-events: auto;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        /* Prevent text selection on the main page */
        body {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Prevent context menu */
        * {
            -webkit-touch-callout: none;
        }
    </style>
</head>
<body>
    <div class="viewer">
        <div class="wm">Situs Digital Library</div>
        <iframe id="pdfViewer" src="serve_pdf.php?token=<?php echo $token; ?>" width="100%" height="100%" style="border: none;"></iframe>
    </div>

    <script>
    (function(){
        // Function to block ALL shortcuts aggressively
        function blockAllShortcuts(e) {
            // Block Ctrl+S, Ctrl+P specifically and show alert
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('Fitur SIMPAN (Ctrl+S) dinonaktifkan untuk dokumen ini.');
                return false;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('Fitur CETAK (Ctrl+P) dinonaktifkan untuk dokumen ini.');
                return false;
            }

            // Block all other dangerous shortcuts
            const dangerousKeys = ['u','U','c','C','v','V','a','A','o','O','n','N','w','W','t','T','r','R'];
            if ((e.ctrlKey || e.metaKey) && dangerousKeys.includes(e.key)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('Shortcut keyboard dinonaktifkan untuk dokumen ini.');
                return false;
            }

            // Block F12, PrintScreen, Alt+F4
            if (e.key === 'F12' || e.key === 'PrintScreen' || (e.altKey && e.key === 'F4')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('Aksi ini dilarang untuk dokumen ini.');
                return false;
            }

            // Block Ctrl+Shift combinations
            if (e.ctrlKey && e.shiftKey) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('Kombinasi keyboard dinonaktifkan untuk dokumen ini.');
                return false;
            }
        }

        // Add event listeners with capture phase to intercept before anything else
        document.addEventListener('keydown', blockAllShortcuts, true);
        document.addEventListener('keypress', blockAllShortcuts, true);
        document.addEventListener('keyup', blockAllShortcuts, true);
        window.addEventListener('keydown', blockAllShortcuts, true);
        window.addEventListener('keypress', blockAllShortcuts, true);
        window.addEventListener('keyup', blockAllShortcuts, true);

        // Override right-click completely
        document.addEventListener('contextmenu', function(e){
            e.preventDefault();
            e.stopImmediatePropagation();
            alert('Klik kanan DINONAKTIFKAN untuk dokumen ini.');
            return false;
        }, true);

        // Block all selection and clipboard operations
        document.addEventListener('selectstart', function(e){ e.preventDefault(); return false; }, true);
        document.addEventListener('dragstart', function(e){ e.preventDefault(); return false; }, true);
        document.addEventListener('copy', function(e){ e.preventDefault(); alert('Copy dinonaktifkan.'); return false; }, true);
        document.addEventListener('cut', function(e){ e.preventDefault(); alert('Cut dinonaktifkan.'); return false; }, true);
        document.addEventListener('paste', function(e){ e.preventDefault(); alert('Paste dinonaktifkan.'); return false; }, true);

        // Override print functions
        window.print = function(){
            alert('Fitur CETAK DINONAKTIFKAN untuk dokumen ini.');
            return false;
        };

        window.addEventListener('beforeprint', function(e){
            e.preventDefault();
            alert('Fitur CETAK DINONAKTIFKAN untuk dokumen ini.');
            return false;
        }, true);

        // Block all forms and submissions
        document.addEventListener('submit', function(e){
            e.preventDefault();
            alert('Form submission dinonaktifkan.');
            return false;
        }, true);

        // Disable fetch and XMLHttpRequest
        const originalFetch = window.fetch;
        window.fetch = function(){
            alert('Network requests dinonaktifkan.');
            return Promise.reject(new Error('Disabled'));
        };

        const originalXMLHttpRequest = window.XMLHttpRequest;
        window.XMLHttpRequest = function(){
            alert('AJAX requests dinonaktifkan.');
            throw new Error('Disabled');
        };

        // Get the iframe and apply security measures after it loads
        const iframe = document.getElementById('pdfViewer');
        if (iframe) {
            iframe.addEventListener('load', function(){
                try {
                    const iframeWin = iframe.contentWindow;
                    if (iframeWin) {
                        // Override iframe's print function
                        iframeWin.print = function(){
                            alert('CETAK DINONAKTIFKAN dalam dokumen ini.');
                            return false;
                        };

                        // Add keyboard blocking to iframe
                        iframeWin.addEventListener('keydown', blockAllShortcuts, true);
                        iframeWin.addEventListener('keypress', blockAllShortcuts, true);
                        iframeWin.addEventListener('keyup', blockAllShortcuts, true);
                        iframeWin.addEventListener('contextmenu', function(e){
                            e.preventDefault();
                            alert('Klik kanan dinonaktifkan dalam dokumen ini.');
                            return false;
                        }, true);
                    }
                } catch(e) {
                    console.log('Cannot access iframe due to security policy');
                }
            });
        }

        // Continuous security monitoring
        let securityCheck = setInterval(function(){
            // Check if dev tools are open
            if (window.outerHeight - window.innerHeight > 200 || window.outerWidth - window.innerWidth > 200) {
                alert('Developer tools terdeteksi. Akses dibatasi.');
                window.location.reload();
            }

            // Check if print dialog is attempted
            if (document.visibilityState === 'hidden') {
                setTimeout(function(){
                    if (document.visibilityState === 'hidden') {
                        alert('Akses dokumen dibatasi saat tidak aktif.');
                    }
                }, 2000);
            }
        }, 500);

        // Prevent focus tricks
        window.addEventListener('blur', function(){
            setTimeout(function(){
                if (document.activeElement === iframe) {
                    document.body.focus();
                }
            }, 10);
        }, true);

        // Add visual indicator that security is active
        const securityNotice = document.createElement('div');
        securityNotice.innerHTML = '🔒 Dokumen dilindungi - Simpan & Cetak dinonaktifkan';
        securityNotice.style.position = 'fixed';
        securityNotice.style.top = '10px';
        securityNotice.style.right = '10px';
        securityNotice.style.background = 'rgba(255, 0, 0, 0.8)';
        securityNotice.style.color = 'white';
        securityNotice.style.padding = '5px 10px';
        securityNotice.style.borderRadius = '4px';
        securityNotice.style.fontSize = '12px';
        securityNotice.style.zIndex = '9999';
        securityNotice.style.pointerEvents = 'none';
        document.body.appendChild(securityNotice);

        // Hide security notice after 3 seconds
        setTimeout(function(){
            securityNotice.style.display = 'none';
        }, 3000);

    })();
    </script>
</body>
</html>
