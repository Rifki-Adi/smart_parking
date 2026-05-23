<?php
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

// Mencegah error fatal jika session browser tersangkut
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === 'esp32_device') {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') { header("Location: dashboard.php"); exit; }

$uid_admin = $_SESSION['user_id'];
$admin_name = $conn->query("SELECT nama FROM profiles WHERE id = '$uid_admin'")->fetchColumn();

// ===========================================
// ACTION: EDIT USER
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    $id_edit = $_POST['user_id'];
    $nama_edit = trim($_POST['nama']);
    $plat_edit = strtoupper(trim($_POST['plat_nomor']));
    $saldo_edit = (int)$_POST['saldo'];
    $nohp_edit = trim($_POST['no_hp']);

    // LOGIKA BARU: Otomatis buat ulang Token Stiker berdasarkan Plat yang baru diedit
    $qr_permanen_edit = "STIKER-" . $plat_edit;

    try {
        $stmt = $conn->prepare("UPDATE profiles SET nama = ?, plat_nomor = ?, no_hp = ?, saldo = ?, qr_token_permanen = ? WHERE id = ? AND role = 'user'");
        $stmt->execute([$nama_edit, $plat_edit, $nohp_edit, $saldo_edit, $qr_permanen_edit, $id_edit]);
        $_SESSION['success_msg'] = "Data pengguna dan token stiker berhasil diperbarui!";
    } catch (Exception $e) { $_SESSION['error_msg'] = "Gagal memperbarui data pengguna."; }
    header("Location: admin_users.php"); exit;
}

// ===========================================
// ACTION: TAMBAH USER BARU (KHUSUS ADMIN)
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_user') {
    $nama_add  = trim($_POST['nama']);
    $email_add = strtolower(trim($_POST['email']));
    $nohp_add  = trim($_POST['no_hp']);
    $plat_add  = strtoupper(trim($_POST['plat_nomor']));
    $pass_add  = $_POST['password'];
    $saldo_add = (int)$_POST['saldo'];

    try {
        // Cek duplikasi email atau plat nomor
        $cek = $conn->prepare("SELECT id FROM profiles WHERE email = ? OR plat_nomor = ?");
        $cek->execute([$email_add, $plat_add]);
        if ($cek->fetch()) {
            $_SESSION['error_msg'] = "Gagal: Email atau Plat Nomor sudah terdaftar!";
        } else {
            $qr_permanen = "STIKER-" . $plat_add;
            $stmt = $conn->prepare("INSERT INTO profiles (email, no_hp, nama, plat_nomor, password, role, saldo, qr_token_permanen) VALUES (?, ?, ?, ?, ?, 'user', ?, ?)");
            $stmt->execute([$email_add, $nohp_add, $nama_add, $plat_add, $pass_add, $saldo_add, $qr_permanen]);
            $_SESSION['success_msg'] = "Pengguna baru berhasil ditambahkan!";
        }
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Gagal menambah pengguna: " . $e->getMessage();
    }
    header("Location: admin_users.php"); exit;
}

// Paginasi Data Pengguna
$limit = 15;
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1; $page = ($page < 1) ? 1 : $page; $offset = ($page - 1) * $limit;
$total_user = $conn->query("SELECT COUNT(*) FROM profiles WHERE role = 'user'")->fetchColumn();
$total_pages = ceil($total_user / $limit);

$stmt_users = $conn->prepare("SELECT * FROM profiles WHERE role = 'user' ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt_users->bindValue(1, $limit, PDO::PARAM_INT); $stmt_users->bindValue(2, $offset, PDO::PARAM_INT); $stmt_users->execute();
$users = $stmt_users->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pengguna - Admin Smart Parking</title>
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
        <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
            <img src="logo 1.png" alt="Smart Parking Logo" style="height: 80px; width: auto;" class="me-3">
            <span class="badge bg-danger fs-6 shadow-sm"><i class="fas fa-user-shield me-1"></i> ADMIN: <?= htmlspecialchars($admin_name) ?></span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><a class="nav-link fw-bold" href="admin_dashboard.php"><i class="fas fa-desktop me-1"></i> Monitor</a></li>
                <li class="nav-item"><a class="nav-link active fw-bold" style="color: var(--primary-color);" href="admin_users.php"><i class="fas fa-users me-1"></i> Pengguna</a></li>
                <li class="nav-item ms-3"><a class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" href="logout.php">Keluar</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container pb-5 mt-3">
    <div class="card card-custom p-4 border-0">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h5 class="fw-bold mb-1" style="color: var(--primary-color);"><i class="fas fa-users me-2 text-warning"></i> Daftar Pengguna</h5>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-1">Total: <?= $total_user ?> Pengguna</span>
            </div>
            
            <button class="btn btn-primary fw-bold shadow-sm rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#modalAdd">
                <i class="fas fa-user-plus me-1"></i> Tambah Pengguna
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-muted small text-uppercase">Nama & Info Kontak</th>
                        <th class="text-muted small text-uppercase text-center">Plat Nomor</th>
                        <th class="text-muted small text-uppercase text-end">Saldo (Rp)</th>
                        <th class="text-muted small text-uppercase text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) == 0): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada pengguna yang terdaftar.</td></tr>
                    <?php endif; ?>

                    <?php foreach($users as $u): ?>
                    <tr>
                        <td>
                            <span class="fw-bold d-block text-dark mb-1"><?= htmlspecialchars($u['nama']) ?></span>
                            <small class="text-muted d-block"><i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($u['email'] ?? '-') ?></small>
                            <small class="text-muted d-block"><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($u['no_hp'] ?? '-') ?></small>
                            <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">ID: <?= substr($u['id'], 0, 8) ?>...</small>
                        </td>
                        <td class="text-center"><span class="badge bg-light text-dark border px-3 py-2"><?= htmlspecialchars($u['plat_nomor']) ?></span></td>
                        <td class="text-end fw-bold text-success"><?= number_format($u['saldo'], 0, ',', '.') ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 fw-bold me-1 mb-1" onclick="bukaModalRiwayat('<?= $u['id'] ?>', '<?= htmlspecialchars(addslashes($u['nama'])) ?>')"><i class="fas fa-history"></i> Riwayat</button>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold me-1 mb-1" 
                                    data-bs-toggle="modal" data-bs-target="#modalEdit" 
                                    data-id="<?= $u['id'] ?>" 
                                    data-nama="<?= htmlspecialchars($u['nama'], ENT_QUOTES) ?>" 
                                    data-plat="<?= htmlspecialchars($u['plat_nomor'], ENT_QUOTES) ?>" 
                                    data-nohp="<?= htmlspecialchars($u['no_hp'] ?? '', ENT_QUOTES) ?>" 
                                    data-saldo="<?= $u['saldo'] ?>" 
                                    onclick="siapkanEdit(this)"><i class="fas fa-edit"></i> Edit</button>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold mb-1" 
                                    onclick="hapusUser('<?= $u['id'] ?>', '<?= htmlspecialchars(addslashes($u['nama'])) ?>')">
                                <i class="fas fa-trash-alt"></i> Hapus
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link shadow-sm border-0" href="?p=<?= $page - 1 ?>">Sebelumnya</a></li>
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($page == $i) ? 'active' : '' ?>"><a class="page-link shadow-sm border-0" href="?p=<?= $i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>"><a class="page-link shadow-sm border-0" href="?p=<?= $page + 1 ?>">Selanjutnya</a></li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0 rounded-top-4">
                <h5 class="modal-title fw-bold" style="color: var(--primary-color);"><i class="fas fa-user-plus me-2"></i>Tambah Pengguna Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="admin_users.php">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_user">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control" placeholder="Nama identitas" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-muted mb-1">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="Email aktif" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-muted mb-1">No. HP <span class="text-danger">*</span></label>
                            <input type="text" inputmode="numeric" pattern="[0-9]*" name="no_hp" class="form-control" placeholder="0812..." required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-muted mb-1">Plat Nomor <span class="text-danger">*</span></label>
                            <input type="text" name="plat_nomor" class="form-control text-uppercase" placeholder="B 1234 ABC" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-muted mb-1">Saldo Awal (Rp)</label>
                            <input type="number" name="saldo" class="form-control fw-bold text-success" value="0" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold text-muted mb-1">Kata Sandi <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Sandi pengguna" required>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Daftarkan Pengguna</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0 rounded-top-4">
                <h5 class="modal-title fw-bold" style="color: var(--primary-color);"><i class="fas fa-user-edit me-2"></i>Edit Pengguna</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="admin_users.php">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_id">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Nama Lengkap</label>
                        <input type="text" name="nama" id="edit_nama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">No. HP</label>
                        <input type="text" inputmode="numeric" pattern="[0-9]*" name="no_hp" id="edit_nohp" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Plat Nomor</label>
                        <input type="text" name="plat_nomor" id="edit_plat" class="form-control text-uppercase" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Saldo (Rp)</label>
                        <input type="number" name="saldo" id="edit_saldo" class="form-control fw-bold text-success" required>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTrx" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0 rounded-top-4 align-items-center flex-wrap gap-2">
                <h5 class="modal-title fw-bold mb-0" style="color: var(--primary-color);"><i class="fas fa-list-alt me-2"></i>Riwayat: <span id="trx_user_name" class="text-info"></span></h5>
                <div class="d-flex flex-wrap gap-2 ms-auto me-2">
                    <input type="date" id="filter_modal_tgl" class="form-control form-control-sm border-info text-info shadow-sm fw-bold w-auto" onchange="renderTrxTable()" title="Pilih Tanggal">
                    <select id="filter_modal_tipe" class="form-select form-select-sm shadow-sm fw-bold border-info text-info" onchange="renderTrxTable()" style="width:auto;">
                        <option value="all">Semua Tipe</option>
                        <option value="topup">Top Up</option>
                        <option value="reservasi">Reservasi (Booking)</option>
                        <option value="parkir">Masuk (Check-In)</option>
                        <option value="checkout">Keluar (Check-Out)</option>
                        <option value="batal">Batal Manual</option>
                        <option value="hangus">Hangus / Waktu Habis</option>
                    </select>
                    <button class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="resetFilterModal()" title="Hapus Filter"><i class="fas fa-times"></i></button>
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
    <?php if(isset($_SESSION['success_msg'])): ?>
        Swal.fire({ icon: 'success', title: 'Berhasil!', text: '<?= $_SESSION['success_msg'] ?>', timer: 2000, showConfirmButton: false });
        <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error_msg'])): ?>
        Swal.fire({ icon: 'error', title: 'Gagal!', text: '<?= $_SESSION['error_msg'] ?>', confirmButtonColor: '#e74c3c' });
        <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>

    function siapkanEdit(btn) { 
        document.getElementById('edit_id').value = btn.getAttribute('data-id'); 
        document.getElementById('edit_nama').value = btn.getAttribute('data-nama'); 
        document.getElementById('edit_nohp').value = btn.getAttribute('data-nohp'); 
        document.getElementById('edit_plat').value = btn.getAttribute('data-plat'); 
        document.getElementById('edit_saldo').value = btn.getAttribute('data-saldo'); 
    }

    function hapusUser(userId, userName) {
        Swal.fire({
            title: 'Hapus Pengguna?',
            html: `Apakah Anda yakin ingin menghapus permanen <b>${userName}</b>?<br><small class="text-danger">Seluruh data reservasi dan transaksi pengguna ini juga akan ikut terhapus.</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74c3c',
            cancelButtonColor: '#1a365d',
            confirmButtonText: '<i class="fas fa-trash-alt me-1"></i> Ya, Hapus!',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });
                
                let fd = new FormData();
                fd.append('user_id', userId);
                
                fetch('api.php?action=delete_user', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire({
                            title: 'Terhapus!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonColor: '#559da0'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Gagal!', data.message, 'error');
                    }
                })
                .catch(e => Swal.fire('Error!', 'Koneksi bermasalah.', 'error'));
            }
        });
    }

    let globalTrxData = []; let currentOpenUserId = null; let liveTrxInterval = null;
    let sortModalCol = 'created_at'; let sortModalDir = 'desc';

    function bukaModalRiwayat(userId, userName) {
        currentOpenUserId = userId; document.getElementById('trx_user_name').innerText = userName;
        document.getElementById('trx_table_body').innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><br>Memuat data riwayat live...</td></tr>';
        resetFilterModal(false); new bootstrap.Modal(document.getElementById('modalTrx')).show();
        fetchAndRenderLive(); if(liveTrxInterval) clearInterval(liveTrxInterval); liveTrxInterval = setInterval(fetchAndRenderLive, 2000);
    }

    document.getElementById('modalTrx').addEventListener('hidden.bs.modal', function () { clearInterval(liveTrxInterval); currentOpenUserId = null; });

    async function fetchAndRenderLive() {
        if(!currentOpenUserId) return;
        try { const response = await fetch(`api.php?action=get_user_trx&user_id=${currentOpenUserId}&_=${Date.now()}`); globalTrxData = await response.json(); renderTrxTable(); } catch (e) {}
    }

    function setSortTrx(colName) {
        if (sortModalCol === colName) { sortModalDir = sortModalDir === 'asc' ? 'desc' : 'asc'; } else { sortModalCol = colName; sortModalDir = 'asc'; }
        updateSortIconsTrx(); renderTrxTable();
    }

    function updateSortIconsTrx() {
        const cols = ['created_at', 'tipe', 'jumlah', 'keterangan'];
        cols.forEach(c => {
            let icon = document.getElementById('icon-sort-modal-' + c); icon.className = 'icon-sort';
            if (c === sortModalCol) { icon.classList.add('fas'); icon.classList.add(sortModalDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down'); icon.classList.add('active'); } else { icon.classList.add('fas', 'fa-sort'); }
        });
    }

    function resetFilterModal(doRender = true) { document.getElementById('filter_modal_tipe').value = 'all'; document.getElementById('filter_modal_tgl').value = ''; sortModalCol = 'created_at'; sortModalDir = 'desc'; updateSortIconsTrx(); if (doRender && globalTrxData.length > 0) renderTrxTable(); }

    function renderTrxTable() {
        let filterType = document.getElementById('filter_modal_tipe').value; let filterDate = document.getElementById('filter_modal_tgl').value; let dataToRender = [...globalTrxData]; 
        if (filterDate !== '') { dataToRender = dataToRender.filter(t => t.created_at.substring(0, 10) === filterDate); }
        if (filterType !== 'all') { dataToRender = dataToRender.filter(t => t.tipe === filterType); }
        
        dataToRender.sort((a, b) => {
            let valA, valB;
            if (sortModalCol === 'created_at') { valA = new Date(a.created_at).getTime(); valB = new Date(b.created_at).getTime(); } else if (sortModalCol === 'jumlah') { valA = parseInt(a.jumlah); valB = parseInt(b.jumlah); } else { valA = (a[sortModalCol] || '').toString().toLowerCase(); valB = (b[sortModalCol] || '').toString().toLowerCase(); }
            if (valA < valB) return sortModalDir === 'asc' ? -1 : 1; if (valA > valB) return sortModalDir === 'asc' ? 1 : -1; return 0;
        });
        
        let html = '';
        if (dataToRender.length === 0) { html = '<tr><td colspan="4" class="text-center py-4 text-muted">Tidak ada transaksi pada filter ini.</td></tr>'; } else {
            dataToRender.forEach(t => {
                let badge = 'bg-secondary'; let label = t.tipe.toUpperCase();
                if(t.tipe === 'topup') { badge = 'bg-success'; label = 'Top Up'; }
                if(t.tipe === 'reservasi') { badge = 'bg-primary'; label = 'Reservasi'; }
                if(t.tipe === 'parkir') { badge = 'bg-info'; label = 'Check-In'; }
                if(t.tipe === 'checkout') { badge = 'bg-dark'; label = 'Check-Out'; }
                if(t.tipe === 'batal') { badge = 'bg-warning'; label = 'Batal Manual'; }
                if(t.tipe === 'hangus') { badge = 'bg-danger'; label = 'Hangus'; }
                if(t.tipe === 'penalty') { badge = 'bg-danger'; label = 'Hangus (Data Lama)'; }
                
                let amount = parseInt(t.jumlah); let prefix = 'Rp '; let colorTxt = 'fw-bold';
                if (t.tipe === 'topup' && amount > 0) { prefix = '+ Rp '; colorTxt = 'text-success fw-bold'; } else if (amount > 0) { prefix = '- Rp '; colorTxt = 'text-danger fw-bold'; } else { prefix = 'Rp '; colorTxt = 'text-muted fw-bold'; }
                
                html += `<tr><td class="ps-4"><span class="fw-bold d-block small">${t.hari_indo}, ${t.tgl_indo}</span><span class="text-muted small"><i class="fas fa-clock me-1"></i>${t.jam_indo} WIB</span></td><td><span class="badge ${badge} bg-opacity-10 text-${badge.replace('bg-','')} border border-${badge.replace('bg-','')}">${label}</span></td><td class="text-end ${colorTxt}">${prefix}${amount.toLocaleString('id-ID')}</td><td class="pe-4 small text-muted">${t.keterangan || '-'}</td></tr>`;
            });
        }
        document.getElementById('trx_table_body').innerHTML = html;
    }
</script>
</body>
</html>
