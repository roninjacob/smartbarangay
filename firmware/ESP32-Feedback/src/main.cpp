#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include "secrets.h"
#include "feedback_protocol.h"

#define GREEN_LED 25
#define RED_LED 26
#define YELLOW_LED 27
#define OLED_SDA 21
#define OLED_SCL 22

Adafruit_SSD1306 display(128, 64, &Wire, -1);

const uint8_t CAMERA_MAC[6] = {
    0x2C, 0xBC, 0xBB, 0x83, 0x92, 0xCC
};

bool oledReady = false;
bool haveResponse = false;
bool pending = false;

uint8_t currentState = 0;
uint8_t previousState = 255;
uint8_t challenge[16];

uint32_t lastRequest = 0;
uint32_t lastResponse = 0;
uint32_t lastReconnect = 0;
uint32_t lastInit = 0;
uint32_t lastLog = 0;

void showScreen(
    const char *title,
    const char *message
) {
    if (!oledReady) return;

    display.clearDisplay();
    display.setTextColor(SSD1306_WHITE);
    display.setTextWrap(false);

    display.setTextSize(1);
    display.setCursor(0, 3);
    display.println("SMART BARANGAY");

    display.drawLine(
        0, 15, 127, 15, SSD1306_WHITE
    );

    display.setTextSize(strlen(title) <= 10 ? 2 : 1);
    display.setCursor(0, 24);
    display.println(title);

    display.setTextSize(1);
    display.setCursor(0, 52);
    display.println(message);

    display.display();
}

void updateOutputs(uint8_t state) {
    digitalWrite(GREEN_LED, state == 4);
    digitalWrite(RED_LED, state == 5);

    bool yellow = state == 1 || state == 3;

    if (state == 0 || state == 6 ||
        state == 7 || state == 8)
        yellow = (millis() / 500) % 2;

    digitalWrite(YELLOW_LED, yellow);

    if (state == previousState) return;

    previousState = state;

    switch (state) {
        case 1:
            showScreen("Starting system", "Initializing scanner");
            Serial.println("[STATE] INITIALIZING");
            break;

        case 2:
            showScreen("Ready to Scan", "Present QR ticket");
            Serial.println("[STATE] READY");
            break;

        case 3:
            showScreen("Verifying Ticket", "Checking reservation");
            Serial.println("[STATE] VERIFYING");
            break;

        case 4:
            showScreen("Ticket Verified", "Verification accepted");
            Serial.println("[STATE] SERVER VALID");
            break;

        case 5:
            showScreen("Ticket Denied", "Ask at the counter");
            Serial.println("[STATE] SERVER INVALID");
            break;

        case 6:
            showScreen("Authentication Error", "Contact administrator");
            Serial.println("[STATE] AUTH ERROR");
            break;

        case 7:
            showScreen("Please Wait", "Try again later");
            Serial.println("[STATE] RATE LIMITED");
            break;

        case 8:
            showScreen("Connecting to Wi-Fi", "Please wait");
            Serial.println("[STATE] WIFI CONNECTING");
            break;

        default:
            showScreen("Device Offline", "Scanner unavailable");
            Serial.println("[STATE] OFFLINE");
            break;
    }
}

void setup() {
    Serial.begin(115200);

    pinMode(GREEN_LED, OUTPUT);
    pinMode(RED_LED, OUTPUT);
    pinMode(YELLOW_LED, OUTPUT);

    digitalWrite(GREEN_LED, LOW);
    digitalWrite(RED_LED, LOW);
    digitalWrite(YELLOW_LED, LOW);

    Wire.begin(OLED_SDA, OLED_SCL);

    for (uint8_t address : {0x3C, 0x3D}) {
        Wire.beginTransmission(address);

        if (Wire.endTransmission() == 0) {
            oledReady = display.begin(
                SSD1306_SWITCHCAPVCC,
                address, true, false
            );

            if (oledReady) break;
        }
    }

    Serial.println("SMART BARANGAY PRODUCTION");

    Serial.println(
        oledReady ? "[OLED] READY" : "[OLED] FAILED"
    );

    updateOutputs(8);

    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}

void loop() {
    const uint32_t now = millis();

    if (WiFi.status() != WL_CONNECTED) {
        haveResponse = false;
        pending = false;

        updateOutputs(8);

        if (now - lastReconnect >= 10000) {
            lastReconnect = now;
            WiFi.reconnect();
        }

        delay(20);
        return;
    }

    if (!sbfeedback::initialized) {
        updateOutputs(0);

        if (now - lastInit >= 2000) {
            lastInit = now;
            sbfeedback::begin(CAMERA_MAC);
        }

        delay(20);
        return;
    }

    sbfeedback::Packet packet{};

    if (sbfeedback::read(packet)) {
        if (packet.kind == 2 &&
            packet.state <= 7 &&
            pending &&
            now - lastRequest <= 550 &&
            memcmp(packet.nonce, challenge, 16) == 0) {

            pending = false;
            haveResponse = true;
            lastResponse = now;
            currentState = packet.state;
        }
    }

    if (now - lastRequest >= 600) {
        lastRequest = now;

        sbfeedback::Packet request{};
        request.kind = 1;

        esp_fill_random(
            challenge, sizeof(challenge)
        );

        memcpy(request.nonce, challenge, 16);

        pending = sbfeedback::send(request);
    }

    if (haveResponse &&
        now - lastResponse <= 1200) {

        updateOutputs(currentState);

    } else {
        haveResponse = false;
        updateOutputs(0);
    }

    if (now - lastLog >= 5000) {
        lastLog = now;

        Serial.printf(
            "[IoT] wifi=connected rssi=%d channel=%d link=%s heap=%lu\n",
            WiFi.RSSI(),
            WiFi.channel(),
            haveResponse ? "authenticated" : "offline",
            static_cast<unsigned long>(ESP.getFreeHeap())
        );
    }

    delay(20);
}
