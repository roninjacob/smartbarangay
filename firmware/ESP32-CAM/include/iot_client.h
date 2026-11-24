#pragma once
#include <cstddef>
#include <cstdint>
#include "iot_performance.h"

namespace deviceiot {
bool start(const char *bootId);
void observe(const uint8_t *payload, size_t length, uint64_t acquiredMs, uint32_t session, uint32_t sequence);
void offerFrame(const uint8_t *jpeg, size_t length, uint64_t acquiredMs);
void cameraState(bool ready, bool decoderReady);
void sceneEmpty(bool empty);
void invalidate();
void recordQrAttempt(const QrAttemptTiming &sample);
void tick();
const char *statusText();
int httpStatus();
int tlsStatus();
bool enabled();
} // namespace deviceiot
