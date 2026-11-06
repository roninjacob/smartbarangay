#include "iot_readiness.h"
extern "C" {
#include "quirc.h"
}
#include <cassert>
#include <fstream>
#include <iostream>
#include <iterator>
#include <string>
#include <vector>

int main(int argc, char **argv) {
    if (argc == 4) {
        std::ifstream input(argv[1], std::ios::binary);
        std::string magic; int width, height, maximum;
        input >> magic >> width >> height >> maximum; input.get();
        assert(magic == "P5" && maximum == 255);
        quirc *decoder = quirc_new(); assert(decoder && quirc_resize(decoder, width, height) == 0);
        uint8_t *pixels = quirc_begin(decoder, nullptr, nullptr);
        input.read(reinterpret_cast<char *>(pixels), width * height); assert(input.gcount() == width * height);
        quirc_end(decoder);
        quirc_code code; quirc_data data;
        bool found = false;
        for (int i = 0; i < quirc_count(decoder); ++i) {
            quirc_extract(decoder, i, &code);
            auto error = quirc_decode(&code, &data);
            if (error == QUIRC_ERROR_DATA_ECC) { quirc_flip(&code); error = quirc_decode(&code, &data); }
            if (error != QUIRC_SUCCESS) { continue; }
            const std::string actual(reinterpret_cast<char *>(data.payload), data.payload_len);
            assert(actual == argv[2]); // Compare exact bytes and length; no trimming/case conversion.
            assert(readiness::smartBarangayFormat(data.payload, data.payload_len) == (std::string(argv[3]) == "1"));
            found = true; break;
        }
        quirc_destroy(decoder); assert(found);
        return 0;
    }
    const std::string a = "SBQ1:550e8400-e29b-41d4-a716-446655440000", b = "SMARTBARANGAY-TEST-A";
    auto bytes = [](const std::string &value) { return reinterpret_cast<const uint8_t *>(value.data()); };
    readiness::ScanEvents events;
    assert(!events.fresh(0, true));
    assert(!events.observe(bytes(a), a.size(), 0, 1)); // Must enter a scanner session.
    events.beginSession();
    assert(events.observe(bytes(a), a.size(), 0, 1)); // Uptime zero is not an absent sentinel.
    assert(events.formatMatch && events.byteCount == 41 && events.session == 1 && events.sequence == 1);
    for (int i = 0; i < 20; ++i) { assert(events.fresh(4999, true)); }
    assert(events.sequence == 1 && events.acquiredMs == 0); // Status reads never mint events.
    assert(!events.observe(bytes(a), a.size(), 4999, 2));
    assert(events.attempt == 1 && events.acquiredMs == 0); // Repeats cannot renew freshness.
    assert(!events.fresh(5000, true));
    events.expire(5000);
    assert(events.observe(bytes(a), a.size(), 5000, 3));
    assert(events.sequence == 2);
    assert(events.observe(bytes(b), b.size(), 5001, 4));
    assert(!events.formatMatch && events.sequence == 3); // Ordinary QR decoding is preserved.
    assert(!events.fresh(5001, false));
    events.invalidate(); assert(!events.present && !events.fresh(5001, true));
    events.beginSession(); assert(events.session == 2);
    assert(events.observe(bytes(a), a.size(), 5002, 5) && events.sequence == 4);
    events.beginSession(); assert(!events.fresh(5002, true));
    assert(events.observe(bytes(a), a.size(), 5003, 6) && events.session == 3);
    assert(!events.fresh(5002, true)); // Time reversal fails closed.
    const auto sequence = events.sequence;
    assert(!events.observe(nullptr, 41, 5004, 7));
    assert(!events.observe(bytes(a), 0, 5004, 7));
    std::vector<uint8_t> huge(1025, 'x');
    assert(!events.observe(huge.data(), huge.size(), 5004, 7));
    assert(events.sequence == sequence);
    events.beginSession();
    assert(events.observe(bytes(a), a.size(), (uint64_t{1} << 32) + 100, 8));
    assert(events.fresh((uint64_t{1} << 32) + 5099, true)); // Beyond millis rollover.
    assert(!events.fresh((uint64_t{1} << 32) + 5100, true));
    const uint8_t binary[] = {'x', 0, 'y'};
    assert(events.observe(binary, sizeof(binary), (uint64_t{1} << 32) + 5200, 9));
    assert(!events.formatMatch && events.byteCount == 3);
    assert(!events.observe(binary, sizeof(binary), (uint64_t{1} << 32) + 5201, 10));
    std::cout << "PASS: event lifecycle, exact repeat comparison, mode reset, expiry, bounds and 64-bit uptime\n";
}
