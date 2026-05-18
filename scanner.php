<?php
session_start();
require 'db_config.php';

// Pastikan hanya admin yang bisa membuka simulator ini
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Akses Ditolak! Hanya untuk Admin/Petugas Gerbang.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Simulator Gerbang - Smart Parking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="admin_dashboard.php"><i class="fas fa-arrow-left me-2"></i> Kembali ke Dashboard</a>
        <span class="navbar-text text-white fw-bold"><i class="fas fa-barcode me-2"></i> Simulator Barcode</span>
    </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card card-custom p-5 text-center">
                <i class="fas fa-qrcode fa-4x text-primary mb-3"></i>
                <h4 class="fw-bold mb-1">Simulasi Gerbang Parkir</h4>
                <p class="text-muted small mb-4">Masukkan kode tiket pelanggan (Misal: PKR-A1B2C3)</p>

                <input type="text" id="inputKode" class="form-control text-center fw-bold fs-5 mb-4 text-uppercase" placeholder="KODE BOOKING" autofocus>

                <div class="row g-2">
                    <div class="col-6">
                        <button onclick="prosesScan('check_in')" class="btn btn-success w-100 py-3 rounded-4 fw-bold shadow-sm">
                            <i class="fas fa-sign-in-alt mb-1 fs-4 d-block"></i> SCAN MASUK
                        </button>
                    </div>
                    <div class="col-6">
                        <button onclick="prosesScan('check_out')" class="btn btn-danger w-100 py-3 rounded-4 fw-bold shadow-sm">
                            <i class="fas fa-sign-out-alt mb-1 fs-4 d-block"></i> SCAN KELUAR
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mt-4 rounded-4 small border-0 shadow-sm">
                <i class="fas fa-info-circle me-1"></i> <b>Aturan Sistem:</b><br>
                1. <b>Masuk:</b> Mengubah slot dari Reserved (Kuning) menjadi Terisi (Merah).<br>
                2. <b>Keluar:</b> Memotong saldo user Rp 3.000 dan mengosongkan slot (Hijau).
            </div>
        </div>
    </div>
</div>

<script>
async function prosesScan(action) {
    const kode = document.getElementById('inputKode').value.trim().toUpperCase();
    if (!kode) {
        Swal.fire('Peringatan', 'Masukkan kode tiket terlebih dahulu!', 'warning');
        return;
    }

    Swal.fire({ title: 'Memproses Barcode...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });

    let fd = new FormData();
    fd.append('kode_booking', kode);

    try {
        let res = await fetch(`api.php?action=${action}`, { method: 'POST', body: fd });
        let data = await res.json();
        
        if (data.status === 'success') {
            Swal.fire({
                icon: 'success',
                title: action === 'check_in' ? 'Pintu Masuk Terbuka!' : 'Pintu Keluar Terbuka!',
                text: data.message,
                timer: 3000,
                showConfirmButton: false
            });
            document.getElementById('inputKode').value = ''; // Kosongkan input
        } else {
            Swal.fire('Ditolak!', data.message, 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Gagal menghubungi server.', 'error');
    }
}
</script>
</body>
</html>