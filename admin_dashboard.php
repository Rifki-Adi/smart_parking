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
    <title>Admin Dashboard - Smart Parking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .th-sortable { cursor: pointer; user-select: none; transition: color 0.2s; }
        .th-sortable:hover { color: var(--primary-color) !important; }
        .icon-sort { opacity: 0.3; margin-left: 5px; font-size: 0.9em; }
        .icon-sort.active { opacity: 1; color: var(--primary-color); }
        /* Custom scrollbar untuk tabel responsive */
        .table-responsive::-webkit-scrollbar { height: 8px; }
        .table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .table-responsive::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
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
                <li class="nav-item"><a class="nav-link active fw-bold" style="color: var(--primary-color);" href="admin_dashboard.php"><i class="fas fa-desktop me-1"></i> Monitor</a></li>
                <li class="nav-item"><a class="nav-link fw-bold" href="admin_users.php"><i class="fas fa-users me-1"></i> Pengguna</a></li>
                <li class="nav-item ms-3"><a class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" href="logout.php">Keluar</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5 mt-3">
    <div class="row g-4 mb-4">
        <div class="col-md-6"><div class="card card-custom p-4 text-center border-0 h-100"><p class="text-muted small fw-bold mb-1">TOTAL PENGGUNA</p><h2 id="total_user_card" class="fw-bold mb-0 text-dark">0</h2></div></div>
        <div class="col-md-6"><div class="card card-custom p-4 text-center border-0 h-100"><p class="text-muted small fw-bold mb-1">PERPUTARAN SALDO</p><h2 id="total_saldo_card" class="fw-bold text-success mb-0">Rp 0</h2></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-12">
            <div class="card card-custom p-4 border-0 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 id="header-kapasitas" class="fw-bold mb-0" style="color: var(--primary-color);">Live Slot Monitor (Memuat...)</h5>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2"><i class="fas fa-sync-alt fa-spin me-1"></i> Live Auto-Sync</span>
                </div>
                
                <div class="row g-3 row-cols-2 row-cols-md-4" id="slot-area-container">
                    <div class="col-12 text-center py-4 text-muted"><i class="fas fa-circle-notch fa-spin fa-2x mb-2"></i><br>Menghubungkan ke server...</div>
                </div>
            </div>

            <div class="card card-custom p-4 border-0">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
                    <h5 class="fw-bold mb-0" style="color: var(--primary-color);">Laporan Transaksi (Live)</h5>
                    <div class="d-flex flex-wrap gap-2 w-100 justify-content-md-end">
                        <a href="export_excel.php" class="btn btn-sm btn-success fw-bold shadow-sm d-flex align-items-center px-3" title="Download Data ke Excel">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                        <input type="date" id="filter_tgl" class="form-control form-control-sm border-primary text-primary shadow-sm fw-bold w-auto" onchange="resetPageAndFetch()">
                        <select id="filter_tipe" class="form-select form-select-sm w-auto shadow-sm fw-bold border-primary text-primary" onchange="resetPageAndFetch()">
                            <option value="">Semua Tipe</option>
                            <option value="topup">Top Up Saldo</option>
                            <option value="reservasi">Reservasi (Booking)</option>
                            <option value="parkir">Masuk (Check-In)</option>
                            <option value="checkout">Keluar (Check-Out)</option>
                            <option value="batal">Batal Manual</option>
                            <option value="hangus">Hangus / Waktu Habis</option>
                        </select>
                        <button class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="clearFilters()" title="Hapus Filter"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                
                <div class="table-responsive border rounded-3 shadow-sm pb-2">
                    <table class="table table-hover align-middle mb-0" style="white-space: nowrap; min-width: 700px;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-muted small text-uppercase th-sortable px-3 py-3" onclick="setSortCol('created_at')">Hari & Tanggal <i id="icon-sort-created_at" class="fas fa-sort-down icon-sort active"></i></th>
                                <th class="text-muted small text-uppercase th-sortable py-3" onclick="setSortCol('nama')">Pengguna <i id="icon-sort-nama" class="fas fa-sort icon-sort"></i></th>
                                <th class="text-muted small text-uppercase th-sortable py-3" onclick="setSortCol('tipe')">Tipe Aksi <i id="icon-sort-tipe" class="fas fa-sort icon-sort"></i></th>
                                <th class="text-muted small text-uppercase text-end th-sortable px-3 py-3" onclick="setSortCol('jumlah')">Jumlah <i id="icon-sort-jumlah" class="fas fa-sort icon-sort"></i></th>
                            </tr>
                        </thead>
                        <tbody id="trx_table_body"></tbody>
                    </table>
                </div>
                
                <nav class="mt-4" id="pagination_container"></nav>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    async function fetchLiveAdminSlots() {
        try {
            const res = await fetch(`api.php?action=get_slots_admin&_=${Date.now()}`);
            const data = await res.json();
            document.getElementById('header-kapasitas').innerText = `Live Slot Monitor (Terisi: ${data.terpakai} / ${data.total})`;
            let htmlContainer = '';
            
            data.slots.forEach(slot => {
                let innerHtml = `<i class="fas fa-car fa-2x mb-1 car-icon"></i><h6 class="fw-bold mb-0">Slot ${slot.slot_nomor}</h6>`;
                let borderState = slot.state;
                
                if (slot.user_data) {
                    if (slot.user_data.is_parkir) {
                        let namaText = slot.user_data.nama;
                        let platText = slot.user_data.plat_nomor;
                        let bgClass = (namaText === 'Tidak Diketahui') ? 'bg-dark' : 'bg-danger';
                        
                        innerHtml += `
                            <div class="mt-2 pt-2 border-top border-danger small">
                                <b class="d-block text-truncate text-danger">${namaText}</b>
                                <span class="badge bg-light text-dark border border-danger mt-1 mb-1">${platText}</span><br>
                                <span class="badge ${bgClass} mt-1 w-100 shadow-sm"><i class="fas fa-parking me-1"></i>SEDANG PARKIR</span>
                            </div>`;
                    } else {
                        if (slot.user_data.status === 'reserved_masuk') {
                            borderState = 'slot-reserved-admin';
                            innerHtml += `
                            <div class="mt-2 pt-2 border-top border-warning small">
                                <b class="d-block text-truncate" style="color:#b45309;">${slot.user_data.nama}</b>
                                <span class="badge bg-warning text-dark mt-1 mb-1">${slot.user_data.plat_nomor}</span><br>
                                <span class="badge bg-warning text-dark mt-1 w-100 shadow-sm"><i class="fas fa-bookmark me-1"></i>RESERVED</span>
                            </div>`;
                        } else {
                            let sisa = slot.user_data.sisa_waktu;
                            let m = Math.floor(sisa / 60); let s = sisa % 60;
                            let textWaktu = sisa > 0 ? `Sisa 0${m}:${s < 10 ? '0'+s : s}` : 'Habis / Batal';
                            let bgWaktu = sisa > 0 ? 'bg-danger' : 'bg-secondary';
                            innerHtml += `
                                <div class="mt-2 pt-2 border-top border-warning small">
                                    <b class="d-block text-truncate" style="color:#b45309;">${slot.user_data.nama}</b>
                                    <span class="badge bg-warning text-dark mt-1 mb-1">${slot.user_data.plat_nomor}</span><br>
                                    <span class="badge ${bgWaktu} mt-1 w-100 shadow-sm"><i class="fas fa-clock me-1"></i>${textWaktu}</span>
                                </div>`;
                        }
                    }
                }
                
                htmlContainer += `<div class="col"><div id="slot-box-${slot.slot_nomor}" class="slot-card p-3 border rounded-4 text-center ${borderState}" style="min-height: 140px;">${innerHtml}</div></div>`;
            });
            document.getElementById('slot-area-container').innerHTML = htmlContainer;
        } catch (e) {}
    }
    fetchLiveAdminSlots(); setInterval(fetchLiveAdminSlots, 1000);

    let currentPage = 1; let currentSortCol = 'created_at'; let currentSortDir = 'desc';
    function setSortCol(colName) { if (currentSortCol === colName) { currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc'; } else { currentSortCol = colName; currentSortDir = 'asc'; } updateSortIcons(); resetPageAndFetch(); }
    function updateSortIcons() { const cols = ['created_at', 'nama', 'tipe', 'jumlah']; cols.forEach(c => { let icon = document.getElementById('icon-sort-' + c); icon.className = 'icon-sort'; if (c === currentSortCol) { icon.classList.add('fas'); icon.classList.add(currentSortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'); icon.classList.add('active'); } else { icon.classList.add('fas', 'fa-sort'); } }); }
    function resetPageAndFetch() { currentPage = 1; fetchDashboardData(); }
    function clearFilters() { document.getElementById('filter_tipe').value = ''; document.getElementById('filter_tgl').value = ''; currentSortCol = 'created_at'; currentSortDir = 'desc'; updateSortIcons(); resetPageAndFetch(); }
    function changePage(page) { currentPage = page; fetchDashboardData(); }

    async function fetchDashboardData() {
        let fTipe = document.getElementById('filter_tipe').value; let fTgl = document.getElementById('filter_tgl').value;
        try {
            const res = await fetch(`api.php?action=get_dashboard_data&p=${currentPage}&tipe=${fTipe}&tgl=${fTgl}&sort_col=${currentSortCol}&sort_dir=${currentSortDir}&_=${Date.now()}`);
            const data = await res.json();
            document.getElementById('total_user_card').innerText = data.total_user; document.getElementById('total_saldo_card').innerText = 'Rp ' + data.total_saldo.toLocaleString('id-ID');
            
            let tbody = document.getElementById('trx_table_body'); let html = '';
            if (data.transactions.length === 0) { 
                html = '<tr><td colspan="4" class="text-center py-4 text-muted">Tidak ada transaksi pada filter ini.</td></tr>'; 
            } else {
                data.transactions.forEach(t => {
                    let badge = 'bg-secondary'; let label = t.tipe.toUpperCase();
                    if(t.tipe === 'topup') { badge = 'bg-success'; label = 'Top Up'; } if(t.tipe === 'reservasi') { badge = 'bg-primary'; label = 'Reservasi'; } if(t.tipe === 'parkir') { badge = 'bg-info'; label = 'Check-In'; } if(t.tipe === 'checkout') { badge = 'bg-dark'; label = 'Check-Out'; } if(t.tipe === 'batal') { badge = 'bg-warning'; label = 'Batal Manual'; } if(t.tipe === 'hangus') { badge = 'bg-danger'; label = 'Hangus'; } if(t.tipe === 'penalty') { badge = 'bg-danger'; label = 'Hangus (Data Lama)'; }
                    let amount = parseInt(t.jumlah); let prefix = 'Rp '; let colorTxt = 'fw-bold';
                    if (t.tipe === 'topup' && amount > 0) { prefix = '+ Rp '; colorTxt = 'text-success fw-bold'; } else if (amount > 0) { prefix = '- Rp '; colorTxt = 'text-danger fw-bold'; } else { prefix = 'Rp '; colorTxt = 'text-muted fw-bold'; }
                    html += `<tr>
                                <td class="px-3"><span class="d-block fw-bold small">${t.hari_indo}, ${t.tgl_indo}</span><span class="text-muted small"><i class="fas fa-clock me-1"></i>${t.jam_indo} WIB</span></td>
                                <td><span class="fw-bold d-block">${t.nama}</span><span class="badge bg-light text-dark border mt-1">${t.plat_nomor}</span></td>
                                <td><span class="badge ${badge} bg-opacity-10 text-${badge.replace('bg-','')} border border-${badge.replace('bg-','')}">${label}</span></td>
                                <td class="text-end px-3 ${colorTxt}">${prefix}${amount.toLocaleString('id-ID')}</td>
                            </tr>`;
                });
            } 
            tbody.innerHTML = html;
            
            // PERBAIKAN SMART PAGINATION 
            let pagContainer = document.getElementById('pagination_container');
            if (data.total_pages > 1) {
                let pHtml = '<ul class="pagination pagination-sm flex-wrap justify-content-center mb-0 gap-1">';
                
                // Tombol Prev
                pHtml += `<li class="page-item ${data.current_page <= 1 ? 'disabled' : ''}"><a class="page-link shadow-sm border-0 rounded" href="#" onclick="changePage(${data.current_page - 1}); return false;">&laquo; Prev</a></li>`;
                
                // Logika pembatasan nomor halaman (Tampil Maksimal 5 Kotak Berdekatan)
                let startPage = Math.max(1, data.current_page - 2);
                let endPage = Math.min(data.total_pages, data.current_page + 2);
                
                if (startPage > 1) {
                    pHtml += `<li class="page-item"><a class="page-link shadow-sm border-0 rounded" href="#" onclick="changePage(1); return false;">1</a></li>`;
                    if (startPage > 2) pHtml += `<li class="page-item disabled"><span class="page-link shadow-sm border-0 rounded bg-light text-muted">...</span></li>`;
                }
                
                for (let i = startPage; i <= endPage; i++) { 
                    pHtml += `<li class="page-item ${data.current_page === i ? 'active' : ''}"><a class="page-link shadow-sm border-0 rounded" href="#" onclick="changePage(${i}); return false;">${i}</a></li>`; 
                }
                
                if (endPage < data.total_pages) {
                    if (endPage < data.total_pages - 1) pHtml += `<li class="page-item disabled"><span class="page-link shadow-sm border-0 rounded bg-light text-muted">...</span></li>`;
                    pHtml += `<li class="page-item"><a class="page-link shadow-sm border-0 rounded" href="#" onclick="changePage(${data.total_pages}); return false;">${data.total_pages}</a></li>`;
                }
                
                // Tombol Next
                pHtml += `<li class="page-item ${data.current_page >= data.total_pages ? 'disabled' : ''}"><a class="page-link shadow-sm border-0 rounded" href="#" onclick="changePage(${data.current_page + 1}); return false;">Next &raquo;</a></li></ul>`;
                
                pagContainer.innerHTML = pHtml;
            } else { 
                pagContainer.innerHTML = ''; 
            }
        } catch (e) {}
    }
    fetchDashboardData(); setInterval(fetchDashboardData, 2000);
</script>
</body>
</html>
