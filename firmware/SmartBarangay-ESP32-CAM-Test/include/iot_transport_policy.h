#pragma once
#include <cstdint>
#include <cstddef>
#include <algorithm>

namespace deviceiot::transport {
constexpr uint32_t captureLifetimeMs = 5000; // Laravel freshness contract: never extend.
constexpr uint32_t controlDeadlineMs = 20000;
constexpr uint32_t dnsTimeoutMs = 2000, tcpTimeoutMs = 3000, handshakeTimeoutMs = 8000, readTimeoutMs = 3000;
constexpr uint32_t minimumInternalFree = 32 * 1024, minimumInternalBlock = 16 * 1024;

inline uint32_t remaining(uint64_t now, uint64_t deadline, uint32_t cap) {
    return now >= deadline ? 0 : static_cast<uint32_t>(std::min<uint64_t>(deadline - now, cap));
}
inline bool memoryAvailable(size_t free, size_t largest) {
    // Conservative admission floor, not a claim that a particular TLS chain fits.
    return free >= minimumInternalFree && largest >= minimumInternalBlock;
}
inline const char *cameraHealth(bool camera, bool decoder) {
    return !camera ? "camera_error" : decoder ? "ready" : "detection_error";
}
class LogGate {
    uint64_t errorAt = 0, successAt = 0;
    bool errorSeen = false, successSeen = false;
public:
    bool allow(uint64_t now, bool success) {
        auto &last = success ? successAt : errorAt;
        auto &seen = success ? successSeen : errorSeen;
        if (seen && now - last < (success ? 30000u : 5000u)) return false;
        last = now; seen = true; return true;
    }
};
}
