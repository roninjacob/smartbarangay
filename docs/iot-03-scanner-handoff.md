# IoT-03 scanner pairing and verification handoff

## Scope and defaults

IoT-03 adds IoT Scanner to the existing Admin QR Verification screen. Laptop Camera remains the default. `QrTicketVerifier` remains authoritative. Valid results use the existing success modal and 1.5-second delayed navigation to the server-resolved reservation.

The API remains disabled by default through `config/iot.php`. IoT requires database-backed browser sessions so device requests can check session existence and lifetime. Other session drivers fail closed. The new schema has only been applied to isolated SQLite databases.

## Browser endpoints

These endpoints use the existing authenticated, active, verified Admin group. POST actions require CSRF and session locking. Logout uses the same session lock so an in-flight IoT poll cannot write its session after logout invalidates it. Responses use private/no-store caching.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/admin/iot-scanner/devices` | Non-revoked provisioned scanners and availability |
| POST | `/admin/iot-scanner/challenge` | Accept `device_id`, replace the owned lease, return pairing SVG and expiry |
| POST | `/admin/iot-scanner/poll` | Renew heartbeat, report state, claim at most one result |
| POST | `/admin/iot-scanner/disconnect` | Delete owned pairing/events and clear pairing/check-in evidence |

The temporary pairing QR contains `SBP1:` plus 64 cryptographically random lowercase hexadecimal characters. It contains no session ID, device key or Resident information. Storage retains only its SHA-256 hash. The challenge expires after 90 seconds and is cleared after one successful confirmation. Replacement supersedes the previous challenge.

A pairing lease lasts at most ten minutes and requires a browser heartbeat within fifteen seconds. Polling runs approximately once per second. Hidden pages disconnect and abort pending work. Successful reservation handoff retains the existing five-minute check-in evidence while the old lease expires through its heartbeat. Explicit disconnect clears that evidence.

The connected state describes an authorized pairing. Physical scanner/network liveness requires future firmware heartbeat support. Shared browser-session tabs share ownership. Separate sessions, including another session belonging to the same Admin, cannot claim results.

## Signed device contracts

IoT-02 exact-body HMAC, headers, database replay nonces, TLS, request-size limits and IP/device throttles remain authoritative. The signed canonical path must exactly match the requested path. The existing probe is preserved.

`POST /api/iot/v1/pair` accepts exactly these JSON members.

```json
{
  "challenge": "SBP1:<64 lowercase hexadecimal characters>",
  "boot_id": "<32 lowercase hexadecimal characters>"
}
```

The authenticated device must match the selected scanner. Successful atomic confirmation returns `ok`, a server-issued `pairing_id` and `expires_at`. Confirmation is one-time.

`POST /api/iot/v1/scans` accepts exactly these members.

```json
{
  "pairing_id": "<server-issued UUID>",
  "boot_id": "<same identifier used during pairing>",
  "scanner_session": 1,
  "sequence": 1,
  "captured_at_ms": 1794000000000,
  "payload": "SBQ1:<lowercase UUID v4>"
}
```

Numeric members must be JSON integers. Session/sequence range is 1 through 2147483647. Capture time is Unix milliseconds with server age `0 <= age < 5000`. Future/stale captures, backwards capture times, old sessions and duplicate/out-of-order sequences are rejected. New boot identifiers require new pairing. An increased scanner session can restart its sequence.

Device success returns only `ok`, an opaque `event_id` and server-derived `valid`. It reveals no Resident identity or reservation destination. Unknown/ineligible tickets return `valid=false`. Errors are bounded and generic, using HTTP 401/403, 409, 422, 429 or 503 as appropriate. Browser CSRF failure uses 419. A transport failure conveys no validity decision.

## Storage, concurrency and lifecycle

The forward migration `2026_11_07_000031_create_iot_scanner_pairings_and_events.php` creates two tables.

- `iot_scanner_pairings` has one row per device and a unique browser-session hash. It stores an encrypted session identifier, hashed binding/challenge/password fingerprint, credential version, deadlines and monotonic counters.
- `iot_scan_events` stores encrypted temporary payloads and a unique lease/boot/session/sequence identity. It retains capture, expiry and consumption metadata without Resident profile details.

Device, account and pairing locks use consistent ordering. Transactions and unique constraints enforce exclusive ownership. Result claim uses a locked row plus a compare-and-set consumption update. Concurrent polling can deliver a result only once. Consumption clears the stored payload. Expired events cannot establish evidence. A minute-based scheduled command removes expired rows when IoT is enabled.

Every operation rechecks device revocation/version, current Admin eligibility, password fingerprint, session existence/lifetime and lease/heartbeat deadlines. Logout/session deletion, account loss, unpairing and rotation invalidate access. Verification is repeated at delivery to reject tickets that became ineligible after capture.

Only delivery to the correct browser establishes the existing `CheckInReservations::evidence()`. Explicit arrival confirmation still performs check-in. Payment, completion and QR consumption keep their existing independent gates.

## Validation and deployment boundaries

`IotScannerIntegrationTest` uses isolated SQLite and synthetic fixtures. It covers authentication, ownership, replay/expiry, encrypted storage, revocation, fresh single-use delivery and unchanged business records. Independent concurrent PHP processes exercise pairing and result-claim races. Existing IoT-02, QR, check-in and completion tests remain regression coverage. JavaScript tests cover camera release, mode switching, stale-result suppression and success-modal navigation.

Actual browser QA uses a separate loopback-only process, disposable SQLite, synthetic accounts and simulated signed device requests. Private QA harness files/evidence remain outside htdocs and Git. Physical camera capture and separate-browser-context limitations are reported explicitly.

Before real rollout, the owner must review the migration, take the normal backup, resolve the existing Apache source-exposure deployment blocker and approve a controlled migration/configuration change. The feature flag remains disabled in the normal application.

## Future firmware integration

IoT-04 must implement these exact signed contracts, pairing-QR decoding, boot/session/sequence handling, accurate capture timestamps, clock synchronization, TLS certificate validation and conservative timeout feedback. ESP32-CAM decoding and OLED/LED/buzzer behavior require physical hardware acceptance. This phase changes no firmware or hardware.

## Rollback

Keep IoT disabled to retain Laptop Camera while preventing device use. Review the exact phase diff before reverting code. No real migration has run, so this checkpoint requires no owner-database rollback. Stop the disposable QA process independently and preserve evidence before discarding its database. Any later real-schema rollback requires a separately reviewed backup/rollback plan.
