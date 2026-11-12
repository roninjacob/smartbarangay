# Personalized PDF preview resource protection

## Previous lifecycle

Each authorized Resident/Admin preview-page GET called `CanonicalDocumentPreview::create`, rendered a fresh PDF while holding account/service/reservation database locks, wrote a random private file and cached a 30-minute token. Protected PDF GET established review evidence. PDF HEAD only validated the artifact. Finalization promoted the reviewed bytes. Abandoned temporary files were removed after one day by the existing daily cleanup.

## Account budgets

The common preview service enforces the same limits for both roles and every reservation. Changing the URL, reservation ID or query parameters keeps the same account budget.

| Operation | Limit |
| --- | --- |
| Personalized preview-page creation/reuse requests | 30 per minute per account |
| Fresh render attempts | 6 per minute and 30 per hour per account |
| Simultaneous preparation | One per account, protected by a 120-second cache lock |

These conservative shared-hosting defaults allow normal opening/reviewing and several corrections while bounding repeated rendering. Valid reuse spends the page budget only. Failed fresh render attempts spend generation budget. Throttling and overlapping preparation return HTTP 429 with `Retry-After` and an explanation in the existing preview layout. A busy account lock returns immediately with a two-second retry suggestion. It does not wait while holding database locks.

Protected PDF GET/HEAD and finalized-document viewing use their existing authorization and integrity checks independently of the generation budget. A preview-page HEAD verifies access without generating an artifact. Creating/reusing a preview does not establish review evidence.

## Safe reuse and publication

The private cache locator is keyed by authenticated user ID and reservation ID. The token entry remains authoritative. Reuse requires matching owner, reservation, fingerprint, server-derived issue date/date components, unexpired token, exact private path, file size and SHA-256 hash. Client-supplied fingerprints or issue dates have no effect.

The fingerprint retains the renderer version, preserved source identity/hash, reservation snapshot, saved template snapshot, field placements/text and applicable issue date. Live profile/master/layout edits preserve existing historical reservation snapshots. Changing the saved historical source, snapshot or placements invalidates reuse. The current master is used only where existing legacy-source compatibility checks already permit it.

Preparation follows these steps.

1. Acquire the shared account cache lock.
2. In a short database transaction, lock/recheck the account, service and reservation, authorize ownership, reject finalized records and validate the current historical document data.
3. Reuse a valid artifact, or reserve generation budget and render after leaving that transaction.
4. Verify cache-lock ownership and its deadline. In a second short transaction, repeat authorization, finalized-record and fingerprint/date checks before publishing the artifact.
5. Retire only the preceding temporary token/file belonging to this exact user/reservation pair after successful publication.

The token still expires after 30 minutes. Reopening a reused artifact retains its original expiry and any prior protected GET review. A fresh token starts unreviewed. The locator lasts one day so expired replacements can retire their preceding temporary file. Each indexed owner/reservation pair keeps one current artifact. Account generation budgets bound new artifacts across reservations. Pre-existing unindexed and abandoned files remain covered by the existing daily cleanup, which only deletes recognized `document-previews` paths older than one day and preserves live artifacts. Finalized PDFs and preserved sources are outside this cleanup namespace.

Source/configuration, eligibility, finalization or Philippine issue-date changes during rendering prevent publication. A preview from before Manila midnight cannot finalize on the next local date. Opening a new page creates a fresh unreviewed artifact for the new date. Finalization continues to store the exact reviewed bytes and preserves previously finalized files.

## Local acceptance and rollout

Use isolated synthetic accounts and documents for acceptance.

1. Open the same Resident preview repeatedly. Confirm the token/file stays the same and the PDF displays normally.
2. Repeat as Admin. Verify HEAD and an unopened preview cannot finalize. Review with protected GET, reopen the page, then finalize and compare the PDF bytes/hash.
3. In tests with a controlled clock, exercise minute/hour budgets, source/snapshot changes, expiry, missing/corrupt files, overlap and the Manila midnight boundary.
4. Confirm a 429 explains the retry delay and that the already-authorized PDF remains viewable.
5. Confirm foreign accounts/reservations cannot reuse or view the token. Confirm cleanup preserves generated PDFs and historical sources.

Use a shared persistent cache with working atomic locks and persistent rate-limit storage on Hostinger. The existing database cache/lock tables support this without a new migration. An array cache is appropriate for isolated tests. Separate per-worker caches cannot coordinate production budgets or preparation. Keep cache and PDF storage private, and keep the existing scheduler active for abandoned-file cleanup. Verify cache expiry/lock behavior on the actual hosting configuration before deployment approval.

Automated overlap tests exercise contention and lease expiry deterministically in isolated SQLite/cache fixtures. Actual multi-process MariaDB/cache contention and Hostinger load measurements require separate isolated runtime acceptance. The lock deadline prevents late publication; it does not interrupt PHP rendering already in progress. Normal PHP execution/memory limits still apply.

This change creates no schema migration and performs no real-data cleanup, hosting deployment or firmware modification.
