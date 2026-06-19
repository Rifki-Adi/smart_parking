<?php
// =====================================================
// HELPER MQTT UNTUK API / WEBSITE - OPTIMAL & FAIL FAST
// =====================================================
// File ini dipakai oleh api.php, cleanup.php, topup.php, dll.
// Jika vendor/autoload.php atau mqtt_config.php belum ada, fungsi return false
// agar halaman tetap berjalan dan tidak fatal error.

if (file_exists(__DIR__ . '/mqtt_config.php')) {
    require_once __DIR__ . '/mqtt_config.php';
}

if (!defined('MQTT_TOPIC_SERVER_EVENT'))  define('MQTT_TOPIC_SERVER_EVENT', 'smartparking/server/event');
if (!defined('MQTT_TOPIC_SLOT_STATE'))    define('MQTT_TOPIC_SLOT_STATE', 'smartparking/server/slot/state');
if (!defined('MQTT_USE_TLS'))             define('MQTT_USE_TLS', true);

function smartparking_mqtt_config_ready(): bool
{
    return defined('MQTT_HOST') && defined('MQTT_PORT') && defined('MQTT_USER') && defined('MQTT_PASS');
}

function smartparking_mqtt_autoload_ready(): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return false;
    }

    require_once $autoload;
    return class_exists('PhpMqtt\\Client\\MqttClient');
}

function smartparking_json(array $payload): string
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function smartparking_mqtt_publish(string $topic, array $payload, int $qos = 0, bool $retain = false): bool
{
    if (!smartparking_mqtt_config_ready() || !smartparking_mqtt_autoload_ready()) {
        return false;
    }

    try {
        $clientId = 'php_web_' . uniqid('', true);

        // Fail fast agar request API tidak terasa lambat ketika MQTT sedang bermasalah.
        $settings = (new \PhpMqtt\Client\ConnectionSettings())
            ->setUsername(MQTT_USER)
            ->setPassword(MQTT_PASS)
            ->setKeepAliveInterval(10)
            ->setConnectTimeout(2);

        if (defined('MQTT_USE_TLS') && MQTT_USE_TLS) {
            $settings->setUseTls(true);
        }

        $mqtt = new \PhpMqtt\Client\MqttClient(
            MQTT_HOST,
            MQTT_PORT,
            $clientId,
            \PhpMqtt\Client\MqttClient::MQTT_3_1_1
        );

        $mqtt->connect($settings, true);
        $mqtt->publish($topic, smartparking_json($payload), $qos, $retain);
        $mqtt->disconnect();

        return true;
    } catch (Throwable $e) {
        // Jangan menampilkan password/credential ke browser.
        return false;
    }
}

function smartparking_publish_event(string $event, string $source = 'api.php', array $extra = []): bool
{
    $payload = array_merge([
        'event' => $event,
        'source' => $source,
        'server_time' => date('c'),
    ], $extra);

    return smartparking_mqtt_publish(MQTT_TOPIC_SERVER_EVENT, $payload, 0, false);
}

function smartparking_get_slot_state_payload(PDO $conn, string $source = 'api.php'): array
{
    $expiredAt = date('Y-m-d H:i:s', time() - 60);

    $slots = $conn->query("SELECT * FROM slot ORDER BY slot_nomor ASC LIMIT 4")
        ->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("
        SELECT slot_id, status, kode_booking, created_at
        FROM reservasi
        WHERE status = 'check-in'
           OR (status = 'pending' AND created_at >= ?)
    ");
    $stmt->execute([$expiredAt]);
    $reservasiAktif = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($reservasiAktif as $r) {
        $map[$r['slot_id']] = $r;
    }

    $resultSlots = [];

    foreach ($slots as $s) {
        $terisiFisik = ($s['terisi'] === true || $s['terisi'] === 't' || $s['terisi'] == 1);
        $status = 'kosong';
        $reservasiStatus = null;
        $kodeBooking = null;

        if ($terisiFisik) {
            $status = 'terisi';
        } elseif (isset($map[$s['id']])) {
            $reservasiStatus = $map[$s['id']]['status'] ?? null;
            $kodeBooking = $map[$s['id']]['kode_booking'] ?? null;

            if ($reservasiStatus === 'pending') {
                $status = 'reserved';
            } elseif ($reservasiStatus === 'check-in') {
                // Untuk parkir langsung PL-/permanen, sensor fisik tetap penentu slot terisi.
                $status = (strpos((string)$kodeBooking, 'PL-') === 0) ? 'kosong' : 'reserved';
            }
        }

        $resultSlots[] = [
            'slot_id' => (int)$s['id'],
            'slot_nomor' => (int)$s['slot_nomor'],
            'terisi' => $status === 'terisi',
            'status' => $status,
            'reservasi_status' => $reservasiStatus,
            'kode_booking' => $kodeBooking,
        ];
    }

    return [
        'event' => 'slot_state',
        'slots' => $resultSlots,
        'source' => $source,
        'server_time' => date('c'),
    ];
}

function smartparking_publish_slot_state(PDO $conn, string $source = 'api.php'): bool
{
    $payload = smartparking_get_slot_state_payload($conn, $source);
    return smartparking_mqtt_publish(MQTT_TOPIC_SLOT_STATE, $payload, 0, false);
}

function smartparking_publish_refresh(PDO $conn, string $event, string $source = 'api.php', array $extra = []): void
{
    // Event dikirim dulu agar dashboard segera fetch data baru.
    smartparking_publish_event($event, $source, $extra);

    // Slot state tetap dipublish untuk ESP32/browser, tapi jika MQTT gagal API tetap lanjut.
    smartparking_publish_slot_state($conn, $source);
}
?>
