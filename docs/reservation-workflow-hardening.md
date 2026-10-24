# Milestone 1: reservation history and submission integrity

## Time and eligibility

Resident choices use `Schedule::upcoming()` with the Asia/Manila date/clock.
A slot must start strictly after now: at its exact start it is no longer bookable.
The controller rechecks the selected slot during selection, confirmation, under
the final schedule lock, and immediately before the transaction returns. The
last check also rolls back upload/document/snapshot/notification work if time
passes during submission; tracked private files are cleaned up on failure.

The final transaction locks the authenticated User and immediately rechecks
active status, non-NULL verification timestamp, and Resident role. It never
accepts a request-supplied owner. A previously eligible middleware model cannot
authorize an account that has since become ineligible. Existing confirmation
tokens, session locking, duplicate, fee, document and capacity safeguards remain.

## Immutable history

Migration `2026_10_24_000025_add_reservation_history_snapshots.php` adds nullable
appointment date/start/end and `requirements_snapshotted_at` to reservations.
Dates and times are Philippine local appointment values, not UTC timestamps.
The checklist capture timestamp is UTC. Submission copies the locked schedule
and all locked requirement names, descriptions and required/optional flags.

`reservation_requirements` preserves the complete checklist, including optional
requirements with no upload. Each new upload has a `reservation_requirement_id`
link to its own request's captured requirement. Existing master IDs remain for
compatibility; a master edit/deletion cannot rewrite captured content. There is
no snapshot editing endpoint. Model guards reject subsequent snapshot updates;
direct SQL/query-builder writes remain privileged operations and bypass model
events, as elsewhere in Laravel. Foreign keys preserve attachment integrity.

Reservation displays and QR verification use the captured appointment when
present. The Admin dashboard uses the captured date for new requests. Linked
master schedules reject date or time changes inside their locked update
transaction regardless of reservation status. Capacity/activation remain
manageable under existing occupancy rules. Create a separate slot for new dates.

## Legacy policy

No historical values are invented or backfilled from current master data.
Existing reservations keep NULL captures and use their linked schedule, with a
legacy notice on details. Their current service checklist is explicitly labelled
as current information, not original history. Legacy attachments whose original
requirement was not captured are labelled accordingly. New requests with an
empty captured checklist remain distinguishable from legacy requests; later
requirements are not projected into that originally empty checklist.

The authorized forward migration ran against local MariaDB after a verified
SQL backup. Existing business-column fingerprints across 18 tables matched
before/after; the new snapshot table remained empty and legacy rows were not
backfilled. Tests use isolated SQLite only. Backup is database-only, excludes
private storage/configuration, and has not undergone a restore rehearsal.

The eligibility race tests deterministically interleave a committed account
change after middleware/FormRequest validation. They exercise stale-authentication
handling without claiming a separate concurrent MariaDB stress test.

## Validation checkpoint

- Full isolated regression: 1,028 tests / 10,386 assertions.
- Final focused hardening + dashboard run: 24 tests / 236 assertions, including
  the snapshot-based appointment count and rollback of notification/upload work.
- Existing reservation/schedule focused regression: 73 tests / 1,165 assertions.
- All 19 JavaScript tests, Vite build, targeted Pint and diff checks passed.
- Routes are unchanged; no new processing/verification endpoint was introduced.
- Migration applied in batch 18. The database backup and its direct URL remain
  protected by HTTP 403. Existing real reservations, attachments, statuses,
  payments and QR records were not rewritten for testing or backfilled.
