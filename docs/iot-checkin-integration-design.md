# IoT check-in integration — design only

Decision reconciled November 3, 2026. The final direction is a separate
ESP32-CAM QR unit plus ESP32 feedback controller with OLED, LED and buzzer.
No device endpoint, secret, firmware or simulated arrival is implemented by this
documentation task. Hardware availability below is user-reported, not tested.

## Approved hardware

| Component | Purpose | Status |
| --- | --- | --- |
| ESP32-WROOM-32D DevKitC V4 Type-C, 38-pin, expansion adapter | Feedback and communication controller | Available |
| Separate ESP32-CAM | QR capture; preferred target is camera-side decoding | Missing; exact board unconfirmed |
| OLED | Verification and connectivity results | Available; controller, size and interface unconfirmed |
| LED | Visible feedback | Available; type/count unconfirmed |
| Buzzer | Audible feedback | Available; type/electrical specifications unconfirmed |

AI-Thinker ESP32-CAM with OV2640 and a matching ESP32-CAM-MB programmer is a
candidate, not a confirmed purchase. Verify actual models before wiring or buying
accessories. Two boards require separate firmware and a verified power/pin plan.
Do not assume the expansion kit includes every wire or sufficient power supply.

Superseded alternatives: dedicated GM65/QR28/USB/UART QR scanners and LCD.
Noise sensors, RFID/fingerprint, queue systems and printers are outside scope.
Existing browser camera scanning remains a fallback, not the primary IoT source.
Preserve all unrelated application modules and historical records.

## Intended boundary

ESP32-CAM captures/decodes QR → authenticated Laravel verification → paired
ESP32 feedback (OLED / LED / buzzer) → paired authorized Admin browser shows
success feedback and opens the server-resolved Reservation Details → explicit
Admin arrival confirmation → existing CheckInReservations transaction → separate
payment/document/release processing → manual Admin completion.

The regular ESP32 is the feedback controller; the separate ESP32-CAM is the camera
unit. Laravel alone decides eligibility. Approved/Ready for Pickup retain existing
verification rules; check-in remains Ready for Pickup only. Scanning, verification,
viewing and check-in never consume QR; eligible Completed transition alone does.

## Future protocol requirements

- Provision one individually revocable device credential; never reuse APP_KEY or
  a human Admin session. No secrets in Git, QR payloads, public settings or logs.
- HTTPS, authenticated requests, signed timestamp + nonce/message identifier,
  replay rejection, expiry, rate limits and timeouts. Verify the hardware's clock,
  TLS support and available secure credential storage before choosing HMAC versus
  short-lived token implementation.
- Bind each feedback message to the initiating Admin verification and workstation.
  Do not accept a client-provided reservation ID as proof of verification.
- A device message is not an authenticated Admin action. The current browser
  flow establishes five-minute evidence bound to Admin, reservation, ticket and
  credential hash. A hardware adapter must establish equivalent trusted evidence
  through an authorized browser handoff and fresh server verification. Do not
  trust a device-provided Admin ID or invent session evidence from decode success.
  Use expiring, single-use handoff identifiers bound to the paired workstation;
  reject wrong-account/device/reservation, stale and replayed handoffs.
- Result contract: verified / invalid / used / not eligible, a short-lived message
  ID and safe display text. Avoid raw credentials, names and private identifiers
  on device screens/logs. Define offline/error indication distinct from invalid QR.
- Delivery retry and acknowledgment are idempotent. Device feedback failure must
  not create arrival or block a legitimate server-side transaction.
- Never queue offline arrivals as if Laravel accepted them. A decode beep means
  capture only; server acceptance requires the authenticated server result.
- Define waiting, processing, verified, invalid, used, ineligible and offline/error
  states. Show readable OLED text alongside LED/buzzer cues. Do not retain camera
  footage or QR image archives by default, or log credentials/raw tokens.
- Device feedback is never proof of physical arrival. It cannot write payment,
  change processing status, mark Completed or consume a QR. Only explicit Admin
  confirmation invokes the existing check-in service and its fresh five-minute
  session evidence. Completion alone consumes QR under the existing payment gate.
- Test replay, invalid/used QR, unplugged device, timeout, duplicate delivery,
  expired verification and two simultaneous workstations before rollout.

## Implementation gates

1. Inventory the actual camera/OLED/LED/buzzer models, programming method, cables
   and supply. Verify common ground, logic voltages, current headroom and pins;
   never assume GPIO tolerates 5 V.
2. Prove ESP32-CAM decoding against actual SmartBarangay tokens on phone screens
   and paper: focus, glare, lighting, distance and latency. Camera-side decoding is
   the target, not a tested capability. If browser stream decoding is needed,
   document and approve that decision rather than silently changing architecture.
3. Select board-to-board transport (UART or network), pairing, TLS, time handling
   and power arrangements after the hardware proof. No final wiring is prescribed.
4. On a separate implementation instruction, reuse existing verification/check-in
   services through a narrowly scoped authenticated adapter. No direct MySQL
   access, duplicate reservation workflow, automatic payment or completion.
5. Test malformed/oversized, valid, invalid, used, wrong-status and expired QR;
   retries, duplicate scans, lost Wi-Fi, unplugged devices, revoked credentials,
   stale sessions and simultaneous workstations. Confirm no unintended mutations.
6. Accept only after scan → safe feedback → correct Admin details → explicit
   check-in → separate payment/documents → manual completion works end to end.

Firmware, device API and physical validation are not delivered by this planning
update. No migration, live-data change, commit or push is authorized by it.
