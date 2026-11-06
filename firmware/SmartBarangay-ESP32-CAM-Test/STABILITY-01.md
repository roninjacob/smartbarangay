# First stability enhancement — ESP32-CAM

Historical report: baseline copies/rollback paths below are in the preserved
standalone project. See the [consolidation guide](../../docs/iot-project-consolidation.md)
for the current folder and IoT-01 safe-default instructions.

Scope: standalone firmware only. User confirmed original upload, Wi-Fi connection, camera initialization and initial MJPEG operation, with 1–2 second latency and intermittent freezing. Physical performance of this enhancement has NOT been verified. No automatic reset or periodic reconnect is used to hide freezes.

## Inspection findings

- No obvious frame-buffer leak in original handler: acquired frames were returned after transmission and on format errors. Keep this ownership pattern; failed acquisitions have nothing to return.
- Original MJPEG boundary and per-image Content-Length were valid. Preserve the endpoints and multipart framing. Boundary length now uses `sizeof` rather than a literal.
- Original PSRAM mode uses VGA, JPEG quality 12 and two continuously captured frames. This costs more transfer bandwidth than QVGA/quality 15. Lower resolution/quality reduces pressure on the Windows hotspot; it is a justified first tuning step, not proof of the freeze's root cause.
- Original sends use HTTP server blocking writes with a 3-second socket timeout. Partial progress can restart per-write waits; this is not an absolute deadline for a complete frame. A framebuffer remains owned by the sender during these writes.
- Original stream server permits two sockets with LRU eviction. A second viewer cannot run concurrently inside the synchronous handler, but pending/replacement clients can interfere with stream ownership. Limit the stream server to one socket and disable its LRU eviction. The page server remains separate.
- Wi-Fi sleep was already disabled. Preserve that setting rather than claiming it is a new fix.
- Original Wi-Fi retry waits up to 20 seconds inside a loop. Make the connection timeout state-based so diagnostic output can continue while Wi-Fi connects.
- Original browser handles an error only with text. Add a single cancellable reconnect timer for explicit image errors; Stop/page exit cancels it. A connected but frozen MJPEG image does not reliably emit an error, so this is NOT a visual-freeze watchdog.
- Original logs have no periodic timing/memory information, so hardware logs are still required to separate a slow camera from a slow network.

## Configuration before and after

| Setting | Original | Enhancement 01 | Reason |
|---|---|---|---|
| Resolution with PSRAM | VGA 640x480 | QVGA 320x240 | Less capture/transfer load |
| JPEG quality with PSRAM | 12 | 15 | More compression; lower driver value means higher quality |
| Without PSRAM | QVGA, quality 15, one DRAM buffer | Unchanged | Conservative fallback |
| With PSRAM | Two buffers, LATEST, PSRAM | Unchanged | Keeps latest frames available; avoids deliberately introducing stale single-buffer capture |
| XCLK | 20 MHz | Unchanged | No evidence requiring a sensor-clock change |
| Stream pacing | 100 ms delay after capture/send (under 10 FPS) | 200 ms total-frame target (at most 5 FPS) | Lower sustained traffic; always yields |
| Send bound | 3-second socket timeout | Nonblocking send override with 1500 ms total-frame deadline; 1-second stream socket timeout | End stalled clients and release their framebuffer |
| Stream clients | Two sockets; LRU enabled | One socket; LRU disabled | Preserve ownership of active viewer |
| Page clients | Two sockets, port 80 | Unchanged | Page remains usable separately |
| Wi-Fi sleep | Disabled | Unchanged | Avoid modem-sleep latency |
| Wi-Fi connect/retry | Blocking 20-second wait, 30-second retry | Nonblocking state tracking, same timings | Keeps 5-second diagnostics running |
| Browser reconnect | Manual on error | Manual plus 2-second retry on image error | Recover explicit disconnects without stacking timers |
| Platform/board/baud/pins | Espressif32 8.0.0, esp32cam, 115200, AI Thinker pins | Unchanged | Preserve working hardware configuration |

The ESP-IDF session send override uses nonblocking socket sends in small pieces. All partial sends and HTTP multipart chunks share the same absolute frame deadline. It yields while the socket is full and returns an error on timeout/disconnect; the handler then returns its framebuffer and closes the session. Successfully sent means bytes accepted by the network stack, not proof that a browser rendered them. TCP/network buffering can still add latency.

`esp_camera_fb_get()` remains the bundled driver's API, with its internal capture timeout. This enhancement does not claim a custom camera acquisition timeout or solve a hardware/driver stall. The loop task continues diagnostics independently of the HTTP handler.

## Five-second diagnostic line

Fields are real measurements, printed every approximately five seconds. No example numbers below are physical test results.

- `stream`: 1 when a stream handler is active; 0 when idle.
- `frames`: cumulative successfully transmitted frames since boot.
- `fps`: successful frames divided by elapsed time in the latest reporting window. An idle or partially active window is naturally lower.
- `capture_avg_ms`: average framebuffer acquisition time in that window, including failed acquisitions. This measures driver wait, not the full sensor exposure interval.
- `tx_avg_ms`, `tx_max_ms`: average/max frame-send attempt duration in the reporting window, including failures.
- `heap`, `psram`: currently free bytes. A boot/first-stream drop can be normal; a continuous drop across repeated connect/stop cycles needs investigation.
- `wifi`: 1 if connected. `rssi`: signal strength in dBm; 0 is a disconnected sentinel, not a signal reading. More-negative readings mean weaker signal.
- `capture_fail`: cumulative missing, empty or non-JPEG acquisitions.
- `disconnects`: cumulative ended stream sessions, including intentional Stop/tab close, not just faults.
- `send_timeouts`: cumulative frames whose complete HTTP send hit the 1500 ms deadline.

The window timing averages are zero when there were no samples. Counters are shared between loop and HTTP tasks under short critical sections; serial output runs outside those sections. There is no per-frame logging.

Startup prints the numeric ESP reset reason with common meanings. Repeated reason 9 points to brownout/power investigation; reasons 4–7 call for panic/watchdog log inspection. Reset reason alone is not a root-cause diagnosis.

Interpretation:

1. FPS falls and `tx_max_ms`/`send_timeouts` rise while captures stay fast: investigate hotspot congestion, weak RSSI, browser/client network and client count. Do not immediately increase the deadline.
2. Capture times/failures rise while TX stays fast: inspect power, sensor/ribbon and camera-driver diagnostics. Power off before touching the ribbon.
3. `stream=1`, frames stop and diagnostics continue: compare capture/TX timings. The current blocked operation may not appear in the timing totals until it returns.
4. `wifi=0`: investigate hotspot/signal; retries should continue without stopping diagnostics.
5. Frames/FPS continue but browser image looks frozen: firmware transmission is progressing; investigate the browser/network buffer and compare motion after manually reconnecting. This is not automatically a camera failure.

## Build and verification

PlatformIO release build: PASS (exit 0, 9.69 seconds) with Espressif32 8.0.0 and installed Arduino 3.3.12. RAM: 57,744 / 327,680 bytes (17.6%). Flash: 1,067,645 / 3,145,728 bytes (33.9%). Generated `.pio/build/esp32cam/firmware.bin` contains private Wi-Fi configuration; do not share it. Browser script checks passed for syntax, single retry timer, explicit-error recovery, Stop cancellation, page-exit cleanup and direct-stream navigation cleanup, using mocks; these are not real-browser or hardware streaming tests. SHA-256 comparisons confirmed the private header and platform configuration remained unchanged.

Modified files: `src/main.cpp`, `README.md`. Added: `STABILITY-01.md` and reversible baseline copies under `backups/before-stability-01/`. `platformio.ini`, private `include/secrets.h` and the Laravel project are unchanged. Existing HTTP endpoints remain `/` on port 80 and `/stream` on port 81.

## Physical test procedure

1. Close all old camera tabs and serial monitors. Keep the same stable power supply and Windows 2.4 GHz hotspot for a meaningful before/after comparison. Keep the laptop awake during the test.
2. From this project run `pio run -e esp32cam`, then `pio device list`. Confirm the actual port; COM3 was detected previously.
3. Upload: `pio run -e esp32cam -t upload --upload-port COM3` (substitute current port). Open `pio device monitor --port COM3 --baud 115200`; press RST once for a complete startup log. If `pio` is unavailable, use the full executable path in README.
4. Confirm OV2640, QVGA/quality settings, PSRAM mode and local IP. Open `http://<printed-IP>/` with ONE browser tab. Wave a hand to verify actual frame updates.
5. Run continuously for at least 20–30 minutes, including a moving clock/hand in the scene. Save diagnostic lines before, during and after any freeze. Record observed latency and whether a manual reconnect helps.
6. Stop/restart the page stream at least ten times. Verify frames resume, disconnect count rises as expected, and free memory settles instead of continually falling. Stop must not restart by itself.
7. Open a second viewer briefly. It should not produce a concurrent second camera producer or evict the first viewer. The second may wait/fail; close it before continuing.
8. Toggle the hotspot off/on. Expect Wi-Fi loss diagnostics and reconnect/IP announcement. Browser retry depends on an image error; manually Start/reload using the latest IP if it remains frozen.
9. Pass criteria: moving frames remain continuous, no unexpected reset, no steadily increasing capture failures/timeouts or sustained memory decline. Occasional user-initiated disconnects are expected. Compare with the baseline under the same conditions.

No board upload or physical stream verification was performed as part of this code change. Do not mark the freezing issue solved until the above hardware test passes.

## Revert to the original working source

Baseline copies were made BEFORE any edit and exclude credentials/binaries. In PowerShell, from this project:

```powershell
Copy-Item -LiteralPath .\backups\before-stability-01\main.cpp -Destination .\src\main.cpp -Force
Copy-Item -LiteralPath .\backups\before-stability-01\README.md -Destination .\README.md -Force
pio run -e esp32cam
pio run -e esp32cam -t upload --upload-port COM3
```

`platformio.ini` was not changed; its original copy is also in the backup directory if needed. Current `include/secrets.h` stays untouched when reverting. Rebuild/upload is required: restoring source alone does not change firmware already on the board. No Git repository is currently initialized, so this backup is the rollback mechanism.

References: [Espressif HTTP server/session send override](https://docs.espressif.com/projects/esp-idf/en/latest/esp32/api-reference/protocols/esp_http_server.html), [camera driver and buffer modes](https://github.com/espressif/esp32-camera), plus installed framework headers inspected before editing.
