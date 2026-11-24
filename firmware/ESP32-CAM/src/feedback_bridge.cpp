#include "feedback_protocol.h"

namespace deviceiot {
    uint8_t feedbackState();
}

static const uint8_t DEVKIT_MAC[6] = {
    0x20, 0x50, 0x0D, 0x2A, 0x39, 0xC8
};

void feedbackTick() {
    if (WiFi.status() != WL_CONNECTED)
        return;

    static uint32_t lastInit = 0;

    if (!sbfeedback::initialized) {
        if (millis() - lastInit < 2000)
            return;

        lastInit = millis();

        if (!sbfeedback::begin(DEVKIT_MAC))
            return;
    }

    sbfeedback::Packet packet{};

    if (!sbfeedback::read(packet) ||
        packet.kind != 1)
        return;

    packet.kind = 2;
    packet.state = deviceiot::feedbackState();

    sbfeedback::send(packet);
}
