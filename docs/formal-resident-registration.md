# Phase 2 formal resident registration

## Implemented contract

Registration collects first name, optional middle name and suffix, last name, date of birth, contact number, optional house/unit number, street/sitio/purok, optional additional zone/purok, email and confirmed password. Birthdate, contact and street are required. The locality is always Barangay Calayo, Nasugbu, Batangas and is composed on the server.

Names accept Unicode letters, combining marks, spaces, apostrophes, periods and hyphens. Suffixes also accept numbers. First/middle/last components have an 80-character limit, suffix has 20, and the combined name must fit the existing 255-character name column. Whitespace is normalized. Birthdate must be a valid calendar date through today in Asia/Manila. Existing Philippine contact and password rules remain in use.

The four name components live on `users`. `User.full_name` formats them in first/middle/last/suffix order and falls back to the original `User.name` for legacy accounts. Normal registration and structured profile writes synchronize `User.name` for existing screens, queries and certificate mappings.

`resident_profiles.birthdate`, `address_street` and `address_zone_purok` remain the existing demographic sources. Only nullable `house_unit_number` is added to that table. For structured residents, `User.address` is a compatibility projection of those address fields and the fixed locality. Registration saves User and profile in one transaction before authenticating or sending verification. Profile updates lock the parent User and profile and save both atomically.

The personal-information form owns names, date of birth, contact and residential address. The separate demographic form displays optional civil status, occupation and emergency contacts. Its existing endpoint supports older demographic clients, validates any submitted identity fields and keeps a structured resident's address projection synchronized. It cannot clear the required birthdate/street of a structured resident.

## Existing residents and review history

Migration 000035 adds nullable columns only. It neither splits legacy names nor rewrites addresses, existing profiles, residency decisions or historical snapshots. Existing approved residents retain eligibility. No structured-name completion gate has been added to their existing reservation workflow.

An unsplit Resident sees their original name/address and a manual completion notice in Profile / Account Settings. Completing the separate fields is an explicit Resident action. The old full-name/address endpoint remains compatible for unsplit accounts, with the existing material-change audit and reapproval rules. Once structured fields exist, name/address-only requests must supply the structured form and cannot overwrite the projections.

Changes to any name component, birthdate, house/unit, street or zone/purok invalidate an approved residency decision and increment its review revision. Old/new material fields, projections, actor and UTC time are saved together in the existing immutable audit log. Prior Admin decisions stay in the decision history. The same User lock coordinates profile writes, Admin review and final reservation submission. Contact-only and optional demographic changes preserve approval. Admin profiles retain their existing full-name form.

Email verification continues to open the Resident Dashboard. New accounts remain pending residency review. Historical reservation/document snapshots are retained and existing PDF/QR operations use their current protected workflow. Separate certificate system-field selectors belong to the next phase.

## Local migration evidence

The verified local database was `127.0.0.1:3306/smartbarangay`, MariaDB 10.4.32. The five ALTER statements were inspected with `migrate --pretend`. The forward migration ran successfully in batch 28.

The private pre-migration dump, hash and before-record fingerprints are at `C:\Users\ronin\SmartBarangayBackups\database\formal_registration_20261111_141125`. The SQL SHA-256 is `3c3c4bf6e1d72ff930ec77c65c96e5ce15913b277a6ac65075b5bff9ab9f8cd6`.

Existing-column fingerprints and row counts matched before/after across users, resident profiles, residency reviews, audit logs, reservations, payments, finalized documents, QR tickets, check-ins and status histories. Local acceptance did not create or edit real Resident records.

## Validation and acceptance

Final full-suite validation passed 1,304 PHP tests with 12,962 assertions. The focused suite passed 142 tests with 1,244 assertions. All 56 JavaScript tests, the Vite production build, targeted Pint and `git diff --check` passed. The new formal-registration feature class contains 19 tests. Migration forward/rollback tests use isolated SQLite and the MariaDB forward SQL was inspected before local activation.

Automated tests use isolated SQLite. Coverage includes formal registration, rejected inputs, atomic User/profile creation, whitespace/optional components, name length, manual legacy completion, protected fields, residency reapproval, projection consistency, migration forward/rollback and the existing registration-to-final-PDF acceptance path.

The guest registration page was checked in the local browser at 1440, 768, 390 and 320 pixels. Inputs have associated labels, optional/required behavior matches the server contract, IDs are unique, and page-level horizontal overflow was absent. Browser console inspection returned no JavaScript errors. Authenticated profile/Admin actions are covered by HTTP feature tests. Physical cross-device email delivery and production acceptance remain owner checks.

For manual acceptance, register a synthetic account locally, complete email verification and confirm pending dashboard access. Review and approve it in Admin User Management. Save an identical profile and then a contact-only change and confirm approval remains. Change the house/street or a name component and confirm pending status, preserved history and blocked new requests. Have Admin review again before creating a new reservation. Reopen a previously submitted reservation and verify its historical document information and QR workflow.

## Safe owner-managed rollout

1. Review the uncommitted file set and automated results. Take a verified private production backup and preserve the prior application release/assets before a future hosting rollout.
2. Confirm the intended production connection privately and use a controlled maintenance window. The hosting deployment remains pending owner authorization.
3. Deploy the reviewed application and compiled assets, inspect the SQL, and apply only migration 000035 through the normal Laravel CLI.

   ```sh
   php artisan migrate:status
   php artisan migrate --pretend --path=database/migrations/2026_11_11_000035_add_formal_resident_information.php --force
   php artisan migrate --path=database/migrations/2026_11_11_000035_add_formal_resident_information.php --force
   php artisan config:cache
   php artisan view:cache
   ```

4. Verify registration, email/dashboard navigation, an unsplit legacy profile, Admin review, reapproval and an existing reservation/PDF/QR using controlled acceptance fixtures. Complete existing resident fields manually through their authenticated profile form after evidence review.
5. Preserve the backup and review/audit history. After collecting structured values, rolling back 000035 deletes those new values, so inspect and export them privately before considering schema rollback. MariaDB DDL may commit individual ALTER statements and requires inspection after an interrupted migration.

## Limitations

Residency remains an Admin evidence-review process. The form cannot prove that an entered address is a real Calayo residence. Legacy free-text addresses stay authoritative until explicit profile completion.

The existing unverified-account cleanup preserves accounts with resident profiles. Formal registration now creates such a profile, so these abandoned accounts will be retained under that existing safeguard. Changing personal-data retention requires a separate reviewed policy.

No new individual certificate placeholders are registered in this phase. The existing full-name, full-address and demographic mappings continue through their existing snapshot engine.

## Exact file manifest

### Added

- `app/Rules/ResidentIdentityRules.php`
- `app/Services/ResidentIdentity.php`
- `database/migrations/2026_11_11_000035_add_formal_resident_information.php`
- `resources/views/partials/resident-identity-fields.blade.php`
- `tests/Feature/FormalResidentRegistrationTest.php`
- `docs/formal-resident-registration.md`

### Modified

- `app/Http/Controllers/Auth/RegisteredUserController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Requests/Auth/RegisterRequest.php`
- `app/Http/Requests/UpdateProfileRequest.php`
- `app/Http/Requests/UpdateResidentDemographicsRequest.php`
- `app/Models/User.php`
- `app/Services/ResidencyProfileChanges.php`
- `resources/views/admin/users/show.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/profile/demographics.blade.php`
- `resources/views/profile/edit.blade.php`
- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/Milestone3AcceptanceTest.php`
- `tests/Feature/ResidencyApprovalTest.php`
- `tests/Feature/ResidentDemographicProfileTest.php`
