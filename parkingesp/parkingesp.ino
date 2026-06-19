#include <ESP32Servo.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// ========================================================================================
// 1. KONFIGURASI WIFI DAN MQTT
// ========================================================================================
const char* WIFI_SSID = "DR";
const char* WIFI_PASS = "kitaaja123";

// Isi host HiveMQ tanpa mqtts://
// Contoh: xxxxxxxx.s1.eu.hivemq.cloud
const char* MQTT_SERVER = "07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud";
const int   MQTT_PORT   = 8883;
const char* MQTT_USER   = "smart_parking";
const char* MQTT_PASS   = "Smartparking2026";

const char* TOPIC_SLOT_STATUS       = "smartparking/slot/status";
const char* TOPIC_GATE_SCAN         = "smartparking/gate/scan";
const char* TOPIC_GATE_RESPONSE     = "smartparking/gate/response";
const char* TOPIC_SERVER_SLOT_STATE = "smartparking/server/slot/state";
const char* TOPIC_SLOT_REQUEST      = "smartparking/slot/request";

// ========================================================================================
// 2. KONFIGURASI PIN
// ========================================================================================
#define GM65_IN_RX   16
#define GM65_IN_TX   17
#define GM65_OUT_RX  25
#define GM65_OUT_TX  26
#define BAUD_GM65    115200

#define SERVO_IN_PIN   13
#define SERVO_OUT_PIN  12

#define IR_SLOT_1  34
#define IR_SLOT_2  35
#define IR_SLOT_3  32
#define IR_SLOT_4  33

#define SR_DATA   23
#define SR_CLOCK  18
#define SR_LATCH   5

// ========================================================================================
// 3. KONFIGURASI SISTEM
// ========================================================================================
#define TOTAL_SLOT              4
#define SERVO_BUKA_MS           5000
#define IR_CHECK_MS             200
#define SLOT_SYNC_MS            15000
#define HARDWARE_SEND_COOLDOWN  1000

#define SERVO_TUTUP  90
#define SERVO_BUKA    0

// ========================================================================================
// 4. OBJECT DAN VARIABEL GLOBAL
// ========================================================================================
HardwareSerial ScannerMasuk(2);
HardwareSerial ScannerKeluar(1);

Servo servoMasuk;
Servo servoKeluar;

LiquidCrystal_I2C lcd(0x27, 20, 4);

WiFiClientSecure secureClient;
PubSubClient mqtt(secureClient);

int statusSlot[TOTAL_SLOT] = {0, 0, 0, 0};
int lastPhysicalState[TOTAL_SLOT] = {-1, -1, -1, -1};

int irPin[TOTAL_SLOT] = {
  IR_SLOT_1,
  IR_SLOT_2,
  IR_SLOT_3,
  IR_SLOT_4
};

String bufferMasuk  = "";
String bufferKeluar = "";

bool servoMasukAktif  = false;
bool servoKeluarAktif = false;
bool isLcdShowingMsg  = false;
bool wifiConnected    = false;

unsigned long timerMasuk          = 0;
unsigned long timerKeluar         = 0;
unsigned long lastIRCheck         = 0;
unsigned long lastSlotSync        = 0;
unsigned long lastHardwareSend    = 0;
unsigned long lcdMessageTimer     = 0;
unsigned long lastLcdIdleUpdate   = 0;

// Variabel untuk menunggu respons validasi QR dari MQTT
bool waitingGateResponse = false;
String pendingQr         = "";
String pendingGate       = "";
String lastGateResult    = "";
unsigned long gateRequestTimer = 0;

// ========================================================================================
// 5. FUNGSI BANTUAN LCD DAN STRING
// ========================================================================================
String fitLine(String text) {
  if (text.length() > 20) {
    return text.substring(0, 20);
  }

  while (text.length() < 20) {
    text += " ";
  }

  return text;
}

void printCenter(int row, String text) {
  if (text.length() > 20) {
    text = text.substring(0, 20);
  }

  int pad = (20 - text.length()) / 2;
  if (pad < 0) pad = 0;

  lcd.setCursor(0, row);
  lcd.print("                    ");

  lcd.setCursor(pad, row);
  lcd.print(text);
}

void showLcdMessage(String baris1, String baris2, String baris3, String baris4) {
  lcd.clear();
  printCenter(0, baris1);
  printCenter(1, baris2);
  printCenter(2, baris3);
  printCenter(3, baris4);

  isLcdShowingMsg = true;
  lcdMessageTimer = millis();
}

String escapeJson(String value) {
  value.replace("\\", "\\\\");
  value.replace("\"", "\\\"");
  value.replace("\n", "");
  value.replace("\r", "");
  return value;
}

String extractJsonValue(String payload, String key) {
  String pattern = "\"" + key + "\":\"";
  int idx = payload.indexOf(pattern);

  if (idx == -1) return "";

  int start = idx + pattern.length();
  int end = payload.indexOf("\"", start);

  if (end == -1) return "";

  return payload.substring(start, end);
}

// ========================================================================================
// 6. LCD IDLE DAN LED STATUS SLOT
// ========================================================================================
void updateLcdIdle() {
  if (isLcdShowingMsg) return;

  int sisaSlot = 0;

  for (int i = 0; i < TOTAL_SLOT; i++) {
    if (statusSlot[i] == 0) {
      sisaSlot++;
    }
  }

  lcd.setCursor(0, 0);
  lcd.print(fitLine("=== SMART PARKING ==="));

  lcd.setCursor(0, 1);
  lcd.print(fitLine("Slot Tersedia: " + String(sisaSlot)));

  lcd.setCursor(0, 2);
  lcd.print(fitLine("Scan QR"));

  String line4 = "";

  for (int i = 0; i < TOTAL_SLOT; i++) {
    line4 += "S";
    line4 += String(i + 1);
    line4 += ":";

    if (statusSlot[i] == 1) {
      line4 += "I ";       // I = Isi / Terisi
    } else if (statusSlot[i] == 2) {
      line4 += "R ";       // R = Reserved
    } else {
      line4 += "K ";       // K = Kosong
    }
  }

  lcd.setCursor(0, 3);
  lcd.print(fitLine(line4));
}

void updateTrafficLight() {
  byte ic1 = 0;
  byte ic2 = 0;

  // Slot 1
  if (statusSlot[0] == 1) ic1 |= 0b00000001;
  else if (statusSlot[0] == 2) ic1 |= 0b00000010;
  else ic1 |= 0b00000100;

  // Slot 2
  if (statusSlot[1] == 1) ic1 |= 0b00001000;
  else if (statusSlot[1] == 2) ic1 |= 0b00010000;
  else ic1 |= 0b00100000;

  // Slot 3
  if (statusSlot[2] == 1) ic2 |= 0b00000001;
  else if (statusSlot[2] == 2) ic2 |= 0b00000010;
  else ic2 |= 0b00000100;

  // Slot 4
  if (statusSlot[3] == 1) ic2 |= 0b00001000;
  else if (statusSlot[3] == 2) ic2 |= 0b00010000;
  else ic2 |= 0b00100000;

  digitalWrite(SR_LATCH, LOW);
  shiftOut(SR_DATA, SR_CLOCK, MSBFIRST, ic2);
  shiftOut(SR_DATA, SR_CLOCK, MSBFIRST, ic1);
  digitalWrite(SR_LATCH, HIGH);
}

// ========================================================================================
// 7. PARSE STATUS SLOT DARI SERVER VIA MQTT
// ========================================================================================
void parseServerSlotState(String payload) {
  bool statusBerubah = false;

  for (int i = 0; i < TOTAL_SLOT; i++) {
    String searchStr1 = "\"slot_nomor\":" + String(i + 1);
    String searchStr2 = "\"slot_nomor\":\"" + String(i + 1) + "\"";

    int slotIdx = payload.indexOf(searchStr1);

    if (slotIdx == -1) {
      slotIdx = payload.indexOf(searchStr2);
    }

    if (slotIdx == -1) {
      continue;
    }

    int objEnd = payload.indexOf("}", slotIdx);

    if (objEnd == -1) {
      objEnd = payload.length() - 1;
    }

    String oneSlot = payload.substring(slotIdx, objEnd);
    bool terisiServer = oneSlot.indexOf("\"terisi\":true") != -1;
    String state = extractJsonValue(oneSlot, "status");

    int oldStatus = statusSlot[i];

    // Prioritas status fisik jika sensor membaca terisi.
    if (digitalRead(irPin[i]) == LOW || terisiServer) {
      statusSlot[i] = 1;
    } else {
      if (state.indexOf("reserved") != -1 || state.indexOf("reservasi") != -1) {
        statusSlot[i] = 2;
      } else {
        statusSlot[i] = 0;
      }
    }

    if (oldStatus != statusSlot[i]) {
      statusBerubah = true;
    }
  }

  if (statusBerubah) {
    updateTrafficLight();

    if (!isLcdShowingMsg) {
      updateLcdIdle();
    }
  }
}

// ========================================================================================
// 8. KONEKSI WIFI DAN MQTT
// ========================================================================================
void connectWiFi() {
  lcd.clear();
  printCenter(1, "Menghubungkan WiFi");
  printCenter(2, WIFI_SSID);

  Serial.print("[WiFi] ");
  Serial.println(WIFI_SSID);

  WiFi.begin(WIFI_SSID, WIFI_PASS);

  int attempt = 0;

  while (WiFi.status() != WL_CONNECTED && attempt < 20) {
    delay(500);
    Serial.print(".");
    attempt++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    wifiConnected = true;

    Serial.println("\n[WiFi] Connected");
    Serial.print("[WiFi] IP: ");
    Serial.println(WiFi.localIP());

    lcd.clear();
    printCenter(1, "WiFi Terhubung");
    printCenter(2, "Sistem Siap");
  } else {
    wifiConnected = false;

    Serial.println("\n[WiFi] Failed");

    lcd.clear();
    printCenter(1, "WiFi Gagal");
    printCenter(2, "Mode Offline");
  }

  delay(1500);
  lcd.clear();
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  String topicStr = String(topic);
  String message = "";

  for (unsigned int i = 0; i < length; i++) {
    message += (char)payload[i];
  }

  Serial.print("[MQTT IN] Topic: ");
  Serial.println(topicStr);
  Serial.print("[MQTT IN] Message: ");
  Serial.println(message);

  // Respons validasi QR dari bridge/server.
  if (topicStr == TOPIC_GATE_RESPONSE) {
    String qrCode = extractJsonValue(message, "qr_code");
    String gate   = extractJsonValue(message, "gate");
    String status = extractJsonValue(message, "status");
    String pesan  = extractJsonValue(message, "message");

    if (pesan.length() == 0) {
      pesan = "Terjadi Kesalahan";
    }

    bool matchQr   = (qrCode.length() == 0 || qrCode == pendingQr);
    bool matchGate = (gate.length() == 0 || gate == pendingGate);

    if (waitingGateResponse && matchQr && matchGate) {
      if (status == "success") {
        lastGateResult = "success|" + pesan;
      } else {
        lastGateResult = "denied|" + pesan;
      }

      waitingGateResponse = false;
    }
  }

  // Status slot dari server/bridge. Bagian ini opsional.
  if (topicStr == TOPIC_SERVER_SLOT_STATE) {
    parseServerSlotState(message);
  }
}

void connectMQTT() {
  if (!wifiConnected) return;

  int attempt = 0;

  while (!mqtt.connected() && attempt < 5) {
    Serial.print("[MQTT] Menghubungkan ke HiveMQ... ");

    String clientId = "ESP32-SmartParking-";
    clientId += String((uint32_t)ESP.getEfuseMac(), HEX);
    clientId += "-";
    clientId += String(random(0xffff), HEX);

    if (mqtt.connect(clientId.c_str(), MQTT_USER, MQTT_PASS)) {
      Serial.println("berhasil");

      mqtt.subscribe(TOPIC_GATE_RESPONSE);
      mqtt.subscribe(TOPIC_SERVER_SLOT_STATE);

      mqtt.publish(
        "smartparking/test",
        "{\"status\":\"berhasil\",\"message\":\"ESP32 terhubung ke HiveMQ\"}"
      );

      Serial.println("[MQTT] Subscribe berhasil");
    } else {
      Serial.print("gagal, rc=");
      Serial.println(mqtt.state());

      attempt++;
      delay(2000);
    }
  }
}

// ========================================================================================
// 9. SENSOR IR DAN PUBLISH STATUS SLOT
// ========================================================================================
void sendSlotStatusToMQTT() {
  if (!wifiConnected) return;

  if (!mqtt.connected()) {
    connectMQTT();
  }

  if (!mqtt.connected()) {
    Serial.println("[MQTT SLOT] Gagal, MQTT belum terkoneksi");
    return;
  }

  String payload = "{";
  payload += "\"s1\":" + String(lastPhysicalState[0]) + ",";
  payload += "\"s2\":" + String(lastPhysicalState[1]) + ",";
  payload += "\"s3\":" + String(lastPhysicalState[2]) + ",";
  payload += "\"s4\":" + String(lastPhysicalState[3]);
  payload += "}";

  bool ok = mqtt.publish(TOPIC_SLOT_STATUS, payload.c_str());

  if (ok) {
    Serial.print("[MQTT SLOT] Publish OK: ");
    Serial.println(payload);
  } else {
    Serial.print("[MQTT SLOT] Publish Gagal: ");
    Serial.println(payload);
  }
}

void updateIRSensor() {
  bool hardwareChanged = false;

  for (int i = 0; i < TOTAL_SLOT; i++) {
    bool terisi = digitalRead(irPin[i]) == LOW;
    int currentState = terisi ? 1 : 0;

    if (currentState != lastPhysicalState[i]) {
      hardwareChanged = true;
      lastPhysicalState[i] = currentState;
    }

    if (terisi) {
      statusSlot[i] = 1;
    } else {
      if (statusSlot[i] != 2) {
        statusSlot[i] = 0;
      }
    }
  }

  if (hardwareChanged && millis() - lastHardwareSend >= HARDWARE_SEND_COOLDOWN) {
    lastHardwareSend = millis();

    Serial.println("[HW SYNC] SENSOR BERUBAH");

    sendSlotStatusToMQTT();

    if (!isLcdShowingMsg) {
      updateLcdIdle();
    }
  }
}

void syncSlotDariServer() {
  // ESP32 tidak lagi request langsung ke api.php.
  // ESP32 hanya mengirim request via MQTT.
  if (!wifiConnected) return;

  if (!mqtt.connected()) {
    connectMQTT();
  }

  if (mqtt.connected()) {
    mqtt.publish(TOPIC_SLOT_REQUEST, "{\"source\":\"esp32\",\"request\":\"slot_state\"}");
    Serial.println("[MQTT] Request slot state");
  }
}

// ========================================================================================
// 10. SCAN QR DAN VALIDASI LEWAT MQTT
// ========================================================================================
String kirimQRkeServer(String qr, String gate) {
  if (!wifiConnected) {
    return "no_wifi";
  }

  if (!mqtt.connected()) {
    connectMQTT();
  }

  if (!mqtt.connected()) {
    return "error|MQTT Tidak Terhubung";
  }

  pendingQr = qr;
  pendingGate = gate;
  lastGateResult = "";
  waitingGateResponse = true;
  gateRequestTimer = millis();

  String payload = "{";
  payload += "\"qr_code\":\"" + escapeJson(qr) + "\",";
  payload += "\"gate\":\"" + escapeJson(gate) + "\"";
  payload += "}";

  bool ok = mqtt.publish(TOPIC_GATE_SCAN, payload.c_str());

  if (!ok) {
    waitingGateResponse = false;
    return "error|Publish QR Gagal";
  }

  Serial.print("[MQTT QR] Publish: ");
  Serial.println(payload);

  while (waitingGateResponse && millis() - gateRequestTimer < 5000) {
    mqtt.loop();
    delay(10);
  }

  if (waitingGateResponse) {
    waitingGateResponse = false;
    return "error|Timeout Server";
  }

  if (lastGateResult.length() == 0) {
    return "error|Response Kosong";
  }

  return lastGateResult;
}

void handleScanMasuk(String qr) {
  Serial.println("[MASUK]");

  showLcdMessage("MEMPROSES", "MOHON TUNGGU", "", "");

  String response = kirimQRkeServer(qr, "in");
  int sepIndex = response.indexOf("|");

  String hasil = (sepIndex != -1) ? response.substring(0, sepIndex) : response;
  String pesan = (sepIndex != -1) ? response.substring(sepIndex + 1) : "";

  if (hasil == "success") {
    showLcdMessage("AKSES DITERIMA", "SILAKAN MASUK", "PALANG TERBUKA", "");

    if (!servoMasukAktif) {
      servoMasuk.write(SERVO_BUKA);
      servoMasukAktif = true;
      timerMasuk = millis();
    }
  } else if (hasil == "no_wifi") {
    showLcdMessage("MODE OFFLINE", "SILAKAN MASUK", "PALANG TERBUKA", "");

    if (!servoMasukAktif) {
      servoMasuk.write(SERVO_BUKA);
      servoMasukAktif = true;
      timerMasuk = millis();
    }
  } else {
    showLcdMessage("AKSES DITOLAK", "TIDAK VALID", pesan, "");
  }
}

void handleScanKeluar(String qr) {
  Serial.println("[KELUAR]");

  showLcdMessage("MEMPROSES", "MOHON TUNGGU", "", "");

  String response = kirimQRkeServer(qr, "out");
  int sepIndex = response.indexOf("|");

  String hasil = (sepIndex != -1) ? response.substring(0, sepIndex) : response;
  String pesan = (sepIndex != -1) ? response.substring(sepIndex + 1) : "";

  if (hasil == "success") {
    showLcdMessage("AKSES DITERIMA", "SILAKAN KELUAR", "SALDO -Rp3000", "PALANG TERBUKA");

    if (!servoKeluarAktif) {
      servoKeluar.write(SERVO_BUKA);
      servoKeluarAktif = true;
      timerKeluar = millis();
    }
  } else if (hasil == "no_wifi") {
    showLcdMessage("MODE OFFLINE", "SILAKAN KELUAR", "PALANG TERBUKA", "");

    if (!servoKeluarAktif) {
      servoKeluar.write(SERVO_BUKA);
      servoKeluarAktif = true;
      timerKeluar = millis();
    }
  } else {
    showLcdMessage("AKSES DITOLAK", "TIDAK VALID", pesan, "");
  }
}

void bacaScanner(HardwareSerial &port, String &buffer, void (*handler)(String)) {
  while (port.available()) {
    char c = port.read();

    if (c == '\n' || c == '\r') {
      buffer.trim();

      if (buffer.length() > 0) {
        handler(buffer);
        buffer = "";
      }
    } else {
      buffer += c;
    }
  }
}

// ========================================================================================
// 11. SERVO
// ========================================================================================
void cekServo(Servo &srv, bool &aktif, unsigned long &timer, const char* nama) {
  if (aktif && millis() - timer >= SERVO_BUKA_MS) {
    srv.write(SERVO_TUTUP);
    aktif = false;

    Serial.print("[");
    Serial.print(nama);
    Serial.println("] TUTUP");

    syncSlotDariServer();
  }
}

// ========================================================================================
// 12. SETUP
// ========================================================================================
void setup() {
  Serial.begin(115200);
  delay(1000);

  Serial.println("=== SMART PARKING MQTT ===");

  lcd.init();
  lcd.backlight();
  lcd.clear();

  printCenter(1, "MEMULAI SISTEM");
  printCenter(2, "SMART PARKING");

  connectWiFi();

  // Untuk prototype TLS HiveMQ.
  // Untuk produksi lebih aman menggunakan sertifikat CA.
  secureClient.setInsecure();

  mqtt.setServer(MQTT_SERVER, MQTT_PORT);
  mqtt.setCallback(mqttCallback);
  mqtt.setBufferSize(2048);

  connectMQTT();

  ScannerMasuk.begin(BAUD_GM65, SERIAL_8N1, GM65_IN_RX, GM65_IN_TX);
  ScannerKeluar.begin(BAUD_GM65, SERIAL_8N1, GM65_OUT_RX, GM65_OUT_TX);

  servoMasuk.attach(SERVO_IN_PIN, 500, 2400);
  servoKeluar.attach(SERVO_OUT_PIN, 500, 2400);

  servoMasuk.write(SERVO_TUTUP);
  servoKeluar.write(SERVO_TUTUP);

  for (int i = 0; i < TOTAL_SLOT; i++) {
    pinMode(irPin[i], INPUT);
  }

  pinMode(SR_DATA, OUTPUT);
  pinMode(SR_LATCH, OUTPUT);
  pinMode(SR_CLOCK, OUTPUT);

  randomSeed(micros());

  updateIRSensor();
  updateTrafficLight();
  syncSlotDariServer();
  updateLcdIdle();
}

// ========================================================================================
// 13. LOOP
// ========================================================================================
void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    wifiConnected = false;
    connectWiFi();
    connectMQTT();
    updateLcdIdle();
  }

  if (wifiConnected && !mqtt.connected()) {
    connectMQTT();
  }

  if (mqtt.connected()) {
    mqtt.loop();
  }

  bacaScanner(ScannerMasuk, bufferMasuk, handleScanMasuk);
  bacaScanner(ScannerKeluar, bufferKeluar, handleScanKeluar);

  cekServo(servoMasuk, servoMasukAktif, timerMasuk, "MASUK");
  cekServo(servoKeluar, servoKeluarAktif, timerKeluar, "KELUAR");

  if (millis() - lastIRCheck >= IR_CHECK_MS) {
    lastIRCheck = millis();
    updateIRSensor();
    updateTrafficLight();
  }

  if (!isLcdShowingMsg && millis() - lastLcdIdleUpdate >= 2000) {
    lastLcdIdleUpdate = millis();
    updateLcdIdle();
  }

  if (isLcdShowingMsg && millis() - lcdMessageTimer >= 4000) {
    isLcdShowingMsg = false;
    lcd.clear();
    updateLcdIdle();
  }

  if (millis() - lastSlotSync >= SLOT_SYNC_MS) {
    lastSlotSync = millis();
    syncSlotDariServer();
  }

  delay(10);
}
