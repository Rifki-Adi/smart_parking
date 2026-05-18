<?php
session_start();
require 'db_config.php';

// Proteksi halaman
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$uid = $_SESSION['user_id'];

// Ambil data QR Token dari database
$stmt = $conn->prepare("SELECT nama, qr_token_permanen, plat_nomor FROM profiles WHERE id = ?");
$stmt->execute([$uid]);
$u = $stmt->fetch();

$qr_content = $u['qr_token_permanen'];
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . $qr_content;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>QR Saya - Smart Parking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">⬅️ Kembali ke Dashboard</a>
    </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 p-4 text-center">
                <h4 class="mb-1"><?= htmlspecialchars($u['nama']) ?></h4>
                <p class="text-muted small mb-4">ID Kendaraan: <?= $u['plat_nomor'] ?></p>
                
                <div class="qr-container p-4 border rounded bg-white mb-4 shadow-inner">
                    <img src="<?= $qr_url ?>" id="qrImage" class="img-fluid" alt="QR Saya">
                    <hr>
                    <h5 class="mb-0 text-primary"><?= $qr_content ?></h5>
                    <p class="small text-muted mt-2">Tempelkan stiker ini pada kaca depan mobil Anda untuk akses MLFF otomatis.</p>
                </div>

                <div class="d-grid gap-2">
                    <button onclick="downloadQR()" class="btn btn-success py-2">
                        📥 Download QR Stiker
                    </button>
                    <p class="small text-muted mt-2">Format: PNG (300x300 px)</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Fungsi untuk download gambar QR
async function downloadQR() {
    const imageUrl = document.getElementById('qrImage').src;
    const plat = "<?= $u['plat_nomor'] ?>";
    
    try {
        const response = await fetch(imageUrl);
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `QR_Parkir_${plat}.png`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    } catch (error) {
        alert("Gagal mendownload gambar. Coba klik kanan pada gambar dan pilih 'Save Image'.");
    }
}
</script>

<style>
    .qr-container {
        background: #ffffff;
        border: 2px dashed #1abc9c !important;
    }
    .shadow-inner {
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
    }
</style>

</body>
</html>