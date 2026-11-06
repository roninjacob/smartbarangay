#pragma once

#include <cstddef>
#include <cstdint>
#include <cstring>

// Default is safe for real-credential readiness tests: no HTTP images/video.
// Enable only in a separate dummy-only development build on a trusted LAN.
#ifndef SB_CAMERA_DIAGNOSTICS
#define SB_CAMERA_DIAGNOSTICS 0
#endif
#if SB_CAMERA_DIAGNOSTICS != 0 && SB_CAMERA_DIAGNOSTICS != 1
#error "SB_CAMERA_DIAGNOSTICS must be 0 or 1"
#endif

namespace readiness {
constexpr bool cameraDiagnostics = SB_CAMERA_DIAGNOSTICS == 1;
constexpr uint64_t eventLifetimeMs = 5000;
constexpr uint64_t repeatCooldownMs = 5000;

// Transport-format hint only. This never establishes ticket validity/eligibility.
inline bool smartBarangayFormat(const uint8_t *bytes, size_t length) {
    if (!bytes || length != 41 || std::memcmp(bytes, "SBQ1:", 5) != 0) { return false; }
    for (size_t i = 5; i < length; ++i) {
        const size_t pos = i - 5;
        if (pos == 8 || pos == 13 || pos == 18 || pos == 23) {
            if (bytes[i] != '-') { return false; }
        } else if (!((bytes[i] >= '0' && bytes[i] <= '9') || (bytes[i] >= 'a' && bytes[i] <= 'f'))) {
            return false;
        }
    }
    return bytes[19] == '4' && (bytes[24] == '8' || bytes[24] == '9' || bytes[24] == 'a' || bytes[24] == 'b');
}

// Fixed storage replaces the old 1025-byte public text cache. No payload accessor.
// The boot nonce + session + sequence is event identity, NEVER authentication.
class ScanEvents {
public:
    uint64_t session = 0, sequence = 0, acquiredMs = 0;
    uint32_t attempt = 0;
    size_t byteCount = 0;
    bool formatMatch = false, present = false;

    void invalidate() {
        std::memset(previous, 0, sizeof(previous));
        previousLength = 0;
        present = false;
        acquiredMs = 0;
        attempt = 0;
        byteCount = 0;
        formatMatch = false;
    }
    void beginSession() { invalidate(); ++session; }
    bool fresh(uint64_t now, bool active) const {
        return present && active && now >= acquiredMs && now - acquiredMs < eventLifetimeMs;
    }
    void expire(uint64_t now) {
        if (present && !fresh(now, true)) {
            std::memset(previous, 0, sizeof(previous));
            previousLength = 0;
        }
    }
    bool observe(const uint8_t *bytes, size_t length, uint64_t now, uint32_t scanAttempt) {
        if (!bytes || !length || length > sizeof(previous) || !session) { invalidate(); return false; }
        const bool repeat = present && length == previousLength
            && now >= acquiredMs && now - acquiredMs < repeatCooldownMs
            && std::memcmp(previous, bytes, length) == 0;
        if (repeat) { return false; } // Re-reading/polling does not refresh age.
        std::memset(previous, 0, sizeof(previous));
        std::memcpy(previous, bytes, length);
        previousLength = length;
        acquiredMs = now;
        attempt = scanAttempt;
        byteCount = length;
        formatMatch = smartBarangayFormat(bytes, length);
        present = true;
        ++sequence;
        return true;
    }
private:
    uint8_t previous[1024] = {};
    size_t previousLength = 0;
};
} // namespace readiness
