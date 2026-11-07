#include <Arduino.h>
#include <WiFi.h>
#include <cstring>
#include <cerrno>
#include <atomic>
#include "img_converters.h"
#include "esp_heap_caps.h"
#include "quirc.h"
#include "esp_camera.h"
#include "esp_err.h"
#include "esp_http_server.h"
#include "esp_system.h"
#include "esp_timer.h"
#include "lwip/sockets.h"
#include "iot_readiness.h"

#if __has_include("smartbarangay_wifi_private.h")
#include "smartbarangay_wifi_private.h"
#else
#error "Configure the external private Wi-Fi header. See README Private Wi-Fi setup."
#endif

namespace {
constexpr uint32_t CONNECT_TIMEOUT_MS = 20000;
constexpr uint32_t RETRY_INTERVAL_MS = 30000;
constexpr uint32_t DIAGNOSTIC_INTERVAL_MS = 5000;
constexpr int64_t FRAME_SEND_BUDGET_US = 1500000;
constexpr uint32_t FRAME_INTERVAL_MS = 125;
constexpr uint32_t QR_SCAN_INTERVAL_MS = 1000;
constexpr bool QR_ENABLE_FAST_EXPERIMENT = false;
constexpr bool QR_BENCHMARK_LOG_EACH_SCAN = false;
constexpr uint32_t QR_EXPERIMENT_INTERVAL_MS = 500;
constexpr uint32_t QR_FAST_HEADROOM_US = 350000;
constexpr uint32_t QR_FAST_REQUIRED_SAMPLES = 20;
constexpr uint32_t QR_SNAPSHOT_INTERVAL_MS = 1000;
constexpr uint32_t QR_MIN_YIELD_MS = 20;
std::atomic<uint32_t> qrCurrentIntervalMs{QR_SCAN_INTERVAL_MS};
std::atomic<uint32_t> qrHeadroomSamples{0};
std::atomic<uint32_t> qrSnapshotsSkipped{0};
httpd_handle_t pageServer = nullptr;
httpd_handle_t streamServer = nullptr;
std::atomic<bool> cameraReady{false};
std::atomic<bool> qrRequested{false};
std::atomic<bool> qrActive{false};
std::atomic<bool> modeSwitching{false};
std::atomic<bool> qrProcessing{false};
SemaphoreHandle_t cameraMutex = nullptr;
SemaphoreHandle_t qrResultMutex = nullptr;
SemaphoreHandle_t snapshotMutex = nullptr;
TaskHandle_t qrTask = nullptr;
char qrMessage[160] = "Preview mode. Select QR mode for high-resolution scanning.";
readiness::ScanEvents scanEvents;
char bootId[33] = {}; // Generated once after Wi-Fi starts; not a device credential.
uint32_t qrAttempts = 0;
uint32_t qrDecodes = 0;
uint32_t qrEvents = 0;
uint32_t qrCompletedAt = 0, qrSession = 0;
std::atomic<uint32_t> snapshotSequence{0};
uint8_t *qrSnapshot = nullptr;
size_t qrSnapshotSize = 0;
uint32_t snapshotAcquiredMs = 0; // Device uptime when the cached frame was acquired.
struct QrTimings {
    uint32_t lockUs = 0, captureUs = 0, cacheWaitUs = 0, copyUs = 0;
    uint32_t convertUs = 0, grayUs = 0, detectUs = 0, decodeUs = 0;
    uint32_t totalUs = 0, periodMs = 0;
    uint32_t readyUs = 0, completedMs = 0, completionPeriodMs = 0, attempt = 0;
    bool captured = false, processed = false, decoded = false;
};
QrTimings qrLastTiming;
uint32_t qrTimingSamples = 0;
int64_t snapshotDeadline = 0;
bool snapshotTimedOut = false;
bool credentialsReady = false;
bool wasConnected = false;
uint32_t lastRetry = 0;
bool connecting = false;
uint32_t connectStarted = 0;
uint32_t lastDiagnostic = 0;
// Only the single stream-server task reads/writes this deadline.
int64_t sendDeadline = 0;
bool sendTimedOut = false;

struct StreamDiagnostics {
    uint32_t frames = 0;
    uint32_t windowFrames = 0;
    uint32_t captures = 0;
    uint32_t transmissions = 0;
    uint64_t captureUs = 0;
    uint64_t transmitUs = 0;
    uint32_t maxTransmitUs = 0;
    uint32_t captureFailures = 0;
    uint32_t disconnects = 0;
    uint32_t sendTimeouts = 0;
    bool active = false;
};
StreamDiagnostics diagnostics;
portMUX_TYPE diagnosticsMux = portMUX_INITIALIZER_UNLOCKED;

void reportDiagnostics() {
    const uint32_t now = millis();
    const uint32_t elapsed = now - lastDiagnostic;
    if (elapsed < DIAGNOSTIC_INTERVAL_MS) {
        return;
    }
    lastDiagnostic = now;
    portENTER_CRITICAL(&diagnosticsMux);
    const StreamDiagnostics snapshot = diagnostics;
    diagnostics.windowFrames = 0;
    diagnostics.captures = 0;
    diagnostics.transmissions = 0;
    diagnostics.captureUs = 0;
    diagnostics.transmitUs = 0;
    diagnostics.maxTransmitUs = 0;
    portEXIT_CRITICAL(&diagnosticsMux);
    const bool wifi = WiFi.status() == WL_CONNECTED;
    Serial.printf("[DIAG] stream=%d frames=%lu fps=%.2f capture_avg_ms=%.2f tx_avg_ms=%.2f tx_max_ms=%.2f heap=%u psram=%u wifi=%d rssi=%d capture_fail=%lu disconnects=%lu send_timeouts=%lu\n",
        snapshot.active, static_cast<unsigned long>(snapshot.frames),
        snapshot.windowFrames * 1000.0 / elapsed,
        snapshot.captures ? snapshot.captureUs / (1000.0 * snapshot.captures) : 0.0,
        snapshot.transmissions ? snapshot.transmitUs / (1000.0 * snapshot.transmissions) : 0.0,
        snapshot.maxTransmitUs / 1000.0,
        static_cast<unsigned int>(ESP.getFreeHeap()), static_cast<unsigned int>(ESP.getFreePsram()),
        wifi, wifi ? WiFi.RSSI() : 0,
        static_cast<unsigned long>(snapshot.captureFailures),
        static_cast<unsigned long>(snapshot.disconnects),
        static_cast<unsigned long>(snapshot.sendTimeouts));
    if (qrResultMutex && xSemaphoreTake(qrResultMutex, 0) == pdTRUE) {
        const uint32_t attempts = qrAttempts, decodes = qrDecodes;
        const QrTimings timing = qrLastTiming;
        const uint32_t samples = qrTimingSamples;
        xSemaphoreGive(qrResultMutex);
        Serial.printf("[QRDIAG] active=%d requested=%d attempts=%lu decodes=%lu worker_stack_free=%u\n",
            qrActive.load(), qrRequested.load(), static_cast<unsigned long>(attempts),
            static_cast<unsigned long>(decodes), qrTask ? static_cast<unsigned int>(uxTaskGetStackHighWaterMark(qrTask)) : 0);
        if (samples) {
            Serial.printf("[QRTIME] samples=%lu last_lock_ms=%.1f capture_ms=%.1f cache_wait_ms=%.1f copy_ms=%.1f convert_ms=%.1f gray_ms=%.1f detect_ms=%.1f decode_ms=%.1f total_ms=%.1f start_period_ms=%lu\n",
                static_cast<unsigned long>(samples), timing.lockUs / 1000.0, timing.captureUs / 1000.0,
                timing.cacheWaitUs / 1000.0, timing.copyUs / 1000.0, timing.convertUs / 1000.0,
                timing.grayUs / 1000.0, timing.detectUs / 1000.0, timing.decodeUs / 1000.0,
                timing.totalUs / 1000.0, static_cast<unsigned long>(timing.periodMs));
            Serial.printf("[QRPERF] interval_ms=%lu headroom_samples=%lu completed_period_ms=%lu ready_ms=%.1f decoded=%d snapshots_skipped=%lu\n",
                static_cast<unsigned long>(qrCurrentIntervalMs.load()), static_cast<unsigned long>(qrHeadroomSamples.load()),
                static_cast<unsigned long>(timing.completionPeriodMs), timing.readyUs / 1000.0, timing.decoded,
                static_cast<unsigned long>(qrSnapshotsSkipped.load()));
        }
    }
}

// A socket send timeout alone can reset on every partial write. This override
// shares one absolute deadline across all HTTP chunks for the current frame.
int boundedSend(httpd_handle_t server, int socket, const char *buffer, size_t length, int flags) {
    const bool snapshot = server == pageServer;
    while (WiFi.status() == WL_CONNECTED) {
        if (esp_timer_get_time() >= (snapshot ? snapshotDeadline : sendDeadline)) {
            if (snapshot) { snapshotTimedOut = true; } else { sendTimedOut = true; }
            return HTTPD_SOCK_ERR_TIMEOUT;
        }
        const size_t piece = length > 1460 ? 1460 : length;
        const int sent = send(socket, buffer, piece, flags | MSG_DONTWAIT);
        if (sent > 0) {
            return sent;
        }
        if (sent == 0 || (errno != EAGAIN && errno != EWOULDBLOCK && errno != EINTR)) {
            return HTTPD_SOCK_ERR_FAIL;
        }
        delay(2);
    }
    return HTTPD_SOCK_ERR_FAIL;
}

// Restore ordinary page-server writes after a bounded snapshot response. The
// server's existing SO_SNDTIMEO still applies to these small page/status replies.
int pageSend(httpd_handle_t, int socket, const char *buffer, size_t length, int flags) {
    const int sent = send(socket, buffer, length, flags);
    if (sent < 0) {
        return errno == EAGAIN || errno == EWOULDBLOCK ? HTTPD_SOCK_ERR_TIMEOUT : HTTPD_SOCK_ERR_FAIL;
    }
    return sent == 0 && length ? HTTPD_SOCK_ERR_FAIL : sent;
}

const char TEST_PAGE[] PROGMEM = R"HTML(<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ESP32-CAM Hardware Test</title>
<style>body{font:16px system-ui,sans-serif;margin:24px auto;padding:0 16px;max-width:800px;background:#14212b;color:#edf4f7}img{display:block;width:100%;height:auto;background:#000;margin:16px 0}#video{max-width:320px}#snapshot{max-width:640px}img[hidden]{display:none}button{padding:12px 18px;margin:4px;font:inherit}p{line-height:1.6}a{color:#8dd9ff}</style></head>
<body><h1>ESP32-CAM Hardware Test</h1>
<p>AI Thinker / OV2640 &middot; JPEG camera test over local Wi-Fi.</p>
<button id="start" type="button" hidden>Start / reconnect</button>
<button id="stop" type="button" hidden>Stop stream</button>
<button id="qr" type="button">Enter QR test mode</button>
<button id="preview" type="button">Return to preview</button>
<p id="status" role="status">Loading safe diagnostics...</p>
<img id="video" alt="Live camera video" hidden>
<img id="snapshot" alt="Latest VGA QR test capture" hidden>
<p id="snapshotStatus" role="status"></p>
<p id="snapshotFreshness">QR images are periodic snapshots, not live video.</p>
<p id="scanState" role="status"></p>
<p id="qrSuccess" role="status" style="color:#a8f0b5;font-weight:bold"></p>
<h2>Scan event readiness</h2>
<pre id="qrResult" style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:1.4rem;padding:16px;border:2px solid #8dd9ff" aria-live="polite">No QR decoded yet.</pre>
<pre id="qrDetails" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre>
<p>QR content is never shown or logged. Format match is not ticket verification. Nothing is submitted to Laravel. Camera diagnostics are disabled by default; enable them only for dummy codes on a trusted LAN.</p>
<p>Use one viewer on a trusted LAN. In the default build, missing video is intentional. Port 81 is available only in the explicitly enabled dummy-only diagnostics build.</p>
<a id="direct" hidden>Open direct MJPEG stream</a>
<script>
const video=document.getElementById('video'),status=document.getElementById('status');
const url='http://'+location.hostname+':81/stream';
document.getElementById('direct').href=url;
let wanted=false,retry=null;
let qrMode=false,modeBusy=false,closed=false,previewAfterSwitch=true,cameraDiagnostics=false;
let imageGeneration=0,snapshotUrl=null,snapshotAbort=null;
let shownSequence=null,receivedAt=0,serverAgeMs=0,receivedClock='',lastEvent='';
let imageInFlight=false,lastImageRequest=-Infinity,modeGeneration=0;
let currentEvent='',eventDeadline=0;
const QR_STATUS_POLL_MS=500,PREVIEW_STATUS_POLL_MS=2000,IMAGE_REFRESH_MS=2000;
const pendingRequests=new Set();
const snapshot=document.getElementById('snapshot'),result=document.getElementById('qrResult');
const details=document.getElementById('qrDetails'),imageStatus=document.getElementById('snapshotStatus');
const freshness=document.getElementById('snapshotFreshness'),scanState=document.getElementById('scanState'),success=document.getElementById('qrSuccess');
function showFreshness(){
 if(!snapshotUrl){freshness.textContent='Waiting for a QR snapshot; this is not live video.';return;}
 const age=serverAgeMs+performance.now()-receivedAt;
 freshness.textContent=(age>5000?'STALE - ':'Snapshot - ')+'frame #'+shownSequence+', acquired approx '+(age/1000).toFixed(1)+'s ago; last image update '+receivedClock+'. Not live video.';
}
function expireEvent(){if(eventDeadline&&performance.now()>=eventDeadline){result.textContent='No fresh scan event. Cached metadata is not a new scan.';success.textContent='';}}
function invalidateEvent(){eventDeadline=0;result.textContent='No fresh scan event. Cached metadata is not a new scan.';success.textContent='';}
const freshnessTimer=setInterval(()=>{if(qrMode&&!closed){if(cameraDiagnostics)showFreshness();expireEvent();}},1000);
async function timedFetch(url,options={}){
 const controller=new AbortController();pendingRequests.add(controller);
 if(url.startsWith('/qr/frame'))snapshotAbort=controller;
 const timer=setTimeout(()=>controller.abort(),4000);
 try{
  const response=await fetch(url,{...options,signal:controller.signal,cache:'no-store'});
  // Include body download inside the timeout, not just response headers.
  const body=await response.blob();return {response,body};
 }finally{clearTimeout(timer);pendingRequests.delete(controller);if(snapshotAbort===controller)snapshotAbort=null;}
}
function cancelImage(){imageGeneration++;snapshotAbort?.abort();}
function clearImage(){cancelImage();snapshot.removeAttribute('src');if(snapshotUrl)URL.revokeObjectURL(snapshotUrl);snapshotUrl=null;shownSequence=null;}
async function refreshSnapshot(){
 const generation=imageGeneration;let candidate=null;const started=performance.now();
 try{
  const {response,body}=await timedFetch('/qr/frame?t='+Date.now());
  if(!response.ok)throw new Error('HTTP '+response.status+' (capture may be busy)');
  if(!(response.headers.get('Content-Type')||'').toLowerCase().startsWith('image/jpeg')||!body.size)throw new Error('Invalid JPEG response');
  const rawSequence=response.headers.get('X-Snapshot-Sequence'),rawAge=response.headers.get('X-Snapshot-Age-Ms');
  const sequence=Number(rawSequence),age=Number(rawAge);
  if(rawSequence===null||rawAge===null||!Number.isFinite(sequence)||sequence<=0||!Number.isFinite(age)||age<0)throw new Error('Missing snapshot freshness metadata');
  if(sequence===shownSequence){showFreshness();return;}
  const downloaded=performance.now();
  candidate=URL.createObjectURL(body);const probe=new Image();probe.src=candidate;await probe.decode();
  if(closed||!qrMode||generation!==imageGeneration)return;
  const previous=snapshotUrl;snapshot.src=candidate;snapshotUrl=candidate;candidate=null;snapshot.hidden=false;
  const displayed=performance.now();shownSequence=sequence;receivedAt=displayed;
  serverAgeMs=age+(displayed-started);receivedClock=new Date().toLocaleTimeString();
  if(previous)URL.revokeObjectURL(previous);
  imageStatus.textContent='Snapshot refresh '+Math.round(displayed-started)+'ms (fetch/body '+Math.round(downloaded-started)+'ms, image decode '+Math.round(displayed-downloaded)+'ms).';
  showFreshness();
 }catch(error){if(!closed&&qrMode&&generation===imageGeneration)imageStatus.textContent='Snapshot retry: '+error.message+'. Keeping the last valid image.';}
 finally{if(candidate)URL.revokeObjectURL(candidate);}
}
function stop(){wanted=false;clearTimeout(retry);video.removeAttribute('src');}
function start(){stop();if(!cameraDiagnostics){status.textContent='Preview configured; camera diagnostics disabled.';return;}wanted=true;status.textContent='Connecting. Moving video confirms camera output.';retry=setTimeout(()=>{if(wanted)video.src=url+'?t='+Date.now();},250);}
document.getElementById('start').onclick=start;
document.getElementById('stop').onclick=()=>{stop();status.textContent='Stream stopped.';};
video.onerror=()=>{if(wanted&&!qrMode){status.textContent='Stream disconnected. Retrying in 2 seconds; check Serial Monitor if repeated.';clearTimeout(retry);retry=setTimeout(start,2000);}};
document.getElementById('direct').onclick=stop;
async function changeMode(qr){if(modeBusy)return;modeBusy=true;modeGeneration++;invalidateEvent();cancelImage();stop();try{const {response,body}=await timedFetch('/mode?value='+(qr?'qr':'preview'),{method:'POST'});const text=await body.text();status.textContent=text;if(!response.ok)throw new Error(text);qrMode=qr;previewAfterSwitch=!qr;}catch(error){status.textContent=error.message;}finally{modeBusy=false;}}
document.getElementById('qr').onclick=()=>changeMode(true);
document.getElementById('preview').onclick=()=>changeMode(false);
document.getElementById('start').onclick=()=>{if(!qrMode)start();else status.textContent='Return to preview before starting a stream.';};
async function poll(){
 if(closed)return;
 const pollStarted=performance.now();
 const generation=modeGeneration;
 try{
  const {response,body}=await timedFetch('/qr/status');if(!response.ok)throw new Error('Status HTTP '+response.status);
  const data=JSON.parse(await body.text());if(closed||generation!==modeGeneration)return;
  cameraDiagnostics=data.camera_diagnostics===true;
  for(const id of ['start','stop','direct'])document.getElementById(id).hidden=!cameraDiagnostics;
  details.textContent=data.text;
  let fresh=data.event_state==='fresh'&&data.mode==='qr'&&typeof data.event_id==='string'&&data.event_id.length>0&&Number.isFinite(data.event_age_ms)&&data.event_age_ms>=0&&data.event_age_ms<5000;
  if(fresh){const deadline=performance.now()+5000-data.event_age_ms;if(currentEvent===data.event_id)eventDeadline=Math.min(eventDeadline,deadline);else{currentEvent=data.event_id;eventDeadline=deadline;}fresh=performance.now()<eventDeadline;}
  const summary=fresh?'QR decoded ('+data.decoded_bytes+' bytes). '+(data.format_match?'SmartBarangay format match; NOT server verified.':'Other QR format; NOT server verified.'):'No fresh scan event. Cached metadata is not a new scan.';
  if(result.textContent!==summary)result.textContent=summary;
  if(fresh&&data.event_id!==lastEvent){lastEvent=data.event_id;success.textContent='QR decoded successfully. Event '+data.event_id+', reported event age '+data.event_age_ms+'ms; status round trip '+Math.round(performance.now()-pollStarted)+'ms. Not server verified.';}
  else if(!fresh)success.textContent='';
  const ready=data.mode==='qr';
  if(!ready)scanState.textContent=data.mode==='switching'?'Switching mode; scanning paused.':'';
  if(ready||data.mode==='switching'||data.mode==='error')stop();
  video.hidden=ready||!cameraDiagnostics;
  if(ready){
   qrMode=true;status.textContent=cameraDiagnostics?'QR mode active - periodic VGA snapshots (dummy codes only).':'QR mode active - camera diagnostics disabled; safe metadata only.';
   const scanning=data.scanning?'Scanning... processing a VGA frame.':'Scanning... waiting for the next scheduled frame.';
   if(scanState.textContent!==scanning)scanState.textContent=scanning;
   if(cameraDiagnostics&&data.snapshot_sequence>0&&data.snapshot_sequence!==shownSequence&&!imageInFlight&&performance.now()-lastImageRequest>=IMAGE_REFRESH_MS){
    imageInFlight=true;lastImageRequest=performance.now();
    refreshSnapshot().finally(()=>{imageInFlight=false;});
   }
   else if(cameraDiagnostics&&!snapshotUrl)imageStatus.textContent='Waiting for the first captured image.';
   if(cameraDiagnostics)showFreshness();else{clearImage();snapshot.hidden=true;imageStatus.textContent='';freshness.textContent='Camera images are disabled in this build.';}
  }
  else if(data.mode==='switching'){status.textContent='Switching camera mode — please wait.';clearImage();snapshot.hidden=true;}
  else if(data.mode==='error'){status.textContent='Camera unavailable — check diagnostics and reset.';clearImage();snapshot.hidden=true;}
  else if(data.mode==='preview'){
   clearImage();snapshot.hidden=true;imageStatus.textContent='';freshness.textContent='QR images are periodic snapshots, not live video.';scanState.textContent='';
   if((qrMode||previewAfterSwitch)&&!modeBusy){qrMode=false;previewAfterSwitch=false;start();}
   status.textContent=!cameraDiagnostics?'Preview mode active — camera diagnostics disabled.':wanted?'Preview mode active.':'Preview mode active — stream stopped.';
  }
 }catch(error){if(!closed){invalidateEvent();status.textContent='Status connection lost: '+error.message;}}
 finally{if(!closed)setTimeout(poll,Math.max(100,(qrMode||modeBusy?QR_STATUS_POLL_MS:PREVIEW_STATUS_POLL_MS)-(performance.now()-pollStarted)));}
}
window.addEventListener('pagehide',()=>{closed=true;clearInterval(freshnessTimer);stop();clearImage();for(const controller of pendingRequests)controller.abort();});
poll();
</script></body></html>)HTML";

esp_err_t pageHandler(httpd_req_t *request) {
    httpd_resp_set_type(request, "text/html; charset=utf-8");
    httpd_resp_set_hdr(request, "Cache-Control", "no-store");
    return httpd_resp_send(request, TEST_PAGE, HTTPD_RESP_USE_STRLEN);
}

esp_err_t streamHandler(httpd_req_t *request) {
    if (!readiness::cameraDiagnostics) { return httpd_resp_send_err(request, HTTPD_404_NOT_FOUND, "Camera diagnostics disabled."); }
    if (qrRequested || qrActive || !cameraReady) {
        httpd_resp_set_status(request, "409 Conflict");
        return httpd_resp_sendstr(request, "Camera is in QR mode or unavailable. Return to preview first.");
    }
    if (httpd_sess_set_send_override(request->handle, httpd_req_to_sockfd(request), boundedSend) != ESP_OK) {
        return ESP_FAIL;
    }
    portENTER_CRITICAL(&diagnosticsMux);
    diagnostics.active = true;
    portEXIT_CRITICAL(&diagnosticsMux);
    Serial.println("[HTTP] MJPEG viewer connected.");
    esp_err_t result = httpd_resp_set_type(request, "multipart/x-mixed-replace;boundary=frame");
    httpd_resp_set_hdr(request, "Cache-Control", "no-store");
    while (result == ESP_OK && WiFi.status() == WL_CONNECTED && !qrRequested && !qrActive) {
        if (xSemaphoreTake(cameraMutex, pdMS_TO_TICKS(100)) != pdTRUE) {
            delay(1);
            continue;
        }
        if (qrRequested || qrActive || !cameraReady) {
            xSemaphoreGive(cameraMutex);
            break;
        }
        const uint32_t frameStarted = millis();
        const int64_t captureStarted = esp_timer_get_time();
        camera_fb_t *frame = esp_camera_fb_get();
        const uint32_t captureUs = static_cast<uint32_t>(esp_timer_get_time() - captureStarted);
        const bool badFrame = frame == nullptr || frame->format != PIXFORMAT_JPEG || frame->len == 0;
        portENTER_CRITICAL(&diagnosticsMux);
        diagnostics.captureUs += captureUs;
        diagnostics.captures++;
        diagnostics.captureFailures += badFrame ? 1 : 0;
        portEXIT_CRITICAL(&diagnosticsMux);
        if (frame == nullptr) {
            xSemaphoreGive(cameraMutex);
            Serial.println("[CAM] Capture failed. Check ribbon and stable 5V power.");
            result = ESP_FAIL;
            break;
        }
        if (badFrame) {
            esp_camera_fb_return(frame);
            xSemaphoreGive(cameraMutex);
            Serial.println("[CAM] Unexpected non-JPEG frame; stream stopped.");
            result = ESP_FAIL;
            break;
        }
        char header[96];
        const int length = snprintf(header, sizeof(header),
            "Content-Type: image/jpeg\r\nContent-Length: %u\r\n\r\n",
            static_cast<unsigned int>(frame->len));
        const int64_t transmitStarted = esp_timer_get_time();
        sendDeadline = transmitStarted + FRAME_SEND_BUDGET_US;
        sendTimedOut = false;
        static constexpr char boundary[] = "\r\n--frame\r\n";
        result = httpd_resp_send_chunk(request, boundary, sizeof(boundary) - 1);
        if (result == ESP_OK) {
            result = httpd_resp_send_chunk(request, header, length);
        }
        if (result == ESP_OK) {
            result = httpd_resp_send_chunk(request, reinterpret_cast<const char *>(frame->buf), frame->len);
        }
        esp_camera_fb_return(frame);
        xSemaphoreGive(cameraMutex);
        const uint32_t transmitUs = static_cast<uint32_t>(esp_timer_get_time() - transmitStarted);
        portENTER_CRITICAL(&diagnosticsMux);
        diagnostics.transmitUs += transmitUs;
        diagnostics.transmissions++;
        if (transmitUs > diagnostics.maxTransmitUs) {
            diagnostics.maxTransmitUs = transmitUs;
        }
        if (result == ESP_OK) {
            diagnostics.frames++;
            diagnostics.windowFrames++;
        }
        diagnostics.sendTimeouts += sendTimedOut ? 1 : 0;
        portEXIT_CRITICAL(&diagnosticsMux);
        if (result != ESP_OK) {
            if (sendTimedOut) {
                Serial.println("[HTTP] Slow viewer: frame send exceeded 1500 ms; closing stream.");
            }
            break;
        }
        const uint32_t elapsed = millis() - frameStarted;
        delay(elapsed < FRAME_INTERVAL_MS ? FRAME_INTERVAL_MS - elapsed : 1);
    }
    portENTER_CRITICAL(&diagnosticsMux);
    diagnostics.active = false;
    diagnostics.disconnects++;
    portEXIT_CRITICAL(&diagnosticsMux);
    Serial.println("[HTTP] Stream ended: viewer closed, Wi-Fi lost, or capture/send error.");
    return result == ESP_OK ? ESP_FAIL : result;
}

bool initializeCamera(bool forQr = false) {
    const bool hasPsram = psramFound();
    Serial.printf("[MEM] PSRAM: %s; total=%u, free=%u; free heap=%u bytes\n",
        hasPsram ? "detected" : "NOT detected",
        static_cast<unsigned int>(ESP.getPsramSize()),
        static_cast<unsigned int>(ESP.getFreePsram()),
        static_cast<unsigned int>(ESP.getFreeHeap()));
    camera_config_t config = {};
    config.ledc_channel = LEDC_CHANNEL_0;
    config.ledc_timer = LEDC_TIMER_0;
    // AI Thinker parallel camera bus: D0=Y2 through D7=Y9.
    config.pin_d0 = 5;
    config.pin_d1 = 18;
    config.pin_d2 = 19;
    config.pin_d3 = 21;
    config.pin_d4 = 36;
    config.pin_d5 = 39;
    config.pin_d6 = 34;
    config.pin_d7 = 35;
    config.pin_xclk = 0;
    config.pin_pclk = 22;
    config.pin_vsync = 25;
    config.pin_href = 23;
    config.pin_sccb_sda = 26;
    config.pin_sccb_scl = 27;
    config.pin_pwdn = 32;
    config.pin_reset = -1;
    config.xclk_freq_hz = 20000000;
    config.pixel_format = PIXFORMAT_JPEG;
    config.frame_size = forQr ? FRAMESIZE_VGA : FRAMESIZE_QVGA;
    config.jpeg_quality = forQr ? 12 : 15;
    config.fb_count = forQr ? 1 : (hasPsram ? 2 : 1);
    config.fb_location = hasPsram ? CAMERA_FB_IN_PSRAM : CAMERA_FB_IN_DRAM;
    config.grab_mode = !forQr && hasPsram ? CAMERA_GRAB_LATEST : CAMERA_GRAB_WHEN_EMPTY;
    if (!hasPsram) {
        Serial.println("[CAM] Low-memory fallback: QVGA JPEG, one DRAM frame buffer.");
    }
    const esp_err_t error = esp_camera_init(&config);
    if (error != ESP_OK) {
        Serial.printf("[CAM] Init failed: 0x%x (%s).\n", error, esp_err_to_name(error));
        Serial.println("[CAM] Power OFF before reseating OV2640 ribbon. Check board model and stable 5V supply, then reset.");
        return false;
    }
    sensor_t *sensor = esp_camera_sensor_get();
    if (sensor == nullptr || sensor->id.PID != OV2640_PID) {
        Serial.println("[CAM] OV2640 not detected; stopping test.");
        esp_camera_deinit();
        return false;
    }
    Serial.printf("[CAM] OV2640 initialized: PID=0x%02x, %s JPEG, buffers=%d.\n",
        sensor->id.PID, forQr ? "640x480 QR" : "320x240 preview", config.fb_count);
    return true;
}

void setQrMessage(const char *message) {
    xSemaphoreTake(qrResultMutex, portMAX_DELAY);
    snprintf(qrMessage, sizeof(qrMessage), "%s", message);
    xSemaphoreGive(qrResultMutex);
}

void recordQrTiming(QrTimings timing, int64_t started) {
    timing.totalUs = static_cast<uint32_t>(esp_timer_get_time() - started);
    timing.completedMs = millis();
    const bool headroom = timing.processed && timing.totalUs <= QR_FAST_HEADROOM_US &&
        ESP.getFreeHeap() >= 65536 && uxTaskGetStackHighWaterMark(nullptr) >= 4096;
    const uint32_t good = headroom ? qrHeadroomSamples.load() + 1 : 0;
    qrHeadroomSamples = good > QR_FAST_REQUIRED_SAMPLES ? QR_FAST_REQUIRED_SAMPLES : good;
    qrCurrentIntervalMs = QR_ENABLE_FAST_EXPERIMENT && qrHeadroomSamples >= QR_FAST_REQUIRED_SAMPLES ? QR_EXPERIMENT_INTERVAL_MS : QR_SCAN_INTERVAL_MS;
    xSemaphoreTake(qrResultMutex, portMAX_DELAY);
    timing.completionPeriodMs = qrCompletedAt ? timing.completedMs - qrCompletedAt : 0;
    qrCompletedAt = timing.completedMs;
    qrLastTiming = timing;
    qrTimingSamples++;
    xSemaphoreGive(qrResultMutex);
    if (QR_BENCHMARK_LOG_EACH_SCAN) {
        Serial.printf("[QRBENCH] session=%lu attempt=%lu completed_ms=%lu total_us=%lu capture_us=%lu convert_us=%lu gray_us=%lu detect_us=%lu decode_us=%lu ready_us=%lu decoded=%d captured=%d\n",
            static_cast<unsigned long>(qrSession), static_cast<unsigned long>(timing.attempt), static_cast<unsigned long>(timing.completedMs),
            static_cast<unsigned long>(timing.totalUs), static_cast<unsigned long>(timing.captureUs), static_cast<unsigned long>(timing.convertUs),
            static_cast<unsigned long>(timing.grayUs), static_cast<unsigned long>(timing.detectUs), static_cast<unsigned long>(timing.decodeUs),
            static_cast<unsigned long>(timing.readyUs), timing.decoded, timing.captured);
    }
    qrProcessing = false;
}

void qrWorker(void *) {
    quirc *decoder = nullptr;
    quirc_code *code = nullptr;
    quirc_data *data = nullptr;
    uint8_t *rgb = nullptr;
    uint32_t lastScan = 0, lastPublished = 0;
    uint32_t previousAttempt = 0;
    auto releaseDecoder = [&]() {
        if (decoder) { quirc_destroy(decoder); decoder = nullptr; }
        free(code); code = nullptr;
        free(data); data = nullptr;
        free(rgb); rgb = nullptr;
        xSemaphoreTake(snapshotMutex, portMAX_DELAY);
        free(qrSnapshot); qrSnapshot = nullptr;
        qrSnapshotSize = 0;
        xSemaphoreGive(snapshotMutex);
    };
    for (;;) {
        xSemaphoreTake(qrResultMutex, portMAX_DELAY);
        scanEvents.expire(static_cast<uint64_t>(esp_timer_get_time() / 1000));
        xSemaphoreGive(qrResultMutex);
        if (qrRequested.load() != qrActive.load()) {
            modeSwitching = true;
            xSemaphoreTake(qrResultMutex, portMAX_DELAY);
            scanEvents.invalidate();
            xSemaphoreGive(qrResultMutex);
            xSemaphoreTake(cameraMutex, portMAX_DELAY);
            cameraReady = false;
            esp_camera_deinit();
            releaseDecoder();
            bool enterQr = qrRequested.load();
            if (enterQr) {
                setQrMessage("Switching to VGA QR capture; please wait.");
                decoder = quirc_new();
                code = static_cast<quirc_code *>(heap_caps_calloc(1, sizeof(quirc_code), MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
                data = static_cast<quirc_data *>(heap_caps_calloc(1, sizeof(quirc_data), MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
                rgb = static_cast<uint8_t *>(heap_caps_malloc(640 * 480 * 3, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
                xSemaphoreTake(snapshotMutex, portMAX_DELAY);
                qrSnapshot = static_cast<uint8_t *>(heap_caps_malloc(128 * 1024, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
                xSemaphoreGive(snapshotMutex);
                if (!decoder || !code || !data || !rgb || !qrSnapshot || quirc_resize(decoder, 640, 480) < 0) {
                    setQrMessage("Insufficient QR workspace memory. Returning to preview.");
                    releaseDecoder();
                    qrRequested = false;
                    enterQr = false;
                }
            }
            cameraReady = initializeCamera(enterQr);
            if (!cameraReady && enterQr) {
                setQrMessage("QR camera initialization failed. Restoring preview.");
                releaseDecoder();
                esp_camera_deinit();
                qrRequested = false;
                enterQr = false;
                cameraReady = initializeCamera(false);
            }
            qrActive = enterQr && cameraReady;
            if (!cameraReady) {
                qrRequested = false;
                releaseDecoder();
                setQrMessage("Camera unavailable. Correct power/sensor and reset board.");
            } else if (qrActive) {
                setQrMessage("QR mode ready: present a large, well-lit QR code.");
            } else if (!enterQr) {
                // Keep any failure explanation visible; preview status is separate.
                Serial.println("[QR] Preview camera restored; QR workspace released.");
            }
            xSemaphoreGive(cameraMutex);
            modeSwitching = false;
            lastScan = millis();
            previousAttempt = 0;
            qrHeadroomSamples = 0;
            qrCurrentIntervalMs = QR_SCAN_INTERVAL_MS;
            lastPublished = 0;
            xSemaphoreTake(qrResultMutex, portMAX_DELAY);
            qrCompletedAt = 0;
            if (qrActive) { qrSession++; scanEvents.beginSession(); }
            xSemaphoreGive(qrResultMutex);
        }
        if (qrActive && qrRequested && millis() - lastScan >= qrCurrentIntervalMs.load()) {
            lastScan = millis();
            QrTimings timing;
            timing.periodMs = previousAttempt ? lastScan - previousAttempt : 0;
            previousAttempt = lastScan;
            const int64_t attemptStarted = esp_timer_get_time();
            xSemaphoreTake(cameraMutex, portMAX_DELAY);
            timing.lockUs = static_cast<uint32_t>(esp_timer_get_time() - attemptStarted);
            if (!qrRequested) { xSemaphoreGive(cameraMutex); continue; }
            qrProcessing = true;
            const int64_t started = esp_timer_get_time();
            // Single-buffer capture may contain a frame from the previous attempt.
            camera_fb_t *stale = esp_camera_fb_get();
            if (stale) { esp_camera_fb_return(stale); }
            camera_fb_t *frame = esp_camera_fb_get();
            const uint32_t captureUs = static_cast<uint32_t>(esp_timer_get_time() - started);
            const uint32_t acquiredMs = millis();
            timing.captureUs = captureUs;
            const bool valid = frame && frame->format == PIXFORMAT_JPEG && frame->width == 640 && frame->height == 480 && frame->len;
            timing.captured = valid;
            portENTER_CRITICAL(&diagnosticsMux);
            diagnostics.captures++;
            diagnostics.captureUs += captureUs;
            diagnostics.captureFailures += (valid ? 0 : 1) + (stale ? 0 : 1);
            portEXIT_CRITICAL(&diagnosticsMux);
            xSemaphoreTake(qrResultMutex, portMAX_DELAY);
            qrAttempts++;
            timing.attempt = qrAttempts;
            xSemaphoreGive(qrResultMutex);
            bool converted = false;
            if (valid) {
                // Optional publication never waits for an HTTP reader. Recognition
                // keeps running if the cache is busy; the page retains its last image.
                if (readiness::cameraDiagnostics && (!lastPublished || millis() - lastPublished >= QR_SNAPSHOT_INTERVAL_MS) && frame->len >= 4 && frame->len <= 128 * 1024 && frame->buf[0] == 0xff && frame->buf[1] == 0xd8) {
                    const int64_t waitStarted = esp_timer_get_time();
                    if (xSemaphoreTake(snapshotMutex, 0) == pdTRUE) {
                        const int64_t copyStarted = esp_timer_get_time();
                        timing.cacheWaitUs = static_cast<uint32_t>(copyStarted - waitStarted);
                        memcpy(qrSnapshot, frame->buf, frame->len);
                        qrSnapshotSize = frame->len;
                        snapshotAcquiredMs = acquiredMs;
                        snapshotSequence++;
                        lastPublished = millis();
                        timing.copyUs = static_cast<uint32_t>(esp_timer_get_time() - copyStarted);
                        xSemaphoreGive(snapshotMutex);
                    } else { qrSnapshotsSkipped++; }
                }
                const int64_t convertStarted = esp_timer_get_time();
                converted = fmt2rgb888(frame->buf, frame->len, PIXFORMAT_JPEG, rgb);
                timing.convertUs = static_cast<uint32_t>(esp_timer_get_time() - convertStarted);
            }
            if (frame) { esp_camera_fb_return(frame); }
            if (!converted) {
                setQrMessage("QR capture/JPEG conversion failed; retrying next capture.");
                recordQrTiming(timing, attemptStarted);
                xSemaphoreGive(cameraMutex);
                vTaskDelay(pdMS_TO_TICKS(100));
                continue;
            }
            uint8_t *gray = quirc_begin(decoder, nullptr, nullptr);
            const int64_t grayStarted = esp_timer_get_time();
            // Driver converter returns three color channels; average is order-independent.
            for (size_t i = 0; i < 640 * 480; ++i) {
                gray[i] = (static_cast<unsigned int>(rgb[i * 3]) + rgb[i * 3 + 1] + rgb[i * 3 + 2]) / 3;
            }
            timing.grayUs = static_cast<uint32_t>(esp_timer_get_time() - grayStarted);
            const int64_t detectStarted = esp_timer_get_time();
            quirc_end(decoder);
            timing.detectUs = static_cast<uint32_t>(esp_timer_get_time() - detectStarted);
            timing.processed = true;
            const int count = quirc_count(decoder);
            setQrMessage(count ? "QR located; attempting decode." : "No QR found. Adjust distance, focus and lighting.");
            for (int i = 0; i < count; ++i) {
                const int64_t decodeStarted = esp_timer_get_time();
                quirc_extract(decoder, i, code);
                quirc_decode_error_t error = quirc_decode(code, data);
                if (error == QUIRC_ERROR_DATA_ECC) {
                    quirc_flip(code);
                    error = quirc_decode(code, data);
                }
                timing.decodeUs += static_cast<uint32_t>(esp_timer_get_time() - decodeStarted);
                if (error != QUIRC_SUCCESS) {
                    setQrMessage(quirc_strerror(error));
                    continue;
                }
                xSemaphoreTake(qrResultMutex, portMAX_DELAY);
                const bool notify = scanEvents.observe(data->payload, data->payload_len,
                    static_cast<uint64_t>(esp_timer_get_time() / 1000), timing.attempt);
                qrDecodes++;
                if (notify) { qrEvents++; }
                snprintf(qrMessage, sizeof(qrMessage), "Decoded %d bytes. Content withheld; decoding/format is not server verification.", data->payload_len);
                xSemaphoreGive(qrResultMutex);
                timing.decoded = true;
                timing.readyUs = static_cast<uint32_t>(esp_timer_get_time() - attemptStarted);
                if (notify) {
                    Serial.printf("[QR] Decoded bytes=%d; content withheld; NOT server verified.\n", data->payload_len);
                }
                break; // First successfully decoded code per capture is enough for feasibility.
            }
            recordQrTiming(timing, attemptStarted);
            xSemaphoreGive(cameraMutex);
        }
        uint32_t pauseMs = 100;
        if (qrActive && qrRequested) {
            const uint32_t elapsed = millis() - lastScan, interval = qrCurrentIntervalMs.load();
            pauseMs = elapsed >= interval ? QR_MIN_YIELD_MS : (interval - elapsed < 100 ? interval - elapsed : 100);
            if (pauseMs < QR_MIN_YIELD_MS) { pauseMs = QR_MIN_YIELD_MS; }
        }
        vTaskDelay(pdMS_TO_TICKS(pauseMs));
    }
}

esp_err_t modeHandler(httpd_req_t *request) {
    char query[32] = {}, value[16] = {};
    if (httpd_req_get_url_query_str(request, query, sizeof(query)) != ESP_OK ||
        httpd_query_key_value(query, "value", value, sizeof(value)) != ESP_OK ||
        (strcmp(value, "qr") && strcmp(value, "preview"))) {
        return httpd_resp_send_err(request, HTTPD_400_BAD_REQUEST, "Use value=qr or value=preview.");
    }
    const bool qr = strcmp(value, "qr") == 0;
    if (qr && (!psramFound() || !qrTask || !cameraReady)) {
        httpd_resp_set_status(request, "503 Service Unavailable");
        return httpd_resp_sendstr(request, "QR mode requires PSRAM, a ready camera and QR worker. Preview remains available if initialized.");
    }
    // Repeating a request for the active mode must not mint a session/event.
    if (qr != qrRequested.load()) {
        xSemaphoreTake(qrResultMutex, portMAX_DELAY);
        scanEvents.invalidate();
        xSemaphoreGive(qrResultMutex);
    }
    qrRequested = qr;
    httpd_resp_set_status(request, "202 Accepted");
    return httpd_resp_sendstr(request, qr ? "Switching to QR mode; preview stream will close." : "Returning to preview; wait for camera initialization.");
}

String quoteJson(const String &text) {
    String json = "\"";
    json.reserve(text.length() + 64);
    for (size_t i = 0; i < text.length(); ++i) {
        const unsigned char byte = text[i];
        if (byte == '"' || byte == '\\') { json += '\\'; json += static_cast<char>(byte); }
        else if (byte < 32 || byte >= 127) {
            // JSON remains valid even for arbitrary QR bytes. UI supports ASCII test text.
            char escape[7]; snprintf(escape, sizeof(escape), "\\u%04x", byte); json += escape;
        } else { json += static_cast<char>(byte); }
    }
    json += '"';
    return json;
}

esp_err_t qrStatusHandler(httpd_req_t *request) {
    String text;
    text.reserve(512);
    const bool active = !modeSwitching && cameraReady && qrActive && qrRequested;
    const uint64_t nowMs = static_cast<uint64_t>(esp_timer_get_time() / 1000);
    char identity[96] = {};
    xSemaphoreTake(qrResultMutex, portMAX_DELAY);
    text = String(qrMessage) + "\nAttempts: " + qrAttempts + "; successful decodes (including repeats): " + qrDecodes + "; notifications: " + qrEvents;
    const uint32_t events = qrEvents;
    const bool fresh = scanEvents.fresh(nowMs, active);
    const bool present = scanEvents.present;
    const uint64_t eventAgeMs = present && nowMs >= scanEvents.acquiredMs ? nowMs - scanEvents.acquiredMs : 0;
    const uint32_t eventAttempt = scanEvents.attempt;
    const size_t byteCount = scanEvents.byteCount;
    const bool formatMatch = scanEvents.formatMatch;
    if (present) {
        snprintf(identity, sizeof(identity), "%s:%llu:%llu", bootId,
            static_cast<unsigned long long>(scanEvents.session), static_cast<unsigned long long>(scanEvents.sequence));
    }
    xSemaphoreGive(qrResultMutex);
    text += String("\nPublished snapshots: ") + snapshotSequence.load() + "; image is the latest cached capture.";
    const char *mode = modeSwitching || qrRequested != qrActive ? "switching" : (!cameraReady ? "error" : (qrActive ? "qr" : "preview"));
    char age[24]; snprintf(age, sizeof(age), "%llu", static_cast<unsigned long long>(eventAgeMs));
    String json = String("{\"mode\":\"") + mode + "\",\"text\":" + quoteJson(text) + ",\"events\":" + events +
        ",\"event_state\":\"" + (fresh ? "fresh" : (present ? "expired" : "absent")) + "\",\"event_id\":" + quoteJson(identity) +
        ",\"decoded_bytes\":" + static_cast<unsigned int>(byteCount) + ",\"format_match\":" + (formatMatch ? "true" : "false") +
        ",\"camera_diagnostics\":" + (readiness::cameraDiagnostics ? "true" : "false") +
        ",\"event_age_ms\":" + age + ",\"event_attempt\":" + eventAttempt +
        ",\"scan_interval_ms\":" + qrCurrentIntervalMs.load() + ",\"scanning\":" + (qrProcessing.load() ? "true" : "false") + ",\"snapshot_sequence\":" + snapshotSequence.load() + "}";
    httpd_resp_set_type(request, "application/json");
    httpd_resp_set_hdr(request, "Cache-Control", "no-store");
    return httpd_resp_send(request, json.c_str(), json.length());
}

esp_err_t qrFrameHandler(httpd_req_t *request) {
    if (!readiness::cameraDiagnostics) { return httpd_resp_send_err(request, HTTPD_404_NOT_FOUND, "Camera diagnostics disabled."); }
    if (xSemaphoreTake(snapshotMutex, pdMS_TO_TICKS(100)) != pdTRUE) {
        httpd_resp_set_status(request, "503 Service Unavailable");
        return httpd_resp_sendstr(request, "QR capture busy; next poll will retry.");
    }
    if (modeSwitching || !qrActive || !qrRequested || !qrSnapshot || !qrSnapshotSize) {
        xSemaphoreGive(snapshotMutex);
        httpd_resp_set_status(request, "503 Service Unavailable");
        return httpd_resp_sendstr(request, "No QR snapshot yet.");
    }
    snapshotDeadline = esp_timer_get_time() + FRAME_SEND_BUDGET_US;
    snapshotTimedOut = false;
    if (httpd_sess_set_send_override(request->handle, httpd_req_to_sockfd(request), boundedSend) != ESP_OK) {
        xSemaphoreGive(snapshotMutex);
        return ESP_FAIL;
    }
    httpd_resp_set_type(request, "image/jpeg");
    httpd_resp_set_status(request, "200 OK");
    httpd_resp_set_hdr(request, "Cache-Control", "no-store");
    char sequenceHeader[16], ageHeader[16];
    snprintf(sequenceHeader, sizeof(sequenceHeader), "%lu", static_cast<unsigned long>(snapshotSequence.load()));
    snprintf(ageHeader, sizeof(ageHeader), "%lu", static_cast<unsigned long>(millis() - snapshotAcquiredMs));
    httpd_resp_set_hdr(request, "X-Snapshot-Sequence", sequenceHeader);
    httpd_resp_set_hdr(request, "X-Snapshot-Age-Ms", ageHeader);
    const int64_t started = esp_timer_get_time();
    const esp_err_t result = httpd_resp_send(request, reinterpret_cast<const char *>(qrSnapshot), qrSnapshotSize);
    const uint32_t elapsed = static_cast<uint32_t>(esp_timer_get_time() - started);
    portENTER_CRITICAL(&diagnosticsMux);
    diagnostics.transmissions++;
    diagnostics.transmitUs += elapsed;
    if (elapsed > diagnostics.maxTransmitUs) { diagnostics.maxTransmitUs = elapsed; }
    diagnostics.sendTimeouts += snapshotTimedOut ? 1 : 0;
    portEXIT_CRITICAL(&diagnosticsMux);
    xSemaphoreGive(snapshotMutex);
    // A successful response stays successful. Restore the page writer so this
    // snapshot's expired deadline cannot affect a later keep-alive request.
    if (httpd_sess_set_send_override(request->handle, httpd_req_to_sockfd(request), pageSend) != ESP_OK) {
        return ESP_FAIL;
    }
    return result;
}

bool startServers() {
    httpd_config_t config = HTTPD_DEFAULT_CONFIG();
    config.server_port = 80;
    config.max_uri_handlers = 5;
    config.max_open_sockets = 2;
    config.lru_purge_enable = true;
    config.send_wait_timeout = 3;
    httpd_uri_t page = {};
    page.uri = "/";
    page.method = HTTP_GET;
    page.handler = pageHandler;
    esp_err_t error = httpd_start(&pageServer, &config);
    if (error == ESP_OK) {
        error = httpd_register_uri_handler(pageServer, &page);
    }
    if (error == ESP_OK) {
        httpd_uri_t mode = {}; mode.uri = "/mode"; mode.method = HTTP_POST; mode.handler = modeHandler;
        error = httpd_register_uri_handler(pageServer, &mode);
    }
    if (error == ESP_OK) {
        httpd_uri_t status = {}; status.uri = "/qr/status"; status.method = HTTP_GET; status.handler = qrStatusHandler;
        error = httpd_register_uri_handler(pageServer, &status);
    }
    if (error == ESP_OK && readiness::cameraDiagnostics) {
        httpd_uri_t frame = {}; frame.uri = "/qr/frame"; frame.method = HTTP_GET; frame.handler = qrFrameHandler;
        error = httpd_register_uri_handler(pageServer, &frame);
    }
    if (error == ESP_OK && readiness::cameraDiagnostics) {
        config.server_port = 81;
        config.ctrl_port += 1;
        config.stack_size = 8192;
        // One streaming socket; do not evict its owner when a second viewer connects.
        config.max_open_sockets = 1;
        config.lru_purge_enable = false;
        config.recv_wait_timeout = 2;
        config.send_wait_timeout = 1;
        error = httpd_start(&streamServer, &config);
    }
    if (error == ESP_OK && readiness::cameraDiagnostics) {
        httpd_uri_t stream = {};
        stream.uri = "/stream";
        stream.method = HTTP_GET;
        stream.handler = streamHandler;
        error = httpd_register_uri_handler(streamServer, &stream);
    }
    if (error != ESP_OK) {
        Serial.printf("[HTTP] Startup failed: %s. Retry in 30 seconds.\n", esp_err_to_name(error));
        if (streamServer != nullptr) {
            httpd_stop(streamServer);
            streamServer = nullptr;
        }
        if (pageServer != nullptr) {
            httpd_stop(pageServer);
            pageServer = nullptr;
        }
        return false;
    }
    Serial.println(readiness::cameraDiagnostics ? "[HTTP] DUMMY-ONLY camera diagnostics enabled on LAN ports 80/81." : "[HTTP] Safe metadata/mode page on port 80; camera endpoints DISABLED.");
    return true;
}

void connectWifi() {
    Serial.println("[WiFi] Connecting to configured 2.4 GHz network (20-second timeout)...");
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    connectStarted = millis();
    connecting = true;
}
} // namespace

void setup() {
    Serial.begin(115200);
    delay(1000);
    Serial.println("\n[BOOT] Standalone AI Thinker ESP32-CAM / OV2640 test");
    Serial.printf("[BOOT] Reset reason=%d (1=power-on, 3=software, 4=panic, 5=int-WDT, 6=task-WDT, 7=other-WDT, 9=brownout).\n", static_cast<int>(esp_reset_reason()));
    pinMode(4, OUTPUT);
    digitalWrite(4, LOW); // Flash LED off.
    cameraMutex = xSemaphoreCreateMutex();
    qrResultMutex = xSemaphoreCreateMutex();
    snapshotMutex = xSemaphoreCreateMutex();
    if (!cameraMutex || !qrResultMutex || !snapshotMutex) {
        Serial.println("[BOOT] Mutex allocation failed; idle until reset.");
        return;
    }
    cameraReady = initializeCamera();
    if (!cameraReady) {
        Serial.println("[BOOT] Camera unavailable. Idle until hardware is corrected and reset pressed.");
        return;
    }
    if (psramFound() && xTaskCreatePinnedToCore(qrWorker, "qr-worker", 24576, nullptr, 1, &qrTask, 1) != pdPASS) {
        qrTask = nullptr;
        Serial.println("[QR] Worker allocation failed; preview only.");
    }
    credentialsReady = strlen(WIFI_SSID) > 0
        && strcmp(WIFI_SSID, "YOUR_2_4_GHZ_WIFI_NAME") != 0
        && strcmp(WIFI_PASSWORD, "YOUR_WIFI_PASSWORD") != 0;
    if (!credentialsReady) {
        Serial.println("[WiFi] Configure the external private Wi-Fi header and rebuild. Credentials are never logged.");
        return;
    }
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);
    connectWifi();
}

void loop() {
    reportDiagnostics();
    if (!cameraReady || !credentialsReady) {
        delay(1000);
        return;
    }
    const bool connected = WiFi.status() == WL_CONNECTED;
    if (connected && !wasConnected) {
        connecting = false;
        if (!bootId[0]) {
            uint8_t nonce[16]; esp_fill_random(nonce, sizeof(nonce));
            for (size_t i = 0; i < sizeof(nonce); ++i) { snprintf(bootId + i * 2, 3, "%02x", nonce[i]); }
        }
        const String ip = WiFi.localIP().toString();
        Serial.printf("[WiFi] Connected; RSSI=%d dBm.\n", WiFi.RSSI());
        Serial.printf("[WiFi] Metadata page: http://%s/\n", ip.c_str());
        if (readiness::cameraDiagnostics) { Serial.printf("[WiFi] DUMMY-ONLY MJPEG: http://%s:81/stream\n", ip.c_str()); }
        if (pageServer == nullptr) {
            startServers();
            lastRetry = millis();
        }
    } else if (!connected && wasConnected) {
        Serial.println("[WiFi] Connection lost. Reconnecting; reload after IP is announced.");
        lastRetry = millis();
    }
    wasConnected = connected;
    if (!connected && connecting && millis() - connectStarted >= CONNECT_TIMEOUT_MS) {
        connecting = false;
        WiFi.disconnect();
        lastRetry = millis();
        Serial.println("[WiFi] Connection timed out. Check credentials/2.4 GHz hotspot; retry in 30 seconds.");
    } else if (!connected && !connecting && millis() - lastRetry >= RETRY_INTERVAL_MS) {
        WiFi.disconnect();
        connectWifi();
    } else if (connected && pageServer == nullptr && millis() - lastRetry >= RETRY_INTERVAL_MS) {
        startServers();
        lastRetry = millis();
    }
    delay(250);
}
