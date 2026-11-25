#include "feedback_protocol.h"
#include <atomic>

namespace deviceiot {
uint8_t feedbackState();
}

static const uint8_t DEVKIT_MAC[6] = {
    0x20, 0x50, 0x0D, 0x2A, 0x39, 0xC8
};

// Answers each authenticated request as soon as it arrives, independent of the main loop and
// of QR processing, so the feedback device never mistakes a slow loop for a lost scanner.
static std::atomic<uint32_t> lastRequestAt{0};

// Evidence of real peer communication for telemetry: last authenticated DevKit request.
uint32_t feedbackLastRequestMs() { return lastRequestAt.load(); }

static void responder(void *) {
    sbfeedback::Packet packet{};
    for (;;) {
        if (!sbfeedback::read(packet, pdMS_TO_TICKS(1000)) || packet.kind != 1) continue;
        lastRequestAt = millis();
        packet.kind = 2;
        packet.state = deviceiot::feedbackState();
        sbfeedback::send(packet);
    }
}

void feedbackTick() {
    static uint32_t lastInit = 0, created = 0;
    static bool reported = false;
    static TaskHandle_t task = nullptr;
    if (task) {
        // Internal RAM is tight after TLS: report the responder's unused stack once.
        if (!reported && millis() - created > 60000) {
            reported = true;
            Serial.printf("[ESP-NOW] Responder stack free=%u bytes\n", static_cast<unsigned>(uxTaskGetStackHighWaterMark(task)));
        }
        return;
    }
    if (WiFi.status() != WL_CONNECTED) return;
    if (!sbfeedback::initialized) {
        if (millis() - lastInit < 2000) return;
        lastInit = millis();
        if (!sbfeedback::begin(DEVKIT_MAC)) return;
    }
    // HMAC-SHA256 signing and esp_now_send need little stack; keep internal RAM for TLS.
    if (xTaskCreatePinnedToCore(responder, "feedback-link", 3072, nullptr, 2, &task, 0) != pdPASS) {
        task = nullptr;
        Serial.println("[ESP-NOW] Responder task allocation failed; retrying.");
        return;
    }
    created = millis();
}
