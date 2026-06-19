<?php
/**
 * AUTO TEST MQTT - Smart Parking ESP32 + HiveMQ + PHP Bridge
 * -----------------------------------------------------------
 * Jalankan dari terminal:
 *   php auto_test_mqtt.php --slot-tests=30 --api=http://localhost/smartparking_mqtt/api.php
 *
 * Tes QR permanen:
 *   php auto_test_mqtt.php --qr-permanen="STIKER-B 0607 EB" --api=http://localhost/smartparking_mqtt/api.php
 *
 * Tes QR reservasi:
 *   php auto_test_mqtt.php --qr-reservasi="PK-ABC123" --api=http://localhost/smartparking_mqtt/api.php
 *
 * Catatan:
 * - File ini membutuhkan Composer library: php-mqtt/client
 * - Jalankan: composer require php-mqtt/client
 * - File ini akan memakai mqtt_config.php jika ada di folder yang sama.
 */

require __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/mqtt_config.php')) {
    require_once __DIR__ . '/mqtt_config.php';
}

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// =====================================================
// HELPER CONFIG
// =====================================================
function cfg(string $name, $default = null) {
    return defined($name) ? constant($name) : $default;
}

function argValue(string $name, $default = null) {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function hasArg(string $name): bool {
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function nowMs(): float {
    return microtime(true) * 1000;
}

function shortJson($data): string {
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function httpGetJson(string $url, int $timeout = 5) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['_error' => $err, '_http_code' => $code];
    }

    $json = json_decode($res, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['_raw' => $res, '_http_code' => $code];
    }

    if (is_array($json)) {
        $json['_http_code'] = $code;
    }
    return $json;
}

function printLine(string $text = ''): void {
    echo $text . PHP_EOL;
}

// =====================================================
// KONFIGURASI MQTT
// =====================================================
$mqttHost = argValue('mqtt-host', cfg('MQTT_HOST', 'ISI_HOST_HIVEMQ'));
$mqttPort = (int) argValue('mqtt-port', cfg('MQTT_PORT', 8883));
$mqttUser = argValue('mqtt-user', cfg('MQTT_USER', 'ISI_USERNAME_HIVEMQ'));
$mqttPass = argValue('mqtt-pass', cfg('MQTT_PASS', 'ISI_PASSWORD_HIVEMQ'));
$mqttTls  = filter_var(argValue('mqtt-tls', cfg('MQTT_USE_TLS', true)), FILTER_VALIDATE_BOOLEAN);

$topicSlotStatus = cfg('MQTT_TOPIC_SLOT_STATUS', 'smartparking/slot/status');
$topicGateScan   = cfg('MQTT_TOPIC_GATE_SCAN', 'smartparking/gate/scan');
$topicGateResp   = cfg('MQTT_TOPIC_GATE_RESPONSE', 'smartparking/gate/response');
$topicEvent      = cfg('MQTT_TOPIC_SERVER_EVENT', 'smartparking/server/event');
$topicSlotState  = cfg('MQTT_TOPIC_SLOT_STATE', 'smartparking/server/slot/state');

// =====================================================
// OPSI PENGUJIAN
// =====================================================
$slotTests    = (int) argValue('slot-tests', 30);
$qrPermanen   = argValue('qr-permanen', '');
$qrReservasi  = argValue('qr-reservasi', '');
$qrLoop       = (int) argValue('qr-loop', 1);
$apiBase      = argValue('api', '');
$timeoutMs    = (int) argValue('timeout-ms', 5000);
$delayMs      = (int) argValue('delay-ms', 300);
$outFile      = argValue('out', __DIR__ . '/hasil_pengujian_mqtt_' . date('Ymd_His') . '.csv');

if (hasArg('help')) {
    printLine("AUTO TEST MQTT SMART PARKING");
    printLine("Contoh:");
    printLine("  php auto_test_mqtt.php --slot-tests=30 --api=http://localhost/smartparking_mqtt/api.php");
    printLine("  php auto_test_mqtt.php --qr-permanen=\"STIKER-B 0607 EB\"");
    printLine("  php auto_test_mqtt.php --qr-reservasi=\"PK-ABC123\"");
    printLine("");
    printLine("Opsi:");
    printLine("  --slot-tests=30");
    printLine("  --qr-permanen=STIKER-xxx");
    printLine("  --qr-reservasi=PK-xxxxxx");
    printLine("  --qr-loop=1");
    printLine("  --api=http://localhost/smartparking_mqtt/api.php");
    printLine("  --timeout-ms=5000");
    printLine("  --delay-ms=300");
    printLine("  --out=hasil.csv");
    exit;
}

if ($mqttHost === 'ISI_HOST_HIVEMQ' || $mqttUser === 'ISI_USERNAME_HIVEMQ') {
    printLine("ERROR: Konfigurasi MQTT belum diisi.");
    printLine("Isi mqtt_config.php atau jalankan dengan --mqtt-host, --mqtt-user, --mqtt-pass.");
    exit(1);
}

// =====================================================
// CSV OUTPUT
// =====================================================
$csv = fopen($outFile, 'w');
if (!$csv) {
    printLine("ERROR: Gagal membuat file CSV: $outFile");
    exit(1);
}

fputcsv($csv, [
    'no',
    'waktu_wib',
    'jenis_uji',
    'topic',
    'payload',
    'publish_mqtt',
    'ack_status',
    'message',
    'latency_ms',
    'keterangan'
]);

$rows = [];
$testNo = 0;
$receivedMessages = [];
$sentCount = 0;
$ackCount = 0;
$successCount = 0;
$failedCount = 0;
$latencies = [];

function addRow(
    string $jenis,
    string $topic,
    string $payload,
    string $publishStatus,
    string $ackStatus,
    string $message,
    $latencyMs,
    string $keterangan = ''
): void {
    global $csv, $testNo, $rows, $successCount, $failedCount, $latencies;

    $testNo++;
    $latencyText = is_numeric($latencyMs) ? round($latencyMs, 2) : '';

    $row = [
        $testNo,
        date('Y-m-d H:i:s'),
        $jenis,
        $topic,
        $payload,
        $publishStatus,
        $ackStatus,
        $message,
        $latencyText,
        $keterangan
    ];

    fputcsv($csv, $row);
    $rows[] = $row;

    if (strtolower($ackStatus) === 'success' || strtolower($ackStatus) === 'berhasil') {
        $successCount++;
    } else {
        $failedCount++;
    }

    if (is_numeric($latencyMs)) {
        $latencies[] = (float) $latencyMs;
    }
}

// =====================================================
// CONNECT MQTT
// =====================================================
$clientId = 'auto_test_php_' . uniqid();
$mqtt = new MqttClient($mqttHost, $mqttPort, $clientId, MqttClient::MQTT_3_1_1);

$settings = (new ConnectionSettings)
    ->setUsername($mqttUser)
    ->setPassword($mqttPass)
    ->setUseTls($mqttTls)
    ->setKeepAliveInterval(60)
    ->setConnectTimeout(10);

printLine("Menghubungkan ke HiveMQ...");
$mqtt->connect($settings, true);
printLine("Terhubung ke HiveMQ sebagai $clientId");

// Subscribe topic response dari bridge/server.
$mqtt->subscribe('smartparking/server/#', function ($topic, $message) use (&$receivedMessages) {
    $receivedMessages[] = [
        'topic' => $topic,
        'message' => $message,
        'json' => json_decode($message, true),
        'time_ms' => nowMs()
    ];
}, 0);

$mqtt->subscribe('smartparking/gate/response', function ($topic, $message) use (&$receivedMessages) {
    $receivedMessages[] = [
        'topic' => $topic,
        'message' => $message,
        'json' => json_decode($message, true),
        'time_ms' => nowMs()
    ];
}, 0);

// Beri waktu subscribe aktif.
$subscribeStart = nowMs();
while (nowMs() - $subscribeStart < 300) {
    $mqtt->loop(false, true);
    usleep(10000);
}

function waitForMqtt(callable $matcher, int $timeoutMs): ?array {
    global $mqtt, $receivedMessages;

    $startIndex = count($receivedMessages);
    $start = nowMs();

    while (nowMs() - $start < $timeoutMs) {
        $mqtt->loop(false, true);

        for ($i = $startIndex; $i < count($receivedMessages); $i++) {
            $msg = $receivedMessages[$i];
            if ($matcher($msg)) {
                return $msg;
            }
        }

        usleep(10000);
    }

    return null;
}

function publishJson(string $topic, array $payload): bool {
    global $mqtt, $sentCount;

    $sentCount++;

    try {
        $mqtt->publish($topic, shortJson($payload), 0);
        return true;
    } catch (Throwable $e) {
        echo "[PUBLISH ERROR] " . $e->getMessage() . PHP_EOL;
        return false;
    }
}

// =====================================================
// TEST 1: SLOT MQTT
// =====================================================
printLine("\n[TEST] Update slot MQTT sebanyak $slotTests kali...");
$overallStart = nowMs();

for ($i = 1; $i <= $slotTests; $i++) {
    $pattern = $i % 4;

    $payload = [
        'seq' => $i,
        's1' => $pattern === 0 ? 1 : 0,
        's2' => $pattern === 1 ? 1 : 0,
        's3' => $pattern === 2 ? 1 : 0,
        's4' => $pattern === 3 ? 1 : 0,
        'source' => 'auto_test_mqtt.php',
        'sent_at_ms' => round(nowMs(), 3)
    ];

    $start = nowMs();
    $ok = publishJson($topicSlotStatus, $payload);

    if (!$ok) {
        addRow('Update Slot MQTT', $topicSlotStatus, shortJson($payload), 'gagal', 'failed', 'Publish MQTT gagal', '', '');
        usleep($delayMs * 1000);
        continue;
    }

    // Tunggu event server. Kalau bridge kamu mengirim seq balik, latency lebih akurat.
    // Kalau tidak ada event, hasil dianggap "terkirim tanpa ACK".
    $ack = waitForMqtt(function ($msg) use ($i, $topicEvent, $topicSlotState) {
        $topic = $msg['topic'];
        $json = is_array($msg['json']) ? $msg['json'] : [];

        if (isset($json['seq']) && (int) $json['seq'] === $i) {
            return true;
        }

        if ($topic === $topicEvent) {
            $event = $json['event'] ?? '';
            return in_array($event, ['slot_updated', 'refresh_all', 'hardware_slot_updated', 'slot_state'], true);
        }

        if ($topic === $topicSlotState) {
            return true;
        }

        return false;
    }, $timeoutMs);

    $latency = $ack ? ($ack['time_ms'] - $start) : '';
    $ackStatus = $ack ? 'success' : 'no_ack';
    $msg = $ack ? ('ACK dari ' . $ack['topic']) : 'Terkirim, tetapi tidak ada ACK/event dalam timeout';

    // Optional verifikasi API.
    $ket = '';
    global $apiBase;
    if ($apiBase !== '') {
        $apiResult = httpGetJson($apiBase . '?action=get_slots&uid=esp32_device');
        $ket = 'Verifikasi API get_slots HTTP ' . ($apiResult['_http_code'] ?? '-');
    }

    addRow('Update Slot MQTT', $topicSlotStatus, shortJson($payload), 'berhasil', $ackStatus, $msg, $latency, $ket);
    usleep($delayMs * 1000);
}

// =====================================================
// TEST 2: QR GATE SCAN
// =====================================================
function runQrTest(string $label, string $qrCode, string $gate, int $loopCount): void {
    global $topicGateScan, $topicGateResp, $timeoutMs, $delayMs;

    if (trim($qrCode) === '') {
        return;
    }

    printLine("\n[TEST] $label gate=$gate sebanyak $loopCount kali...");

    for ($i = 1; $i <= $loopCount; $i++) {
        $payload = [
            'seq' => $i,
            'qr_code' => $qrCode,
            'gate' => $gate,
            'source' => 'auto_test_mqtt.php',
            'sent_at_ms' => round(nowMs(), 3)
        ];

        $start = nowMs();
        $ok = publishJson($topicGateScan, $payload);

        if (!$ok) {
            addRow($label, $topicGateScan, shortJson($payload), 'gagal', 'failed', 'Publish MQTT gagal', '', '');
            usleep($delayMs * 1000);
            continue;
        }

        $ack = waitForMqtt(function ($msg) use ($topicGateResp, $qrCode, $gate) {
            if ($msg['topic'] !== $topicGateResp) {
                return false;
            }

            $json = is_array($msg['json']) ? $msg['json'] : [];
            $respQr = $json['qr_code'] ?? '';
            $respGate = $json['gate'] ?? '';

            $qrMatch = ($respQr === '' || $respQr === $qrCode);
            $gateMatch = ($respGate === '' || $respGate === $gate);

            return $qrMatch && $gateMatch;
        }, $timeoutMs);

        if ($ack) {
            $json = is_array($ack['json']) ? $ack['json'] : [];
            $status = $json['status'] ?? ($json['response']['status'] ?? 'unknown');
            $message = $json['message'] ?? ($json['response']['message'] ?? $ack['message']);
            $latency = $ack['time_ms'] - $start;

            addRow($label, $topicGateScan, shortJson($payload), 'berhasil', $status, (string) $message, $latency, 'ACK gate response diterima');
        } else {
            addRow($label, $topicGateScan, shortJson($payload), 'berhasil', 'no_ack', 'Tidak ada gate response dalam timeout', '', '');
        }

        usleep($delayMs * 1000);
    }
}

if ($qrPermanen !== '') {
    runQrTest('QR Permanen Masuk', $qrPermanen, 'in', $qrLoop);
    runQrTest('QR Permanen Keluar', $qrPermanen, 'out', $qrLoop);
}

if ($qrReservasi !== '') {
    runQrTest('QR Reservasi Masuk', $qrReservasi, 'in', $qrLoop);
    runQrTest('QR Reservasi Keluar', $qrReservasi, 'out', $qrLoop);
}

// =====================================================
// SUMMARY
// =====================================================
$overallEnd = nowMs();
$totalTimeSec = max(0.001, ($overallEnd - $overallStart) / 1000);
$totalTests = $successCount + $failedCount;
$avgLatency = count($latencies) > 0 ? array_sum($latencies) / count($latencies) : 0;
$throughput = $sentCount / $totalTimeSec;
$messageLoss = $sentCount > 0 ? (($sentCount - $successCount) / $sentCount) * 100 : 0;
$successRate = $sentCount > 0 ? ($successCount / $sentCount) * 100 : 0;

fputcsv($csv, []);
fputcsv($csv, ['RINGKASAN']);
fputcsv($csv, ['total_publish', $sentCount]);
fputcsv($csv, ['success_ack', $successCount]);
fputcsv($csv, ['failed_or_no_ack', $failedCount]);
fputcsv($csv, ['success_rate_percent', round($successRate, 2)]);
fputcsv($csv, ['message_loss_percent', round($messageLoss, 2)]);
fputcsv($csv, ['average_latency_ms', round($avgLatency, 2)]);
fputcsv($csv, ['throughput_msg_per_second', round($throughput, 4)]);
fputcsv($csv, ['total_time_second', round($totalTimeSec, 2)]);

fclose($csv);

printLine("\n========== RINGKASAN PENGUJIAN ==========");
printLine("Total publish        : $sentCount");
printLine("ACK success          : $successCount");
printLine("Failed / no ACK      : $failedCount");
printLine("Success rate         : " . round($successRate, 2) . "%");
printLine("Message loss/no ACK  : " . round($messageLoss, 2) . "%");
printLine("Rata-rata latency    : " . round($avgLatency, 2) . " ms");
printLine("Throughput           : " . round($throughput, 4) . " pesan/detik");
printLine("Total waktu          : " . round($totalTimeSec, 2) . " detik");
printLine("File hasil CSV       : $outFile");
printLine("=========================================");

$mqtt->disconnect();
