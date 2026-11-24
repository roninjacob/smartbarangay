#pragma once

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include <esp_random.h>
#include <mbedtls/md.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <cstring>
#include <cstddef>
#include "feedback_keys.h"

namespace sbfeedback {

struct __attribute__((packed)) Packet {
    uint8_t kind;
    uint8_t state;
    uint8_t nonce[16];
    uint8_t tag[32];
};

static QueueHandle_t queue = nullptr;
static uint8_t peerMac[6];
static bool initialized = false;

inline bool sign(Packet &p) {
    const auto *md =
        mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

    return md && mbedtls_md_hmac(
        md, SB_AUTH, sizeof(SB_AUTH),
        reinterpret_cast<const uint8_t *>(&p),
        offsetof(Packet, tag), p.tag
    ) == 0;
}

inline bool authenticated(const Packet &p) {
    Packet copy = p;

    if (!sign(copy)) return false;

    uint8_t diff = 0;

    for (size_t i = 0; i < sizeof(p.tag); i++)
        diff |= copy.tag[i] ^ p.tag[i];

    return diff == 0;
}

inline void receive(
    const esp_now_recv_info_t *info,
    const uint8_t *data, int len
) {
    if (!info || !data || !queue ||
        len != sizeof(Packet) ||
        memcmp(info->src_addr, peerMac, 6) != 0)
        return;

    Packet p;
    memcpy(&p, data, sizeof(p));
    xQueueOverwrite(queue, &p);
}

inline bool begin(const uint8_t *mac) {
    if (initialized) return true;

    if (WiFi.status() != WL_CONNECTED)
        return false;

    memcpy(peerMac, mac, 6);

    if (!queue)
        queue = xQueueCreate(1, sizeof(Packet));

    if (!queue) return false;

    if (esp_now_init() != ESP_OK)
        return false;

    esp_now_peer_info_t peer{};

    memcpy(peer.peer_addr, mac, 6);
    memcpy(peer.lmk, SB_LMK, 16);

    peer.channel = 0;
    peer.ifidx = WIFI_IF_STA;
    peer.encrypt = true;

    if (esp_now_set_pmk(SB_PMK) != ESP_OK ||
        esp_now_add_peer(&peer) != ESP_OK ||
        esp_now_register_recv_cb(receive) != ESP_OK) {

        esp_now_deinit();
        return false;
    }

    initialized = true;

    Serial.println("[ESP-NOW] Secure link initialized");

    return true;
}

inline bool send(Packet &p) {
    if (!initialized || !sign(p))
        return false;

    return esp_now_send(
        peerMac,
        reinterpret_cast<const uint8_t *>(&p),
        sizeof(p)
    ) == ESP_OK;
}

inline bool read(Packet &p) {
    return queue &&
        xQueueReceive(queue, &p, 0) == pdTRUE &&
        authenticated(p);
}

}
