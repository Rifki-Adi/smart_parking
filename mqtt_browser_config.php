<?php
require_once __DIR__ . '/mqtt_config.php';
header('Content-Type: application/javascript');
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
