<?php
session_start();
require 'db_config.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['kode'])) {
    header("Location: dashboard.php");
    exit;
}

$kode = $_GET['kode'];
$uid = $_SESSION['user_id'];

try {
    // Pastikan tiket ini memang milik user yang login
    $stmt = $conn->prepare("
        SELECT r.kode_booking, s.slot_nomor 
        FROM reservasi r 
        JOIN slot s ON r.slot_id = s.id 
        WHERE r.kode_booking = ? AND r.user_id = ?
    ");
    $stmt->execute([$kode, $uid]);
    $tiket = $stmt->fetch();

    if (!$tiket) {
        die("Tiket tidak ditemukan atau Anda tidak memiliki akses.");
    }

    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . $tiket['kode_booking'];

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lihat Tiket - <?= $tiket['kode_booking'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #1a1a1a; color: white; }
        .ticket-view {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .qr-wrapper {
            background: white;
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 0 30px rgba(26, 188, 156, 0.4);
        }
        .btn-back {
            position: absolute;
            top: 20px;
            left: 20px;
            color: white;
            text-decoration: none;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>

<a href="reservasi_saya.php" class="btn-back"><i class="fas fa-times"></i> Tutup</a>

<div class="ticket-view text-center">
    <div class="mb-4">
        <h2 class="fw-bold mb-0">TIKET MASUK</h2>
        <p class="text-info">Slot: <?= $tiket['slot_nomor'] ?></p>
    </div>

    <div class="qr-wrapper animate__animated animate__zoomIn">
        <img src="<?= $qr_url ?>" alt="QR Code" class="img-fluid" style="width: 280px;">
    </div>

    <div class="mt-4">
        <h4 class="fw-bold tracking-widest"><?= $tiket['kode_booking'] ?></h4>
        <p class="small text-muted">Arahkan QR Code ke arah kamera/scanner gate</p>
    </div>

    <div class="mt-3">
        <button onclick="downloadQR('<?= $qr_url ?>', '<?= $tiket['kode_booking'] ?>')" class="btn btn-outline-info rounded-pill px-4">
            <i class="fas fa-download me-2"></i> Simpan Gambar
        </button>
    </div>
</div>

<script>
async function downloadQR(url, kode) {
    const response = await fetch(url);
    const blob = await response.blob();
    const blobUrl = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = blobUrl;
    a.download = `Tiket_${kode}.png`;
    a.click();
}
</script>

</body>
</html>