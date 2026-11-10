#pragma once

#include "iot_readiness.h"
#include <cstdio>
#include <cstring>
#include <string>

namespace deviceiot {
enum class Kind { Other, Pair, Ticket };
enum class Status { Disabled, NeedTime, NeedPair, Paired, Processing, Valid, Invalid, Unknown, Revoked, RateLimited };
inline Kind classify(const uint8_t *value, size_t length) {
    if (readiness::smartBarangayFormat(value, length)) return Kind::Ticket;
    if (!value || length != 69 || std::memcmp(value, "SBP1:", 5)) return Kind::Other;
    for (size_t i = 5; i < length; ++i) {
        if (!((value[i] >= '0' && value[i] <= '9') || (value[i] >= 'a' && value[i] <= 'f'))) return Kind::Other;
    }
    return Kind::Pair;
}
inline bool uuid(const char *value) {
    if (!value || std::strlen(value) != 36) return false;
    const std::string prefixed = std::string("SBQ1:") + value;
    return readiness::smartBarangayFormat(reinterpret_cast<const uint8_t *>(prefixed.data()), prefixed.size());
}
inline bool hex(const char *value, size_t length) {
    if (!value || std::strlen(value) != length) return false;
    for (size_t i = 0; i < length; ++i) {
        if (!((value[i] >= '0' && value[i] <= '9') || (value[i] >= 'a' && value[i] <= 'f'))) return false;
    }
    return true;
}
inline std::string canonical(const char *path, const char *device, unsigned version,
                             uint64_t seconds, const char *nonce, const char *bodyHash) {
    if (!path || (std::strcmp(path, "/api/iot/v1/pair") && std::strcmp(path, "/api/iot/v1/scans") && std::strcmp(path, "/api/iot/v1/probe"))
        || !uuid(device) || !version || version > 999999999 || seconds < 1000000000ULL || seconds > 9999999999ULL
        || !hex(nonce, 32) || !hex(bodyHash, 64)) return {};
    char numbers[48];
    std::snprintf(numbers, sizeof(numbers), "%u\n%llu", version, static_cast<unsigned long long>(seconds));
    return std::string("SB-IOT-1\nPOST\n") + path + "\n" + device + "\n" + numbers + "\n" + nonce + "\n" + bodyHash;
}
struct Observation {
    char payload[70] = {};
    Kind kind = Kind::Other;
    uint64_t acquiredMs = 0, capturedUtcMs = 0;
    uint32_t session = 0, sequence = 0;
};
struct Reply {
    int http = 0;
    bool wellFormed = false, valid = false;
    char pairingId[37] = {};
    uint64_t expiresUtcMs = 0;
};

// Single owner on the communication worker. No unbounded queue or persisted QR.
class Session {
public:
    Status status = Status::NeedPair;
    char pairingId[37] = {};
    uint64_t expiresUtcMs = 0, backoffUntilMs = 0;
    bool busy = false;

    bool offer(const uint8_t *value, size_t length, uint64_t acquiredMs, uint64_t capturedUtcMs,
               uint32_t scannerSession, uint32_t sequence, uint64_t nowMs, uint64_t utcMs, bool timeReady,
               Observation &output) {
        if (status == Status::Revoked) return false;
        if (!timeReady) { clear(Status::NeedTime); return false; }
        if (busy || nowMs < backoffUntilMs || nowMs < acquiredMs || nowMs - acquiredMs >= 5000
            || capturedUtcMs < 1000000000000ULL || capturedUtcMs > utcMs || utcMs - capturedUtcMs >= 5000
            || !scannerSession || !sequence || scannerSession > 2147483647u || sequence > 2147483647u) return false;
        const Kind kind = classify(value, length);
        if (kind == Kind::Other) return false;
        if (std::strlen(lastQr) == length && !std::memcmp(lastQr, value, length)) return false;
        std::memset(lastQr, 0, sizeof(lastQr));
        std::memcpy(lastQr, value, length);
        if (kind == Kind::Ticket && (!pairingId[0] || utcMs >= expiresUtcMs)) { clear(Status::NeedPair); return false; }
        output = Observation{};
        std::memcpy(output.payload, value, length);
        output.kind = kind;
        output.acquiredMs = acquiredMs;
        output.capturedUtcMs = capturedUtcMs;
        output.session = scannerSession;
        output.sequence = sequence;
        busy = true;
        status = Status::Processing;
        return true;
    }

    void finish(const Observation &event, const Reply &reply, uint64_t nowMs, uint64_t utcMs) {
        busy = false;
        if (nowMs < event.acquiredMs || nowMs - event.acquiredMs >= 5000) { clear(Status::Unknown); return; }
        if (reply.http == 401 || reply.http == 403) { clear(Status::Revoked); return; }
        if (reply.http == 429) { clear(Status::RateLimited); backoffUntilMs = nowMs + 60000; return; }
        if (reply.http != 200 || !reply.wellFormed) { clear(Status::Unknown); backoffUntilMs = nowMs + 3000; return; }
        if (event.kind == Kind::Pair) {
            if (!uuid(reply.pairingId) || reply.expiresUtcMs <= utcMs || reply.expiresUtcMs - utcMs > 600000) {
                clear(Status::Unknown); return;
            }
            std::memcpy(pairingId, reply.pairingId, sizeof(pairingId));
            expiresUtcMs = reply.expiresUtcMs;
            status = Status::Paired;
        } else { status = reply.valid ? Status::Valid : Status::Invalid; }
    }
    void clear(Status reason) {
        std::memset(pairingId, 0, sizeof(pairingId));
        expiresUtcMs = 0;
        busy = false;
        status = reason;
        // Keep the exact last QR latch: reconnect must not resubmit a stationary/stale code.
    }
private:
    char lastQr[70] = {};
};

inline std::string body(const Observation &event, const char *boot, const char *pairing) {
    if (!hex(boot, 32)) return {};
    if (event.kind == Kind::Pair) {
        if (classify(reinterpret_cast<const uint8_t *>(event.payload), std::strlen(event.payload)) != Kind::Pair) return {};
        return std::string("{\"challenge\":\"") + event.payload + "\",\"boot_id\":\"" + boot + "\"}";
    }
    if (event.kind != Kind::Ticket || !uuid(pairing)
        || classify(reinterpret_cast<const uint8_t *>(event.payload), std::strlen(event.payload)) != Kind::Ticket) return {};
    char values[130];
    std::snprintf(values, sizeof(values), "\",\"scanner_session\":%u,\"sequence\":%u,\"captured_at_ms\":%llu,\"payload\":\"",
                  static_cast<unsigned>(event.session), static_cast<unsigned>(event.sequence), static_cast<unsigned long long>(event.capturedUtcMs));
    return std::string("{\"pairing_id\":\"") + pairing + "\",\"boot_id\":\"" + boot + values + event.payload + "\"}";
}
struct Lights { bool green = false, red = false, yellow = false; };
inline Lights lights(Status status, uint64_t elapsedMs) {
    if (status == Status::Valid) return {true, false, false};
    if (status == Status::Invalid) return {false, true, false};
    if (status == Status::Processing) return {false, false, true};
    if (status == Status::Unknown || status == Status::Revoked || status == Status::RateLimited)
        return {false, false, (elapsedMs % 1200 < 150) || (elapsedMs % 1200 >= 300 && elapsedMs % 1200 < 450)};
    return {};
}
} // namespace deviceiot
