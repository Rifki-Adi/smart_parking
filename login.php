<?php
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

// Redirect aman untuk XAMPP dan Azure.
function redirectPage($file) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($base === '/' || $base === '\\' || $base === '.') {
        $base = '';
    }
    header('Location: ' . $base . '/' . $file);
    exit;
}

function redirectByRole($role) {
    if (strtolower((string)$role) === 'admin') {
        redirectPage('admin_dashboard.php');
    }
    redirectPage('dashboard.php');
}


// Jika sudah login, arahkan ke dashboard
if (isset($_SESSION['user_id'])) {
    redirectByRole($_SESSION['role'] ?? 'user');
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_id = strtolower(trim($_POST['login_id']));
    $password = $_POST['password'];

    try {
        $stmt = $conn->prepare("SELECT * FROM profiles WHERE email = ? OR no_hp = ?");
        $stmt->execute([$login_id, $login_id]);
        $user = $stmt->fetch();

        if ($user && $password === $user['password']) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = strtolower($user['role']);
            
            redirectByRole($user['role']);
        } else {
            $stmt_fallback = $conn->prepare("SELECT * FROM profiles WHERE (nama = ? OR plat_nomor = ?) AND password = ?");
            $stmt_fallback->execute([$login_id, $login_id, $password]);
            $user_fallback = $stmt_fallback->fetch();
            
            if ($user_fallback) {
                $_SESSION['user_id'] = $user_fallback['id'];
                $_SESSION['role']    = strtolower($user_fallback['role']);
                redirectByRole($user_fallback['role']);
            }

            $error_msg = "Akun tidak ditemukan atau Kata Sandi salah!";
        }
    } catch (Exception $e) {
        $error_msg = "Error DB: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Smart Parking</title>
    <link rel="icon" href="logo 1.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
</head>

<body class="auth-bg d-flex align-items-center" style="min-height: 100vh;">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 d-flex justify-content-center">
            
            <div class="auth-card text-center">
                <div class="mb-4">
                    <img src="Logo.png" alt="Logo" style="height: 80px; width: auto;" class="mb-3">
                    <h4 class="fw-bold" style="color: var(--primary-color);">Selamat Datang</h4>
                    <p class="text-muted small">Silakan masuk ke akun Anda</p>
                </div>

                <form method="POST" action="login.php" class="text-start">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Email / No. HP</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-user text-muted"></i></span>
                            <input type="text" name="login_id" class="form-control border-start-0 ps-0" placeholder="Masukkan Email atau No. HP" value="<?= isset($_POST['login_id']) ? htmlspecialchars($_POST['login_id']) : '' ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="small fw-bold text-muted mb-1">Kata Sandi</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" name="password" id="loginPassword" class="form-control border-start-0 border-end-0 ps-0" placeholder="Masukkan kata sandi" value="<?= isset($_POST['password']) ? htmlspecialchars($_POST['password']) : '' ?>" required>
                            <span class="input-group-text bg-white" style="cursor: pointer;" onclick="togglePassword('loginPassword', 'eyeLogin')">
                                <i class="fas fa-eye text-muted" id="eyeLogin"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold mb-3 shadow-sm py-2">
                        MASUK
                    </button>
                </form>

                <div class="text-center mt-2">
                    <p class="small text-muted mb-0">Belum punya akun? <a href="register.php" class="fw-bold text-decoration-none" style="color: var(--accent-color);">Daftar sekarang</a></p>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script untuk memunculkan/menyembunyikan password
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }

    <?php if(isset($_SESSION['success_msg'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: '<?= $_SESSION['success_msg'] ?>',
            confirmButtonColor: '#559da0'
        });
        <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>

    <?php if($error_msg): ?>
        Swal.fire({ icon: 'error', title: 'Akses Ditolak', text: '<?= $error_msg ?>', confirmButtonColor: '#e74c3c' });
    <?php endif; ?>
</script>
</body>
</html>
