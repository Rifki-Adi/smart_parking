<?php
session_start();
require 'db_config.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit; 
}

$uid = $_SESSION['user_id'];
$status_topup = '';
$pesan_topup = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Menangkap angka murni yang dikirim oleh input tersembunyi
    $nominal = (int)$_POST['nominal'];
    
    // VALIDASI: Minimal 10rb, Maksimal 5 Juta
    if ($nominal < 10000) {
        $status_topup = 'warning';
        $pesan_topup = 'Minimal Top Up adalah Rp 10.000.';
    } elseif ($nominal > 5000000) {
        $status_topup = 'error';
        $pesan_topup = 'Maksimal Top Up adalah Rp 5.000.000.';
    } else {
        try {
            $conn->beginTransaction();
            // Tambah Saldo
            $conn->prepare("UPDATE profiles SET saldo = saldo + ? WHERE id = ?")->execute([$nominal, $uid]);
            // Catat Transaksi
            $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'topup', ?, 'Top Up Saldo')")->execute([$uid, $nominal]);
            $conn->commit();
            
            $status_topup = 'success';
            $pesan_topup = 'Top Up Rp ' . number_format($nominal, 0, ',', '.') . ' berhasil!';
        } catch (Exception $e) {
            $conn->rollBack();
            $status_topup = 'error';
            $pesan_topup = 'Gagal memproses transaksi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Up - Smart Parking</title>
    <link rel="icon" href="logo 1.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top mb-4 py-2">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="logo 1.png" alt="Smart Parking Logo" style="height: 80px; width: auto;">
        </a>
        <div class="ms-auto">
            <a class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold" href="dashboard.php">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>
</nav>

<div class="container mt-3 pb-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card card-custom p-4 p-md-5 border-0 text-center shadow-sm">
                <div class="mb-4">
                    <div class="bg-light d-inline-block p-4 rounded-circle mb-3">
                        <i class="fas fa-wallet fa-3x" style="color: var(--accent-color);"></i>
                    </div>
                    <h4 class="fw-bold" style="color: var(--primary-color);">Top Up Saldo</h4>
                    <p class="text-muted small">Maksimal pengisian Rp 5.000.000</p>
                </div>

                <form method="POST" id="formTopup">
                    <div class="row g-2 mb-4">
                        <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 fw-bold small py-2" onclick="setNominal(50000)">Rp 50.000</button></div>
                        <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 fw-bold small py-2" onclick="setNominal(100000)">Rp 100.000</button></div>
                        <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 fw-bold small py-2" onclick="setNominal(200000)">Rp 200.000</button></div>
                        <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 fw-bold small py-2" onclick="setNominal(500000)">Rp 500.000</button></div>
                        <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 fw-bold small py-2" onclick="setNominal(1000000)">Rp 1.000.000</button></div>
                        <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 fw-bold small py-2" onclick="setNominal(5000000)">Rp 5.000.000</button></div>
                    </div>

                    <div class="mb-4 text-start">
                        <label class="small fw-bold text-muted ms-1 mb-2">Input Nominal Manual</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white fw-bold border-end-0" style="border-radius: 12px 0 0 12px;">Rp</span>
                            
                            <input type="hidden" id="nominal_asli" name="nominal" required>
                            
                            <input type="text" id="inputNominal" class="form-control border-start-0 fw-bold fs-5" 
                                   placeholder="0" required style="border-radius: 0 12px 12px 0;" 
                                   oninput="formatRupiahManual(this)" autocomplete="off">
                        </div>
                        <div class="form-text text-danger mt-1 ms-1 fw-bold">* Minimal top up Rp 10.000</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold fs-5 shadow-sm">
                        KONFIRMASI PEMBAYARAN
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // FUNGSI JAVASCRIPT MANDIRI KHUSUS HALAMAN TOP UP
    
    // 1. Fungsi saat user mengetik manual di kolom input
    function formatRupiahManual(inputElement) {
        let angka_asli = inputElement.value.replace(/[^0-9]/g, ''); // Buang huruf/titik
        
        // Simpan ke input tersembunyi
        document.getElementById('nominal_asli').value = angka_asli;

        // Pasang titik
        let sisa = angka_asli.length % 3;
        let rupiah = angka_asli.substr(0, sisa);
        let ribuan = angka_asli.substr(sisa).match(/\d{3}/g);

        if (ribuan) {
            let separator = sisa ? '.' : '';
            rupiah += separator + ribuan.join('.');
        }
        
        // Tampilkan ke layar
        inputElement.value = rupiah;
    }

    // 2. Fungsi saat user klik tombol pilihan cepat (50k, 100k, dll)
    function setNominal(angka) {
        document.getElementById('nominal_asli').value = angka; // Simpan angka asli
        
        // Format ke dalam tampilan titik
        let sisa = angka.toString().length % 3;
        let rupiah = angka.toString().substr(0, sisa);
        let ribuan = angka.toString().substr(sisa).match(/\d{3}/g);
        if (ribuan) rupiah += (sisa ? '.' : '') + ribuan.join('.');
        
        document.getElementById('inputNominal').value = rupiah; // Tampilkan
    }

    // 3. Validasi saat tombol "KONFIRMASI PEMBAYARAN" diklik
    document.getElementById('formTopup').addEventListener('submit', function(e) {
        let nominal = parseInt(document.getElementById('nominal_asli').value);
        if (!nominal || nominal < 10000) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Nominal Kurang!', text: 'Minimal top up adalah Rp 10.000', confirmButtonColor: '#1a365d' });
        } else if (nominal > 5000000) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Nominal Berlebihan!', text: 'Maksimal top up adalah Rp 5.000.000', confirmButtonColor: '#1a365d' });
        }
    });

    // 4. Animasi Pop Up Sukses / Gagal dari PHP
    <?php if($status_topup == 'success'): ?>
        Swal.fire({
            icon: 'success',
            title: 'Top Up Berhasil!',
            text: '<?= $pesan_topup ?>',
            timer: 3000,
            showConfirmButton: false,
            confirmButtonColor: '#1a365d'
        }).then(() => {
            window.location.href = 'dashboard.php';
        });
    <?php elseif($status_topup == 'error' || $status_topup == 'warning'): ?>
        Swal.fire({
            icon: '<?= $status_topup ?>',
            title: 'Gagal',
            text: '<?= $pesan_topup ?>',
            confirmButtonColor: '#1a365d'
        });
    <?php endif; ?>
</script>

</body>
</html>
