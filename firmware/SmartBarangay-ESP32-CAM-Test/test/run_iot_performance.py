"""Native tests for the actual fixed-size firmware timing window. No credentials."""
from pathlib import Path
import subprocess
import tempfile
import json
import re

root = Path(__file__).resolve().parents[1]
source = r'''
#include "iot_performance.h"
#include <cassert>
#include <iostream>
int main() {
    deviceiot::QrPerformance stats;
    assert(stats.meanUs() == 0 && stats.p95Us() == 0);
    for (unsigned i = 1; i <= 32; ++i) {
        stats.record({1, 2, 3, 4, 5, i * 1000, true, i % 2 == 0});
    }
    assert(stats.attempts == 32 && stats.decodes == 16 && stats.captureFailures == 0);
    assert(stats.meanUs() == 16500 && stats.p95Us() == 31000);
    stats.record({0, 0, 0, 0, 0, 33000, false, false});
    assert(stats.meanUs() == 17500 && stats.p95Us() == 32000 && stats.captureFailures == 1);
    assert(stats.last.totalUs == 33000);
    for (unsigned i = 0; i < 32; ++i) stats.record({0, 0, 0, 0, 0, UINT32_MAX, true, false});
    assert(stats.meanUs() == UINT32_MAX && stats.p95Us() == UINT32_MAX);
    std::cout << "PASS: bounded 32-attempt window, nearest-rank p95, rolling mean, failure counters, 64-bit summation.\n";
}
'''
with tempfile.TemporaryDirectory(prefix="sb-iot-performance-") as temporary:
    cpp = Path(temporary) / "test.cpp"
    binary = Path(temporary) / "test.exe"
    cpp.write_text(source)
    subprocess.run(["g++", "-std=c++17", "-Wall", "-Wextra", "-Werror", "-I" + str(root / "include"), str(cpp), "-o", str(binary)], check=True)
    subprocess.run([str(binary)], check=True)

# Bound the exact production heartbeat format with maximal allowed numeric values.
client = (root / "src/iot_client.cpp").read_text()
formatter = client.split("const int length = snprintf(statusJson, sizeof(statusJson),", 1)[1].split("boot, static_cast", 1)[0]
template = "".join(json.loads(literal) for literal in re.findall(r'"(?:\\.|[^"\\])*"', formatter))
strings = iter(["1" * 32, "detection_error", "local_configuration_or_body_invalid"])
body = re.sub(r"%llu|%lu|%d|%s", lambda match: next(strings) if match[0] == "%s" else "9999999999999" if match[0] == "%llu" else "4294967295" if match[0] == "%lu" else "-127", template)
assert len(body.encode()) <= 1024, "Actual heartbeat can exceed the authenticated API limit"
json.loads(body)
print(f"PASS: production heartbeat format bounded at {len(body.encode())} bytes with maximal fields (1024-byte API limit).")
