# Recorded event reports (Phase 14C)

Admin-only GET endpoints: `/admin/reports/checkins` and `/admin/reports/documents`.
Both use the existing active/verified Admin middleware and private no-store responses.

## Populations and dates

Check-ins use `checkin_logs.verified_at` and only `verification_result = checked_in`.
A valid attributed arrival must link to an existing reservation, resident, service and schedule.
NULL/missing links are period-bound warnings, not valid arrivals. A missing recording Admin is allowed (former account).
Current reservation status, account/service activation and current schedule dates do not restrict historical events.
Ordinary QR verification and legacy verification results never count as arrival.

Documents use `reservation_documents.generated_at`. All document rows in the period are partitioned into valid PDF events or warnings.
A valid event has the same complete reservation links, exact PDF MIME, positive file size and template version,
64 lowercase hexadecimal hash and revision, nonblank original/source filenames, and the existing safe private PDF path structure.
Legacy DOCX and incomplete metadata contribute only to period-bound warnings. A removed current master or recording Admin does not invalidate history.
No filesystem reads, PDF availability checks, generation, repair, printing or downloads occur from reports.
Counts establish recorded PDF finalization, not verified current bytes, physical preparation, printing or release.
Existing authorized file endpoints remain authoritative when a document is explicitly opened.

## Filtering and consistency

Reuse the Phase 14A period contract: Philippine local calendar dates converted to UTC half-open intervals.
Default This Month; Today, Yesterday, Monday-Sunday This Week, Specific Date and inclusive Custom Range are supported.
Invalid filters return validation errors and suppress report totals on the error response.
Missing event timestamps cannot be placed in a period; never substitute creation, submission or schedule time.

Each response runs manifest validation and all report queries in one DB transaction using the established
MariaDB REPEATABLE-READ pattern. No locks or business writes are introduced.
`ReportingExclusions::validatedIds()` is unchanged: every approved identity must match, including fixtures outside the period.
Fixture exclusions apply by event reservation ID to both valid and warning populations.
Unlinked events remain warnings (SQL NULL is handled explicitly).

Valid total = chart series total = complete service breakdown total = Top 5 + Other total = paginator total.
Partition counts must also reconcile. Configuration or reconciliation failure suppresses all report components.
Charts use bounded hourly/daily/monthly/yearly buckets. Detail tables use SQL pagination (15 rows), timestamp DESC then ID DESC.
Only safe display fields are projected; no QR credentials, storage paths, notes, snapshots or passwords are returned.
Names are current linked labels. These reports cannot reconstruct historical appointment assignments or prove no-shows.

No appointment report, exports, schema/index changes or historical backfill are part of Phase 14C.
