<?php
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

// Auto release reservasi: 5 menit = 300 detik
const AUTO_RELEASE_SECONDS = 300;

if (!isset($_SESSION['user_id']) || !isset($_GET['kode'])) {
    header("Location: dashboard.php");
    exit;
}

$kode = $_GET['kode'];
$uid = $_SESSION['user_id'];

try {
    // Pastikan tiket ini memang milik user yang login
    $stmt = $conn->prepare("
        SELECT r.kode_booking, r.status, r.created_at, s.slot_nomor 
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

    $time_left = 0;
    if (($tiket['status'] ?? '') === 'pending') {
        $created_at = substr($tiket['created_at'], 0, 19);
        $elapsed = time() - strtotime($created_at);
        $time_left = AUTO_RELEASE_SECONDS - $elapsed;
        if ($time_left < 0) $time_left = 0;
    }

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
    <link rel="icon" href="logo 1.png" type="image/png">
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
        .ticket-countdown {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #e74c3c, #ff7675);
            color: #fff;
            padding: 10px 18px;
            border-radius: 999px;
            font-weight: 700;
            box-shadow: 0 0 20px rgba(231, 76, 60, 0.45);
        }
        .ticket-countdown.expired {
            background: #6c757d;
            box-shadow: none;
        }
    </style>
</head>
<body>

<a href="reservasi_saya.php" class="btn-back"><i class="fas fa-times"></i> Tutup</a>

<div class="ticket-view text-center">
    <div class="mb-4">
        <h2 class="fw-bold mb-0">TIKET MASUK</h2>
        <p class="text-info">Slot: <?= $tiket['slot_nomor'] ?></p>
        <?php if (($tiket['status'] ?? '') === 'pending'): ?>
            <div id="ticket-countdown-wrap" class="ticket-countdown <?= $time_left <= 0 ? 'expired' : '' ?>">
                <i class="fas fa-clock"></i>
                <span><?= $time_left > 0 ? 'Hangus dalam:' : 'Hangus / Batal' ?></span>
                <?php if ($time_left > 0): ?>
                    <span id="ticket-timer-text">00:00</span>
                <?php endif; ?>
            </div>
            <p class="small text-muted mt-2 mb-0">Batas check-in maksimal 5 menit setelah reservasi.</p>
        <?php endif; ?>
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
let ticketTimeLeft = <?= (int)$time_left ?>;
function formatTicketCountdown(seconds) {
    seconds = Math.max(0, parseInt(seconds || 0));
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
function updateTicketCountdown() {
    const text = document.getElementById('ticket-timer-text');
    const wrap = document.getElementById('ticket-countdown-wrap');
    if (!wrap) return;
    if (ticketTimeLeft > 0) {
        if (text) text.innerText = formatTicketCountdown(ticketTimeLeft);
    } else {
        wrap.classList.add('expired');
        wrap.innerHTML = '<i class="fas fa-clock"></i><span>Hangus / Batal</span>';
    }
}
updateTicketCountdown();
setInterval(() => {
    if (ticketTimeLeft > 0) {
        ticketTimeLeft--;
        updateTicketCountdown();
    }
}, 1000);

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
