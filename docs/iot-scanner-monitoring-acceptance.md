# IoT scanner and monitoring acceptance

## Local checkpoint

Implemented on existing `main` at `4277233`, preserving the earlier uncommitted HTTPS diagnostic changes. All work remains unstaged and uncommitted. This report covers the scanner and dedicated IoT Dashboard only.

### Completed and automated-tested

- Selecting a scanner automatically acquires the existing exclusive Admin session lease and starts private preview. Pause and recovery Resume remain available.
- Device/mode changes, hidden tabs, page departure, authorization failure and lease expiry stop viewing. Browser mutations are serialized, late responses are discarded and image URLs are revoked.
- Preview becomes LIVE only after a bounded JPEG decodes. Signed capture time, server frame age, response/decode elapsed time and an independent expiry timer prevent stale images remaining LIVE. Older uploads cannot restart a five-second capture lifetime. Legacy frames without capture metadata fail closed.
- Existing valid-ticket confirmation and reservation handoff remain. Verification never checks in, pays or completes a reservation. Existing laptop camera behavior remains covered by regression tests.
- Separate Admin IoT Dashboard exposes actual authenticated heartbeat, uptime, firmware, camera state, RSSI, internal heap/largest block, PSRAM, allocation failures, attempt timings and preview/HTTP measurements. Missing legacy metrics display `Not reported`.
- Accepted scan/valid/invalid counters persist after short-lived handoff events are pruned. They start when monitoring first records a scan. Duplicate/replayed submissions are excluded. Counts are verification results, independent of explicit check-ins.
- Diagnostic history stores safe categorical observations only, paginated 15 rows, restricted to seven days and immediately capped at 500 rows per device. The existing scheduled pruning command also observes stale heartbeat once per outage. Offline means no heartbeat within 15 seconds. Its physical cause remains unknown until telemetry supplies evidence.
- Read-only dashboard polling runs every five seconds while visible. Existing signed status calls carry allowlisted telemetry without another device endpoint. Existing HMAC, nonce replay rejection, TLS validation, revocation, private image storage and rate budgets remain enforced.
- Firmware reports capture, JPEG conversion, grayscale, candidate detection, decode, preview-copy and HTTPS durations. Mean/p95 use a fixed 32-attempt window with 64-bit summation. Counters reset on device boot. No raw QR payload is included in telemetry.
- VGA capture is retained. The diagnostics-only 128 KiB snapshot buffer is omitted from normal operation. TLS connection reuse, bounded deadlines and one PSRAM preview body allocation from the preceding hardening work are preserved. No preview backlog is added.

## Database requirement

New additive migration `2026_11_10_000033_add_iot_monitoring_telemetry.php` adds nullable telemetry/heartbeat/count origin/offline/handoff/capture metadata and zero-default scan counters to `iot_device_states`. It creates `iot_device_diagnostics` with device FK, categorical details, event time and a device/time index.

Existing business tables and historical reservation records are unchanged. The migration has only run against isolated SQLite during tests and private QA setup. MariaDB/Hostinger runtime execution is pending owner-controlled backup and rollout. Deploying code before this migration causes the device services to fail closed with safe errors. Old firmware can continue sending its original heartbeat after the schema update and will have unknown telemetry fields.

## Validation evidence

- Full PHP suite passed with 1,243 tests and 12,174 assertions. Test databases were isolated SQLite. All 44 application JavaScript tests passed.
- Focused PHP tests cover automatic preview, capture-time expiry, bounded images, revoked/invalid device, authorization, inactive/unverified Admin, replay/rate budgets, telemetry schema, offline recovery, paginated read-only projection and bounded retention.
- JavaScript tests cover auto-selection, ordered reconnection, missing/expired/offline frames, expiry while polling stalls, hidden-tab cleanup, existing success handoff, dashboard pagination and safe text rendering.
- Both PlatformIO environments build. Normal environment uses the existing private configuration loader. Diagnostics remains dummy-only.
- Native tests compile actual transport fault injection, timing window and independent HMAC/lifecycle vectors. Ten synthetic QR images decode. Private config validators and benchmark-analysis tests pass.
- The exact production heartbeat format is bounded at 645 bytes under maximal test field widths against the existing 1024-byte API body limit.
- Vite, route inspection, targeted Pint and diff checks pass.

### Browser and physical verification limits

A private synthetic SQLite environment and signed HTTP simulator were prepared outside htdocs. After Windows Chrome interaction was interrupted by window/input changes, QA continued successfully through the available in-app browser against the same loopback test server.

| Actual browser scenario | Result |
| --- | --- |
| Laptop Camera default UI and switching to IoT | PASS for controls/mode, physical webcam capture not exercised |
| Select synthetic provisioned device, connect and automatically display decoded private JPEG with LIVE | PASS |
| Pause hides frame, Resume reconnects, leaving scanner ends preview viewing | PASS |
| Simulator heartbeat stops, scanner becomes Offline and removes LIVE/image | PASS |
| Signed invalid QR request displays Invalid QR Ticket and stays on scanner | PASS |
| Signed valid real-format synthetic ticket opens the matching Reservation Details | PASS |
| Explicit Confirm Check-In checkbox/button remains available after verification | PASS for availability, action left unsubmitted |
| Dedicated IoT Dashboard selection, telemetry, unknown values and diagnostic history | PASS |
| Captured browser warnings/errors on scanner/dashboard/details | None |

Both scanner and dashboard document widths were 1425/753/375/305 at viewport widths 1440/768/390/320, matching each viewport's scrollbar allowance. There was no page-level horizontal overflow. Temporary viewport overrides were reset. Screenshots are private under `C:\Users\ronin\.smartbarangay\iot16-review-20261110-202943`, including `scanner-desktop.png` and `dashboard-mobile.png`. The synthetic JPEG is clearly labelled QA. It proves the authenticated upload/render path, while physical camera recognition remains pending. The short success modal's delayed handoff contract is covered by executable JavaScript tests and the resulting reservation navigation was observed. A screenshot of that brief modal was not captured.

Cross-Admin isolation, lease expiry, replay, revocation, pagination bounds and hidden-tab cancellation are covered by automated tests. Independent concurrent browser sessions and session-expiration UI were not separately exercised in this browser run. The new migration was never applied to the real MariaDB database.

Final isolated data inspection found two accepted scans, one valid and one invalid, with zero check-ins, payments or status-history mutations. The reservation remained Ready for Pickup and its QR remained unused. Owned QA PHP processes were stopped after verification.

No ESP32-CAM was flashed or physically measured. The earlier 0.9–1.1 second attempt baseline is owner-supplied historical context. Current average/p95, hosted QR-to-UI latency, refresh FPS, memory stability and before/after speed gains remain unmeasured. Synthetic timing values in tests validate calculations only.

## Local review commands

From `C:\xampp\htdocs\smartbarangay`, run these against the repository's isolated testing configuration.

```powershell
php artisan test --compact --filter='IotMonitoringTest|IotAutomaticScannerTest'
node --test tests/Js/*.test.*
python firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot_performance.py
python firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot_transport.py
pio run -d firmware/SmartBarangay-ESP32-CAM-Test -e esp32cam -e esp32cam-diagnostics
npm run build
php artisan route:list --path=iot --except-vendor
git diff --check
```

For browser acceptance use an isolated database with synthetic Admin, provisioned device and eligible reservation. Open QR Verification, choose IoT Scanner and select the device. Confirm automatic private image display, Pause/Resume, switching device/mode, hidden-tab expiry, offline recovery and the success handoff. Inspect the dedicated IoT Dashboard and pagination. Check widths 1440, 768, 390 and 320, keyboard focus and browser console. A signed synthetic JPEG verifies the upload/display path. Physical camera accuracy requires the hardware run below.

## Owner-controlled deployment requirements

1. Review the uncommitted diff and complete browser acceptance before approving a Git checkpoint.
2. Back up the existing database and private image storage. Confirm existing IoT migrations through 000032 are already present.
3. During a controlled maintenance window, install the matching Laravel code and built Vite assets, then apply the additive migration through the normal deployment process. The reviewed targeted command is shown below for the owner. It has not been executed against real data.

```powershell
php artisan migrate --path=database/migrations/2026_11_10_000033_add_iot_monitoring_telemetry.php --force
```

4. Preserve existing application key, database sessions, enabled IoT configuration, trusted HTTPS hostname/CA and private credentials outside public_html. Do not provision another device merely to update firmware.
5. Confirm Hostinger's scheduler runs the existing Laravel schedule every minute for bounded history/frame cleanup. Immediate 500-row limits also apply without cron. Read access always rejects expired frames even before disk cleanup.
6. Smoke-test Admin authorization, private frame access, telemetry schema, current device heartbeat and explicit manual check-in with synthetic records. Rebuild/upload only the normal firmware after backend acceptance.
7. If rollout fails, disable the affected IoT workflow and restore the previous matching application/assets. The additive columns may remain until an owner-reviewed recovery. Do not automatically roll back or erase diagnostic history.

## Firmware upload and hardware acceptance

Owner commands from the repository root, after backend migration and browser verification, using the actual detected port.

```powershell
pio device list
pio run -d firmware/SmartBarangay-ESP32-CAM-Test -e esp32cam
pio run -d firmware/SmartBarangay-ESP32-CAM-Test -e esp32cam -t upload --upload-port COM3
pio device monitor --port COM3 --baud 115200
```

Close any serial monitor before upload. Use the existing private Wi-Fi/IoT configuration. Do not upload diagnostics for real tickets. Build artifacts embed device credentials and remain outside htdocs/Git. No upload was performed in this task.

1. Power the scanner from a stable standalone 5 V USB adapter on its configured 2.4 GHz Wi-Fi. Verify OV2640 initialization, PSRAM, synchronized time and signed HTTP 200. Laptop USB is only needed for uploading/serial diagnostics.
2. Sign in as an eligible Admin, select the device and confirm an actual changing camera image with LIVE shown only while fresh. Preview should stop when the page hides or disconnects.
3. Present a synthetic real-format SmartBarangay ticket on a phone and paper. Confirm the success feedback and correct Reservation Details. Inspect eligibility, then explicitly Confirm Check-In. Scanning alone must leave check-in/payment/completion unchanged.
4. Test malformed/forged, cancelled and completed tickets. Test repeat scan and a previously checked-in request. An already checked-in request must never gain another arrival record. Ticket usability follows the existing server lifecycle, with signed request/preview expiry enforced separately.
5. Hold one ticket stationary, remove it for at least two empty captures, wait past the repeat cooldown, then present again. Confirm continuous sampling discovers new tickets and backend duplicate rules still apply.
6. Interrupt Wi-Fi and restore it, then reboot. Verify fresh authenticated heartbeat recovery, camera readiness and old preview expiration. Revocation should stop authenticated operations. Authentication failures require owner correction/restart according to existing firmware behavior.
7. Run at least 30 minutes with preview off/on. Record dashboard metrics and safe `[IoT-DIAG]` categories, timings and heap/largest-block/PSRAM only. Measure actual phone/printed recognition success rates, mean/p95 attempt latency, QR-to-browser handoff age and preview frames per second. Compare identical lighting/distance/power conditions before claiming improvement.

## Acceptance status

| Area | Status |
| --- | --- |
| P0 automatic workflow, expiry and manual check-in boundaries | PASS automated and synthetic browser, PARTIAL physical acceptance |
| P1 monitoring, strict telemetry, authorization and bounded history | PASS automated |
| QR decoding calculation/transport regressions and builds | PASS simulated/native/build |
| Physical reliability and performance improvement | PARTIAL, measurements pending |
| Desktop/mobile actual browser QA | PASS tested scenarios, limitations listed above |
| Production migration/deployment/firmware upload | PARTIAL, owner-controlled next steps |

Overall status is **PARTIAL — ready for owner review and controlled physical acceptance**.

## Exact repository file set

Modified existing files in this task, including the preserved earlier firmware edits in the two shared source files.

```text
app/Console/Commands/PruneIotScannerState.php
app/Http/Controllers/Admin/IotScannerController.php
app/Http/Controllers/Api/IotScannerController.php
app/Models/IotDeviceState.php
app/Services/IotAutomaticScanner.php
bootstrap/app.php
config/navigation.php
firmware/SmartBarangay-ESP32-CAM-Test/include/iot_client.h
firmware/SmartBarangay-ESP32-CAM-Test/src/iot_client.cpp
firmware/SmartBarangay-ESP32-CAM-Test/src/main.cpp
resources/css/qr-verification.css
resources/js/app.js
resources/js/iot-scanner.js
resources/js/qr-verification.js
resources/views/admin/qr-verification/index.blade.php
routes/web.php
tests/Feature/IotAutomaticScannerTest.php
tests/Js/iot-scanner.test.mjs
```

New task files.

```text
app/Http/Controllers/Admin/IotDashboardController.php
app/Services/IotMonitoring.php
app/Services/IotTelemetry.php
database/migrations/2026_11_10_000033_add_iot_monitoring_telemetry.php
docs/iot-scanner-monitoring-acceptance.md
firmware/SmartBarangay-ESP32-CAM-Test/include/iot_performance.h
firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot_performance.py
resources/js/iot-dashboard.js
resources/views/admin/iot/index.blade.php
tests/Feature/IotMonitoringTest.php
tests/Js/iot-dashboard.test.mjs
```

Preserved earlier uncommitted hardening files additionally include modified `include/iot_crypto.h`, new `include/iot_transport_policy.h`, `test/run_iot_transport.py` under the firmware project, and `docs/iot-https-diagnostics.md`. The unrelated LSP file, workspace file and ZIP remain untouched.
