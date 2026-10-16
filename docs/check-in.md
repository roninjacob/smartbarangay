# Phase 12A: persisted QR check-in

Check-in means physical arrival for a Ready for Pickup reservation. It is an explicit Admin confirmation after QR verification, not approval, payment, document release, completion or QR consumption. No check-in is required before completion; Phase 11C payment rules remain authoritative.

## Existing foundation and migration

Reuse `checkin_logs`: reservation_id (nullable, restrictive FK), scanned_ticket_code (nullable), verified_by (nullable user FK, SET NULL on actor deletion), verification_result, verified_at and timestamps. Existing reservation/user relationships remain. Operational events use verification_result = checked_in; verified_at is the server-owned arrival timestamp in UTC, and verified_by is the authenticated Admin. No raw QR credential or new Resident PII is stored for arrival.

Migration `2026_10_16_000021_add_operational_checkin_uniqueness.php` adds one virtual generated discriminator: checked_in_reservation_id = reservation_id only for checked_in records, otherwise NULL. A unique index enforces one operational arrival per reservation while preserving multiple legacy verification attempts. Indexed virtual generated columns support MariaDB 10.4 and SQLite; existing records are not rewritten or relabeled. The migration must fail rather than discard conflicting historical arrivals. Rollback removes only the added index/column, preserving log rows. The real MariaDB migration is pending review and was NOT executed during implementation.

## Verification and explicit confirmation

Successful QR verification stores a five-minute session context with Admin ID, reservation ID, ticket ID, credential SHA-256 and expiry. Invalid scans clear the previous context. JSON remains unchanged: success confirmation and automatic redirect still lead to the server-resolved Reservation Details. Verification does not persist an arrival or consume QR.

Reservation Details offers Confirm Check-In only for Ready for Pickup with recent evidence. The Admin must explicitly confirm arrival. Direct POST without evidence, another Admin/reservation, expired context, changed/used QR or an ineligible/inactive/unverified Resident fails. All QR validity and Ready for Pickup checks are repeated inside the transaction. Browser audit/status/payment/source fields are prohibited and never authoritative. Evidence stays bounded by its original expiry, allowing safe repeated POSTs after success without extending verification indefinitely.

## Domain operation and audit

`CheckInReservations::record()` owns authorization, eligibility, revalidation, transaction, duplicate protection and audit values. It locks reservation then QR, consistently with completion. Payment recording locks reservation first too. The first arrival wins; subsequent eligible verified attempts return the original record and Already checked in. Model updates/deletes reject operational arrivals, and no edit/delete endpoints exist. Privileged direct SQL can bypass application immutability; the pre-existing actor SET NULL policy remains, with Former account fallback in Admin display.

All payment states may check in: Unpaid, Paid, Free, Fee Not Recorded and Payment Integrity Issue. Arrival changes no reservation attributes, status history, payment, collection totals, QR usage, document bytes/metadata, historical source or date_issued. Payment remains Ready for Pickup only; completion later consumes QR through the existing atomic lifecycle.

Admin details show persisted arrival time in Asia/Manila and recording Admin. Resident arrival display is deferred; existing Resident payment/document views remain unchanged. No broad activity timeline or Phase 12B screen is implemented.

The domain service accepts trusted server-side verification context without browser/session dependencies, so a future authenticated hardware adapter can reuse the operation. No IoT API, device secret, ESP32/GM65 firmware, OLED/LED/buzzer or hardware-specific files exist in this phase. Real MariaDB concurrency and physical camera scanning are not proven by isolated SQLite/HTTP tests.
