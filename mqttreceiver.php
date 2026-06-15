<?php
// mqttreceiver.php
// Jalankan lewat terminal:
// composer install
// php mqttreceiver.php

date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/db_config.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// =====================================================
// KONFIGURASI HIVEMQ CLOUD
// =====================================================
$server   = getenv('MQTT_HOST') ?: '07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud';
$port     = intval(getenv('MQTT_PORT') ?: 8883);
$username = getenv('MQTT_USER') ?: 'Rifki';
$password = getenv('MQTT_PASS') ?: 'Kitaaja123';
$clientId = 'php_bridge_' . uniqid();

// =====================================================
// KONFIGURASI SISTEM
// =====================================================
$AUTO_RELEASE_SECONDS = intval(getenv('AUTO_RELEASE_SECONDS') ?: 60);
$PARKING_FEE          = intval(getenv('PARKING_FEE') ?: 3000);
$BOOKING_FEE          = intval(getenv('BOOKING_FEE') ?: 5000);
$MIN_BOOKING_SALDO    = intval(getenv('MIN_BOOKING_SALDO') ?: 8000);
$TOTAL_SLOT           = intval(getenv('TOTAL_SLOT') ?: 4);

$mqtt = new MqttClient($server, $port, $clientId);
$settings = (new ConnectionSettings())
    ->setUsername($username)
    ->setPassword($password)
    ->setUseTls(true)
    ->setTlsSelfSignedAllowed(true)
    ->setKeepAliveInterval(60);

try {
    $mqtt->connect($settings, true);
    echo "=================================================\n";
    echo "✅ KONEKSI BERHASIL: Terhubung ke HiveMQ Cloud!\n";
    echo "Client ID: {$clientId}\n";
    echo "=================================================\n";
} catch (Exception $e) {
    die("❌ GAGAL KONEKSI MQTT: " . $e->getMessage() . "\n");
}

function nowWibSql() {
    return "now() at time zone 'Asia/Jakarta'";
}

function publishJson($mqtt, $topic, $data, $retain = false) {
    $data['source'] = $data['source'] ?? 'mqttreceiver.php';
    $data['server_time'] = date('c');
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $mqtt->publish($topic, $payload, 0, $retain);
    echo "   -> 📤 Publish: {$topic}\n";
}

function publishSlotState($conn, $mqtt) {
    global $TOTAL_SLOT, $AUTO_RELEASE_SECONDS;

    try {
        $stmtSlots = $conn->prepare("SELECT id, slot_nomor, terisi FROM slot ORDER BY slot_nomor::int ASC LIMIT ?");
        $stmtSlots->execute([$TOTAL_SLOT]);
        $slots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

        $stmtRes = $conn->prepare("SELECT slot_id, user_id, status, kode_booking FROM reservasi WHERE status = 'check-in' OR (status = 'pending' AND created_at >= (" . nowWibSql() . " - (? || ' seconds')::interval))");
        $stmtRes->execute([$AUTO_RELEASE_SECONDS]);
        $reservasi = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($reservasi as $r) {
            $map[$r['slot_id']] = $r;
        }

        $payloadSlots = [];
        foreach ($slots as $s) {
            $terisi = ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1);
            $status = 'kosong';

            if ($terisi) {
                $status = 'terisi';
            } elseif (isset($map[$s['id']])) {
                $status = $map[$s['id']]['status'] === 'pending' ? 'reserved' : 'check-in';
            }

            $payloadSlots[] = [
                'slot_id' => intval($s['id']),
                'slot_nomor' => intval($s['slot_nomor']),
                'terisi' => $terisi,
                'status' => $status,
                'reservasi_status' => $map[$s['id']]['status'] ?? null,
                'kode_booking' => $map[$s['id']]['kode_booking'] ?? null
            ];
        }

        publishJson($mqtt, 'smartparking/server/slot/state', [
            'event' => 'slot_state',
            'slots' => $payloadSlots
        ], true);
    } catch (Exception $e) {
        echo "   -> 🔴 Gagal publish slot state: " . $e->getMessage() . "\n";
    }
}

function cleanupExpiredReservations($conn, $mqtt) {
    global $AUTO_RELEASE_SECONDS;

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("DELETE FROM reservasi WHERE status = 'pending' AND created_at < (" . nowWibSql() . " - (? || ' seconds')::interval) RETURNING id, user_id, slot_id, kode_booking");
        $stmt->execute([$AUTO_RELEASE_SECONDS]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($expired as $row) {
            $trx = $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'hangus', 0, ?)");
            $trx->execute([$row['user_id'], 'Waktu Habis Tiket ' . $row['kode_booking']]);
        }

        $conn->commit();

        if (count($expired) > 0) {
            publishJson($mqtt, 'smartparking/server/reservation/expired', [
                'event' => 'reservation_expired',
                'status' => 'expired',
                'count' => count($expired),
                'data' => $expired
            ]);
            publishSlotState($conn, $mqtt);
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo "   -> 🔴 Cleanup error: " . $e->getMessage() . "\n";
    }
}

function processReservationCreate($conn, $mqtt, $payload) {
    global $AUTO_RELEASE_SECONDS, $BOOKING_FEE, $MIN_BOOKING_SALDO, $TOTAL_SLOT;

    $requestId = $payload['request_id'] ?? 'res-' . time();
    $uid = $payload['user_id'] ?? '';
    $slotNomor = intval($payload['slot_nomor'] ?? 0);

    if (!$uid || !$slotNomor) {
        publishJson($mqtt, 'smartparking/server/reservation/response', [
            'request_id' => $requestId,
            'status' => 'error',
            'message' => 'Data reservasi tidak lengkap'
        ]);
        return;
    }

    try {
        $conn->beginTransaction();

        if ($slotNomor > $TOTAL_SLOT) {
            $conn->commit();
            publishJson($mqtt, 'smartparking/server/reservation/response', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Slot tidak tersedia']);
            return;
        }

        $stmtSaldo = $conn->prepare("SELECT saldo FROM profiles WHERE id = ? LIMIT 1");
        $stmtSaldo->execute([$uid]);
        $saldo = intval($stmtSaldo->fetchColumn() ?: 0);

        if ($saldo < $MIN_BOOKING_SALDO) {
            $conn->commit();
            publishJson($mqtt, 'smartparking/server/reservation/response', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Saldo tidak cukup']);
            return;
        }

        $stmtUserAktif = $conn->prepare("SELECT id FROM reservasi WHERE user_id = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (" . nowWibSql() . " - (? || ' seconds')::interval))) LIMIT 1");
        $stmtUserAktif->execute([$uid, $AUTO_RELEASE_SECONDS]);

        if ($stmtUserAktif->fetch()) {
            $conn->commit();
            publishJson($mqtt, 'smartparking/server/reservation/response', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Anda sudah memiliki tiket aktif atau sedang parkir']);
            return;
        }

        $stmtSlot = $conn->prepare("SELECT id, terisi FROM slot WHERE slot_nomor::int = ? LIMIT 1");
        $stmtSlot->execute([$slotNomor]);
        $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            $conn->commit();
            publishJson($mqtt, 'smartparking/server/reservation/response', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Slot tidak ditemukan']);
            return;
        }

        $slotId = $slot['id'];
        $slotTerisi = ($slot['terisi'] === true || $slot['terisi'] === 't' || $slot['terisi'] == 1);

        $stmtSlotAktif = $conn->prepare("SELECT id FROM reservasi WHERE slot_id = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (" . nowWibSql() . " - (? || ' seconds')::interval))) LIMIT 1");
        $stmtSlotAktif->execute([$slotId, $AUTO_RELEASE_SECONDS]);

        if ($stmtSlotAktif->fetch() || $slotTerisi) {
            $conn->commit();
            publishJson($mqtt, 'smartparking/server/reservation/response', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Slot sudah diambil atau terisi']);
            return;
        }

        $kode = 'PK-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

        $stmtInsert = $conn->prepare("INSERT INTO reservasi (user_id, slot_id, kode_booking, status, created_at) VALUES (?, ?, ?, 'pending', " . nowWibSql() . ") RETURNING id");
        $stmtInsert->execute([$uid, $slotId, $kode]);
        $reservasiId = $stmtInsert->fetchColumn();

        $stmtSaldoMinus = $conn->prepare("UPDATE profiles SET saldo = saldo - ? WHERE id = ?");
        $stmtSaldoMinus->execute([$BOOKING_FEE, $uid]);

        $stmtTrx = $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'reservasi', ?, 'Biaya Reservasi (Booking Slot)')");
        $stmtTrx->execute([$uid, $BOOKING_FEE]);

        $conn->commit();

        publishJson($mqtt, 'smartparking/server/reservation/response', [
            'event' => 'reservation_created',
            'request_id' => $requestId,
            'status' => 'success',
            'message' => 'Reservasi berhasil',
            'reservasi_id' => $reservasiId,
            'user_id' => $uid,
            'slot_nomor' => $slotNomor,
            'slot_id' => $slotId,
            'kode_booking' => $kode,
            'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($kode),
            'timeout_seconds' => $AUTO_RELEASE_SECONDS
        ]);

        publishSlotState($conn, $mqtt);
        echo "   -> 🟢 Reservasi tersimpan ke Supabase: {$kode}\n";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo "   -> 🔴 Reservation error: " . $e->getMessage() . "\n";
        publishJson($mqtt, 'smartparking/server/reservation/response', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Gagal memproses reservasi']);
    }
}

function processReservationCancel($conn, $mqtt, $payload) {
    $requestId = $payload['request_id'] ?? 'cancel-' . time();
    $uid = $payload['user_id'] ?? '';
    $kode = $payload['kode_booking'] ?? '';

    if (!$uid || !$kode) {
        publishJson($mqtt, 'smartparking/server/reservation/cancelled', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Data cancel tidak lengkap']);
        return;
    }

    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("DELETE FROM reservasi WHERE kode_booking = ? AND user_id = ? AND status = 'pending' RETURNING id, slot_id");
        $stmt->execute([$kode, $uid]);
        $deleted = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deleted) {
            $conn->commit();
            publishJson($mqtt, 'smartparking/server/reservation/cancelled', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Reservasi tidak ditemukan']);
            return;
        }

        $stmtTrx = $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'batal', 0, ?)");
        $stmtTrx->execute([$uid, 'Batal Manual Tiket ' . $kode]);
        $conn->commit();

        publishJson($mqtt, 'smartparking/server/reservation/cancelled', ['request_id' => $requestId, 'status' => 'success', 'message' => 'Reservasi berhasil dibatalkan', 'kode_booking' => $kode]);
        publishSlotState($conn, $mqtt);
        echo "   -> 🟢 Reservasi dibatalkan: {$kode}\n";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo "   -> 🔴 Cancel error: " . $e->getMessage() . "\n";
    }
}

function processTopupCreate($conn, $mqtt, $payload) {
    $requestId = $payload['request_id'] ?? 'topup-' . time();
    $uid = $payload['user_id'] ?? '';
    $jumlah = intval($payload['jumlah'] ?? 0);

    if (!$uid || $jumlah <= 0) {
        publishJson($mqtt, 'smartparking/server/transaction/created', ['request_id' => $requestId, 'status' => 'error', 'message' => 'Data top up tidak valid']);
        return;
    }

    try {
        $conn->beginTransaction();
        $stmtSaldo = $conn->prepare("UPDATE profiles SET saldo = saldo + ? WHERE id = ?");
        $stmtSaldo->execute([$jumlah, $uid]);
        $stmtTrx = $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'topup', ?, 'Top Up Saldo')");
        $stmtTrx->execute([$uid, $jumlah]);
        $conn->commit();

        publishJson($mqtt, 'smartparking/server/transaction/created', ['request_id' => $requestId, 'status' => 'success', 'message' => 'Top up berhasil', 'user_id' => $uid, 'jumlah' => $jumlah]);
        echo "   -> 🟢 Top up tersimpan: {$jumlah}\n";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo "   -> 🔴 Topup error: " . $e->getMessage() . "\n";
    }
}

function processSlotUpdate($conn, $mqtt, $payload) {
    $slots = $payload['slots'] ?? [];
    if (!is_array($slots) || count($slots) === 0) return;

    try {
        foreach ($slots as $item) {
            $slotNomor = intval($item['slot_nomor'] ?? 0);
            $terisi = (($item['terisi'] ?? false) === true || ($item['status'] ?? '') === 'terisi' || ($item['terisi'] ?? '') == 1);
            if ($slotNomor <= 0) continue;
            $stmt = $conn->prepare("UPDATE slot SET terisi = ? WHERE slot_nomor::int = ?");
            $stmt->execute([$terisi, $slotNomor]);
        }
        publishSlotState($conn, $mqtt);
        echo "   -> 🟢 Status slot tersimpan ke Supabase\n";
    } catch (Exception $e) {
        echo "   -> 🔴 Slot update error: " . $e->getMessage() . "\n";
    }
}

function sendGateResponse($mqtt, $gate, $requestId, $status, $message, $openGate = false, $extra = []) {
    $topic = $gate === 'out' ? 'smartparking/server/gate/out/response' : 'smartparking/server/gate/in/response';
    publishJson($mqtt, $topic, array_merge([
        'event' => 'gate_response',
        'gate' => $gate,
        'request_id' => $requestId,
        'status' => $status,
        'message' => $message,
        'open_gate' => $openGate
    ], $extra));
}

function processGateScan($conn, $mqtt, $payload, $gate) {
    global $AUTO_RELEASE_SECONDS, $PARKING_FEE;

    $qr = trim($payload['qr_code'] ?? '');
    $requestId = $payload['request_id'] ?? null;

    if ($qr === '') {
        sendGateResponse($mqtt, $gate, $requestId, 'error', 'QR kosong', false);
        return;
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM reservasi WHERE kode_booking = ? AND (status = 'check-in' OR (status = 'pending' AND created_at >= (" . nowWibSql() . " - (? || ' seconds')::interval))) LIMIT 1");
        $stmt->execute([$qr, $AUTO_RELEASE_SECONDS]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $conn->commit();
            sendGateResponse($mqtt, $gate, $requestId, 'error', 'QR Tdk Dikenali / Tiket Hangus', false);
            return;
        }

        $uid = $booking['user_id'];

        if ($booking['status'] === 'pending') {
            if ($gate === 'out') {
                $conn->commit();
                sendGateResponse($mqtt, $gate, $requestId, 'error', 'Belum Scan Masuk', false);
                return;
            }

            $stmtSaldo = $conn->prepare("SELECT saldo FROM profiles WHERE id = ? LIMIT 1");
            $stmtSaldo->execute([$uid]);
            $saldo = intval($stmtSaldo->fetchColumn() ?: 0);

            if ($saldo < $PARKING_FEE) {
                $conn->commit();
                sendGateResponse($mqtt, $gate, $requestId, 'error', 'Saldo Tdk Cukup', false);
                return;
            }

            $stmtTrx = $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'parkir', 0, 'Masuk / Check-In')");
            $stmtTrx->execute([$uid]);
            $stmtUpdate = $conn->prepare("UPDATE reservasi SET status = 'check-in' WHERE id = ?");
            $stmtUpdate->execute([$booking['id']]);
            $conn->commit();

            sendGateResponse($mqtt, $gate, $requestId, 'success', 'Reservasi Valid - Silakan Masuk', true, ['reservasi_id' => $booking['id'], 'slot_id' => $booking['slot_id']]);
            publishSlotState($conn, $mqtt);
            echo "   -> 🟢 Check-in berhasil: {$qr}\n";
            return;
        }

        if ($booking['status'] === 'check-in') {
            if ($gate === 'in') {
                $conn->commit();
                sendGateResponse($mqtt, $gate, $requestId, 'error', 'Mobil Sudah di Dalam', false);
                return;
            }

            $stmtSaldo = $conn->prepare("UPDATE profiles SET saldo = saldo - ? WHERE id = ?");
            $stmtSaldo->execute([$PARKING_FEE, $uid]);
            $stmtTrx = $conn->prepare("INSERT INTO transaksi (user_id, tipe, jumlah, keterangan) VALUES (?, 'checkout', ?, 'Keluar / Check-Out')");
            $stmtTrx->execute([$uid, $PARKING_FEE]);
            $stmtRes = $conn->prepare("UPDATE reservasi SET status = 'selesai' WHERE id = ?");
            $stmtRes->execute([$booking['id']]);
            $stmtSlot = $conn->prepare("UPDATE slot SET terisi = false WHERE id = ?");
            $stmtSlot->execute([$booking['slot_id']]);
            $conn->commit();

            sendGateResponse($mqtt, $gate, $requestId, 'success', 'Check-out Berhasil', true, ['reservasi_id' => $booking['id'], 'slot_id' => $booking['slot_id']]);
            publishSlotState($conn, $mqtt);
            echo "   -> 🟢 Check-out berhasil: {$qr}\n";
            return;
        }

        $conn->commit();
        sendGateResponse($mqtt, $gate, $requestId, 'error', 'Status tiket tidak valid', false);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo "   -> 🔴 Gate scan error: " . $e->getMessage() . "\n";
        sendGateResponse($mqtt, $gate, $requestId, 'error', 'Kesalahan Sistem', false);
    }
}

function handleMessage($topic, $message, $conn, $mqtt) {
    echo "\n[" . date('H:i:s') . "] Topic: {$topic}\n";
    echo "Payload: {$message}\n";

    cleanupExpiredReservations($conn, $mqtt);
    $payload = json_decode($message, true);

    if (!is_array($payload)) {
        echo "   -> 🔴 Payload JSON tidak valid\n";
        return;
    }

    if ($topic === 'smartparking/web/reservation/create') {
        processReservationCreate($conn, $mqtt, $payload);
    } elseif ($topic === 'smartparking/web/reservation/cancel') {
        processReservationCancel($conn, $mqtt, $payload);
    } elseif ($topic === 'smartparking/web/topup/create') {
        processTopupCreate($conn, $mqtt, $payload);
    } elseif ($topic === 'smartparking/esp32/slot/update') {
        processSlotUpdate($conn, $mqtt, $payload);
    } elseif ($topic === 'smartparking/esp32/gate/in/scan') {
        processGateScan($conn, $mqtt, $payload, 'in');
    } elseif ($topic === 'smartparking/esp32/gate/out/scan') {
        processGateScan($conn, $mqtt, $payload, 'out');
    }
}

$topics = [
    'smartparking/web/reservation/create',
    'smartparking/web/reservation/cancel',
    'smartparking/web/topup/create',
    'smartparking/esp32/slot/update',
    'smartparking/esp32/gate/in/scan',
    'smartparking/esp32/gate/out/scan'
];

foreach ($topics as $topicName) {
    $mqtt->subscribe($topicName, function ($topic, $message) use ($conn, $mqtt) {
        handleMessage($topic, $message, $conn, $mqtt);
    }, 0);
}

publishJson($mqtt, 'smartparking/server/device/status', [
    'event' => 'worker_online',
    'status' => 'online',
    'message' => 'mqttreceiver.php aktif'
]);

publishSlotState($conn, $mqtt);

echo "\n✅ mqttreceiver.php aktif.\n";
echo "✅ Subscribe topic smartparking aktif.\n";
echo "✅ Jangan tutup terminal ini.\n\n";

$mqtt->loop(true);
