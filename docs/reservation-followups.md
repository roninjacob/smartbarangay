# Reservation follow-ups and paper guidance

## Approved scope

- Pending Resident cancellation remains immediate and unchanged.
- Under Review / Approved / Ready for Pickup may request office-reviewed
  cancellation only if there is no payment row, finalized document row or
  operational arrival. Any payment row, including inconsistent payment data,
  blocks cancellation. A missing physical PDF does not bypass the document guard.
- One pending cancellation per reservation. A declined request can be followed by
  a new request; the prior decision is preserved. Each decision is final for its
  request. Approval requires current status evidence, fresh eligibility and a
  locked reservation, followed by a scoped locked cancellation request.
- Approved cancellation writes Cancelled and history atomically with the decision
  and notifications. QR credentials and used_at/used_by are untouched. The existing
  verifier rejects Cancelled requests. There is no global Admin status shortcut
  from Approved to Cancelled that could bypass these guards.
- Requests and office explanations are escaped. Internal attendance notes are
  Admin-only. Viewing pages and worklist filters creates no history or arrival.

## Missed appointments and late visits

Past appointment with no arrival is an observation, not proof of absence.
No scheduler auto-cancels, auto-completes, consumes a QR or creates a no-show.

An Admin may explicitly record one missed-pickup review only if:

1. The reservation has an immutable appointment snapshot and it has ended in
   Asia/Manila time.
2. The reservation is currently Ready for Pickup.
3. Authoritative status history proves it was Ready for Pickup by appointment end.
4. There is no operational checked_in arrival and no prior missed review.

Legacy mutable schedule dates cannot establish this evidence. An ordinary scan
does not count as arrival. Recorded missed attendance has its own actor, timestamp
and private note; it does not alter processing status, files, fees, payments or QR.
A later explicit check-in remains allowed under the existing QR/evidence checks.
The UI then shows the prior missed review and later arrival separately.

Admin worklist filters expose pending cancellations, past Ready appointments with
no arrival, and recorded missed reviews. These are operational filters, not new
statistical report denominators. The past-appointment filter does not claim no-show.

## Paper sizes

The existing FPDI renderer and per-page print sizes are unchanged. PDF.js reads
actual page dimensions for A4, A5, Letter, Legal, Long/Folio and Custom labels.
Legal (8.5 × 14 in) is distinguished from Long/Folio (8.5 × 13 in). Mixed sizes
produce explicit guidance. The UI does not resize or regenerate finalized PDFs.
Browser/printer settings and loaded paper still need physical acceptance testing.

## Deployment

Forward migration: `2026_10_28_000029_create_reservation_followup_tables.php`.
Two new tables only; no historical rows are backfilled or modified. Backup before
the controlled migration. MySQL/MariaDB DDL is not transactionally rolled back;
verify both tables and the migration ledger after running it.

Existing exact-reviewed-preview promotion, payment-before-completion, Admin-only
check-in, CSRF and account eligibility remain authoritative. Simultaneous MariaDB
lock scheduling is not proven by SQLite feature tests.

## Deliberately separate policies

No paid/finalized/checked-in cancellation, refunds, document revocation/reissuance,
automatic expiry, penalties, or automatic rescheduling. Rescheduling requires an
approved appointment-revision/capacity/QR policy; never edit the original snapshot
or cancel and re-create a paid/finalized transaction as a workaround.
## Verified implementation checkpoint — October 28, 2026

- Full isolated PHP regression: 1,118 passed / 11,249 assertions.
- JavaScript: 32 passed, including paper-size identification and mixed-size guidance.
- Vite build, targeted Pint, route registration and diff checks passed.
- Browser: Resident request → office approval, explicit missed review, and A4
  detection with enabled Print action. Follow-up forms and print UI checked at
  1440/768/390/320px without page overflow. Real localhost worklist filters loaded
  successfully using GET only; no real cancellation or arrival was recorded.
- MariaDB 10.4.32 forward migration applied in batch 22. Both new tables were empty.
  Before/after fingerprints of 20 existing business tables matched exactly.
- Verified pre-migration backup: smartbarangay_before_followups_2026-10-28_104309_7bde3f6.sql
  in C:/xampp/htdocs/smartbarangay_backups; 146,261 bytes / 28 tables.
  SHA-256: 2188adfeb26571fa58ede03301ae59b6c6fc41e6a3030c99135c4b1f1eb2f6fc.
  Database-only backup; a restore rehearsal was not performed.
- Physical printer output and real simultaneous MariaDB lock contention were not
  exercised. Hardware integration remains design-only by the user's decision.
- No Git commit, push or branch operation was performed. Prior UI changes remain.
