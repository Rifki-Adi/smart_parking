<?php
session_start();
require 'db_config.php';
require_once 'mqtt_config.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit; 
}
$uid = $_SESSION['user_id'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Saya - Smart Parking</title>
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
            <img src="Logo.png" alt="Smart Parking Logo" style="height: 80px; width: auto;">
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><a class="nav-link fw-bold" href="dashboard.php">Beranda</a></li>
                <li class="nav-item"><a class="nav-link active fw-bold" style="color: var(--primary-color);" href="reservasi_saya.php">QR Saya</a></li>
                <li class="nav-item"><a class="nav-link text-danger fw-bold" href="logout.php">Keluar</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-3">
    <h4 class="fw-bold mb-4" style="color: var(--primary-color);"><i class="fas fa-calendar-check me-2" style="color: var(--accent-color);"></i> Reservasi Aktif Anda</h4>
    
    <div class="row g-4" id="ticket-container">
        <div class="col-12 text-center py-5">
            <i class="fas fa-circle-notch fa-spin fa-3x text-muted mb-3"></i>
            <h5 class="fw-bold text-muted">Memeriksa Tiket Aktif...</h5>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/paho-mqtt@1.1.0/paho-mqtt-min.js"></script>
<script src="mqtt_browser_config.php"></script>
<script src="js/mqtt_realtime.js"></script>

<script>
    const USER_ID = "<?= $uid ?>";

    async function fetchMyTickets() {
        try {
            let res = await fetch(`api.php?action=get_user_live_data&uid=${USER_ID}&_=${Date.now()}`);
            let data = await res.json();
            
            let container = document.getElementById('ticket-container');
            
            if (data.tiket.length === 0) {
                container.innerHTML = `<div class="col-12 text-center py-5">
                    <i class="fas fa-box-open fa-4x text-muted mb-3 opacity-50"></i>
                    <h5 class="fw-bold text-muted">Belum ada reservasi aktif</h5>
                    <p class="text-muted">Silakan lakukan reservasi slot parkir di halaman utama.</p>
                    <a href="dashboard.php" class="btn btn-primary rounded-pill px-4 mt-2 fw-bold">Reservasi Sekarang</a>
                </div>`;
                return;
            }

            let html = '';
            data.tiket.forEach(t => {
                let badgeStatus = t.status === 'pending' 
                    ? `<div class="position-absolute top-0 end-0 bg-warning text-dark px-3 py-1 fw-bold border-bottom border-start" style="border-radius: 0 16px 0 16px; font-size: 0.8rem;">MENUNGGU MASUK</div>`
                    : `<div class="position-absolute top-0 end-0 bg-danger text-white px-3 py-1 fw-bold border-bottom border-start" style="border-radius: 0 16px 0 16px; font-size: 0.8rem;">SEDANG PARKIR</div>`;

                let timerHtml = '';
                if (t.status === 'pending' && data.time_left > 0) {
                    let m = Math.floor(data.time_left / 60);
                    let s = data.time_left % 60;
                    let textWaktu = "0" + m + ":" + (s < 10 ? "0"+s : s);
                    timerHtml = `<div class="mb-3"><span class="badge bg-danger rounded-pill px-3 py-2 fw-normal shadow-sm"><i class="fas fa-clock me-1"></i> Hangus dalam: <span class="fw-bold">${textWaktu}</span></span></div>`;
                } else if (t.status === 'pending' && data.time_left <= 0) {
                    timerHtml = `<div class="mb-3"><span class="badge bg-secondary rounded-pill px-3 py-2 fw-normal shadow-sm"><i class="fas fa-clock me-1"></i> Hangus / Batal</span></div>`;
                }

                let btnBatal = t.status === 'check-in' ? 'disabled' : '';

                html += `<div class="col-md-6 col-lg-4">
                    <div class="card card-custom p-4 border-0 text-center position-relative overflow-hidden shadow-sm">
                        ${badgeStatus}
                        <h5 class="fw-bold mt-3 mb-1">SLOT ${t.slot_nomor}</h5>
                        <p class="text-muted small mb-2">${t.tgl_format}</p>
                        ${timerHtml}
                        <h4 class="fw-bold mb-4" style="color: var(--primary-color);">${t.kode_booking}</h4>
                        <div class="row g-2">
                            <div class="col-6">
                                <button type="button" onclick="lihatQR('${t.kode_booking}')" class="btn btn-outline-primary w-100 rounded-pill fw-bold">
                                    <i class="fas fa-qrcode me-1"></i> Lihat QR
                                </button>
                            </div>
                            <div class="col-6">
                                <button type="button" onclick="cancelBooking('${t.kode_booking}')" class="btn btn-danger w-100 rounded-pill fw-bold ${btnBatal}">
                                    <i class="fas fa-times me-1"></i> Batal
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        } catch(e) {}
    }
    
    let localTicketTimer = null;
    let ticketTimeLeft = 0;

    function startTicketRealtime() {
        fetchMyTickets();

        window.smartParkingRealtimeRefresh = function(reason = '') {
            fetchMyTickets();
        };

        if (typeof window.smartParkingStartMqttRealtime === 'function') {
            window.smartParkingStartMqttRealtime();
        }
    }

    startTicketRealtime();

    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            if (typeof window.smartParkingStopMqttRealtime === 'function') {
                window.smartParkingStopMqttRealtime();
            }
        } else {
            startTicketRealtime();
        }
    });

    async function cancelBooking(kode) {
        const result = await Swal.fire({
            title: 'Batalkan Reservasi?', html: `Menghapus reservasi <b>${kode}</b>.<br><small class="text-danger">Saldo Rp 5.000 tidak akan dikembalikan.</small>`,
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#e74c3c', cancelButtonColor: '#95a5a6', confirmButtonText: 'Ya, Batalkan', reverseButtons: true
        });

        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Menghapus...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });

        let fd = new FormData();
        fd.append('kode_booking', kode);
        fd.append('user_id', USER_ID);

        try {
            let res = await fetch('api.php?action=cancel_booking', { method: 'POST', body: fd });
            let data = await res.json();
            if (data.status === 'success') {
                // MENGEMBALIKAN TOMBOL OK
                await Swal.fire({ icon: 'success', title: 'Berhasil!', text: data.message, confirmButtonColor: '#559da0' });
                fetchMyTickets(); 
            } else { Swal.fire('Gagal!', data.message, 'error'); }
        } catch (e) { Swal.fire('Error!', 'Gagal menghubungi server.', 'error'); }
    }

    function lihatQR(kode) {
        const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${kode}`;
        Swal.fire({ 
            title: 'QR Tiket Reservasi', 
            html: `
                <div class="mb-3 text-center"><img src="${url}" width="200" height="200" class="rounded shadow-sm border p-2"></div>
                <p class="text-muted small mb-1">Kode Tiket (Bisa di-copy):</p>
                <div class="input-group justify-content-center px-4">
                    <input type="text" id="qr-res-copy-text" class="form-control text-center fw-bold bg-light" value="${kode}" readonly>
                    <button class="btn btn-outline-secondary" onclick="copyToClipboard('qr-res-copy-text')" type="button"><i class="fas fa-copy"></i></button>
                </div>
            `,
            showCancelButton: true, confirmButtonColor: '#559da0', cancelButtonColor: '#1a365d', 
            confirmButtonText: '<i class="fas fa-download"></i> Unduh QR', cancelButtonText: 'Tutup'
        }).then((result) => { if (result.isConfirmed) downloadQR(kode, 'Reservasi'); });
    }

    function copyToClipboard(elementId) {
        var copyText = document.getElementById(elementId);
        copyText.select();
        copyText.setSelectionRange(0, 99999); 
        navigator.clipboard.writeText(copyText.value).then(() => {
            // MENGEMBALIKAN TOMBOL OK
            Swal.fire({ title: 'Tersalin!', text: copyText.value, icon: 'success', confirmButtonColor: '#559da0' });
        });
    }

    async function downloadQR(kode, tipe) {
        const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${kode}`;
        try {
            const resp = await fetch(url);
            const blob = await resp.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `QR_${tipe}_${kode}.png`;
            a.click();
        } catch (e) {}
    }
</script>
</body>
</html>
