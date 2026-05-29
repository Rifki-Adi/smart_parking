<?php
error_reporting(0);
ob_start();
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json');

// =============================================
// CLEANUP OTOMATIS RESERVASI EXPIRED
// Pending lebih dari 60 detik akan dihapus,
// sehingga slot otomatis kembali tersedia.
// =============================================
function cleanupExpiredReservations($conn) {
    try {
        $expiredAt = date('Y-m-d H:i:s', time() - 60);

        $stmt = $conn->prepare("
            WITH expired AS (
                DELETE FROM reservasi
                WHERE status = 'pending'
                AND created_at < ?
                RETURNING user_id, kode_booking
            )
            INSERT INTO transaksi (user_id, tipe, jumlah, keterangan)
            SELECT 
                user_id,
                'hangus',
                0,
                'Waktu Habis Tiket ' || kode_booking
            FROM expired
        ");

        $stmt->execute([$expiredAt]);

    } catch (Exception $e) {
        // Cleanup jangan sampai bikin API utama gagal.
    }
}

// Jalankan cleanup otomatis setiap API dipanggil
//$action = isset($_GET['action']) ? $_GET['action'] : '';

//if (in_array($action, ['get_slots', 'get_slots_admin'])) {
//    cleanupExpiredReservations($conn);
//}


// =============================================
// BYPASS UNTUK ESP32 HARDWARE DEVICE
// =============================================
$action_early = isset($_GET['action']) ? $_GET['action'] : '';

if (
    in_array(
        $action_early,
        [
            'gate_scan',
            'get_slots',
            'get_slots_admin',
            'update_hardware_slots'
        ]
    )
    && !isset($_SESSION['user_id'])
) {
    $_SESSION['user_id'] = 'esp32_device';
    $_SESSION['role']    = 'admin';
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. ACTION: RESERVASI SLOT
if ($action == 'book_slot') {
    $uid = $_POST['user_id'];
    $slot_nomor = $_POST['slot_nomor'];
    
    if ((int)$slot_nomor > 4) { echo json_encode(['status' => 'error', 'message' => 'Slot tidak tersedia.']); exit; }

    $user_saldo = $conn->query("SELECT saldo FROM profiles WHERE id = '$uid'")->fetchColumn();
    if ($user_saldo < 8000) { echo json_encode(['status' => 'error', 'message' => 'Saldo Anda tidak cukup (Min. Rp 8.000: 5rb Booking + 3rb Gerbang). Silakan Top Up.']); exit; }
    
    $stmt_cek = $conn->prepare("SELECT id FROM reservasi WHERE user_id = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds')))");
    $stmt_cek->execute([$uid]);
    if ($stmt_cek->fetch()) { echo json_encode(['status' => 'error', 'message' => 'Anda sudah memiliki tiket aktif atau sedang parkir!']); exit; }
    
    $slot = $conn->query("SELECT id FROM slot WHERE slot_nomor = '$slot_nomor'")->fetch();
    
    $stmt_cek_slot = $conn->prepare("SELECT id FROM reservasi WHERE slot_id = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds')))");
    $stmt_cek_slot->execute([$slot['id']]);
    if ($stmt_cek_slot->fetch()) { 
        echo json_encode(['status' => 'error', 'message' => 'Mohon maaf, slot ini baru saja diambil oleh kendaraan di lokasi.']); 
        exit; 
    }

    $kode = "PK-" . strtoupper(substr(md5(uniqid()), 0, 6));

    try {
        $conn->beginTransaction();
        $now = date('Y-m-d H:i:s');
        $ins = $conn->prepare("INSERT INTO reservasi (user_id, slot_id, kode_booking, status, created_at) VALUES (?, ?, ?, 'pending', ?)");
        $ins->execute([$uid, $slot['id'], $kode, $now]);
        
        $conn->query("UPDATE profiles SET saldo = saldo - 5000 WHERE id = '$uid'");
        $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'reservasi', 5000, 'Biaya Reservasi (Booking Slot)')")->execute([$uid]);
        $conn->commit();
        echo json_encode(['status' => 'success', 'kode_booking' => $kode, 'qr_url' => "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=$kode"]);
    } catch (Exception $e) { $conn->rollBack(); echo json_encode(['status' => 'error', 'message' => 'Gagal memproses reservasi.']); }
    exit;
}

// 2. ACTION: GET SLOTS (DASHBOARD USER)
if ($action == 'get_slots') {

    ob_clean();

    $uid = isset($_GET['uid']) ? $_GET['uid'] : '';

    $slots = $conn->query("
    SELECT *
    FROM slot
    ORDER BY slot_nomor ASC
    LIMIT 4
    ")->fetchAll(PDO::FETCH_ASSOC);

    $res_aktif = $conn->query("
    SELECT slot_id, user_id, status, kode_booking
    FROM reservasi
    WHERE status = 'check-in'
       OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds'))
    ")->fetchAll(PDO::FETCH_ASSOC);

    $map = [];

    foreach($res_aktif as $r) {
        $map[$r['slot_id']] = [
            'uid' => $r['user_id'],
            'status' => $r['status'],
            'kode' => $r['kode_booking']
        ];
    }

    $result = [];

    foreach($slots as $s) {

        $state = 'kosong';

        if ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1) {

            if (
                isset($map[$s['id']]) &&
                $map[$s['id']]['uid'] == $uid &&
                $map[$s['id']]['status'] == 'check-in'
            ) {

                $state = 'terisi_me';

            } else {

                $state = 'terisi';
            }

        } elseif (isset($map[$s['id']])) {

            $is_me = ($map[$s['id']]['uid'] == $uid);

            $r_status = $map[$s['id']]['status'];

            $r_kode = $map[$s['id']]['kode'];

            if ($r_status == 'pending') {

                $state = $is_me ? 'reserved_me' : 'reserved_other';

            } else {

                if (strpos($r_kode, 'PL-') === 0) {

                    $state = 'kosong';

                } else {

                    $state = $is_me ? 'reserved_me' : 'reserved_other';
                }
            }
        }

        $result[] = [
            'slot_nomor' => $s['slot_nomor'],
            'state' => $state
        ];
    }

    echo json_encode($result);

    exit;
}

// 3. ACTION: CANCEL MANUAL
if ($action == 'cancel_booking') {
    $kode = $_POST['kode_booking'];
    $uid = $_POST['user_id'];
    $stmt = $conn->prepare("DELETE FROM reservasi WHERE kode_booking = ? AND user_id = ? AND status = 'pending'");
    $stmt->execute([$kode, $uid]);
    if ($stmt->rowCount() > 0) {
        $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'batal', 0, 'Batal Manual Tiket $kode')")->execute([$uid]);
        echo json_encode(['status' => 'success', 'message' => 'Reservasi berhasil dibatalkan.']);
    } else { echo json_encode(['status' => 'error', 'message' => 'Reservasi tidak ditemukan.']); }
    exit;
}

// 4. ACTION: GET USER TRANSACTIONS
if ($action == 'get_user_trx') {
    ob_clean(); 
    $target_uid = isset($_GET['user_id']) ? $_GET['user_id'] : '';
    $stmt = $conn->prepare("SELECT * FROM transaksi WHERE user_id = ? ORDER BY created_at ASC");
    $stmt->execute([$target_uid]);
    $trx = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hari_arr = ['Sunday'=>'Minggu', 'Monday'=>'Senin', 'Tuesday'=>'Selasa', 'Wednesday'=>'Rabu', 'Thursday'=>'Kamis', 'Friday'=>'Jumat', 'Saturday'=>'Sabtu'];
    foreach ($trx as &$t) {
        $h_en = date('l', strtotime($t['created_at']));
        $t['hari_indo'] = $hari_arr[$h_en];
        $t['tgl_indo'] = date('d M Y', strtotime($t['created_at']));
        $t['jam_indo'] = date('H:i', strtotime($t['created_at']));
    }
    echo json_encode($trx); exit;
}

// 5. ACTION: GET SLOTS ADMIN
if ($action == 'get_slots_admin') {
    ob_clean();
    $slots = $conn->query("SELECT * FROM slot ORDER BY slot_nomor ASC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    
    $res_info = $conn->query("SELECT r.slot_id, r.status, r.created_at, r.kode_booking, p.nama, p.plat_nomor FROM reservasi r JOIN profiles p ON r.user_id = p.id WHERE r.status = 'check-in' OR (r.status = 'pending' AND r.created_at >= (NOW() - INTERVAL '60 seconds'))")->fetchAll(PDO::FETCH_ASSOC);
    $info_map = []; foreach($res_info as $ri) { $info_map[$ri['slot_id']] = $ri; }
    $reservasi_aktif = $conn->query("SELECT slot_id FROM reservasi WHERE status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds'))")->fetchAll(PDO::FETCH_COLUMN);
    
    $kapasitas_terpakai = 0; $result = [];
    foreach($slots as $s) {
        if ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1 || in_array($s['id'], $reservasi_aktif)) $kapasitas_terpakai++;
        
        $state = 'slot-free'; $user_data = null;
        
        if ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1) {
            $state = 'slot-occupied';
            if (isset($info_map[$s['id']]) && $info_map[$s['id']]['status'] == 'check-in') {
                $ri = $info_map[$s['id']];
                $user_data = ['nama' => htmlspecialchars($ri['nama']), 'plat_nomor' => htmlspecialchars($ri['plat_nomor']), 'is_parkir' => true];
            } else {
                $user_data = ['nama' => 'Tidak Diketahui', 'plat_nomor' => 'TIDAK VALID', 'is_parkir' => true];
            }
        } elseif (isset($info_map[$s['id']])) { 
            $ri = $info_map[$s['id']];
            if ($ri['status'] == 'pending') {
                $state = 'slot-reserved-admin';
                $sisa = 60 - (strtotime(date('Y-m-d H:i:s')) - strtotime(substr($ri['created_at'], 0, 19)));
                if ($sisa < 0) $sisa = 0;
                $user_data = ['nama' => htmlspecialchars($ri['nama']), 'plat_nomor' => htmlspecialchars($ri['plat_nomor']), 'sisa_waktu' => $sisa, 'is_parkir' => false, 'status' => 'pending'];
            } else {
                if (strpos($ri['kode_booking'], 'PL-') === 0) {
                    $state = 'slot-free';
                    $user_data = null;
                } else {
                    $state = 'slot-reserved-admin';
                    $user_data = ['nama' => htmlspecialchars($ri['nama']), 'plat_nomor' => htmlspecialchars($ri['plat_nomor']), 'sisa_waktu' => -1, 'is_parkir' => false, 'status' => 'reserved_masuk'];
                }
            }
        }
        $result[] = ['slot_nomor' => $s['slot_nomor'], 'state' => $state, 'user_data' => $user_data];
    }
    echo json_encode(['slots' => $result, 'terpakai' => $kapasitas_terpakai, 'total' => count($slots)]); exit;
}

// 6. ACTION: SIMULATOR GERBANG
if ($action == 'gate_scan') {
    ob_clean();
    $qr = trim($_POST['qr_code']);
    $gate = isset($_POST['gate']) ? trim($_POST['gate']) : ''; 
    $now = date('Y-m-d H:i:s');
    
    try {
        if (strpos($qr, 'PK-') === 0) {
            $stmt = $conn->prepare("SELECT * FROM reservasi WHERE kode_booking = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds')))");
            $stmt->execute([$qr]);
            $booking = $stmt->fetch();
            
            if ($booking) {
                $uid = $booking['user_id'];
                
                if ($booking['status'] == 'pending') {
                    if ($gate == 'out') {
                        echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Belum Scan Masuk']); 
                        exit;
                    }

                    $u = $conn->query("SELECT saldo FROM profiles WHERE id = '$uid'")->fetch();
                    if ($u['saldo'] < 3000) { echo json_encode(['status' => 'error', 'message' => 'Saldo Tdk Cukup!|Min. Rp 3.000']); exit; }
                    
                    $conn->beginTransaction();
                    $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'parkir', 0, 'Masuk / Check-In (Biaya dipotong saat keluar)')")->execute([$uid]);
                    $conn->query("UPDATE reservasi SET status = 'check-in' WHERE id = " . $booking['id']);
                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Reservasi Valid|Silakan Masuk']); exit;
                    
                } else if ($booking['status'] == 'check-in') {
                    if ($gate == 'in') {
                        echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Mobil Sdh di Dalam']); 
                        exit;
                    }

                    $conn->beginTransaction();
                    $conn->query("UPDATE profiles SET saldo = saldo - 3000 WHERE id = '$uid'");
                    $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'checkout', 3000, 'Keluar / Check-Out (Pembayaran Biaya Parkir)')")->execute([$uid]);
                    $conn->query("UPDATE reservasi SET status = 'selesai' WHERE id = " . $booking['id']);
                    $conn->query("UPDATE slot SET terisi = 'false' WHERE id = " . $booking['slot_id']);
                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Check-out Berhasil|Saldo -Rp 3.000']); exit;
                }
            } else { echo json_encode(['status' => 'error', 'message' => 'QR Tdk Dikenali!|Atau Tiket Hangus']); exit; }
            
        } else {
            $prof = $conn->prepare("SELECT id, saldo FROM profiles WHERE qr_token_permanen = ? OR CONCAT('STIKER-', plat_nomor) = ?");
            $prof->execute([$qr, $qr]);
            $user = $prof->fetch();
            
            if ($user) {
                $uid = $user['id'];
                $cek_in = $conn->query("SELECT * FROM reservasi WHERE user_id = '$uid' AND status = 'check-in'")->fetch();
                
                if ($cek_in) {
                    if ($gate == 'in') {
                        echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Mobil Sdh di Dalam']); 
                        exit;
                    }

                    $conn->beginTransaction();
                    $conn->query("UPDATE profiles SET saldo = saldo - 3000 WHERE id = '$uid'");
                    $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'checkout', 3000, 'Keluar / Check-Out (Pembayaran Biaya Parkir)')")->execute([$uid]);
                    $conn->query("UPDATE reservasi SET status = 'selesai' WHERE id = " . $cek_in['id']);
                    $conn->query("UPDATE slot SET terisi = 'false' WHERE id = " . $cek_in['slot_id']);
                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Check-out Berhasil|Saldo -Rp 3.000']); exit;
                    
                } else {
                    if ($gate == 'out') {
                        echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Belum Scan Masuk']); 
                        exit;
                    }

                    $punya_pending = $conn->query("SELECT id FROM reservasi WHERE user_id = '$uid' AND status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds')")->fetch();
                    if ($punya_pending) { echo json_encode(['status' => 'error', 'message' => 'Punya Tiket Aktif!|Gunakan QR Aplikasi']); exit; }
                    
                    $slot_kosong = $conn->query("SELECT id, slot_nomor FROM slot WHERE terisi = 'false' AND CAST(slot_nomor AS INT) <= 4 AND id NOT IN (SELECT slot_id FROM reservasi WHERE status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds'))) ORDER BY slot_nomor ASC LIMIT 1")->fetch();
                    if (!$slot_kosong) { echo json_encode(['status' => 'error', 'message' => 'Mohon Maaf...|Parkir Penuh']); exit; }
                    
                    if ($user['saldo'] < 3000) { echo json_encode(['status' => 'error', 'message' => 'Saldo Tdk Cukup!|Min. Rp 3.000']); exit; }
                    
                    $kode_langsung = "PL-" . strtoupper(substr(md5(uniqid()), 0, 6)); 
                    $conn->beginTransaction();
                    $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'parkir', 0, 'Masuk / Check-In Langsung (Biaya dipotong saat keluar)')")->execute([$uid]);
                    $ins = $conn->prepare("INSERT INTO reservasi (user_id, slot_id, kode_booking, status, created_at) VALUES (?, ?, ?, 'check-in', ?)");
                    $ins->execute([$uid, $slot_kosong['id'], $kode_langsung, $now]);
                    $conn->commit();
                    
                    echo json_encode(['status' => 'success', 'message' => 'Akses Stiker Valid|Silakan Masuk']); exit;
                }
            } else { echo json_encode(['status' => 'error', 'message' => 'Stiker Tdk Valid!|Atau Tdk Terdaftar']); exit; }
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        echo json_encode(['status' => 'error', 'message' => 'Kesalahan Sistem|Hubungi Admin']); exit;
    }
}

// 7. ACTION: GET USER LIVE DATA
if ($action == 'get_user_live_data') {
    ob_clean();
    $uid = isset($_GET['uid']) ? $_GET['uid'] : '';
    $saldo = $conn->query("SELECT saldo FROM profiles WHERE id = '$uid'")->fetchColumn();
    $my_res = $conn->query("SELECT created_at FROM reservasi WHERE user_id = '$uid' AND status = 'pending' AND created_at >= (NOW() - INTERVAL '60 seconds')")->fetch(PDO::FETCH_ASSOC);
    $time_left = 0; $has_pending = false;
    if ($my_res && !empty($my_res['created_at'])) {
        $clean_date = substr($my_res['created_at'], 0, 19); $elapsed = time() - strtotime($clean_date); $time_left = 60 - $elapsed;
        if ($time_left < 0) $time_left = 0; $has_pending = true;
    }
    $tiket_aktif = $conn->prepare("SELECT r.*, s.slot_nomor FROM reservasi r JOIN slot s ON r.slot_id = s.id WHERE r.user_id = ? AND (r.status = 'check-in' OR (r.status = 'pending' AND r.created_at >= (NOW() - INTERVAL '60 seconds'))) ORDER BY r.created_at DESC");
    $tiket_aktif->execute([$uid]);
    $tiket = $tiket_aktif->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tiket as &$t) { $t['tgl_format'] = date('d M Y, H:i', strtotime($t['created_at'])) . ' WIB'; }
    echo json_encode(['saldo' => (int)$saldo, 'time_left' => $time_left, 'has_pending' => $has_pending, 'tiket' => $tiket]); exit;
}

// 8. ACTION: GET DASHBOARD DATA LIVE
if ($action == 'get_dashboard_data') {
    ob_clean();
    $limit = 10; $page = isset($_GET['p']) ? (int)$_GET['p'] : 1; $page = ($page < 1) ? 1 : $page; $offset = ($page - 1) * $limit;
    $filter_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : '';
    $filter_tgl = isset($_GET['tgl']) ? $_GET['tgl'] : '';
    
    $sort_col = isset($_GET['sort_col']) ? $_GET['sort_col'] : 'created_at';
    $sort_dir = (isset($_GET['sort_dir']) && strtolower($_GET['sort_dir']) == 'asc') ? 'ASC' : 'DESC';
    $allowed_cols = ['created_at' => 't.created_at', 'nama' => 'p.nama', 'tipe' => 't.tipe', 'jumlah' => 't.jumlah'];
    $order_by_sql = isset($allowed_cols[$sort_col]) ? $allowed_cols[$sort_col] : 't.created_at';

    $where_clauses = []; $params = [];
    if ($filter_tipe && in_array($filter_tipe, ['topup', 'reservasi', 'parkir', 'checkout', 'batal', 'hangus', 'penalty'])) {
        $where_clauses[] = "t.tipe = :tipe"; $params[':tipe'] = $filter_tipe;
    }
    if ($filter_tgl) {
        $where_clauses[] = "t.created_at >= :tgl_start AND t.created_at <= :tgl_end";
        $params[':tgl_start'] = $filter_tgl . " 00:00:00"; $params[':tgl_end'] = $filter_tgl . " 23:59:59";
    }

    $where_sql = ""; if (count($where_clauses) > 0) { $where_sql = " WHERE " . implode(" AND ", $where_clauses); }
    $total_user = $conn->query("SELECT COUNT(*) FROM profiles WHERE role = 'user'")->fetchColumn();
    $total_saldo = $conn->query("SELECT SUM(saldo) FROM profiles WHERE role = 'user'")->fetchColumn();

    $q_count = "SELECT COUNT(*) FROM transaksi t" . $where_sql;
    $stmt_count = $conn->prepare($q_count);
    foreach($params as $key => $val) { $stmt_count->bindValue($key, $val); }
    $stmt_count->execute();
    $total_transaksi = $stmt_count->fetchColumn();
    $total_pages = ceil($total_transaksi / $limit);

    $q_trx = "SELECT t.*, p.nama, p.plat_nomor FROM transaksi t JOIN profiles p ON t.user_id = p.id" . $where_sql . " ORDER BY " . $order_by_sql . " " . $sort_dir . " LIMIT :limit OFFSET :offset";
    $stmt_trx = $conn->prepare($q_trx);
    foreach($params as $key => $val) { $stmt_trx->bindValue($key, $val); }
    $stmt_trx->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_trx->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt_trx->execute();
    $trx = $stmt_trx->fetchAll(PDO::FETCH_ASSOC);

    $hari_arr = ['Sunday'=>'Minggu', 'Monday'=>'Senin', 'Tuesday'=>'Selasa', 'Wednesday'=>'Rabu', 'Thursday'=>'Kamis', 'Friday'=>'Jumat', 'Saturday'=>'Sabtu'];
    foreach ($trx as &$t) {
        $h_en = date('l', strtotime($t['created_at']));
        $t['hari_indo'] = $hari_arr[$h_en]; $t['tgl_indo'] = date('d M Y', strtotime($t['created_at'])); $t['jam_indo'] = date('H:i', strtotime($t['created_at']));
    }

    echo json_encode(['total_user' => (int)$total_user, 'total_saldo' => (int)$total_saldo, 'total_pages' => $total_pages, 'current_page' => $page, 'transactions' => $trx]); exit;
}

// 9. ACTION: UPDATE DARI SENSOR IR (HARDWARE ESP32) - OPTIMASI ANTI LAG
if ($action == 'update_hardware_slots') {

    ob_clean();

    try {
        for ($i = 1; $i <= 4; $i++) {

            $key = 's' . $i;

            if (isset($_GET[$key])) {

                $val = ($_GET[$key] == '1') ? 'true' : 'false';

                $stmt = $conn->prepare("
                    UPDATE slot
                    SET terisi = CAST(? AS boolean)
                    WHERE slot_nomor = ?
                    AND terisi IS DISTINCT FROM CAST(? AS boolean)
                ");

                $stmt->execute([
                    $val,
                    $i,
                    $val
                ]);
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Database slot berhasil diupdate oleh hardware'
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

// 10. ACTION: DELETE USER (KHUSUS ADMIN)
if ($action == 'delete_user') {
    ob_clean();
    if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') { 
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak!']); exit; 
    }
    
    $target_uid = $_POST['user_id'];
    
    // Keamanan: Admin tidak boleh menghapus dirinya sendiri
    if ($target_uid == $_SESSION['user_id']) { 
        echo json_encode(['status' => 'error', 'message' => 'Anda tidak bisa menghapus akun Anda sendiri!']); exit; 
    }
    
    try {
        $conn->beginTransaction();
        
        // Hapus juga riwayat transaksi dan reservasi milik user ini agar tidak error
        $conn->prepare("DELETE FROM reservasi WHERE user_id = ?")->execute([$target_uid]);
        $conn->prepare("DELETE FROM transaksi WHERE user_id = ?")->execute([$target_uid]);
        
        // Terakhir, hapus data profil utamanya
        $stmt = $conn->prepare("DELETE FROM profiles WHERE id = ?");
        $stmt->execute([$target_uid]);
        
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Pengguna dan seluruh datanya berhasil dihapus!']);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
    }
    exit;
}
?>
