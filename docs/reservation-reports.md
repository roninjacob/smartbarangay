# Phase 14A: Admin Reservation Reports

Scope: **Recorded submissions excluding reviewed acceptance fixtures.** This does not certify every remaining record as an official transaction. The dashboard is unchanged. Collections, exports, completion-period reports, appointment/check-in/document reports and IoT are outside this phase.

`GET /admin/reports/reservations` uses existing authenticated, active, verified Admin protection. The existing details route remains the processing location. Reports have no business mutation actions. The private manifest, its identity tuples, private files, contact details, purposes, Admin notes and QR credentials are never displayed.

## Reviewed exclusion configuration

The user approved an exact development fixture set and the identity-bound private-manifest mechanism before implementation. Those environment-specific IDs are deliberately absent from general application source. No database marker, backfill or migration is introduced.

`config/reporting.php` holds **only a path**. Default: `storage/app/private/reporting/exclusions.json`, covered by the existing private-storage `.gitignore`. An operator may set `REPORTING_MANIFEST_PATH` to another private absolute location outside the public web root. Protect that file with operating-system permissions; never commit it. The public document root must remain Laravel's `public/` directory.

Manifest schema:

```json
{
  "version": 1,
  "reviewed": true,
  "binding": {
    "environment": "deployment APP_ENV",
    "driver": "actual connection driver",
    "host": "configured host or empty string",
    "port": "configured port or empty string",
    "database": "configured database name or SQLite path"
  },
  "excluded_reservations": []
}
```

This empty-list example is a schema illustration, **not approval to activate an unreviewed dataset**. Every environment needs explicit review, including one with no exclusions. Obtain the exact binding from `ReportingExclusions::binding()` in that environment; do not copy the example's placeholder strings. Keep the binding fields in the illustrated order. Do not copy another database's reviewed manifest or assume IDs identify the same fixtures after import/reset.

Each exclusion is an object with exactly five fields: integer positive `reservation_id`, `user_id`, `service_id`, `schedule_id`, and `created_at` as the original UTC `YYYY-MM-DD HH:MM:SS` string. Review against retained acceptance provenance and the actual row. Names, inactivity and statuses are never classification criteria. Legitimate inactive records and records named TEST remain included unless separately approved by exact identity.

Missing/unreadable/oversized (>1 MiB)/invalid manifests, unsupported versions, absent review, wrong environment/database binding, non-list entries, duplicate IDs, missing rows and mismatched tuple members block the entire report. All listed rows validate, even outside the requested period. No invalid entry is silently skipped. A generic warning replaces all totals, charts and table; private diagnostics are not rendered. Fixes require operator review, not an Admin UI bypass.

The manifest is read once and validated **on every request**; its contents are never placed in Laravel's configuration cache or a service singleton. `config:cache` caches only the path. After a path/environment/connection configuration change, rebuild the deployment's config cache normally; content changes at the same path are read next request. Replace manifests atomically during maintenance to avoid partial reads (partial reads fail closed). A file editable by an untrusted person is not a trustworthy review artifact; protect filesystem access. No public manifest-upload or classification editor exists.

Maintain the private manifest with the corresponding environment backup/restore notes and re-review after restores or dataset changes. This phase does not detect unknown future synthetic fixtures automatically. Synthetic acceptance work must register new reviewed exclusions before relying on reports. Do not interpret the exclusion list as exhaustive certification of the remaining records.

## Periods and calculations

Default This Month; Today, Yesterday, Monday–Sunday This Week, Specific Date and inclusive Custom Date Range. Invalid modes/dates/reversed ranges/pages redirect with validation errors, and the error response page displays no fallback totals. Non-applicable date fields are ignored deliberately. Filter application starts at page one; pagination preserves applicable filters.

All components use the same `reservations.created_at >= start AND created_at < next-day-end` UTC interval derived from Asia/Manila calendar boundaries and the same validated exclusions. `updated_at`, status history and notifications do not determine submission membership. Status counts describe the **current status of those submissions**, not period-end status or transitions in the period. Unknown stored statuses block reporting rather than disappearing from the sum.

SQL aggregates compute status counts, time buckets and top services. A read transaction validates identities and reads the report together (MariaDB's normal REPEATABLE READ consistent snapshot; SQLite transaction in tests). There are no write statements, materialized report rows or whole-history PHP collections. Only aggregated buckets, five service rows and a 15-row database page are loaded. Service ties use ascending service ID; detail ties use descending reservation ID after descending submission timestamp. Concurrent changes can move offset-page boundaries between separate requests.

Asia/Manila has a fixed UTC+08 offset for the contemporary application data. SQL bucket conversion uses that fixed offset, not MariaDB named-timezone tables: SQLite `strftime(..., '+8 hours')`, MariaDB `DATE_FORMAT(DATE_ADD(..., INTERVAL 8 HOUR), ...)`. This is intended for the application's Philippine civil-time records, not reconstruction of historic timezone changes.

Charts zero-fill aggregated buckets. Single-day periods use hours; 2–93 days use days. To keep long custom periods readable without truncating reservations, longer ranges through 731 days use months; longer ranges use years. The displayed bucket unit and exact date/count disclosure make this adaptation explicit. SVG/CSS charts require no new dependencies or JavaScript. Top 5 is a subset when more services exist; it is not falsely presented as reconciling to the total by itself. All-service membership still follows the shared population.

Names use current linked User/Service relationships; historical service names are not fabricated. No historical period-end status or original appointment snapshot is reconstructed. Generated/printed documents are not counted as releases.

Existing indexes support the current small development dataset. An unqualified creation-date range can scan the status/created_at index rather than use a leading created_at range. No index migration is added without separate approval and representative-volume evidence. SQLite tests alone do not establish MariaDB performance or concurrency.

## Acceptance and Git

Review focused exclusion/date/authorization/pagination/read-only tests, full regression, existing JS tests, build, targeted Pint, route list and diff checks. Browser QA uses isolated SQLite synthetic fixtures, including pagination and all statuses. Keep the real development manifest ignored; list it separately from intended tracked files. Never stage it with a force-add.

No automatic Git checkpoint, real migration, backup, deployment or next phase is performed. Physical printing and exports are not part of this report page.

## Implementation handoff — October 23, 2026

Checkpoint: implementation ready for user final acceptance review, not yet committed.
Branch `feature/reports-analytics`; existing HEAD `1aa65c2` (Phase 13 notifications).
Intended change set: 3 modified files and 9 new files, including this document;
the environment's private manifest is a separate ignored runtime artifact.

The approved development manifest was activated only after all eight expected
identity tuples matched. Real MariaDB 10.4.32 read-only execution verified hourly,
daily, monthly and yearly grouping without named timezone tables. October's report
reconciled 8 included submissions and 8 reviewed exclusions across status totals,
chart totals and table count. This is not certification of the remaining records.
Fingerprints of 10 business tables matched before and after execution. Actual
session isolation was REPEATABLE-READ. A separate temporary config-cache probe
validated all eight exclusions with cached configuration and confirmed that no
manifest contents entered the cache. That temporary cache was removed; the main
deployment cache location was not used.

Final focused report tests: 35 passed / 237 assertions. Final full PHP suite:
804 passed / 8,240 assertions in isolated SQLite. Existing JavaScript tests:
19 passed. Vite production build, targeted Pint, 115-route inspection and
`git diff --check` passed. No new JavaScript dependency or migration was added.

Browser QA ran on an isolated SQLite dataset on localhost:8008: 38 requests,
one reviewed fixture exclusion and 37 report members. Factory accounts were
test fixtures only; no real accounts were modified. Verified all six period
modes, filter-preserving pagination, existing details navigation, empty state,
reversed-range feedback without fallback totals, expandable exact chart data,
malformed-manifest blocking/restoration, Guest login redirect and Resident 403.
1440/768/390/320px had no page-level horizontal overflow; the table scrolls in
its own region and keyboard scrolling worked at 320px. Screenshots and a browser
QA JSON record are outside Git in the task's visualization artifact directory.
No browser console warnings/errors were observed. The temporary QA server/tab
were closed and the viewport override was reset. Browser QA did not sign in to
the real database application on port 8000; real database verification above was
read-only query execution.

No new database dump, private-storage backup, restore rehearsal, commit, push,
merge, tag or deployment was performed. Remaining limitations are current linked
names, explicit manifest maintenance, long-range bucket adaptation and unproven
large-volume performance. The next action is user acceptance review, followed
only when authorized by an intended-files-only Git checkpoint. Phase 14B/14C/14D,
later phases and IoT were not started.

## Final audit correction — JSON list types

The independent acceptance audit reproduced a fail-open shape-validation defect:
associative JSON decoding collapsed an empty object `{}` into the same PHP value
as an empty array `[]`. A numeric-key object could likewise pass the old PHP
list check. A temporary malformed-manifest probe therefore displayed 16 October
submissions instead of applying the eight reviewed exclusions. The active
manifest and real business data were never changed by that audit.

The bounded correction decodes JSON with objects preserved. The root and binding
must be JSON objects; `excluded_reservations` must be a JSON array, and each
identity entry must be an object. Entries convert to PHP arrays only after their
JSON type is checked. Existing identity, review, binding and duplicate-ID guards
remain enforced. An explicitly reviewed empty JSON array remains supported.

Regression tests cover empty objects, numeric-key objects, array-shaped entries
and the valid reviewed empty array. The two object-shaped exclusion-list cases
failed before the fix and pass after it. Focused reports plus dashboard/payment/
timeline regression: 102 passed / 693 assertions. Real MariaDB read-only probes
confirmed both malformed shapes now block without totals, while the active valid
manifest still reconciles 8 included, 8 excluded, 8 chart submissions and 8 table
rows. The active manifest hash and all ten business-table fingerprints remained
unchanged. No frontend, route, dependency or migration changes were needed for
this correction. Prior browser/build/JS evidence applies to the unchanged UI;
these were not rerun solely for the parser correction.

Final full PHP regression after the correction: 808 passed / 8,264 assertions.
The report-specific suite now contains 39 tests / 261 assertions (included in
the focused and full totals above). Targeted Pint and final diff checks passed.
The reproduced JSON-shape finding is resolved; the implementation is ready for
the user Git checkpoint with the previously documented non-blocking limitations.
No commit, push, merge, tag, migration or later phase was performed.
