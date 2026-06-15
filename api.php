<?php

error_reporting(0);
ob_start();
session_start();
require 'db_config.php';
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    exit;
}

// =====================================================
// MQTT CONFIG HIVEMQ CLOUD
// Untuk localhost boleh isi langsung.
// Untuk Azure/GitHub, simpan di Application Settings.
// =====================================================
define('MQTT_HOST', getenv('MQTT_HOST') ?: '07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud');
define('MQTT_PORT', intval(getenv('MQTT_PORT') ?: 8883));
define('MQTT_USER', getenv('MQTT_USER') ?: 'Rifki');
define('MQTT_PASS', getenv('MQTT_PASS') ?: 'Kitaaja123');

define('AUTO_RELEASE_SECONDS', intval(getenv('AUTO_RELEASE_SECONDS') ?: 60));
define('TOTAL_SLOT', intval(getenv('TOTAL_SLOT') ?: 4));

// =====================================================
// MQTT PUBLISH TANPA LIBRARY EKSTERNAL
// QoS 0, TLS port 8883.
// =====================================================
function mqttPackString($string) {
    return pack('n', strlen($string)) . $string;
}

function mqttEncodeLength($length) {
    $encoded = '';
    do {
        $digit = $length % 128;
        $length = intdiv($length, 128);
        if ($length > 0) {
            $digit = $digit | 0x80;
        }
        $encoded .= chr($digit);
    } while ($length > 0);

    return $encoded;
}

function mqttPublish($topic, $payload) {
    if (
        MQTT_USER === 'ISI_USERNAME_HIVEMQ' ||
        MQTT_PASS === 'ISI_PASSWORD_HIVEMQ' ||
        empty(MQTT_USER) ||
        empty(MQTT_PASS)
    ) {
        return false;
    }

    // Client ID MQTT dibuat otomatis, tidak perlu diisi manual.
    $clientId = 'api_php_' . substr(md5(uniqid('', true)), 0, 12);

    $variableHeader = mqttPackString('MQTT') . chr(4) . chr(0xC2) . pack('n', 60);
    $connectPayload = mqttPackString($clientId) . mqttPackString(MQTT_USER) . mqttPackString(MQTT_PASS);

    $connectPacket = chr(0x10) .
        mqttEncodeLength(strlen($variableHeader) + strlen($connectPayload)) .
        $variableHeader .
        $connectPayload;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ]
    ]);

    $socket = @stream_socket_client(
        'tls://' . MQTT_HOST . ':' . MQTT_PORT,
        $errno,
        $errstr,
        4,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 4);

    fwrite($socket, $connectPacket);
    $response = fread($socket, 4);

    if (strlen($response) < 4 || ord($response[0]) !== 0x20 || ord($response[3]) !== 0x00) {
        fclose($socket);
        return false;
    }

    $publishPayload = mqttPackString($topic) . $payload;
    $publishPacket = chr(0x30) . mqttEncodeLength(strlen($publishPayload)) . $publishPayload;

    fwrite($socket, $publishPacket);
    fwrite($socket, chr(0xE0) . chr(0x00));
    fclose($socket);

    return true;
}

function publishMqttEvent($topic, $data = []) {
    $data['source'] = isset($data['source']) ? $data['source'] : 'api.php';
    $data['api_time'] = date('Y-m-d H:i:s');

    return mqttPublish(
        $topic,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function jsonOut($data) {
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestId($prefix = 'api') {
    return $prefix . '-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
}

function cutoffExpiredSql() {
    return "(now() at time zone 'Asia/Jakarta') - interval '" . AUTO_RELEASE_SECONDS . " seconds'";
}

function publishCurrentSlotState($conn, $source = 'api.php') {
    try {
        $slots = $conn->query("select id, slot_nomor, terisi from slot order by slot_nomor::int asc limit " . TOTAL_SLOT)->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->query("select slot_id, user_id, status, kode_booking from reservasi where status = 'check-in' or (status = 'pending' and created_at >= " . cutoffExpiredSql() . ")");
        $resAktif = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($resAktif as $r) {
            $map[$r['slot_id']] = $r;
        }

        $payloadSlots = [];
        foreach ($slots as $s) {
            $terisi = ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1);
            $status = 'kosong';

            if ($terisi) {
                $status = 'terisi';
            } elseif (isset($map[$s['id']])) {
                $status = ($map[$s['id']]['status'] == 'pending') ? 'reserved' : 'check-in';
            }

            $payloadSlots[] = [
                'slot_id' => (int)$s['id'],
                'slot_nomor' => (int)$s['slot_nomor'],
                'terisi' => $terisi,
                'status' => $status
            ];
        }

        return publishMqttEvent('smartparking/server/slot/state', [
            'event' => 'slot_state',
            'source' => $source,
            'slots' => $payloadSlots
        ]);
    } catch (Exception $e) {
        return false;
    }
}

// =====================================================
// ACTION
// =====================================================
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Bypass device/API testing
if (in_array($action, ['get_slots', 'get_slots_admin', 'get_user_live_data', 'get_user_trx', 'get_dashboard_data']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = isset($_GET['uid']) ? $_GET['uid'] : 'esp32_device';
    $_SESSION['role'] = 'admin';
}

// 1. TEST MQTT
if ($action == 'mqtt_test') {
    $ok = publishMqttEvent('smartparking/server/test', [
        'event' => 'mqtt_test',
        'status' => 'success',
        'message' => 'API berhasil publish ke HiveMQ',
        'request_id' => requestId('mqtt-test')
    ]);

    jsonOut([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'MQTT publish berhasil. Cek MQTTX topic smartparking/#' : 'MQTT publish gagal. Cek credential HiveMQ.',
    ]);
}

// 2. RESERVASI SLOT VIA MQTT
if ($action == 'book_slot') {
    $uid = isset($_POST['user_id']) ? $_POST['user_id'] : (isset($_GET['user_id']) ? $_GET['user_id'] : '');
    $slot_nomor = isset($_POST['slot_nomor']) ? $_POST['slot_nomor'] : (isset($_GET['slot_nomor']) ? $_GET['slot_nomor'] : '');
    $rid = isset($_POST['request_id']) ? $_POST['request_id'] : requestId('res');

    $ok = publishMqttEvent('smartparking/web/reservation/create', [
        'event' => 'reservation_create',
        'request_id' => $rid,
        'user_id' => $uid,
        'slot_nomor' => (int)$slot_nomor
    ]);

    jsonOut([
        'status' => $ok ? 'accepted' : 'error',
        'message' => $ok ? 'Permintaan reservasi dikirim ke MQTT. Tunggu respons smartparking/server/reservation/response.' : 'Gagal publish MQTT.',
        'request_id' => $rid
    ]);
}

// 3. CANCEL RESERVASI VIA MQTT
if ($action == 'cancel_booking') {
    $kode = isset($_POST['kode_booking']) ? $_POST['kode_booking'] : (isset($_GET['kode_booking']) ? $_GET['kode_booking'] : '');
    $uid = isset($_POST['user_id']) ? $_POST['user_id'] : (isset($_GET['user_id']) ? $_GET['user_id'] : '');
    $rid = isset($_POST['request_id']) ? $_POST['request_id'] : requestId('cancel');

    $ok = publishMqttEvent('smartparking/web/reservation/cancel', [
        'event' => 'reservation_cancel',
        'request_id' => $rid,
        'user_id' => $uid,
        'kode_booking' => $kode
    ]);

    jsonOut([
        'status' => $ok ? 'accepted' : 'error',
        'message' => $ok ? 'Permintaan cancel dikirim ke MQTT.' : 'Gagal publish MQTT.',
        'request_id' => $rid
    ]);
}

// 4. TOP UP VIA MQTT
if ($action == 'topup_create') {
    $uid = isset($_POST['user_id']) ? $_POST['user_id'] : (isset($_GET['user_id']) ? $_GET['user_id'] : '');
    $jumlah = isset($_POST['jumlah']) ? $_POST['jumlah'] : (isset($_GET['jumlah']) ? $_GET['jumlah'] : 0);
    $rid = isset($_POST['request_id']) ? $_POST['request_id'] : requestId('topup');

    $ok = publishMqttEvent('smartparking/web/topup/create', [
        'event' => 'topup_create',
        'request_id' => $rid,
        'user_id' => $uid,
        'jumlah' => (int)$jumlah
    ]);

    jsonOut([
        'status' => $ok ? 'accepted' : 'error',
        'message' => $ok ? 'Permintaan top up dikirim ke MQTT.' : 'Gagal publish MQTT.',
        'request_id' => $rid
    ]);
}

// 5. TEST SCAN GATE VIA MQTT DARI BROWSER/POSTMAN
if ($action == 'gate_scan') {
    $qr = isset($_POST['qr_code']) ? trim($_POST['qr_code']) : (isset($_GET['qr_code']) ? trim($_GET['qr_code']) : '');
    $gate = isset($_POST['gate']) ? trim($_POST['gate']) : (isset($_GET['gate']) ? trim($_GET['gate']) : 'in');
    $rid = isset($_POST['request_id']) ? $_POST['request_id'] : requestId('qr-' . $gate);

    $topic = ($gate == 'out') ? 'smartparking/esp32/gate/out/scan' : 'smartparking/esp32/gate/in/scan';
    $ok = publishMqttEvent($topic, [
        'event' => 'gate_scan_test_from_api',
        'request_id' => $rid,
        'gate' => $gate,
        'qr_code' => $qr,
        'sent_at' => round(microtime(true) * 1000)
    ]);

    jsonOut([
        'status' => $ok ? 'accepted' : 'error',
        'message' => $ok ? 'Scan QR dikirim ke MQTT. Tunggu respons gate dari mqttreceiver.js.' : 'Gagal publish MQTT.',
        'request_id' => $rid
    ]);
}

// 6. TEST UPDATE SLOT VIA MQTT DARI BROWSER/POSTMAN
if ($action == 'update_hardware_slots') {
    $slots = [];
    for ($i = 1; $i <= TOTAL_SLOT; $i++) {
        $key = 's' . $i;
        if (isset($_GET[$key])) {
            $terisi = ($_GET[$key] == '1');
            $slots[] = [
                'slot_nomor' => $i,
                'terisi' => $terisi,
                'status' => $terisi ? 'terisi' : 'tersedia'
            ];
        }
    }

    $mid = requestId('slot-api');
    $ok = publishMqttEvent('smartparking/esp32/slot/update', [
        'event' => 'slot_update_test_from_api',
        'device_id' => 'api_test',
        'message_id' => $mid,
        'slots' => $slots,
        'sent_at' => round(microtime(true) * 1000)
    ]);

    jsonOut([
        'status' => $ok ? 'accepted' : 'error',
        'message' => $ok ? 'Update slot dikirim ke MQTT.' : 'Gagal publish MQTT.',
        'message_id' => $mid,
        'slots' => $slots
    ]);
}

// 7. PUBLISH CURRENT SLOT STATE MANUAL
if ($action == 'publish_slot_state') {
    $ok = publishCurrentSlotState($conn, 'api_manual');
    jsonOut([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Slot state dipublish ke MQTT.' : 'Gagal publish slot state.'
    ]);
}

// 8. GET SLOTS UNTUK BOOTSTRAP WEBSITE
if ($action == 'get_slots') {
    $uid = isset($_GET['uid']) ? $_GET['uid'] : '';

    $slots = $conn->query("select * from slot order by slot_nomor::int asc limit " . TOTAL_SLOT)->fetchAll(PDO::FETCH_ASSOC);

    $stmt_res = $conn->query("select slot_id, user_id, status, kode_booking from reservasi where status = 'check-in' or (status = 'pending' and created_at >= " . cutoffExpiredSql() . ")");
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
        $terisi = ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1);

        if ($terisi) {
            if (isset($map[$s['id']]) && $map[$s['id']]['uid'] == $uid && $map[$s['id']]['status'] == 'check-in') {
                $state = 'terisi_me';
            } else {
                $state = 'terisi';
            }
        } elseif (isset($map[$s['id']])) {
            $is_me = ($map[$s['id']]['uid'] == $uid);
            $r_status = $map[$s['id']]['status'];
            $state = ($r_status == 'pending') ? ($is_me ? 'reserved_me' : 'reserved_other') : ($is_me ? 'reserved_me' : 'reserved_other');
        }

        $result[] = [
            'slot_nomor' => (int)$s['slot_nomor'],
            'state' => $state
        ];
    }

    jsonOut($result);
}

// 9. GET SLOTS ADMIN
if ($action == 'get_slots_admin') {
    $slots = $conn->query("select * from slot order by slot_nomor::int asc limit " . TOTAL_SLOT)->fetchAll(PDO::FETCH_ASSOC);

    $stmt_info = $conn->query("select r.slot_id, r.status, r.created_at, r.kode_booking, p.nama, p.plat_nomor from reservasi r join profiles p on r.user_id = p.id where r.status = 'check-in' or (r.status = 'pending' and r.created_at >= " . cutoffExpiredSql() . ")");
    $res_info = $stmt_info->fetchAll(PDO::FETCH_ASSOC);

    $info_map = [];
    foreach($res_info as $ri) {
        $info_map[$ri['slot_id']] = $ri;
    }

    $kapasitas_terpakai = 0;
    $result = [];

    foreach($slots as $s) {
        $terisi = ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1);
        $state = 'slot-free';
        $user_data = null;

        if ($terisi) {
            $kapasitas_terpakai++;
            $state = 'slot-occupied';
            if (isset($info_map[$s['id']]) && $info_map[$s['id']]['status'] == 'check-in') {
                $ri = $info_map[$s['id']];
                $user_data = ['nama' => htmlspecialchars($ri['nama']), 'plat_nomor' => htmlspecialchars($ri['plat_nomor']), 'is_parkir' => true];
            } else {
                $user_data = ['nama' => 'Tidak Diketahui', 'plat_nomor' => 'TIDAK VALID', 'is_parkir' => true];
            }
        } elseif (isset($info_map[$s['id']])) {
            $kapasitas_terpakai++;
            $ri = $info_map[$s['id']];
            $state = 'slot-reserved-admin';
            $sisa = AUTO_RELEASE_SECONDS - (time() - strtotime(substr($ri['created_at'], 0, 19)));
            if ($sisa < 0) $sisa = 0;
            $user_data = ['nama' => htmlspecialchars($ri['nama']), 'plat_nomor' => htmlspecialchars($ri['plat_nomor']), 'sisa_waktu' => $sisa, 'is_parkir' => false, 'status' => $ri['status']];
        }

        $result[] = ['slot_nomor' => (int)$s['slot_nomor'], 'state' => $state, 'user_data' => $user_data];
    }

    jsonOut(['slots' => $result, 'terpakai' => $kapasitas_terpakai, 'total' => count($slots)]);
}

// 10. GET USER LIVE DATA
if ($action == 'get_user_live_data') {
    $uid = isset($_GET['uid']) ? $_GET['uid'] : '';

    $stmt_saldo = $conn->prepare("select saldo from profiles where id = ?");
    $stmt_saldo->execute([$uid]);
    $saldo = $stmt_saldo->fetchColumn();

    $stmt_my_res = $conn->prepare("select created_at from reservasi where user_id = ? and status = 'pending' and created_at >= " . cutoffExpiredSql() . " limit 1");
    $stmt_my_res->execute([$uid]);
    $my_res = $stmt_my_res->fetch(PDO::FETCH_ASSOC);

    $time_left = 0;
    $has_pending = false;
    if ($my_res && !empty($my_res['created_at'])) {
        $elapsed = time() - strtotime(substr($my_res['created_at'], 0, 19));
        $time_left = AUTO_RELEASE_SECONDS - $elapsed;
        if ($time_left < 0) $time_left = 0;
        $has_pending = true;
    }

    $tiket_aktif = $conn->prepare("select r.*, s.slot_nomor from reservasi r join slot s on r.slot_id = s.id where r.user_id = ? and (r.status = 'check-in' or (r.status = 'pending' and r.created_at >= " . cutoffExpiredSql() . ")) order by r.created_at desc");
    $tiket_aktif->execute([$uid]);
    $tiket = $tiket_aktif->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tiket as &$t) {
        $t['tgl_format'] = date('d M Y, H:i', strtotime($t['created_at'])) . ' WIB';
        $t['qr_url'] = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($t['kode_booking']);
    }

    jsonOut(['saldo' => (int)$saldo, 'time_left' => $time_left, 'has_pending' => $has_pending, 'tiket' => $tiket]);
}

// 11. GET USER TRANSACTIONS
if ($action == 'get_user_trx') {
    $target_uid = isset($_GET['user_id']) ? $_GET['user_id'] : '';
    $stmt = $conn->prepare("select * from transaksi where user_id = ? order by created_at asc");
    $stmt->execute([$target_uid]);
    $trx = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hari_arr = ['Sunday'=>'Minggu', 'Monday'=>'Senin', 'Tuesday'=>'Selasa', 'Wednesday'=>'Rabu', 'Thursday'=>'Kamis', 'Friday'=>'Jumat', 'Saturday'=>'Sabtu'];
    foreach ($trx as &$t) {
        $h_en = date('l', strtotime($t['created_at']));
        $t['hari_indo'] = $hari_arr[$h_en];
        $t['tgl_indo'] = date('d M Y', strtotime($t['created_at']));
        $t['jam_indo'] = date('H:i', strtotime($t['created_at']));
    }

    jsonOut($trx);
}

// 12. GET DASHBOARD DATA
if ($action == 'get_dashboard_data') {
    $limit = 10;
    $page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $page = ($page < 1) ? 1 : $page;
    $offset = ($page - 1) * $limit;

    $total_user = $conn->query("select count(*) from profiles where role = 'user'")->fetchColumn();
    $total_saldo = $conn->query("select coalesce(sum(saldo),0) from profiles where role = 'user'")->fetchColumn();
    $total_transaksi = $conn->query("select count(*) from transaksi")->fetchColumn();
    $total_pages = ceil($total_transaksi / $limit);

    $stmt = $conn->prepare("select t.*, p.nama, p.plat_nomor from transaksi t join profiles p on t.user_id = p.id order by t.created_at desc limit :limit offset :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $trx = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hari_arr = ['Sunday'=>'Minggu', 'Monday'=>'Senin', 'Tuesday'=>'Selasa', 'Wednesday'=>'Rabu', 'Thursday'=>'Kamis', 'Friday'=>'Jumat', 'Saturday'=>'Sabtu'];
    foreach ($trx as &$t) {
        $h_en = date('l', strtotime($t['created_at']));
        $t['hari_indo'] = $hari_arr[$h_en];
        $t['tgl_indo'] = date('d M Y', strtotime($t['created_at']));
        $t['jam_indo'] = date('H:i', strtotime($t['created_at']));
    }

    jsonOut([
        'total_user' => (int)$total_user,
        'total_saldo' => (int)$total_saldo,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'transactions' => $trx
    ]);
}

jsonOut(['status' => 'error', 'message' => 'Action tidak dikenal atau belum diisi.']);
?>
