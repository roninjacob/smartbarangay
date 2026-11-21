# Automatic ESP32-CAM scanning and private preview

This change uses the existing Laravel application, provisioned device credentials and HMAC protocol. No new device provisioning, production configuration changes, deployment or flashing has been performed.

## Normal workflow

1. Provisioned firmware connects to Wi-Fi, synchronizes UTC, and scans supported `SBQ1:` reservation credentials continuously. It no longer scans a pairing QR.
2. Signed HTTPS POST `/api/iot/v1/scans` omits `pairing_id`. Device credentials, timestamp, nonce and complete body signature remain mandatory. Device identifiers alone never authenticate.
3. Events are encrypted, expire five seconds after camera capture and are independent of an Admin connection. No resident information or credential appears in device responses.
4. Admin opens QR Verification, chooses IoT Scanner and connects to a provisioned scanner. This opens an exclusive, database-session/password-bound viewing lease; it does not authenticate the hardware or require camera pairing.
5. AJAX polling delivers fresh events captured after this viewing lease began, at most once. Laravel revalidates the ticket and establishes the existing session-bound check-in evidence. Existing success feedback and reservation navigation remain in charge.
6. Confirm Check-In, payment and completion remain separate explicit actions. Scanning and previewing perform none of these actions.

Legacy `/pair` and paired scan requests remain compatible for older clients, but the main Admin UI and updated firmware never use pairing QR codes.

## Security and retention

- Existing direct HTTPS enforcement, HMAC canonicalization, timestamp tolerance, unique nonces, device credential version and revocation checks remain active. Each business operation rechecks the locked device row.
- Automatic scan sequence/capture high-water marks, database uniqueness and a three-second same-payload cooldown reject duplicates even with a new HTTP nonce. Firmware latches a stationary ticket instead of repeatedly submitting it. Remove the ticket for at least two empty camera captures before presenting it again; the existing five-second capture repeat window also applies. A different ticket can be presented immediately.
- Signed `/api/iot/v1/status` runs approximately every five seconds, reports only allowlisted camera health and returns preview authorization. A changed boot resets sequence state only through a fresh signed heartbeat; older delayed heartbeats cannot roll back newer activity.
- Online means accepted authenticated activity within fifteen seconds; offline is inferred, not evidence of a particular hardware fault.
- Admin Start Preview enables a ten-second preview lease, refreshed only while the owning page polls. Disconnect, logout, password/account changes, device revocation, browser inactivity and lease expiry block images and uploads. Firmware learns stop state through its next control request or a rejected upload; its local preview deadline also expires.
- Signed `/api/iot/v1/preview` accepts JSON with `boot_id`, `lease_id`, positive `sequence`, UTC `captured_at_ms`, and base64 `image`. Request limit: 45,056 bytes; decoded JPEG limit: 32,768 bytes; maximum input dimensions: 640 x 480. Ordinary IoT requests retain their 1,024-byte limit.
- GD validates/re-encodes the JPEG, removes metadata and limits output width to 320 pixels. Missing GD fails closed with 503.
- Only the latest encrypted frame is written to the **local private disk** (`storage/app/private/iot-preview/<device UUID>.frame`). In hosting, Laravel source/storage must remain outside `public_html`. The disk is not publicly served. Admin GET `/admin/iot-scanner/frame` requires the owning live session and returns JPEG with no-store/nosniff headers. Frames expire five seconds after server receipt; uploads must already be less than five seconds old at acceptance.
- Existing scheduled `smartbarangay:prune-iot-scanner-state` removes expired automatic events and private frames. The scheduler must run every minute; access expiry is enforced even when cleanup is delayed. Stopping preview/disconnecting deletes the image immediately. Backups must also treat this directory as private transient material.
- Preview/control have separate bounded IP/device rate limits, preserving the existing scan budget. Admin scanner endpoints permit 240 requests/minute to accommodate result polling and protected frame retrieval.

## Firmware behavior and limits

- Uses the existing OV2640 capture, camera pins, quirc decoder, JPEG quality, VGA QR resolution and scan cadence. No FPDI, reservation or resident portal changes.
- Copies an existing JPEG into a single latest-frame PSRAM queue only when preview is authorized, at most once every 1.1 seconds. Oversized frames are skipped; no additional camera capture, conversion or network call is performed on the QR task.
- The communication worker prioritizes QR observations over preview, discards old frames, aborts an active preview transfer when a QR observation arrives, and keeps trusted-CA TLS with redirects disabled. A TLS handshake can still delay an outgoing scan briefly; the server freshness deadline continues to apply.
- Camera initialization/decoder failures are reported through signed status requests when Wi-Fi/time are available. Server/network failure uses bounded waits and retry backoff. Authentication failure stops requests until reset; rate limiting waits sixty seconds. Wi-Fi reconnection does not require a pairing QR.
- Approximately one frame/second is a ceiling, not measured hardware throughput. Shared-hosting latency, TLS handshakes, JPEG size and PSRAM pressure can lower this rate. QR capture/decoding remains independent of image uploads. No physical throughput/reliability claim is made by a successful build.
- Diagnostics remains dummy-only and never enables the private IoT client. External LEDs retain the existing wiring-approval gate. No OLED/buzzer pin assignments have been invented.

## Schema and local verification

Forward migration: `2026_11_10_000032_add_automatic_iot_scanning.php` adds automatic/preview lease metadata and independent `iot_device_states` and `iot_device_scan_events` tables. Existing paired events and all business tables remain intact. No real MariaDB migration has been run.

1. Verify your local `.env` points to an isolated local test database, not production. Use synthetic accounts/tickets only. Take a private backup before any local schema update.
2. In `C:\xampp\htdocs\smartbarangay`, run `php artisan test --compact --filter=IotAutomaticScannerTest` (the suite explicitly uses in-memory SQLite). Run `node --test tests/Js/*.test.*` and `npm run build`.
3. Only for a verified local database intended for this test, apply pending schema with `php artisan migrate`. Review `php artisan migrate:status` first; do not blindly apply unrelated pending migrations. This command is a future owner action, not something executed by this task.
4. Use the existing trusted-HTTPS development entry point and database-backed sessions. Enable the existing IoT feature only in that isolated local environment. Do not disable TLS verification or point a production-provisioned firmware build at a different server with copied production credentials.
5. Build independently from `firmware\SmartBarangay-ESP32-CAM-Test` using `pio run -e esp32cam` and `pio run -e esp32cam-diagnostics`. Existing private build-time configuration remains outside htdocs/Git; inspect only the safe status, never print generated credential headers. Build artifacts contain credentials and stay in the existing external build directory. Do not share firmware binaries.
6. Physical upload is a separate owner step after verifying the target hostname, CA and synthetic device provisioning. No upload command is run automatically. Do not flash diagnostics with real credentials/tickets.
7. With compatible firmware connected to the isolated HTTPS app, test Admin > QR Verification > IoT Scanner > Connect scanner. Verify online/last seen, scan a synthetic reservation QR without pairing, observe success handoff, then manually Confirm Check-In.
8. Start preview explicitly: verify actual camera pixels, at 1440/768/390/320 widths. Stop, disconnect, hide the browser tab and expire/logout the Admin session: the image must disappear/be refused, with no public image URL. Verify invalid/completed tickets never check in; replayed requests are rejected; a second Admin/session cannot view another session's scans/images.
9. Observe device memory, QR detection latency and achieved frame rate on physical hardware before accepting presentation readiness. If oversized JPEGs or latency prevent preview, keep preview off while retaining QR operation; do not lower authentication or TLS requirements.

## Rollback and checkpoint

Changes are uncommitted/unstaged. No production records, secrets, hosting configuration or hardware were changed. Before any future deployment, back up database/configuration privately, deploy compatible code and forward migration under owner control, then build/upload matching firmware. To roll back an activated environment, disable the IoT feature, stop previews, restore the previously reviewed code/firmware and retain the added tables unless a separate reviewed rollback explicitly approves dropping transient data. Do not reset Git or run migration rollback against production automatically.

## Changed-file manifest

Created:
- `app/Models/IotDeviceScanEvent.php`
- `app/Models/IotDeviceState.php`
- `app/Services/IotAutomaticScanner.php`
- `database/migrations/2026_11_10_000032_add_automatic_iot_scanning.php`
- `docs/iot-automatic-scanning.md`
- `tests/Feature/IotAutomaticScannerTest.php`
- `tests/Js/iot-scanner.test.mjs`

Modified:
- `app/Console/Commands/PruneIotScannerState.php`
- `app/Http/Controllers/Admin/IotScannerController.php`
- `app/Http/Controllers/Api/IotScannerController.php`
- `app/Http/Middleware/AuthenticateIotDevice.php`
- `app/Http/Middleware/LimitIotRequestBody.php`
- `app/Models/IotScannerPairing.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/IotDeviceAuthentication.php`
- `app/Services/IotScannerSessions.php`
- `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_client.h`
- `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_protocol.h`
- `firmware/SmartBarangay-ESP32-CAM-Test/src/iot_client.cpp`
- `firmware/SmartBarangay-ESP32-CAM-Test/src/main.cpp`
- `firmware/SmartBarangay-ESP32-CAM-Test/test/iot04-contract.cpp`
- `firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot01.py`
- `resources/js/iot-scanner.js`
- `resources/views/admin/qr-verification/index.blade.php`
- `routes/api.php`
- `routes/web.php`

The IoT-01 baseline runner now accepts the original reviewed CRLF checksums when Git checks out the same library text using LF. Library contents and camera/decoder baseline checksums were not changed.

Unrelated existing untracked files remain untouched and unstaged. Generated firmware remains excluded from Git and outside the web tree; private build configuration and source credentials were not opened or printed.

## Verification evidence (local, November 10, 2026)

- Final full Laravel suite: **1,232 passed / 12,060 assertions** using isolated testing configuration.
- New automatic-scanner suite: 13 scenarios, including signed pairing-free scans, once-only handoff, replay/tamper/revocation rejection, session ownership, capture expiry, frame re-encoding/retention, logout/password/account invalidation and heartbeat clock-skew handling. These scenarios are included in the final full run.
- JavaScript: **39 passed**, including 5 new IoT UI harness cases for connection, protected preview, missing frames, hidden-page cleanup and safe success handoff.
- Vite production build, targeted Pint, IoT route listing and diff whitespace checks passed.
- PlatformIO safe-default/private-client and dummy-diagnostics environments compile independently. No upload command executed.
- Native IoT-04 protocol/HMAC tests, IoT-01 camera/decoder baseline and 10 synthetic quirc image decodes passed. Private-configuration validators passed 11 IoT and 8 Wi-Fi synthetic tests; they do not inspect real credentials.
- No live browser/hardware acceptance, physical frame-rate measurement, MariaDB migration, hosting deployment or Git checkpoint has been performed. Automated DOM tests are not a substitute for those checks.
