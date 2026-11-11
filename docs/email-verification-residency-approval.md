# Email verification and Admin residency approval

## Implemented flow

Registration creates an active, email-unverified Resident with pending residency. The signed email link requires authentication as the same account. A different device first signs in and then resumes that account's signed link. Existing signature, expiry, email hash and account checks apply. Successful verification opens the Resident Dashboard. Admin accounts open their Admin Dashboard. An unauthenticated device must sign in before it can verify the account.

The original verification page checks its authenticated account's current database state every 10 seconds while visible. Returning to the tab also triggers a check, with a minimum five-second gap. Confirmed verification redirects that session to its role dashboard. Expired sessions show a sign-in instruction. Hidden/departed pages abort requests and pause checks. Requests have an eight-second deadline. A rate-limit response backs off for at least one minute. Direct visits to the notice and resend action redirect verified accounts to their dashboard.

The status endpoint returns the session account's verification boolean and role dashboard URL. Its dedicated rate limit allows 12 checks per minute per account. It has private, no-store responses. The signed-link and resend limits have independent keys.

Email-verified Residents can use their dashboard, permitted profile fields, settings and existing reservations. New-reservation wizard routes require approved residency. The final submission transaction locks and reloads the Resident through `AccountEligibility`, then checks residency before service/schedule checks or reservation/file writes. The current application has one reservation creation writer and no separate Resident reservation-creation API. Future creation APIs must enforce the same approval check under the User lock.

## Residency decisions

The four values are `pending`, `approved`, `needs_correction`, and `rejected`. Account active status, role and email verification retain their existing meanings.

User Management has a residency filter, current state, review form and paginated history. An active, email-verified Admin can approve, request corrections or reject an account. Correction and rejection require a message visible to the Resident. Admins can reconsider earlier decisions. The submitted revision must match the current locked User row, and repeating an identical decision is rejected. A dedicated rate limit allows 20 review attempts per minute per Admin.

Every decision records the previous/new state, reviewing Admin, UTC timestamp and Resident-visible message in `residency_reviews`. The current User row stores the latest reviewer/time and incremented revision. Both writes share a transaction. History updates and deletion are blocked through the model. Foreign keys preserve review attribution. Unverified-account cleanup preserves accounts with residency review history.

Material changes to full name, birthdate, recorded address, street or zone/purok return an approved Resident to pending review and clear the current reviewer/time. Both profile update endpoints lock the parent User row, compare submitted values to stored values, and record old/new material values, actor, timestamp, status and revision in the existing immutable `audit_logs` table. Prior Admin decisions remain in `residency_reviews`. Admin User Management shows both histories with separate pagination. Every material edit increments the review revision, including edits while pending, needs-correction or rejected, so an open stale Admin review form cannot approve changed information. Those non-approved states retain their existing decision until an Admin reviews them. Identical saves, contact number, picture, theme, password, civil status, occupation and emergency contacts retain approval. Admin profile edits retain their existing behavior. Profile edits and Admin review serialize with final reservation submission through the same parent User lock. Approval can be recorded before email verification or while an account is inactive, because these are independent checks. Actual submission requires an active, email-verified Resident with approved residency. Evidence collection follows the barangay office's review procedure.

## Migration and existing accounts

Migration `2026_11_11_000034_add_residency_approval.php` adds four User columns and one history table. Existing accounts receive `pending`, revision zero and empty reviewer/time fields. Admin routes continue to use their existing role, active-account and email checks. The migration performs no bulk approval or historical-record classification.

Validation applied and reversed the migration in isolated SQLite and generated MariaDB SQL using `--pretend`. The forward migration was then applied to the confirmed local XAMPP development database after a verified private SQL backup. Existing-field and record fingerprints matched across users, reservations, QR tickets, check-ins, payments, finalized documents and reservation status history. All 12 existing local Residents have pending residency and the new review history is empty.

The local pre-migration backup and SHA-256 record are under `C:\Users\ronin\SmartBarangayBackups\database\residency_20261111_211732`. Production migration remains an owner-managed deployment step.

Existing Residents need individual Admin review before creating another request. Their existing reservations remain accessible under existing ownership rules, including valid QR verification, explicit check-in, payment and completion workflows.

The schema rollback removes residency columns and decision history. Once real decisions exist, preserve an export of that history and assess data loss before rollback. A forward correction can retain those decisions.

## Local acceptance

1. Back up the selected local development database and verify the backup. Confirm the configured connection points to that local database.
2. Inspect the SQL and then apply this migration locally when ready.

   ```powershell
   php artisan migrate --pretend --path=database/migrations/2026_11_11_000034_add_residency_approval.php
   php artisan migrate --path=database/migrations/2026_11_11_000034_add_residency_approval.php
   npm run build
   ```

3. Register a synthetic Resident. Confirm the notice page appears and residency remains pending. Open the signed link in a separate browser/device, sign into that same synthetic account, and complete verification. Keep the original tab visible or return to it. Both the verification browser and the original tab should open the Resident Dashboard, with the original tab updating within the next check interval.
4. Confirm dashboard/profile access, pending residency feedback and rejection of all new-reservation wizard endpoints. Visit the verification notice again and confirm it redirects to the dashboard.
5. As Admin, open User Management, filter pending residency and open the synthetic Resident. Review the recorded information and use the office's evidence procedure. Request corrections with a message. Confirm the Resident can read the message and update their profile while new requests remain blocked.
6. Approve the Resident. Confirm one new immutable decision entry and the updated reviewer/time. Reuse a stale review form from another tab and confirm rejection.
7. As an approved Resident, change a material address field and confirm pending residency, the immutable profile-change audit, and blocked new requests. Confirm unchanged values and cosmetic settings preserve approval. Have an Admin review the changed information and approve again. As the Resident, complete the normal reservation wizard. Recheck existing document requirements, capacity, appointment time and submission safeguards. Repeat with rejected and needs-correction accounts and confirm submission remains blocked.
8. Open an existing reservation as its owner. Use its existing valid QR in Admin verification and the ESP32 workflow when hardware is available. Confirm that explicit Admin check-in and eligible payment/completion actions follow the existing workflow.

Automated checks use isolated SQLite and synthetic fixtures. Existing booking fixtures explicitly use the new `residencyApproved()` factory state. Factory defaults and registration still produce pending residency. The registration-to-final-PDF acceptance test now performs an explicit Admin residency decision before booking.

## Hostinger deployment precautions

The owner manages deployment after reviewing the checkpoint. Preserve a verified private database backup, the prior release and its Vite manifest/assets. Check PHP CLI selection and the configured production connection privately. Keep existing private storage and environment configuration in their configured protected locations.

Schedule a controlled maintenance window for the additive User-table migration. Deploy the reviewed PHP files and matching compiled Vite assets together. Apply the single reviewed migration and inspect `migrate:status` before reopening the application. MariaDB DDL can commit in stages, so an interrupted migration needs inspection of the actual columns, foreign keys and history table before attempting recovery.

```sh
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_11_11_000034_add_residency_approval.php --force
php artisan migrate --path=database/migrations/2026_11_11_000034_add_residency_approval.php --force
php artisan config:cache
php artisan view:cache
```

Confirm the existing HTTPS `APP_URL`, signed verification email URL, cookie/session settings and outbound mail configuration match the deployed domain. Smoke-test with a synthetic Resident and a verified Admin. Approve existing Residents individually through User Management after evidence review. Save the review history alongside normal private database backups.

## Files in this checkpoint

Validation on November 11, 2026 passed with 1,285 PHP tests and 12,813 assertions, 56 JavaScript tests, the Vite production build, targeted Pint, PHP syntax checks, route inspection and `git diff --check`. Focused authentication/profile/residency checks passed 99 tests and 1,100 assertions. Final pre-commit regressions cover dashboard verification redirects, material profile re-review, stale review rejection, atomic audit rollback, immutable history and the historical final-PDF pipeline. Migration rollback and role-constraint regressions passed in isolated SQLite. The local MariaDB forward migration completed in batch 27 after backup verification.

Cross-device synchronization is covered by server-side session/database tests and JavaScript polling tests. Physical email delivery, a real two-device browser run and production Hostinger acceptance remain owner acceptance steps.

### New application files

- `app/Enums/ResidencyStatus.php`
- `app/Http/Middleware/EnsureResidencyApproved.php`
- `app/Http/Requests/Admin/ReviewResidencyRequest.php`
- `app/Models/ResidencyReview.php`
- `app/Services/ResidencyProfileChanges.php`
- `database/migrations/2026_11_11_000034_add_residency_approval.php`
- `resources/js/email-verification.js`
- `resources/views/partials/residency-status.blade.php`

### Modified application files

- `app/Console/Commands/CleanupUnverifiedUsers.php`
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/Auth/EmailVerificationController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/Resident/ReservationController.php`
- `app/Http/Requests/Admin/FilterUsersRequest.php`
- `app/Http/Requests/UpdateProfileRequest.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `database/factories/UserFactory.php`
- `resources/js/app.js`
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/users/show.blade.php`
- `resources/views/auth/verify-email.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/dashboards/resident.blade.php`
- `resources/views/errors/403.blade.php`
- `resources/views/profile/edit.blade.php`
- `routes/web.php`

### New tests and documentation

- `tests/Feature/ResidencyApprovalTest.php`
- `tests/Feature/ResidencyReverificationTest.php`
- `tests/Js/email-verification.test.mjs`
- `docs/email-verification-residency-approval.md`

### Existing test fixtures updated

- `tests/Feature/AuthenticationAdditionsTest.php`
- `tests/Feature/DocumentAutomationHardeningTest.php`
- `tests/Feature/Milestone3AcceptanceTest.php`
- `tests/Feature/ReservationDuplicatePreventionTest.php`
- `tests/Feature/ReservationGapClosureTest.php`
- `tests/Feature/ReservationNotificationsTest.php`
- `tests/Feature/ReservationWorkflowHardeningTest.php`
- `tests/Feature/ResidentDemographicProfileTest.php`
- `tests/Feature/ResidentDocumentInformationTest.php`
- `tests/Feature/ResidentReservationViewingTest.php`
- `tests/Feature/ResidentReservationWizardTest.php`
- `tests/Feature/ServiceDocumentTemplateTest.php`
- `tests/Feature/ServiceFeesTest.php`
- `tests/Feature/UnverifiedAccountCleanupTest.php`
- `tests/Feature/WorkflowIntegrityCorrectionsTest.php`
- `tests/Feature/WorkflowRefinementsTest.php`
