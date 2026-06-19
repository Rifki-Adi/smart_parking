<?php
header('Content-Type: application/javascript');

$configFile = __DIR__ . '/mqtt_config.php';
if (!file_exists($configFile)) {
    echo "window.SMARTPARKING_MQTT = null;\n";
    echo "console.log('[MQTT WEB] mqtt_config.php belum ada, realtime browser nonaktif.');\n";
    exit;
}

require_once $configFile;

if (!defined('MQTT_TOPIC_SERVER_EVENT')) define('MQTT_TOPIC_SERVER_EVENT', 'smartparking/server/event');
if (!defined('MQTT_TOPIC_SLOT_STATE')) define('MQTT_TOPIC_SLOT_STATE', 'smartparking/server/slot/state');
if (!defined('MQTT_WS_PATH')) define('MQTT_WS_PATH', '/mqtt');
if (!defined('MQTT_WS_USE_SSL')) define('MQTT_WS_USE_SSL', true);

$required = ['MQTT_WS_HOST', 'MQTT_WS_PORT', 'MQTT_USER', 'MQTT_PASS'];
foreach ($required as $constName) {
    if (!defined($constName)) {
        echo "window.SMARTPARKING_MQTT = null;\n";
        echo "console.log('[MQTT WEB] Konfigurasi {$constName} belum diisi.');\n";
        exit;
    }
}
?>
window.SMARTPARKING_MQTT = {
    host: <?= json_encode(MQTT_WS_HOST) ?>,
    port: <?= (int) MQTT_WS_PORT ?>,
    path: <?= json_encode(MQTT_WS_PATH) ?>,
    useSSL: <?= MQTT_WS_USE_SSL ? 'true' : 'false' ?>,
    username: <?= json_encode(MQTT_USER) ?>,
    password: <?= json_encode(MQTT_PASS) ?>,
    topicEvent: <?= json_encode(MQTT_TOPIC_SERVER_EVENT) ?>,
    topicSlotState: <?= json_encode(MQTT_TOPIC_SLOT_STATE) ?>
};
