# Phase 7: reports, analytics and financial integrity

Baseline: `ad877e8` (Phase 6 committed). Pre-flight found only the three existing
unrelated untracked files. Preserve all previous phases and private manifests.

## Source-of-truth mapping inspected before changes

| Area | Population, date and denominator |
| --- | --- |
| Reservation reports | reservations.created_at submissions in the selected period; current status breakdown across all seven statuses, including terminal historical submissions |
| Collections | reservation_payments.paid_at; immutable amount consistent with positive reservation fee_snapshot; valid revenue and integrity-issue populations partitioned separately |
| Recorded check-ins | checkin_logs.verified_at with verification_result=checked_in; linked valid reservation/service/resident/schedule required; malformed arrivals are period-bound warnings |
| Finalized PDFs | reservation_documents.generated_at with PDF/metadata/path/hash-shape validity; legacy/incomplete records are period-bound warnings; previews and uploaded masters are not document events |
| CSV | ReportQueries shares the exact population/partition/projection with each page; all matching rows across pages, stable timestamp/ID keyset batches and reconciled counts; valid collections also reconcile exact decimal sum |
| Dashboards | Existing operational current-database totals, not the reporting-excluded population. Phase 14A explicitly preserved these totals. They must not be described as the same filtered/reviewed report population |

ReportingExclusions validates the entire private manifest on every request:
explicit review, exact environment/driver/host/port/database binding and complete
reservation/user/service/schedule/original UTC created_at identity tuple. Missing,
malformed, duplicate, mismatched and reused-ID entries fail closed, including
fixtures outside the selected interval. Synthetic tests must create separate
temporary manifests; no real private manifest is changed.

All four page services and CSV preparation use read transactions. Under MariaDB
InnoDB REPEATABLE READ, the first consistent read establishes the population
snapshot used by subsequent aggregates, relationships and batches. Verify this
behavior with the existing disposable MariaDB guard, not SQLite alone.

Dates use half-open UTC intervals corresponding to Asia/Manila inclusive calendar
days. Chart bucketing adds eight hours to stored UTC event timestamps. Collections
use SQL DECIMAL sums and Brick Math BigDecimal reconciliation; current service
prices, unpaid bookings and completion alone never constitute revenue.

Current report filters are period/date/custom range and pagination, with the
valid/integrity table toggle for collections. Service/status search filters live
in operational reservation management, not these report endpoints. Do not add
new filters or silently change report contracts during this audit.

CSV uses a private disk-backed temporary file, UTF-8 BOM, CRLF and explicit CSV
quoting. Text formula starters, including concealed whitespace/control and
full-width variants, are neutralized. Credentials, paths, QR payloads, snapshots
and private manifest contents are not projected. Exports fail explicitly on
reconciliation, size or preparation-time limits rather than truncating output.

## Findings and reconciliation evidence

No confirmed application defect requiring a production-code change was reproduced.
This checkpoint adds regression coverage and documentation only: no routes, schema,
UI, firmware, payment rules or historical records are changed. The existing local
manifest was checked read-only: eight reviewed identities validated against its
local environment binding. This is not verification of the production manifest.

The shared synthetic fixture produces the following reviewed populations on both
SQLite and MariaDB. These numbers are test expectations, not official totals.

| Report | Valid total | Warning partition | CSV |
| --- | --- | --- | --- |
| Reservations | 7 submissions; one of each status | Not applicable | 7 rows |
| Collections | 2 payments totaling exactly 0.30 | 1 inconsistent payment | 2 valid rows; 1 integrity row in the separate export |
| Check-ins | 1 confirmed linked arrival | 1 unlinked arrival | 1 row |
| Finalized PDFs | 1 valid persisted PDF event | 1 legacy/incomplete document | 1 row |

Each report excludes the same individually reviewed synthetic reservation and its
linked events. An inactive service with TEST in its name remains included. Repeated
QR verification does not add arrival records; a stored preview without a finalized
document row does not add a document event. The current service price differs from
historical fees without changing collected amounts. Paid Ready for Pickup and paid
Completed requests both contribute actual valid payments.

Current occupancy is five in that fixture (four consuming status records plus the
excluded Ready for Pickup fixture), while reviewed historical submissions are seven.
The operational dashboard deliberately retains the fixture in its raw totals:
eight reservations and 999.30 collected, versus 0.30 in the reviewed report.
Changing that dashboard policy requires a separate approved scope decision.

## MariaDB concurrency verification

Six new cases run against guarded disposable MariaDB 10.4.32/InnoDB with
REPEATABLE-READ. An independent PHP process commits through the actual payment,
check-in or completion workflow after the reader's first aggregate query.

- A concurrent payment stays absent from the current cards, chart and detail table,
  and appears in the next report transaction.
- A concurrent confirmed arrival appears only in the next check-in snapshot.
- A concurrent completion cannot mix Ready for Pickup counts with Completed rows;
  the next report sees Completed while retaining the historical submission.
- A payment committed between CSV aggregate reconciliation and keyset batching
  stays outside that export and appears in the next export.
- Exact DECIMAL aggregation of 99999999.99 plus 0.01 yields 100000000.00.
- All four report/CSV populations and warning partitions execute on MariaDB;
  monitored report/export queries issue no business-data writes.

These tests reuse the Phase 5 disposable-server guard (loopback port, temporary
datadir, sentinel and testing environment). They never reset or connect to the
development/production database for concurrency testing. Synthetic rows are retained
in the disposable database; each run chooses an unused period.

## Limitations and deployment gates

- Production was not accessed. Confirm InnoDB REPEATABLE READ on the deployment
  connection before claiming the same concurrent-snapshot guarantee there.
- A page request and a later CSV download have separate snapshots. New committed
  events between those requests can legitimately change totals. Their filtering,
  partition and exclusion rules are identical; no cross-request snapshot is promised.
- Document reporting validates persisted metadata, not physical file existence or
  content bytes on every report request. File recovery/health checks are separate.
- Linked resident/service display names are current labels, not immutable historical
  name snapshots. Event timestamps and immutable historical payment amounts remain
  the authoritative metric inputs.
- Existing tests cover empty states, authorization, invalid filters, manifest failure,
  date boundaries, pagination, formula escaping and export limits. The added cases
  extend those tests; they are not a production-volume load benchmark or browser QA.
- Confirm private manifest/storage permissions and export staging cleanup on hosting.
  Do not copy a development manifest into another database or weaken fail-closed guards.

## Local acceptance checklist

Use only isolated synthetic data and an explicitly reviewed test manifest.

1. Sign in as an active verified Admin; confirm Residents/guests cannot download reports.
2. Open all four reports, select a specific day and custom range, and verify clear
   validation for reversed ranges and accurate zero/empty states.
3. Check Manila midnight, month/year boundaries and all seven reservation statuses;
   distinguish historical submissions from current capacity consumption.
4. Compare cards, chart, complete breakdown and all CSV rows for an unchanged dataset.
   Compare valid and integrity Collections exports separately.
5. Confirm QR verification alone and PDF preview alone add no confirmed event totals.
6. Verify missing/mismatched manifests fail closed in the isolated environment;
   preserve the real manifest and all business records.
7. Run the guarded MariaDB tests following the disposable-server setup in
   docs/reservation-capacity-integrity.md. Set SB_CAPACITY_TEST_PORT to its verified
   port, then run the focused report classes and full suite. Without that variable,
   MariaDB cases explicitly skip and must not be reported as concurrency passes.

No migration, production reconciliation operation or data correction is required by
this code checkpoint. Owner review is required before any Git or deployment action.

## Validation recorded November 13, 2026

- Focused report/CSV suites including the new cases: 240 passed, 2,453 assertions.
- Full PHP suite with disposable MariaDB enabled: 1,469 passed, 14,432 assertions.
  This includes 16 opt-in MariaDB cases across Phases 5, 6 and 7. One conditional
  Phase 5 race assertion can vary with the winning lock contender.
- New Phase 7 cases alone: 8 passed, 118 assertions (2 cross-report and 6 MariaDB).
- JavaScript: 56 passed, zero failures. Vite production build passed.
- Targeted Pint, PHP syntax checks for all four added PHP files and git diff --check
  passed. No tracked files were modified or staged.
- No live browser or production acceptance was performed. No real-data migration,
  data correction, firmware change, commit, push or deployment was performed.
