<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mqtt_config.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// =====================================================
// MQTT BRIDGE OPTIMAL / REALTIME RINGAN
// =====================================================
// Alur: ESP32/MQTTX -> HiveMQ -> mqtt_bridge.php -> api.php -> Supabase
// Perbaikan utama:
// - Tidak subscribe smartparking/# agar bridge tidak memproses pesan buatannya sendiri.
// - Timeout HTTP diperkecil agar tidak menggantung lama.
// - Pesan slot yang sama tidak diproses berulang.
// - Scan QR dobel dalam waktu dekat diabaikan agar tidak double proses.
// - Publish realtime hanya saat data benar-benar berubah.

// Ganti jika domain Azure berubah. Jangan pakai slash di belakang api.php.
$apiBase = 'https://smart-parking-rifki-eqfwfbghh3edbyd7.eastasia-01.azurewebsites.net/api.php';

// Fallback jika topic belum didefinisikan di mqtt_config.php
if (!defined('MQTT_TOPIC_SLOT_STATUS'))   define('MQTT_TOPIC_SLOT_STATUS', 'smartparking/slot/status');
if (!defined('MQTT_TOPIC_GATE_SCAN'))     define('MQTT_TOPIC_GATE_SCAN', 'smartparking/gate/scan');
if (!defined('MQTT_TOPIC_SLOT_REQUEST'))  define('MQTT_TOPIC_SLOT_REQUEST', 'smartparking/slot/request');
if (!defined('MQTT_TOPIC_GATE_RESPONSE')) define('MQTT_TOPIC_GATE_RESPONSE', 'smartparking/gate/response');
if (!defined('MQTT_TOPIC_SERVER_EVENT'))  define('MQTT_TOPIC_SERVER_EVENT', 'smartparking/server/event');
if (!defined('MQTT_TOPIC_SLOT_STATE'))    define('MQTT_TOPIC_SLOT_STATE', 'smartparking/server/slot/state');
if (!defined('MQTT_USE_TLS'))             define('MQTT_USE_TLS', true);

function shortJson(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function httpRequest(string $method, string $url, array $data = []): string
{
    $ch = curl_init();

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_USERAGENT => 'smartparking-mqtt-bridge/optimized',
    ];

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        throw new Exception('CURL: ' . $error);
    }

    if ($httpCode >= 400) {
        throw new Exception('HTTP ' . $httpCode . ' dari API: ' . substr((string)$response, 0, 200));
    }

    return (string)$response;
}

function httpGet(string $url): string
{
    return httpRequest('GET', $url);
}

function httpPost(string $url, array $data): string
{
    return httpRequest('POST', $url, $data);
}

function publishJson(MqttClient $mqtt, string $topic, array $payload): void
{
    $mqtt->publish($topic, shortJson($payload), 0);
}

function publishServerEvent(MqttClient $mqtt, string $event, array $extra = []): void
{
    publishJson($mqtt, MQTT_TOPIC_SERVER_EVENT, array_merge([
        'event' => $event,
        'source' => 'mqtt_bridge.php',
        'server_time' => date('c'),
    ], $extra));
}

function publishSlotStateFromApi(MqttClient $mqtt, string $apiBase): void
{
    $apiResponse = httpGet($apiBase . '?action=get_slots_admin&_=' . time());
    $data = json_decode($apiResponse, true);

    if (!is_array($data) || !isset($data['slots'])) {
        echo "Gagal mengambil slot state dari API\n";
        return;
    }

    $slots = [];
    foreach ($data['slots'] as $s) {
        $state = $s['state'] ?? 'slot-free';

        if ($state === 'slot-occupied') {
            $status = 'terisi';
            $terisi = true;
        } elseif ($state === 'slot-reserved-admin') {
            $status = 'reserved';
            $terisi = false;
        } else {
            $status = 'kosong';
            $terisi = false;
        }

        $slots[] = [
            'slot_nomor' => (int)($s['slot_nomor'] ?? 0),
            'terisi' => $terisi,
            'status' => $status,
            'user_data' => $s['user_data'] ?? null,
        ];
    }

    publishJson($mqtt, MQTT_TOPIC_SLOT_STATE, [
        'event' => 'slot_state',
        'slots' => $slots,
        'source' => 'mqtt_bridge.php',
        'server_time' => date('c'),
    ]);

    echo "Publish slot state ke MQTT berhasil\n";
}

$clientId = 'php_bridge_' . uniqid('', true);
$settings = (new ConnectionSettings())
    ->setUsername(MQTT_USER)
    ->setPassword(MQTT_PASS)
    ->setUseTls(MQTT_USE_TLS)
    ->setKeepAliveInterval(30)
    ->setConnectTimeout(5);

$mqtt = new MqttClient(MQTT_HOST, MQTT_PORT, $clientId, MqttClient::MQTT_3_1_1);

// Cache ringan untuk mencegah proses berulang.
$lastSlotHash = '';
$lastSlotAt = 0.0;
$lastScanHash = '';
$lastScanAt = 0.0;

$handler = function (string $topic, string $message) use ($mqtt, $apiBase, &$lastSlotHash, &$lastSlotAt, &$lastScanHash, &$lastScanAt) {
    $allowedTopics = [
        MQTT_TOPIC_SLOT_STATUS,
        MQTT_TOPIC_GATE_SCAN,
        MQTT_TOPIC_SLOT_REQUEST,
    ];

    // Pengaman jika nanti subscribe wildcard: abaikan pesan response/event dari bridge sendiri.
    if (!in_array($topic, $allowedTopics, true)) {
        return;
    }

    echo "\nTopic: {$topic}\n";
    echo "Message: {$message}\n";

    $payload = json_decode($message, true);
    if (!is_array($payload)) {
        echo "Payload bukan JSON valid\n";
        return;
    }

    try {
        // 1. STATUS SLOT DARI ESP32 / MQTTX
        if ($topic === MQTT_TOPIC_SLOT_STATUS) {
            $s1 = isset($payload['s1']) ? (int)$payload['s1'] : 0;
            $s2 = isset($payload['s2']) ? (int)$payload['s2'] : 0;
            $s3 = isset($payload['s3']) ? (int)$payload['s3'] : 0;
            $s4 = isset($payload['s4']) ? (int)$payload['s4'] : 0;

            $slotHash = "{$s1}{$s2}{$s3}{$s4}";
            $nowMicro = microtime(true);

            if ($slotHash === $lastSlotHash && ($nowMicro - $lastSlotAt) < 1.0) {
                echo "Slot sama dalam <1 detik, diabaikan supaya tidak spam.\n";
                return;
            }

            $lastSlotHash = $slotHash;
            $lastSlotAt = $nowMicro;

            $url = $apiBase . '?action=update_hardware_slots'
                . '&s1=' . $s1
                . '&s2=' . $s2
                . '&s3=' . $s3
                . '&s4=' . $s4;

            $apiResponse = httpGet($url);
            echo "Response API Slot: {$apiResponse}\n";

            $apiData = json_decode($apiResponse, true) ?: [];
            $changed = (int)($apiData['changed'] ?? 0);
            $assigned = (int)($apiData['assigned_permanent_history'] ?? 0);

            // Publish event hanya jika database benar-benar berubah.
            if ($changed > 0 || $assigned > 0) {
                publishServerEvent($mqtt, 'hardware_slot_updated', [
                    's1' => $s1,
                    's2' => $s2,
                    's3' => $s3,
                    's4' => $s4,
                    'changed' => $changed,
                    'assigned_permanent_history' => $assigned,
                ]);
            } else {
                echo "Tidak ada perubahan slot, realtime event tidak dikirim.\n";
            }

            return;
        }

        // 2. SCAN QR RESERVASI / QR PERMANEN
        if ($topic === MQTT_TOPIC_GATE_SCAN) {
            $qrCode = isset($payload['qr_code']) ? trim((string)$payload['qr_code']) : '';
            $gate = isset($payload['gate']) ? strtolower(trim((string)$payload['gate'])) : '';

            if ($qrCode === '' || !in_array($gate, ['in', 'out'], true)) {
                echo "qr_code kosong atau gate bukan in/out\n";
                return;
            }

            $scanHash = sha1($qrCode . '|' . $gate);
            $nowMicro = microtime(true);

            if ($scanHash === $lastScanHash && ($nowMicro - $lastScanAt) < 2.0) {
                echo "Scan QR dobel dalam <2 detik, diabaikan.\n";
                return;
            }

            $lastScanHash = $scanHash;
            $lastScanAt = $nowMicro;

            $apiResponse = httpPost($apiBase . '?action=gate_scan', [
                'qr_code' => $qrCode,
                'gate' => $gate,
            ]);

            echo "Response API Gate: {$apiResponse}\n";
            $apiData = json_decode($apiResponse, true) ?: [];

            publishJson($mqtt, MQTT_TOPIC_GATE_RESPONSE, [
                'qr_code' => $qrCode,
                'gate' => $gate,
                'status' => $apiData['status'] ?? 'error',
                'message' => $apiData['message'] ?? 'Response tidak valid',
                'server_time' => date('c'),
            ]);

            publishServerEvent($mqtt, 'gate_scan_processed', [
                'qr_code' => $qrCode,
                'gate' => $gate,
                'api_status' => $apiData['status'] ?? 'error',
            ]);

            return;
        }

        // 3. REQUEST STATUS SLOT DARI ESP32 / MQTTX
        if ($topic === MQTT_TOPIC_SLOT_REQUEST) {
            publishSlotStateFromApi($mqtt, $apiBase);
            publishServerEvent($mqtt, 'slot_state_requested', [
                'request' => $payload['request'] ?? 'slot_state',
            ]);
            return;
        }
    } catch (Throwable $e) {
        echo "Error proses data: " . $e->getMessage() . "\n";
        publishJson($mqtt, 'smartparking/system/error', [
            'error' => $e->getMessage(),
            'topic' => $topic,
            'server_time' => date('c'),
        ]);
    }
};

try {
    $mqtt->connect($settings, true);
    echo "Bridge PHP terhubung ke HiveMQ\n";

    // Subscribe hanya topic input, bukan smartparking/#.
    $mqtt->subscribe(MQTT_TOPIC_SLOT_STATUS, $handler, 0);
    $mqtt->subscribe(MQTT_TOPIC_GATE_SCAN, $handler, 0);
    $mqtt->subscribe(MQTT_TOPIC_SLOT_REQUEST, $handler, 0);

    echo "Subscribe topic input berhasil:\n";
    echo "- " . MQTT_TOPIC_SLOT_STATUS . "\n";
    echo "- " . MQTT_TOPIC_GATE_SCAN . "\n";
    echo "- " . MQTT_TOPIC_SLOT_REQUEST . "\n";
    echo "Menunggu data MQTT...\n";

    $mqtt->loop(true);
} catch (Throwable $e) {
    echo "Gagal konek MQTT: " . $e->getMessage() . "\n";
}
?>
