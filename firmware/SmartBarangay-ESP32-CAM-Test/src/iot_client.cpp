#include "iot_client.h"
#include "iot_protocol.h"
#include "iot_crypto.h"
#include "iot_transport_policy.h"
#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <atomic>
#include <sys/time.h>
#include "esp_sntp.h"
#include "esp_system.h"
#include "esp_timer.h"
#include "cJSON.h"
#include "mbedtls/base64.h"
#include "esp_heap_caps.h"
#include "lwip/dns.h"
#include "lwip/tcpip.h"

#if defined(SB_IOT_PRIVATE_BUILD) && !SB_CAMERA_DIAGNOSTICS
#if defined(CORE_DEBUG_LEVEL) && CORE_DEBUG_LEVEL > 0
#error "Private IoT builds require framework debug logging disabled."
#endif
#include "smartbarangay_iot_private.generated.h"
#else
static constexpr char IOT_HOST[] = "", IOT_DEVICE_ID[] = "", IOT_KEY_HEX[] = "", IOT_ROOT_CA[] = "";
static constexpr unsigned IOT_PORT = 443, IOT_KEY_VERSION = 0;
#endif

// These outputs require a separate reviewed wiring approval. No buzzer GPIO exists.
#ifndef SB_EXTERNAL_LEDS
#define SB_EXTERNAL_LEDS 0
#endif
#if SB_EXTERNAL_LEDS != 0 && SB_EXTERNAL_LEDS != 1
#error "SB_EXTERNAL_LEDS must be 0 or 1."
#endif
#if SB_EXTERNAL_LEDS && (!defined(SB_LED_WIRING_APPROVED) || SB_LED_WIRING_APPROVED != 1)
#error "External LED wiring requires explicit approval; see IoT-04 wiring prerequisites."
#endif

namespace deviceiot {
namespace {
#ifdef SB_IOT_CONFIGURED
constexpr bool configured = !readiness::cameraDiagnostics;
#else
constexpr bool configured = false;
#endif
struct Pending { uint8_t payload[70]; size_t length; uint64_t acquiredMs; uint32_t session, sequence, generation, presentation; };
QueueHandle_t observations = nullptr;
struct Frame { uint8_t *bytes; size_t length; uint64_t acquiredMs; };
QueueHandle_t frames = nullptr;
std::atomic<bool> previewWanted{false}, cameraOk{false}, decoderOk{false};
std::atomic<uint64_t> previewExpires{0};
char previewLease[37] = {};
char boot[33] = {};
std::atomic<uint32_t> generation{0}, syncedAt{0}, presentation{0};
std::atomic<bool> synchronized{false};
std::atomic<int> currentStatus{static_cast<int>(Status::Disabled)}, lastHttp{0};
std::atomic<int> lastTls{0};
bool started = false;
bool previousWifi = false;

uint64_t monotonicMs() { return static_cast<uint64_t>(esp_timer_get_time() / 1000); }
uint64_t utcMs() { timeval time{}; gettimeofday(&time, nullptr); return static_cast<uint64_t>(time.tv_sec) * 1000 + time.tv_usec / 1000; }
bool clockReady() { return synchronized.load() && static_cast<uint32_t>(millis() - syncedAt.load()) < 900000 && utcMs() >= 1767225600000ULL; }
void synchronizedTime(timeval *) { syncedAt = millis(); synchronized = true; }
void publish(Status status) { currentStatus = static_cast<int>(status); }

std::atomic<uint32_t> allocationFailures{0}, failedBytes{0};
void allocationFailed(size_t bytes, uint32_t caps, const char *) {
    if (caps & MALLOC_CAP_INTERNAL) { allocationFailures++; failedBytes = bytes; }
}
struct TransportMetrics {
    const char *category = "ok";
    uint32_t dnsMs = 0, connectMs = 0, tlsMs = 0, httpMs = 0, verifyFlags = 0;
    bool reused = false;
    size_t heapBefore = 0, largestBefore = 0;
};
transport::LogGate diagnosticGate;
portMUX_TYPE performanceMux = portMUX_INITIALIZER_UNLOCKED;
QrPerformance qrPerformance;
std::atomic<uint32_t> previewCopyUs{0}, previewSent{0}, lastDurationMs{0};
const char *lastCategory = "ok"; // Static allowlisted strings only, owned by transport task.
void diagnose(const char *operation, const TransportMetrics &metrics, uint64_t began, int http, int tls) {
    if (!diagnosticGate.allow(monotonicMs(), !strcmp(metrics.category, "ok"))) return;
    Serial.printf("[IoT-DIAG] op=%s category=%s dns_ms=%lu connect_ms=%lu tls_ms=%lu http_ms=%lu total_ms=%lu reused=%d http=%d tls=%d verify=%lu heap_before=%lu largest_before=%lu heap=%lu largest=%lu min_heap=%lu alloc_fail=%lu failed_bytes=%lu stack=%lu clock_age_ms=%lu camera=%s\n",
        operation, metrics.category, static_cast<unsigned long>(metrics.dnsMs), static_cast<unsigned long>(metrics.connectMs),
        static_cast<unsigned long>(metrics.tlsMs), static_cast<unsigned long>(metrics.httpMs), static_cast<unsigned long>(monotonicMs() - began),
        metrics.reused, http, tls, static_cast<unsigned long>(metrics.verifyFlags),
        static_cast<unsigned long>(metrics.heapBefore), static_cast<unsigned long>(metrics.largestBefore),
        static_cast<unsigned long>(heap_caps_get_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT)),
        static_cast<unsigned long>(heap_caps_get_largest_free_block(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT)),
        static_cast<unsigned long>(heap_caps_get_minimum_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT)),
        static_cast<unsigned long>(allocationFailures.load()), static_cast<unsigned long>(failedBytes.load()),
        static_cast<unsigned long>(uxTaskGetStackHighWaterMark(nullptr)),
        static_cast<unsigned long>(synchronized.load() ? millis() - syncedAt.load() : UINT32_MAX),
        transport::cameraHealth(cameraOk.load(), decoderOk.load()));
}
// One static DNS slot: a timed-out callback never refers to a stack/freed object.
// All DNS API calls execute on the lwIP thread; late completion is consumed safely.
std::atomic<int> dnsState{0};
std::atomic<uint32_t> dnsAddress{0};
void dnsResult(const char *, const ip_addr_t *address, void *) {
    if (address && IP_IS_V4(address)) { dnsAddress = ip_2_ip4(address)->addr; dnsState = 2; }
    else dnsState = 3;
}
void dnsLookup(void *) {
    ip_addr_t address;
    const err_t result = dns_gethostbyname_addrtype(IOT_HOST, &address, dnsResult, nullptr, LWIP_DNS_ADDRTYPE_IPV4);
    if (result == ERR_OK) dnsResult(nullptr, &address, nullptr);
    else if (result != ERR_INPROGRESS) dnsState = 3;
}
class DeadlineClient : public WiFiClientSecure {
public:
    uint64_t deadline = 0;
    bool previewTransfer = false, allowConnect = true;
    TransportMetrics metrics;
    int tlsCode = 0;
    bool alive() {
        if (monotonicMs() >= deadline || (previewTransfer && uxQueueMessagesWaiting(observations) > 0)) {
            metrics.category = monotonicMs() >= deadline ? "deadline_exceeded" : "preview_yielded_to_scan";
            stop(); return false;
        }
        return true;
    }
    int connect(const char *host, uint16_t port, int32_t timeout) override {
        if (!alive()) return 0;
        if (!allowConnect) { metrics.category = "connection_closed_before_request"; return 0; }
        uint64_t phase = monotonicMs();
        if (dnsState == 0) {
            dnsState = 1;
            if (tcpip_callback(dnsLookup, nullptr) != ERR_OK) dnsState = 3;
        }
        const uint64_t until = phase + transport::remaining(phase, deadline, transport::dnsTimeoutMs);
        while (dnsState == 1 && monotonicMs() < until) vTaskDelay(pdMS_TO_TICKS(10));
        metrics.dnsMs = monotonicMs() - phase;
        if (dnsState != 2) {
            metrics.category = dnsState == 1 ? "dns_timeout" : "dns_resolution_failed";
            if (dnsState == 3) dnsState = 0;
            return 0;
        }
        const IPAddress address(dnsAddress.load()); dnsState = 0;
        const uint32_t failures = allocationFailures.load();
        phase = monotonicMs();
        const uint32_t tcpBudget = transport::remaining(phase, deadline, std::min<uint32_t>(timeout > 0 ? timeout : 3000, transport::tcpTimeoutMs));
        if (!tcpBudget) { metrics.category = "deadline_exceeded"; return 0; }
        // Same trusted-CA SDK path, separated only to identify TCP/setup vs handshake failures.
        int result = start_ssl_client(sslclient.get(), address, port, host, tcpBudget, _CA_cert, false,
            nullptr, nullptr, nullptr, nullptr, false, nullptr);
        metrics.connectMs = monotonicMs() - phase;
        if (result < 0) {
            metrics.category = result == MBEDTLS_ERR_SSL_ALLOC_FAILED || result == MBEDTLS_ERR_X509_ALLOC_FAILED
                ? "tls_allocation_failed" : allocationFailures.load() != failures ? "internal_allocation_observed"
                : metrics.connectMs >= tcpBudget ? "tcp_connect_timeout" : "tcp_or_tls_setup_failed";
        } else {
            const uint32_t budget = transport::remaining(monotonicMs(), deadline, transport::handshakeTimeoutMs);
            if (!budget) { metrics.category = "deadline_exceeded"; result = -1; }
            else {
                sslclient->handshake_timeout = budget;
                phase = monotonicMs(); result = ssl_starttls_handshake(sslclient.get());
                metrics.tlsMs = monotonicMs() - phase;
                metrics.verifyFlags = mbedtls_ssl_get_verify_result(&sslclient->ssl_ctx);
                if (result < 0 || metrics.verifyFlags != 0) {
                    metrics.category = result == MBEDTLS_ERR_SSL_ALLOC_FAILED || result == MBEDTLS_ERR_X509_ALLOC_FAILED ? "tls_allocation_failed"
                        : result == MBEDTLS_ERR_X509_CERT_VERIFY_FAILED || (metrics.verifyFlags != 0 && metrics.verifyFlags != UINT32_MAX) ? "tls_certificate_rejected"
                        : allocationFailures.load() != failures ? "internal_allocation_observed"
                        : result == MBEDTLS_ERR_SSL_TIMEOUT || (result == -1 && metrics.tlsMs >= budget) ? "tls_handshake_timeout" : "tls_handshake_failed";
                    if (result >= 0) result = MBEDTLS_ERR_X509_CERT_VERIFY_FAILED;
                }
            }
        }
        sslclient->last_error = result; tlsCode = result < 0 ? result : 0;
        if (result < 0 || !alive()) { stop(); return 0; }
        _connected = true;
        return 1;
    }
    int available() override { return alive() ? WiFiClientSecure::available() : 0; }
    uint8_t connected() override { return alive() ? WiFiClientSecure::connected() : 0; }
    int read() override { return alive() ? WiFiClientSecure::read() : -1; }
    int read(uint8_t *data, size_t size) override { return alive() ? WiFiClientSecure::read(data, size) : -1; }
    size_t write(const uint8_t *data, size_t size) override {
        size_t sent = 0;
        // SDK send_ssl_data refreshes its timeout on progress. Bound each small
        // write separately so a slowly progressing JPEG cannot defeat our deadline.
        while (sent < size && alive()) {
            sslclient->socket_timeout = transport::remaining(monotonicMs(), deadline, transport::readTimeoutMs);
            if (!sslclient->socket_timeout) break;
            const size_t chunk = std::min<size_t>(1024, size - sent);
            const size_t wrote = WiFiClientSecure::write(data + sent, chunk);
            if (!wrote) break;
            sent += wrote;
        }
        return sent;
    }
};
class ResponseBuffer : public Stream {
public:
    char bytes[769] = {};
    size_t length = 0;
    int available() override { return 0; }
    int read() override { return -1; }
    int peek() override { return -1; }
    void flush() override {}
    size_t write(uint8_t byte) override { return write(&byte, 1); }
    size_t write(const uint8_t *data, size_t size) override {
        if (size > sizeof(bytes) - 1 - length) return 0;
        memcpy(bytes + length, data, size); length += size; bytes[length] = 0; return size;
    }
};

// Carbon emits UTC ISO8601 with +00:00. Also accept canonical Z, never local time.
uint64_t expiry(const char *value) {
    if (!value || !((strlen(value) == 25 && !strcmp(value + 19, "+00:00")) || (strlen(value) == 20 && value[19] == 'Z'))) return 0;
    unsigned year, month, day, hour, minute, second;
    if (sscanf(value, "%4u-%2u-%2uT%2u:%2u:%2u", &year, &month, &day, &hour, &minute, &second) != 6
        || year < 2020 || year > 2286 || month < 1 || month > 12 || !day || hour > 23 || minute > 59 || second > 59) return 0;
    const unsigned lengths[] = {31,28,31,30,31,30,31,31,30,31,30,31};
    const bool leap = year % 4 == 0 && (year % 100 != 0 || year % 400 == 0);
    if (day > lengths[month - 1] + (month == 2 && leap ? 1u : 0u)) return 0;
    char normalized[20]; snprintf(normalized, sizeof(normalized), "%04u-%02u-%02uT%02u:%02u:%02u", year, month, day, hour, minute, second);
    if (memcmp(normalized, value, 19)) return 0;
    // Gregorian civil date -> days since Unix epoch, independent of process timezone.
    year -= month <= 2;
    const unsigned era = year / 400, yoe = year - era * 400;
    const unsigned doy = (153 * (month > 2 ? month - 3 : month + 9) + 2) / 5 + day - 1;
    const int64_t days = static_cast<int64_t>(era) * 146097 + yoe * 365 + yoe / 4 - yoe / 100 + doy - 719468;
    return static_cast<uint64_t>(days * 86400 + hour * 3600 + minute * 60 + second) * 1000;
}

DeadlineClient *activeClient = nullptr;
Reply send(const char *input, size_t inputLength, const char *path, uint64_t acquiredMs) {
    Reply reply;
    const uint64_t began = monotonicMs();
    const bool control = !strcmp(path, "/api/iot/v1/status");
    const char *operation = control ? "status" : !strcmp(path, "/api/iot/v1/preview") ? "preview" : "scan";
    TransportMetrics early;
    if (!clockReady() || WiFi.status() != WL_CONNECTED) {
        early.category = !synchronized.load() ? "clock_unsynchronized" : !clockReady() ? "clock_stale" : "wifi_disconnected";
        diagnose(operation, early, began, 0, 0); return reply;
    }
    if (!control && (began < acquiredMs || began - acquiredMs >= transport::captureLifetimeMs)) {
        early.category = "stale_capture_dropped"; diagnose(operation, early, began, 0, 0); return reply;
    }
    const size_t limit = control ? 1024 : !strcmp(path, "/api/iot/v1/preview") ? 45056 : 1024;
    if (!input || inputLength > limit || !uuid(IOT_DEVICE_ID) || !hex(IOT_KEY_HEX, 64) || !IOT_ROOT_CA[0]) {
        early.category = "local_configuration_or_body_invalid"; diagnose(operation, early, began, 0, 0); return reply;
    }
    static DeadlineClient client;
    static HTTPClient http;
    activeClient = &client;
    client.metrics = {}; client.tlsCode = 0; client.allowConnect = true;
    client.metrics.heapBefore = heap_caps_get_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT);
    client.metrics.largestBefore = heap_caps_get_largest_free_block(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT);
    client.deadline = control ? began + transport::controlDeadlineMs : acquiredMs + transport::captureLifetimeMs;
    client.previewTransfer = !strcmp(path, "/api/iot/v1/preview");
    client.setCACert(IOT_ROOT_CA);
    client.setHandshakeTimeout(transport::handshakeTimeoutMs / 1000);
    client.setTimeout(transport::readTimeoutMs);
    const auto finish = [&]() {
        lastTls = client.tlsCode;
        lastDurationMs = monotonicMs() - began;
        lastCategory = client.metrics.category;
        diagnose(operation, client.metrics, began, reply.http, client.tlsCode);
        return reply;
    };
    const bool connected = client.connected();
    client.metrics.reused = connected;
    if (!connected) {
        if (!transport::memoryAvailable(heap_caps_get_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT),
                heap_caps_get_largest_free_block(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT))) {
            client.metrics.category = "internal_heap_pressure"; return finish();
        }
        client.stop();
        if (!client.connect(IOT_HOST, IOT_PORT, transport::tcpTimeoutMs)) { reply.http = -1; return finish(); }
    }
    client.allowConnect = false; // Reconnect on the next request, never after capturing/signing this body.
    // Cold TLS may exceed the capture lifetime. Never change scan/frame timestamps;
    // a heartbeat is captured *after* TLS and signed over the resulting exact body.
    char statusJson[1025];
    if (control) {
        QrPerformance performance;
        portENTER_CRITICAL(&performanceMux);
        performance = qrPerformance;
        portEXIT_CRITICAL(&performanceMux);
        const int length = snprintf(statusJson, sizeof(statusJson),
            "{\"boot_id\":\"%s\",\"captured_at_ms\":%llu,\"camera_state\":\"%s\",\"telemetry\":{"
            "\"firmware\":\"sb-iot-16.3\",\"uptime_ms\":%llu,\"rssi\":%d,\"heap\":%lu,\"largest\":%lu,\"psram\":%lu,"
            "\"attempts\":%lu,\"decodes\":%lu,\"capture_fail\":%lu,\"avg_us\":%lu,\"p95_us\":%lu,"
            "\"capture_us\":%lu,\"convert_us\":%lu,\"gray_us\":%lu,\"detect_us\":%lu,\"decode_us\":%lu,"
            "\"preview_copy_us\":%lu,\"preview_sent\":%lu,\"http_ms\":%lu,\"http_code\":%d,\"error\":\"%s\",\"alloc_fail\":%lu,\"reset\":%d}}",
            boot, static_cast<unsigned long long>(utcMs()), transport::cameraHealth(cameraOk.load(), decoderOk.load()),
            static_cast<unsigned long long>(monotonicMs()), WiFi.RSSI(),
            static_cast<unsigned long>(heap_caps_get_free_size(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT)),
            static_cast<unsigned long>(heap_caps_get_largest_free_block(MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT)),
            static_cast<unsigned long>(heap_caps_get_free_size(MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT)),
            static_cast<unsigned long>(performance.attempts), static_cast<unsigned long>(performance.decodes), static_cast<unsigned long>(performance.captureFailures),
            static_cast<unsigned long>(performance.meanUs()), static_cast<unsigned long>(performance.p95Us()),
            static_cast<unsigned long>(performance.last.captureUs), static_cast<unsigned long>(performance.last.convertUs),
            static_cast<unsigned long>(performance.last.grayUs), static_cast<unsigned long>(performance.last.detectUs), static_cast<unsigned long>(performance.last.decodeUs),
            static_cast<unsigned long>(previewCopyUs.load()), static_cast<unsigned long>(previewSent.load()), static_cast<unsigned long>(lastDurationMs.load()),
            lastHttp.load(), lastCategory, static_cast<unsigned long>(allocationFailures.load()), static_cast<int>(esp_reset_reason()));
        if (length <= 0 || static_cast<size_t>(length) >= sizeof(statusJson)) {
            client.metrics.category = "local_configuration_or_body_invalid";
            return finish();
        }
        input = statusJson; inputLength = strlen(statusJson);
    } else if (monotonicMs() - acquiredMs >= transport::captureLifetimeMs) {
        client.metrics.category = "stale_capture_dropped"; return finish();
    }
    http.setConnectTimeout(transport::tcpTimeoutMs);
    http.setTimeout(transport::remaining(monotonicMs(), client.deadline, transport::readTimeoutMs));
    http.setReuse(true);
    http.useHTTP10(false);
    http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
    const String url = String("https://") + IOT_HOST + ":" + IOT_PORT + path;
    if (!http.begin(client, url)) { client.stop(); client.metrics.category = "http_initialization_failed"; return finish(); }
    uint8_t random[16]; esp_fill_random(random, sizeof(random));
    char nonce[33] = {};
    for (size_t i = 0; i < sizeof(random); ++i) snprintf(nonce + i * 2, 3, "%02x", random[i]);
    const uint64_t timestamp = utcMs() / 1000;
    const std::string hash = digestBytes(reinterpret_cast<const uint8_t *>(input), inputLength);
    const std::string signingInput = canonical(path, IOT_DEVICE_ID, IOT_KEY_VERSION, timestamp, nonce, hash.c_str());
    const std::string signature = digest(signingInput, IOT_KEY_HEX);
    if (signingInput.empty() || hash.size() != 64 || signature.size() != 64) {
        http.end(); client.stop(); client.metrics.category = "signing_failed"; return finish();
    }
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("Accept-Encoding", "identity");
    http.addHeader("X-SB-Device", IOT_DEVICE_ID);
    http.addHeader("X-SB-Key-Version", String(IOT_KEY_VERSION));
    http.addHeader("X-SB-Timestamp", String(static_cast<unsigned long long>(timestamp)));
    http.addHeader("X-SB-Nonce", nonce);
    http.addHeader("X-SB-Signature", signature.c_str());
    const uint64_t httpStarted = monotonicMs();
    reply.http = http.POST(reinterpret_cast<uint8_t *>(const_cast<char *>(input)), inputLength);
    client.metrics.httpMs = monotonicMs() - httpStarted;
    ResponseBuffer response;
    if (reply.http == 200 && (http.getSize() > 768 || http.writeToStream(&response) < 0)) {
        http.end(); client.stop(); client.metrics.category = "http_response_bounds_or_read_failed"; return finish();
    }
    http.end();
    if (reply.http != 200 || !clockReady() || monotonicMs() >= client.deadline || WiFi.status() != WL_CONNECTED) {
        client.stop();
        if (!strcmp(client.metrics.category, "ok")) {
            client.metrics.category = reply.http > 0 ? "application_http_error"
                : reply.http == HTTPC_ERROR_READ_TIMEOUT ? "http_read_timeout" : "http_connection_failed";
        }
        return finish();
    }
    const char *parseEnd = nullptr;
    cJSON *root = cJSON_ParseWithLengthOpts(response.bytes, response.length + 1, &parseEnd, true);
    if (!root) { client.stop(); client.metrics.category = "application_invalid_json"; return finish(); }
    const cJSON *ok = cJSON_GetObjectItemCaseSensitive(root, "ok");
    if (cJSON_IsObject(root) && cJSON_IsTrue(ok)) {
        if (!strcmp(path, "/api/iot/v1/status")) {
            previewWanted = false;
            const cJSON *show = cJSON_GetObjectItemCaseSensitive(root, "preview");
            const cJSON *id = cJSON_GetObjectItemCaseSensitive(root, "lease_id");
            const cJSON *until = cJSON_GetObjectItemCaseSensitive(root, "preview_expires_at");
            if (cJSON_IsTrue(show) && cJSON_IsString(id) && uuid(id->valuestring) && cJSON_IsString(until)) {
                const uint64_t end = expiry(until->valuestring);
                if (end > utcMs() && end - utcMs() <= 15000) {
                    memcpy(previewLease, id->valuestring, sizeof(previewLease));
                    previewExpires = end;
                    previewWanted = true;
                }
            }
            reply.wellFormed = cJSON_IsBool(show);
        } else if (!strcmp(path, "/api/iot/v1/scans")) {
            const cJSON *valid = cJSON_GetObjectItemCaseSensitive(root, "valid");
            const cJSON *id = cJSON_GetObjectItemCaseSensitive(root, "event_id");
            reply.wellFormed = cJSON_IsBool(valid) && cJSON_IsString(id) && uuid(id->valuestring);
            reply.valid = cJSON_IsTrue(valid);
        } else reply.wellFormed = true;
    }
    cJSON_Delete(root);
    if (!reply.wellFormed) { client.stop(); client.metrics.category = "application_invalid_response"; }
    return finish();
}

Reply send(const std::string &json, const char *path, uint64_t acquiredMs) {
    return send(json.data(), json.size(), path, acquiredMs);
}

void worker(void *) {
    Pending pending{};
    uint64_t nextControl = 0, nextFrame = 0, backoff = 0, finishedAt = 0;
    uint32_t frameSequence = 0;
    std::string lastQr;
    uint32_t lastPresentation = presentation.load();
    for (;;) {
        const bool received = xQueueReceive(observations, &pending, pdMS_TO_TICKS(100)) == pdTRUE;
        if (lastPresentation != presentation.load()) { lastQr.clear(); lastPresentation = presentation.load(); }
        if (WiFi.status() != WL_CONNECTED || !clockReady()) {
            previewWanted = false;
            if (activeClient) activeClient->stop();
            TransportMetrics info; info.category = !synchronized.load() ? "clock_unsynchronized" : !clockReady() ? "clock_stale" : "wifi_disconnected";
            lastCategory = info.category;
            diagnose("idle", info, monotonicMs(), 0, 0);
            publish(WiFi.status() == WL_CONNECTED ? Status::NeedTime : Status::Unknown);
            memset(&pending, 0, sizeof(pending));
            continue;
        }
        if (monotonicMs() < backoff) { memset(&pending, 0, sizeof(pending)); continue; }
        // Register a new boot before any queued ticket; control polling cannot starve
        // while QR codes remain in view. A stale observation is never retried.
        if (monotonicMs() >= nextControl) {
            const Reply reply = send("{}", "/api/iot/v1/status", monotonicMs());
            nextControl = monotonicMs() + 5000; lastHttp = reply.http;
            if (reply.http == 401 || reply.http == 403) { previewWanted = false; publish(Status::Revoked); backoff = UINT64_MAX; }
            else if (!reply.wellFormed || reply.http != 200) { previewWanted = false; publish(Status::Unknown); }
            else if (monotonicMs() - finishedAt >= 2000) publish(Status::Paired);
            if (backoff == UINT64_MAX) continue;
        }
        if (received && pending.generation == generation.load() && pending.presentation == presentation.load() && monotonicMs() >= pending.acquiredMs
            && monotonicMs() - pending.acquiredMs < 5000) {
            Observation event;
            memcpy(event.payload, pending.payload, pending.length);
            event.kind = Kind::Ticket; event.acquiredMs = pending.acquiredMs;
            event.capturedUtcMs = utcMs() - (monotonicMs() - pending.acquiredMs);
            event.session = pending.session; event.sequence = pending.sequence;
            // Latch stationary tickets; two empty captures re-arm the presentation.
            const bool sent = lastQr != event.payload;
            if (sent) {
                lastQr = event.payload;
                publish(Status::Processing);
                const Reply reply = send(automaticBody(event, boot), "/api/iot/v1/scans", event.acquiredMs);
                lastHttp = reply.http; finishedAt = monotonicMs();
                if (reply.http == 401 || reply.http == 403) { publish(Status::Revoked); backoff = UINT64_MAX; }
                else if (reply.http == 429) { publish(Status::RateLimited); backoff = monotonicMs() + 60000; }
                else if (reply.http == 200 && reply.wellFormed) publish(reply.valid ? Status::Valid : Status::Invalid);
                else { publish(Status::Unknown); backoff = monotonicMs() + 3000; lastQr.clear(); }
            }
            memset(&event, 0, sizeof(event)); memset(&pending, 0, sizeof(pending));
            if (sent) continue;
        }
        if (previewExpires.load() <= utcMs()) previewWanted = false;
        Frame frame{};
        if (xQueueReceive(frames, &frame, 0) == pdTRUE) {
            if (previewWanted && monotonicMs() >= nextFrame && monotonicMs() - frame.acquiredMs < 3000
                && uxQueueMessagesWaiting(observations) == 0) {
                char prefix[230];
                const int prefixLength = snprintf(prefix, sizeof(prefix), "{\"boot_id\":\"%s\",\"lease_id\":\"%s\",\"sequence\":%u,\"captured_at_ms\":%llu,\"image\":\"",
                    boot, previewLease, static_cast<unsigned>(++frameSequence), static_cast<unsigned long long>(utcMs() - (monotonicMs() - frame.acquiredMs)));
                const size_t capacity = prefixLength + 4 * ((frame.length + 2) / 3) + 3;
                // One PSRAM allocation, no full-size std::string/String copies in internal RAM.
                auto *json = static_cast<char *>(heap_caps_malloc(capacity, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
                if (json && prefixLength > 0 && static_cast<size_t>(prefixLength) < sizeof(prefix)) {
                    memcpy(json, prefix, prefixLength);
                    size_t length = 0;
                    if (mbedtls_base64_encode(reinterpret_cast<unsigned char *>(json + prefixLength), capacity - prefixLength,
                            &length, frame.bytes, frame.length) == 0) {
                        json[prefixLength + length] = '"'; json[prefixLength + length + 1] = '}'; json[prefixLength + length + 2] = 0;
                        const Reply reply = send(json, prefixLength + length + 2, "/api/iot/v1/preview", frame.acquiredMs);
                        if (reply.http != 200) previewWanted = false;
                        else ++previewSent;
                    }
                } else {
                    TransportMetrics info; info.category = "preview_psram_allocation_failed";
                    lastCategory = info.category;
                    diagnose("preview", info, monotonicMs(), 0, 0);
                }
                if (json) { memset(json, 0, capacity); free(json); }
                nextFrame = monotonicMs() + 1100;
            }
            memset(frame.bytes, 0, frame.length); free(frame.bytes);
        }
    }
}
} // namespace

const char *statusText() {
    switch (static_cast<Status>(currentStatus.load())) {
        case Status::Disabled: return "disabled";
        case Status::NeedTime: return "waiting_for_time_sync";
        case Status::NeedPair: return "ready_for_ticket";
        case Status::Paired: return "ready_for_ticket";
        case Status::Processing: return "processing";
        case Status::Valid: return "server_valid";
        case Status::Invalid: return "server_invalid";
        case Status::Revoked: return "authentication_or_authorization_failed";
        case Status::RateLimited: return "rate_limited_wait_60_seconds";
        default: return "connection_unavailable";
    }
}
int httpStatus() { return lastHttp.load(); }
int tlsStatus() { return lastTls.load(); }
bool enabled() { return configured; }
bool start(const char *bootId) {
    if (!configured || started || !hex(bootId, 32)) return started;
    heap_caps_register_failed_alloc_callback(allocationFailed);
    memcpy(boot, bootId, sizeof(boot));
    observations = xQueueCreate(1, sizeof(Pending));
    frames = xQueueCreate(1, sizeof(Frame));
    if (!observations || !frames) { publish(Status::Unknown); Serial.println("[IoT] communication queue allocation failed"); return false; }
    sntp_set_time_sync_notification_cb(synchronizedTime);
    sntp_set_sync_interval(300000);
    configTime(0, 0, "pool.ntp.org", "time.google.com");
    if (xTaskCreatePinnedToCore(worker, "iot-client", 16384, nullptr, 1, nullptr, 0) != pdPASS) {
        vQueueDelete(observations); observations = nullptr; publish(Status::Unknown);
        Serial.println("[IoT] communication worker allocation failed"); return false;
    }
    started = true;
    previousWifi = true;
    publish(Status::NeedTime);
#if SB_EXTERNAL_LEDS
    for (int pin : {13, 14, 2}) { pinMode(pin, OUTPUT); digitalWrite(pin, LOW); }
#endif
    return true;
}
void observe(const uint8_t *payload, size_t length, uint64_t acquiredMs, uint32_t scannerSession, uint32_t sequence) {
    if (!observations || length > 69 || classify(payload, length) != Kind::Ticket || !clockReady() || WiFi.status() != WL_CONNECTED) return;
    Pending pending{};
    memcpy(pending.payload, payload, length); pending.length = length; pending.acquiredMs = acquiredMs;
    pending.session = scannerSession; pending.sequence = sequence; pending.generation = generation.load();
    pending.presentation = presentation.load();
    // A single latest observation is enough; old queued events are never retried.
    xQueueOverwrite(observations, &pending);
    memset(&pending, 0, sizeof(pending));
}
void recordQrAttempt(const QrAttemptTiming &sample) {
    portENTER_CRITICAL(&performanceMux);
    qrPerformance.record(sample);
    portEXIT_CRITICAL(&performanceMux);
}
void sceneEmpty(bool empty) {
    // Called only by the camera task; the transport worker sees an atomic epoch.
    static unsigned missing = 0;
    static bool rearmed = false;
    if (!empty) { missing = 0; rearmed = false; return; }
    if (missing < 2) ++missing;
    if (missing == 2 && !rearmed) { presentation++; rearmed = true; }
}
void cameraState(bool ready, bool decoderReady) { cameraOk = ready; decoderOk = decoderReady; }
void offerFrame(const uint8_t *jpeg, size_t length, uint64_t acquiredMs) {
    static uint64_t lastOffered = 0;
    if (!frames || !previewWanted || !clockReady() || previewExpires.load() <= utcMs() || length > 32768
        || monotonicMs() - lastOffered < 1100 || length < 4 || jpeg[0] != 0xff || jpeg[1] != 0xd8) return;
    // Copy only; no TLS/network, waiting, extra capture, or decoding on the camera task.
    auto *copy = static_cast<uint8_t *>(heap_caps_malloc(length, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
    if (!copy) return;
    const uint64_t copyBegan = esp_timer_get_time();
    memcpy(copy, jpeg, length);
    previewCopyUs = esp_timer_get_time() - copyBegan;
    Frame previous{};
    if (xQueueReceive(frames, &previous, 0) == pdTRUE) { memset(previous.bytes, 0, previous.length); free(previous.bytes); }
    Frame frame{copy, length, acquiredMs};
    if (xQueueSend(frames, &frame, 0) != pdTRUE) { memset(copy, 0, length); free(copy); }
    lastOffered = monotonicMs();
}
void invalidate() {
    previewWanted = false;
    generation++;
    if (observations) xQueueReset(observations);
}
void tick() {
    if (!started) return;
    const bool connected = WiFi.status() == WL_CONNECTED;
    if (!connected && previousWifi) { invalidate(); publish(Status::Unknown); }
    if (!clockReady()) publish(Status::NeedTime);
    previousWifi = connected;
    static int previous = -1;
    static uint32_t changedAt = 0;
    const int state = currentStatus.load();
    if (state != previous) { previous = state; changedAt = millis(); Serial.printf("[IoT] state=%s http=%d tls=%d\n", statusText(), httpStatus(), tlsStatus()); }
#if SB_EXTERNAL_LEDS
    Lights output = lights(static_cast<Status>(state), static_cast<uint32_t>(millis() - changedAt));
    if ((state == static_cast<int>(Status::Valid) || state == static_cast<int>(Status::Invalid)) && millis() - changedAt >= 2000) output = {};
    digitalWrite(13, output.green); digitalWrite(14, output.red); digitalWrite(2, output.yellow);
#else
    (void)changedAt;
#endif
}
} // namespace deviceiot
