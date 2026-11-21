# Phase 6: operational workflow and data integrity

Baseline: `861eb41` on `main` (Phase 5 committed), following `7974f15` (safe IoT
error reporting). Pre-flight found only the three previously unrelated untracked
files. No Phase 4/5 changes needed carrying into this task.

## Architecture inspected before implementation

| Stage | Authoritative path and existing safeguards |
| --- | --- |
| Register | RegisterRequest, ResidentIdentity, RegisteredUserController: structured identity, registration transaction, hashed password, pending residency, no self-approval |
| Email | EmailVerificationController: authenticated signed link, account/hash checks under User lock, database verification polling, dashboard redirect; no guest auto-login |
| Residency | Admin User Management and ResidencyProfileChanges: locked accounts, expected revision, immutable decision/audit rows; material identity/address edits reset approved residency to pending |
| Submit | ReservationController wizard: reviewed requirements/fee/configuration, fresh approved Resident lock, Service then Schedule locks, duplicate/current occupancy checks, immutable appointment/requirement/profile/document snapshots |
| Process | Admin ReservationController: locked eligible Admin then reservation; expected status and permitted transition checks; status/history/QR/notifications in one transaction |
| Cancel | Pending self-cancellation, or requested Under Review/Approved/Ready for Pickup cancellation reviewed by Admin. Request alone has no capacity effect. Paid, finalized or checked-in requests block cancellation approval |
| Payment | ReservationPayments: positive historical fee only, Ready for Pickup, fresh Admin/reservation locks, unique immutable payment, exact amount from fee_snapshot, notification in same transaction |
| QR | QrTicketVerifier resolves SBQ1 credential to the reservation, validates owner account/state and unused ticket. Browser/IoT verification supplies short-lived Admin-bound evidence; verification does not record arrival |
| Check-in | CheckInReservations requires explicit confirmation, Ready for Pickup, fresh evidence/ticket verification, Admin/reservation locks and unique checked_in reservation key; duplicate confirmation retains first actor/time |
| PDF | ReservationDocumentInformation snapshots, CanonicalDocumentPreview bounded/reusable private artifacts, protected GET review evidence, GeneratedReservationDocuments promotes reviewed bytes under locks, one finalized record, hash-checked private delivery |
| Complete | Manual Ready for Pickup to Completed, historical payment gate, QR used metadata and history/notification committed atomically; check-in/payment/finalization do not themselves complete |
| Read/report | Ownership-scoped Resident reads, protected Admin routes, private PDF delivery, immutable event/submission-based reporting and reviewed fixture exclusions |

Supported ordinary transitions: Pending → Under Review or Rejected; Under Review
→ Approved or Rejected; Approved → Ready for Pickup; Ready for Pickup → Completed.
Terminal states cannot be reopened. Pending cancellation and eligible
Admin-approved cancellation are separate existing paths to Cancelled.

Capacity policy is unchanged: Pending, Under Review, Approved and Ready for Pickup
hold capacity; Completed, Rejected and Cancelled release current occupancy.
There is no check-out, rescheduling, online payment or automatic expiry workflow.
Residency reapproval restricts new submissions; existing eligible operational
requests remain processable under their preserved snapshots.

## Verification approach

Add a linked HTTP feature scenario rather than replacing domain services: actual
registration/verification/review, wizard booking, approval, protected PDF review,
finalization, QR verification, explicit arrival, payment and manual completion.
Exercise retries, cross-owner access and material profile changes around the same
historical reservation. Add transaction rollback evidence at committed-notification
boundaries. Existing focused suites remain authoritative for individual validation,
CSRF, expiration, rate limits, device signatures and all 25 system fields.

Reuse the Phase 5 disposable MariaDB guard for independent-process Admin races.
Controller/domain race fixtures use synthetic validated inputs and trusted
server-created evidence; they test actual production locks, not browser/session
middleware. Real HTTP feature tests separately cover those boundaries. Never run
the fixtures against development port 3306 or a production database.

## Confirmed findings and minimal correction

No operational contradiction has been reproduced in the linked lifecycle or
tested Admin contention scenarios. No production application code, UI, route,
schema, status policy, PDF field registry or firmware was changed.

One **Low-severity test reliability defect** was reproduced: the existing
`IotDeviceApiTest::test_invalid_authentication_is_redacted` computed a future
timestamp at now+91 seconds, then validated it against a moving clock. Crossing
one second made it only 90 seconds ahead, legitimately inside the existing
authentication window. Freeze the Laravel clock for that test only. The +/-91
rejection expectations and production 90-second tolerance are unchanged; nonce,
expiration and other time-sensitive tests retain their own clocks.

## New evidence

- `EndToEndWorkflowIntegrityTest`: real HTTP registration, signed verification,
  residency review, wizard submission, ordinary status transitions, stale approval
  rejection, protected PDF GET review and exact-byte promotion, duplicate
  finalization rejection, explicit/idempotent arrival, historical-fee payment,
  payment-gated manual completion, used QR rejection and owner isolation.
- The same reservation survives material profile edits before finalization and
  again after completion; its profile/template/appointment snapshots and saved PDF
  bytes/metadata remain unchanged. Pending reapproval blocks new bookings while
  preserving existing historical access and eligible Admin processing.
- Approval and payment tests inject a failure **after** notification writes. The
  outer transaction restores the original reservation and leaves no partial
  ticket/history/payment/notification. Existing document/file failure suites cover
  artifact cleanup and source preservation.
- `OperationalWorkflowMariaDbTest`: independent PHP processes compete at the
  production reservation row lock for approve versus reject, duplicate payment,
  duplicate arrival, payment versus cancellation review, and payment versus manual
  completion. Original actor attribution, unique records and notification source
  keys are checked. Duplicate arrival is intentionally idempotent, not a second
  business event. Payment alone never auto-completes.
- The Phase 5 five-case MariaDB capacity suite remains unchanged and is rerun.

The disposable test setup and shutdown instructions are in
[reservation-capacity-integrity.md](reservation-capacity-integrity.md). With that
verified instance running, enable `SB_CAPACITY_TEST_PORT=33185` and run:

```powershell
php artisan test --compact --filter='OperationalWorkflowMariaDbTest|ReservationCapacityMariaDbTest|EndToEndWorkflowIntegrityTest|IotDeviceApiTest'
php artisan test --compact
```

Without opt-in, MariaDB tests explicitly skip; SQLite-only success is not claimed
as row-lock validation. Do not change the harness port/database checks to point
at development or production data.

## Existing compatibility coverage

The full suite also covers all allowed/disallowed transitions, cancelled/rejected
terminal handling, stale residency reviews, inactive/unverified actors, CSRF,
mass-assignment rejection, cancellation guards, immutable payments/audits,
preview ownership/HEAD/expiry/rate limits, original-source tampering, date/age
boundaries, all 25 system fields, report exclusions/financial reconciliation,
CSV escaping and device HMAC/replay/revocation controls. These are automated
coverage claims, not claims of live Hostinger or physical hardware verification.

## Safe browser acceptance checklist

Use an isolated synthetic environment, not real resident or financial records:

1. Register; verify the signed email on an authenticated browser; confirm the
   original verification page updates and guests are not auto-authenticated.
2. Confirm pending residency allows dashboard/history/profile but blocks new
   bookings. Review it as Admin; submit a request with requirements and a future
   slot. Verify its fee, appointment and resident information.
3. Review and approve; keep a stale second Admin tab and confirm its old decision
   is rejected without extra history or QR tickets.
4. Change material Resident information. Confirm reapproval is required for new
   requests and the existing document preview still shows submitted information.
5. Open the protected PDF, finalize, reopen/download and inspect print preview.
   Repeated finalization must preserve the first PDF and issue date.
6. Mark Ready for Pickup. Verify QR without arrival, then explicitly Confirm
   Check-In. Repeat confirmation and confirm the original actor/time remains.
7. Attempt unpaid completion; record the historical fee; confirm payment leaves
   status unchanged; manually complete. Confirm QR is historical/unusable and
   history/notifications reflect only committed changes.
8. In a separate unpaid/unfinalized/unarrived request, submit cancellation and
   verify capacity remains held until the Admin approves cancellation. Confirm
   payment/finalized/arrival guards prevent conflicting cancellation.
9. Reopen as the owner and another Resident; verify historical access and owner
   isolation. Check matching reports/CSV filters without changing business data.

## Limits and follow-ups

Bounded multi-process tests are not a load benchmark, formal proof of every
deadlock ordering, or a physical-device test. Controller-level race fixtures do
not replace real HTTP middleware coverage. Cross-device live email delivery,
physical ESP32 scanning, printer output and browser responsiveness remain manual
acceptance items. File/cache/logging outages and deployment permissions need
operational monitoring; DB transactions alone cannot roll back an unavailable
filesystem. Direct SQL bypasses model/event guards and remains outside normal
authorized application workflows. No auto-expiry, refund/correction, rescheduling
or check-out policy is introduced here.

## Verified local results — November 13, 2026

- Focused linked workflow, both MariaDB suites and corrected IoT device tests:
  48 passed, 379 assertions.
- Full PHP suite with disposable MariaDB tests enabled: 1,461 passed,
  14,313 assertions. All ten Phase 5/6 MariaDB scenarios ran rather than skipping.
- All 56 JavaScript tests, Vite production build, targeted Pint, PHP syntax and
  Git diff whitespace checks passed.
- MariaDB 10.4.32/InnoDB used only the guarded disposable server on loopback
  port 33185. It was cleanly shut down afterward; synthetic evidence remains in
  the existing private OS temporary directory outside the web root and Git.
- Production application/firmware, schema and Phase 5 files remain unchanged.
  The only existing-file edit is the two-line IoT test-clock correction.
- No production access, real-data writes, database reset, Git staging, commit,
  push or deployment was performed. Browser/physical acceptance remains pending.
