# Milestone 2: automation, sessions and configuration audits

## Scheduled housekeeping

Laravel 12 registrations remain in `routes/console.php`; no legacy Console
Kernel is introduced. Tasks use Asia/Manila and `withoutOverlapping()`:

| Task | Local time |
| --- | --- |
| Existing safe unverified-Resident cleanup | 02:00 daily |
| `auth:clear-resets` | 02:10 daily |
| `smartbarangay:cleanup-document-previews` | 02:20 daily |

Preview cleanup reuses the existing conservative implementation: only recognized
app-owned temporary PDF names older than one day are candidates, and a live
cached workflow protects its file. Unknown files, recent previews, generated
certificates, preserved sources and Resident uploads are not swept. CSV staging
uses automatically closed/unlinked `tmpfile()` handles; no global OS-temp sweep
is introduced. The previous hourly preview registration is now daily.

These registrations need the normal deployment scheduler runner (`schedule:run`
each minute via cron or Windows Task Scheduler). This task does not install an
OS task or run housekeeping against real data. `schedule:list` can display UTC
equivalents of the configured Philippine times.

## Password reset and sessions

The existing shared broker-authorized reset path serves both roles. There is
no signed-in password editor, new password endpoint or frontend change.
`AuthenticateSession` is active in the web middleware group, covering protected
routes and authenticated public-page requests.

After a valid broker lookup, password/remember-token changes occur under a User
lock. `Auth::logoutOtherDevices($password)` forces Laravel's credential rehash
and device-logout event. Its temporary in-memory guard user is removed without
creating a login session, and any queued remember cookie is unqueued so a stale
incoming cookie cannot produce a remembered login during a guest reset.

With the application's database session driver, all rows for the resetting
account are immediately deleted, including older sessions without an initialized
password fingerprint. Other accounts' sessions remain. Hash-aware middleware
also rejects stale sessions on their next request; it does not cancel an HTTP
request that already passed authorization. Other session backends use Laravel's
hash-based next-request rejection rather than database-row deletion. Resetting
the password leaves the resetting browser signed out and rotates its guest
session/CSRF token. Failed reset validation does not revoke sessions.

## Configuration audit contract

Migration `2026_10_24_000026_create_audit_logs_table.php` creates:
`id`, Admin `user_id`, `action`, `target_id`, JSON `old_values`/`new_values`,
and UTC `created_at`. The actor FK restricts deletion; target IDs remain useful
after safe configuration deletion. Action suffixes identify the target type.

Audited operations are created/updated/deleted services, schedules and service
requirements where those operations exist. Activation changes count as updates.
Allowlisted values are:

- Service: name, description, fee amount, active state.
- Schedule: date, start/end time, capacity, active state.
- Requirement: service ID, name, description, required state.

Actual locked database values are captured, never client-supplied old values or
actor IDs. No-op updates add no event. Failed validation, blocked deletion and
rolled-back changes add no success event. Audit persistence shares the business
transaction; an audit failure rolls back the configuration change. Existing
private-file cleanup on failed service updates remains in place.

The model rejects audit edits/deletion. Privileged raw SQL bypasses Eloquent
guards and remains a database administration responsibility. No public audit
endpoint or UI is introduced. Credentials, file paths and arbitrary request
payloads are not projected. The October 27 refinement extends this audit to Resident activation/deactivation, official master upload/replacement/removal, and dynamic configuration, field and placement changes. Account logs contain only active-state changes. Master logs contain service ID, MIME, size and a revision hash, never private paths. Configuration logs contain allowlisted field/placement definitions and source hashes. Audit writes share the mutation transaction. Personal theme preferences remain outside this audit; reservation status and payment history keep their own authoritative records.

## Local rollout

A verified database-only backup preceded the authorized forward migration.
All existing business rows across 19 tables matched fingerprints before/after;
no real reset, pruning, configuration update or audit fixture was performed.
The backup excludes private storage/configuration and has no restore rehearsal.
Focused tests use isolated SQLite and fake private storage, including two
independent database session payloads, stale-hash middleware rejection, both
roles, audit rollback, configured schedule times and cleanup boundaries.

Migration applied in local batch 19. Final validation: 15 focused tests / 86
assertions; 1,043 full PHP tests / 10,474 assertions; 19 JavaScript tests; Vite
build, targeted Pint, schedule listing and diff checks passed. No frontend files
changed. Backup directory and direct SQL URL both returned HTTP 403. The real
audit table remained empty because no real configuration fixtures were written.
