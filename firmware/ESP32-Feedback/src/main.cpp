#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <cstring>
#include "secrets.h"
#include "feedback_protocol.h"
#include "feedback_states.h"

#define GREEN_LED 25
#define RED_LED 26
#define YELLOW_LED 27
#define OLED_SDA 21
#define OLED_SCL 22

using namespace sbfeedback;

Adafruit_SSD1306 display(128, 64, &Wire, -1);

const uint8_t CAMERA_MAC[6] = {
    0x2C, 0xBC, 0xBB, 0x83, 0x92, 0xCC
};

// The scanner answers each request at once (event-driven). A request is due every 600 ms and its
// answer must match the outstanding nonce within 550 ms; two missed answers mean the scanner is
// genuinely unreachable.
constexpr uint32_t REQUEST_INTERVAL_MS = 600;
constexpr uint32_t RESPONSE_WINDOW_MS = 550;
constexpr uint32_t LINK_LOST_MS = 1200;

enum Led : uint8_t { LedOff, LedGreen, LedRed, LedYellow, LedYellowBlink };
struct Screen { const char *title; const char *message; Led led; const char *name; };

// Every message fits 128x64: titles use two 10-character lines at size 2 (or one size-1 line
// when a word is longer), messages are at most 21 characters. No resident data is shown.
const Screen SCREENS[] = {
    {"Device Offline", "Scanner unavailable", LedYellowBlink, "OFFLINE"},
    {"Starting system", "Initializing scanner", LedYellow, "STARTING"},
    {"Ready to Scan", "Present QR ticket", LedOff, "READY"},
    {"Verifying Ticket", "Please wait", LedYellow, "VERIFYING"},
    {"Authorized", "Proceed to counter", LedGreen, "AUTHORIZED"},
    {"Access Denied", "Please approach staff", LedRed, "DENIED"},
    {"Authentication Error", "Contact administrator", LedYellowBlink, "AUTH_ERROR"},
    {"Please Wait", "Too many requests", LedYellowBlink, "RATE_LIMITED"},
    {"Connecting to Wi-Fi", "Please wait", LedYellowBlink, "WIFI_CONNECTING"},
    {"Reconnecting", "Restoring connection", LedYellowBlink, "RECONNECTING"},
    {"Check-in OK", "Check-in recorded", LedGreen, "CHECKIN_RECORDED"},
    {"Checked In", "Proceed to counter", LedGreen, "ALREADY_CHECKED_IN"},
    {"Not Ready Yet", "Please approach staff", LedYellow, "NOT_READY"},
    {"Camera Error", "Scanner unavailable", LedYellowBlink, "CAMERA_ERROR"},
    {"Scanner Off", "Enable from website", LedOff, "SCANNER_OFF"},
    {"Restarting", "Please wait", LedYellow, "RESTARTING"},
};
static_assert(sizeof(SCREENS) / sizeof(SCREENS[0]) == LastState + 1, "one screen per scanner state");

bool oledReady = false;
bool pending = false;
bool haveResponse = false;
uint8_t currentState = Starting;
uint8_t shownState = 255;
uint8_t challenge[16];
uint32_t lastRequest = 0, lastResponse = 0, lastReconnect = 0, lastInit = 0, lastLog = 0;
uint32_t answered = 0, missed = 0, maxLatency = 0, lastLatency = 0;

void drawCentered(const char *text, int16_t y, uint8_t size) {
    display.setTextSize(size);
    const int16_t width = static_cast<int16_t>(strlen(text)) * 6 * size;
    display.setCursor(width < 128 ? (128 - width) / 2 : 0, y);
    display.print(text);
}

// Splits a title into two lines of at most 10 characters at a space; false if it cannot.
bool splitTitle(const char *title, char *first, char *second) {
    const size_t length = strlen(title);
    if (length <= 10) { strcpy(first, title); second[0] = '\0'; return true; }
    for (int i = length < 10 ? length : 10; i > 0; --i) {
        if (title[i] == ' ' && length - i - 1 <= 10) {
            memcpy(first, title, i); first[i] = '\0';
            strcpy(second, title + i + 1);
            return true;
        }
    }
    return false;
}

void showScreen(const Screen &screen) {
    if (!oledReady) return;
    display.clearDisplay();
    display.setTextColor(SSD1306_WHITE);
    display.setTextWrap(false);
    drawCentered("SMART BARANGAY", 0, 1);
    display.drawLine(0, 10, 127, 10, SSD1306_WHITE);
    char first[11] = {}, second[11] = {};
    if (splitTitle(screen.title, first, second)) {
        if (second[0]) { drawCentered(first, 15, 2); drawCentered(second, 33, 2); }
        else drawCentered(first, 24, 2);
    } else {
        drawCentered(screen.title, 28, 1);
    }
    drawCentered(screen.message, 55, 1);
    display.display();
}

void updateOutputs(uint8_t state) {
    if (state > LastState) state = Offline;
    const Screen &screen = SCREENS[state];
    const bool blinkOn = (millis() / 500) % 2;
    digitalWrite(GREEN_LED, screen.led == LedGreen);
    digitalWrite(RED_LED, screen.led == LedRed);
    digitalWrite(YELLOW_LED, screen.led == LedYellow || (screen.led == LedYellowBlink && blinkOn));
    // Redraw only on a real state change; repeated answers never cause flicker.
    if (state == shownState) return;
    shownState = state;
    showScreen(screen);
    Serial.printf("[STATE] %s up_s=%lu\n", screen.name, static_cast<unsigned long>(millis() / 1000));
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
            oledReady = display.begin(SSD1306_SWITCHCAPVCC, address, true, false);
            if (oledReady) break;
        }
    }
    Serial.println("\n[BOOT] SMART BARANGAY FEEDBACK DISPLAY");
    Serial.printf("[BOOT] Reset reason=%d\n", static_cast<int>(esp_reset_reason()));
    Serial.println(oledReady ? "[OLED] READY" : "[OLED] FAILED");
    updateOutputs(Starting);

    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}

void logLink(uint32_t now) {
    if (now - lastLog < 5000) return;
    lastLog = now;
    Serial.printf("[LINK] wifi=%d rssi=%d ch=%d link=%s answered=%lu missed=%lu last_ms=%lu max_ms=%lu state=%s heap=%lu\n",
        WiFi.status() == WL_CONNECTED, WiFi.status() == WL_CONNECTED ? static_cast<int>(WiFi.RSSI()) : 0, static_cast<int>(WiFi.channel()),
        haveResponse ? "authenticated" : "lost", static_cast<unsigned long>(answered), static_cast<unsigned long>(missed),
        static_cast<unsigned long>(lastLatency), static_cast<unsigned long>(maxLatency),
        SCREENS[shownState > LastState ? static_cast<uint8_t>(Offline) : shownState].name, static_cast<unsigned long>(ESP.getFreeHeap()));
}

void loop() {
    const uint32_t now = millis();
    logLink(now);

    if (WiFi.status() != WL_CONNECTED) {
        haveResponse = false;
        pending = false;
        updateOutputs(WifiConnecting);
        if (now - lastReconnect >= 10000) {
            lastReconnect = now;
            WiFi.reconnect();
        }
        delay(20);
        return;
    }

    if (!sbfeedback::initialized) {
        updateOutputs(Starting);
        if (now - lastInit >= 2000) {
            lastInit = now;
            sbfeedback::begin(CAMERA_MAC);
        }
        delay(20);
        return;
    }

    sbfeedback::Packet packet{};
    if (sbfeedback::read(packet)) {
        // Accept only the answer to the outstanding nonce: replayed or late answers are ignored.
        if (packet.kind == 2 && packet.state <= LastState && pending
            && now - lastRequest <= RESPONSE_WINDOW_MS && memcmp(packet.nonce, challenge, 16) == 0) {
            pending = false;
            haveResponse = true;
            lastResponse = now;
            lastLatency = now - lastRequest;
            if (lastLatency > maxLatency) maxLatency = lastLatency;
            answered++;
            currentState = packet.state;
        }
    }

    if (now - lastRequest >= REQUEST_INTERVAL_MS) {
        if (pending) missed++;
        lastRequest = now;
        sbfeedback::Packet request{};
        request.kind = 1;
        esp_fill_random(challenge, sizeof(challenge));
        memcpy(request.nonce, challenge, 16);
        pending = sbfeedback::send(request);
    }

    if (haveResponse && now - lastResponse <= LINK_LOST_MS) {
        updateOutputs(currentState);
    } else {
        haveResponse = false;
        updateOutputs(Offline);
    }
    delay(20);
}
