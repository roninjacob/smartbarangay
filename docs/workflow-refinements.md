# Workflow refinement checkpoint — October 27, 2026

Implementation remains uncommitted on main at base ec85a26. Ronin performs final acceptance and Git operations. Existing Dealyn handoff documents are preserved.

## Delivered behavior

- Admin Service and Schedule edit forms carry a record-bound HMAC revision. The locked current row is checked before mutation. Missing, tampered, foreign-record and stale revisions fail with a reload action; stale form data cannot silently acquire a current revision during error rendering. Service revision also covers current master-template identity.
- New schedules and changed appointment ranges must start in the future in Asia/Manila. Unchanged historical ranges retain permitted capacity edits. Referenced date/time edits and deletion remain blocked. Upcoming/Past organization from the preceding pass remains included.
- My Reservations has owned request-number/service search, status and Active/History filters with database pagination. UI filters never accept another user's ownership scope.
- Request details explain the Resident's next step and link directly to the owned QR ticket. Viewing a ticket or its request does not consume it. Completed tickets remain historical.
- Resident appointment selection has an optional date filter and 12-slot database pagination. Existing final locked eligibility/capacity/duplicate checks remain authoritative.
- Profile labels explain separate section saves and preserve the legacy full document address. A draft belonging to the current Resident adds Return to your request; its destination is a fixed authorized route.
- Admin Details surfaces the current task, removes the duplicate preparation link while Approved/Ready, and separates payment from the main information card. Document preparation, arrival, payment and completion remain distinct operations.
- Admin service cards describe actual document workflow: Dynamic printing ready, Manual processing, Fee setup required or Setup needs attention. This is a current UI hint, never a replacement for submission-time validation. No live service was activated or reconfigured.
- New rejections require a separate plain-text Resident explanation (maximum 1,000 characters). Admin notes stay private. Only rejected transitions accept this message. Residents see the escaped explanation only on their own request/details timeline; historical internal notes are never copied to it.
- Existing configuration audits now include Resident activation, official master changes and dynamic configuration/fields/layout changes. Only allowlisted configuration/state values are stored; credentials and private file paths are excluded. Audit failure rolls back the mutation.

## Schema and controlled local rollout

One additive migration: `2026_10_27_000028_add_resident_message_to_reservation_status_histories.php` adds nullable TEXT resident_message. Existing records are not backfilled.

Applied to the configured local smartbarangay MariaDB in batch 21 after a verified database-only dump. Fingerprints of existing business columns/rows matched before/after and every existing public message remained NULL. No reservation, profile, service configuration or payment fixture was written to real MySQL.

Backup: `C:/xampp/htdocs/smartbarangay_backups/smartbarangay_before_workflow_refinements_2026-10-27_165324_ec85a26.sql` (146,730 bytes, 28 table definitions).
SHA-256: `e9d9dc25bd4f0670a2d17a73886106cc64516e266a71c1d0022c82a0243ad31a`.
The checksum and rollout fingerprint metadata are beside the dump; directory/direct-file HTTP access returned 403. This is not a tested restore or a private-storage backup. Other installations need this normal forward migration after their own backup; never reset/reseed them.

## Verification

- New WorkflowRefinementsTest exercises stale service/schedule writes, Manila cutoff, owned filtering/pagination, QR display without use, safe draft resume, separate escaped rejection messages and audit projections.
- Existing mutation tests now explicitly submit current revision tokens. Existing transition-denial tests provide valid public explanations where required so they still exercise the state machine. Resident ownership tests now use the intended status filter while retaining forged-user checks.
- Browser QA uses an isolated SQLite/private-storage environment. No real business records were changed during browser tests. Two simultaneous forms proved latest-value preservation; a synthetic rejection proved public/private message separation; QR and Profile-return navigation worked.
- Affected Admin pages: 6 at each of 1440/768/390/320 (24 checks). Affected Resident pages: 4 at each width (16 checks). No page-level overflow or application error observed. Scheduler/restore/physical-printer acceptance is not claimed.
- Full PHP, JS, build, targeted Pint, routes and diff results are recorded in the final report below.

## Deliberately pending

- The user approved temporary public contact guidance while official details are being obtained. The landing page now offers known location, visit planning, account assistance and a role-aware tracking action. Exact office address/hours/phone/email remain clearly marked as awaiting confirmation; no invented contact numbers or hours are published.
- Rescheduling, correction of submitted files, payment reversal, certificate supersession and Completed reprinting need explicit business policies before implementation. No new transition or historical overwrite was introduced.
- A production scheduler runner and a full restore rehearsal still require a separate controlled operational task. No unattended automation was installed.
- Internal User/password preferences, roles, QR lifecycle, historical snapshots, exact preview-to-final PDF bytes, reporting exclusions, CSV protections and payment-before-Completed rules retain their established contracts.

## Intended files in this combined checkpoint

- `app/Http/Controllers/Admin/ReservationController.php`
- `app/Http/Controllers/Admin/ScheduleController.php`
- `app/Http/Controllers/Admin/ServiceController.php`
- `app/Http/Controllers/Admin/ServiceDocumentTemplateController.php`
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/Resident/RequestStatusController.php`
- `app/Http/Controllers/Resident/ReservationController.php`
- `app/Http/Controllers/Resident/ReservationViewController.php`
- `app/Http/Requests/Admin/SaveScheduleRequest.php`
- `app/Http/Requests/Admin/UpdateReservationStatusRequest.php`
- `app/Models/ReservationStatusHistory.php`
- `app/Services/ConfigurationAudit.php`
- `app/Services/DocumentTemplateConfiguration.php`
- `app/Services/ServiceDocumentTemplates.php`
- `database/migrations/2026_10_27_000028_add_resident_message_to_reservation_status_histories.php`
- `docs/automation-and-configuration-audit.md`
- `docs/schedule-organization.md`
- `docs/workflow-refinements.md`
- `resources/css/app.css`
- `resources/css/module-refinements.css`
- `resources/css/schedules.css`
- `resources/js/civic-interface.js`
- `resources/views/admin/reservations/show.blade.php`
- `resources/views/admin/schedules/form.blade.php`
- `resources/views/admin/schedules/index.blade.php`
- `resources/views/admin/services/form.blade.php`
- `resources/views/admin/services/index.blade.php`
- `resources/views/profile/edit.blade.php`
- `resources/views/resident/request-status/show.blade.php`
- `resources/views/resident/reservations/index.blade.php`
- `resources/views/resident/reservations/show.blade.php`
- `resources/views/resident/reservations/step-3.blade.php`
- `resources/views/welcome.blade.php`
- `tests/Feature/AdminReservationProcessingTest.php`
- `tests/Feature/AutomationAuditTest.php`
- `tests/Feature/DocumentAutomationHardeningTest.php`
- `tests/Feature/ReservationCancellationTest.php`
- `tests/Feature/ReservationGapClosureTest.php`
- `tests/Feature/ReservationNotificationsTest.php`
- `tests/Feature/ReservationWorkflowHardeningTest.php`
- `tests/Feature/ResidentReservationViewingTest.php`
- `tests/Feature/ScheduleManagementTest.php`
- `tests/Feature/ServiceCertificateTest.php`
- `tests/Feature/ServiceDocumentTemplateTest.php`
- `tests/Feature/ServiceFeesTest.php`
- `tests/Feature/ServiceManagementTest.php`
- `tests/Feature/UserPreferencesTest.php`
- `tests/Feature/WorkflowRefinementsTest.php`

The two pre-existing untracked Dealyn Taglish guides are separate documentation additions and were not changed in this implementation.

## Final validation

- Full isolated PHP suite: 1,081 passed / 10,863 assertions (215.46 seconds).
- New focused refinement tests: 7 passed / 70 assertions.
- Final targeted rollback/cancellation/refinement group: 45 passed / 540 assertions.
- JavaScript: 19 passed; Vite build passed; targeted Pint passed; 127 routes retained; git diff --check passed.
- Browser: 40 responsive checks plus actual filter, QR navigation, Profile resume, stale-form and public/private rejection flows. Local QA server and synthetic login are cleaned up after verification.
- No commit, push, merge, branch change, destructive database command or subsequent phase was performed.

Public-guidance follow-up (user-approved temporary content): UserPreferencesTest passed 28 tests / 184 assertions after the contact copy change; four additional public-page width checks passed with no overflow. Official contact details still require confirmation.


## Subsequent integrity correction pass

See [workflow-integrity-corrections.md](workflow-integrity-corrections.md) for fresh locked actor eligibility, reviewed requirement fingerprints, Schedule return context, pagination recovery and unsaved-answer protection. This pass adds no migration and preserves the conservative policy boundaries. Validation totals there supersede the earlier totals above for the combined working tree.
