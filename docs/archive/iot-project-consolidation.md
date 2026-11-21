# IoT project folder consolidation

## Locations and boundaries

Canonical firmware source: `firmware/SmartBarangay-ESP32-CAM-Test/` under
`C:\xampp\htdocs\smartbarangay` on `feature/iot-integration`.

The complete original project remains untouched at
`C:\Users\ronin\OneDrive\Documents\PlatformIO\Projects\SmartBarangay-ESP32-CAM-Test`.
Its `backups/` directory is private recovery material outside the Laravel repository.
Do not stage/copy it into Git. For consolidation rollback, reopen that original
project; no move/deletion or firmware upload is needed. It retains its own source,
libraries, configuration, private settings and old build/recovery files.

This consolidation changes organization and test portability only. It does not
implement a Laravel device API, modify reservation/check-in/payment logic or change
camera firmware behavior. No hardware upload, serial connection or Git checkpoint
is performed. Work is prepared locally for owner review.

## Portable versus local files

Copied: source, public/example headers, full portable quirc library sources/license/
documentation/examples, host tests, PlatformIO configuration, Markdown reports,
ignore rules and portable VS Code extension recommendations.

Excluded: `.pio/`, `.iot01-test/`, Python caches, private `backups/`, upstream Git
metadata, generated editor launch/C++ configuration and the original `secrets.h`.
No nested `.git/` is introduced. Historical Markdown reports are retained as history;
their backup paths refer to the preserved standalone project, not this repository.
Do not execute old upload/rollback instructions without reviewing the current guide.

The local ignored `include/secrets.h` remains a legacy placeholder and is no longer
read by builds. Current private Wi-Fi setup uses an external header at
`%USERPROFILE%\.smartbarangay\firmware\esp32cam\smartbarangay_wifi_private.h`.
The PlatformIO pre-build script validates it and adds its external include directory.
See the firmware README for setup, permissions and safe failure behavior. Never
force-add private headers or share credential-bearing firmware binaries.

`test/iot01-baseline.json` contains non-secret QR-06 camera/library/config checksum
evidence; it has no backup payloads or Wi-Fi-secret hash. Host tests use it instead
of requiring private recovery files, so a clean clone can run tests. They find the
enclosing Laravel Composer autoloader by relative location and do not boot Laravel
or access the database. Install Composer dependencies normally if vendor is absent.

## Development commands

From a newly opened terminal with the configured PlatformIO PATH:

```powershell
cd C:\xampp\htdocs\smartbarangay\firmware\SmartBarangay-ESP32-CAM-Test
# Configure the external private header first, following the firmware README.
pio run -e esp32cam
pio run -e esp32cam-diagnostics
python test/run_iot01.py
node test/qr03-web.test.cjs
node test/qr03-web.test.cjs --safe
python -B -m unittest discover -s test -p test_benchmark_analysis.py
python -B -m unittest discover -s test -p test_private_wifi.py
```

Host decoder tests additionally require gcc/g++, PHP and the installed BaconQrCode
dependency. An alternate Composer location can be supplied with `--autoload`.
Generated test images/executables remain ignored under `.iot01-test/`.

From repository root, either independent build can also be invoked as:

```powershell
pio run --project-dir firmware/SmartBarangay-ESP32-CAM-Test -e esp32cam
pio run --project-dir firmware/SmartBarangay-ESP32-CAM-Test -e esp32cam-diagnostics
git status --short
git status --short --untracked-files=all -- firmware
git check-ignore firmware/SmartBarangay-ESP32-CAM-Test/include/secrets.h
git check-ignore firmware/SmartBarangay-ESP32-CAM-Test/.pio
```

Build commands do not upload. Do not use an upload target without separate owner
approval for the borrowed boards. Prefer the safe default build; the diagnostic
environment exposes unauthenticated LAN images/video for synthetic codes only.

## Web-root assessment

The Laravel application's serving root must be `C:/xampp/htdocs/smartbarangay/public`.
Never serve the repository root with a static HTTP server. The firmware directory
is outside public and has its own Apache deny-all `.htaccess` with indexing off.

The inspected XAMPP Apache configuration has global `DocumentRoot C:/xampp/htdocs`,
`AllowOverride All` and granted access. This can expose repository files through
`http://localhost/smartbarangay/...` even when the app itself runs on port 8000.
The new firmware denial protects firmware on that Apache route, but does not fix
the pre-existing broad htdocs exposure for other Laravel files. A dedicated
public-only virtual host/global Apache hardening requires owner-reviewed server
configuration; none is changed or restarted by this organization task. Servers
that ignore `.htaccess` need equivalent denial/public-only configuration.

Local verification: Apache returned HTTP 403 for the firmware directory and a
firmware `platformio.ini` request. A harmless repository-root `.env.example` request
returned HTTP 200, confirming the broader pre-existing root exposure. No private
file contents were requested. The running PHP development server uses Laravel's
server router; the installed ServeCommand starts that process with `public_path()`
as its working directory. This does not secure the separate Apache port 80 route.

No private original backup or original Wi-Fi header is copied under htdocs by this
task. An ignored file would still be readable over HTTP without web-root protection.

## Local verification checkpoint

- 54 portable files were copied and hash-verified before documentation/test-path
  adaptation. Original source/environment/library identities still match exactly.
- Excluded from copying: 181 `.pio` files, 28 generated test artifacts, 78 private
  recovery files, 25 upstream Git metadata files, two Python cache files, two
  generated editor files and the private secrets header. Original files remain.
- Added the portable checksum manifest, firmware directory protection/guide and
  this report. There are 59 intended untracked files eligible for owner review;
  no files were staged, committed or pushed.
- Both `esp32cam` and `esp32cam-diagnostics` release builds passed from the new
  project folder using placeholder-only local configuration. This is not a Wi-Fi
  runtime or physical hardware success claim.
- Ten synthetic quirc exact-byte image decodes, event/HTTP host regressions for
  both policies, both browser-script scenarios and two Python arithmetic tests
  passed. Private backups are no longer needed to run the test suite.
- PHP fixture-generator syntax check passed. Original library hashes and private
  header integrity passed; known private credential values were absent from all
  59 eligible new files. Git ignore checks excluded secrets, builds, private
  recovery and generated fixtures. No nested repository exists.
- Laravel tracked-file and staged diffs remain empty. Existing unrelated untracked
  files remain unchanged in status; branch and HEAD remain feature/iot-integration
  and 602affb. No migration, application logic change, firmware flash, deployment,
  server reconfiguration/restart or Git history/index mutation occurred.

Owner action: review the prepared files and the pre-existing broad Apache web-root
exposure before approving any Git checkpoint or later deployment. Open the new
firmware subfolder in PlatformIO; old IDE tabs still refer to the rollback copy.
