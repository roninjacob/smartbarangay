# ESP32-CAM HTTPS reliability investigation — November 10, 2026

## What is established

The reported physical failure cannot be assigned a definitive root cause from `http=-1 tls=-1`. Inspection of the installed, pinned Arduino ESP32 3.3.12 implementation confirms that its socket failure paths and TLS handshake deadline can return the same -1. No physical device or hosted production endpoint was exercised by this task.

Verified code defects/risks corrected:

1. Each request previously used HTTP/1.0, disabled reuse and constructed a new secure client. Every heartbeat/preview needed a cold TLS handshake inside a 5-second capture deadline, with only a 3-second handshake allowance. Those budgets can reject a slow but otherwise valid hosted connection; timing alone cannot establish that this caused the observed failures.
2. Preview construction used base64 `std::string`, another complete JSON concatenation, a body hashing conversion, and an HTTP `String` copy. At the 32-KiB JPEG limit, a base64 body is approximately 43.7 KiB, and overlapping full-body copies amplify heap pressure. A total internal heap reading of 55 KiB does not prove allocation failure; largest contiguous free block and failed-allocation evidence are now recorded.
3. The main loop copied the transient `cameraReady=false` during normal camera mode reinitialization into health reporting. Camera health is now published only after initialization/reinitialization reaches a stable result; QR workspace failure reports `detection_error`, not a camera hardware failure.
4. Generic -1 errors previously had no phase diagnostics. They now preserve DNS/TCP/TLS/HTTP phase evidence without exposing credential-bearing buffers.

## Transport changes and security

- The pinned SDK's trusted CA connection path is instrumented by separating `start_ssl_client` and `ssl_starttls_handshake`, while preserving the original hostname for SNI/certificate verification. A successful connection requires zero certificate verification flags. No insecure mode, fingerprint bypass, redirects or library changes.
- Bounded asynchronous IPv4 DNS runs on the lwIP thread. One static result slot survives timeout and late completion; no callback writes to freed/stack memory. No unbounded DNS wait or new resolver task per retry.
- HTTP/1.1 keep-alive reuses a verified TLS socket where the hosting server allows it. Non-200, malformed or oversized responses close the connection; server-requested close is honored. No automatic re-POST or second handshake after a request body is captured/signed; the next request reconnects with fresh headers.
- Cold heartbeat: 20-second overall budget, DNS up to 2 seconds, TCP/setup up to 3 seconds, TLS handshake up to 8 seconds, HTTP read up to 3 seconds. Each connect/handshake budget is clamped to remaining overall time. Low-level SDK calls may finish at a scheduler-tick boundary after their configured timeout; expired connections cannot proceed to authenticated application writes.
- Heartbeat capture timestamp and HMAC are produced only after TLS establishment. This is health telemetry, not a re-timestamped QR capture.
- Reservation scans and preview uploads retain the existing **5-second capture lifetime**, including queue/connection time. Cold connections that consume that lifetime drop the observation instead of weakening Laravel freshness validation. Warm keep-alive avoids paying a fresh handshake for each upload/scan.
- TLS application writes are split into at most 1-KiB calls, each clamped to remaining time. This also fixes the SDK write loop's progress-reset timeout from allowing a slowly progressing large JPEG to exceed the intended deadline. A fault-injection test covers partial progress until the absolute limit.
- Preview uses a single PSRAM JSON/base64 allocation and raw byte hashing/POST. It no longer builds complete preview-body copies in internal-RAM `std::string`/Arduino `String` buffers.
- A conservative cold-handshake admission floor requires at least 32 KiB internal free RAM and a 16 KiB contiguous internal block. This is not proof that every certificate chain will fit. Explicit mbedTLS allocation failures and concurrent internal allocation failures remain distinguishable.
- Existing nonce/HMAC/rate limits, trusted root CA, revocation, automatic QR detection, private preview lease/expiry and manual check-in rules remain unchanged. Safe-default camera HTTP image endpoints remain disabled. Dummy diagnostics is still isolated and must never be flashed for real tickets.

## Safe diagnostics

One error line at most every 5 seconds, and one success line at most every 30 seconds. The failed-allocation callback records only atomic counters and allocation size; it never formats/logs a failing buffer. No callback logs raw request/response data, hosts, QR credentials, signatures, keys, certificate text, authorization headers, URLs or images.

Example **format**, not captured physical evidence:

```
[IoT-DIAG] op=status category=tls_handshake_timeout dns_ms=... connect_ms=... tls_ms=... http_ms=... total_ms=... reused=0 http=-1 tls=-1 verify=... heap_before=... largest_before=... heap=... largest=... min_heap=... alloc_fail=... failed_bytes=... stack=... clock_age_ms=... camera=ready
```

`connect_ms` includes TCP connect plus TLS context/CA setup; `tls_ms` measures the handshake separately. `reused=1` means the request began with a live verified socket. `verify=4294967295` on an incomplete handshake is not itself proof of a certificate rejection. Heap figures are bytes of **internal 8-bit RAM**, not PSRAM. Early precondition diagnostics have zero before-sample fields because no handshake was attempted. `alloc_fail` is cumulative and can include concurrent QR work; `internal_allocation_observed` is evidence of concurrent internal allocation failure, not proof that TLS caused it.

Categories:

| Category | Meaning / next evidence |
| --- | --- |
| `clock_unsynchronized` / `clock_stale` | SNTP has not synchronized, aged out, or UTC is below the accepted floor; HTTPS is withheld. `clock_age_ms=4294967295` means never synchronized. |
| `wifi_disconnected` | No connected Wi-Fi link. |
| `dns_resolution_failed` / `dns_timeout` | DNS failed or the bounded wait expired; no certificate conclusion is possible. |
| `internal_heap_pressure` | Admission floor failed; compare internal free/contiguous sizes. |
| `tls_allocation_failed` | SDK returned an explicit TLS/X.509 allocation error. |
| `internal_allocation_observed` | Allocation hook observed a concurrent internal-RAM failure during the failed operation. |
| `preview_psram_allocation_failed` | Private preview staging allocation failed; QR processing remains independent. |
| `tcp_connect_timeout` | TCP/setup phase exhausted its available connect budget. |
| `tcp_or_tls_setup_failed` | Failure before handshake (socket or TLS setup); raw code and allocation counters narrow it further. |
| `tls_certificate_rejected` | Explicit X.509 verification error/meaningful verification flags. Do not bypass validation; verify the actual configured CA, server chain, hostname and clock privately. |
| `tls_handshake_timeout` / `tls_handshake_failed` | Handshake deadline or another handshake error; preserve numeric TLS code. |
| `http_read_timeout` / `http_connection_failed` | TLS preparation passed but HTTP failed. `reused=1` may indicate a server-closed keep-alive connection. |
| `application_http_error` | Server replied; HTTP code distinguishes auth (401/403), duplicate/lease (409), capture validation (422), rate limit (429), server errors (5xx). No response body is logged. |
| `http_response_bounds_or_read_failed` / `application_invalid_json` / `application_invalid_response` | Response could not safely be accepted. |
| `deadline_exceeded` / `stale_capture_dropped` | Bounded request or original capture lifetime elapsed; no stale evidence is submitted. |
| `connection_closed_before_request` | Prepared socket closed before POST; no second cold handshake over an aging signed body. |
| `preview_yielded_to_scan` | Intentional preview cancellation to prioritize an arriving QR; not evidence of broken TLS. |
| `ok` | Successful validated request. |

## Physical acceptance and owner flashing

No upload or serial hardware test has been performed. Keep the existing private configuration untouched, and confirm the intended server/CA through your private provisioning process.

In ordinary PowerShell:

```powershell
Set-Location C:\xampp\htdocs\smartbarangay\firmware\SmartBarangay-ESP32-CAM-Test
pio device list
pio run -e esp32cam
```

Close any serial monitor first. **Only if COM3 is still the intended ESP32-CAM-MB**, the owner may upload:

```powershell
pio run -e esp32cam -t upload --upload-port COM3
pio device monitor --port COM3 --baud 115200
```

Use the actual listed port if different. Do not run erase-flash or upload `esp32cam-diagnostics` for real use. Normal upload replaces firmware; no credential configuration changes are required. Generated binaries embed private credentials and must remain outside Git/public web storage.

Capture 60 seconds from reset with Admin preview OFF, then 60 seconds after explicitly starting Admin preview. Keep only a synthetic ticket/neutral scene in view. Preserve `[IoT-DIAG]` and `[IoT] state=...` lines, successful OV2640 initialization, and existing QR timing/memory lines. These are the precise logs needed to determine the remaining physical root cause. Compare:

1. SNTP readiness and `camera=ready` after QR initialization;
2. cold vs `reused=1` timings and HTTP 200;
3. internal largest/free/minimum heap and allocation counter changes with preview off/on;
4. Admin last-seen and first frame arrival;
5. synthetic QR decoding while preview is active, duplicate handling and explicit manual check-in only.

If the server closes keep-alive between heartbeats or TLS still runs out of internal RAM, these logs will show that limitation. Do not increase QR capture lifetime or disable certificate validation to hide it.

## Changed files

- `firmware/SmartBarangay-ESP32-CAM-Test/src/iot_client.cpp`
- `firmware/SmartBarangay-ESP32-CAM-Test/src/main.cpp`
- `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_crypto.h`
- `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_transport_policy.h` (new)
- `firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot_transport.py` (new)
- `docs/iot-https-diagnostics.md` (this file)

No Laravel behavior, database, migrations, private credentials, production settings, deployment, Git staging/commit/push/merge or physical flashing changed.

## Local validation results

- PlatformIO `esp32cam` private-client build and `esp32cam-diagnostics` dummy-only build: passed.
- New native fault-injection runner compiles the actual `DeadlineClient` implementation against synthetic SDK outcomes: passed DNS success/failure/timeout/late completion, TCP timeout, certificate rejection, handshake timeout, TLS allocation failure, fragmented heap admission, bounded large writes, no reconnect over an aging signed body, preview preemption, clock/deadline budgets, log rate gates and binary-safe hashing. This is simulated transport testing, not a physical network test.
- Existing native IoT-04 lifecycle/HMAC vectors: passed.
- Existing IoT-01 camera/pins/decoder baseline and 10 synthetic QR image decodes: passed.
- Synthetic private configuration validators: 11 IoT + 8 Wi-Fi tests passed.
- Firmware benchmark analysis: 2 tests passed. Firmware browser-script regression: passed.
- Relevant Laravel IoT/QR/check-in suite: **216 tests / 2,038 assertions passed** (isolated testing database).
- `git diff --check`: passed. No files staged; unrelated untracked files preserved.

Hardware acceptance is pending. None of these results establishes the cause of the reported physical outage or proves hosted preview throughput.
