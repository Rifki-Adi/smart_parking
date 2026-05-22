<?php
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

// Mencegah error fatal jika session browser tersangkut sebagai ESP32
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === 'esp32_device') {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') { header("Location: admin_dashboard.php"); exit; }

$uid = $_SESSION['user_id'];
$u = $conn->query("SELECT * FROM profiles WHERE id = '$uid'")->fetch();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Smart Parking</title>
    <link rel="icon" href="logo 1.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .th-sortable { cursor: pointer; user-select: none; transition: color 0.2s; }
        .th-sortable:hover { color: var(--primary-color) !important; }
        .icon-sort { opacity: 0.3; margin-left: 5px; font-size: 0.9em; }
        .icon-sort.active { opacity: 1; color: var(--primary-color); }
    </style>
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
                <?php if (strtolower($_SESSION['role']) == 'admin'): ?>
                    <li class="nav-item"><a class="nav-link text-warning fw-bold" href="admin_dashboard.php"><i class="fas fa-shield-alt"></i> Panel Admin</a></li>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link active fw-bold" style="color: var(--primary-color);" href="dashboard.php">Beranda</a></li>
                <li class="nav-item"><a class="nav-link" href="reservasi_saya.php">QR Saya</a></li>
                <li class="nav-item"><a class="nav-link text-danger fw-bold" href="logout.php">Keluar</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-3">
    <div class="row g-4">
        <div class="col-lg-3">
            <div class="card card-custom p-4 text-center border-0">
                <i class="fas fa-user-circle fa-4x mb-3" style="color: var(--accent-color);"></i>
                <h5 class="fw-bold mb-1" style="color: var(--primary-color);"><?= htmlspecialchars($u['nama']) ?></h5>
                <span class="badge bg-secondary mb-2"><?= htmlspecialchars($u['plat_nomor']) ?></span>
                
                <div class="text-muted small mb-3 text-start bg-light p-2 rounded text-center">
                    <div class="mb-1"><i class="fas fa-envelope text-primary me-1"></i> <?= htmlspecialchars($u['email'] ?? 'Belum ada email') ?></div>
                    <div><i class="fas fa-phone text-success me-1"></i> <?= htmlspecialchars($u['no_hp'] ?? 'Belum ada No. HP') ?></div>
                </div>

                <div class="bg-light p-3 rounded-4">
                    <small class="text-muted fw-bold d-block">SALDO ANDA</small>
                    <h3 id="teks-saldo" class="text-success fw-bold mb-0">Rp <?= number_format($u['saldo'],0,',','.') ?></h3>
                </div>
                <div class="d-grid gap-2 mt-3">
                    <a href="topup.php" class="btn btn-primary rounded-pill"><i class="fas fa-wallet me-1"></i> Top Up Saldo</a>
                    <?php $token_qr = !empty($u['qr_token_permanen']) ? $u['qr_token_permanen'] : 'STIKER-'.$u['plat_nomor']; ?>
                    <button type="button" onclick="showQRPermanen('<?= $token_qr ?>')" class="btn btn-outline-secondary rounded-pill fw-bold">
                        <i class="fas fa-qrcode me-1"></i> Stiker QR Saya
                    </button>
                    <button type="button" onclick="bukaModalRiwayat()" class="btn btn-outline-info rounded-pill fw-bold">
                        <i class="fas fa-history me-1"></i> Riwayat Transaksi
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="card card-custom p-4 border-0">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold mb-0" style="color: var(--primary-color);">Status Slot Parkir</h4>
                    <span id="countdown-badge" class="badge bg-danger rounded-pill px-3 py-2 fw-normal" style="font-size: 0.9rem; display: none;">
                        <i class="fas fa-clock me-1"></i> Waktu Sisa: <span id="timer-text" class="fw-bold">00:00</span>
                    </span>
                </div>
                
                <div class="mb-4 small text-muted lh-lg">
                    <i class="fas fa-circle text-success me-1"></i> Kosong &nbsp;
                    <i class="fas fa-circle text-warning me-1"></i> Reserved &nbsp;
                    <i class="fas fa-circle text-danger me-1"></i> Terisi
                </div>
                
                <div class="row g-3 row-cols-2 row-cols-md-4" id="slot-area-container">
                    <div class="col-12 text-center py-3 text-muted"><i class="fas fa-circle-notch fa-spin me-2"></i>Memuat Area Parkir...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTrx" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0 rounded-top-4 align-items-center flex-wrap gap-2">
                <h5 class="modal-title fw-bold mb-0" style="color: var(--primary-color);"><i class="fas fa-list-alt me-2"></i>Riwayat Transaksi</h5>
                <div class="d-flex flex-wrap gap-2 ms-auto me-2">
                    <input type="date" id="filter_modal_tgl" class="form-control form-control-sm border-info text-info shadow-sm fw-bold w-auto" onchange="renderTrxTable()">
                    <select id="filter_modal_tipe" class="form-select form-select-sm shadow-sm fw-bold border-info text-info" onchange="renderTrxTable()" style="width:auto;">
                        <option value="all">Semua Tipe</option>
                        <option value="topup">Top Up</option>
                        <option value="reservasi">Reservasi (Booking)</option>
                        <option value="parkir">Masuk (Check-In)</option>
                        <option value="checkout">Keluar (Check-Out)</option>
                        <option value="batal">Batal Manual</option>
                        <option value="hangus">Hangus / Waktu Habis</option>
                    </select>
                    <button class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="resetFilterModal()"><i class="fas fa-times"></i></button>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive m-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="text-muted small ps-4 th-sortable" onclick="setSortTrx('created_at')">Hari & Tanggal <i id="icon-sort-modal-created_at" class="fas fa-sort-down icon-sort active"></i></th>
                                <th class="text-muted small th-sortable" onclick="setSortTrx('tipe')">Tipe Aksi <i id="icon-sort-modal-tipe" class="fas fa-sort icon-sort"></i></th>
                                <th class="text-muted small text-end th-sortable" onclick="setSortTrx('jumlah')">Jumlah <i id="icon-sort-modal-jumlah" class="fas fa-sort icon-sort"></i></th>
                                <th class="text-muted small pe-4 th-sortable" onclick="setSortTrx('keterangan')">Keterangan <i id="icon-sort-modal-keterangan" class="fas fa-sort icon-sort"></i></th>
                            </tr>
                        </thead>
                        <tbody id="trx_table_body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light rounded-bottom-4"><button type="button" class="btn btn-dark rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Tutup</button></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const USER_ID = "<?= $uid ?>";

    async function fetchUserLiveData() {
        try {
            let res = await fetch(`api.php?action=get_user_live_data&uid=${USER_ID}&_=${Date.now()}`);
            let data = await res.json();
            let elSaldo = document.getElementById('teks-saldo');
            if(elSaldo) elSaldo.innerText = 'Rp ' + data.saldo.toLocaleString('id-ID');
            let badge = document.getElementById('countdown-badge');
            let timerText = document.getElementById('timer-text');
            if (data.has_pending && data.time_left > 0) {
                badge.style.display = 'inline-block';
                let m = Math.floor(data.time_left / 60); let s = data.time_left % 60;
                timerText.innerText = "0" + m + ":" + (s < 10 ? "0"+s : s);
            } else { badge.style.display = 'none'; }
        } catch (e) {}
    }

    async function fetchLiveSlots() {
        try {
            const response = await fetch(`api.php?action=get_slots&uid=${USER_ID}&_=${Date.now()}`);
            const slots = await response.json();
            
            let htmlContainer = '';
            slots.forEach(slot => {
                let cls = 'slot-free';
                let btn_html = `<button type="button" onclick="bookingSlot('${slot.slot_nomor}')" class="btn btn-success btn-sm w-100 rounded-pill mt-2 fw-bold shadow-sm">RESERVASI</button>`;
                
                if (slot.state === 'terisi') {
                    cls = 'slot-occupied'; btn_html = `<div class="badge bg-danger w-100 py-2 mt-2 rounded-pill shadow-sm">TERISI</div>`;
                } 
                else if (slot.state === 'terisi_me') {
                    cls = 'slot-occupied border-danger'; 
                    btn_html = `<div class="badge bg-danger w-100 py-2 mt-2 rounded-pill border border-dark shadow-sm">MOBIL ANDA</div>`;
                } 
                else if (slot.state === 'reserved_me') {
                    cls = 'slot-reserved-me'; btn_html = `<div class="badge bg-warning text-dark w-100 py-2 mt-2 rounded-pill border border-dark shadow-sm">RESERVED (ANDA)</div>`;
                } else if (slot.state === 'reserved_other') {
                    cls = 'slot-reserved'; btn_html = `<div class="badge bg-secondary w-100 py-2 mt-2 rounded-pill shadow-sm">RESERVED</div>`;
                } 
                
                htmlContainer += `<div class="col"><div id="slot-box-${slot.slot_nomor}" class="slot-card p-3 border rounded-4 text-center ${cls}"><i class="fas fa-car fa-2x mb-2"></i><h6 class="fw-bold mb-0">Slot ${slot.slot_nomor}</h6><div id="slot-btn-${slot.slot_nomor}">${btn_html}</div></div></div>`;
            });
            document.getElementById('slot-area-container').innerHTML = htmlContainer;
        } catch (e) {}
    }

    fetchUserLiveData(); fetchLiveSlots();
    setInterval(fetchUserLiveData, 1000); setInterval(fetchLiveSlots, 2000); 

    async function bookingSlot(nomor) {
        const result = await Swal.fire({
            title: `Reservasi Slot ${nomor}?`,
            html: `Biaya reservasi <b>Rp 5.000</b>.<br><small class="text-danger fw-bold mt-2 d-block"><i class="fas fa-exclamation-circle me-1"></i> Reservasi hangus otomatis dlm 1 menit.</small>`,
            icon: 'question', showCancelButton: true, confirmButtonColor: '#1a365d', cancelButtonColor: '#e74c3c', confirmButtonText: 'Ya, Reservasi!', cancelButtonText: 'Batal', reverseButtons: true
        });
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });
        let fd = new FormData(); fd.append('user_id', USER_ID); fd.append('slot_nomor', nomor);
        try {
            let res = await fetch('api.php?action=book_slot', { method: 'POST', body: fd });
            let data = await res.json();
            if(data.status === 'success') {
                Swal.fire({title: 'Berhasil!', text: `Reservasi berhasil diamankan.`, icon: 'success', confirmButtonColor: '#559da0'}).then(() => { fetchUserLiveData(); fetchLiveSlots(); });
            } else { Swal.fire('Gagal!', data.message, 'error'); }
        } catch (e) { Swal.fire('Error!', 'Koneksi bermasalah.', 'error'); }
    }

    function showQRPermanen(token) {
        const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${token}`;
        Swal.fire({
            title: 'QR Stiker Kendaraan',
            html: `<div class="mb-3 text-center"><img src="${url}" width="200" height="200" class="rounded shadow-sm border p-2"></div><p class="text-muted small mb-1">Kode Verifikasi (Bisa di-copy):</p><div class="input-group justify-content-center px-4"><input type="text" id="qr-copy-text" class="form-control text-center fw-bold bg-light" value="${token}" readonly><button class="btn btn-outline-secondary" onclick="copyToClipboard('qr-copy-text')" type="button"><i class="fas fa-copy"></i></button></div>`,
            showCancelButton: true, confirmButtonColor: '#559da0', cancelButtonColor: '#1a365d', confirmButtonText: '<i class="fas fa-download"></i> Unduh', cancelButtonText: 'Tutup'
        }).then((result) => { if (result.isConfirmed) downloadQR(token, 'Permanen'); });
    }

    function copyToClipboard(elementId) {
        var copyText = document.getElementById(elementId); copyText.select(); copyText.setSelectionRange(0, 99999); 
        navigator.clipboard.writeText(copyText.value).then(() => { Swal.fire({title: 'Tersalin!', text: copyText.value, icon: 'success', confirmButtonColor: '#559da0'}); });
    }

    async function downloadQR(kode, tipe) {
        const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${kode}`;
        try { const resp = await fetch(url); const blob = await resp.blob(); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `QR_${tipe}_${kode}.png`; a.click(); } catch (e) {}
    }

    let globalTrxData = []; let liveTrxInterval = null; let sortModalCol = 'created_at'; let sortModalDir = 'desc';

    function bukaModalRiwayat() {
        document.getElementById('trx_table_body').innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><br>Memuat data riwayat live...</td></tr>';
        resetFilterModal(false); new bootstrap.Modal(document.getElementById('modalTrx')).show(); fetchAndRenderLive();
        if(liveTrxInterval) clearInterval(liveTrxInterval); liveTrxInterval = setInterval(fetchAndRenderLive, 2000);
    }
    document.getElementById('modalTrx').addEventListener('hidden.bs.modal', function () { clearInterval(liveTrxInterval); });
    async function fetchAndRenderLive() { try { const response = await fetch(`api.php?action=get_user_trx&user_id=${USER_ID}&_=${Date.now()}`); globalTrxData = await response.json(); renderTrxTable(); } catch (e) {} }
    function setSortTrx(colName) { if (sortModalCol === colName) { sortModalDir = sortModalDir === 'asc' ? 'desc' : 'asc'; } else { sortModalCol = colName; sortModalDir = 'asc'; } updateSortIconsTrx(); renderTrxTable(); }
    function updateSortIconsTrx() { const cols = ['created_at', 'tipe', 'jumlah', 'keterangan']; cols.forEach(c => { let icon = document.getElementById('icon-sort-modal-' + c); icon.className = 'icon-sort'; if (c === sortModalCol) { icon.classList.add('fas'); icon.classList.add(sortModalDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'); icon.classList.add('active'); } else { icon.classList.add('fas', 'fa-sort'); } }); }
    function resetFilterModal(doRender = true) { document.getElementById('filter_modal_tipe').value = 'all'; document.getElementById('filter_modal_tgl').value = ''; sortModalCol = 'created_at'; sortModalDir = 'desc'; updateSortIconsTrx(); if (doRender && globalTrxData.length > 0) renderTrxTable(); }
    function renderTrxTable() {
        let filterType = document.getElementById('filter_modal_tipe').value; let filterDate = document.getElementById('filter_modal_tgl').value; let dataToRender = [...globalTrxData]; 
        if (filterDate !== '') { dataToRender = dataToRender.filter(t => t.created_at.substring(0, 10) === filterDate); }
        if (filterType !== 'all') { dataToRender = dataToRender.filter(t => t.tipe === filterType); }
        dataToRender.sort((a, b) => { let valA, valB; if (sortModalCol === 'created_at') { valA = new Date(a.created_at).getTime(); valB = new Date(b.created_at).getTime(); } else if (sortModalCol === 'jumlah') { valA = parseInt(a.jumlah); valB = parseInt(b.jumlah); } else { valA = (a[sortModalCol] || '').toString().toLowerCase(); valB = (b[sortModalCol] || '').toString().toLowerCase(); } if (valA < valB) return sortModalDir === 'asc' ? -1 : 1; if (valA > valB) return sortModalDir === 'asc' ? 1 : -1; return 0; });
        let html = '';
        if (dataToRender.length === 0) { html = '<tr><td colspan="4" class="text-center py-4 text-muted">Tidak ada transaksi pada filter ini.</td></tr>'; } else {
            dataToRender.forEach(t => {
                let badge = 'bg-secondary'; let label = t.tipe.toUpperCase();
                if(t.tipe === 'topup') { badge = 'bg-success'; label = 'Top Up'; } if(t.tipe === 'reservasi') { badge = 'bg-primary'; label = 'Reservasi'; } if(t.tipe === 'parkir') { badge = 'bg-info'; label = 'Check-In'; } if(t.tipe === 'checkout') { badge = 'bg-dark'; label = 'Check-Out'; } if(t.tipe === 'batal') { badge = 'bg-warning'; label = 'Batal Manual'; } if(t.tipe === 'hangus') { badge = 'bg-danger'; label = 'Hangus'; } if(t.tipe === 'penalty') { badge = 'bg-danger'; label = 'Hangus (Data Lama)'; }
                let amount = parseInt(t.jumlah); let prefix = 'Rp '; let colorTxt = 'fw-bold';
                if (t.tipe === 'topup' && amount > 0) { prefix = '+ Rp '; colorTxt = 'text-success fw-bold'; } else if (amount > 0) { prefix = '- Rp '; colorTxt = 'text-danger fw-bold'; } else { prefix = 'Rp '; colorTxt = 'text-muted fw-bold'; }
                html += `<tr><td class="ps-4"><span class="fw-bold d-block small">${t.hari_indo}, ${t.tgl_indo}</span><span class="text-muted small"><i class="fas fa-clock me-1"></i>${t.jam_indo} WIB</span></td><td><span class="badge ${badge} bg-opacity-10 text-${badge.replace('bg-','')} border border-${badge.replace('bg-','')}">${label}</span></td><td class="text-end ${colorTxt}">${prefix}${amount.toLocaleString('id-ID')}</td><td class="pe-4 small text-muted">${t.keterangan || '-'}</td></tr>`;
            });
        } document.getElementById('trx_table_body').innerHTML = html;
    }
</script>
</body>
</html>
