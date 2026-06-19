// =====================================================
// MQTT REALTIME CLIENT UNTUK DASHBOARD WEBSITE
// =====================================================
// Membutuhkan:
// 1. Paho MQTT JS
// 2. mqtt_browser_config.php sebelum file ini

(function () {
    let mqttClient = null;
    let reconnectTimer = null;
    let refreshTimer = null;

    function hasConfig() {
        return typeof window.SMARTPARKING_MQTT !== 'undefined';
    }

    function debounceRefresh(reason) {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => {
            if (typeof window.smartParkingRealtimeRefresh === 'function') {
                window.smartParkingRealtimeRefresh(reason || 'mqtt_event');
            }
        }, 150);
    }

    window.smartParkingStartMqttRealtime = function smartParkingStartMqttRealtime() {
        if (!hasConfig() || typeof Paho === 'undefined' || typeof Paho.MQTT === 'undefined') {
            console.log('[MQTT WEB] Config/Paho belum tersedia, realtime MQTT browser tidak aktif.');
            return;
        }

        if (mqttClient && mqttClient.isConnected && mqttClient.isConnected()) {
            return;
        }

        const cfg = window.SMARTPARKING_MQTT;
        const clientId = 'web_' + Math.random().toString(16).substring(2) + '_' + Date.now();

        mqttClient = new Paho.MQTT.Client(cfg.host, Number(cfg.port), cfg.path || '/mqtt', clientId);

        mqttClient.onConnectionLost = function (responseObject) {
            console.log('[MQTT WEB] Koneksi terputus:', responseObject.errorMessage || responseObject.errorCode);
            clearTimeout(reconnectTimer);
            reconnectTimer = setTimeout(window.smartParkingStartMqttRealtime, 3000);
        };

        mqttClient.onMessageArrived = function (message) {
            console.log('[MQTT WEB] Topic:', message.destinationName, 'Payload:', message.payloadString);

            if (message.destinationName === cfg.topicEvent || message.destinationName === cfg.topicSlotState) {
                debounceRefresh(message.destinationName);
            }
        };

        mqttClient.connect({
            userName: cfg.username,
            password: cfg.password,
            useSSL: !!cfg.useSSL,
            cleanSession: true,
            keepAliveInterval: 30,
            timeout: 5,
            onSuccess: function () {
                console.log('[MQTT WEB] Terhubung ke HiveMQ WebSocket');
                mqttClient.subscribe(cfg.topicEvent, { qos: 0 });
                mqttClient.subscribe(cfg.topicSlotState, { qos: 0 });
            },
            onFailure: function (err) {
                console.log('[MQTT WEB] Gagal konek:', err.errorMessage || err.errorCode);
                clearTimeout(reconnectTimer);
                reconnectTimer = setTimeout(window.smartParkingStartMqttRealtime, 3000);
            }
        });
    };

    window.smartParkingStopMqttRealtime = function smartParkingStopMqttRealtime() {
        clearTimeout(reconnectTimer);
        if (mqttClient && mqttClient.isConnected && mqttClient.isConnected()) {
            mqttClient.disconnect();
        }
        mqttClient = null;
    };
})();
