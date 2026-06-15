#include <ESP32Servo.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <time.h>

// =====================================================
// KONFIGURASI WIFI
// Ganti sesuai WiFi kamu.
// =====================================================
const char* WIFI_SSID = "DR";
const char* WIFI_PASS = "kitaaja123";

// =====================================================
// KONFIGURASI MQTT HIVEMQ CLOUD
// Ambil dari HiveMQ Cloud -> Cluster -> Connection Details
// Username/password dari Access Management.
// =====================================================
const char* MQTT_HOST      = "07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud";
const int   MQTT_PORT      = 8883;
const char* MQTT_USER      = "Rifki";
const char* MQTT_PASS      = "Kitaaja123";

// =====================================================
// TOPIC MQTT
// =====================================================
const char* TOPIC_SLOT_UPDATE       = "smartparking/esp32/slot/update";
const char* TOPIC_GATE_IN_SCAN      = "smartparking/esp32/gate/in/scan";
const char* TOPIC_GATE_OUT_SCAN     = "smartparking/esp32/gate/out/scan";
const char* TOPIC_DEVICE_STATUS     = "smartparking/esp32/device/status";
const char* TOPIC_GATE_IN_RESPONSE  = "smartparking/server/gate/in/response";
const char* TOPIC_GATE_OUT_RESPONSE = "smartparking/server/gate/out/response";
const char* TOPIC_SLOT_STATE        = "smartparking/server/slot/state";
const char* TOPIC_SERVER_ALL        = "smartparking/server/#";

// =====================================================
// PIN HARDWARE
// =====================================================
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

#define SR_DATA    23
#define SR_CLOCK   18
#define SR_LATCH    5

#define TOTAL_SLOT     4
#define SERVO_BUKA_MS  5000
#define IR_CHECK_MS    200
#define HARDWARE_SEND_COOLDOWN 1000
#define MQTT_RECONNECT_MS 5000
#define MQTT_HEARTBEAT_MS 5000
#define QR_RESPONSE_TIMEOUT_MS 7000

#define SERVO_TUTUP    90
#define SERVO_BUKA     0

HardwareSerial ScannerMasuk(2);
HardwareSerial ScannerKeluar(1);

Servo servoMasuk;
Servo servoKeluar;

LiquidCrystal_I2C lcd(0x27, 20, 4);

WiFiClientSecure mqttSecureClient;
PubSubClient mqttClient(mqttSecureClient);

// statusSlot: 0 = kosong, 1 = terisi, 2 = reserved
int statusSlot[TOTAL_SLOT] = {0,0,0,0};
int lastPhysicalState[TOTAL_SLOT] = {-1,-1,-1,-1};

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

unsigned long timerMasuk  = 0;
unsigned long timerKeluar = 0;

unsigned long lastIRCheck = 0;
unsigned long lastHardwareSend = 0;
unsigned long lastMQTTReconnect = 0;
unsigned long lastMQTTHeartbeat = 0;

unsigned long lcdMessageTimer = 0;
unsigned long lastLcdIdleUpdate = 0;

bool isLcdShowingMsg = false;
bool wifiConnected = false;
bool timeReady = false;

String pendingInRequestId = "";
String pendingOutRequestId = "";
unsigned long pendingInTimer = 0;
unsigned long pendingOutTimer = 0;

// =====================================================
// DEVICE ID OTOMATIS DARI MAC ADDRESS ESP32
// MQTT tetap membutuhkan client id untuk koneksi,
// tetapi tidak perlu ditulis manual. Client id dibuat otomatis.
// =====================================================
String getDeviceId(){
  String mac = WiFi.macAddress();
  mac.replace(":", "");
  mac.toLowerCase();
  return "esp32_" + mac;
}


// =====================================================
// UTILITAS LCD
// =====================================================
String fitLine(String text){
  if(text.length() > 20){
    return text.substring(0, 20);
  }
  while(text.length() < 20){
    text += " ";
  }
  return text;
}

void printCenter(int row, String text){
  if(text.length() > 20){
    text = text.substring(0, 20);
  }

  int pad = (20 - text.length()) / 2;
  if(pad < 0) pad = 0;

  lcd.setCursor(0, row);
  lcd.print("                    ");

  lcd.setCursor(pad, row);
  lcd.print(text);
}

void showLcdMessage(String baris1, String baris2, String baris3, String baris4){
  lcd.clear();
  printCenter(0, baris1);
  printCenter(1, baris2);
  printCenter(2, baris3);
  printCenter(3, baris4);
  isLcdShowingMsg = true;
  lcdMessageTimer = millis();
}

void updateLcdIdle(){
  if(isLcdShowingMsg) return;

  int sisaSlot = 0;
  for(int i = 0; i < TOTAL_SLOT; i++){
    if(statusSlot[i] == 0){
      sisaSlot++;
    }
  }

  lcd.setCursor(0,0);
  lcd.print(fitLine("=== SMART PARKING ==="));

  lcd.setCursor(0,1);
  lcd.print(fitLine("Slot Tersedia: " + String(sisaSlot)));

  lcd.setCursor(0,2);
  lcd.print(fitLine("Scan QR"));

  String line4 = "";
  for(int i = 0; i < TOTAL_SLOT; i++){
    line4 += "S";
    line4 += String(i + 1);
    line4 += ":";

    if(statusSlot[i] == 1){
      line4 += "I ";
    }
    else if(statusSlot[i] == 2){
      line4 += "R ";
    }
    else{
      line4 += "K ";
    }
  }

  lcd.setCursor(0,3);
  lcd.print(fitLine(line4));
}

// =====================================================
// UTILITAS JSON SEDERHANA TANPA ARDUINOJSON
// =====================================================
String jsonEscape(String text){
  text.replace("\\", "\\\\");
  text.replace("\"", "\\\"");
  text.replace("\n", "");
  text.replace("\r", "");
  return text;
}

String getJsonString(String json, String key){
  String pattern = "\"" + key + "\":\"";
  int idx = json.indexOf(pattern);
  if(idx == -1) return "";

  int start = idx + pattern.length();
  int end = json.indexOf("\"", start);
  if(end == -1) return "";

  return json.substring(start, end);
}

bool getJsonBool(String json, String key){
  String patternTrue = "\"" + key + "\":true";
  String patternFalse = "\"" + key + "\":false";

  if(json.indexOf(patternTrue) != -1) return true;
  if(json.indexOf(patternFalse) != -1) return false;

  String val = getJsonString(json, key);
  val.toLowerCase();
  return (val == "true" || val == "1" || val == "success");
}

String getSlotStatusFromPayload(String payload, int slotNomor){
  String pattern1 = "\"slot_nomor\":" + String(slotNomor);
  String pattern2 = "\"slot_nomor\":\"" + String(slotNomor) + "\"";

  int idx = payload.indexOf(pattern1);
  if(idx == -1){
    idx = payload.indexOf(pattern2);
  }
  if(idx == -1) return "";

  int statusIdx = payload.indexOf("\"status\":\"", idx);
  if(statusIdx == -1) return "";

  int start = statusIdx + 10;
  int end = payload.indexOf("\"", start);
  if(end == -1) return "";

  return payload.substring(start, end);
}

String uint64ToString(unsigned long long value){
  char buffer[32];
  snprintf(buffer, sizeof(buffer), "%llu", value);
  return String(buffer);
}

unsigned long long getEpochMs(){
  time_t now;
  time(&now);

  if(now > 1700000000){
    timeReady = true;
    return ((unsigned long long)now * 1000ULL) + (millis() % 1000);
  }

  return (unsigned long long)millis();
}

// =====================================================
// TRAFFIC LIGHT SHIFT REGISTER
// =====================================================
void updateTrafficLight(){
  byte ic1 = 0;
  byte ic2 = 0;

  if(statusSlot[0] == 1) ic1 |= 0b00000001;
  else if(statusSlot[0] == 2) ic1 |= 0b00000010;
  else ic1 |= 0b00000100;

  if(statusSlot[1] == 1) ic1 |= 0b00001000;
  else if(statusSlot[1] == 2) ic1 |= 0b00010000;
  else ic1 |= 0b00100000;

  if(statusSlot[2] == 1) ic2 |= 0b00000001;
  else if(statusSlot[2] == 2) ic2 |= 0b00000010;
  else ic2 |= 0b00000100;

  if(statusSlot[3] == 1) ic2 |= 0b00001000;
  else if(statusSlot[3] == 2) ic2 |= 0b00010000;
  else ic2 |= 0b00100000;

  digitalWrite(SR_LATCH, LOW);
  shiftOut(SR_DATA, SR_CLOCK, MSBFIRST, ic2);
  shiftOut(SR_DATA, SR_CLOCK, MSBFIRST, ic1);
  digitalWrite(SR_LATCH, HIGH);
}

// =====================================================
// MQTT PUBLISH
// =====================================================
bool publishMQTT(const char* topic, String payload){
  if(!wifiConnected || !mqttClient.connected()){
    Serial.println("[MQTT] Publish gagal, belum terkoneksi");
    return false;
  }

  bool ok = mqttClient.publish(topic, payload.c_str());
  Serial.print("[MQTT PUBLISH] ");
  Serial.print(topic);
  Serial.print(" => ");
  Serial.println(ok ? "OK" : "GAGAL");
  Serial.println(payload);

  return ok;
}

void publishDeviceStatus(String status){
  String payload = "{";
  payload += "\"device_id\":\"" + getDeviceId() + "\",";
  payload += "\"status\":\"" + status + "\",";
  payload += "\"wifi_rssi\":" + String(WiFi.RSSI()) + ",";
  payload += "\"uptime_ms\":" + String(millis()) + ",";
  payload += "\"sent_at\":" + uint64ToString(getEpochMs());
  payload += "}";

  publishMQTT(TOPIC_DEVICE_STATUS, payload);
}

void publishSlotStatusMQTT(){
  String payload = "{";
  payload += "\"device_id\":\"" + getDeviceId() + "\",";
  payload += "\"message_id\":\"slot-" + String((unsigned long)millis()) + "\",";
  payload += "\"slots\":[";

  for(int i = 0; i < TOTAL_SLOT; i++){
    bool terisi = lastPhysicalState[i] == 1;

    payload += "{";
    payload += "\"slot_nomor\":" + String(i + 1) + ",";
    payload += "\"terisi\":" + String(terisi ? "true" : "false") + ",";
    payload += "\"status\":\"" + String(terisi ? "terisi" : "tersedia") + "\"";
    payload += "}";

    if(i < TOTAL_SLOT - 1){
      payload += ",";
    }
  }

  payload += "],";
  payload += "\"sent_at\":" + uint64ToString(getEpochMs());
  payload += "}";

  publishMQTT(TOPIC_SLOT_UPDATE, payload);
}

String createRequestId(String gate){
  return "qr-" + gate + "-" + String((unsigned long)millis());
}

void publishQRScanMQTT(String qr, String gate, String requestId){
  String topic = (gate == "in") ? TOPIC_GATE_IN_SCAN : TOPIC_GATE_OUT_SCAN;

  String payload = "{";
  payload += "\"device_id\":\"" + getDeviceId() + "\",";
  payload += "\"request_id\":\"" + requestId + "\",";
  payload += "\"gate\":\"" + gate + "\",";
  payload += "\"qr_code\":\"" + jsonEscape(qr) + "\",";
  payload += "\"sent_at\":" + uint64ToString(getEpochMs());
  payload += "}";

  publishMQTT(topic.c_str(), payload);
}

// =====================================================
// RESPONSE MQTT DARI SERVER/WORKER
// =====================================================
void bukaPalangMasuk(){
  if(!servoMasukAktif){
    servoMasuk.write(SERVO_BUKA);
    servoMasukAktif = true;
    timerMasuk = millis();
  }
}

void bukaPalangKeluar(){
  if(!servoKeluarAktif){
    servoKeluar.write(SERVO_BUKA);
    servoKeluarAktif = true;
    timerKeluar = millis();
  }
}

void handleGateResponse(String gate, String payload){
  String requestId = getJsonString(payload, "request_id");
  String status = getJsonString(payload, "status");
  String message = getJsonString(payload, "message");
  bool openGate = getJsonBool(payload, "open_gate");

  if(message == ""){
    message = (status == "success") ? "Akses Diterima" : "Akses Ditolak";
  }

  if(gate == "in"){
    if(requestId != "" && pendingInRequestId != "" && requestId != pendingInRequestId){
      Serial.println("[MQTT] Response masuk bukan untuk request aktif");
      return;
    }

    pendingInRequestId = "";

    if(openGate || status == "success"){
      showLcdMessage("AKSES DITERIMA", "SILAKAN MASUK", "PALANG TERBUKA", "");
      bukaPalangMasuk();
    } else {
      showLcdMessage("AKSES DITOLAK", "TIDAK VALID", message, "");
    }
  }

  if(gate == "out"){
    if(requestId != "" && pendingOutRequestId != "" && requestId != pendingOutRequestId){
      Serial.println("[MQTT] Response keluar bukan untuk request aktif");
      return;
    }

    pendingOutRequestId = "";

    if(openGate || status == "success"){
      showLcdMessage("AKSES DITERIMA", "SILAKAN KELUAR", message, "PALANG TERBUKA");
      bukaPalangKeluar();
    } else {
      showLcdMessage("AKSES DITOLAK", "TIDAK VALID", message, "");
    }
  }
}

void handleSlotState(String payload){
  bool changed = false;

  for(int i = 0; i < TOTAL_SLOT; i++){
    String st = getSlotStatusFromPayload(payload, i + 1);
    if(st == "") continue;

    int oldStatus = statusSlot[i];

    bool fisikTerisi = digitalRead(irPin[i]) == LOW;

    if(fisikTerisi){
      statusSlot[i] = 1;
    } else {
      if(st == "reserved" || st == "reserved_me" || st == "reserved_other" || st == "pending"){
        statusSlot[i] = 2;
      } else if(st == "terisi" || st == "check-in"){
        statusSlot[i] = 1;
      } else {
        statusSlot[i] = 0;
      }
    }

    if(oldStatus != statusSlot[i]){
      changed = true;
    }
  }

  if(changed){
    updateTrafficLight();
    if(!isLcdShowingMsg){
      updateLcdIdle();
    }
  }
}

void mqttCallback(char* topic, byte* payloadBytes, unsigned int length){
  String payload = "";
  for(unsigned int i = 0; i < length; i++){
    payload += (char)payloadBytes[i];
  }

  String topicStr = String(topic);

  Serial.print("[MQTT RECEIVE] ");
  Serial.print(topicStr);
  Serial.print(" => ");
  Serial.println(payload);

  if(topicStr == TOPIC_GATE_IN_RESPONSE){
    handleGateResponse("in", payload);
  }
  else if(topicStr == TOPIC_GATE_OUT_RESPONSE){
    handleGateResponse("out", payload);
  }
  else if(topicStr == TOPIC_SLOT_STATE){
    handleSlotState(payload);
  }
}

void setupMQTT(){
  mqttSecureClient.setInsecure(); // praktis untuk HiveMQ TLS tanpa sertifikat root
  mqttClient.setServer(MQTT_HOST, MQTT_PORT);
  mqttClient.setCallback(mqttCallback);
  mqttClient.setKeepAlive(30);
  mqttClient.setSocketTimeout(10);
  mqttClient.setBufferSize(2048);
}

void connectMQTT(){
  if(!wifiConnected) return;
  if(mqttClient.connected()) return;

  if(lastMQTTReconnect > 0 && millis() - lastMQTTReconnect < MQTT_RECONNECT_MS){
    return;
  }

  lastMQTTReconnect = millis();

  Serial.print("[MQTT] Menghubungkan ke HiveMQ... ");

  String clientId = getDeviceId() + "_" + String((unsigned long)millis());
  bool ok = mqttClient.connect(clientId.c_str(), MQTT_USER, MQTT_PASS);

  if(ok){
    Serial.println("Connected");
    mqttClient.subscribe(TOPIC_SERVER_ALL);
    Serial.print("[MQTT SUBSCRIBE] ");
    Serial.println(TOPIC_SERVER_ALL);
    publishDeviceStatus("online");
    publishSlotStatusMQTT();
  } else {
    Serial.print("Gagal, rc=");
    Serial.println(mqttClient.state());
  }
}

void maintainMQTT(){
  if(!wifiConnected){
    return;
  }

  if(!mqttClient.connected()){
    connectMQTT();
  } else {
    mqttClient.loop();
  }
}

// =====================================================
// WIFI & TIME
// =====================================================
void setupTime(){
  configTime(7 * 3600, 0, "pool.ntp.org", "time.google.com");

  Serial.print("[TIME] Sinkronisasi NTP");
  for(int i = 0; i < 10; i++){
    time_t now;
    time(&now);
    if(now > 1700000000){
      timeReady = true;
      Serial.println(" OK");
      return;
    }
    Serial.print(".");
    delay(500);
  }
  Serial.println(" Gagal, pakai millis()");
}

void connectWiFi(){
  lcd.clear();
  printCenter(1, "Menghubungkan WiFi");
  printCenter(2, WIFI_SSID);

  Serial.print("[WiFi] ");
  Serial.println(WIFI_SSID);

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  int attempt = 0;
  while(WiFi.status() != WL_CONNECTED && attempt < 20){
    delay(500);
    Serial.print(".");
    attempt++;
  }

  if(WiFi.status() == WL_CONNECTED){
    wifiConnected = true;
    Serial.println("\n[WiFi] Connected");
    Serial.print("[WiFi] IP: ");
    Serial.println(WiFi.localIP());

    lcd.clear();
    printCenter(1, "WiFi Terhubung");
    printCenter(2, "Sistem Siap");

    setupTime();
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

// =====================================================
// SENSOR IR
// =====================================================
void updateIRSensor(){
  bool hardwareChanged = false;

  for(int i = 0; i < TOTAL_SLOT; i++){
    bool terisi = digitalRead(irPin[i]) == LOW;
    int currentState = terisi ? 1 : 0;

    if(currentState != lastPhysicalState[i]){
      hardwareChanged = true;
      lastPhysicalState[i] = currentState;
    }

    if(terisi){
      statusSlot[i] = 1;
    } else {
      if(statusSlot[i] != 2){
        statusSlot[i] = 0;
      }
    }
  }

  if(hardwareChanged){
    if(millis() - lastHardwareSend >= HARDWARE_SEND_COOLDOWN){
      lastHardwareSend = millis();
      Serial.println("[IR] Sensor berubah, publish MQTT");
      publishSlotStatusMQTT();

      if(!isLcdShowingMsg){
        updateLcdIdle();
      }
    }
  }
}

// =====================================================
// SCANNER GM65
// =====================================================
void handleScanMasuk(String qr){
  Serial.print("[SCAN MASUK] ");
  Serial.println(qr);

  showLcdMessage("MEMPROSES", "VALIDASI MQTT", "MOHON TUNGGU", "");

  String requestId = createRequestId("in");
  pendingInRequestId = requestId;
  pendingInTimer = millis();

  publishQRScanMQTT(qr, "in", requestId);
}

void handleScanKeluar(String qr){
  Serial.print("[SCAN KELUAR] ");
  Serial.println(qr);

  showLcdMessage("MEMPROSES", "VALIDASI MQTT", "MOHON TUNGGU", "");

  String requestId = createRequestId("out");
  pendingOutRequestId = requestId;
  pendingOutTimer = millis();

  publishQRScanMQTT(qr, "out", requestId);
}

void bacaScanner(HardwareSerial &port, String &buffer, void (*handler)(String)){
  while(port.available()){
    char c = port.read();

    if(c == '\n' || c == '\r'){
      buffer.trim();
      if(buffer.length() > 0){
        handler(buffer);
        buffer = "";
      }
    } else {
      buffer += c;
    }
  }
}

void cekPendingQRTimeout(){
  if(pendingInRequestId != "" && millis() - pendingInTimer >= QR_RESPONSE_TIMEOUT_MS){
    pendingInRequestId = "";
    showLcdMessage("AKSES DITOLAK", "TIMEOUT MQTT", "ULANGI SCAN", "");
  }

  if(pendingOutRequestId != "" && millis() - pendingOutTimer >= QR_RESPONSE_TIMEOUT_MS){
    pendingOutRequestId = "";
    showLcdMessage("AKSES DITOLAK", "TIMEOUT MQTT", "ULANGI SCAN", "");
  }
}

// =====================================================
// SERVO
// =====================================================
void cekServo(Servo &srv, bool &aktif, unsigned long &timer, const char* nama){
  if(aktif && (millis() - timer >= SERVO_BUKA_MS)){
    srv.write(SERVO_TUTUP);
    aktif = false;

    Serial.print("[");
    Serial.print(nama);
    Serial.println("] TUTUP");
  }
}

// =====================================================
// SETUP & LOOP
// =====================================================
void setup(){
  Serial.begin(115200);
  delay(1000);

  Serial.println("=== SMART PARKING FULL MQTT ===");

  lcd.init();
  lcd.backlight();
  lcd.clear();

  printCenter(1, "MEMULAI SISTEM");
  printCenter(2, "FULL MQTT");

  ScannerMasuk.begin(BAUD_GM65, SERIAL_8N1, GM65_IN_RX, GM65_IN_TX);
  ScannerKeluar.begin(BAUD_GM65, SERIAL_8N1, GM65_OUT_RX, GM65_OUT_TX);

  servoMasuk.attach(SERVO_IN_PIN, 500, 2400);
  servoKeluar.attach(SERVO_OUT_PIN, 500, 2400);
  servoMasuk.write(SERVO_TUTUP);
  servoKeluar.write(SERVO_TUTUP);

  for(int i = 0; i < TOTAL_SLOT; i++){
    pinMode(irPin[i], INPUT);
  }

  pinMode(SR_DATA, OUTPUT);
  pinMode(SR_LATCH, OUTPUT);
  pinMode(SR_CLOCK, OUTPUT);

  connectWiFi();
  setupMQTT();
  connectMQTT();

  updateIRSensor();
  updateTrafficLight();
  updateLcdIdle();
}

void loop(){
  if(WiFi.status() != WL_CONNECTED){
    wifiConnected = false;
    connectWiFi();
    updateLcdIdle();
  }

  maintainMQTT();

  bacaScanner(ScannerMasuk, bufferMasuk, handleScanMasuk);
  bacaScanner(ScannerKeluar, bufferKeluar, handleScanKeluar);

  cekPendingQRTimeout();

  cekServo(servoMasuk, servoMasukAktif, timerMasuk, "MASUK");
  cekServo(servoKeluar, servoKeluarAktif, timerKeluar, "KELUAR");

  if(millis() - lastIRCheck >= IR_CHECK_MS){
    lastIRCheck = millis();
    updateIRSensor();
    updateTrafficLight();
  }

  if(!isLcdShowingMsg && (millis() - lastLcdIdleUpdate >= 2000)){
    lastLcdIdleUpdate = millis();
    updateLcdIdle();
  }

  if(isLcdShowingMsg && (millis() - lcdMessageTimer >= 4000)){
    isLcdShowingMsg = false;
    lcd.clear();
    updateLcdIdle();
  }

  if(millis() - lastMQTTHeartbeat >= MQTT_HEARTBEAT_MS){
    lastMQTTHeartbeat = millis();
    publishDeviceStatus("online");
  }

  delay(10);
}
