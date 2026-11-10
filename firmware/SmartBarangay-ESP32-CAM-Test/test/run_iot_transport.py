"""Host fault injection against the actual DeadlineClient class; synthetic inputs only."""
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT / "src/iot_client.cpp").read_text()
client = source[source.index("class DeadlineClient"):source.index("class ResponseBuffer")]
metrics = source[source.index("struct TransportMetrics"):source.index("transport::LogGate diagnosticGate")]
STUBS = r'''
#include "iot_transport_policy.h"
#include "iot_crypto.h"
#include <atomic>
#include <cassert>
#include <cstring>
#include <memory>
#include <iostream>
using namespace deviceiot;
uint64_t clockMs = 0;
uint64_t monotonicMs() { return clockMs; }
int observations = 0, queued = 0;
unsigned uxQueueMessagesWaiting(int) { return queued; }
struct IPAddress { explicit IPAddress(uint32_t) {} };
std::atomic<int> dnsState{2};
std::atomic<uint32_t> dnsAddress{1}, allocationFailures{0};
int dnsAnswer = 2;
void dnsLookup(void*) {}
constexpr int ERR_OK = 0;
int tcpip_callback(void(*)(void*), void*) { dnsState = dnsAnswer; return ERR_OK; }
void vTaskDelay(uint32_t ms) { clockMs += ms; }
#define pdMS_TO_TICKS(ms) (ms)
constexpr int MBEDTLS_ERR_SSL_ALLOC_FAILED = -0x7f00, MBEDTLS_ERR_X509_ALLOC_FAILED = -0x2880;
constexpr int MBEDTLS_ERR_X509_CERT_VERIFY_FAILED = -0x2700, MBEDTLS_ERR_SSL_TIMEOUT = -0x6800;
struct Context { unsigned long handshake_timeout = 0, socket_timeout = 0; int last_error = 0, ssl_ctx = 0; };
int tcpResult = 1, tlsResult = 0, tcpCalls = 0, writeCalls = 0;
uint32_t writeDelay = 0;
size_t largestWrite = 0;
uint32_t tcpDelay = 10, tlsDelay = 10, verifyFlags = 0;
uint32_t observedTcpBudget = 0, observedTlsBudget = 0;
int start_ssl_client(Context*, IPAddress, uint16_t, const char* host, uint32_t timeout,
                     const char* ca, bool bundle, void*, void*, void*, void*, bool insecure, void*) {
    assert(!strcmp(host, "synthetic.invalid") && !strcmp(ca, "synthetic CA"));
    assert(!bundle && !insecure); observedTcpBudget = timeout; tcpCalls++; clockMs += tcpDelay; return tcpResult;
}
int ssl_starttls_handshake(Context* context) { observedTlsBudget = context->handshake_timeout; clockMs += tlsDelay; return tlsResult; }
uint32_t mbedtls_ssl_get_verify_result(int*) { return verifyFlags; }
class WiFiClientSecure {
public:
    std::shared_ptr<Context> sslclient = std::make_shared<Context>();
    const char* _CA_cert = "synthetic CA";
    bool _connected = false;
    void stop() { _connected = false; }
    virtual int connect(const char*, uint16_t, int32_t) { return 0; }
    virtual int available() { return 0; }
    virtual uint8_t connected() { return _connected; }
    virtual int read() { return -1; }
    virtual int read(uint8_t*, size_t) { return -1; }
    virtual size_t write(const uint8_t*, size_t size) {
        ++writeCalls; largestWrite = std::max(largestWrite, size);
        if (writeDelay > sslclient->socket_timeout) { clockMs += sslclient->socket_timeout; return 0; }
        clockMs += writeDelay; return size;
    }
};
'''
TESTS = r'''
void reset() {
    clockMs = 100; queued = 0; dnsState = 0; dnsAnswer = 2; tcpResult = 1; tlsResult = 0;
    tcpDelay = 10; tlsDelay = 10; verifyFlags = 0; tcpCalls = 0; writeCalls = 0; writeDelay = 0; largestWrite = 0;
}
int main() {
    assert(transport::remaining(5000, 5000, 3000) == 0);
    assert(transport::remaining(4000, 5000, 8000) == 1000);
    assert(!transport::memoryAvailable(55000, 8000)); // fragmented internal RAM
    assert(!transport::memoryAvailable(30000, 20000));
    assert(transport::memoryAvailable(55000, 20000));
    assert(!strcmp(transport::cameraHealth(true, true), "ready"));
    assert(!strcmp(transport::cameraHealth(true, false), "detection_error"));
    transport::LogGate gate;
    assert(gate.allow(0, false) && !gate.allow(1000, false) && gate.allow(5000, false));
    assert(gate.allow(5001, true) && !gate.allow(10000, true) && gate.allow(35001, true));
    const uint8_t raw[] = {'a', 0, 'b'};
    assert(digestBytes(raw, 3) == digest(std::string("a\0b", 3)));

    reset(); DeadlineClient ok; ok.deadline = clockMs + 20000;
    assert(ok.connect("synthetic.invalid", 443, 3000) == 1 && ok._connected);
    assert(observedTlsBudget == 8000 && observedTcpBudget == 3000);
    reset(); DeadlineClient dns; dns.deadline = clockMs + 20000; dnsAnswer = 1;
    assert(!dns.connect("synthetic.invalid", 443, 3000));
    assert(!strcmp(dns.metrics.category, "dns_timeout") && clockMs == 2100 && tcpCalls == 0);
    // Late resolver completion has static lifetime; the next request can consume it.
    dnsState = 2; assert(dns.connect("synthetic.invalid", 443, 3000));
    reset(); DeadlineClient badDns; badDns.deadline = clockMs + 20000; dnsAnswer = 3;
    assert(!badDns.connect("synthetic.invalid", 443, 3000));
    assert(!strcmp(badDns.metrics.category, "dns_resolution_failed"));
    reset(); DeadlineClient tcp; tcp.deadline = clockMs + 20000; tcpResult = -1; tcpDelay = 3000;
    assert(!tcp.connect("synthetic.invalid", 443, 3000)); assert(!strcmp(tcp.metrics.category, "tcp_connect_timeout"));
    reset(); DeadlineClient cert; cert.deadline = clockMs + 20000; tlsResult = MBEDTLS_ERR_X509_CERT_VERIFY_FAILED; verifyFlags = 8;
    assert(!cert.connect("synthetic.invalid", 443, 3000) && !cert._connected);
    assert(!strcmp(cert.metrics.category, "tls_certificate_rejected"));
    reset(); DeadlineClient tls; tls.deadline = clockMs + 20000; tlsResult = -1; tlsDelay = 8001; verifyFlags = UINT32_MAX;
    assert(!tls.connect("synthetic.invalid", 443, 3000)); assert(!strcmp(tls.metrics.category, "tls_handshake_timeout"));
    reset(); DeadlineClient alloc; alloc.deadline = clockMs + 20000; tlsResult = MBEDTLS_ERR_SSL_ALLOC_FAILED; verifyFlags = UINT32_MAX;
    assert(!alloc.connect("synthetic.invalid", 443, 3000)); assert(!strcmp(alloc.metrics.category, "tls_allocation_failed"));
    reset(); DeadlineClient scan; scan.deadline = clockMs + 5000; tcpDelay = 1000;
    assert(scan.connect("synthetic.invalid", 443, 3000)); assert(observedTlsBudget == 4000);
    reset(); DeadlineClient reconnect; reconnect.deadline = clockMs + 20000; reconnect.allowConnect = false;
    assert(!reconnect.connect("synthetic.invalid", 443, 3000) && tcpCalls == 0);
    assert(!strcmp(reconnect.metrics.category, "connection_closed_before_request"));
    reset(); DeadlineClient stale; stale.deadline = clockMs;
    assert(!stale.connect("synthetic.invalid", 443, 3000) && tcpCalls == 0);
    reset(); DeadlineClient writes; writes.deadline = clockMs + 5000;
    uint8_t payload[4096] = {};
    assert(writes.write(payload, sizeof(payload)) == sizeof(payload) && writeCalls == 4 && largestWrite == 1024);
    reset(); DeadlineClient slow; slow.deadline = clockMs + 5000; writeDelay = 2000;
    assert(slow.write(payload, sizeof(payload)) == 2048 && clockMs == 5100);
    reset(); DeadlineClient preview; preview.deadline = clockMs + 5000; preview.previewTransfer = true; queued = 1;
    assert(!preview.alive()); assert(!strcmp(preview.metrics.category, "preview_yielded_to_scan"));
    std::cout << "PASS: actual transport fault injection: DNS late/failed/timeout, TCP timeout, TLS certificate/timeout/allocation, bounded deadlines, preview priority, heap fragmentation, log limits, binary hashing.\n";
}
'''
with tempfile.TemporaryDirectory(prefix="sb-iot-transport-") as temporary:
    cpp = Path(temporary) / "test.cpp"
    binary = Path(temporary) / "test.exe"
    cpp.write_text(STUBS + metrics + client + TESTS)
    subprocess.run(["g++", "-std=c++17", "-Wall", "-Wextra", "-Werror", "-DSB_IOT_HOST_TEST", "-I" + str(ROOT / "include"), str(cpp), "-lcrypto", "-o", str(binary)], check=True)
    subprocess.run([str(binary)], check=True)
assert "POST(String(json.c_str()))" not in source
assert "setInsecure(" not in source and "getString(" not in source
assert "heap_caps_malloc(capacity, MALLOC_CAP_SPIRAM" in source
assert "http.setReuse(true)" in source and "setCACert(IOT_ROOT_CA)" in source
main = (ROOT / "src/main.cpp").read_text()
assert 'deviceiot::cameraState' not in main[main.index('void loop()'):]
assert 'deviceiot::cameraState(cameraReady.load(), qrActive.load());' in main
print("PASS: no full preview String copies, trusted CA retained, reusable HTTP connection, bounded safe diagnostics.")
