# Pre-hosting cleanup and security review — 2026-11-10

Scope: existing SmartBarangay application on `feature/iot-integration`, starting
at `3083fbe`, including the pre-existing uncommitted IoT-04 work. No deployment,
real database migration/seeding, hardware upload, Git checkpoint or shared
Apache/Windows configuration change is part of this review.

## File classification and cleanup

| Classification | Files/directories | Evidence and handling |
| --- | --- | --- |
| KEEP | `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `public/`, `artisan`, Composer manifests/lock, Vite/package manifests/lock | Laravel runtime, migrations, compiled asset inputs and dependency resolution. Keep framework storage/cache scaffolding. |
| KEEP | `tests/`, `docs/`, `AGENTS.md`, `.env.example`, firmware source/configuration/libraries/tests and historical firmware reports | Required development, regression and hardware recovery context. Historical reports are not proof of current activation. Exclude from the production release package. |
| EXCLUDE FROM HOSTING | `.git/`, `.claude/`, `.vscode/`, `node_modules/`, `artifacts/` (46 tracked historical QA images), firmware tree, tests and developer reports | No web-runtime dependency; historical screenshots may contain account information. Do not publish a repository ZIP or copy the whole checkout into `public_html`. |
| EXCLUDE FROM HOSTING | Firmware `.pio/`, `.iot01-test/`, `.iot04-test/`, Python bytecode, PHP/JS test output, PHPUnit cache, `public/hot` | Generated local artifacts. Firmware binaries can embed Wi-Fi/device credentials. New PlatformIO builds default to `~/.smartbarangay/firmware/smartbarangay-esp32cam-build`, outside htdocs. Existing artifacts were preserved. |
| EXCLUDE FROM WEB ROOT | `.env`, application code, `vendor/`, private storage, logs, reporting manifest, database dumps, signing keys, firmware credentials and recovery archives | Some are required on the server, but only outside the served directory. `vendor/` must be installed using the committed Composer lock. Never upload local caches or development logs as production content. |
| NEEDS OWNER APPROVAL | `storage/framework/lsp-2a0a3518c40f2cd3.php`, `tests/Feature/smartbarangay.code-workspace`, older firmware build caches, private backups/recovery material | Unrelated files or potentially useful recovery state. Unchanged; do not stage automatically. |
| SAFE TO REMOVE | None approved or proven unnecessary source files | No controller/view/asset was removed based merely on apparent lack of direct references: Blade, routing, Vite dynamic imports and firmware tooling also establish dependencies. |

Deletion/relocation performed on existing project files: **none**. Proposed optional
owner-reviewed cleanup targets are the exact three firmware cache directories
above and the two unrelated untracked files; they are not prerequisites for a
safe production package. Do not delete backups. The approved earlier ZIP recovery
move and repository-root denial guard remain intact.

## Applied bounded fixes

Exact files changed by this review (pre-existing IoT-04 edits remain separate):

- Modified: `app/Http/Middleware/AuthenticateIotDevice.php` (comment only),
  `bootstrap/app.php`, `composer.lock`, `config/filesystems.php`,
  `firmware/SmartBarangay-ESP32-CAM-Test/platformio.ini`, `package-lock.json`,
  `public/.htaccess`.
- Added: `app/Http/Middleware/SecurityHeaders.php`, `config/trustedproxy.php`,
  `tests/Feature/PreHostingSecurityTest.php`, `docs/prehosting-security-review.md`.

1. Removed wildcard proxy trust. `config/trustedproxy.php` defaults to an empty
   IP/CIDR allowlist, with `TRUSTED_PROXIES` read only through configuration.
   Populate it only with verified provider ingress addresses; never `*`,
   `REMOTE_ADDR`, all private ranges or `0.0.0.0/0`. Production host validation
   accepts only the exact hostname in `APP_URL` (local/unit-test exceptions are
   Laravel's standard behavior). Set the canonical production URL correctly.
2. Added default response headers: nosniff, SAMEORIGIN framing, strict-origin
   referrer policy, same-origin camera permission and disabled microphone/location.
   Existing PDF sandbox/CSP headers are preserved. HSTS is emitted only for
   production HTTPS requests, without an unreviewed subdomain/preload policy.
3. Disabled the unused generic local-disk serving route. No application code uses
   `temporaryUrl`/`temporaryUploadUrl`; existing ownership-checked controllers
   continue serving private attachments, pictures, previews and finalized PDFs.
4. Added public-directory denial of dotfiles, private directory paths, key/dump/
   archive/backup extensions and directory listing. This is defense in depth,
   not a substitute for a correct public-only document root. The repository-root
   deny-all guard was preserved.
5. Updated only affected compatible locked dependencies: Symfony http-foundation,
   http-kernel, routing and yaml to 7.4.23, mime to 7.4.22; npm audit fixed four
   compatible packages including Vite. No framework-major migration.
6. Moved the default firmware build output location outside htdocs using
   PlatformIO's supported home-directory expansion. Safe-default and dummy-only
   diagnostics environments, decoding and credential mechanisms are preserved.

## Access, storage and IoT findings

- Actual roles are Resident and Admin. Staff, a dedicated IoT Dashboard and
  live camera-preview management are not implemented; none is presented as done.
- Protected routes retain authentication, active-account, verified-email and
  role middleware. Registration forces Resident; session regeneration, CSRF,
  password-reset throttling and other-device revocation remain in place.
- Resident reads are ownership-scoped; attachments require both reservation
  ownership and attachment relationship/path validation. Upload MIME, extension,
  size and image bounds are validated. Private documents are not public uploads.
- IoT retains direct TLS evidence (a forwarded protocol header alone is
  insufficient), device HMAC over method/path/body/time/nonce, key version and
  revocation checks, transactional replay protection, bounded input and generic
  errors. Browser pairing is scoped to the active verified Admin's DB session,
  binding, password hash and expiring lease. Scanning still does not check in,
  record payment or complete a reservation.
- IoT activation remains disabled in the real environment; no real device was
  provisioned during this review. The physical HTTPS/listener approval blocker
  remains separate from software regression acceptance.
- Future camera preview must require an authorized session, be disabled by
  default, avoid public streaming, and never record resident QR images as logs.
  The existing diagnostics firmware is dummy-only and unsuitable for real QR use.
- Existing private reporting exclusions must be reviewed for the production
  database identity. An explicit empty reviewed manifest is required even when
  production contains no acceptance fixtures; never copy development exclusions
  blindly or weaken fail-closed behavior.

## Verification evidence and limits

- Legacy source exposure: 69 requests across localhost, 127.0.0.1 and
  192.168.137.1: 63 returned 403 and six archive requests returned 404. Covered
  source/config/dotfiles, firmware, storage, backups, encoded dotfile paths,
  PATH_INFO and the known Windows short-name paths. No response bodies/secrets
  were collected. Private evidence: `~/.smartbarangay/prehosting-review/exposure.json`.
- The attempted harmless Apache fixture creation/test/cleanup command was
  rejected by automatic execution policy before running. No policy reason was
  supplied beyond `blocked by policy`. It was not retried through another method.
  Therefore the newly added public `.htaccess` rules still require approved
  runtime verification on Apache/LiteSpeed. Legacy root-guard results above are
  real runtime checks and should not be confused with this outstanding test.
- Config-cache round-trip used an external private cache and SQLite `:memory:`;
  confirmed IoT enable flag, database-session setting, proxy list and disabled
  generic storage route survive caching. The temporary config cache was cleared;
  real application configuration was not cached/changed.
- Browser smoke tests used the existing code with a loopback-only isolated
  SQLite harness at port 8025 and synthetic accounts, service, reservation and
  device. Landing/login, Admin dashboard, camera/IoT mode switching and device
  list, logout, Resident dashboard/list/details and Resident denial of the Admin
  QR page passed. QR and Resident list layouts had no page-level overflow at
  1440/768/390/320; no browser JavaScript errors were observed. No real camera
  permissions, hardware pairing, payment/completion or real resident mutations
  were performed. Registration/reset, deeper business flows and token ownership
  are regression-test coverage, not a claim of exhaustive manual UI testing.
- Full automated results are recorded in the final owner report and private
  `full-php.txt`/JUnit/build evidence. Composer/npm audits reported zero remaining
  known advisories after the compatible updates.

Validation totals: full Laravel run **1,188 passed / 11,742 assertions**;
latest focused security run **6 passed / 17 assertions** (the full run loaded
the first four security cases before the two production-host/HSTS cases were
added; those two are covered by the later focused run). JS **34 passed**;
firmware Python **21 passed**, portable decoder/lifecycle/HMAC contracts and
**10 synthetic image decode vectors** passed; both firmware Node policy runs
passed; PlatformIO `esp32cam` and `esp32cam-diagnostics` builds passed. Vite,
targeted Pint, PHP syntax, route listing, Composer validation/platform checks,
external config-cache round-trip and diff checks passed. These are local checks,
not hosting-account or physical-device acceptance.

## Hostinger release layout and acceptance checklist

Preferred layout: a private release directory outside `public_html`, with the
domain document root pointed to that release's `public/`. If the plan fixes
`public_html`, publish **only** public assets/index/.htaccess there, and adjust
the deployed entry point to load the private release's autoloader/bootstrap and
set the application's public path to the actual served directory. Keep that
host-specific adapter out of the local development entry point until reviewed.
Do not put the full repository under `public_html` and rely on redirects.

Before owner deployment approval:

- Verify the purchased plan's actual PHP/SSH/cron/document-root capabilities.
  Laravel 12 requires PHP >=8.2; test the selected supported PHP version against
  the lock file. Run `composer check-platform-reqs --no-dev` there. Required app
  extensions include PDO MySQL, OpenSSL, mbstring, XML/DOM, ctype, fileinfo, GD,
  iconv, intl, zip and bcmath where used. Local CLI is PHP 8.3.35; this does not
  prove the hosting account's configuration.
- Install locked dependencies with `composer install --no-dev --prefer-dist
  --optimize-autoloader`; do not use `composer setup` (it includes migrations).
  Build Vite assets with `npm ci`/`npm run build` in a controlled build environment;
  upload `public/build`, including the PDF worker. Node need not run in production.
- Use a separate production database/user, least privileges, InnoDB transactions,
  backups and an explicitly approved migration window. Do not transfer synthetic
  local accounts or private QA fixtures. Preserve private documents and historical
  snapshots in a reviewed data transfer; database-only backup does not back up files.
- Use production-only secrets outside Git/web root: `APP_ENV=production`,
  `APP_DEBUG=false`, canonical HTTPS `APP_URL`, a fresh APP_KEY for a fresh DB,
  `SESSION_SECURE_COOKIE=true`, HTTP-only/Lax cookies and database sessions/cache.
  **If transferring existing encrypted data, preserve its encryption key or plan
  explicit decryption/re-encryption; blindly changing APP_KEY destroys access to
  encrypted IoT credentials and other encrypted values.** Never use a demo key.
- Configure and verify SMTP, email verification and password recovery. Keep
  logs private with appropriate retention and production log level. Audit logging
  must not capture QR/device credentials, passwords or private file contents.
- Permit writes only to private `storage/` and `bootstrap/cache` as required;
  avoid 777 permissions and PHP execution in upload locations. No public storage
  symlink is required for the current private-upload architecture.
- Cache config/routes/views on the target only after secrets and canonical URLs
  are correct; do not upload local caches. Verify report configuration, secure
  session cookies, rate limits and IoT transport under the real ingress topology.
  Proxy TLS termination must provide trustworthy direct server HTTPS evidence
  for IoT; do not weaken the IoT middleware to trust arbitrary forwarded headers.
- Configure one per-minute cron invoking the private release's `artisan
  schedule:run`. It drives existing cleanup/reset-token/nonce/pairing tasks.
  Verify scheduler locks/cache and expired-session handling. Never expose a
  public URL that triggers cleanup or artisan commands.
- Keep the second Laravel project on its own site root, database/user, APP_KEY,
  storage/cache and session-cookie name; never share a full public directory.
- Before promotion, run the private-path matrix (HTTP and HTTPS, aliases, backup
  extensions, encoded/short-name variants where applicable), assets/PDF worker,
  guest/Resident/Admin authorization, mail, printing, pairing and physical-device
  acceptance. Use valid certificate verification; never bypass a TLS warning.
- Git delivery: owner reviews the complete diff and intentional files, tests,
  commits/pushes the feature checkpoint, then promotes only an approved commit.
  Rollback is the previous release plus a verified compatible database/private
  file backup and secrets; do not reset or overwrite historical data automatically.

Official references consulted: [Laravel deployment](https://laravel.com/docs/12.x/deployment),
[Hostinger framework support](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/),
[Hostinger PHP extensions](https://www.hostinger.com/support/which-php-extensions-and-configuration-options-are-supported-at-hostinger/).
Product marketing names alone do not establish account-specific deployment support.

Verdict: **NOT READY for hosting activation** until the target-account/public-root,
public guard runtime, production secrets/data/mail and HTTPS/IoT transport checks
above are accepted. Local software hardening is a prepared uncommitted checkpoint,
not proof of deployment or physical-device connectivity.

## Final release review follow-up

The original eleven-file hardening set was reviewed individually. The IoT
middleware change is comment-only; cryptography, direct TLS requirements,
pairing/session ownership and reservation side effects are unchanged. The
registered-route and compiled-asset manifests do not match the private-directory
deny expression. This static compatibility check does not execute `.htaccess`.

The complete-suite rerun identified test-only incompatibilities that the earlier
focused run could not detect:

- Production-mode cases leave Symfony Request's static trusted-host allowlist
  behind even though Laravel flushes the middleware's configuration. Added
  cleanup in `tests/TestCase.php` to prevent cross-case contamination.
- The existing IoT production plaintext/TLS case sends to `localhost`; it now
  explicitly sets its synthetic `app.url` to that host, rather than inheriting
  the developer's unrelated local hostname. Its 403 plaintext and successful
  authenticated direct-TLS expectations remain intact.

Consequently the complete checkpoint now contains the original eleven review
files **plus two modified test-support files**: `tests/TestCase.php` and
`tests/Feature/IotDeviceApiTest.php`. No production behavior was weakened to make
the tests pass. Existing IoT-04 pending files are still a separate preserved set.

Fresh loopback HTTP smoke checks on the same application code returned 200 for
landing/index/login/register/password recovery, the logo, compiled CSS/JS and
both PDF worker assets. Guest Admin/Resident pages returned 302 to sign-in;
an unauthenticated login POST without CSRF returned 419. The private QA router
was corrected to bootstrap `/index.php` through its isolated configuration,
instead of executing that entry point with an uninitialized in-memory database.
This was a private test-harness correction, not a Laravel entry-point change.

### Precise fixed-public-root Hostinger layout

Use the actual account/domain names in place of the example placeholders:

```text
/home/uACCOUNT/domains/barangay.example/
  smartbarangay/                  private existing Laravel application
    .env                         production-only secret configuration
    artisan
    composer.json
    composer.lock
    app/
    bootstrap/cache/
    config/
    database/migrations/
    resources/views/
    routes/
    vendor/                      installed from the lock, without dev packages
    storage/app/private/         documents, uploads, reporting manifest
    storage/framework/
    storage/logs/
  private-recovery/              backups/keys, outside every served root
  public_html/                   the only domain document root
    index.php                    deployment-specific entry-point adapter
    .htaccess                    reviewed public guard/front controller rules
    build/manifest.json
    build/assets/                compiled CSS, JS and PDF worker
    images/smartbarangay-mark.svg
    favicon.ico
    robots.txt
```

No `.env`, `.git`, `vendor`, firmware, tests, backups, logs or whole-project ZIP
belongs under `public_html`. Do not symlink private storage into it. Keep the
local repository-root deny-all file intact locally; a sibling `public_html`
does not inherit that guard. For any alternative direct `app/public` document
root, confirm parent access-rule inheritance explicitly to avoid denying the
public site along with the private application.

The deployed `public_html/index.php` must preserve the existing entry-point
behavior while using `dirname(__DIR__).'/smartbarangay'` for maintenance-file,
Composer autoloader and bootstrap paths, then call `$app->usePublicPath(__DIR__)`
before `handleRequest()`. The repository's local `public/index.php` stays
unchanged. Cron invokes the private application's `artisan`, not an exposed
web endpoint. No deployment adapter has been installed during this review.

Private deployment verification is distinct from public activation: confirm
Hostinger's access controls before uploading a private staging release, then
run the public guard runtime matrix, redirects/assets/authentication, email,
private downloads, trusted proxy/TLS evidence, sessions/cache/cron and physical
IoT acceptance on that actual topology. The blocked Apache fixture operation
was not retried. Its runtime coverage remains a mandatory deployment gate.

### Final completed verification

- Final complete Laravel suite: **1,190 passed / 11,747 assertions** in
  458.04 seconds, including all six `PreHostingSecurityTest` cases and the
  production IoT transport case. No outstanding failures.
- Fresh JS run: **34 passed**; Vite build, targeted Pint, PHP syntax and
  `git diff --check` passed. Composer and npm audits returned zero known advisories.
- No additional confirmed production security defect remains identified by this
  bounded review. The resolved failures were test isolation/fixture issues; the
  existing IoT transport and authorization rules were preserved.
- **READY for initial private deployment verification**, subject to verified
  staging access controls. **Public activation remains gated** on actual server
  guard enforcement and the hosting-specific acceptance checks above.
- No real database changes, shared infrastructure changes, flashing, staging,
  commit, push or deployment occurred. Unrelated files and existing IoT work remain.
