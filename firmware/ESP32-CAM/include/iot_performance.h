#pragma once

#include <algorithm>
#include <array>
#include <cstdint>

namespace deviceiot {
struct QrAttemptTiming {
    uint32_t captureUs = 0;
    uint32_t convertUs = 0;
    uint32_t grayUs = 0;
    uint32_t detectUs = 0;
    uint32_t decodeUs = 0;
    uint32_t totalUs = 0;
    bool captured = false;
    bool decoded = false;
};

class QrPerformance {
    std::array<uint32_t, 32> durations{};
    unsigned count = 0;
    unsigned position = 0;
public:
    uint32_t attempts = 0;
    uint32_t decodes = 0;
    uint32_t captureFailures = 0;
    QrAttemptTiming last;

    void record(const QrAttemptTiming &sample) {
        last = sample;
        ++attempts;
        if (sample.decoded) ++decodes;
        if (!sample.captured) ++captureFailures;
        durations[position] = sample.totalUs;
        position = (position + 1) % durations.size();
        if (count < durations.size()) ++count;
    }

    uint32_t meanUs() const {
        uint64_t sum = 0;
        for (unsigned i = 0; i < count; ++i) sum += durations[i];
        return count ? static_cast<uint32_t>(sum / count) : 0;
    }

    uint32_t p95Us() const {
        if (!count) return 0;
        auto sorted = durations;
        std::sort(sorted.begin(), sorted.begin() + count);
        return sorted[(count * 95 + 99) / 100 - 1];
    }
};
}
