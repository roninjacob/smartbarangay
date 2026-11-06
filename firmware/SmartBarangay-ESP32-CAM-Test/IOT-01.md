# IoT-01 — ESP32-CAM security baseline and QR compatibility

Consolidation note: this is the IoT-01 implementation record. Current development
is in `firmware/SmartBarangay-ESP32-CAM-Test/`. Private `backups/` stays in the
preserved original standalone project; it is not part of Git or a test dependency.
See [current folder/recovery guide](../../docs/iot-project-consolidation.md).

Date: November 6, 2026. Scope: standalone firmware readiness only. Laravel stays
at `feature/iot-integration` / `602affb`. No device API, credential transmission,
board-to-board communication, database change, automatic check-in or completion.
No upload, serial connection or physical device test was performed for IoT-01.

## Preserved baseline and rollback

Before edits, 49 non-secret source/config/library/test/editor files were copied to
`backups/before-iot-01/` and SHA-256 verified. `backup-manifest.json` records them.
It also records the hash of the private header, which remains untouched in place.
Wi-Fi secrets, `.pio` binaries, earlier backups, Python caches and upstream Git
metadata were deliberately excluded. This is a source rollback, not a dump of the
borrowed device's flash. Retain the private header locally for a baseline rebuild.
The original standalone folder has no Git history. The consolidated copy belongs
to the enclosing SmartBarangay repository, without a nested Git repository.

For a deliberate rollback to PRE-IoT-01 QR-06 (not routine consolidation rollback),
from the consolidated project directory use the external private baseline:

```powershell
$baseline = 'C:\Users\ronin\OneDrive\Documents\PlatformIO\Projects\SmartBarangay-ESP32-CAM-Test\backups\before-iot-01'
Copy-Item -LiteralPath "$baseline\src\main.cpp" -Destination .\src\main.cpp -Force
Copy-Item -LiteralPath "$baseline\platformio.ini" -Destination .\platformio.ini -Force
Copy-Item -LiteralPath "$baseline\README.md" -Destination .\README.md -Force
Copy-Item -LiteralPath "$baseline\.gitignore" -Destination .\.gitignore -Force
Copy-Item -LiteralPath "$baseline\test\qr03-web.test.cjs" -Destination .\test\qr03-web.test.cjs -Force
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32cam
```

New IoT-01 header/tests/documentation may remain as unused records after rollback;
the baseline main.cpp does not include them. Library files were not changed and
remain verified against the complete backup. Restoring source/building does not
change the board. Any later flash requires owner permission and the correct board,
port and recovery arrangement. Old QR-06 exposes raw QR text and LAN images: use
synthetic data only if reverting. Never share firmware.bin/ELF/map containing Wi-Fi
configuration or treat a source backup as proof of the current on-device image.

## Safe versus dummy diagnostics builds

| Environment | Default | HTTP surface |
| --- | --- | --- |
| `esp32cam` | Yes | `/`, POST `/mode`, `/qr/status`; safe metadata only |
| `esp32cam-diagnostics` | Explicit opt-in | Adds `/qr/frame` and port 81 `/stream`; synthetic/dummy QR only |

Default `SB_CAMERA_DIAGNOSTICS=0` is defined in `include/iot_readiness.h`.
The diagnostic environment inherits the original board/platform/PSRAM flags and
sets the flag to 1. The default environment remains explicitly `esp32cam`.
Default firmware does not start the streaming server or register the frame route;
both camera handlers also deny access when disabled. Its browser page never
requests video/snapshots. Decoder capture remains active in QR mode.

Raw decoded bytes are not printed to Serial or included in HTTP status in either
environment. No payload/hash accessor is exposed from the event helper. Byte count,
format hint, event identity/age and existing timing/memory/capture counters remain.
The page does not equate decoding or format match with Laravel verification.

Remaining risks: the metadata/mode HTTP server is still unauthenticated LAN test
infrastructure. A LAN client can view metadata or change modes. Do not expose it
to the internet or use it as a production integration API. The opt-in diagnostic
build intentionally exposes images and can reveal a QR through the camera image;
it must never handle real resident credentials. A build flag cannot detect whether
someone presents a real QR. Device credentials, TLS, pairing and replay protection
are not implemented here. Compiled Wi-Fi configuration remains private.

## Exact bytes and format boundary

`smartBarangayFormat()` is a strict 41-byte ASCII format hint for
`SBQ1:<lowercase UUID v4>` (including UUID variant). It is not an authoritative
ticket validator: a matching synthetic QR is NOT issued/valid/eligible. Wrong
format codes still decode; they get `format_match=false`, not a business verdict.
No trimming, case conversion or URL/ID substitution is performed.

`test/iot01-vectors.json` contains ten synthetic cases: matching format, wrong
prefix, invalid hex UUID, uppercase, truncated, oversized, ordinary dummy, wrong
version, wrong variant and trailing newline. The PHP fixture generator uses the
already installed BaconQrCode dependency via an explicit autoload path, without
booting Laravel, reading .env or querying any database. It writes PGM/SVG fixtures
only to `.iot01-test/`. The host harness runs the existing quirc C sources against
the PGM images and compares exact decoded bytes/length and expected format hint.
This proves host-side encoder/decoder compatibility, not optical camera success.

## Scan identity and freshness

- Boot nonce: 128 random bits generated once after Wi-Fi starts, rendered as hex.
  It is a probabilistic reboot discriminator, never an authentication credential.
- Each actual QR-mode entry increments a scanner-session counter and clears the
  previous event. Requested mode changes immediately invalidate cached event state;
  repeating a request for the already-selected mode does not mint a new event.
- New identity is `boot_nonce:session:sequence`. Sequence increases only for a
  newly published decoded observation; polling never creates or refreshes it.
- Same bytes within 5000 ms are suppressed using exact byte comparison, replacing
  the prior FNV hash comparison. Their original timestamp is not refreshed.
- Different bytes publish immediately. A repeated stationary QR after the cooldown
  can publish again. This preserves the scan behavior; it is not server idempotency.
- Freshness lasts strictly less than 5000 ms while actively in QR mode. Status
  reports `fresh`, `expired` or `absent`; expiry clears the private repeat cache.
  Preview, switching, camera error and mode changes cannot present fresh events.
- Event age uses 64-bit monotonic ESP timer time, not wall time or wrapping millis.
  Browser success also expires locally if status polling stops; repeated same-ID
  responses cannot extend the local deadline. Mode changes/network failures clear
  success, and reconnecting cannot promote the pre-failure event again.
- Fixed 1024-byte private repeat storage replaces the old 1025-byte public text
  buffer. Empty/oversized/unusable observations invalidate the prior event rather
  than replaying it. There is no outbound credential queue or payload transport.

For future API integration: send exact bytes using a separate reviewed contract;
bind accepted events to a device/workstation/Admin session; atomically claim them;
enforce server TTL, replay/idempotency and fresh verifier checks. Counters, local
format hints and random event IDs alone cannot authorize an action.

## Camera and resource preservation

The camera initialization function/pins match the QR-06 backup byte-for-byte.
Preview: QVGA/JPEG quality 15, existing PSRAM/buffer modes and 125 ms pacing.
QR: VGA/JPEG quality 12, one frame buffer, existing RGB/grayscale/quirc path.
Original camera mutex, capture/return ownership and zero-wait snapshot publication
are retained. Default safe mode skips optional snapshot copying. Existing snapshot
allocation remains; there is no extra task or large image buffer. Decoder sources
and license are unchanged. Scan floor stays 1000 ms; 500 ms experiments stay off.

## Automated validation

Run from this project:

```powershell
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32cam -e esp32cam-diagnostics
python test/run_iot01.py
node test/qr03-web.test.cjs
node test/qr03-web.test.cjs --safe
python -B -m unittest discover -s test -p test_benchmark_analysis.py
```

The native tests require existing gcc/g++, PHP and the BaconQrCode autoloader.
In the consolidated folder the autoloader defaults to `../../vendor/autoload.php`;
`--autoload` can override it. A portable checksum manifest replaces dependencies
on private recovery files and the private Wi-Fi header.
They compile the actual event helper and existing quirc sources. HTTP host shims
execute the actual status serializer and route-registration functions extracted
from main.cpp for BOTH build policies; they do not emulate ESP-IDF concurrency or
networking. Tests cover credential absence, exact byte compatibility, bounds,
expiry, repeats, session/reboot identity, mode/clock rules, default/dev routes,
startup failure cleanup, plus browser polling/snapshot/error/cleanup regressions.
Python benchmark arithmetic tests remain separate from physical measurements.

Verified IoT-01 results:

- Both PlatformIO release environments built successfully; no upload target used.
- Default: static RAM 62,248 / 327,680 bytes (19.0%); flash 1,128,093 /
  3,145,728 bytes (35.9%).
- Dummy diagnostics: static RAM 62,280 / 327,680 bytes (19.0%); flash 1,132,961 /
  3,145,728 bytes (36.0%). These build figures do not measure runtime PSRAM headroom.
- Ten of ten actual host quirc image decodes matched exact synthetic bytes and
  expected format hints, including invalid-format codes that must still decode.
- Event lifecycle/bounds/64-bit time tests and actual status/registration host
  tests passed for both policies. Both browser regression scenarios passed.
- Two Python benchmark-analysis tests passed. No physical benchmark was run.
- All 49 baseline backup file hashes and the unchanged private header hash matched.
  Modified-file whitespace checks passed. Laravel branch/HEAD/status stayed unchanged.

Changed existing files: src/main.cpp, platformio.ini, README.md, .gitignore and
test/qr03-web.test.cjs. Added: include/iot_readiness.h, this document,
test/iot01-vectors.json, test/iot01-vectors.php, test/iot01-readiness.cpp,
test/iot01-http.cpp and test/run_iot01.py. Reversible source backup and ignored
`.iot01-test/` fixtures/host executables were generated locally. No secrets were
copied into these fixtures or the portable source backup. No Git operation changed
repository history/index. Status: ready for code review; physical acceptance pending.

## Feedback-controller inventory

Only the camera PlatformIO project was found in the inspected PlatformIO/workspace
locations. No separate feedback-controller firmware or wiring specification was
available there. User reports a borrowed ESP32 plus OLED, LED and buzzer; exact
controller variant, OLED controller/interface/size, LED type/count, buzzer type,
GPIOs, supply and camera-to-controller transport remain unverified. No GPIO or
transport is assumed or implemented. This does not block camera synthetic tests.

## Manual hardware acceptance — owner-operated, synthetic only

1. Preserve borrowed-board owner's source/configuration/recovery arrangement and
   obtain permission before any flash. Confirm the actual board/programmer/port.
   No upload command was executed by this task.
2. Owner uploads the default `esp32cam` build. Keep private firmware files local.
   Confirm OV2640/PSRAM initialization and existing memory/reset/Wi-Fi diagnostics.
3. Open the printed metadata-page address. Enter QR mode; present each generated
   SVG from `.iot01-test/` on a phone and on paper with controlled lighting/focus.
   Confirm all ten decode, only the matching case gets the format hint, and no
   status/log output reveals decoded text. No Laravel validity is expected.
4. Confirm `/qr/frame` is unavailable and port 81 does not provide a stream in the
   safe build. Check actual network behavior; host tests alone are insufficient.
5. Poll the same result repeatedly: identity/time must not refresh. Remove the QR,
   wait at least five seconds: state/success expire. Switch Preview/QR: no old fresh
   event; a new capture gets a new identity. Reboot: boot nonce changes. Reconnect
   browser/Wi-Fi: no pre-outage event is announced as a new scan.
6. With ONLY synthetic codes in view, owner may separately upload the explicitly
   selected `esp32cam-diagnostics` build. Check existing Preview motion/pacing,
   Stop/restart, QR/Preview transitions, VGA snapshots, slow/closed clients and
   freshness. No raw payload should appear in Serial/status even in this build.
7. Run at least twenty minutes of no-code and alternating-code trials. Record
   actual success counts/latency, heap/PSRAM, reset reasons and capture/network
   failures. Do not claim 8 FPS, optical reliability or improved timing from a
   successful compile. Return to the safe default build before real-credential use.

## Remaining requirements before Laravel integration

Physical IoT-01 acceptance; feedback hardware/firmware inventory; verified power
and transport; device credentials/TLS/revocation; secure workstation/Admin pairing;
server-owned event acceptance/idempotency/TTL; authorized browser handoff using
existing verifier and five-minute check-in evidence; isolated regression tests and
end-to-end hardware QA. Scans remain verification only. No automatic arrival,
payment, status change or QR consumption is authorized by this milestone.
