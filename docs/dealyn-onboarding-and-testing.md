# SmartBarangay: Dealyn's setup, testing and contribution guide

Prepared October 26, 2026. Commands below target Windows PowerShell.

## 1. Working agreement: who does what

**Dealyn tests and proposes fixes. Ronin and Codex review them. Ronin performs final acceptance and controls integration into `main`.**

| Person | Responsibility |
| --- | --- |
| Dealyn | Set up her own copy, test with synthetic data, report defects, make only assigned fixes on her branch, push that branch and submit a PR for review. |
| Ronin | Choose the starting checkpoint, assign work, review findings, perform the final whole-system test and decide whether to accept changes. Only Ronin integrates accepted work into `main`. |
| Codex | Help Ronin inspect the actual diff, review correctness/security/scope, run validation and identify remaining issues. Codex does not merge or push without Ronin's explicit instruction. |

Dealyn must not push to `main` or another person's branch, merge her PR, enable auto-merge, change repository access/rules, create release tags or deploy the application. A passing test run or Copilot approval does not replace Ronin's acceptance. New commits after review require another review of the new diff.

**A branch is not an access restriction.** A collaborator with repository write access may have capabilities beyond her assigned branch. Ronin should check the actual GitHub protection/rules for `main` and the approved base branch: require a PR and Ronin's review, prevent force pushes/deletion, dismiss stale approvals, and restrict integration actors where supported. Required reviews alone do not mean only Ronin can click Merge after approval. Availability depends on repository ownership, visibility and plan. If the required restrictions cannot be enforced, do not claim the branch is technically locked; use an owner-approved fork/PR workflow without upstream write access where available, or have Ronin receive and apply reviewed patches. Keep private source private. See [GitHub's protected-branch documentation](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches).

## 2. Project overview and boundaries

SmartBarangay is the Laravel application for **Barangay Calayo, Nasugbu, Batangas**. It supports document reservations, personalized certificates, QR verification, explicit arrival check-in, on-site payment recording, notifications, logs and reports.

- Exactly two roles: **Resident** and **Admin**. One barangay only.
- Backend: PHP 8.2 target, Laravel 12, Eloquent and MySQL/MariaDB.
- UI: Blade, Bootstrap 5, custom CSS, vanilla JavaScript and Vite.
- Documents: official PDF backgrounds, saved field placements and FPDI-based personalized PDFs. Do not introduce another document engine.
- Public registration creates Residents. Admin creation is Artisan-only.
- Read [the redesign handoff](milestone4-redesign.md), then the module document relevant to the assigned task.

`AGENTS.md` is local-only and is not delivered by cloning this repository. This guide includes the essential collaboration rules; ask Ronin for any additional task-specific rules. Do not commit his local AGENTS file.

## 3. Ronin: prepare the handoff first

At preparation time, the inspected workspace is on `feature/milestone4-redesign`, HEAD `93a1d13`, with additional **uncommitted** UI changes. That hash is a reference point, not a claim that the latest design is already on GitHub.

1. Review/validate the intended pending changes with Codex.
2. Inspect the exact file list and stage only accepted files, including this guide if approved. Never force-add ignored files.
3. Inspect the staged diff, then personally commit and push the accepted checkpoint.
4. Confirm the intended base is on GitHub. Do not branch from an outdated `main` just because it is the default.
5. Give Dealyn access using her own GitHub account. Do not share your GitHub password, token or recovery codes.
6. Create her branch from that accepted base. The example below assumes Ronin explicitly chose `feature/milestone4-redesign` as the base. If `main` is chosen instead, consistently replace the base and PR target.

Run these checks first; **stop if the working tree is dirty or the remote base is unexpected**:

```powershell
git status -sb
git branch --show-current
git log -1 --oneline
git fetch origin
```

After the checkpoint above is committed/pushed and the tree is clean, Ronin can run:

```powershell
git switch feature/milestone4-redesign
git pull --ff-only origin feature/milestone4-redesign
git switch -c qa/dealyn-whole-system
git push -u origin qa/dealyn-whole-system
git status -sb
git log -1 --oneline
```

If the branch already exists, inspect it and use it; do not overwrite it. A non-fast-forward error is a stop-and-review condition, not permission to force push.

Send Dealyn this filled handoff:

```text
Repository: https://github.com/roninjacob/smartbarangay.git
Assigned branch: qa/dealyn-whole-system
Approved base / PR target: [Ronin fills this]
Starting commit: [full accepted commit hash]
Assigned task / allowed files: [Ronin fills this]
Known limitations / current findings: [Ronin fills this]
Final tester and integration owner: Ronin
Review process: Dealyn -> Ronin + Codex -> Ronin final test -> Ronin integration
```

## 4. Dealyn: prerequisites on your own laptop

Install Git, VS Code, Composer 2, Node.js/npm and XAMPP with a PHP version compatible with the PHP 8.2 target. Use reputable official installers. The installed Vite 7 dependency declares Node `^20.19.0 || >=22.12.0`; use a supported Node LTS satisfying that range.

In a new PowerShell terminal, check:

```powershell
git --version
where.exe php
php -v
php --ini
composer --version
node -v
npm -v
php -m
```

Ensure CLI PHP is the intended XAMPP PHP, not an unrelated installation. If necessary, add your own `C:\xampp\php` to PATH and reopen the terminal. Do not copy Ronin's temporary PHP configuration paths.

PHP needs the Laravel platform extensions and the application's image/PDF/test support. Check `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `mbstring`, `openssl`, PDO, `pdo_mysql`, `pdo_sqlite`, `session`, `tokenizer` and `xml`. Composer's platform check below is authoritative for installed dependency requirements; GD and SQLite also matter to image/QR and isolated-test workflows. Enable missing extensions in the PHP configuration identified by `php --ini`, then restart the affected process. Do not bypass platform requirements.

## 5. Clone and enter only the assigned branch

Accept Ronin's invitation first. Example local directory:

```powershell
Set-Location C:\xampp\htdocs
git clone https://github.com/roninjacob/smartbarangay.git smartbarangay-dealyn
Set-Location C:\xampp\htdocs\smartbarangay-dealyn
git fetch origin
git switch --track origin/qa/dealyn-whole-system
git status -sb
git branch --show-current
git log -1 --oneline
```

The branch must be `qa/dealyn-whole-system` and the starting commit must match the handoff. If a local tracking branch already exists, use `git switch qa/dealyn-whole-system`. Do not run clone inside an existing project directory.

GitHub's login prompt may use Git Credential Manager/browser authentication. Use your own account; never paste a token into a repository URL or issue.

## 6. Install the exact dependencies

From the project root:

```powershell
composer install
composer check-platform-reqs
npm ci
```

Use the committed lockfiles. Do not run `composer update`, `npm update`, `npm audit fix --force` or add packages as a setup shortcut. Dependency warnings are findings to report, not permission to upgrade.

Do not use `composer setup` for this onboarding: its script includes automatic migration. Configure and verify your own database explicitly first.

## 7. Create your private local environment

Only on a fresh clone without an existing `.env`:

```powershell
Copy-Item .env.example .env
```

Edit **your own** `.env`. Keep other required example settings. Use your actual local database credentials in place of the placeholders:

```dotenv
APP_NAME=SmartBarangay
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartbarangay_dealyn
DB_USERNAME=YOUR_LOCAL_DB_USERNAME
DB_PASSWORD=YOUR_LOCAL_DB_PASSWORD
DB_URL=

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS=smartbarangay@example.test
MAIL_FROM_NAME=SmartBarangay
```

Use the port configured in your XAMPP MySQL. The database must belong to **your laptop**, with no connection to Ronin's or a shared/production database. Prefer credentials scoped to your local database. Never copy Ronin's `.env`, APP_KEY, SMTP password or real dump.

Start XAMPP MySQL. Use phpMyAdmin's database creation screen to create an **empty** `smartbarangay_dealyn` database with `utf8mb4`; do not manually create application tables. Apache is needed if you access phpMyAdmin through Apache, but the application below runs through Artisan.

On the new installation, generate your own application key once:

```powershell
php artisan key:generate
php artisan config:clear
php artisan tinker
```

Inside Tinker, inspect only the effective connection:

```php
config('database.default');
DB::connection()->getDatabaseName();
config('database.connections.mysql.host');
exit
```

Expect `mysql`, `smartbarangay_dealyn`, and your intended loopback host. Stop if different. Do not print passwords. Once confirmed as your newly created local database:

```powershell
php artisan migrate
php artisan migrate:status
```

This authorizes setup migrations on Dealyn's new local database only. Never run `migrate:fresh`, `db:wipe`, destructive SQL, repair/backfill commands or restore operations as troubleshooting shortcuts. Do not rerun `key:generate` after every pull. Do not seed by default: the current DatabaseSeeder only creates a sample user; it is not a complete approved whole-system fixture pack.

## 8. Open the website

Build assets, then start the application:

```powershell
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open **http://127.0.0.1:8000/**. Keep the terminal running. For active CSS/JS editing, run `npm run dev` in a second terminal; otherwise rebuild after frontend changes. Use the application URL, not Vite's asset-server URL.

Keep the origin consistent: `localhost` and `127.0.0.1` have separate sessions. If the port changes, update your own APP_URL, clear config, restart and obtain fresh verification links. A different laptop's `127.0.0.1` is not Ronin's website. This setup does not expose the application to the internet or LAN.

Private files are served through authorized routes. Do not run `storage:link` to make requirement files, avatars, masters or generated certificates public.

## 9. Create test accounts and verify them normally

Use fictional records only, for example `Dealyn QA Resident A`, `Dealyn QA Resident B` and addresses under `example.test`. Choose your own passwords; do not put them in this guide or Git.

**Resident A and B:** register through `/register`. Log in and complete email verification. Use separate browser profiles for A, B and Admin, or log out before changing roles.

**Admin:** in a separate terminal in this local project:

```powershell
php artisan smartbarangay:create-admin
```

Enter fictional Admin details and a password interactively. The Admin remains unverified until completing the normal verification flow. Do not use Tinker/SQL to set `email_verified_at`, change roles or bypass middleware.

With `MAIL_MAILER=log`, messages go to `storage/logs/laravel.log`; they are not delivered to a real inbox. Read the latest relevant local message, sign in as that exact account, and open its complete signed verification URL using the same APP_URL before expiry. If needed, use the application's resend control. Password reset messages use the same local mechanism. Treat verification/reset links as credentials: never share them in Git, issues or screenshots.

For real email delivery testing, ask Ronin for a separately approved mail-capture/test-delivery setup; do not borrow his Gmail App Password.

## 10. Prepare a useful synthetic dataset

A fresh clone contains code, not Ronin's business records or private PDFs. Empty states are expected.

Use the Admin UI on your own database to prepare:

1. Two services: one with an explicit `0.00` fee and one with a positive fee, such as `50.00`.
2. A required requirement and an optional requirement, with fictional descriptions.
3. Future schedules with sufficient capacity in **Asia/Manila**. A slot is no longer bookable at its start time. Do not alter the laptop clock to defeat this rule.
4. A harmless sample PDF master clearly marked `SAMPLE / NOT VALID`, within the upload limit. Use no real seal, signatures, government ID or Resident document.
5. Document configuration, system/custom fields and non-overlapping placements through the existing Admin UI. Save and activate a valid configuration before testing dynamic requests.
6. Fake JPG/PNG/PDF supporting files and a harmless avatar. Never upload a real ID for QA.
7. Separate reservations for cancellation, rejection, free completion and paid completion. Final documents/payments/status histories are not disposable edit-in-place records.

Do not copy Ronin's private storage or SQL dump. If more fixtures are needed, request a reviewed synthetic fixture package; do not invent bulk SQL or build a seeder with Copilot without authorization.

## 11. Reports: explicit configuration review is required

All four reports/CSV exports use an environment-bound private exclusion manifest. A missing manifest intentionally makes reports unavailable. **Do not remove the check or copy Ronin's reservation exclusion IDs.**

On your own environment, inspect the binding:

```powershell
php artisan tinker
```

```php
app(App\Services\ReportingExclusions::class)->binding();
exit
```

Send Ronin the non-secret binding plus a description of the synthetic dataset for review. Keep report testing marked **Blocked - configuration review pending** until approved. Other modules can be tested meanwhile.

After approval, create the private file at `storage/app/private/reporting/exclusions.json`, using the exact binding and approved exclusion list. This example is **not an activated/approved manifest**:

```json
{
  "version": 1,
  "reviewed": false,
  "binding": {
    "environment": "local",
    "driver": "mysql",
    "host": "127.0.0.1",
    "port": "3306",
    "database": "smartbarangay_dealyn"
  },
  "excluded_reservations": []
}
```

Ronin must explicitly approve the binding/list before `reviewed` becomes `true`, including an empty list. Preserve JSON types and binding key order. An empty approved list means the report includes the local synthetic records; it does not make them official transactions. Mismatched/missing fixture identities must fail closed.

Check that it is ignored:

```powershell
git check-ignore storage/app/private/reporting/exclusions.json
```

Do not commit the file. See [reporting policy](reservation-reports.md) and [CSV exports](report-exports.md). Do not classify records based on names containing `TEST` or assume numeric IDs identify the same fixtures across machines.

## 12. Whole-system manual QA checklist

Use a record per scenario and mark **Pass / Fail / Blocked / Not tested**. Record branch/commit, browser, viewport and timestamp. A screen that loads is not proof its actions work.

| Area | Essential scenarios / expected behavior |
| --- | --- |
| Public website | Guest and authenticated CTAs, service descriptions, empty services, section links, mobile menu; no public fees or private records. |
| Registration/login | Resident-only registration, invalid/duplicate email, incorrect password, logout, verification/resend, reset password. No public Admin option. |
| Access control | Guests redirected, wrong roles forbidden, inactive/unverified accounts blocked as appropriate. Resident B cannot view A's details, files, QR or certificate via copied URLs. |
| Profile/settings | Allowed fields save; protected fields stay protected. Optional demographic fields, invalid date/contact, avatar upload/replace/remove, missing-avatar fallback, persistent light/dark appearance. |
| Services/requirements | Create/edit/activate/deactivate; fee validation; required/optional uploads; safe deletion/protection of referenced records. Old requests keep captured requirements and fees. |
| Schedules | Future slots only, time validation, capacity, full slots, inactive slots. Linked appointment times cannot be silently rewritten; capacity cannot undercut current occupancy. |
| Resident wizard | Service -> document information -> schedule -> confirmation/uploads. Required fields/files, invalid type/size, back navigation, retry, double submit, duplicate active request and another Resident's independent booking. |
| Resident tracking | Own lists/details, current status, history and notifications. Cancellation is owner-only and Pending-only, with history preserved. |
| Admin processing | Pending -> Under Review -> Approved -> Ready for Pickup -> Completed, plus permitted rejection. Invalid/stale transitions fail without partial side effects. Admin account activation/deactivation remains bounded. |
| Dynamic PDFs | Create a new request after configuration. Admin reviews the protected personalized Preview before Finalize; unreviewed/stale access is rejected. Exact reviewed PDF is saved, not regenerated by printing. Check long text, page edges and multiple-page layouts. |
| Document history | Change current profile/master/configuration after creating a request in a dedicated scenario; history must not silently adopt current values. Current master replacement invalidates stale configuration for new requests. |
| Printing | Service tools use the blank master. Reservation printing uses its saved personalized PDF only; no blank fallback. Preview/download/print do not change status, payments, QR usage or arrival. Test print dialog and cancellation; mark physical printing Not tested unless actually done. |
| QR | Issued at Approved. Valid Approved/Ready tickets verify; feedback precedes details navigation. Invalid/used tickets expose no private details. Scanning/verification alone creates no arrival and does not consume QR. |
| Arrival check-in | Explicit Admin confirmation at Ready for Pickup records one operational arrival. Repeat confirmation does not duplicate it or alter payment/status/QR consumption. |
| Payments/completion | Record payment only at Ready for Pickup for a known positive historical fee. Duplicate payments blocked. Positive Unpaid and Integrity Issue cannot Complete. Explicit zero can Complete without payment; legacy NULL keeps its existing eligibility. Payment alone never completes. Do not manufacture corrupt/legacy rows to test: use automated fixtures or request an approved fixture. |
| Completion | Authorized Ready -> Completed atomically records status/history and QR used metadata. Re-scanning cannot reuse the ticket. |
| Logs/notifications | Correct ownership, read/unread actions, correct event sources, no fake arrival/payment events, no private processing notes in Resident notifications. Viewing/searching logs does not create business events. |
| Reports | After manifest approval: date filters and boundaries, empty periods, invalid/reversed range, partition warnings, charts/counts/table reconciliation and pagination. Collections use paid_at + recorded amount; arrivals use verified_at; finalized PDFs use generated_at. |
| CSV | Correct period/partition/exclusions across all pages; safe spreadsheet text; no private paths, passwords or raw QR credentials. Export failure must be explicit, never a silently truncated success. |
| Automation/audits | Inspect scheduled registrations and automated tests. Verify approved configuration edits produce correct audits in local QA. Do not run cleanup/backfill jobs or install an unattended scheduler without an assigned task. |
| Responsive/accessibility | 1440, 768, 390 and 320px; long names, keyboard focus/Tab/Enter/Escape, mobile offcanvas, modals, readable dark mode, validation feedback, internal table scroll and no page-level overflow. Respect reduced motion. |

Also check refreshing forms, expired sessions/tokens and browser Back. Expected safety rejection is not a defect merely because it prevents an unsafe operation. For concurrency claims, use existing automated tests or an approved reproducible two-session scenario; one successful click does not prove race safety.

Useful module references: [printing](certificate-printing.md), [payments](on-site-payments.md), [check-in](check-in.md), [workflow snapshots](reservation-workflow-hardening.md), [profiles](resident-demographic-profiles.md), [notifications](notifications.md), [logs](reservation-activity.md), [collections](collection-reports.md), [events](recorded-event-reports.md), [automation/audit](automation-and-configuration-audit.md).

## 13. Record a finding before changing code

Copy this template into a GitHub issue or a QA note Ronin can review:

```text
ID / title:
Severity: Blocker / High / Medium / Low
Branch + commit:
Module / role / viewport:
Environment: Dealyn local synthetic database
Preconditions / fictional fixture reference:
Steps to reproduce:
Expected:
Actual:
Reproducibility:
Screenshot / sanitized console error:
Affected files (if identified):
Proposed bounded fix:
Tests / checks needed:
Status: Reported / Assigned / Fix proposed / Awaiting Ronin acceptance
```

Do not upload `.env`, logs containing secrets, reset links, raw QR payloads, private file paths or genuine personal documents. Crop/blur sensitive material. Avoid duplicate reports by checking existing issues first.

## 14. Copilot rules and starter prompt

Ronin assigns which findings Dealyn may fix. Investigation is welcome; changes to business policy, migrations, dependencies or architecture need Ronin's approval first. Do not let an AI weaken tests or security just to get a green result.

Paste this prompt into Copilot for each assigned issue:

```text
Work on SmartBarangay, an existing Laravel 12 / PHP 8.2 application.
Read docs/dealyn-onboarding-and-testing.md and the relevant module docs first.
My only working branch is qa/dealyn-whole-system. Stop if it is different.
Assigned issue: [paste Ronin-approved finding]
Allowed scope/files: [paste scope]

Inspect code/tests and explain the root cause and smallest safe fix first.
Preserve Blade, Bootstrap 5, vanilla JS, existing routes and authorization.
Resident/Admin are the only roles. Preserve locked status/payment/QR rules,
historical snapshots, private files, review-before-finalization and generated-only printing.
Do not introduce frameworks, upgrade packages, create/run migrations, reset data,
bypass verification/permissions, edit secrets or rewrite the document renderer.
Use only synthetic local data; automated tests must use isolated SQLite.
Do not commit, push, merge, enable auto-merge, deploy or modify GitHub settings.
Implement only the assigned fix after the investigation is reviewed.
Run meaningful focused checks and report changed files, results and limitations.
Ronin + Codex review the diff; Ronin alone performs final acceptance and main integration.
```

Review every AI edit. UI work must not alter PDF coordinates/canvas/print geometry, fee sources, query populations or workflow transactions. Preserve unrelated changes. Report business-policy ambiguity instead of inventing a rule.

## 15. Validate fixes safely

For automated PHP tests, open a **dedicated new PowerShell terminal** in the project. Do not use a running application's cached database configuration:

```powershell
php artisan config:clear
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
$env:MAIL_MAILER='array'
$env:QUEUE_CONNECTION='sync'
php artisan test --compact
```

Tests may create/drop tables only inside the isolated test database. Run PHP suites serially, not multiple parallel processes sharing fake-storage directories. Close this dedicated terminal after testing; do not start the normal application with those test environment variables.

In a normal project terminal:

```powershell
node --test tests/Js/*.test.js tests/Js/*.test.mjs
npm run build
php artisan route:list --except-vendor
git diff --check
git status -sb
```

For changed PHP files, run Pint in check mode with their actual paths, for example `php vendor/bin/pint --test app/Http/Controllers/ChangedController.php`. Replace the example with real changed paths. Do not format unrelated files. There are pre-existing Pint findings in CreateAdmin.php and RegisteredUserController.php at this preparation point; distinguish baseline failures from new ones.

At the inspected local revision, the full suite passed 1,071 tests / 10,764 assertions and 19 JS tests. Dealyn must run her own checks on the handed-off commit; these figures are a reference, not evidence for her future changes. Record actual totals, failures, skips and manual cases not tested.

## 16. Daily branch workflow and submission

Before editing:

```powershell
git status -sb
git branch --show-current
git fetch origin
git pull --ff-only origin qa/dealyn-whole-system
```

Pull only with a clean tree. If Ronin updates the base, ask for the approved synchronization step; do not reset/rebase/force-push or resolve business-code conflicts blindly. Report the conflict and preserve work.

After an assigned fix, inspect changes and stage **explicit paths**:

```powershell
git diff --stat
git diff
git diff --check
# Example only: replace with the actual intended files.
git add resources/views/path-to-changed-view.blade.php resources/css/path-to-changed-style.css
git diff --cached --stat
git diff --cached
git status -sb
git commit -m "Fix assigned QA issue: concise description"
git push origin qa/dealyn-whole-system
git log -1 --oneline
```

The example paths are placeholders; do not execute them unchanged. Avoid `git add .`. Do not include other experiments, `.env`, database dumps, the manifest, storage uploads, dependencies or built assets.

Create a **Draft PR**: compare `qa/dealyn-whole-system` against the base Ronin specified, not an assumed `main`. Include issue IDs, cause, changed behavior/files, test commands/results, screenshots at relevant sizes, and limitations. Request Ronin's review. Leave merge/auto-merge untouched. Mark it Ready for review only when the checklist is complete; this does not mean accepted/closed.

## 17. Ronin + Codex: final review and integration gate

1. Dealyn sends the PR URL, base/head commit hashes, QA report and exact test results.
2. Ronin asks Codex to inspect the complete diff and scope, including added/deleted files and unexpected changes. Fetching or reading a PR is not approval to merge it.
3. Review code before executing it. Test in a separate review checkout/worktree with a separate synthetic database, private storage and reporting configuration; do not point unreviewed code at Ronin's live development data.
4. Codex and Ronin verify authorization, snapshots, money calculations, document/QR lifecycle, query exclusions, CSRF, safe files and regression risk relevant to the diff.
5. Ronin personally performs the final whole-system and browser acceptance. Document remaining limitations and explicit acceptance.
6. If changes are requested, Dealyn fixes them on the same branch and pushes; repeat review for the new head. Do not silently accept earlier approval for later edits.
7. **Only Ronin** performs the approved merge/integration into `main`. Codex can prepare an exact integration plan after examining the actual branch history. Do not hard-reset `main` or force-push it to copy another branch.
8. Ronin verifies the merged commit, clean tree, remote status and post-integration smoke tests. Any real database migration requires its own reviewed plan/backup/approval; accepting code does not automatically authorize migrating real data.
9. Close issues and archive/delete Dealyn's branch only after Ronin accepts and the required Git checkpoint is verified.

Do not copy integration commands from an old conversation without checking the current graph and chosen PR base. This document does not perform or authorize a merge now.

## 18. Common setup blockers

| Symptom | What to check |
| --- | --- |
| Repository not found | Accepted invitation, correct GitHub identity and exact repository URL. Do not request Ronin's login. |
| PHP/Composer extension error | `where.exe php`, `php --ini`, `php -m`, platform requirements. Do not ignore requirements. |
| Vite/Node failure | Node version against the installed Vite requirement; `npm ci` from the lockfile. |
| Unknown database / SQL error | MySQL running, correct port/database/credentials and migrations on your local DB. Never fix by resetting Ronin's data. |
| Landing page has no services | Fresh database is empty. Prepare fictional services through Admin, not by importing real data. |
| Login redirects to verification | Verify the specific account through its signed local log-mail URL. This is expected protection. |
| Verification invalid | Same account/origin, APP_URL/port, fresh unexpired link, correct system clock. Resend; do not remove signature validation. |
| Reports unavailable | Manifest review/binding/list pending or invalid. Preserve fail-closed behavior. |
| Unstyled page / missing assets | `npm run build`, or running Vite. A stale `public/hot` can point to a stopped dev server; inspect it and remove only that generated marker in your own clone after confirming Vite is stopped, then build. |
| PDF unavailable | A Git clone does not contain private masters/generated files. Create a synthetic sample through the approved UI; never add a blank-master reservation fallback. |
| Camera denied | Browser camera permission and available device, localhost context. Report hardware testing Blocked when unavailable; do not weaken verification. |
| Test command tries MySQL | Stop. Clear cached config and use the dedicated SQLite test terminal above before rerunning. |
| Push rejected / conflicts | Stop, preserve work, send Ronin the sanitized error. No `reset --hard`, `clean -fd`, force push or forced pull. |

## 19. Ready-to-send message for Dealyn

> Hi Dealyn! Please use this guide to set up SmartBarangay on your own laptop and database. Your branch is `qa/dealyn-whole-system`; Ronin will send the approved base and starting commit. Test first, report reproducible findings, and edit only assigned fixes. Copilot can assist, but review every change. Push only your branch and submit a Draft PR with test evidence. Ronin and Codex will review the work, Ronin will perform the final overall test, and Ronin alone will integrate accepted changes into main. Do not merge, deploy, reset data or share credentials/private files.

This guide was created through read-only repository inspection plus documentation writing. No branch, access rule, account, database or GitHub setting was created/changed by preparing it. No code was committed or pushed.
