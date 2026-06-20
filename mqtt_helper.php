<?php
// =====================================================
// HELPER MQTT UNTUK API / WEBSITE
// =====================================================
// File ini dipakai oleh api.php, cleanup.php, topup.php, dll.
// Jika vendor/autoload.php belum ada, fungsi akan return false
// agar halaman tetap berjalan dan tidak fatal error.

require_once __DIR__ . '/mqtt_config.php';

function smartparking_mqtt_autoload_ready(): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return false;
    }

    require_once $autoload;
    return class_exists('PhpMqtt\\Client\\MqttClient');
}

function smartparking_mqtt_publish(string $topic, array $payload, int $qos = 0, bool $retain = false): bool
{
    if (!smartparking_mqtt_autoload_ready()) {
        return false;
    }

    try {
        $clientId = 'php_web_' . uniqid('', true);

        $settings = (new \PhpMqtt\Client\ConnectionSettings())
            ->setUsername(MQTT_USER)
            ->setPassword(MQTT_PASS)
            ->setKeepAliveInterval(30)
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
        $mqtt->publish($topic, json_encode($payload, JSON_UNESCAPED_UNICODE), $qos, $retain);
        $mqtt->disconnect();

        return true;
    } catch (Throwable $e) {
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

    $stmt = $conn->prepare("\n        SELECT slot_id, status, kode_booking, created_at\n        FROM reservasi\n        WHERE status = 'check-in'\n           OR (status = 'pending' AND created_at >= ?)\n    ");
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
                // Kalau parkir langsung PL- dan sensor tidak membaca mobil, dianggap kosong.
                // Selain itu tetap ditandai reserved agar LED kuning dan sistem tidak double booking.
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
    smartparking_publish_slot_state($conn, $source);
    smartparking_publish_event($event, $source, $extra);
}
?>
