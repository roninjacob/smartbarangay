#include "iot_client.h"
#include "iot_protocol.h"
#include "iot_crypto.h"
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
struct Pending { uint8_t payload[70]; size_t length; uint64_t acquiredMs; uint32_t session, sequence, generation; };
QueueHandle_t observations = nullptr;
Session session;
char boot[33] = {};
std::atomic<uint32_t> generation{0}, syncedAt{0};
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

class DeadlineClient : public WiFiClientSecure {
public:
    uint64_t deadline = 0;
    bool alive() { if (monotonicMs() >= deadline) { stop(); return false; } return true; }
    int available() override { return alive() ? WiFiClientSecure::available() : 0; }
    uint8_t connected() override { return alive() ? WiFiClientSecure::connected() : 0; }
    int read() override { return alive() ? WiFiClientSecure::read() : -1; }
    int read(uint8_t *data, size_t size) override { return alive() ? WiFiClientSecure::read(data, size) : -1; }
    size_t write(const uint8_t *data, size_t size) override { return alive() ? WiFiClientSecure::write(data, size) : 0; }
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

Reply send(const Observation &event) {
    Reply reply;
    if (!clockReady() || WiFi.status() != WL_CONNECTED || monotonicMs() - event.acquiredMs >= 5000) return reply;
    const std::string json = body(event, boot, session.pairingId);
    const char *path = event.kind == Kind::Pair ? "/api/iot/v1/pair" : "/api/iot/v1/scans";
    if (json.empty() || json.size() > 1024 || !uuid(IOT_DEVICE_ID) || !hex(IOT_KEY_HEX, 64) || !IOT_ROOT_CA[0]) return reply;
    DeadlineClient client;
    client.deadline = event.acquiredMs + 5000;
    client.setCACert(IOT_ROOT_CA);
    client.setHandshakeTimeout(3);
    client.setTimeout(1500);
    HTTPClient http;
    http.setConnectTimeout(2000);
    http.setTimeout(1500);
    http.setReuse(false);
    http.useHTTP10(true);
    http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
    const String url = String("https://") + IOT_HOST + ":" + IOT_PORT + path;
    if (!http.begin(client, url)) return reply;
    uint8_t random[16]; esp_fill_random(random, sizeof(random));
    char nonce[33] = {};
    for (size_t i = 0; i < sizeof(random); ++i) snprintf(nonce + i * 2, 3, "%02x", random[i]);
    const uint64_t timestamp = utcMs() / 1000;
    const std::string hash = digest(json);
    const std::string signingInput = canonical(path, IOT_DEVICE_ID, IOT_KEY_VERSION, timestamp, nonce, hash.c_str());
    if (signingInput.empty()) { http.end(); return reply; }
    const std::string signature = digest(signingInput, IOT_KEY_HEX);
    if (hash.size() != 64 || signature.size() != 64) { http.end(); return reply; }
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("Accept-Encoding", "identity");
    http.addHeader("X-SB-Device", IOT_DEVICE_ID);
    http.addHeader("X-SB-Key-Version", String(IOT_KEY_VERSION));
    http.addHeader("X-SB-Timestamp", String(static_cast<unsigned long long>(timestamp)));
    http.addHeader("X-SB-Nonce", nonce);
    http.addHeader("X-SB-Signature", signature.c_str());
    reply.http = http.POST(String(json.c_str()));
    char withheld[96] = {};
    lastTls = reply.http <= 0 ? client.lastError(withheld, sizeof(withheld)) : 0;
    memset(withheld, 0, sizeof(withheld));
    ResponseBuffer response;
    if (reply.http == 200 && (http.getSize() > 768 || http.writeToStream(&response) < 0)) { http.end(); return reply; }
    http.end();
    if (reply.http != 200 || !clockReady() || monotonicMs() - event.acquiredMs >= 5000 || WiFi.status() != WL_CONNECTED) return reply;
    const char *parseEnd = nullptr;
    cJSON *root = cJSON_ParseWithLengthOpts(response.bytes, response.length + 1, &parseEnd, true);
    if (!root) return reply;
    const cJSON *ok = cJSON_GetObjectItemCaseSensitive(root, "ok");
    if (cJSON_IsObject(root) && cJSON_GetArraySize(root) == 3 && cJSON_IsTrue(ok)) {
        if (event.kind == Kind::Pair) {
            const cJSON *id = cJSON_GetObjectItemCaseSensitive(root, "pairing_id");
            const cJSON *until = cJSON_GetObjectItemCaseSensitive(root, "expires_at");
            if (cJSON_IsString(id) && uuid(id->valuestring) && cJSON_IsString(until)) {
                memcpy(reply.pairingId, id->valuestring, 37);
                reply.expiresUtcMs = expiry(until->valuestring);
                reply.wellFormed = reply.expiresUtcMs != 0;
            }
        } else {
            const cJSON *valid = cJSON_GetObjectItemCaseSensitive(root, "valid");
            const cJSON *id = cJSON_GetObjectItemCaseSensitive(root, "event_id");
            reply.wellFormed = cJSON_IsBool(valid) && cJSON_IsString(id) && uuid(id->valuestring);
            reply.valid = cJSON_IsTrue(valid);
        }
    }
    cJSON_Delete(root);
    return reply;
}

void worker(void *) {
    Pending pending{};
    uint32_t observedGeneration = generation.load();
    uint64_t finishedAt = 0;
    for (;;) {
        const bool received = xQueueReceive(observations, &pending, pdMS_TO_TICKS(250)) == pdTRUE;
        if (observedGeneration != generation.load()) {
            observedGeneration = generation.load();
            session.clear(Status::NeedPair);
        }
        if (WiFi.status() != WL_CONNECTED) session.clear(Status::Unknown);
        else if (!clockReady()) session.clear(Status::NeedTime);
        else if (session.status == Status::NeedTime) session.clear(Status::NeedPair);
        if (session.pairingId[0] && utcMs() >= session.expiresUtcMs) session.clear(Status::NeedPair);
        if ((session.status == Status::Valid || session.status == Status::Invalid) && monotonicMs() - finishedAt >= 2000)
            session.status = Status::Paired;
        publish(session.status);
        if (!received) continue;
        if (pending.generation != generation.load()) continue;
        Observation event;
        const uint64_t now = monotonicMs(), utc = utcMs();
        const uint64_t captured = now >= pending.acquiredMs && utc >= now - pending.acquiredMs ? utc - (now - pending.acquiredMs) : 0;
        if (!session.offer(pending.payload, pending.length, pending.acquiredMs, captured, pending.session, pending.sequence,
                           now, utc, clockReady(), event)) { publish(session.status); memset(&pending, 0, sizeof(pending)); continue; }
        publish(Status::Processing);
        const Reply reply = send(event);
        lastHttp = reply.http;
        if (pending.generation != generation.load()) session.clear(Status::NeedPair);
        else session.finish(event, reply, monotonicMs(), utcMs());
        finishedAt = monotonicMs();
        publish(session.status);
        memset(&event, 0, sizeof(event));
        memset(&pending, 0, sizeof(pending));
    }
}
} // namespace

const char *statusText() {
    switch (static_cast<Status>(currentStatus.load())) {
        case Status::Disabled: return "disabled";
        case Status::NeedTime: return "waiting_for_time_sync";
        case Status::NeedPair: return "scan_pairing_qr";
        case Status::Paired: return "paired_waiting_for_ticket";
        case Status::Processing: return "processing";
        case Status::Valid: return "server_valid";
        case Status::Invalid: return "server_invalid";
        case Status::Revoked: return "authentication_or_authorization_failed";
        case Status::RateLimited: return "rate_limited_wait_60_seconds";
        default: return "unknown_scan_new_pairing_qr";
    }
}
int httpStatus() { return lastHttp.load(); }
int tlsStatus() { return lastTls.load(); }
bool enabled() { return configured; }
bool start(const char *bootId) {
    if (!configured || started || !hex(bootId, 32)) return started;
    memcpy(boot, bootId, sizeof(boot));
    observations = xQueueCreate(1, sizeof(Pending));
    if (!observations) { publish(Status::Unknown); Serial.println("[IoT] communication queue allocation failed"); return false; }
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
    if (!observations || length > 69 || classify(payload, length) == Kind::Other || !clockReady() || WiFi.status() != WL_CONNECTED) return;
    Pending pending{};
    memcpy(pending.payload, payload, length); pending.length = length; pending.acquiredMs = acquiredMs;
    pending.session = scannerSession; pending.sequence = sequence; pending.generation = generation.load();
    // A single latest observation is enough; old queued events are never retried.
    xQueueOverwrite(observations, &pending);
    memset(&pending, 0, sizeof(pending));
}
void invalidate() {
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
