<?php
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

// Jika sudah login, lempar ke dashboard yang sesuai
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && strtolower($_SESSION['role']) == 'admin') { 
        header("Location: admin_dashboard.php"); 
    } else { 
        header("Location: dashboard.php"); 
    }
    exit;
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email      = strtolower(trim($_POST['email']));
    $no_hp      = trim($_POST['no_hp']);
    $nama       = trim($_POST['nama']);
    $alamat     = trim($_POST['alamat']);
    $plat_nomor = strtoupper(trim($_POST['plat_nomor']));
    $password   = $_POST['password']; 

    try {
        $cek = $conn->prepare("SELECT id, email, plat_nomor FROM profiles WHERE email = ? OR plat_nomor = ?");
        $cek->execute([$email, $plat_nomor]);
        $duplikat = $cek->fetch();
        
        if ($duplikat) {
            if ($duplikat['email'] === $email) {
                $error_msg = "Pendaftaran Gagal: Email sudah terdaftar!";
            } else {
                $error_msg = "Pendaftaran Gagal: Plat Nomor sudah terdaftar!";
            }
        } else {
            $conn->beginTransaction();

            $qr_permanen = "STIKER-" . $plat_nomor;

            // PERBAIKAN: Saldo default diubah menjadi 0 (sebelumnya 50000)
            $ins_user = $conn->prepare("INSERT INTO profiles (email, no_hp, nama, alamat, plat_nomor, password, role, saldo, qr_token_permanen) VALUES (?, ?, ?, ?, ?, ?, 'user', 0, ?) RETURNING id");
            $ins_user->execute([$email, $no_hp, $nama, $alamat, $plat_nomor, $password, $qr_permanen]);
            
            // Catatan: Baris insert ke tabel 'transaksi' untuk bonus telah dihapus total.

            $conn->commit();
            
            // PERBAIKAN: Pesan sukses diubah, tidak lagi menyebutkan bonus
            $_SESSION['success_msg'] = "Akun berhasil dibuat! Silakan masuk menggunakan Email atau No. HP Anda.";
            header("Location: login.php");
            exit;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $error_msg = "Gagal memproses data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - Smart Parking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-bg d-flex align-items-center py-5" style="min-height: 100vh;">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 d-flex justify-content-center">
            
            <div class="auth-card" style="max-width: 600px; width: 100%;">
                <div class="text-center mb-4">
                    <img src="Logo.png" alt="Logo" style="height: 80px; width: auto;" class="mb-3">
                    <h4 class="fw-bold" style="color: var(--primary-color);">Daftar Akun Baru</h4>
                    <p class="text-muted small">Lengkapi data Anda untuk menggunakan Smart Parking.</p>
                </div>

                <form method="POST" action="register.php">
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-envelope text-muted"></i></span>
                                <input type="email" name="email" class="form-control border-start-0 ps-0" placeholder="contoh@email.com" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Nama Lengkap <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-user text-muted"></i></span>
                                <input type="text" name="nama" class="form-control border-start-0 ps-0" placeholder="Nama sesuai identitas" value="<?= isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : '' ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">No. HP <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-phone text-muted"></i></span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" name="no_hp" class="form-control border-start-0 ps-0" placeholder="0812..." value="<?= isset($_POST['no_hp']) ? htmlspecialchars($_POST['no_hp']) : '' ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">Plat Nomor <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-car text-muted"></i></span>
                                <input type="text" name="plat_nomor" class="form-control border-start-0 ps-0 text-uppercase" placeholder="B 1234 ABC" value="<?= isset($_POST['plat_nomor']) ? htmlspecialchars($_POST['plat_nomor']) : '' ?>" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Alamat Domisili</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-map-marker-alt text-muted"></i></span>
                                <textarea name="alamat" class="form-control border-start-0 ps-0" rows="2" placeholder="Alamat lengkap (opsional)"><?= isset($_POST['alamat']) ? htmlspecialchars($_POST['alamat']) : '' ?></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Kata Sandi <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-lock text-muted"></i></span>
                                <input type="password" name="password" id="regPassword" class="form-control border-start-0 border-end-0 ps-0" placeholder="Buat kata sandi rahasia" value="<?= isset($_POST['password']) ? htmlspecialchars($_POST['password']) : '' ?>" required>
                                <span class="input-group-text bg-white" style="cursor: pointer;" onclick="togglePassword('regPassword', 'eyeReg')">
                                    <i class="fas fa-eye text-muted" id="eyeReg"></i>
                                </span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold mb-3 mt-3 shadow-sm py-2">
                        DAFTAR SEKARANG
                    </button>
                </form>

                <div class="text-center mt-2">
                    <p class="small text-muted mb-0">Sudah punya akun? <a href="login.php" class="fw-bold text-decoration-none" style="color: var(--accent-color);">Masuk di sini</a></p>
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

    <?php if($error_msg): ?>
        Swal.fire({ 
            icon: 'error', 
            title: 'Oops...', 
            text: '<?= addslashes($error_msg) ?>', 
            confirmButtonColor: '#e74c3c' 
        });
    <?php endif; ?>
</script>
</body>
</html>