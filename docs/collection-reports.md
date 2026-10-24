# Phase 14B: Collections Reports

Status: implemented, uncommitted; ready for user browser QA after automated
validation. This is not consolidated Phase 14 acceptance or deployment.

`GET /admin/reports/collections` (`admin.reports.collections`) uses the existing
authenticated, active, verified Admin middleware and authenticated layout.
Residents receive the existing wrong-role denial. The sidebar highlights only
the current report. No payment, dashboard, completion, document, QR, notification
or check-in behavior is changed.

## Population and periods

The report describes **recorded collections excluding reviewed acceptance
fixtures**. It does not certify the remaining rows as official transactions.
Only `reservation_payments.paid_at` selects payment membership. Submission date,
schedule date, payment row creation date, current reservation status, account
activity and current Service fee do not select the population.

The six period modes, This Month default, Monday-Sunday week and inclusive
custom end date reuse Phase 14A's validated period rules. Asia/Manila boundaries
become UTC start-inclusive/end-exclusive bounds. Inapplicable fields are omitted
from validated filters. Invalid period/date/range/view/page inputs redirect with
explicit validation errors, and the error page shows no fallback totals.
Vanilla JavaScript enables/requires only applicable date fields and constrains
the custom end date; server validation remains authoritative without JavaScript.

Unpaid Ready for Pickup is deliberately absent: unpaid records have no `paid_at`.
The dashboard's existing independent operational metric is unchanged.

## Exclusions and integrity partition

`ReportingExclusions::validatedIds()` is reused unchanged. The complete private
manifest validates within the report's read transaction, including tuples outside
the chosen period. Missing/stale/duplicate/malformed configurations fail closed.
Empty objects and numeric-key objects are not valid exclusion arrays. The
approved manifest and runtime configuration are never rewritten by the report.

The selected-period payment population excludes those reservation IDs once.
Remaining rows partition into `ReservationPayment::consistent()` and its
complement. Consistency requires an existing reservation, positive historical
fee snapshot and matching stored payment amount. `paid_at` is non-null in the
schema; parity tests cover authoritative `ReservationPayments::status()` rules.
No global/all-time integrity count is inherited from the dashboard.

Inconsistent records whose payment time falls within this same interval generate
the warning count. They never contribute to valid monetary totals, time charts
or service rankings. The Valid Payments / Integrity Issues links change only
the 15-row database table; summary and graphs always retain valid collections.
Filter changes and table switches reset page one. Pagination preserves filters
and view; ties use descending `paid_at`, then descending payment ID.

Each table displays actual recorded amount/time and authorized context, with
links to existing Reservation Details. The integrity view cannot repair or
overwrite payments. Missing optional display context uses neutral placeholders
and does not remove monetary rows. Blade escapes names. Private notes, purposes,
file paths, QR credentials and the manifest are not projected into the view.

## Exact amounts and reconciliation

MariaDB uses native `SUM(DECIMAL amount)`; PHP receives decimal strings and uses
`Brick\Math\BigDecimal` for all aggregation/reconciliation and graph geometry.
The sum is never constrained back to the per-record DECIMAL(10,2) maximum.
SQLite NUMERIC arithmetic can use binary floats, so its test-query branch rounds
each two-decimal stored amount to integer centavos, sums integers and converts
with BigDecimal. Production MariaDB retains native exact DECIMAL sums. Chart
coordinates/widths are rounded display geometry, not rounded financial totals.

One transaction validates the manifest and reads counts, SQL aggregates, grouped
services, table page and eager-loaded relations together. The verified MariaDB
connection uses REPEATABLE-READ. No `lockForUpdate`, write query, materialized
report table or full payment-history PHP collection is used. Joined service
context uses left joins; payment/reservation uniqueness avoids multiplication.

Runtime reconciliation checks:

- Non-excluded period records = valid count + integrity count.
- Summary amount/count = time-bucket amount/count.
- Summary amount/count = complete service-breakdown amount/count.
- Top 5 plus Other services = complete summary amount/count.
- Selected paginator total = the corresponding valid/integrity count.

Reconciliation failure hides the entire report with a generic unavailable message.
Service ranking orders by actual collection amount descending, then service ID.
All represented services remain in the complete breakdown. More than five
services produces an Other services remainder; five or fewer need no remainder.
Current linked names are explicitly identified, not fabricated historical labels.

Hourly buckets apply to one day; daily buckets through 93 days; monthly through
731 days; yearly for longer periods, matching Phase 14A. Missing buckets are zero.
The contemporary Philippine UTC+08 offset is applied explicitly in SQL, without
requiring MariaDB named-timezone tables. Exact chart data is available as text.
Concurrent records can move offset-page boundaries between separate requests.
Large-volume performance has not been established; no index migration is added.

## Verification and handoff

Branch: `feature/reports-analytics`; existing HEAD: `e5d1ef3`.
Scope limited to Phase 14B. Existing dashboard calculations and Phase 14A
exclusion/period/query services are unchanged; only the Reservation Reports
sidebar active-route pattern is narrowed to avoid two highlighted entries.

Focused collections/reservation reports/payment/completion/dashboard regression:
142 tests passed / 1,200 assertions. New collection-specific coverage comprises
34 tests / 239 assertions. Coverage includes all period boundaries, fractional
and large sums, consistency/status parity, fixture exclusions, malformed JSON
shapes, period-bound warnings, view toggles, old submissions, completed and
inactive records, ranking/remainder, pagination, escaping and read-only/query
budgets. Test data is isolated SQLite, never the real MariaDB database.

Real MariaDB 10.4.32 read-only execution verified both table views and all four
bucket units. The currently selected October dataset has four fixture payments
excluded and therefore PHP 0.00 valid collections, zero valid payments and zero
remaining integrity issues. This is an expected empty state, not a report error.
A native MariaDB decimal probe returned exactly 0.30 for 0.10 + 0.20. The active
manifest hash and fingerprints of ten business tables matched before/after.

Production Vite build and 19 existing JS regression tests passed. No new
dependency, migration, table or index was introduced. No actual browser QA was
performed for Phase 14B in this implementation task: the user requested a
prepared uncommitted checkpoint for browser QA. Check both views, applicable
date controls, empty/error states, filter-preserving pagination, details links,
chart data, readable amounts and overflow at 1440/768/390/320px. The real dataset
cannot demonstrate nonzero collections/pagination after exclusions; use an
isolated synthetic QA database for those cases rather than changing real data.

No commit, push, merge, tag, real migration, live business-data mutation, backup,
deployment, export, new financial workflow, Phase 14C/14D or IoT work occurred.

Final full regression: 842 PHP tests passed / 8,503 assertions. Targeted Pint,
report-route inspection and final `git diff --check` passed. The prepared change
set is four modified files and seven new files (11 total), with no staged files.
No frontend browser acceptance is claimed. Next action is user browser QA, then
review before any user-authorized Git checkpoint.
