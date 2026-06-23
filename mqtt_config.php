<?php
// =====================================================
// KONFIGURASI MQTT SMART PARKING
// =====================================================
// Isi sesuai data HiveMQ kamu.
// Host diisi TANPA mqtts:// atau wss://
// Contoh: xxxxxxxx.s1.eu.hivemq.cloud

const MQTT_HOST = '07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud';
const MQTT_PORT = 8883;
const MQTT_USER = 'smart_parking';
const MQTT_PASS = 'Smartparking2026';
const MQTT_USE_TLS = true;

// Untuk browser / MQTT WebSocket.
// HiveMQ Cloud biasanya memakai port 8884 dengan path /mqtt.
const MQTT_WS_HOST = '07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud:8884/mqtt';
const MQTT_WS_PORT = 8884;
const MQTT_WS_PATH = '/mqtt';
const MQTT_WS_USE_SSL = true;

// Topic standar sistem.
const MQTT_TOPIC_SLOT_STATUS   = 'smartparking/slot/status';
const MQTT_TOPIC_GATE_SCAN     = 'smartparking/gate/scan';
const MQTT_TOPIC_GATE_RESPONSE = 'smartparking/gate/response';
const MQTT_TOPIC_SLOT_REQUEST  = 'smartparking/slot/request';
const MQTT_TOPIC_SLOT_STATE    = 'smartparking/server/slot/state';
const MQTT_TOPIC_SERVER_EVENT  = 'smartparking/server/event';
?>
