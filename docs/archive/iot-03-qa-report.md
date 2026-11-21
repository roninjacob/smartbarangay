# IoT-03 implementation and Browser QA report

## Checkpoint

Branch `feature/iot-integration`, starting HEAD `a4d051d`. Implementation, tests and documentation remain unstaged and uncommitted. Normal application IoT defaults remain disabled. The owner/planner controls the Git checkpoint and any later real migration.

The implementation contract, migration, firmware requirements and rollback procedure are documented in [IoT-03 scanner handoff](iot-03-scanner-handoff.md).

## Browser test environment

Actual browser automation accessed a separate PHP development process bound only to `127.0.0.1:8023`. It used an isolated SQLite database, synthetic Admin/Resident accounts, one synthetic device and a synthetic Ready for Pickup reservation. No real QR credential or Resident record was used.

Private bootstrap, fixtures, signed-device simulator, screenshots and JUnit evidence are under `C:\Users\ronin\.smartbarangay\iot03-review`, outside htdocs and Git. The QA bootstrap forces SQLite, a synthetic application key, database sessions/cache, array mail, private logging and built Vite assets. Test-only instrumentation captures the synthetic pairing challenge for the signed-device simulator. That instrumentation is outside the application repository.

## Pages and interactions

| Scenario | Result | Observed evidence |
| --- | --- | --- |
| Isolated login and Admin dashboard | PASS | Synthetic Admin signed in through normal login |
| Admin sidebar to QR Verification | PASS | Correct scanner page and navigation state |
| Laptop Camera default and mode switching | PASS for UI | Camera-start action reached permission request, switching selected IoT and hid camera controls |
| Scanner selection | PASS | Provisioned synthetic device appeared and enabled pairing |
| Pairing QR and countdown | PASS | SVG loaded, waiting state displayed, countdown decreased |
| Signed device confirmation | PASS | Real HTTP HMAC request returned 200 and browser displayed connected state |
| Invalid QR | PASS | Signed scan returned `valid=false`, browser displayed Invalid QR Ticket and stayed on scanner page |
| Valid QR and reservation handoff | PASS | Signed scan returned `valid=true`, browser automatically opened the correct synthetic Reservation Details |
| Existing manual check-in evidence | PASS | Details displayed QR verified and explicit Confirm Check-In control |
| Pairing expiry | PASS | Isolated fixture deadline expired, QR disappeared and regeneration became available |
| Explicit Disconnect | PASS | QR cleared, disconnect became disabled and unpaired feedback appeared |
| Service unavailable and recovery | PASS | Isolated feature-off fault displayed safe service error, restoring QA configuration allowed pairing again |
| Default-disabled mode | PASS | IoT control disabled, Laptop Camera and Start Camera remained available |
| Console inspection | PASS | No JavaScript errors/warnings observed in inspected scanner/details states |
| Automatic business mutations | PASS | Reservation remained Ready for Pickup, QR Active, zero check-ins/payments/status-history rows |

The existing success-modal visibility/delayed-navigation contract also passes executable JavaScript tests. Browser handoff was observed twice. The short-lived modal was not successfully captured in a screenshot, so screenshots are evidence of the scanner states and resulting reservation page.

## Responsive checks

| Viewport width | Document scroll width | Result |
| --- | --- | --- |
| 1440 | 1425 | PASS |
| 768 | 753 | PASS |
| 390 | 375 | PASS |
| 320 | 305 | PASS |

Widths include the browser scrollbar allowance. There was no page-level horizontal overflow. Controls wrapped on narrow screens and the pairing QR fit its container. The temporary viewport override was reset after QA.

## Screenshot evidence

Evidence files in the private QA directory include the following.

- `invalid-scan.png`
- `pairing-desktop-1440.png`
- `pairing-tablet-768.png`
- `pairing-mobile-320.png`
- `expired-mobile-320.png`
- `service-error-mobile.png`
- `feature-disabled.png`
- `final-mobile-390.png`
- `final-reservation-details.png`

Pairing screenshots contain only synthetic, expired one-time challenges. They are private QA artifacts.

## Defects corrected and retested

- Guest authentication exceptions initially produced generic 503 responses on the new browser endpoints. They now preserve HTTP 401.
- Device/account/pairing lock ordering was aligned across challenge, device submission and browser consumption. Logout now shares the scanner's session lock.
- Millisecond freshness is enforced at selection and after verification. Slow verification cannot extend a scan's lifetime or establish late check-in evidence.
- Cleanup additionally checks capture milliseconds, preventing coarse SQL timestamp rounding from deleting a still-fresh event.
- Scanner status updates avoid repeatedly rewriting identical live-region text.
- The isolated QA harness was switched from inherited hot assets to built assets so development refreshes could not interrupt controlled browser scenarios.

No unresolved in-scope application defect was identified by the completed focused tests and browser checks.

## Explicit limitations

- Physical webcam capture/decoding was not completed. The in-app browser remained at a permission request. Camera UI and mode transitions were exercised, and simulated JavaScript tests verify track release, pending-permission cancellation and existing scan behavior.
- No ESP32-CAM was flashed or physically exercised. Signed synthetic device HTTP requests exercised the Laravel integration. Camera/OLED/LED/buzzer firmware acceptance belongs to the later hardware milestone.
- A second isolated browser-cookie context was not available in this QA session. Cross-Admin and same-Admin/different-session isolation were tested in PHP with distinct persisted browser sessions. Independent concurrent PHP processes verified exclusive pairing and single-use delivery.
- The connected label represents authorized pairing, not physical device liveness. Device heartbeat support is future firmware work.
- The known Apache source-exposure issue remains a separate deployment blocker. This checkpoint is an implementation review, not production rollout approval.
- Real MariaDB migration and runtime/concurrency acceptance remain pending a separately approved controlled rollout. Current database validation used isolated SQLite.

## Validation results

| Validation | Final result |
| --- | --- |
| Focused IoT-03 PHP tests | 30 passed, 239 assertions |
| IoT-02 and reservation/schedule/QR/check-in regressions including IoT-03 | 194 passed, 1,801 assertions |
| Independent document-hardening rerun | 30 passed, 243 assertions |
| Full PHP suite | 1,183 passed, 11,713 assertions |
| JavaScript tests | 34 passed |
| Vite production build | PASS |
| PHP syntax | PASS for all 15 changed/new non-Blade PHP files |
| Targeted Pint | PASS |
| Route inspection | PASS, six new endpoints and the preserved probe |
| `git diff --check` | PASS |
| Staging area | Empty |

The latest sequential full run completed in 278.07 seconds. Its JUnit output is saved privately under `date-hardening-20261107-193445/sequential-full-tests.xml`. The isolated QA server was stopped after browser verification. The limits chosen for this phase are a 90-second challenge, ten-minute lease and fifteen-second browser heartbeat, with database sessions required.

## Final date-fixture hardening

Two IoT-03 schedule fixtures and the private browser-QA seed previously used December 1, 2026. They now choose tomorrow in Asia/Manila. Both appointment-dependent tests also run at a frozen December 31, 2035 UTC clock, crossing into 2036 in Manila. Each case asserts the schedule remains future and is returned by the existing upcoming scope. All original reservation, privacy and QR lifecycle assertions are preserved.

Only these two single-process cases freeze their clock. Their synthetic browser sessions are created after advancing the clock, preserving coherent session-expiry checks. The first future-clock run exposed two stale-session fixture failures, which were corrected by creating fresh sessions. Pairing/event expiry, timestamp and independent-process race tests retain their existing clocks and pass unchanged.

An overlapping focused/full validation run produced seven document-test failures because those suites share Laravel's fake-storage directory. The document-hardening suite passed independently, and the full suite subsequently passed sequentially. Document implementation and assertions were preserved. Standalone PHP suites sharing fake storage should run sequentially.

The revised private seed was exercised in a new disposable SQLite database under `C:\Users\ronin\.smartbarangay\iot03-review\date-hardening-20261107-193445`. Existing QA records/evidence were preserved. Actual browser retesting passed login, device selection, pairing, signed invalid scan, signed valid scan and reservation handoff. The details showed tomorrow's appointment and explicit Confirm Check-In. The reservation remained Ready for Pickup, with zero check-in/payment/status-history rows and an unused QR. Console inspection found no JavaScript errors or warnings. Evidence is saved as `reservation-relative-date.png` in that private run directory.

This hardening changes the integration test, this report and the private seed. Application business logic, firmware and frontend source retain their preceding IoT-03 implementation. Existing physical-hardware/browser-context and deployment limitations above still apply.

READY FOR OWNER/PLANNER REVIEW — NOT YET COMMITTED

## Complete repository change set

Modified files are listed below. Paths are relative to the repository root.

```text
app/Http/Middleware/AuthenticateIotDevice.php
app/Services/IotDeviceAuthentication.php
bootstrap/app.php
docs/iot-02-device-api.md
resources/js/app.js
resources/js/qr-verification.js
resources/views/admin/qr-verification/index.blade.php
routes/api.php
routes/console.php
routes/web.php
tests/Js/qr-verification.test.mjs
```

New files are listed below.

```text
app/Console/Commands/PruneIotScannerState.php
app/Http/Controllers/Admin/IotScannerController.php
app/Http/Controllers/Api/IotScannerController.php
app/Models/IotScanEvent.php
app/Models/IotScannerPairing.php
app/Services/IotScannerSessions.php
database/migrations/2026_11_07_000031_create_iot_scanner_pairings_and_events.php
docs/iot-03-scanner-handoff.md
docs/iot-03-qa-report.md
resources/js/iot-scanner.js
tests/Feature/IotScannerIntegrationTest.php
tests/Fixtures/iot-scanner-race.php
```

There are 11 modified and 12 new repository files. No deleted files or firmware changes are included. Built assets and private QA artifacts remain outside the Git change set. No real MariaDB migration/data, Apache/XAMPP/hosts/firewall/PATH configuration, Git history or other project was modified. IoT-04 was not started.
