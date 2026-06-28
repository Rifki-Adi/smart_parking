<?php
error_reporting(0);
ob_start();
session_start();
require 'db_config.php';

// Optional: dipakai kalau kamu sudah menambahkan realtime MQTT helper.
// Kalau file belum ada, api.php tetap berjalan normal.
if (file_exists(__DIR__ . '/mqtt_helper.php')) {
    require_once __DIR__ . '/mqtt_helper.php';
}

date_default_timezone_set('Asia/Jakarta');

// Auto release reservasi: 5 menit = 300 detik
const AUTO_RELEASE_SECONDS = 300;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Jika request berasal dari mqtt_bridge.php, API tidak perlu publish MQTT lagi.
// Ini mencegah koneksi MQTT ganda dari Azure yang bikin QR scan timeout/lemot.
function isSilentRealtimeRequest(): bool {
    return (isset($_GET['silent']) && $_GET['silent'] == '1')
        || (isset($_POST['silent']) && $_POST['silent'] == '1');
}

// =============================================
// CLEANUP OTOMATIS RESERVASI EXPIRED
// Pending lebih dari 5 menit akan dihapus,
// sehingga slot otomatis kembali tersedia.
// =============================================
function cleanupExpiredReservations($conn) {
    try {
        $expiredAt = date('Y-m-d H:i:s', time() - AUTO_RELEASE_SECONDS);

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

        if ($stmt->rowCount() > 0) {
            publishRealtimeSafe($conn, 'reservation_expired', ['reason' => 'auto_release']);
        }

    } catch (Exception $e) {
        // Cleanup jangan sampai bikin API utama gagal.
    }
}


// =============================================
// HELPER REALTIME MQTT (AMAN JIKA MQTT_HELPER TIDAK ADA)
// =============================================
function publishRealtimeSafe($conn, $event, $extra = []) {
    // Untuk request dari bridge MQTT, jangan publish MQTT dari api.php lagi.
    // Bridge akan publish gate_response, slot_state, dan server_event sendiri.
    if (function_exists('isSilentRealtimeRequest') && isSilentRealtimeRequest()) {
        return;
    }

    try {
        if (function_exists('smartparking_publish_refresh')) {
            smartparking_publish_refresh($conn, $event, 'api.php', $extra);
        }
    } catch (Throwable $e) {
        // Jangan sampai MQTT membuat API utama gagal atau lambat.
    }
}

// =============================================
// HELPER RIWAYAT SLOT
// Struktur tabel yang dipakai:
// id, slot_id, reservasi_id, user_id, tipe_akses, kode_akses,
// waktu_mulai, waktu_selesai, durasi_menit, status, keterangan, created_at
//
// Catatan konsep:
// - QR reservasi (PK-xxxx)  => reservasi_id TERISI, tipe_akses = reservasi
// - QR permanen (STIKER-)  => reservasi_id NULL, tipe_akses = permanen
// =============================================
function catatRiwayatSlotReservasiMulai($conn, $slot_id, $reservasi_id, $user_id, $kode_akses, $keterangan) {
    try {
        $cek = $conn->prepare("
            SELECT id
            FROM riwayat_slot
            WHERE reservasi_id = ?
            AND tipe_akses = 'reservasi'
            AND status = 'aktif'
            LIMIT 1
        ");
        $cek->execute([$reservasi_id]);
        if ($cek->fetch()) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO riwayat_slot
            (slot_id, reservasi_id, user_id, tipe_akses, kode_akses, waktu_mulai, status, keterangan, created_at)
            VALUES (?, ?, ?, 'reservasi', ?, NOW(), 'aktif', ?, NOW())
        ");

        $stmt->execute([
            $slot_id,
            $reservasi_id,
            $user_id,
            $kode_akses,
            $keterangan
        ]);
    } catch (Exception $e) {
        // Jangan sampai pencatatan riwayat membuat gate gagal.
    }
}

function catatRiwayatSlotPermanenMulai($conn, $slot_id, $user_id, $kode_akses, $keterangan) {
    try {
        $cek = $conn->prepare("
            SELECT id
            FROM riwayat_slot
            WHERE user_id = ?
            AND tipe_akses = 'permanen'
            AND status = 'aktif'
            LIMIT 1
        ");
        $cek->execute([$user_id]);
        if ($cek->fetch()) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO riwayat_slot
            (slot_id, reservasi_id, user_id, tipe_akses, kode_akses, waktu_mulai, status, keterangan, created_at)
            VALUES (?, NULL, ?, 'permanen', ?, NOW(), 'aktif', ?, NOW())
        ");

        $stmt->execute([
            $slot_id,
            $user_id,
            $kode_akses,
            $keterangan
        ]);
    } catch (Exception $e) {
        // Jangan sampai pencatatan riwayat membuat gate gagal.
    }
}

function catatRiwayatSlotSelesaiReservasi($conn, $reservasi_id, $keterangan) {
    try {
        $stmt = $conn->prepare("
            UPDATE riwayat_slot
            SET
                waktu_selesai = NOW(),
                durasi_menit = GREATEST(
                    1,
                    CEIL(EXTRACT(EPOCH FROM (NOW() - waktu_mulai)) / 60.0)::int
                ),
                status = 'selesai',
                keterangan = ?
            WHERE reservasi_id = ?
            AND tipe_akses = 'reservasi'
            AND status = 'aktif'
        ");

        $stmt->execute([
            $keterangan,
            $reservasi_id
        ]);
    } catch (Exception $e) {
        // Jangan sampai pencatatan riwayat membuat gate gagal.
    }
}

function catatRiwayatSlotSelesaiPermanen($conn, $user_id, $keterangan) {
    try {
        $stmt = $conn->prepare("
            WITH target AS (
                SELECT id
                FROM riwayat_slot
                WHERE user_id = ?
                AND tipe_akses = 'permanen'
                AND status = 'aktif'
                ORDER BY waktu_mulai DESC
                LIMIT 1
            )
            UPDATE riwayat_slot
            SET
                waktu_selesai = NOW(),
                durasi_menit = GREATEST(
                    1,
                    CEIL(EXTRACT(EPOCH FROM (NOW() - waktu_mulai)) / 60.0)::int
                ),
                status = 'selesai',
                keterangan = ?
            WHERE id IN (SELECT id FROM target)
        ");

        $stmt->execute([
            $user_id,
            $keterangan
        ]);
    } catch (Exception $e) {
        // Jangan sampai pencatatan riwayat membuat gate gagal.
    }
}


// =============================================
// HELPER RIWAYAT SLOT WAJIB (DEBUGGING)
// Versi ini tidak menyembunyikan error, supaya kalau struktur tabel salah langsung terlihat.
// =============================================
function catatRiwayatSlotReservasiMulaiWajib($conn, $slot_id, $reservasi_id, $user_id, $kode_akses, $keterangan) {
    // Kunci transaksi per user agar scan dobel yang masuk hampir bersamaan tidak membuat 2 riwayat aktif.
    $lock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
    $lock->execute([(string)$user_id]);

    // Satu user hanya boleh punya satu riwayat aktif, baik dari QR reservasi maupun QR permanen.
    $cekAktif = $conn->prepare("
        SELECT id FROM riwayat_slot
        WHERE user_id = ?
        AND status = 'aktif'
        LIMIT 1
    ");
    $cekAktif->execute([$user_id]);
    if ($cekAktif->fetch()) {
        return;
    }

    $cekReservasi = $conn->prepare("
        SELECT id FROM riwayat_slot
        WHERE reservasi_id = ?
        AND tipe_akses = 'reservasi'
        AND status = 'aktif'
        LIMIT 1
    ");
    $cekReservasi->execute([$reservasi_id]);
    if ($cekReservasi->fetch()) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO riwayat_slot
        (slot_id, reservasi_id, user_id, tipe_akses, kode_akses, waktu_mulai, status, keterangan, created_at)
        VALUES (?, ?, ?, 'reservasi', ?, NOW(), 'aktif', ?, NOW())
        ON CONFLICT DO NOTHING
    ");
    $stmt->execute([$slot_id, $reservasi_id, $user_id, $kode_akses, $keterangan]);
}

function catatRiwayatSlotReservasiSelesaiWajib($conn, $reservasi_id, $keterangan) {
    $stmt = $conn->prepare(""
        . "UPDATE riwayat_slot SET "
        . "waktu_selesai = NOW(), "
        . "durasi_menit = GREATEST(1, CEIL(EXTRACT(EPOCH FROM (NOW() - waktu_mulai)) / 60.0)::int), "
        . "status = 'selesai', "
        . "keterangan = ? "
        . "WHERE reservasi_id = ? AND tipe_akses = 'reservasi' AND status = 'aktif'"
    );
    $stmt->execute([$keterangan, $reservasi_id]);
}

function catatRiwayatSlotPermanenMulaiWajib($conn, $slot_id, $user_id, $kode_akses, $keterangan) {
    // Kunci transaksi per user agar scan dobel yang masuk hampir bersamaan tidak membuat 2 riwayat aktif.
    $lock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
    $lock->execute([(string)$user_id]);

    // Satu user hanya boleh punya satu riwayat aktif. Ini yang mencegah double scan masuk.
    $cek = $conn->prepare("
        SELECT id FROM riwayat_slot
        WHERE user_id = ?
        AND status = 'aktif'
        LIMIT 1
    ");
    $cek->execute([$user_id]);
    if ($cek->fetch()) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO riwayat_slot
        (slot_id, reservasi_id, user_id, tipe_akses, kode_akses, waktu_mulai, status, keterangan, created_at)
        VALUES (?, NULL, ?, 'permanen', ?, NOW(), 'aktif', ?, NOW())
        ON CONFLICT DO NOTHING
    ");
    $stmt->execute([$slot_id, $user_id, $kode_akses, $keterangan]);
}

function ambilRiwayatPermanenAktif($conn, $user_id) {
    $stmt = $conn->prepare(""
        . "SELECT rs.*, s.slot_nomor "
        . "FROM riwayat_slot rs "
        . "LEFT JOIN slot s ON rs.slot_id = s.id "
        . "WHERE rs.user_id = ? AND rs.tipe_akses = 'permanen' AND rs.status = 'aktif' "
        . "ORDER BY rs.waktu_mulai DESC "
        . "LIMIT 1"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function catatRiwayatSlotPermanenSelesaiWajib($conn, $riwayat_id, $keterangan) {
    $stmt = $conn->prepare(""
        . "UPDATE riwayat_slot SET "
        . "waktu_selesai = NOW(), "
        . "durasi_menit = GREATEST(1, CEIL(EXTRACT(EPOCH FROM (NOW() - waktu_mulai)) / 60.0)::int), "
        . "status = 'selesai', "
        . "keterangan = ? "
        . "WHERE id = ? AND tipe_akses = 'permanen' AND status = 'aktif'"
    );
    $stmt->execute([$keterangan, $riwayat_id]);
}

function assignRiwayatPermanenKeSlotBaru($conn, $slot_id) {
    $stmt = $conn->prepare(""
        . "SELECT id FROM riwayat_slot "
        . "WHERE tipe_akses = 'permanen' AND status = 'aktif' AND slot_id IS NULL "
        . "ORDER BY waktu_mulai DESC "
        . "LIMIT 1"
    );
    $stmt->execute();
    $riwayat = $stmt->fetch();

    if ($riwayat) {
        $upd = $conn->prepare("UPDATE riwayat_slot SET slot_id = ? WHERE id = ?");
        $upd->execute([$slot_id, $riwayat['id']]);
        return true;
    }

    return false;
}

function kosongkanSlotJikaAda($conn, $slot_id) {
    if ($slot_id === null || $slot_id === '' || (int)$slot_id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE slot SET terisi = false WHERE id = ?");
    $stmt->execute([(int)$slot_id]);
    return $stmt->rowCount() > 0;
}

// Ambil action lebih awal
$action = isset($_GET['action']) ? $_GET['action'] : '';
$expiredAt = date('Y-m-d H:i:s', time() - AUTO_RELEASE_SECONDS);

// Jalankan cleanup hanya di action yang berkaitan dengan slot/tiket.
// Jangan dijalankan di get_dashboard_data supaya dashboard admin tidak berat.
// Cleanup jangan dijalankan saat request gate_scan dari mqtt_bridge.php (silent=1),
// karena proses gate harus dibalas cepat ke ESP32 agar LCD tidak menampilkan Server Timeout.
// Validasi expired tetap aman karena query QR reservasi sudah mengecek created_at >= $expiredAt.
if (in_array($action, ['book_slot', 'get_slots', 'get_slots_admin', 'get_user_live_data', 'gate_scan', 'cancel_booking'])) {
    if (!($action === 'gate_scan' && isSilentRealtimeRequest())) {
        cleanupExpiredReservations($conn);
    }
}


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

// 1. ACTION: RESERVASI SLOT
if ($action == 'book_slot') {
    $uid = $_POST['user_id'];
    $slot_nomor = $_POST['slot_nomor'];
    
    if ((int)$slot_nomor > 4) { echo json_encode(['status' => 'error', 'message' => 'Slot tidak tersedia.']); exit; }

    $user_saldo = $conn->query("SELECT saldo FROM profiles WHERE id = '$uid'")->fetchColumn();
    if ($user_saldo < 8000) { echo json_encode(['status' => 'error', 'message' => 'Saldo Anda tidak cukup (Min. Rp 8.000: 5rb Booking + 3rb Gerbang). Silakan Top Up.']); exit; }
    
    $stmt_cek = $conn->prepare("SELECT id FROM reservasi WHERE user_id = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '300 seconds')))");
    $stmt_cek->execute([$uid]);
    if ($stmt_cek->fetch()) { echo json_encode(['status' => 'error', 'message' => 'Anda sudah memiliki tiket aktif atau sedang parkir!']); exit; }
    
    $slot = $conn->query("SELECT id FROM slot WHERE slot_nomor = '$slot_nomor'")->fetch();
    
    $stmt_cek_slot = $conn->prepare("SELECT id FROM reservasi WHERE slot_id = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '300 seconds')))");
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

        publishRealtimeSafe($conn, 'reservation_created', [
            'user_id' => $uid,
            'slot_nomor' => (int)$slot_nomor,
            'kode_booking' => $kode
        ]);

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

    $stmt_res = $conn->prepare("
    SELECT slot_id, user_id, status, kode_booking
    FROM reservasi
    WHERE status = 'check-in'
       OR (status = 'pending' AND created_at >= ?)
    ");
    $stmt_res->execute([$expiredAt]);
    $res_aktif = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

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

        publishRealtimeSafe($conn, 'reservation_cancelled', [
            'user_id' => $uid,
            'kode_booking' => $kode
        ]);

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
    
    $stmt_info = $conn->prepare("SELECT r.slot_id, r.status, r.created_at, r.kode_booking, p.nama, p.plat_nomor FROM reservasi r JOIN profiles p ON r.user_id = p.id WHERE r.status = 'check-in' OR (r.status = 'pending' AND r.created_at >= ?)");
    $stmt_info->execute([$expiredAt]);
    $res_info = $stmt_info->fetchAll(PDO::FETCH_ASSOC);

    $info_map = []; foreach($res_info as $ri) { $info_map[$ri['slot_id']] = $ri; }

    $stmt_active = $conn->prepare("SELECT slot_id FROM reservasi WHERE status = 'check-in' OR (status = 'pending' AND created_at >= ?)");
    $stmt_active->execute([$expiredAt]);
    $reservasi_aktif = $stmt_active->fetchAll(PDO::FETCH_COLUMN);
    
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
                $sisa = AUTO_RELEASE_SECONDS - (strtotime(date('Y-m-d H:i:s')) - strtotime(substr($ri['created_at'], 0, 19)));
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
            $stmt = $conn->prepare("SELECT * FROM reservasi WHERE kode_booking = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= ?))");
            $stmt->execute([$qr, $expiredAt]);
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

                    catatRiwayatSlotReservasiMulaiWajib(
                        $conn,
                        $booking['slot_id'],
                        $booking['id'],
                        $uid,
                        $qr,
                        'Check-in melalui QR reservasi ' . $qr
                    );

                    $conn->commit();

                    publishRealtimeSafe($conn, 'gate_checkin', [
                        'user_id' => $uid,
                        'kode_booking' => $qr,
                        'gate' => $gate,
                        'slot_id' => (int)$booking['slot_id']
                    ]);

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
                    kosongkanSlotJikaAda($conn, $booking['slot_id']);

                    catatRiwayatSlotReservasiSelesaiWajib(
                        $conn,
                        $booking['id'],
                        'Check-out melalui QR reservasi ' . $qr
                    );

                    $conn->commit();

                    publishRealtimeSafe($conn, 'gate_checkout', [
                        'user_id' => $uid,
                        'kode_booking' => $qr,
                        'gate' => $gate,
                        'slot_id' => (int)$booking['slot_id']
                    ]);

                    echo json_encode(['status' => 'success', 'message' => 'Check-out Berhasil|Saldo -Rp 3.000']); exit;
                }
            } else { echo json_encode(['status' => 'error', 'message' => 'QR Tdk Dikenali!|Atau Tiket Hangus']); exit; }
            
        } else {
            $prof = $conn->prepare("SELECT id, saldo FROM profiles WHERE qr_token_permanen = ? OR CONCAT('STIKER-', plat_nomor) = ?");
            $prof->execute([$qr, $qr]);
            $user = $prof->fetch();
            
            if ($user) {
                $uid = $user['id'];
                // 1) Cek apakah user sedang parkir dari QR reservasi.
                //    QR permanen boleh dipakai untuk keluar walaupun masuknya menggunakan QR reservasi.
                $cek_in = $conn->query("SELECT * FROM reservasi WHERE user_id = '$uid' AND status = 'check-in' ORDER BY created_at DESC LIMIT 1")->fetch();
                
                if ($cek_in) {
                    if ($gate == 'in') {
                        echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Mobil Sdh di Dalam']); 
                        exit;
                    }

                    $conn->beginTransaction();
                    $conn->query("UPDATE profiles SET saldo = saldo - 3000 WHERE id = '$uid'");
                    $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'checkout', 3000, 'Keluar / Check-Out (Pembayaran Biaya Parkir)')")->execute([$uid]);
                    $conn->query("UPDATE reservasi SET status = 'selesai' WHERE id = " . $cek_in['id']);
                    kosongkanSlotJikaAda($conn, $cek_in['slot_id']);

                    if (strpos($cek_in['kode_booking'], 'PK-') === 0) {
                        catatRiwayatSlotReservasiSelesaiWajib(
                            $conn,
                            $cek_in['id'],
                            'Check-out melalui QR permanen ' . $qr . ' untuk reservasi ' . $cek_in['kode_booking']
                        );
                    } else {
                        // Jaga-jaga untuk data lama PL- yang masih ada di tabel reservasi.
                        $riwayat_permanen = ambilRiwayatPermanenAktif($conn, $uid);
                        if ($riwayat_permanen) {
                            catatRiwayatSlotPermanenSelesaiWajib(
                                $conn,
                                $riwayat_permanen['id'],
                                'Check-out melalui QR permanen ' . $qr
                            );
                        }
                    }

                    $conn->commit();

                    publishRealtimeSafe($conn, 'gate_checkout_sticker', [
                        'user_id' => $uid,
                        'qr_code' => $qr,
                        'gate' => $gate,
                        'slot_id' => (int)$cek_in['slot_id']
                    ]);

                    echo json_encode(['status' => 'success', 'message' => 'Check-out Berhasil|Saldo -Rp 3.000']); exit;
                }

                // 2) Kalau tidak ada reservasi check-in, cek riwayat_slot permanen aktif.
                //    Ini yang dipakai untuk QR permanen tanpa reservasi.
                $riwayat_permanen = ambilRiwayatPermanenAktif($conn, $uid);

                if ($riwayat_permanen) {
                    if ($gate == 'in') {
                        echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Mobil Sdh di Dalam']); 
                        exit;
                    }

                    $conn->beginTransaction();
                    $conn->query("UPDATE profiles SET saldo = saldo - 3000 WHERE id = '$uid'");
                    $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'checkout', 3000, 'Keluar / Check-Out (Pembayaran Biaya Parkir)')")->execute([$uid]);
                    catatRiwayatSlotPermanenSelesaiWajib(
                        $conn,
                        $riwayat_permanen['id'],
                        'Check-out melalui QR permanen ' . $qr
                    );
                    kosongkanSlotJikaAda($conn, $riwayat_permanen['slot_id']);
                    $conn->commit();

                    publishRealtimeSafe($conn, 'gate_checkout_permanent', [
                        'user_id' => $uid,
                        'qr_code' => $qr,
                        'gate' => $gate,
                        'slot_id' => !empty($riwayat_permanen['slot_id']) ? (int)$riwayat_permanen['slot_id'] : null
                    ]);

                    echo json_encode(['status' => 'success', 'message' => 'Check-out Berhasil|Saldo -Rp 3.000']); exit;
                }

                // 3) Kalau gate out tetapi tidak ada data aktif, berarti memang belum masuk.
                if ($gate == 'out') {
                    echo json_encode(['status' => 'error', 'message' => 'Akses Ditolak!|Belum Scan Masuk']); 
                    exit;
                }

                // 4) Gate in QR permanen tanpa reservasi.
                $punya_pending = $conn->query("SELECT id FROM reservasi WHERE user_id = '$uid' AND status = 'pending' AND created_at >= (NOW() - INTERVAL '300 seconds')")->fetch();
                if ($punya_pending) { echo json_encode(['status' => 'error', 'message' => 'Punya Tiket Aktif!|Gunakan QR Aplikasi']); exit; }

                // Saat scan QR permanen di gerbang, sistem belum tahu mobil akan parkir di slot fisik nomor berapa.
                // Jadi jangan otomatis memilih slot 1. Slot_id riwayat dibuat NULL dulu, lalu diisi saat sensor IR mendeteksi slot sebenarnya.
                $slot_tersedia = $conn->query("
                    SELECT COUNT(*)
                    FROM slot
                    WHERE terisi = 'false'
                    AND CAST(slot_nomor AS INT) <= 4
                    AND id NOT IN (
                        SELECT slot_id FROM reservasi
                        WHERE status = 'check-in'
                           OR (status = 'pending' AND created_at >= (NOW() - INTERVAL '300 seconds'))
                    )
                    AND id NOT IN (
                        SELECT slot_id FROM riwayat_slot
                        WHERE status = 'aktif'
                        AND slot_id IS NOT NULL
                    )
                ")->fetchColumn();

                // Tambahan proteksi kapasitas:
                // Jika ada QR permanen yang sudah masuk tetapi slot_id belum terisi oleh IR,
                // tetap dianggap memakai kapasitas parkir agar gerbang tidak terbuka saat penuh.
                $permanen_menunggu_slot = $conn->query("
                    SELECT COUNT(*)
                    FROM riwayat_slot
                    WHERE status = 'aktif'
                    AND tipe_akses = 'permanen'
                    AND slot_id IS NULL
                ")->fetchColumn();

                $slot_tersedia_real = (int)$slot_tersedia - (int)$permanen_menunggu_slot;

                if ($slot_tersedia_real <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Mohon Maaf...|Parkir Penuh']);
                    exit;
                }
                
                if ($user['saldo'] < 3000) { echo json_encode(['status' => 'error', 'message' => 'Saldo Tdk Cukup!|Min. Rp 3.000']); exit; }
                
                $conn->beginTransaction();
                $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'parkir', 0, 'Masuk / Check-In Langsung QR Permanen')")->execute([$uid]);

                catatRiwayatSlotPermanenMulaiWajib(
                    $conn,
                    null,
                    $uid,
                    $qr,
                    'Check-in langsung melalui QR permanen ' . $qr . ' - menunggu deteksi slot fisik'
                );

                $conn->commit();

                publishRealtimeSafe($conn, 'gate_checkin_permanent', [
                    'user_id' => $uid,
                    'qr_code' => $qr,
                    'gate' => $gate,
                    'slot_id' => null,
                    'slot_nomor' => null,
                    'note' => 'slot_id akan diisi oleh sensor IR'
                ]);
                
                echo json_encode(['status' => 'success', 'message' => 'Akses Stiker Valid|Silakan Masuk']); exit;
            } else { echo json_encode(['status' => 'error', 'message' => 'Stiker Tdk Valid!|Atau Tdk Terdaftar']); exit; }
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log('[gate_scan] ' . $e->getMessage());
        $res = ['status' => 'error', 'message' => 'Kesalahan Sistem|Hubungi Admin'];
        if (isset($_GET['debug']) && $_GET['debug'] == '1') {
            $res['debug'] = $e->getMessage();
        }
        echo json_encode($res); exit;
    }
}

// 7. ACTION: GET USER LIVE DATA
if ($action == 'get_user_live_data') {
    ob_clean();
    $uid = isset($_GET['uid']) ? $_GET['uid'] : '';
    $saldo = $conn->query("SELECT saldo FROM profiles WHERE id = '$uid'")->fetchColumn();
    $stmt_my_res = $conn->prepare("SELECT created_at FROM reservasi WHERE user_id = ? AND status = 'pending' AND created_at >= ?");
    $stmt_my_res->execute([$uid, $expiredAt]);
    $my_res = $stmt_my_res->fetch(PDO::FETCH_ASSOC);
    $time_left = 0; $has_pending = false;
    if ($my_res && !empty($my_res['created_at'])) {
        $clean_date = substr($my_res['created_at'], 0, 19); $elapsed = time() - strtotime($clean_date); $time_left = AUTO_RELEASE_SECONDS - $elapsed;
        if ($time_left < 0) $time_left = 0; $has_pending = true;
    }
    $tiket_aktif = $conn->prepare("SELECT r.*, s.slot_nomor FROM reservasi r JOIN slot s ON r.slot_id = s.id WHERE r.user_id = ? AND (r.status = 'check-in' OR (r.status = 'pending' AND r.created_at >= ?)) ORDER BY r.created_at DESC");
    $tiket_aktif->execute([$uid, $expiredAt]);
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

// 9. ACTION: UPDATE DARI SENSOR IR (HARDWARE ESP32) - EVENT DRIVEN
if ($action == 'update_hardware_slots') {

    ob_clean();

    try {
        $changed = 0;
        $newlyOccupiedSlots = [];

        // Ambil kondisi slot sebelum update agar bisa tahu slot mana yang BARU terisi.
        $oldRows = $conn->query("SELECT id, slot_nomor, terisi FROM slot WHERE CAST(slot_nomor AS INT) <= 4 ORDER BY slot_nomor ASC")->fetchAll(PDO::FETCH_ASSOC);
        $oldMap = [];
        foreach ($oldRows as $row) {
            $oldMap[(int)$row['slot_nomor']] = [
                'id' => (int)$row['id'],
                'terisi' => ($row['terisi'] === true || $row['terisi'] === 't' || $row['terisi'] == 1)
            ];
        }

        $conn->beginTransaction();

        for ($i = 1; $i <= 4; $i++) {

            $key = 's' . $i;

            if (isset($_GET[$key])) {

                $newBool = ($_GET[$key] == '1');
                $val = $newBool ? 'true' : 'false';
                $oldBool = isset($oldMap[$i]) ? $oldMap[$i]['terisi'] : false;
                $slotId = isset($oldMap[$i]) ? $oldMap[$i]['id'] : null;

                // Jika sebelumnya kosong lalu sekarang terisi, berarti inilah slot fisik sebenarnya.
                if ($slotId && !$oldBool && $newBool) {
                    $newlyOccupiedSlots[] = $slotId;
                }

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

                $changed += $stmt->rowCount();
            }
        }

        // Hubungkan QR permanen terbaru yang belum punya slot_id dengan slot fisik yang baru terisi.
        $assigned = 0;
        foreach ($newlyOccupiedSlots as $slotId) {
            if (assignRiwayatPermanenKeSlotBaru($conn, $slotId)) {
                $assigned++;
            }
        }

        $conn->commit();

        if ($changed > 0 || $assigned > 0) {
            publishRealtimeSafe($conn, 'hardware_slot_updated', [
                'changed' => $changed,
                'assigned_permanent_history' => $assigned
            ]);
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Database slot berhasil diupdate oleh hardware',
            'changed' => $changed,
            'assigned_permanent_history' => $assigned
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
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
        
        // Hapus juga riwayat slot, transaksi, dan reservasi milik user ini agar tidak error
        $conn->prepare("DELETE FROM riwayat_slot WHERE user_id = ?")->execute([$target_uid]);
        $conn->prepare("DELETE FROM reservasi WHERE user_id = ?")->execute([$target_uid]);
        $conn->prepare("DELETE FROM transaksi WHERE user_id = ?")->execute([$target_uid]);
        
        // Terakhir, hapus data profil utamanya
        $stmt = $conn->prepare("DELETE FROM profiles WHERE id = ?");
        $stmt->execute([$target_uid]);
        
        $conn->commit();

        publishRealtimeSafe($conn, 'user_deleted', [
            'user_id' => $target_uid
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Pengguna dan seluruh datanya berhasil dihapus!']);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
    }
    exit;
}
?>
