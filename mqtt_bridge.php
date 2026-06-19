<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mqtt_config.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// =====================================================
// API WEB KAMU
// =====================================================
$apiBase = 'https://smart-parking-rifki-eqfwfbghh3edbyd7.eastasia-01.azurewebsites.net/api.php';

function httpGet($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception($error);
    }

    return $response;
}

function httpPost($url, $data)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception($error);
    }

    return $response;
}

function publishServerEvent(MqttClient $mqtt, string $event, array $extra = [])
{
    $payload = array_merge([
        'event' => $event,
        'source' => 'mqtt_bridge.php',
        'server_time' => date('c'),
    ], $extra);

    $mqtt->publish(MQTT_TOPIC_SERVER_EVENT, json_encode($payload, JSON_UNESCAPED_UNICODE), 0);
}

function publishSlotStateFromApi(MqttClient $mqtt, string $apiBase)
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

    $payload = [
        'event' => 'slot_state',
        'slots' => $slots,
        'source' => 'mqtt_bridge.php',
        'server_time' => date('c'),
    ];

    $mqtt->publish(MQTT_TOPIC_SLOT_STATE, json_encode($payload, JSON_UNESCAPED_UNICODE), 0);
    echo "Publish slot state ke MQTT berhasil\n";
}

$clientId = 'php_bridge_' . uniqid();
$settings = (new ConnectionSettings())
    ->setUsername(MQTT_USER)
    ->setPassword(MQTT_PASS)
    ->setUseTls(MQTT_USE_TLS)
    ->setKeepAliveInterval(60)
    ->setConnectTimeout(10);

$mqtt = new MqttClient(MQTT_HOST, MQTT_PORT, $clientId, MqttClient::MQTT_3_1_1);

try {
    $mqtt->connect($settings, true);
    echo "Bridge PHP terhubung ke HiveMQ\n";

    $mqtt->subscribe('smartparking/#', function ($topic, $message) use ($mqtt, $apiBase) {
        echo "\nTopic: $topic\n";
        echo "Message: $message\n";

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

                $url = $apiBase . '?action=update_hardware_slots'
                    . '&s1=' . $s1
                    . '&s2=' . $s2
                    . '&s3=' . $s3
                    . '&s4=' . $s4;

                $apiResponse = httpGet($url);
                echo "Response API Slot: $apiResponse\n";

                publishSlotStateFromApi($mqtt, $apiBase);
                publishServerEvent($mqtt, 'slot_hardware_updated', [
                    's1' => $s1,
                    's2' => $s2,
                    's3' => $s3,
                    's4' => $s4,
                ]);
            }

            // 2. SCAN QR RESERVASI / QR PERMANEN
            if ($topic === MQTT_TOPIC_GATE_SCAN) {
                $qrCode = isset($payload['qr_code']) ? trim($payload['qr_code']) : '';
                $gate = isset($payload['gate']) ? trim($payload['gate']) : '';

                if ($qrCode === '' || $gate === '') {
                    echo "qr_code atau gate kosong\n";
                    return;
                }

                $apiResponse = httpPost($apiBase . '?action=gate_scan', [
                    'qr_code' => $qrCode,
                    'gate' => $gate,
                ]);

                echo "Response API Gate: $apiResponse\n";
                $apiData = json_decode($apiResponse, true);

                $mqtt->publish(MQTT_TOPIC_GATE_RESPONSE, json_encode([
                    'qr_code' => $qrCode,
                    'gate' => $gate,
                    'status' => $apiData['status'] ?? 'error',
                    'message' => $apiData['message'] ?? 'Response tidak valid',
                    'time' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE), 0);

                publishSlotStateFromApi($mqtt, $apiBase);
                publishServerEvent($mqtt, 'gate_scan_processed', [
                    'qr_code' => $qrCode,
                    'gate' => $gate,
                    'api_status' => $apiData['status'] ?? 'error',
                ]);
            }

            // 3. REQUEST STATUS SLOT DARI ESP32 / MQTTX
            if ($topic === MQTT_TOPIC_SLOT_REQUEST) {
                publishSlotStateFromApi($mqtt, $apiBase);
                publishServerEvent($mqtt, 'slot_state_requested', [
                    'request' => $payload['request'] ?? 'slot_state',
                ]);
            }

        } catch (Throwable $e) {
            echo "Error proses data: " . $e->getMessage() . "\n";
            $mqtt->publish('smartparking/system/error', json_encode([
                'error' => $e->getMessage(),
                'topic' => $topic,
                'time' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE), 0);
        }
    }, 0);

    echo "Subscribe smartparking/# berhasil\n";
    echo "Menunggu data MQTT...\n";

    $mqtt->loop(true);

} catch (Throwable $e) {
    echo "Gagal konek MQTT: " . $e->getMessage() . "\n";
}
?>
