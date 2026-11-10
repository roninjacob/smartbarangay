#pragma once
#include <cstddef>
#include <cstdint>

namespace deviceiot {
bool start(const char *bootId);
void observe(const uint8_t *payload, size_t length, uint64_t acquiredMs, uint32_t session, uint32_t sequence);
void invalidate();
void tick();
const char *statusText();
int httpStatus();
int tlsStatus();
bool enabled();
} // namespace deviceiot
