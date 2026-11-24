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
  nonce. The scanner answers with its state and the same nonce.
- Both packets are HMAC-SHA256 signed, ESP-NOW unicast is encrypted with
  PMK/LMK keys, and the sender MAC must match the configured peer.
- A reply is accepted only for the outstanding nonce within 550 ms. With no
  accepted reply for 1.2 s the display shows "Device Offline".

| Scanner state | OLED | LED |
|---|---|---|
| Starting / waiting for time or camera | Starting system | Yellow |
| Wi-Fi connecting (this device) | Connecting to Wi-Fi | Yellow blinking |
| Ready | Ready to Scan | Off |
| Processing | Verifying Ticket | Yellow |
| Server valid (3 s) | Ticket Verified | Green |
| Server invalid (3 s) | Ticket Denied | Red |
| Scanner unreachable or server/network error | Device Offline | Yellow blinking |
| Authentication failure | Authentication Error | Yellow blinking |
| Rate limited | Please Wait | Yellow blinking |

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
