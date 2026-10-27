# Workflow integrity corrections — 2026-10-27

Status: implementation complete; final validation results recorded below. This adds to the existing uncommitted workflow refinement work, without replacing it.

## Confirmed defects closed

### Fresh actor eligibility

`AccountEligibility::lock()` requires an active transaction, locks and re-reads the acting User, and requires the expected role, active flag and verified email. It runs before target Service, Schedule or Reservation locks.

Integrated into payment recording, explicit QR check-in, status transitions, PDF finalization, protected preview creation, Resident submission/cancellation, and Admin configuration writes (services, requirements, schedules, official template removal, document configuration and Resident activation).

Existing Profile writes already lock/revalidate their owning User. Official template replacement is invoked inside the now actor-first Service transaction. Audit logging does not acquire a late actor lock after target locks.

Revocation and writes serialize on the User row: a revocation committed before the write acquires its lock rejects the write. A write that already owns the lock may finish first; this does not retroactively cancel committed transactions.

### Reviewed requirements cannot drift

`RequirementChecklist` signs the Service ID and sorted requirement IDs, names, descriptions and required flags with an HMAC. Step 2 submits the signature from the rendered checklist. The server stores the reviewed signature in the session and verifies it during progression, confirmation and final submission, including after locking the current requirements.

Missing, malformed, foreign-service or stale signatures require a fresh review and invalidate the old confirmation token. Rename/add/delete/description/required-flag changes cannot silently relabel an upload or create a mismatched snapshot. Unchanged reviewed checklists remain bookable, including empty checklists. Submitted historical snapshots remain immutable.

Existing in-progress wizard sessions without this new signature must re-review Step 2 once after deployment. No schema change or data backfill is needed.

## UX corrections

- Schedule create/edit/status/delete forms carry only validated local view/status/from/to/page parameters. Success returns to that context; arbitrary return URLs/extra keys are rejected before writes.
- Empty pages with matching slots elsewhere offer Back to first page. They do not claim that no appointments exist. Empty pagination summaries are hidden.
- Custom document answers have form-scoped unsaved-change protection plus visible save guidance. Continue stores validated answers in the existing session flow. No personal data is put into localStorage. This is a navigation warning, not background autosave; browser shutdown/mobile termination cannot guarantee preservation of unsaved input.

## Regression coverage

New `WorkflowIntegrityCorrectionsTest`: checklist changes after rendering and after confirmation, missing/tampered/foreign signatures, immutable snapshots, stale Admin eligibility for payment/check-in/finalization/status/configuration, local filter context, page recovery, and transaction requirement.

Existing wizard tests explicitly submit the checklist they intend to review. Upload rollback/validation tests now complete the fixture checklist and re-review before exercising file handling; production checks were not weakened to accommodate old fixtures.

JavaScript tests exercise edited/restored answers, normal submission and browser-history restoration.

## Policy boundaries approved by the owner

No automatic cancellation or no-show transition. No payment reversal/overwrite, certificate overwrite or rescheduling. Those require a separate correction/rescheduling design. Existing payment-before-completion, QR consumption only on Completed, private attachments and exact-reviewed-PDF promotion remain in place.

## Data and environment safety

No new migration, real MySQL migration, or real business-data mutation in this correction pass. PHP tests use isolated SQLite in memory and fake storage. Browser mutations use only the isolated synthetic `m4-live-qa/review.sqlite` environment at localhost:8019. Existing prior migration 000028 and its documented backup are unaffected.

SQLite proves rejection and rollback behavior, not simultaneous MariaDB lock scheduling. Actor-first production SQL locking order was inspected; no real database concurrency experiment was performed.

No commit, push, merge or branch change.

## Final validation — combined working tree

- Full isolated PHP suite: **1,100 passed / 11,115 assertions**, 156.84 seconds.
- Focused checklist/upload/document corrections: **67 passed / 836 assertions**.
- Earlier focused workflow/check-in group: **106 passed / 1,006 assertions**.
- New regression class: **19 tests**. Existing test changes explicitly provide reviewed checklist signatures and re-review fixtures before upload validation.
- JavaScript: **21 passed** (19 existing plus 2 unsaved-answer tests).
- Vite build passed. Targeted Pint passed. **130 routes** inspected; no routes added by this pass. `git diff --check` passed.
- Browser: 12 width checks (Admin Schedule, Resident page recovery, Resident document form at 1440/768/390/320), all without page overflow. Actual Schedule save retained Past/active/page context. Unsaved Profile navigation remained on the form; normal Continue succeeded and retained the answer on return. Recovery preserved the selected date and displayed the existing future slot. No console errors observed.
- Browser artifacts outside Git: `C:/Users/ronin/.codex/tmp/workflow-correction-browser-qa.json` and matching `workflow-correction-*.png` screenshots. Synthetic session signed out, temporary tab closed and the isolated QA server stopped. User's real browser tab/session was left alone.
- No new migration, real business-data edits or Git mutations. Ready for the owner's final acceptance review.

Files added in this correction pass:

- `app/Services/AccountEligibility.php`
- `app/Services/RequirementChecklist.php`
- `resources/js/unsaved-document-guard.js`
- `resources/views/admin/schedules/return-context.blade.php`
- `tests/Feature/WorkflowIntegrityCorrectionsTest.php`
- `tests/Js/unsaved-document-guard.test.js`
- `docs/workflow-integrity-corrections.md`

Existing files touched in this pass: the Admin Reservation/Schedule/Service/ServiceRequirement/ServiceDocumentTemplate/User controllers; Resident Reservation/Cancellation controllers; CanonicalDocumentPreview, CheckInReservations, DocumentTemplateConfiguration, GeneratedReservationDocuments and ReservationPayments services; Schedule index/form and Resident wizard Step 2/3 views; civic-interface.js; the shared test helper and affected wizard/upload tests; workflow-refinements.md. All earlier uncommitted refinements and the two Dealyn guides were preserved.
