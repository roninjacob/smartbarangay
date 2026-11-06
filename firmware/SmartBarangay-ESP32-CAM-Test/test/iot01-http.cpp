// Host shim executes the actual status serializer and route registration extracted
// from main.cpp by run_iot01.py. No board/network or Arduino hardware is simulated.
#include "iot_readiness.h"
#include <atomic>
#include <cassert>
#include <cstdio>
#include <sstream>
#include <string>
#include <vector>
#include <iostream>
class String {
    std::string value;
public:
    String(const char *s = "") : value(s) {}
    String(const std::string &s) : value(s) {}
    void reserve(size_t n) { value.reserve(n); }
    size_t length() const { return value.size(); }
    const char *c_str() const { return value.c_str(); }
    char operator[](size_t n) const { return value[n]; }
    template<class T> String operator+(const T &other) const { std::ostringstream out; out << value << other; return String(out.str()); }
    template<class T> String &operator+=(const T &other) { *this = *this + other; return *this; }
    friend std::ostream &operator<<(std::ostream &out, const String &s) { return out << s.value; }
};
using esp_err_t = int;
using httpd_handle_t = void *;
struct httpd_req_t {};
constexpr int ESP_OK = 0, HTTP_GET = 0, HTTP_POST = 1, portMAX_DELAY = 0;
struct httpd_config_t { int server_port=80, max_uri_handlers=0, max_open_sockets=0, ctrl_port=1, stack_size=0, recv_wait_timeout=0, send_wait_timeout=0; bool lru_purge_enable=false; };
struct httpd_uri_t { const char *uri=nullptr; int method=0; esp_err_t (*handler)(httpd_req_t *)=nullptr; };
httpd_config_t HTTPD_DEFAULT_CONFIG() { return {}; }
httpd_handle_t pageServer=nullptr, streamServer=nullptr;
std::vector<std::string> routes;
std::vector<int> ports;
int failRoute = -1, registration = 0, stopped = 0;
esp_err_t httpd_start(httpd_handle_t *handle, const httpd_config_t *config) { *handle=reinterpret_cast<void *>(1); ports.push_back(config->server_port); return ESP_OK; }
esp_err_t httpd_register_uri_handler(httpd_handle_t, httpd_uri_t *route) { routes.emplace_back(route->uri); return ++registration == failRoute ? 1 : ESP_OK; }
void httpd_stop(httpd_handle_t) { ++stopped; }
const char *esp_err_to_name(int) { return "synthetic-error"; }
struct SerialMock { template<class... T> void printf(const char *, T...) {} void println(const char *) {} } Serial;
int pageHandler(httpd_req_t *) { return 0; }
int modeHandler(httpd_req_t *) { return 0; }
int qrFrameHandler(httpd_req_t *) { return 0; }
int streamHandler(httpd_req_t *) { return 0; }
int qrResultMutex=0;
void xSemaphoreTake(int, int) {}
void xSemaphoreGive(int) {}
int64_t clockUs=100000;
int64_t esp_timer_get_time() { return clockUs; }
std::atomic<bool> modeSwitching{false},cameraReady{true},qrActive{true},qrRequested{true},qrProcessing{false};
std::atomic<uint32_t> snapshotSequence{0},qrCurrentIntervalMs{1000};
uint32_t qrAttempts=1,qrDecodes=1,qrEvents=1;
char qrMessage[160]="Decoded content withheld; NOT server verified.";
char bootId[33]="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
readiness::ScanEvents scanEvents;
std::string responseBody;
int httpd_resp_set_type(httpd_req_t *, const char *) { return 0; }
int httpd_resp_set_hdr(httpd_req_t *, const char *, const char *) { return 0; }
int httpd_resp_send(httpd_req_t *, const char *body, size_t size) { responseBody.assign(body,size); return 0; }

#include "http-functions.inc"

int main() {
    const std::string credential="SBQ1:550e8400-e29b-41d4-a716-446655440000";
    scanEvents.beginSession();
    assert(scanEvents.observe(reinterpret_cast<const uint8_t *>(credential.data()),credential.size(),100,1));
    httpd_req_t request;
    qrStatusHandler(&request);
    assert(responseBody.find(credential)==std::string::npos);
    assert(responseBody.find("550e8400")==std::string::npos);
    assert(responseBody.find("\"payload\"")==std::string::npos);
    assert(responseBody.find("\"event_state\":\"fresh\"")!=std::string::npos);
    assert(responseBody.find("\"format_match\":true")!=std::string::npos);
    const auto original=responseBody;
    qrStatusHandler(&request); assert(responseBody==original);
    clockUs=5100000; qrStatusHandler(&request);
    assert(responseBody.find("\"event_state\":\"expired\"")!=std::string::npos);
    scanEvents.invalidate(); qrStatusHandler(&request);
    assert(responseBody.find("\"event_state\":\"absent\"")!=std::string::npos);
    scanEvents.beginSession();
    assert(scanEvents.observe(reinterpret_cast<const uint8_t *>(credential.data()),credential.size(),5100,2));
    qrStatusHandler(&request);
    assert(responseBody.find("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:2:2")!=std::string::npos);
    std::memset(bootId,'b',32); bootId[32]=0; scanEvents=readiness::ScanEvents();scanEvents.beginSession();
    assert(scanEvents.observe(reinterpret_cast<const uint8_t *>(credential.data()),credential.size(),5100,1));
    qrStatusHandler(&request);
    assert(responseBody.find("bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:1:1")!=std::string::npos);
    qrRequested=false; qrStatusHandler(&request);
    assert(responseBody.find("\"event_state\":\"fresh\"")==std::string::npos);
    assert(startServers());
    const std::vector<std::string> safe={"/","/mode","/qr/status"};
    const std::vector<std::string> development={"/","/mode","/qr/status","/qr/frame","/stream"};
    assert(routes==(readiness::cameraDiagnostics?development:safe));
    assert(ports==(readiness::cameraDiagnostics?std::vector<int>{80,81}:std::vector<int>{80}));
    routes.clear();ports.clear();registration=0;failRoute=2;stopped=0;
    assert(!startServers()); assert(pageServer==nullptr && streamServer==nullptr && stopped>=1);
    std::cout << "PASS: actual HTTP serializer/registration; no raw credential; TTL, mode/reboot identity; safe/dev routes; failure cleanup\n";
}
