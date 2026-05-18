#include <ESP32Servo.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// ============================================
// WIFI & SERVER — SESUAIKAN INI
// ============================================
const char* WIFI_SSID  = "Lab TI lantai 1";
const char* WIFI_PASS  = "";
const char* SERVER_URL = "http://10.10.6.86/smart-parking/api.php";

// ============================================
// PIN DEFINITION
// ============================================
#define GM65_IN_RX   16
#define GM65_IN_TX   17
#define GM65_OUT_RX  25
#define GM65_OUT_TX  26
#define BAUD_GM65  115200

#define SERVO_IN_PIN  13
#define SERVO_OUT_PIN 12

#define IR_SLOT_1  34
#define IR_SLOT_2  35
#define IR_SLOT_3  32
#define IR_SLOT_4  33

#define SR_DATA   23
#define SR_CLOCK  18
#define SR_LATCH   5

// ============================================
// KONFIGURASI
// ============================================
#define TOTAL_SLOT    4
#define SERVO_BUKA_MS 5000
#define IR_CHECK_MS   500
#define SLOT_SYNC_MS  3000

// SESUAIKAN JIKA SERVO TERBALIK
#define SERVO_TUTUP   90  
#define SERVO_BUKA    0   

// ============================================
// OBJEK & VARIABEL GLOBAL
// ============================================
HardwareSerial ScannerMasuk(2);
HardwareSerial ScannerKeluar(1);
Servo servoMasuk;
Servo servoKeluar;

// LCD I2C 20x4, Pin Default ESP32: SDA=21, SCL=22
LiquidCrystal_I2C lcd(0x27, 20, 4); 

int statusSlot[TOTAL_SLOT] = {0, 0, 0, 0};
int irPin[TOTAL_SLOT]      = {IR_SLOT_1, IR_SLOT_2, IR_SLOT_3, IR_SLOT_4};
int lastPhysicalState[TOTAL_SLOT] = {-1, -1, -1, -1};

String        bufferMasuk      = "";
String        bufferKeluar     = "";
bool          servoMasukAktif  = false;
bool          servoKeluarAktif = false;
unsigned long timerMasuk       = 0;
unsigned long timerKeluar      = 0;
unsigned long lastIRCheck      = 0;
unsigned long lastSlotSync     = 0;

unsigned long lcdMessageTimer  = 0;
bool          isLcdShowingMsg  = false;
unsigned long lastLcdIdleUpdate= 0;

bool wifiConnected = false;

// ============================================
// FUNGSI TAMPILAN LCD
// ============================================
void showLcdMessage(String baris1, String baris2, String baris3, String baris4) {
  lcd.clear();
  int pad1 = (20 - baris1.length()) / 2;
  int pad2 = (20 - baris2.length()) / 2;
  int pad3 = (20 - baris3.length()) / 2;
  int pad4 = (20 - baris4.length()) / 2;

  if (pad1 < 0) pad1 = 0; if (pad2 < 0) pad2 = 0;
  if (pad3 < 0) pad3 = 0; if (pad4 < 0) pad4 = 0;

  lcd.setCursor(pad1, 0); lcd.print(baris1);
  lcd.setCursor(pad2, 1); lcd.print(baris2);
  lcd.setCursor(pad3, 2); lcd.print(baris3);
  lcd.setCursor(pad4, 3); lcd.print(baris4);

  isLcdShowingMsg = true;
  lcdMessageTimer = millis();
}

void updateLcdIdle() {
  if (isLcdShowingMsg) return;

  int sisaSlot = 0;
  for (int i = 0; i < TOTAL_SLOT; i++) {
    if (statusSlot[i] == 0) sisaSlot++;
  }

  lcd.setCursor(0, 0); lcd.print("=== SMART PARKING ==");
  lcd.setCursor(0, 1); lcd.print("  Sisa Slot : "); lcd.print(sisaSlot); lcd.print("     ");
  lcd.setCursor(0, 2); lcd.print(" Silakan Scan QR/ID ");

  lcd.setCursor(0, 3);
  String statusText = "";
  for(int i = 0; i < TOTAL_SLOT; i++) {
    statusText += "S"; statusText += (i+1); statusText += "=";
    if (statusSlot[i] == 1) statusText += "I ";      // I = Isi (Merah)
    else if (statusSlot[i] == 2) statusText += "R "; // R = Reserved (Kuning)
    else statusText += "K ";                         // K = Kosong (Hijau)
  }
  lcd.print(statusText); 
}

// ============================================
// TRAFFIC LIGHT (LED SLOT) - FIX ANTI NOISE
// ============================================
void updateTrafficLight() {
  byte ic1 = 0; // Mengontrol Slot 1 & 2
  byte ic2 = 0; // Mengontrol Slot 3 & 4

  // Slot 1
  if      (statusSlot[0] == 1) ic1 |= 0b00000001; 
  else if (statusSlot[0] == 2) ic1 |= 0b00000010; 
  else                         ic1 |= 0b00000100; 

  // Slot 2
  if      (statusSlot[1] == 1) ic1 |= 0b00001000; 
  else if (statusSlot[1] == 2) ic1 |= 0b00010000; 
  else                         ic1 |= 0b00100000; 

  // Slot 3
  if      (statusSlot[2] == 1) ic2 |= 0b00000001; 
  else if (statusSlot[2] == 2) ic2 |= 0b00000010; 
  else                         ic2 |= 0b00000100; 

  // Slot 4
  if      (statusSlot[3] == 1) ic2 |= 0b00001000; 
  else if (statusSlot[3] == 2) ic2 |= 0b00010000; 
  else                         ic2 |= 0b00100000; 

  digitalWrite(SR_LATCH, LOW);
  
  // Menggunakan fungsi standar shiftOut yang lebih tahan noise
  // IC 2 wajib dikirim duluan
  shiftOut(SR_DATA, SR_CLOCK, MSBFIRST, ic2); 
  shiftOut(SR_DATA, SR_CLOCK, MSBFIRST, ic1); 
  
  digitalWrite(SR_LATCH, HIGH);
}

// ============================================
// MENGIRIM STATUS SENSOR IR KE DATABASE WEB
// ============================================
void sendSlotStatusToServer() {
  if (!wifiConnected) return;
  HTTPClient http;
  String url = String(SERVER_URL) + "?action=update_hardware_slots";
  url += "&s1=" + String(lastPhysicalState[0]); url += "&s2=" + String(lastPhysicalState[1]);
  url += "&s3=" + String(lastPhysicalState[2]); url += "&s4=" + String(lastPhysicalState[3]);
  http.begin(url);
  int code = http.GET();
  if (code == 200) { Serial.println("[HW] Berhasil update IR ke Server!"); } 
  http.end();
}

// ============================================
// PEMBACAAN SENSOR IR FISIK
// ============================================
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
      if (statusSlot[i] != 2) { statusSlot[i] = 0; } 
    }
  }
  
  if (hardwareChanged) {
    sendSlotStatusToServer();
    if (!isLcdShowingMsg) updateLcdIdle(); 
  }
}

// ============================================
// WIFI
// ============================================
void connectWiFi() {
  lcd.clear(); lcd.setCursor(0, 1); lcd.print(" Menghubungkan WiFi "); lcd.setCursor(0, 2); lcd.print(" " + String(WIFI_SSID) + " ");
  Serial.print("[WiFi] Menghubungkan ke: "); Serial.println(WIFI_SSID);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  int attempt = 0;
  while (WiFi.status() != WL_CONNECTED && attempt < 20) { delay(500); Serial.print("."); attempt++; }
  if (WiFi.status() == WL_CONNECTED) {
    wifiConnected = true; Serial.println("\n[WiFi] Terhubung!");
    lcd.clear(); lcd.setCursor(2, 1); lcd.print("WiFi Terhubung!");
  } else {
    wifiConnected = false; Serial.println("\n[WiFi] Gagal! Mode Offline.");
    lcd.clear(); lcd.setCursor(2, 1); lcd.print("Gagal Connect WiFi");
  }
  delay(1500); lcd.clear();
}

// ============================================
// SINKRONISASI STATUS RESERVED 
// ============================================
void syncSlotDariServer() {
  if (!wifiConnected) return;
  HTTPClient http;
  String url = String(SERVER_URL) + "?action=get_slots&uid=esp32_device";
  http.begin(url); http.setTimeout(5000);
  int code = http.GET();
  
  bool statusBerubah = false;

  if (code == 200) {
    String payload = http.getString();
    
    for (int i = 0; i < TOTAL_SLOT; i++) {
      String searchStr1 = "\"slot_nomor\":\"" + String(i + 1) + "\"";
      String searchStr2 = "\"slot_nomor\":" + String(i + 1);
      
      int slotIdx = payload.indexOf(searchStr1);
      if (slotIdx == -1) slotIdx = payload.indexOf(searchStr2);
      
      if (slotIdx != -1) {
        int stateIdx = payload.indexOf("\"state\":\"", slotIdx);
        if (stateIdx != -1) {
          int valStart = stateIdx + 9;
          int valEnd = payload.indexOf("\"", valStart);
          String state = payload.substring(valStart, valEnd);
          
          if (digitalRead(irPin[i]) != LOW) {
            int oldStatus = statusSlot[i];
            if (state.indexOf("reserved") != -1) { 
              statusSlot[i] = 2; // Kuning
            } else { 
              statusSlot[i] = 0; // Hijau
            }
            if(oldStatus != statusSlot[i]) statusBerubah = true;
          }
        }
      }
    }
    updateTrafficLight();
    if(statusBerubah && !isLcdShowingMsg) updateLcdIdle();
  }
  http.end();
}

// ============================================
// KOMUNIKASI GATE DENGAN API
// ============================================
String kirimQRkeServer(String qr, String gate) {
  if (!wifiConnected) { return "no_wifi"; }
  HTTPClient http;
  String url = String(SERVER_URL) + "?action=gate_scan";
  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(8000);
  String postData = "qr_code=" + qr + "&gate=" + gate;
  int code = http.POST(postData);

  String result = "error|Koneksi Gagal";
  if (code == 200) {
    String payload = http.getString();
    String msg = "Terjadi Kesalahan";
    int msgIdx = payload.indexOf("\"message\":\"");
    if (msgIdx != -1) {
      int msgStart = msgIdx + 11; int msgEnd = payload.indexOf("\"", msgStart);
      msg = payload.substring(msgStart, msgEnd);
    }
    if (payload.indexOf("\"success\"") != -1) { result = "success|" + msg; } 
    else { result = "denied|" + msg; }
  }
  http.end(); return result;
}

// ============================================
// HANDLER SCAN MASUK & KELUAR
// ============================================
void handleScanMasuk(String qr) {
  Serial.println("[MASUK] Memproses...");
  showLcdMessage("Memproses...", "Mohon Tunggu", "", "");

  String response = kirimQRkeServer(qr, "in");
  int sepIndex = response.indexOf("|");
  String hasil = (sepIndex != -1) ? response.substring(0, sepIndex) : response;
  String pesan = (sepIndex != -1) ? response.substring(sepIndex + 1) : "";

  String baris3 = pesan; String baris4 = "";
  int splitMsg = pesan.indexOf('|');
  if (splitMsg != -1) { baris3 = pesan.substring(0, splitMsg); baris4 = pesan.substring(splitMsg + 1); } 
  else if(pesan.length() > 20) { baris3 = pesan.substring(0,20); baris4 = pesan.substring(20); }

  if (hasil == "success") {
    showLcdMessage("AKSES DITERIMA", "Pintu Masuk Terbuka", baris3, baris4);
    if (!servoMasukAktif) { servoMasuk.write(SERVO_BUKA); servoMasukAktif = true; timerMasuk = millis(); }
  } else if (hasil == "no_wifi") {
    showLcdMessage("AKSES OFFLINE", "Pintu Masuk Terbuka", "Hati-hati di jalan", "");
    if (!servoMasukAktif) { servoMasuk.write(SERVO_BUKA); servoMasukAktif = true; timerMasuk = millis(); }
  } else {
    showLcdMessage("AKSES DITOLAK", "Pintu Tertutup", baris3, baris4);
  }
}

void handleScanKeluar(String qr) {
  Serial.println("[KELUAR] Memproses...");
  showLcdMessage("Memproses...", "Mohon Tunggu", "", "");

  String response = kirimQRkeServer(qr, "out");
  int sepIndex = response.indexOf("|");
  String hasil = (sepIndex != -1) ? response.substring(0, sepIndex) : response;
  String pesan = (sepIndex != -1) ? response.substring(sepIndex + 1) : "";

  String baris3 = pesan; String baris4 = "";
  int splitMsg = pesan.indexOf('|');
  if (splitMsg != -1) { baris3 = pesan.substring(0, splitMsg); baris4 = pesan.substring(splitMsg + 1); } 
  else if(pesan.length() > 20) { baris3 = pesan.substring(0,20); baris4 = pesan.substring(20); }

  if (hasil == "success") {
    showLcdMessage("AKSES DITERIMA", "Pintu Keluar Terbuka", baris3, baris4);
    if (!servoKeluarAktif) { servoKeluar.write(SERVO_BUKA); servoKeluarAktif = true; timerKeluar = millis(); }
  } else if (hasil == "no_wifi") {
    showLcdMessage("AKSES OFFLINE", "Pintu Keluar Terbuka", "Hati-hati di jalan", "");
    if (!servoKeluarAktif) { servoKeluar.write(SERVO_BUKA); servoKeluarAktif = true; timerKeluar = millis(); }
  } else {
    showLcdMessage("AKSES DITOLAK", "Pintu Tertutup", baris3, baris4);
  }
}

void bacaScanner(HardwareSerial &port, String &buffer, void (*handler)(String)) {
  while (port.available()) {
    char c = port.read();
    if (c == '\n' || c == '\r') { buffer.trim(); if (buffer.length() > 0) { handler(buffer); buffer = ""; } } 
    else { buffer += c; }
  }
}

void cekServo(Servo &srv, bool &aktif, unsigned long &timer, const char* nama) {
  if (aktif && (millis() - timer >= SERVO_BUKA_MS)) {
    srv.write(SERVO_TUTUP); aktif = false;
    Serial.print("["); Serial.print(nama); Serial.println("] Palang TUTUP"); syncSlotDariServer();
  }
}

void setup() {
  Serial.begin(115200); delay(1000); Serial.println("=== SMART PARKING ESP32 ===");
  lcd.init(); lcd.backlight(); lcd.clear(); lcd.setCursor(1, 1); lcd.print("MEMULAI SISTEM...");

  connectWiFi();

  ScannerMasuk.begin(BAUD_GM65,  SERIAL_8N1, GM65_IN_RX,  GM65_IN_TX);
  ScannerKeluar.begin(BAUD_GM65, SERIAL_8N1, GM65_OUT_RX, GM65_OUT_TX);

  servoMasuk.attach(SERVO_IN_PIN, 500, 2400); servoKeluar.attach(SERVO_OUT_PIN, 500, 2400);
  servoMasuk.write(SERVO_TUTUP); servoKeluar.write(SERVO_TUTUP);
  
  for (int i = 0; i < TOTAL_SLOT; i++) { pinMode(irPin[i], INPUT); }
  
  pinMode(SR_DATA,  OUTPUT); pinMode(SR_LATCH, OUTPUT); pinMode(SR_CLOCK, OUTPUT);

  updateIRSensor(); updateTrafficLight(); syncSlotDariServer();
  updateLcdIdle(); 
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) { wifiConnected = false; connectWiFi(); updateLcdIdle(); }

  bacaScanner(ScannerMasuk,  bufferMasuk,  handleScanMasuk);
  bacaScanner(ScannerKeluar, bufferKeluar, handleScanKeluar);

  cekServo(servoMasuk,  servoMasukAktif,  timerMasuk,  "MASUK");
  cekServo(servoKeluar, servoKeluarAktif, timerKeluar, "KELUAR");

  if (millis() - lastIRCheck >= IR_CHECK_MS) { lastIRCheck = millis(); updateIRSensor(); updateTrafficLight(); }
  if (!isLcdShowingMsg && (millis() - lastLcdIdleUpdate >= 2000)) { lastLcdIdleUpdate = millis(); updateLcdIdle(); }
  if (isLcdShowingMsg && (millis() - lcdMessageTimer >= 4000)) { isLcdShowingMsg = false; lcd.clear(); updateLcdIdle(); }
  if (millis() - lastSlotSync >= SLOT_SYNC_MS) { lastSlotSync = millis(); syncSlotDariServer(); }
}