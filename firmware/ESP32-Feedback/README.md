# SmartBarangay ESP32 feedback device

ESP32 DevKit firmware that shows the scanner state on an SSD1306 OLED
(128x64, I2C address 0x3C or 0x3D) and three LEDs. It never decides a
verification result: it only displays the state reported by the
[ESP32-CAM scanner](../ESP32-CAM/README.md), and the scanner only reports
"Ticket Verified" after Laravel returned a valid result.

| Signal | GPIO |
|---|---|
| OLED SDA / SCL | 21 / 22 |
| Green LED | 25 |
| Red LED | 26 |
| Yellow LED | 27 |

No buzzer is used.

## Link to the scanner

- Both boards join the same 2.4 GHz Wi-Fi network, so ESP-NOW uses the access
  point's current channel (peer channel 0).
- Every 600 ms the feedback device sends a request with a fresh random 16-byte
  nonce. The scanner answers with its state and the same nonce from a dedicated
  task, immediately on arrival, independent of QR processing and its main loop.
  Serial logs `[LINK]` every 5 s: answered and missed requests and reply latency.
- Both packets are HMAC-SHA256 signed, ESP-NOW unicast is encrypted with
  PMK/LMK keys, and the sender MAC must match the configured peer.
- A reply is accepted only for the outstanding nonce within 550 ms. With no
  accepted reply for 1.2 s the display shows "Device Offline".

State numbers are defined once in `include/feedback_states.h` (identical copy in
`../ESP32-CAM/include/`).

| Scanner state | OLED | LED |
|---|---|---|
| Starting, waiting for time, first server contact | Starting system | Yellow |
| Wi-Fi connecting (this device) | Connecting to Wi-Fi | Yellow blinking |
| Ready | Ready to Scan / Present QR ticket | Off |
| Verifying | Verifying Ticket / Please wait | Yellow |
| Server: Ready for Pickup, check-in may proceed | Authorized / Proceed to counter | Green |
| Server: arrival already recorded | Checked In / Proceed to counter | Green |
| Admin confirmed check-in (shown 4 s) | Check-in OK / Check-in recorded | Green |
| Server: valid ticket, not yet Ready for Pickup | Not Ready Yet / Please approach staff | Yellow |
| Server rejected the ticket | Access Denied / Please approach staff | Red |
| One request failed, retrying | Reconnecting / Restoring connection | Yellow blinking |
| No Wi-Fi, no server answer for 20 s, or scanner unreachable | Device Offline / Scanner unavailable | Yellow blinking |
| Camera or decoder unavailable | Camera Error / Scanner unavailable | Yellow blinking |
| Authentication failure | Authentication Error | Yellow blinking |
| Rate limited | Please Wait | Yellow blinking |

### How long a result stays

Laravel owns the result lifecycle (`config/iot.php`, `result`): while an Admin has the
result window open the scanner keeps the result (renewed by the page every second,
at most 120 s). Done releases it at once; a recorded check-in shows "Check-in OK"
for 4 s. With no Admin reviewing, the result shows for 15 s, or until a different
ticket is presented. A connection problem always replaces a shown result, so a
stale green or red is never displayed. A green LED only follows a server answer.

## Private configuration

Kept outside htdocs in `%USERPROFILE%\.smartbarangay\firmware\esp32feedback\`:
`secrets.h` (`WIFI_SSID`, `WIFI_PASSWORD`) and `feedback_keys.h` (ESP-NOW
PMK/LMK and the HMAC key, identical to the scanner's copy in
`%USERPROFILE%\.smartbarangay\firmware\esp32cam\`). `scripts/private_wifi.py`
stops the build when `secrets.h` is missing. Never copy these files into the
repository. Board MAC addresses are set in `src/main.cpp` (`CAMERA_MAC`) and
`../ESP32-CAM/src/feedback_bridge.cpp` (`DEVKIT_MAC`).

## Build and upload (COM4)

```powershell
cd "C:\xampp\htdocs\smartbarangay\firmware\ESP32-Feedback"
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32dev
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" run -e esp32dev -t upload
& "$env:USERPROFILE\.platformio\penv\Scripts\platformio.exe" device monitor
```

Build output stays outside htdocs (`build_dir`). After upload the board runs
from any USB charger or power bank; the Serial Monitor is only for
troubleshooting.
