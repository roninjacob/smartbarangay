# Phase 5: reservation capacity and slot integrity

## Current behavior (inspected before changes)

- Capacity is a number of reservation records per shared Schedule, across all
  services. It is not people, facility units, or a service-specific quota.
- `ReservationStatus::occupyingStatuses()` includes Pending, Under Review,
  Approved and Ready for Pickup. Submission immediately holds one slot.
- Rejected, Cancelled and Completed release current occupancy. Completed records
  still exist as historical reservations; reports count their authoritative
  submissions/events rather than treating occupancy as historical throughput.
- No expiry, automatic no-show release, check-out, rescheduling or facility
  scheduling workflow exists. Attendance follow-up does not release capacity.
- A cancellation request alone does not release a slot. Pending self-cancellation
  or an eligible Admin-approved cancellation changes status and releases it.
- Same resident/service/schedule active duplicates are rejected. Different
  services on one schedule are intentionally allowed, consuming one slot each.
- `Schedule::remainingSlots()` clamps to zero. Both portals reuse the shared
  occupying relationship and batch counts; these displays remain advisory.
- Submission locks the fresh Resident, Service and Schedule, validates current
  eligibility/configuration/time, checks active duplicates and uses a locking
  occupancy read before creating the reservation, snapshots and notifications.
  Session-blocked wizard routes consume the confirmation draft after success.
- Schedule edits lock the Admin then Schedule, validate revision and occupancy.
  Linked reservations of any status protect the date/time and prevent deletion.
  Deactivation blocks new bookings and preserves existing requests.
- Exact duplicate schedule date/time ranges have a database unique constraint.
  Overlapping, nonidentical schedules are currently independent capacities.
  There is no facility/resource model establishing a cross-schedule quota; do
  not invent one or change this policy during capacity hardening.
- State transitions only retain or reduce occupancy, never reactivate terminal
  reservations. They serialize on the reservation row and preserve histories.
  QR verification/check-in, payments and document operations do not consume
  additional capacity. Completion remains manual and payment-gated.

## Concurrency boundary

The Schedule row serializes every production booking and capacity edit. Current
locking reads are necessary under InnoDB REPEATABLE READ: an earlier ordinary
read may have established a stale snapshot. A cached count or ordinary aggregate
must not replace `lockedOccupancy()` inside booking/capacity transactions.

Do not add an occupancy counter, change consuming statuses, auto-expire requests,
add facility conflict rules, or add schema constraints without a separate need.
Real MariaDB multi-process acceptance and the existing rollback/authorization/
historical workflow regression tests determine whether code changes are needed.

## Findings and implementation

No reproducible overbooking, duplicate-consumption, invalid capacity reduction or
stale-snapshot defect was found in the inspected production write paths. No
application behavior, UI, schema, status policy or existing records were changed.
The implementation adds missing regression coverage instead of replacing the
working locking protocol.

`ReservationCapacityIntegrityTest` covers all seven status values, batched versus
direct counts, Admin reduction floors, stale cross-service confirmation, negative
remaining-slot protection and rollback after notification writes. Historical
records remain byte-for-byte equivalent at the model attribute level.

`ReservationCapacityMariaDbTest` opts into a disposable local MariaDB instance.
It uses independent PHP processes executing the actual submission and schedule
controllers, with synthetic requests and validated inputs. It does not simulate
the locks with SQLite, and it does not bypass production transaction/eligibility/
capacity logic. Middleware and HTTP validation are covered by the existing
feature suites, not claimed as tested by this controller-level race harness.

A coordinator holds the Schedule row while workers reach their production
locking query. It releases them only after observing the expected barrier files.
The same-user case waits at the earlier User lock. Timeouts fail the test; they
are not interpreted as successful booking rejection. A separate test establishes
an old REPEATABLE READ snapshot, commits a booking on another connection and
proves the locking occupancy read sees it while the ordinary count remains stale.

The harness refuses ports outside 33180–33199, any server data directory outside
the OS temporary `sb-phase5-mariadb-*` directory, or a missing sentinel. It uses
only `sb_phase5_capacity` on that disposable server. It never drops a database or
uses migrate:fresh. Existing synthetic cases remain available for inspection.

## Reproduce the isolated MariaDB acceptance on Windows/XAMPP

Run in the repository PowerShell terminal. These commands create a new private
temporary instance, not the development database. Do not substitute port 3306 or
the real data directory. Confirm port 33185 is free first.

```powershell
if (Get-NetTCPConnection -State Listen -LocalPort 33185 -ErrorAction SilentlyContinue) { throw 'Port occupied' }
$capacityRoot = Join-Path $env:TEMP ('sb-phase5-mariadb-' + [guid]::NewGuid())
New-Item -ItemType Directory -Path $capacityRoot | Out-Null
& C:\xampp\mysql\bin\mysql_install_db.exe "--datadir=$capacityRoot\data" --port=33185 --silent
if ($LASTEXITCODE -ne 0) { throw 'Disposable initialization failed' }
[IO.File]::WriteAllText("$capacityRoot\phase5-disposable.txt", 'SmartBarangay Phase 5 disposable MariaDB')
$capacityData = (Join-Path $capacityRoot 'data').Replace('\','/')
@"
[mysqld]
basedir=C:/xampp/mysql
datadir=$capacityData
port=33185
bind-address=127.0.0.1
innodb_buffer_pool_size=32M
log_error=$capacityData/error.log
"@ | Set-Content "$capacityRoot\server.ini"
$capacityProcess = Start-Process C:\xampp\mysql\bin\mysqld.exe -ArgumentList "--defaults-file=$capacityRoot\server.ini", '--console' -WindowStyle Hidden -RedirectStandardOutput "$capacityRoot\stdout.log" -RedirectStandardError "$capacityRoot\stderr.log" -PassThru
# Wait for startup; this must report alive before testing.
& C:\xampp\mysql\bin\mysqladmin.exe -h 127.0.0.1 -P 33185 -u root ping
$env:SB_CAPACITY_TEST_PORT = '33185'
php artisan test --compact --filter=ReservationCapacityMariaDbTest
Remove-Item Env:SB_CAPACITY_TEST_PORT
# Shut down only the scratch server. Keep its files for inspection.
& C:\xampp\mysql\bin\mysqladmin.exe -h 127.0.0.1 -P 33185 -u root shutdown
```

With the opt-in variable absent the normal PHP suite explicitly skips the five
MariaDB cases. A skipped case is not a substitute for the separate real-server
acceptance. No production credentials or private option files are needed.

## Existing regression coverage and browser acceptance

- `ResidentReservationWizardTest`, `ReservationDuplicatePreventionTest`: available
  and last-slot submission, duplicate/replayed drafts, different services,
  authorization and fresh server-side rejection.
- `ReservationWorkflowHardeningTest`, `WorkflowIntegrityCorrectionsTest`:
  Philippine time cutoff, fresh locked account eligibility, immutable snapshots,
  stale Admin edits, rollback and file cleanup.
- `ScheduleManagementTest`: capacity validation, duplicate schedule constraint,
  deactivation, protected time edits/deletion, date filters and pagination.
- `AdminReservationProcessingTest`, `ReservationCancellationTest`,
  `ReservationFollowupsTest`: allowed transitions, stale actions, cancellation
  requests versus actual cancellation and preserved history.
- `ReservationPaymentsTest`, `GeneratedReservationDocumentTest`,
  `ReservationCheckInTest`, QR/IoT suites: independent payment/document/check-in
  behavior, manual completion and QR consumption.
- `ReservationReportsTest` and other report suites: reporting populations retain
  historical submissions/events and use their existing exclusion rules.

For browser acceptance use synthetic records in an isolated environment: book a
capacity-one slot; keep a second confirmation page open before the first submit;
verify the second submit returns a useful unavailable message; reject/cancel the
first through the existing eligible flow and verify a new request can book; try
reducing capacity below occupancy; attempt date/time edits/deletion with linked
terminal requests; deactivate a slot and verify existing details remain visible.
Verify approved/Ready for Pickup requests, manual check-in, payment gating and
completion using the existing workflow. Do not mutate real resident records for
this checklist. No new browser UI behavior is introduced by this phase.

## Limits

This is bounded contention testing, not a load benchmark or proof against every
deadlock. Submission still performs required snapshot/file and notification work
inside its atomic transaction; heavy uploads or many notification recipients can
increase lock duration. No unsafe automatic transaction retry was added around
file writes. Direct SQL/manual imports can bypass controller invariants; inspect
existing over-capacity data separately without silently changing it. Pending
requests have no automatic expiry and completed requests release current capacity
by existing policy. Future facility quotas, overlapping appointments per resident,
rescheduling and expiry require separately approved business rules.

## Local verification — November 13, 2026

- Focused existing/new reservation and schedule suites: 132 tests, 1,821 assertions.
- Full PHP suite with disposable MariaDB cases enabled: 1,453 tests, 14,176 assertions.
- MariaDB 10.4.32, InnoDB, REPEATABLE READ: all five multi-process/current-read
  scenarios passed; rerun after the fixture date safeguard also passed. The
  conditional occupancy-error assertion count varies with which lock contender wins.
- All 56 JavaScript tests, Vite production build, targeted Pint, syntax checks
  and Git diff whitespace checks passed.
- No live browser acceptance, production access, real-data migration, firmware
  change, Git staging, commit, push or deployment was performed.
- The independent scratch server was shut down after verification; its synthetic
  data/evidence were retained outside Git and the web root. Port 3306 was not
  used for Phase 5 tests.
