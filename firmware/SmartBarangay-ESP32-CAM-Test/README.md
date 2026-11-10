# SmartBarangay ESP32-CAM standalone hardware test

Canonical development folder: `firmware/SmartBarangay-ESP32-CAM-Test/` inside
the SmartBarangay repository. The PlatformIO project still builds independently.
See [consolidation and recovery instructions](../../docs/iot-project-consolidation.md).
The original standalone folder is preserved as a private rollback copy. `backups/`
is deliberately not copied or required by the portable tests. Locally generated
`include/secrets.h` is a legacy ignored placeholder and is no longer read by builds.
Wi-Fi configuration now lives outside htdocs as described below.

This project tests an AI Thinker ESP32-CAM with OV2640 over local Wi-Fi. It includes on-camera QR decoding using quirc. [IoT-04 integration and acceptance](../../docs/iot-04-integration.md) adds an optional authenticated HTTPS client for Laravel pairing and verification. Without an external enabled private IoT configuration, `esp32cam` retains the standalone IoT-01 baseline. An enabled client starts QR mode automatically and blocks unauthenticated LAN mode changes. No raw QR text, camera images or video are exposed by the safe build. The explicit `esp32cam-diagnostics` build remains client-disabled and restores unauthenticated LAN images/video for synthetic/dummy codes ONLY. QR-06 camera scheduling/locking and the stable 1000 ms QR floor are preserved; fast experiments remain disabled. Physical IoT-04 acceptance and deployment remain pending.

Earlier QR-02 through QR-06 and STABILITY-01 documents are historical change records. IoT-01 supersedes instructions there that expose raw payloads or assume camera endpoints are enabled by default. No IoT-01 physical upload or device acceptance has been performed.

## Private Wi-Fi setup

1. Open this folder in VS Code / PlatformIO.
2. Create `%USERPROFILE%\.smartbarangay\firmware\esp32cam\smartbarangay_wifi_private.h` outside htdocs. The current owner's private header is already configured at `C:\Users\ronin\.smartbarangay\firmware\esp32cam\smartbarangay_wifi_private.h`. Its directory grants access to the current Windows user and SYSTEM. On another laptop, copy `include/secrets.example.h` to that external location and restrict its permissions before editing.
3. Replace the example values privately with your actual SSID/password. Use a 2.4 GHz personal Wi-Fi network. An open network can use an empty password. SSID must be nonempty. Captive-portal and enterprise authentication are not implemented.
4. Keep credentials inside double quotes. Escape any literal double quote as `\"` and backslash as `\\`.
5. Save and rebuild. `scripts/private_wifi.py` runs before both environments, validates the external header, and adds its directory to the compiler include search path. Credentials never enter command-line definitions or a generated repository header. Missing, unreadable, malformed, duplicate or placeholder configuration stops the build with a generic error. A private header resolving inside htdocs is rejected. Hardware upload requires separate owner authorization.

Use only the two example string declarations, `#pragma once`, blank lines and `//` comments in the private header. Literal quotes and backslashes must be escaped. Laravel `.env` is independent of this configuration. Both environments use this Wi-Fi header. The diagnostics environment remains restricted to synthetic/dummy QR codes.

The private header lives outside the repository. Its filename is additionally
Git-ignored if accidentally copied here. Legacy `include/secrets.h`, private
recovery folders and `.pio` artifacts are Git-ignored.
This folder now belongs to the enclosing Laravel Git repository; do not initialize
a nested repository or force-add secrets. Never share credential-bearing binaries.

### Recovery

The original standalone project at
`C:\Users\ronin\OneDrive\Documents\PlatformIO\Projects\SmartBarangay-ESP32-CAM-Test`
retains its original private setup. Reopen it for fallback builds. For a rollback
of this configuration change, review and reverse only the external-header setup
diff in the consolidated project, including its pre-build script and regression
test. Preserve other pending work. Keep the external header private during recovery.
Changing the header requires rebuilding the firmware. Previously built binaries
retain their compiled credentials and must stay private.

## Configuration and pin mapping

Pinned platform: `espressif32@8.0.0`; board `esp32cam`; framework Arduino. Verified installed framework package: `4.30312.261024`, Arduino 3.3.12. Uses bundled camera/JPEG, WiFi and HTTP server components plus locally vendored quirc v1.2 (ISC license); no camera-wrapper library or external API is used.

| Signal | GPIO |
|---|---|
| D0/Y2, D1/Y3, D2/Y4, D3/Y5 | 5, 18, 19, 21 |
| D4/Y6, D5/Y7, D6/Y8, D7/Y9 | 36, 39, 34, 35 |
| XCLK, PCLK | 0, 22 |
| VSYNC, HREF | 25, 23 |
| SCCB SDA, SCL | 26, 27 |
| PWDN, RESET | 32, -1 (no reset pin) |
| Flash LED | 4 (kept off) |

Current camera configuration: Preview QVGA 320x240 JPEG, quality 15, up to two PSRAM frame buffers, latest-frame mode and an 8 FPS cap. QR mode remains VGA 640x480 JPEG, quality 12, one buffer, when-empty mode. XCLK remains 20 MHz; pin mapping is unchanged. QR mode requires PSRAM. Streaming is available only in the dummy diagnostics build; each image retains its 1500 ms send deadline. See [IOT-01.md](IOT-01.md) for current build/test/rollback instructions.

## Build, upload and Serial Monitor (Windows)

Use a USB data cable with the ESP32-CAM properly seated in its MB programmer. Power off before handling the camera ribbon. No hardware upload has been performed by the coding agent.

In the PlatformIO terminal:

```powershell
cd "C:\xampp\htdocs\smartbarangay\firmware\SmartBarangay-ESP32-CAM-Test"
pio run -e esp32cam
pio device list
pio run -e esp32cam -t upload --upload-port COM3
pio device monitor --port COM3 --baud 115200
```

COM3 was detected as USB-SERIAL CH340 during setup; confirm the current port with `pio device list` or Device Manager. Close other serial monitors before uploading. Exit the PlatformIO monitor using Ctrl+C. Press the board RST/EN button after opening the monitor to see startup diagnostics.

If `pio` is not on PATH, use this equivalent command prefix in a normal PowerShell terminal:

```powershell
$env:PYTHONIOENCODING = 'utf-8'
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32cam
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32cam -t upload --upload-port COM3
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" device monitor --port COM3 --baud 115200
```

VS Code alternative: PlatformIO sidebar > Project Tasks > esp32cam > General > Build, Upload, Monitor. Baud rates are already 115200. Monitor DTR/RTS defaults are set low to avoid holding the board in reset.

The MB board may automatically enter download mode. If upload stays at Connecting, hold IO0/BOOT (if provided), press/release RST, then release IO0 when writing starts. If using a GPIO0-to-GND jumper instead, connect it only for flashing; disconnect it and press RST to run. GPIO0 is also the camera XCLK pin, so never leave it grounded during camera operation.

## Browser test

1. Watch for PSRAM and `[CAM] OV2640 initialized` diagnostics.
2. Watch for `[WiFi] Metadata page: http://<assigned-IP>/`. MJPEG on port 81 is deliberately absent from the default build.
3. Connect laptop/phone to the same local network. Open the actual printed `http://` address, not localhost and not HTTPS.
4. The page automatically requests live MJPEG. Wave a hand in front of the camera to confirm moving video.
5. Test Stop stream and Start / reconnect. Use one viewer at a time; the stream handler is intentionally minimal and serves one continuous stream at a time.
6. Disconnect/reconnect Wi-Fi and verify that the firmware announces connection recovery. Reload the page using the latest IP.

The page and stream are unencrypted and unauthenticated for this local hardware test. Anyone with network access to the device may view it. Do not configure port forwarding, expose it publicly, or point it at private scenes on an untrusted network.

## Failure behavior and diagnostics

- Camera init error: logs hexadecimal error plus ESP error name, starts no server, and idles with delays. Correct hardware then reset; no rapid reboot loop.
- Missing or placeholder credentials stop the PlatformIO build with setup instructions. Existing runtime credential checks remain a second safeguard.
- Wi-Fi: waits at most 20 seconds per attempt, then retries after 30 seconds. Common status values: 1 = SSID unavailable, 4 = connection failed, 6 = disconnected. No password is logged. Auto reconnect is enabled for link loss.
- HTTP startup failure: partial servers are stopped and initialization retries after 30 seconds.
- Stream failure: frame buffers are returned and the connection is closed. Reload/reconnect after correcting the cause.

## Troubleshooting

| Symptom | Action |
|---|---|
| No COM port | Try another USB data cable/USB port; check Device Manager. If needed, install the CH340 driver from the chip vendor or Windows Update. |
| Port busy / access denied | Close PlatformIO, Arduino and other serial monitors using that COM port; retry upload. |
| Failed to connect / wrong boot mode | Use BOOT/IO0 and RST sequence above; check seating and correct COM port. Release IO0 before normal operation. |
| Brownout / repeated reset / corrupt video | Use stable USB 5V, a short reliable cable and adequate power. Do not disable brownout detection to hide a supply problem. |
| Camera not detected / probe failed / 0x105 | Power off; reseat and latch the OV2640 ribbon correctly. Verify AI Thinker board/pinout and stable power. Never reseat while powered. |
| PSRAM not found | Firmware tries lower-resolution DRAM mode. Confirm board identity and PSRAM build flags, then inspect power/hardware if fallback also fails. |
| Wi-Fi timeout | Check exact SSID/password privately, enable 2.4 GHz, move closer, and check router DHCP. Avoid captive portals/enterprise login for this test. |
| IP printed but page unreachable | Use actual current IP and HTTP; ensure both devices are on same LAN. Disable guest/client isolation on the test network or use another trusted network. A VPN may interfere with local routing. |
| Page loads but video does not | Expected in the default safe build. For synthetic-only camera diagnostics, deliberately build `esp32cam-diagnostics`; then check port 81, client count and power. |
| Board remains in download mode | Remove GPIO0-GND jumper/release IO0, then press RST. |
| UnicodeEncodeError in PlatformIO terminal | Set `$env:PYTHONIOENCODING = 'utf-8'` in that terminal, then retry. This is a terminal encoding issue, not camera firmware failure. |

## Build verification

Original build: RAM 57,656 bytes, flash 1,065,237 bytes with placeholder credentials. Stability enhancement 01 has a separate build record in [STABILITY-01.md](STABILITY-01.md). The latest local build uses the current private header without changing its contents. Do not share `.pio` firmware binaries because Wi-Fi credentials are compiled into them. Build success does not establish camera, PSRAM or Wi-Fi hardware operation.

## Acceptance checklist (requires actual hardware)

- [ ] Upload completes and normal boot works after reset.
- [ ] OV2640 detected; PSRAM detected or documented fallback works.
- [ ] Correct IP shown without credentials in logs.
- [ ] Default build exposes safe metadata, with camera images/video unavailable.
- [ ] Synthetic-only diagnostics build: moving video, Stop/restart and stream stability work on the same LAN.
- [ ] Wi-Fi failure/recovery and wrong-credentials timeout behave as described.

Physical camera, Wi-Fi and streaming results remain unverified until these checks are performed on the board.

References: [PlatformIO AI Thinker board](https://docs.platformio.org/en/stable/boards/espressif32/esp32cam.html), [Espressif camera driver](https://github.com/espressif/esp32-camera), and the camera example/pins bundled with the installed Arduino framework.
