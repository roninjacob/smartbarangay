# SmartBarangay - Agent Instructions

These are the current rules for any coding agent working in this repository.
The full original instructions and phase history are archived, unchanged, in
`docs/archive/AGENTS-full-history.md`. That archive is historical context only;
this file, the latest prompt and the actual code take precedence.

## 1. Project identity

- Name: SmartBarangay
- Full title: SmartBarangay: A Web-Based Barangay Document Reservation System
  with QR Code and IoT-Based Verification
- Client: Barangay Calayo, Nasugbu, Batangas
- Context: academic project (Web Systems and Technologies, plus IoT)
- Local path: `C:\xampp\htdocs\smartbarangay`
- Local development database: `smartbarangay` (XAMPP MySQL/MariaDB)

This is an existing, working system that is being prepared for its defense.

## 2. Current mode: enhance only, no new features

- Do not add features, routes, tables, columns, roles or packages.
- Allowed work: cleanup, polish, fixes, tests and documentation, within the
  scope of the current `prompt-XX` task.
- Do not rebuild or redesign working modules unless the prompt says so.
- Do not start the next phase automatically. Stop and report when the task is done.
- Never present planned work as implemented. Inspect code and tests before
  making claims about behavior.

## 3. Workflow

- Prompts arrive as `prompt-XX` phases; see `docs/DEVELOPMENT_PLAN.md`.
- The coding agent (Claude Code) codes, tests and reports.
- The coding agent never runs git write commands: no commit, add, rm, push,
  merge, checkout, branch, stash, reset, clean or rebase. Read-only git
  (status, diff, log, ls-files, show) is allowed.
- The team handles branches, staging, commits, merges and pushes.
- The coding agent never opens, prints or edits `.env`. To check one setting,
  use `php artisan config:show <key>` and report only that value.
- The coding agent never runs migrate, seed, `migrate:fresh`, `db:wipe` or any
  other command against the real MySQL database.
- Preserve unrelated changes in the working tree.
- Every deleted or moved path must be listed in the task report.
- Report honestly: state what was run, the results, and what was not verified.

## 4. Locked scope

- Single barangay only (Barangay Calayo). No multi-barangay, tenant, municipality
  or SaaS architecture, and no barangay selector.
- Exactly two roles: **Resident** and **Admin**.
- No Staff role, Staff account, Staff login, Staff dashboard, Staff middleware or
  Staff routes. Older Staff wording is superseded; that work belongs to Admin.
- Public registration is Resident only. Never expose role, `is_active`,
  verification or authorization fields in public forms.
- Admin accounts are created only with `php artisan smartbarangay:create-admin`
  and stay unverified until normal email verification. No verification bypass.
- Protected access requires: authenticated + active + verified + correct role
  (plus residency approval where the existing middleware requires it).
  A wrong role keeps the existing 403 behavior.
- Do not fabricate an official Barangay Calayo seal, address, phone number,
  email or office hours.

## 5. Approved stack

- PHP 8.2+, Laravel 12, MySQL/MariaDB (XAMPP locally)
- Blade, semantic HTML5, Bootstrap 5, custom CSS, vanilla JavaScript, Vite
- PDF: setasign/fpdi, setasign/tfpdf, smalot/pdfparser, pdfjs-dist
- QR: bacon/bacon-qr-code, @zxing/browser
- IoT: ESP32-CAM firmware (PlatformIO) with on-device QR decoding, talking to
  Laravel over an authenticated HTTPS API (`/api/iot/v1`)

Do not introduce React, Vue, Angular, Next.js, Tailwind, SPA frameworks,
ready-made admin dashboards or extra auth frameworks.

## 6. Coding conventions used in this repo

- Controllers are grouped under `app/Http/Controllers/{Admin,Resident,Auth,Api}`.
  Keep controllers thin; domain logic lives in `app/Services`.
- Enums in `app/Enums` (for example `ReservationStatus`, `PaymentStatus`,
  `UserRole`); use them instead of raw strings.
- Form Requests in `app/Http/Requests` for validation; always validate and
  authorize server-side, even when the client also validates.
- Access control through middleware in `app/Http/Middleware` (role, active
  account, residency, IoT device authentication) plus ownership checks.
- Schema changes only through Laravel migrations (none are allowed in the
  current mode).
- Multi-step state changes use database transactions and row locks; keep
  status history in the same transaction as the status change.
- Blade views in `resources/views`, styles in `resources/css`, scripts in
  `resources/js`, built by Vite. Follow existing file and class naming.
- Format changed PHP files with Laravel Pint (default preset). Do not
  mass-format unrelated files. Follow `.editorconfig` (4 spaces, LF).

## 7. Business rules that must not change

- Reservation status flow: Pending -> Under Review -> Approved ->
  Ready for Pickup -> Completed. Other outcomes: Rejected, Cancelled.
  Reservations are official records; no normal permanent deletion.
- Resident cancellation: owner only, Pending only, a status change with reason.
- Duplicate rule: same Resident + same Service + same Schedule with an active
  reservation is blocked. Capacity is enforced server-side.
- QR: issued on Approved, one per reservation, opaque UUID payload `SBQ1:<id>`,
  no personal data. QR is used only on Ready for Pickup -> Completed.
  Scanning, verification, preview and printing do not consume it.
- Check-in is a separate, explicit Admin action and does not complete a request.
- Payments are manual and on-site. Reservations keep their fee snapshot; never
  recalculate history from the current service fee. Paid services require a
  recorded payment before Completed.
- Documents: resident attachments, the blank service master PDF, the
  personalized preview and the generated final PDF are separate things.
  Historical reservations keep their historical source and snapshots.
  Generated certificates are never silently regenerated or overwritten.
- Notifications reflect events; they never change status, payment, check-in or QR use.
- Do not invent policies for `date_issued`, certificate correction or
  regeneration, waivers, refunds or partial payments.

## 8. Security rules

- Never open, print, commit or paste `.env`, `APP_KEY`, database credentials,
  SMTP or app passwords, API keys or device secrets.
- IoT device credentials, Wi-Fi settings and private firmware config live
  outside htdocs (`%USERPROFILE%\.smartbarangay\`). Never read, copy or move
  them into the repository, and never touch anything outside the project folder.
- Firmware build output (`.pio`, test binaries) must not be kept or committed;
  it may embed credentials.
- Devices never connect to MySQL. Laravel is the only authority for QR
  validity, check-in, payment and status.
- Private files (attachments, profile pictures, master PDFs, generated
  certificates) stay in private storage with MIME, extension and size
  validation, generated filenames and ownership checks. Never expose storage paths.
- Keep password hashing, CSRF, session regeneration, login throttling, POST
  logout, email verification and rate limits intact.
- Never put personal data in URLs or query strings.
- Database backups and private storage never go into Git.

## 9. Testing rules

- PHP tests run on SQLite in memory (`phpunit.xml`). Never point tests at the
  real MySQL database.
- Before reporting, run the full suite and record passed/failed/skipped counts:

  ```
  php artisan test
  node --test tests/Js/
  npm run build
  php artisan route:list
  ```

- Record a baseline before changing anything. If the baseline already fails,
  stop and report instead of fixing unrelated failures.
- Add or update tests for behavior changes. Only change an existing assertion
  when the task explicitly changes that output, and explain it in the report.
- SQLite results do not prove MySQL concurrency behavior; say so when relevant.
- For UI changes, check 1440, 768, 390 and 320 px widths for overflow and
  broken layout when a browser is available. Do not claim physical printer or
  hardware tests that were not performed.

## 10. Report format

Write the report in English Markdown. Include: baseline, changes grouped by
step with every created/modified/deleted/moved path, test and build results
before and after, anything skipped or uncertain and why, and confirmation that
no git write command, `.env` access or real-database command was run.
