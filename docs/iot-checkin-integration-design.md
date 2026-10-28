# IoT check-in integration — design only

Hardware is not available yet. No device endpoint, secret, firmware, scanner
dependency or simulated arrival has been implemented by this task.

## Intended boundary

Browser camera → authenticated Admin verification → Laravel result → device
feedback (OLED / LED / buzzer) → explicit Admin arrival confirmation → existing
CheckInReservations transaction → separate payment/release → Admin completion.

An ESP32 with display/LED/buzzer is a feedback device, not automatically a QR
scanner. A hardware scanner, if selected later, needs its own integration review.

## Future protocol requirements

- Provision one individually revocable device credential; never reuse APP_KEY or
  a human Admin session. No secrets in Git, QR payloads, public settings or logs.
- HTTPS, authenticated requests, signed timestamp + nonce/message identifier,
  replay rejection, expiry, rate limits and timeouts. Verify the hardware's clock,
  TLS support and available secure credential storage before choosing HMAC versus
  short-lived token implementation.
- Bind each feedback message to the initiating Admin verification and workstation.
  Do not accept a client-provided reservation ID as proof of verification.
- Result contract: verified / invalid / used / not eligible, a short-lived message
  ID and safe display text. Avoid raw credentials, names and private identifiers
  on device screens/logs. Define offline/error indication distinct from invalid QR.
- Delivery retry and acknowledgment are idempotent. Device feedback failure must
  not create arrival or block a legitimate server-side transaction.
- Device feedback is never proof of physical arrival. It cannot write payment,
  change processing status, mark Completed or consume a QR. Only explicit Admin
  confirmation invokes the existing check-in service and its fresh five-minute
  session evidence. Completion alone consumes QR under the existing payment gate.
- Test replay, invalid/used QR, unplugged device, timeout, duplicate delivery,
  expired verification and two simultaneous workstations before rollout.

Hardware-specific firmware and transport are deferred until actual components and
network deployment are known. This design is not a claim of connected hardware.
