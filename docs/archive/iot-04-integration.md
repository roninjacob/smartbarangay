# IoT-04 firmware integration and acceptance

Implemented locally on November 9, 2026, against `feature/iot-integration` at
`3083fbe`. This extends the committed IoT-03 implementation. All changes remain
unstaged and uncommitted. Laravel business logic, migrations and the real device
API configuration are unchanged.

## Changed files

Six modified files and twelve new files form the intended checkpoint. The full
repository prefix for every entry is `C:\xampp\htdocs\smartbarangay\`.

| State | Exact repository-relative path |
| --- | --- |
| Modified | `firmware/SmartBarangay-ESP32-CAM-Test/.gitignore` |
| Modified | `firmware/SmartBarangay-ESP32-CAM-Test/README.md` |
| Modified | `firmware/SmartBarangay-ESP32-CAM-Test/platformio.ini` |
| Modified | `firmware/SmartBarangay-ESP32-CAM-Test/src/main.cpp` |
| Modified | `firmware/SmartBarangay-ESP32-CAM-Test/test/iot01-http.cpp` |
| Modified | `firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot01.py` |
| New | `docs/iot-04-integration.md` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/include/iot.example.json` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_client.h` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_crypto.h` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/include/iot_protocol.h` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/scripts/private_iot.py` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/src/iot_client.cpp` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/test/iot04-contract.cpp` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/test/iot04-vectors.json` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/test/run_iot04.py` |
| New | `firmware/SmartBarangay-ESP32-CAM-Test/test/test_private_iot.py` |
| New | `tests/Feature/IotFirmwareContractTest.php` |

## Implemented workflow

The existing AI-Thinker ESP32-CAM quirc worker recognizes strict `SBP1:` pairing
challenges and `SBQ1:` lowercase UUID-v4 tickets. Pairing and ticket classification
are separate. Other data stays local. A format match alone cannot establish
validity.

The communication worker uses the existing IoT-02 exact-body protocol. Its
canonical input has eight LF-separated lines and no trailing LF. Keys are decoded
from 64 lowercase hex characters to 32 binary bytes before HMAC-SHA256. Each
request gets a fresh 128-bit hardware-random nonce, current UTC seconds, configured
device UUID/key version and SHA256 of the exact transmitted JSON bytes.

The pair request contains only `challenge` and the boot's random 32-hex `boot_id`.
The successful server response supplies the temporary pairing UUID and expiry.
Ticket requests contain that UUID, the same boot ID, monotonic scanner-session and
sequence counters, UTC capture milliseconds and the exact ticket credential.
Session/sequence overflow is rejected instead of wrapped for transmission.

The camera task enqueues one latest observation without waiting on networking.
A separate FreeRTOS task performs HTTPS. Its queue is bounded to one observation,
responses to 768 bytes and request bodies to 1024 bytes. Received JSON must match
the expected typed success contract. Pairing expiry accepts UTC ISO8601 only.
Payloads and pairing IDs remain in volatile RAM. Nothing is persisted to device
flash by this client.

The existing Admin Connected label represents an authorized pairing lease. It
does not prove continuous physical scanner reachability. No new device heartbeat
endpoint is introduced in IoT-04.

SNTP must have actually synchronized before signing. A stale synchronization
(15 minutes), Wi-Fi loss, mode generation change, expired lease, stale capture or
failed authentication prevents successful handoff. Time is resynchronized every
five minutes while connectivity permits. Device metadata reports clock/connection
state, numeric HTTP status and numeric TLS status, without credentials.

The capture freshness budget is five seconds, including decoding and transport.
Socket/connect/handshake timeouts are finite, and deadline checks reject late
results. The underlying framework DNS/TLS call can outlast the application
deadline before returning. It runs outside the camera task and its late result
is discarded. Physical timing and memory/PSRAM stability still require measurement.

There are no automatic HTTP retries. After an uncertain outcome the lease is
cleared and a new pairing QR is required. HTTP 401/403 blocks further offers in
the authentication-failed state. HTTP 429 imposes a 60-second cooldown. Other
errors use a three-second cooldown. Duplicate frames remain latched beyond the
old five-second QR notification cooldown. Reconnect alone cannot resubmit the
same stationary code. A new pairing challenge is the recovery path.

WiFiClientSecure uses the configured trusted CA and HTTP URL hostname. Redirects
are disabled. No insecure TLS mode or HTTP fallback exists. Framework debug logs
are compile-time prohibited in enabled private builds.

## Private build configuration

Existing Wi-Fi configuration remains at this external path.

`C:\Users\ronin\.smartbarangay\firmware\esp32cam\smartbarangay_wifi_private.h`

Optional IoT configuration belongs here, independently of Laravel `.env`.

`C:\Users\ronin\.smartbarangay\firmware\esp32cam\smartbarangay_iot.json`

The real IoT JSON was absent during this task and was not created. The tracked
`firmware/SmartBarangay-ESP32-CAM-Test/include/iot.example.json` is disabled and
contains placeholders. After deployment/provisioning approval, the owner privately
supplies exactly these fields.

| Field | Required value when enabled |
| --- | --- |
| `enabled` | JSON boolean `true` |
| `host` | Approved DNS hostname or IPv4 address, without scheme/path/query |
| `port` | Integer 1–65535, normally 443 |
| `device_id` | Provisioned lowercase UUID v4 |
| `key_version` | Integer 1–999999999 matching the server |
| `key_hex` | Independent provisioned 64-lowercase-hex signing key |
| `root_ca_file` | Absolute external path to the trusted PEM CA certificate |

`scripts/private_iot.py` rejects malformed/duplicate JSON keys, invalid hosts,
placeholder credentials, CA private keys, invalid certificates and locations
inside htdocs. Invalid enabled configuration fails the build with values withheld.
Missing/disabled configuration leaves the API client disabled. Diagnostics never
loads this configuration, even when it exists.

The script atomically creates `smartbarangay_iot_private.generated.h` beside the
private JSON and passes only its directory and an enable macro to the compiler.
Key material is absent from build arguments. This directory inherits the existing
private Wi-Fi directory's access controls. Keep it restricted to the owner/SYSTEM.
Recheck permissions before provisioning on another laptop.

Build artifacts contain compiled credentials, including Wi-Fi, and must remain
private. The verification builds used an external `PLATFORMIO_BUILD_DIR`. Git
ignore rules cover local credential names, `.pio`, Python caches and test binaries.
Ignoring files does not make an unsafe web root safe.

## Real-environment preflight and approval blockers

The following was verified read-only against the current development environment.

| Check | Result |
| --- | --- |
| MariaDB | 10.4.32, database connection works |
| Real IoT API | Disabled |
| Browser sessions | Database driver, sessions table exists |
| IoT device/nonce/pairing/event tables | Absent |
| Migration `2026_11_07_000030_create_iot_devices_and_request_nonces` | Pending |
| Migration `2026_11_07_000031_create_iot_scanner_pairings_and_events` | Pending |
| Configured APP_URL scheme | HTTPS, reachability/trust not established for hardware |
| HTTP HEAD `http://localhost/smartbarangay/composer.json` | **200, source/config exposure blocker** |
| HTTP HEAD firmware `platformio.ini` through `127.0.0.1/smartbarangay/` | 403 |
| HTTPS HEAD `https://localhost/smartbarangay/composer.json` | 000, trusted connection unsuccessful |
| `pio device list` | No serial device detected |

Only HTTP status was collected for protected-path checks. No private file bodies
or archives were retrieved. This is a bounded preflight, not a complete renewed
Apache audit. The legacy source URL must be fixed in a separately approved
configuration rollout. A successful denial for firmware does not resolve the
`composer.json` exposure. LAN/alternate-host HTTPS security remains to be accepted.

The owner must separately approve a backup and controlled application of the two
existing migrations, secure device provisioning, API activation and a public-only
trusted HTTPS endpoint reachable by the ESP32. No new migration is needed for
IoT-04. Keep database sessions and a shared rate-limit cache. Configure the existing
scheduler for nonce/expired scanner-state cleanup under the earlier IoT contracts.

The existing provisioning command is `php artisan smartbarangay:iot-device`.
Use its `--help` and the IoT-02 instructions during the approved rollout. Save the
credential JSON outside htdocs, then privately map its device/version/key into
the firmware configuration. Do not use APP_KEY as a device signing key.

Windows hosts entries alone do not give the ESP32 DNS resolution. The eventual
hostname must resolve on its Wi-Fi network and match the trusted certificate.
Do not use a public tunnel or disable certificate validation to force a demo.

## External outputs and wiring prerequisites

External outputs default to disabled. No buzzer pin or buzzer driver is enabled.
No wiring or hardware upload occurred. The two unidentified starter-kit buzzers
remain electrically unverified. Confirm active/passive type, voltage and current
before selecting a transistor driver, base/gate resistor and any required flyback
protection. Do not connect either directly to a GPIO based on appearance.

The camera mapping is preserved from the existing working firmware.

| Resource | GPIOs |
| --- | --- |
| Camera data | 5, 18, 19, 21, 36, 39, 34, 35 |
| XCLK/PCLK/VSYNC/HREF | 0, 22, 25, 23 |
| SCCB SDA/SCL, PWDN | 26, 27, 32 |
| PSRAM | 16/17 reserved |
| Built-in flash | 4, retained off |

Initial external LED candidates are GPIO13 green and GPIO14 red. GPIO2 yellow is
a **conditional candidate** because it is a boot-strapping pin. All three overlap
microSD functions. The current firmware does not initialize microSD. Confirm the
actual borrowed board, no SD usage and the MB programmer/header clearance before
approval. Avoid GPIO12's flash-voltage strap and all camera/PSRAM/programming pins.
Do not insert jumpers into a seated MB connector or force stacked headers.

After wiring approval, each individual LED would use this separate series path.

| LED | Provisional signal path |
| --- | --- |
| Green | GPIO13 → its own 220-ohm resistor → LED anode; cathode → GND |
| Red | GPIO14 → its own 220-ohm resistor → LED anode; cathode → GND |
| Yellow | GPIO2 → its own 220-ohm resistor → LED anode; cathode → GND, only after strap verification |

Power off before wiring. Confirm polarity, permissible GPIO current, common ground
and a stable regulated supply for the actual board. Do not feed 5V into a GPIO.
Verify boot/upload with the proposed GPIO2 load before enabling all outputs.
`SB_EXTERNAL_LEDS=1` requires the additional explicit
`SB_LED_WIRING_APPROVED=1` compile flag. Neither flag is set in this task.

Processing uses yellow, server `valid=true` green and server `valid=false` red.
Verdicts last two seconds. Unknown/network/TLS/auth/rate-limit states use a yellow
double-flash error pattern and never a red invalid-ticket verdict. Buzzer/OLED
extensions and a separate feedback ESP32 were not added to this milestone.

The strap/power constraints follow Espressif's
[ESP32 datasheet](https://documentation.espressif.com/esp32_datasheet_en.html) and
[hardware design checklist](https://docs.espressif.com/projects/esp-hardware-design-guidelines/en/latest/esp32/schematic-checklist.html).

## Validation evidence

| Validation | Final result |
| --- | --- |
| Default `esp32cam` build, private IoT client disabled | PASS |
| Dummy-only `esp32cam-diagnostics` build | PASS |
| Enabled-client synthetic private build | PASS, 65,616 bytes static RAM and 1,354,633 bytes flash |
| IoT-01 camera/pin/quirc baseline, event/HTTP tests | PASS, 10 synthetic QR images decoded |
| Native IoT-04 lifecycle/serialization/HMAC | PASS, 2 C++ vectors independently checked with Python |
| Python config/regression/host TLS tests | 21 passed |
| Firmware browser JS regression harness | Both safe and diagnostics policies passed |
| Focused Laravel IoT/QR/check-in selection | 142 tests / 1,088 assertions passed |
| Full Laravel suite | 1,184 tests / 11,730 assertions passed |
| Laravel JS suite | 34 tests passed |
| Targeted Pint, PHP syntax, diff whitespace | PASS |

The native crypto harness uses host OpenSSL. Enabled ESP32 compilation uses bundled
mbedTLS. Python loopback TLS tests accept explicitly trusted localhost certificates
and reject untrusted certificates and hostname mismatches. These tests do not
establish physical ESP32 TLS acceptance. Static checks verify CA validation,
HTTPS-only URLs, redirect rejection and bounded response/queue handling.

`IotFirmwareContractTest` freezes the test clock, uses SQLite `:memory:` only and
sends the exact reviewed native C++ JSON/HMAC vectors through real Laravel pair
and scan middleware/controllers. It proves matching boot identity, successful
Admin-bound handoff, no raw credential response and no implicit arrival/payment/
status history/QR use. Existing IoT-02/03 security and lifecycle tests remain green.

An initial overlapping test invocation collided with Laravel fake-storage files.
The focused and full PHP suites were rerun sequentially to obtain the final passing
results above. No application rule was changed to accommodate that test artifact.

## Actual browser QA, synthetic device traffic

The ChatGPT in-app browser used `http://127.0.0.1:8024` with a separate SQLite file,
database sessions, synthetic Admin/Resident/device/ticket and private QA logs.
API enabling was confined to that disposable test application. Device traffic
was simulated using signed local requests. The firmware itself was not flashed.

| Scenario | Observed result |
| --- | --- |
| Admin login, QR Verification, mode switching | PASS |
| Scanner selection, QR SVG and countdown | PASS |
| Signed pair request → Connected | PASS |
| Invalid scan | PASS, safe invalid result without redirect |
| Valid scan | PASS, existing result flow navigates to verified Reservation Details |
| Natural challenge expiry | PASS, expired message and regeneration action |
| Explicit disconnect, regenerate | PASS |
| Feature-disabled service failure | PASS, safe unavailable message |
| Widths 1440/768/390/320 | PASS, no document-level horizontal overflow |
| Console during normal pairing/handoff | No JS errors observed |
| Laptop Camera start/stop controls | PASS, pending access can be stopped |
| Live laptop camera decoding | Not exercised, camera permission remained pending |
| Physical ESP32 camera/TLS/LED/buzzer | Not exercised, no serial device/approved rollout |

Before explicit check-in the isolated database had 0 arrivals, payments, status
histories and used QRs. After the Admin checked the arrival checkbox and pressed
Confirm Check-In, it had exactly 1 arrival, 0 payments, 0 histories, 0 used QRs and
the reservation remained Ready for Pickup. Session isolation and authorization
are covered by the existing automated IoT-03 tests, rather than a new second-session
browser run in this milestone.

Evidence is private at `C:\Users\ronin\.smartbarangay\iot04-review`.
This includes build logs, full PHP JUnit/results, preflight JSON and screenshots
under `browser`. The disposable server and tab were closed after QA. The synthetic
database and fixtures remain available for review outside htdocs.

## Owner build/upload procedure after approval

Start by connecting the borrowed ESP32-CAM-MB with a data-capable USB cable,
without external outputs. Confirm the actual COM port before proceeding. These
commands are instructions for the owner; upload was not executed by this task.

```powershell
Set-Location 'C:\xampp\htdocs\smartbarangay\firmware\SmartBarangay-ESP32-CAM-Test'
pio device list
# Keep credential-bearing artifacts outside htdocs.
$env:PLATFORMIO_BUILD_DIR = "$env:USERPROFILE\.smartbarangay\firmware\build-iot04"
pio run -e esp32cam
# Only after a successful enabled-client build and explicit flash approval,
# replace COM_PORT below with the verified COM value, for example COM3.
pio run -e esp32cam --target upload --upload-port COM_PORT
pio device monitor --port COM_PORT --baud 115200
```

Confirm the build's redacted message says external client configuration validated.
A successful disabled-client build alone does not provide the connected demo.
Do not upload the synthetic test build or diagnostics with real tickets. Close
Serial monitors before uploading. Stop the monitor with Ctrl+C. Do not change
boot wiring until the exact MB programmer procedure is confirmed.

After the approved HTTPS/database/provisioning rollout, verify Wi-Fi and synchronized
time using safe metadata. Generate the pairing QR in the authorized Admin browser.
Scan it physically and verify Connected before presenting a synthetic Ready for
Pickup ticket. Measure the five-second capture/transport budget and inspect the
success popup/handoff. Confirm arrival explicitly, inspect unchanged payment/status/
QR-use state and repeat invalid/disconnect/stale/reboot tests. Add approved LEDs
only after this core test passes. Keep the unverified buzzer disconnected.

Run software checks independently from this firmware directory as follows.

```powershell
pio run -e esp32cam -e esp32cam-diagnostics
python -B test/run_iot01.py
python -B test/run_iot04.py
python -B -m unittest discover -s test -p 'test_*.py'
node --test test/qr03-web.test.cjs
node --test test/qr03-web.test.cjs --safe
```

Host native tests need the existing g++/OpenSSL tools and Python. No replacement
of the original standalone project is needed.

## Rollback and checkpoint

No original standalone firmware, physical flash, real database, `.env`, Apache,
hosts, firewall or Git history was changed. Preserve the original standalone
folder and its recovery material. To return the consolidated project to the
standalone behavior, privately set IoT `enabled` to false (or keep the optional
JSON absent) and rebuild the safe environment. Existing flashed binaries retain
their compiled configuration until an explicitly approved replacement upload.

For source rollback, review this milestone's diff against `3083fbe` and reverse
only its listed changes, retaining unrelated work. Do not reset or clean the
repository indiscriminately. The two unrelated initial untracked files remain
untouched. No new migration, device provisioning, real API activation, staging,
commit, push, merge or hardware flashing occurred.

**READY FOR OWNER/PLANNER REVIEW.** Software checks are complete. The physical
presentation demonstration still requires the approved infrastructure/database
rollout, private provisioning, connection/flash approval and on-device acceptance.
