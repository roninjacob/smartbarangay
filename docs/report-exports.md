# Phase 14D — CSV exports and Reports acceptance

## Endpoints and populations

GET `/admin/reports/{reservations|collections|checkins|documents}/export` uses the existing active, verified Admin middleware.
HEAD authorizes/validates filters but does not materialize an export.

Exports include all matching rows (not just the current 15-row page) in timestamp DESC / ID DESC order.
`ReportQueries` supplies the SAME populations, partitions and safe detail projections to report pages and CSV generation.
The existing `ReportingExclusions::validatedIds()` is unchanged and validates the complete private manifest inside each read transaction.

| Report | Timestamp | Population |
| --- | --- | --- |
| Reservations | created_at | Recorded submissions; current status at export snapshot |
| Collections | paid_at | Active table partition: valid or integrity issues |
| Recorded Check-ins | verified_at | Explicit, linked, valid checked_in events only |
| Finalized PDFs | generated_at | Valid attributed PDF finalization metadata only |

Malformed arrivals, legacy DOCX and incomplete PDF metadata remain excluded from event exports.
Collections integrity exports are labelled separately and never contribute to valid collection amounts.
Current activation, names containing TEST and later schedule/master edits do not change the approved historical event rules.
Names are current linked labels, not immutable historical snapshots.

## CSV contract

UTF-8 BOM, comma delimiter, double-quote enclosure, CRLF line endings; exactly one header row and no preamble/footer.
Timestamps are ISO 8601 with the Philippine +08:00 offset. PHP amounts are exact decimal strings with two places, no symbols/group separators.

- Reservations: Request, Resident, Service, Submitted At (Asia/Manila), Current Reservation Status
- Collections: Request, Resident, Service, Recorded Amount (PHP), Paid At (Asia/Manila), Recorded By, Payment Classification
- Check-ins: Request, Resident, Service, Checked In At (Asia/Manila), Recorded By
- PDFs: Request, Resident, Service, Finalized At (Asia/Manila), Recorded By

Request references match the authorized UI (`Request #N`). No action URLs, other IDs, credentials, paths, notes, snapshots or passwords are exported.
Untrusted text beginning with spreadsheet formula characters (including after whitespace/control characters and full-width variants),
or starting with tab/CR/LF, receives an apostrophe prefix. This intentionally preserves safety over byte-identical spreadsheet text.

## Staging, limits and consistency

One database transaction covers complete manifest validation, expected totals, safe row retrieval and reconciliation.
MariaDB deployment requires the existing REPEATABLE-READ isolation; transactions use SELECTs and introduce no business writes or locks.
Composite timestamp/ID keyset queries fetch at most 500 rows; relationships are bounded by that batch.
Only one CSV row is encoded in memory at a time. No full query result is collected and no PDO cursor buffering assumption is required.

`tmpfile()` creates a disk-backed OS temporary file, outside public application storage, owned by the runtime account.
The deployment must keep that account's temporary directory private and writable; the local temporary directory is outside the web root.
Handles close/unlink in failure paths, stream finally blocks, and object destruction if an unsent response is discarded.
Normal client disconnects are handled with user-abort temporarily disabled so cleanup can run.
An OS crash/forced process kill is outside PHP finally guarantees; OS temporary-file retention and private directory permissions remain deployment responsibilities.

Hard configured ceilings: 25 MiB output and a 60-second preparation budget, checked before/after batches and during row writes.
An in-flight database statement cannot be preempted by PHP's cooperative elapsed-time checks; after it returns, an exceeded budget fails before download.
No limit silently truncates. Partial disk writes are completed or explicitly rejected. Staging row counts and valid payment decimal sums must reconcile before response headers.
The database transaction closes before network delivery. The response streams 8 KiB chunks with Content-Length, attachment disposition, text/csv UTF-8, nosniff and private/no-store.
HTTP transfer interruption can still truncate a client download; no error text is appended to CSV bytes.

Invalid filters return normal validation errors. Policy, reconciliation, resource and limit failures redirect to the same filtered report with an explicit error and no attachment.
Each export is its own snapshot; a report opened earlier can differ if data changed between requests.

## Acceptance scope

Existing report regression tests cover queries, exclusions, partitioning, historical limitations and responsive UI foundations.
Export tests cover four-route authorization, filters, identities, formula protection, Unicode/CSV quoting, decimal reconciliation, keyset ties,
all-pages downloads, bounded batches, limit failures, staging cleanup, read-only fingerprints and safe projections.
Run focused report tests, full PHP tests, JS tests, build, targeted Pint, routes and diff checks; finish user browser/download QA before Git acceptance.

No migrations, tables, indexes, report correction workflows, appointments, XLSX, PDF report exports, new business logs, or unrelated phases are included.
