# Reporting production readiness and recovery

## What the yellow warning means

All four report services call `ReportingExclusions::validatedIds()` before calculating anything. Their controllers catch `RuntimeException` with `ReportingExclusions::UNAVAILABLE`, then render the warning with no report payload. Collections and recorded-event reports also use that message when partition/chart/service/table reconciliation fails. Reservations reject unknown stored statuses. This is not a reporting feature flag, and there is no separate database field to mark a report accepted.

The private manifest is deliberately ignored by Git. A code deployment does not supply the production review artifact. Its default location is `storage/app/private/reporting/exclusions.json`, relative to the Laravel application, **not public_html**. A local development manifest must not be copied as production approval: environment, connection binding and every five-member fixture identity must match the reviewed production dataset.

A reproduced code defect rejected identical JSON bindings with reordered object properties. The fix compares the same exact keys/types/values independently of order. It does not approve missing/unreviewed manifests, broaden exclusions, or change report populations. This defect is not proof of the production cause; use diagnostics below to establish it.

## Read-only SSH diagnostics

Run from the existing Laravel source directory outside `public_html`, using Hostinger's selected PHP 8.3 CLI binary (substitute its absolute path for `php` if necessary):

```sh
php --version
php artisan migrate:status
php artisan route:list --path=admin/reports
php artisan smartbarangay:reporting-check
php artisan smartbarangay:reporting-check --start=2026-11-01 --end=2026-11-10
```

The new command requires this reviewed code to be deployed by the owner first. It prints only safe reason codes, schema table names, period, cache state and PASS/FAIL. No names, financial amounts, IDs, SQL errors, credentials, manifest paths or contents are printed. Exit 0 means the selected period passes; exit 1 means blocked. It does not write a manifest, approve exclusions, migrate, repair rows, generate PDFs or export files. Each production report still validates independently in its existing read transaction; the diagnostic groups its checks inside an outer read transaction. MariaDB's existing REPEATABLE READ isolation should be retained.

Before deploying the command, this standalone probe works with the existing release and performs no writes:

```sh
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $service = app(App\Services\ReportingExclusions::class);
    $path = config('reporting.manifest');
    if (!is_string($path) || !is_file($path)) { echo "manifest_missing_or_path_invalid\n"; exit(1); }
    if (!is_readable($path)) { echo "manifest_unreadable\n"; exit(1); }
    if (filesize($path) > 1048576) { echo "manifest_too_large\n"; exit(1); }
    $data = json_decode(file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
    if (!($data instanceof stdClass) || ($data->version ?? null) !== 1) { echo "manifest_schema_invalid\n"; exit(1); }
    if (($data->reviewed ?? null) !== true) { echo "manifest_not_reviewed\n"; exit(1); }
    $actual = ($data->binding ?? null) instanceof stdClass ? get_object_vars($data->binding) : [];
    $expected = $service->binding();
    $ordered = $actual === $expected;
    ksort($actual); ksort($expected);
    if ($actual !== $expected) { echo "manifest_binding_mismatch\n"; exit(1); }
    if (!$ordered) { echo "binding_order_defect_in_old_release_possible\n"; }
    try {
        $service->validatedIds();
        echo "manifest_and_fixture_identities_pass\n";
    } catch (RuntimeException $e) {
        echo $e->getMessage() === App\Services\ReportingExclusions::UNAVAILABLE
            ? "guard_blocked_check_structure_and_fixture_identities\n" : "database_or_query_failure\n";
        exit(1);
    }
} catch (JsonException) { echo "manifest_invalid_json\n"; exit(1); }
catch (Throwable) { echo "database_or_configuration_probe_failed\n"; exit(1); }
PHP
```

This probe intentionally does not display tuple discrepancies or connection values. Do not paste `.env`, database dumps, or full exception/SQL traces into chat. A missing migration normally produces a query failure, not this particular caught readiness warning; verify rather than assuming.

## Formal owner review and activation (not performed by this task)

1. Preserve private backups of the current manifest and configuration before any owner-managed change. Keep recovery material outside the public document root and Git.
2. If the manifest is absent or bound to another environment, review the actual production dataset against retained acceptance-fixture provenance. Local approval of development IDs is not production approval. Do not classify by names containing TEST, inactivity, dates, or guessed IDs.
3. Privately obtain the production binding from `ReportingExclusions::binding()`. It contains exactly `environment`, `driver`, `host`, `port`, `database`; host, port and database are strings. Never include connection passwords. The following read-only command displays only those binding fields; keep the output private rather than sharing it publicly:

   ```sh
   php artisan tinker --execute='echo json_encode(app(App\Services\ReportingExclusions::class)->binding(), JSON_PRETTY_PRINT), PHP_EOL;'
   ```

4. Have the owner explicitly approve the complete exclusion list. Every entry requires positive integer reservation/user/service/schedule IDs and the original raw UTC `created_at` string. All five must match. Missing/mismatched/reused IDs must stop activation. An empty array is permitted **only after explicit review confirms that no records are approved for exclusion**; do not use it merely to unblock reports. Uncertain provenance is an unresolved policy decision, not permission to include/exclude guessed fixtures.
5. Using the existing schema documented in `reservation-reports.md`, prepare the approved manifest privately, version 1, `reviewed: true`, exact production binding and approved entries. Install atomically at the configured private path. Restrict permissions to the deployment owner/PHP runtime (for example 0600 when they run as the same user); do not use 0777. Do not put it in public_html or commit it. Verify direct HTTP access remains denied.
6. If the configured path changes, the owner updates `REPORTING_MANIFEST_PATH` privately and rebuilds `php artisan config:cache` through the normal deployment process. Cached configuration stores the path, not file contents. A corrected manifest at the same path takes effect on the next request; do not flush sessions or disable guards.
7. Run `smartbarangay:reporting-check` for Today, a known populated interval and an empty interval (using explicit dates). Read-only reconciliation verifies the existing queries; it never certifies unreviewed transactions as revenue. Investigate schema/query/reconciliation errors separately; never mark them resolved by editing historical records.
8. As active verified Admin, open all four pages, apply Today/This Month/custom ranges and an invalid reversed range, inspect empty states, and compare each chart/service breakdown/table with its totals. Collections must preserve the Valid/Integrity split. Confirm CSVs retain filters/exclusions. Resident and guest access must remain blocked.
9. Preserve the approved manifest separately during future deployments and re-review after database restores or new synthetic acceptance fixtures. A successful code test cannot establish production provenance or filesystem readiness.

## Existing report semantics preserved

- Reservations: UTC `created_at` selected by Asia/Manila calendar boundaries; current status, including cancelled/rejected submissions. This is not a historical period-end status report. No new status filter is introduced.
- Collections: actual immutable `reservation_payments.amount` and `paid_at`, consistent with a positive historical `fee_snapshot`; one payment per reservation under the existing unique constraint. Current service prices, unpaid requests and receipts/previews are not revenue. Inconsistent rows are period-bound warnings and a separate table partition. This application has no additional bank-reconciliation approval flag.
- Check-ins: only persisted `verification_result = checked_in` by `verified_at`, with valid reservation attribution. Ordinary QR verification attempts never count as arrival. Migration 000021 enforces one operational arrival per reservation; no scan histories are rewritten.
- Documents: persisted PDF finalization metadata by `generated_at`. Legacy/incomplete/unlinked records are period-bound warnings, not valid totals. Counts are historical finalized events, not a real-time filesystem audit or print count; previews/templates do not count.
- Every component and CSV uses the same validated exclusions and half-open UTC date interval. Existing financial/record partition reconciliation remains enabled.

No new migration, production data repair, IoT change, deployment, or automatic manifest approval is needed for the code correction. Actual production readiness remains pending safe diagnostic output and owner-approved configuration review.
