// =====================================================
// MQTT REALTIME CLIENT UNTUK DASHBOARD WEBSITE - OPTIMAL
// =====================================================
// Tujuan:
// - Tetap realtime via HiveMQ WebSocket.
// - Tidak membuat fetch bertumpuk.
// - Tidak spam console log.
// - Tidak refresh dua kali saat bridge publish slot_state + server_event.

(function () {
    let mqttClient = null;
    let reconnectTimer = null;
    let refreshTimer = null;
    let lastInfo = null;
    let connecting = false;

    function hasConfig() {
        return typeof window.SMARTPARKING_MQTT !== 'undefined';
    }

    function debounceRefresh(info) {
        lastInfo = info || lastInfo;
        clearTimeout(refreshTimer);

        refreshTimer = setTimeout(() => {
            if (document.hidden) return;
            if (typeof window.smartParkingRealtimeRefresh === 'function') {
                window.smartParkingRealtimeRefresh(lastInfo || { event: 'mqtt_event' });
            }
            lastInfo = null;
        }, 350);
    }

    window.smartParkingStartMqttRealtime = function smartParkingStartMqttRealtime() {
        if (!hasConfig() || typeof Paho === 'undefined' || typeof Paho.MQTT === 'undefined') {
            return;
        }

        if (connecting) return;

        if (mqttClient && mqttClient.isConnected && mqttClient.isConnected()) {
            return;
        }

        const cfg = window.SMARTPARKING_MQTT;
        const clientId = 'web_' + Math.random().toString(16).substring(2) + '_' + Date.now();

        mqttClient = new Paho.MQTT.Client(cfg.host, Number(cfg.port), cfg.path || '/mqtt', clientId);

        mqttClient.onConnectionLost = function () {
            connecting = false;
            clearTimeout(reconnectTimer);
            if (!document.hidden) {
                reconnectTimer = setTimeout(window.smartParkingStartMqttRealtime, 4000);
            }
        };

        mqttClient.onMessageArrived = function (message) {
            const topic = message.destinationName;

            if (topic !== cfg.topicEvent && topic !== cfg.topicSlotState) {
                return;
            }

            let payload = {};
            try {
                payload = JSON.parse(message.payloadString || '{}');
            } catch (e) {
                payload = {};
            }

            debounceRefresh({
                topic: topic,
                event: payload.event || 'mqtt_event',
                payload: payload
            });
        };

        connecting = true;
        mqttClient.connect({
            userName: cfg.username,
            password: cfg.password,
            useSSL: !!cfg.useSSL,
            cleanSession: true,
            keepAliveInterval: 30,
            timeout: 5,
            onSuccess: function () {
                connecting = false;
                mqttClient.subscribe(cfg.topicEvent, { qos: 0 });
                mqttClient.subscribe(cfg.topicSlotState, { qos: 0 });
            },
            onFailure: function () {
                connecting = false;
                clearTimeout(reconnectTimer);
                if (!document.hidden) {
                    reconnectTimer = setTimeout(window.smartParkingStartMqttRealtime, 4000);
                }
            }
        });
    };

    window.smartParkingStopMqttRealtime = function smartParkingStopMqttRealtime() {
        clearTimeout(reconnectTimer);
        clearTimeout(refreshTimer);
        connecting = false;

        if (mqttClient && mqttClient.isConnected && mqttClient.isConnected()) {
            mqttClient.disconnect();
        }
        mqttClient = null;
    };
})();
