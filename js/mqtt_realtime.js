// =====================================================
// MQTT REALTIME CLIENT UNTUK DASHBOARD WEBSITE - OPTIMAL
// =====================================================
// Membutuhkan:
// 1. Paho MQTT JS
// 2. mqtt_browser_config.php sebelum file ini

(function () {
    let mqttClient = null;
    let reconnectTimer = null;
    let refreshTimer = null;
    let reconnectDelay = 2000;
    let lastRefreshAt = 0;
    let lastEventKey = '';

    const DEBUG_MQTT = false;
    const MIN_REFRESH_GAP_MS = 700;
    const DEBOUNCE_MS = 350;

    function log() {
        if (DEBUG_MQTT) console.log.apply(console, arguments);
    }

    function hasConfig() {
        return typeof window.SMARTPARKING_MQTT === 'object' && window.SMARTPARKING_MQTT !== null;
    }

    function getEventName(topic, payload) {
        if (payload && payload.event) return String(payload.event);
        return String(topic || 'mqtt_event');
    }

    function debounceRefresh(eventName, payload) {
        if (document.hidden) return;

        const now = Date.now();
        const eventKey = eventName + '|' + JSON.stringify(payload || {}).slice(0, 300);

        // Abaikan event identik yang datang sangat berdekatan.
        if (eventKey === lastEventKey && (now - lastRefreshAt) < 1200) {
            return;
        }

        const runRefresh = function () {
            lastRefreshAt = Date.now();
            lastEventKey = eventKey;
            if (typeof window.smartParkingRealtimeRefresh === 'function') {
                window.smartParkingRealtimeRefresh(eventName || 'mqtt_event', payload || null);
            }
        };

        clearTimeout(refreshTimer);

        if ((now - lastRefreshAt) >= MIN_REFRESH_GAP_MS) {
            refreshTimer = setTimeout(runRefresh, DEBOUNCE_MS);
        } else {
            refreshTimer = setTimeout(runRefresh, MIN_REFRESH_GAP_MS);
        }
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
            log('[MQTT WEB] Koneksi terputus:', responseObject.errorMessage || responseObject.errorCode);
            clearTimeout(reconnectTimer);
            reconnectTimer = setTimeout(window.smartParkingStartMqttRealtime, reconnectDelay);
            reconnectDelay = Math.min(reconnectDelay + 1000, 10000);
        };

        mqttClient.onMessageArrived = function (message) {
            let payload = null;
            try {
                payload = JSON.parse(message.payloadString || '{}');
            } catch (e) {
                payload = null;
            }

            log('[MQTT WEB] Topic:', message.destinationName, 'Payload:', payload || message.payloadString);

            if (message.destinationName === cfg.topicEvent || message.destinationName === cfg.topicSlotState) {
                debounceRefresh(getEventName(message.destinationName, payload), payload);
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
                reconnectDelay = 2000;
                log('[MQTT WEB] Terhubung ke HiveMQ WebSocket');
                mqttClient.subscribe(cfg.topicEvent, { qos: 0 });
                mqttClient.subscribe(cfg.topicSlotState, { qos: 0 });
            },
            onFailure: function (err) {
                log('[MQTT WEB] Gagal konek:', err.errorMessage || err.errorCode);
                clearTimeout(reconnectTimer);
                reconnectTimer = setTimeout(window.smartParkingStartMqttRealtime, reconnectDelay);
                reconnectDelay = Math.min(reconnectDelay + 1000, 10000);
            }
        });
    };

    window.smartParkingStopMqttRealtime = function smartParkingStopMqttRealtime() {
        clearTimeout(reconnectTimer);
        clearTimeout(refreshTimer);
        if (mqttClient && mqttClient.isConnected && mqttClient.isConnected()) {
            mqttClient.disconnect();
        }
        mqttClient = null;
    };
})();
