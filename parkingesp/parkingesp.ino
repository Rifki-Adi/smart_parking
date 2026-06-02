#include <ESP32Servo.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

const char* WIFI_SSID  = "DR";
const char* WIFI_PASS  = "zidan1804";

const char* SERVER_URL =
"https://smart-parking-rifki-eqfwfbghh3edbyd7.eastasia-01.azurewebsites.net/api.php";

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
#define SLOT_SYNC_MS   15000
#define HARDWARE_SEND_COOLDOWN 1000

#define SERVO_TUTUP    90
#define SERVO_BUKA     0

HardwareSerial ScannerMasuk(2);
HardwareSerial ScannerKeluar(1);

Servo servoMasuk;
Servo servoKeluar;

LiquidCrystal_I2C lcd(0x27, 20, 4);

int statusSlot[TOTAL_SLOT] = {0,0,0,0};

int irPin[TOTAL_SLOT] = {
  IR_SLOT_1,
  IR_SLOT_2,
  IR_SLOT_3,
  IR_SLOT_4
};

int lastPhysicalState[TOTAL_SLOT] = {-1,-1,-1,-1};

String bufferMasuk  = "";
String bufferKeluar = "";

bool servoMasukAktif  = false;
bool servoKeluarAktif = false;

unsigned long timerMasuk  = 0;
unsigned long timerKeluar = 0;

unsigned long lastIRCheck  = 0;
unsigned long lastSlotSync = 0;
unsigned long lastHardwareSend = 0;

unsigned long lcdMessageTimer   = 0;
unsigned long lastLcdIdleUpdate = 0;

bool isLcdShowingMsg = false;
bool wifiConnected   = false;

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

void sendSlotStatusToServer(){

  if(!wifiConnected) return;

  HTTPClient http;

  String url = String(SERVER_URL) + "?action=update_hardware_slots";

  url += "&s1=" + String(lastPhysicalState[0]);
  url += "&s2=" + String(lastPhysicalState[1]);
  url += "&s3=" + String(lastPhysicalState[2]);
  url += "&s4=" + String(lastPhysicalState[3]);

  http.begin(url);
  http.addHeader("Connection", "close");
  http.setTimeout(5000);

  int code = http.GET();

  if(code == 200){
    Serial.println("[HW SYNC] OK");
  } else {
    Serial.print("[HW SYNC ERROR] ");
    Serial.println(code);
  }

  http.end();
}

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

      Serial.println("[HW SYNC] SENSOR BERUBAH");

      sendSlotStatusToServer();

      if(!isLcdShowingMsg){
        updateLcdIdle();
      }
    }
  }
}

void connectWiFi(){

  lcd.clear();
  printCenter(1, "Menghubungkan WiFi");
  printCenter(2, WIFI_SSID);

  Serial.print("[WiFi] ");
  Serial.println(WIFI_SSID);

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

void syncSlotDariServer(){

  if(!wifiConnected) return;

  HTTPClient http;

  String url = String(SERVER_URL) + "?action=get_slots&uid=esp32_device";

  http.begin(url);
  http.addHeader("Connection", "close");
  http.setTimeout(5000);

  int code = http.GET();

  bool statusBerubah = false;

  if(code == 200){

    String payload = http.getString();

    for(int i = 0; i < TOTAL_SLOT; i++){

      String searchStr1 = "\"slot_nomor\":\"" + String(i+1) + "\"";
      String searchStr2 = "\"slot_nomor\":" + String(i+1);

      int slotIdx = payload.indexOf(searchStr1);

      if(slotIdx == -1){
        slotIdx = payload.indexOf(searchStr2);
      }

      if(slotIdx != -1){

        int stateIdx = payload.indexOf("\"state\":\"", slotIdx);

        if(stateIdx != -1){

          int valStart = stateIdx + 9;
          int valEnd = payload.indexOf("\"", valStart);

          String state = payload.substring(valStart, valEnd);

          if(digitalRead(irPin[i]) != LOW){

            int oldStatus = statusSlot[i];

            if(state.indexOf("reserved") != -1){
              statusSlot[i] = 2;
            } else {
              statusSlot[i] = 0;
            }

            if(oldStatus != statusSlot[i]){
              statusBerubah = true;
            }
          }
        }
      }
    }

    updateTrafficLight();

    if(statusBerubah && !isLcdShowingMsg){
      updateLcdIdle();
    }

  } else {
    Serial.print("[SYNC ERROR] ");
    Serial.println(code);
  }

  http.end();
}

String kirimQRkeServer(String qr, String gate){

  if(!wifiConnected){
    return "no_wifi";
  }

  HTTPClient http;

  String url = String(SERVER_URL) + "?action=gate_scan";

  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.addHeader("Connection", "close");
  http.setTimeout(5000);

  String postData = "qr_code=" + qr + "&gate=" + gate;

  int code = http.POST(postData);

  String result = "error|Koneksi Gagal";

  if(code == 200){

    String payload = http.getString();

    String msg = "Terjadi Kesalahan";

    int msgIdx = payload.indexOf("\"message\":\"");

    if(msgIdx != -1){
      int msgStart = msgIdx + 11;
      int msgEnd = payload.indexOf("\"", msgStart);
      msg = payload.substring(msgStart, msgEnd);
    }

    if(payload.indexOf("\"success\"") != -1){
      result = "success|" + msg;
    } else {
      result = "denied|" + msg;
    }
  }

  http.end();

  return result;
}

void handleScanMasuk(String qr){

  Serial.println("[MASUK]");

  showLcdMessage(
    "MEMPROSES",
    "MOHON TUNGGU",
    "",
    ""
  );

  String response = kirimQRkeServer(qr, "in");

  int sepIndex = response.indexOf("|");

  String hasil = (sepIndex != -1) ? response.substring(0, sepIndex) : response;
  String pesan = (sepIndex != -1) ? response.substring(sepIndex + 1) : "";

  if(hasil == "success"){

    showLcdMessage(
      "AKSES DITERIMA",
      "SILAKAN MASUK",
      "PALANG TERBUKA",
      ""
    );

    if(!servoMasukAktif){
      servoMasuk.write(SERVO_BUKA);
      servoMasukAktif = true;
      timerMasuk = millis();
    }

  } else if(hasil == "no_wifi"){

    showLcdMessage(
      "MODE OFFLINE",
      "SILAKAN MASUK",
      "PALANG TERBUKA",
      ""
    );

    if(!servoMasukAktif){
      servoMasuk.write(SERVO_BUKA);
      servoMasukAktif = true;
      timerMasuk = millis();
    }

  } else {

    showLcdMessage(
      "AKSES DITOLAK",
      "TIDAK VALID",
      pesan,
      ""
    );
  }
}

void handleScanKeluar(String qr){

  Serial.println("[KELUAR]");

  showLcdMessage(
    "MEMPROSES",
    "MOHON TUNGGU",
    "",
    ""
  );

  String response = kirimQRkeServer(qr, "out");

  int sepIndex = response.indexOf("|");

  String hasil = (sepIndex != -1) ? response.substring(0, sepIndex) : response;
  String pesan = (sepIndex != -1) ? response.substring(sepIndex + 1) : "";

  if(hasil == "success"){

    showLcdMessage(
      "AKSES DITERIMA",
      "SILAKAN KELUAR",
      "SALDO -Rp3000",
      "PALANG TERBUKA"
    );

    if(!servoKeluarAktif){
      servoKeluar.write(SERVO_BUKA);
      servoKeluarAktif = true;
      timerKeluar = millis();
    }

  } else if(hasil == "no_wifi"){

    showLcdMessage(
      "MODE OFFLINE",
      "SILAKAN KELUAR",
      "PALANG TERBUKA",
      ""
    );

    if(!servoKeluarAktif){
      servoKeluar.write(SERVO_BUKA);
      servoKeluarAktif = true;
      timerKeluar = millis();
    }

  } else {

    showLcdMessage(
      "AKSES DITOLAK",
      "TIDAK VALID",
      pesan,
      ""
    );
  }
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

void cekServo(Servo &srv, bool &aktif, unsigned long &timer, const char* nama){

  if(aktif && (millis() - timer >= SERVO_BUKA_MS)){

    srv.write(SERVO_TUTUP);
    aktif = false;

    Serial.print("[");
    Serial.print(nama);
    Serial.println("] TUTUP");

    syncSlotDariServer();
  }
}

void setup(){

  Serial.begin(115200);
  delay(1000);

  Serial.println("=== SMART PARKING ===");

  lcd.init();
  lcd.backlight();
  lcd.clear();

  printCenter(1, "MEMULAI SISTEM");
  printCenter(2, "SMART PARKING");

  connectWiFi();

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

  updateIRSensor();
  updateTrafficLight();
  syncSlotDariServer();
  updateLcdIdle();
}

void loop(){

  if(WiFi.status() != WL_CONNECTED){

    wifiConnected = false;
    connectWiFi();
    updateLcdIdle();
  }

  bacaScanner(ScannerMasuk, bufferMasuk, handleScanMasuk);
  bacaScanner(ScannerKeluar, bufferKeluar, handleScanKeluar);

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

  if(millis() - lastSlotSync >= SLOT_SYNC_MS){

    lastSlotSync = millis();

    syncSlotDariServer();
  }

  delay(10);
}
