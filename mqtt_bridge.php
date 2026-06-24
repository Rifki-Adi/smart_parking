<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mqtt_config.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// =====================================================
// API WEB KAMU
// =====================================================
// Bisa dipindah ke mqtt_config.php dengan define('API_BASE_URL', 'https://.../api.php');
$apiBase = defined('API_BASE_URL')
    ? API_BASE_URL
    : 'https://smart-parking-rifki-eqfwfbghh3edbyd7.eastasia-01.azurewebsites.net/api.php';

// Topic yang diproses bridge. Jangan subscribe smartparking/# agar bridge tidak memproses pesan publish miliknya sendiri.
$inputTopics = [
    MQTT_TOPIC_SLOT_STATUS,
    MQTT_TOPIC_GATE_SCAN,
    MQTT_TOPIC_SLOT_REQUEST,
];

$lastSlotHash = null;
$lastSlotProcessMs = 0;
$lastSlotStatePublishMs = 0;
$lastQrCache = []; // key => unix_ms

// Anti lag: slot/status sering berubah karena sensor IR flicker.
// Bridge hanya kirim ke API jika status stabil/berubah dan tidak terlalu cepat.
const SLOT_API_MIN_INTERVAL_MS = 2500;
const SLOT_STATE_REQUEST_MIN_INTERVAL_MS = 3000;

function nowMs(): int
{
    return (int) round(microtime(true) * 1000);
}

function jsonOut(array $payload): string
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function withQuery(string $url, array $params): string
{
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . http_build_query($params);
}

function httpRequest(string $method, string $url, array $data = []): array
{
    $started = microtime(true);
    $ch = curl_init();

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
    ];

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $elapsedMs = (int) round((microtime(true) - $started) * 1000);

    if ($response === false || $errno) {
        throw new RuntimeException("HTTP {$method} gagal ({$errno}): {$error}");
    }

    if ($httpCode >= 400 || $httpCode === 0) {
        throw new RuntimeException("HTTP {$method} status {$httpCode}: " . substr((string)$response, 0, 200));
    }

    return [
        'body' => $response,
        'http_code' => $httpCode,
        'elapsed_ms' => $elapsedMs,
    ];
}

function httpGet(string $url): array
{
    return httpRequest('GET', $url);
}

function httpPost(string $url, array $data): array
{
    return httpRequest('POST', $url, $data);
}

function publishJson(MqttClient $mqtt, string $topic, array $payload): void
{
    $mqtt->publish($topic, jsonOut($payload), 0);
}

function publishServerEvent(MqttClient $mqtt, string $event, array $extra = []): void
{
    publishJson($mqtt, MQTT_TOPIC_SERVER_EVENT, array_merge([
        'event' => $event,
        'source' => 'mqtt_bridge.php',
        'server_time' => date('c'),
    ], $extra));
}

function publishGateResponse(MqttClient $mqtt, string $qrCode, string $gate, string $status, string $message, array $extra = []): void
{
    publishJson($mqtt, MQTT_TOPIC_GATE_RESPONSE, array_merge([
        'qr_code' => $qrCode,
        'gate' => $gate,
        'status' => $status,
        'message' => $message,
        'time' => date('Y-m-d H:i:s'),
        'source' => 'mqtt_bridge.php',
    ], $extra));
}

function publishSlotStateFromApi(MqttClient $mqtt, string $apiBase): void
{
    try {
        // silent=1 agar api.php tidak membuat koneksi MQTT lagi. Bridge yang publish event.
        $url = withQuery($apiBase, [
            'action' => 'get_slots_admin',
            'silent' => 1,
            '_' => time(),
        ]);

        $res = httpGet($url);
        $data = json_decode($res['body'], true);

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
            'api_ms' => $res['elapsed_ms'],
            'server_time' => date('c'),
        ]);

        echo "Publish slot state berhasil ({$res['elapsed_ms']} ms)\n";
    } catch (Throwable $e) {
        echo "Gagal publish slot state: " . $e->getMessage() . "\n";
    }
}

function handleSlotStatus(MqttClient $mqtt, string $apiBase, array $payload): void
{
    global $lastSlotHash, $lastSlotProcessMs;

    $s1 = isset($payload['s1']) ? (int)$payload['s1'] : 0;
    $s2 = isset($payload['s2']) ? (int)$payload['s2'] : 0;
    $s3 = isset($payload['s3']) ? (int)$payload['s3'] : 0;
    $s4 = isset($payload['s4']) ? (int)$payload['s4'] : 0;

    $hash = implode(',', [$s1, $s2, $s3, $s4]);
    $now = nowMs();

    if ($hash === $lastSlotHash) {
        echo "Slot status sama, skip API: {$hash}\n";
        return;
    }

    if (($now - $lastSlotProcessMs) < SLOT_API_MIN_INTERVAL_MS) {
        echo "Slot status terlalu cepat, skip agar server tidak lag: {$hash}\n";
        return;
    }

    $lastSlotHash = $hash;
    $lastSlotProcessMs = $now;

    $url = withQuery($apiBase, [
        'action' => 'update_hardware_slots',
        'silent' => 1,
        's1' => $s1,
        's2' => $s2,
        's3' => $s3,
        's4' => $s4,
    ]);

    $res = httpGet($url);
    echo "Response API Slot ({$res['elapsed_ms']} ms): {$res['body']}\n";

    // Penting untuk anti lag: setelah update slot, bridge tidak langsung ambil get_slots_admin.
    // Dashboard akan refresh sendiri setelah menerima server/event.
    publishServerEvent($mqtt, 'slot_hardware_updated', [
        's1' => $s1,
        's2' => $s2,
        's3' => $s3,
        's4' => $s4,
        'api_ms' => $res['elapsed_ms'],
    ]);
}

function handleGateScan(MqttClient $mqtt, string $apiBase, array $payload): void
{
    global $lastQrCache;

    $qrCode = isset($payload['qr_code']) ? trim((string)$payload['qr_code']) : '';
    $gate = isset($payload['gate']) ? strtolower(trim((string)$payload['gate'])) : '';

    if ($qrCode === '' || !in_array($gate, ['in', 'out'], true)) {
        echo "qr_code atau gate tidak valid\n";
        publishGateResponse($mqtt, $qrCode, $gate, 'error', 'QR/gate tidak valid');
        return;
    }

    // Debounce scanner: GM65 kadang membaca QR sama beberapa kali sangat cepat.
    $key = $qrCode . '|' . $gate;
    $now = nowMs();
    if (isset($lastQrCache[$key]) && ($now - $lastQrCache[$key] < 3500)) {
        echo "Duplicate QR cepat, skip: {$key}\n";
        return;
    }
    $lastQrCache[$key] = $now;

    try {
        $res = httpPost(withQuery($apiBase, ['action' => 'gate_scan']), [
            'qr_code' => $qrCode,
            'gate' => $gate,
            'silent' => 1, // penting: API tidak publish MQTT lagi, bridge yang publish agar tidak dobel dan tidak lambat.
        ]);

        echo "Response API Gate ({$res['elapsed_ms']} ms): {$res['body']}\n";
        $apiData = json_decode($res['body'], true);

        publishGateResponse(
            $mqtt,
            $qrCode,
            $gate,
            $apiData['status'] ?? 'error',
            $apiData['message'] ?? 'Response tidak valid',
            ['api_ms' => $res['elapsed_ms']]
        );

        // Response gate sudah dikirim dulu. Jangan langsung ambil slot_state di sini,
        // supaya bridge tidak tertahan dan ESP32 tidak timeout.
        publishServerEvent($mqtt, 'gate_scan_processed', [
            'qr_code' => $qrCode,
            'gate' => $gate,
            'api_status' => $apiData['status'] ?? 'error',
            'api_ms' => $res['elapsed_ms'],
        ]);
    } catch (Throwable $e) {
        echo "Error Gate Scan: " . $e->getMessage() . "\n";
        publishGateResponse($mqtt, $qrCode, $gate, 'error', 'Server timeout / API tidak merespons', [
            'error' => $e->getMessage(),
        ]);
        publishServerEvent($mqtt, 'gate_scan_error', [
            'qr_code' => $qrCode,
            'gate' => $gate,
            'error' => $e->getMessage(),
        ]);
    }
}

$clientId = 'php_bridge_' . uniqid();
$settings = (new ConnectionSettings())
    ->setUsername(MQTT_USER)
    ->setPassword(MQTT_PASS)
    ->setUseTls(defined('MQTT_USE_TLS') ? MQTT_USE_TLS : true)
    ->setKeepAliveInterval(60)
    ->setConnectTimeout(10);

$mqtt = new MqttClient(MQTT_HOST, MQTT_PORT, $clientId, MqttClient::MQTT_3_1_1);

try {
    $mqtt->connect($settings, true);
    echo "Bridge PHP terhubung ke HiveMQ\n";
    echo "API Base: {$apiBase}\n";

    $callback = function ($topic, $message) use ($mqtt, $apiBase, $inputTopics) {
        if (!in_array($topic, $inputTopics, true)) {
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
            if ($topic === MQTT_TOPIC_SLOT_STATUS) {
                handleSlotStatus($mqtt, $apiBase, $payload);
                return;
            }

            if ($topic === MQTT_TOPIC_GATE_SCAN) {
                handleGateScan($mqtt, $apiBase, $payload);
                return;
            }

            if ($topic === MQTT_TOPIC_SLOT_REQUEST) {
                global $lastSlotStatePublishMs;
                $now = nowMs();
                if (($now - $lastSlotStatePublishMs) >= SLOT_STATE_REQUEST_MIN_INTERVAL_MS) {
                    $lastSlotStatePublishMs = $now;
                    publishSlotStateFromApi($mqtt, $apiBase);
                    publishServerEvent($mqtt, 'slot_state_requested', [
                        'request' => $payload['request'] ?? 'slot_state',
                    ]);
                } else {
                    echo "Slot request terlalu cepat, skip get_slots_admin agar tidak lag\n";
                }
                return;
            }
        } catch (Throwable $e) {
            echo "Error proses data: " . $e->getMessage() . "\n";
            publishJson($mqtt, 'smartparking/system/error', [
                'error' => $e->getMessage(),
                'topic' => $topic,
                'time' => date('Y-m-d H:i:s'),
            ]);
        }
    };

    foreach ($inputTopics as $t) {
        $mqtt->subscribe($t, $callback, 0);
        echo "Subscribe {$t} berhasil\n";
    }

    echo "Menunggu data MQTT...\n";
    $mqtt->loop(true);
} catch (Throwable $e) {
    echo "Gagal konek MQTT: " . $e->getMessage() . "\n";
}
?>
