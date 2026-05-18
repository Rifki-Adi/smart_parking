<?php
session_start();
require 'db_config.php';

// Pastikan hanya admin yang bisa akses
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$uid_admin = $_SESSION['user_id'];
$admin_name = $conn->query("SELECT nama FROM profiles WHERE id = '$uid_admin'")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi QR - Admin Smart Parking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4 py-2">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
            <img src="Logo.png" alt="Smart Parking Logo" style="height: 80px; width: auto;" class="me-3">
            <span class="badge bg-danger fs-6 shadow-sm"><i class="fas fa-user-shield me-1"></i> ADMIN: <?= htmlspecialchars($admin_name) ?></span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><a class="nav-link fw-bold" href="admin_dashboard.php"><i class="fas fa-desktop me-1"></i> Monitor</a></li>
                <li class="nav-item"><a class="nav-link fw-bold" href="admin_users.php"><i class="fas fa-users me-1"></i> Pengguna</a></li>
                <li class="nav-item"><a class="nav-link active fw-bold" style="color: var(--primary-color);" href="admin_scan.php"><i class="fas fa-qrcode me-1"></i> Verifikasi QR</a></li>
                <li class="nav-item"><a class="nav-link" href="export_excel.php"><i class="fas fa-file-excel text-success me-1"></i> Excel</a></li>
                <li class="nav-item ms-3"><a class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" href="logout.php">Keluar</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card card-custom p-5 border-0 shadow-lg text-center" style="background: linear-gradient(135deg, #1a365d 0%, #2b5c9e 100%); border-radius: 20px;">
                <i class="fas fa-camera fa-5x text-warning mb-4"></i>
                <h3 class="fw-bold text-white mb-2">Scanner Gerbang Parkir</h3>
                <p class="text-white-50 mb-4">Verifikasi otomatis kendaraan <b>Masuk (Check-In)</b> dan <b>Keluar (Check-Out)</b>.</p>
                
                <div class="form-group mb-4 text-start">
                    <label class="text-warning fw-bold small mb-2">KODE TIKET / STIKER</label>
                    <input type="text" id="sim_qr" class="form-control form-control-lg text-center text-uppercase fw-bold shadow-sm" placeholder="Contoh: PK-XXXX atau STIKER-PLAT" style="letter-spacing: 2px;">
                </div>
                
                <button class="btn btn-warning btn-lg fw-bold w-100 rounded-pill shadow-sm" onclick="scanQR()">
                    <i class="fas fa-search me-2"></i> SCAN & BUKA GERBANG
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Memfokuskan kursor ke kotak input secara otomatis
    document.getElementById('sim_qr').focus();

    async function scanQR() {
        let qr = document.getElementById('sim_qr').value.trim();
        if (!qr) { Swal.fire('Oops!', 'Kolom kode QR masih kosong!', 'warning'); return; }
        
        Swal.fire({ title: 'Memproses ke Server...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        let fd = new FormData();
        fd.append('qr_code', qr);
        
        try {
            let res = await fetch(`api.php?action=gate_scan&_=${Date.now()}`, { method: 'POST', body: fd });
            let text = await res.text(); // Ambil respons mentah untuk jaga-jaga jika ada error database
            
            try {
                let data = JSON.parse(text);
                
                if(data.status === 'success') {
                    Swal.fire({icon: 'success', title: 'Gerbang Terbuka!', text: data.message, confirmButtonColor: '#559da0'});
                    document.getElementById('sim_qr').value = ''; 
                    document.getElementById('sim_qr').focus();
                } else {
                    Swal.fire({icon: 'error', title: 'Akses Ditolak', text: data.message, confirmButtonColor: '#e74c3c'});
                    document.getElementById('sim_qr').focus();
                }
            } catch (err) {
                Swal.fire({icon: 'error', title: 'Sistem Error!', text: text});
            }
        } catch (e) {
            Swal.fire('Error', 'Gagal memproses data.', 'error');
        }
    }

    // Bisa langsung menekan tombol Enter di keyboard untuk Scan
    document.getElementById("sim_qr").addEventListener("keypress", function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            scanQR();
        }
    });
</script>
</body>
</html>