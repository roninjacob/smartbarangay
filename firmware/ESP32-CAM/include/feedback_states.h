#pragma once
#include <cstdint>

// Scanner states sent over authenticated ESP-NOW to the feedback device.
// Keep this file identical in firmware/ESP32-CAM/include and firmware/ESP32-Feedback/include.
namespace sbfeedback {
enum State : uint8_t {
    Offline = 0,       // no Wi-Fi, or no authenticated server answer for 20 s
    Starting = 1,      // booting, waiting for time, first server contact, camera starting
    Ready = 2,
    Verifying = 3,
    Authorized = 4,    // server: Ready for Pickup, check-in may proceed
    Denied = 5,        // server rejected the ticket
    AuthError = 6,
    RateLimited = 7,
    WifiConnecting = 8, // feedback device only: its own Wi-Fi is not connected
    Reconnecting = 9,  // a request failed; retrying, last success was recent
    CheckInRecorded = 10,
    AlreadyCheckedIn = 11,
    NotReady = 12,     // valid ticket, reservation not yet Ready for Pickup
    CameraError = 13,
    LastState = CameraError,
};
} // namespace sbfeedback
