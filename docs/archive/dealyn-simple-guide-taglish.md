# SmartBarangay — Simple Guide para kay Dealyn

Updated: October 26, 2026. Para ito sa Windows + PowerShell + XAMPP.

Bago mag-test, basahin ang [System Walkthrough](dealyn-system-walkthrough-taglish.md) para maintindihan ang pages, modules at buong transaction flow.

## Bago magsimula: ito ang role mo

Hi Dealyn! Tutulong ka sa pag-test ng SmartBarangay at pag-fix ng mga issue na ia-assign ni Ronin.

**Ganito ang flow:**

1. Ikaw: test at report ng issues.
2. Ikaw: ayusin ang approved/assigned issues sa sarili mong branch.
3. Ikaw: commit, push sa branch mo, at gumawa ng Pull Request.
4. Ronin + Codex: review ng changes mo.
5. Ronin: final overall testing at final decision.
6. Ronin lang: mag-i-integrate ng accepted changes sa `main`.

| Item | Gagamitin mo |
| --- | --- |
| Repository | `https://github.com/roninjacob/smartbarangay.git` |
| Branch mo | `qa/dealyn-whole-system` |
| PR target | `main` |
| Local project folder | `C:\xampp\htdocs\smartbarangay-dealyn` |
| Sarili mong local database | `smartbarangay_dealyn` |
| Local website | `http://127.0.0.1:8000` |

**Huwag mag-push sa main, mag-merge ng PR, mag-auto-merge, o mag-deploy.** Kahit pasado sa tests o sabi ni Copilot na okay, kailangan pa rin ng review at final test ni Ronin.

Branch assignment ay working rule; hindi ito automatic GitHub permission lock. Si Ronin ang bahala sa repository protection/settings.

## Step 1 — Ihanda ang laptop mo

Kailangan mo ng:

- XAMPP na may PHP compatible sa PHP 8.2 target ng project at MySQL/MariaDB.
- Composer 2.
- Node.js at npm. Vite 7 needs Node `^20.19.0` o `>=22.12.0`; piliin ang supported LTS na pasok dito.
- Git.
- VS Code at browser. Optional ang GitHub Copilot.

Gamitin ang sarili mong GitHub account. Accept muna ang repository invitation ni Ronin.

Sa PowerShell, i-check:

```powershell
git --version
php -v
php --ini
composer --version
node -v
npm -v
```

Kung hindi makita ang PHP, i-check ang `C:\xampp\php` sa PATH at mag-open ulit ng terminal. Para malaman kung anong PHP ang ginagamit:

```powershell
where.exe php
php -m
```

Kailangan ang normal Laravel extensions, `pdo_mysql`, `pdo_sqlite`, `mbstring`, `fileinfo`, XML/DOM at GD support. Kapag may missing extension, i-enable sa PHP configuration na ipinakita ng `php --ini`; huwag i-ignore ang error.

## Step 2 — Kunin ang project at branch mo

Sa PowerShell:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/roninjacob/smartbarangay.git smartbarangay-dealyn
cd smartbarangay-dealyn
git fetch origin
git switch --track origin/qa/dealyn-whole-system
git branch --show-current
git status -sb
git log -1 --oneline
```

**Dapat branch mo ay `qa/dealyn-whole-system`.** I-send kay Ronin ang last commit para makumpirma na tamang version ang nakuha mo.

Kung mayroon ka nang clone, huwag mag-clone sa loob nito. Sa existing project folder, gamitin ang `git switch qa/dealyn-whole-system` kung existing na ang local branch. Kung may error o local edits, huminto muna at itanong kay Ronin.

Hindi kasama sa clone ang database, private files o passwords ni Ronin. Normal iyon. Sarili mong environment ang ise-set up mo.

## Step 3 — Install ang dependencies

Nasa `smartbarangay-dealyn` folder dapat ang terminal:

```powershell
composer install
composer check-platform-reqs
npm ci
```

Huwag `composer update`, `npm update`, o `npm audit fix --force`. Gamitin natin ang versions sa lockfiles. Huwag gamitin ang `composer setup` shortcut dahil may automatic migration iyon.

## Step 4 — Gumawa ng sarili mong .env

Sa fresh clone na wala pang `.env`:

```powershell
Copy-Item .env.example .env
```

Buksan ang `.env` sa VS Code. I-set ang mga ito, habang pinapanatili ang ibang needed settings sa example:

```dotenv
APP_NAME=SmartBarangay
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartbarangay_dealyn
DB_USERNAME=YOUR_LOCAL_USERNAME
DB_PASSWORD=YOUR_LOCAL_PASSWORD
DB_URL=

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS=smartbarangay@example.test
MAIL_FROM_NAME=SmartBarangay
```

**Palitan ang `YOUR_LOCAL_USERNAME` at `YOUR_LOCAL_PASSWORD` ng sarili mong local MySQL credentials.** I-match din ang DB_PORT sa XAMPP mo. Kung walang password ang mismong local account mo, blank lang ang value ng DB_PASSWORD.

Huwag hingin o kopyahin ang `.env`, APP_KEY, SMTP password o GitHub token ni Ronin. Huwag i-upload ang `.env` sa GitHub o i-paste sa Copilot/chat.

## Step 5 — Gumawa ng sariling database at tables

1. Start **MySQL** sa XAMPP.
2. Kung phpMyAdmin ang gagamitin, start din ang Apache para mabuksan iyon.
3. Sa phpMyAdmin, gumawa ng **bagong empty database**: `smartbarangay_dealyn`, gamit ang `utf8mb4`.
4. Huwag gumawa ng application tables manually at huwag mag-import ng real database ni Ronin.

Sa terminal, fresh installation lang:

```powershell
php artisan key:generate
php artisan config:clear
php artisan tinker
```

Sa Tinker, i-type isa-isa:

```php
config('database.default');
DB::connection()->getDatabaseName();
config('database.connections.mysql.host');
exit
```

Dapat `mysql`, `smartbarangay_dealyn`, at intended local host mo ang makita. **Kung iba, huwag muna mag-migrate.**

Kapag confirmed na sariling bagong local database mo:

```powershell
php artisan migrate
php artisan migrate:status
```

Ito ang gagawa ng tables gamit ang existing migrations. Huwag mag-`migrate:fresh`, `db:wipe`, reset o restore bilang shortcut. Huwag mag-`db:seed` basta-basta: hindi complete QA dataset ang default seeder. Huwag ulit-ulitin ang `key:generate` tuwing mag-pull.

## Step 6 — Buksan ang website

Sa project terminal:

```powershell
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Buksan sa browser: **http://127.0.0.1:8000/**

Panatilihing running ang terminal. Para sa active CSS/JS editing, mag-open ng second terminal sa project at run:

```powershell
npm run dev
```

Application sa port **8000** ang bubuksan, hindi ang Vite asset-server URL. Kung build mode lang, mag-`npm run build` pagkatapos ng frontend edits at refresh.

Gamitin consistently ang `127.0.0.1`; hiwalay ang session ng `localhost`. Kung occupied ang port, sabihin kay Ronin o gumamit ng agreed port at i-update ang APP_URL. Kailangan ng fresh verification links kapag nagpalit ng origin/port.

Sariling local website mo ito. Hindi nito ina-access ang database o laptop ni Ronin. Huwag i-public expose ang server. Hindi kailangan ng `storage:link` para ilabas ang private uploads.

## Step 7 — Gumawa ng test accounts

Gamitin ang fictional names tulad ng:

- `Dealyn QA Resident A`
- `Dealyn QA Resident B`
- `Dealyn QA Admin`

Puwedeng gumamit ng test email gaya ng `dealyn-resident-a@example.test`. Sariling test passwords mo ang gamitin; huwag ilagay sa issue o commit.

### Resident

1. Open `/register`.
2. Gumawa ng Resident account.
3. Complete ang normal email verification.
4. Gumawa ng second Resident para sa ownership/security testing.

### Admin

Sa second terminal sa project:

```powershell
php artisan smartbarangay:create-admin
```

Sagutan ang interactive prompts. Kailangan ding mag-verify ng email ang Admin. Walang public Admin registration.

### Paano ang verification kung walang actual email?

Dahil `MAIL_MAILER=log`, nasa **sarili mong `storage/logs/laravel.log`** ang verification message/link.

1. Mag-sign in bilang account na ive-verify.
2. Hanapin ang latest verification message para sa account na iyon.
3. Buksan ang complete signed link sa parehong browser/origin bago mag-expire.
4. Kung expired, gamitin ang resend sa website.

Ganito rin ang local password-reset email. Huwag i-post ang links; sensitive tokens ang mga iyon. Huwag i-edit ang `email_verified_at` o role sa database para laktawan ang verification.

## Step 8 — Maghanda ng sample data

Walang laman o kaunti ang data sa fresh installation. Normal na empty muna ang services/dashboard.

Sa Admin UI, gumawa ng:

1. Isang service na may fee na `0.00` at isang may positive fee, halimbawa `50.00`.
2. Required at optional requirements.
3. Future schedules na may enough capacity. Philippine time / Asia-Manila ang sundin; huwag palitan ang laptop clock para makalusot sa past-slot checks.
4. Harmless sample PDF master na may markang **SAMPLE / NOT VALID**.
5. Document fields at layout gamit ang existing configuration/designer; save at activate ang valid configuration.
6. Fake supporting PDF/JPG/PNG at harmless profile photo para sa uploads.

**Huwag gumamit ng real government ID, signature, Resident record o private file ni Ronin.** Huwag gumawa ng bulk SQL/seeder gamit si Copilot nang walang approved task.

### Important: Reports setup

Kung unavailable ang reports dahil sa reporting configuration, **expected protection iyon**, hindi agad bug.

May private, environment-specific reporting manifest. Huwag kopyahin ang exclusion IDs/file ni Ronin at huwag tanggalin ang validation. I-report muna kay Ronin para ma-review ang sarili mong dataset at ma-set up ang approved local manifest. Habang pending, markahan ang Reports/CSV tests na **Blocked — configuration review pending**; tuloy ang ibang testing.

May technical instructions sa [detailed guide, Reports section](dealyn-onboarding-and-testing.md#11-reports-explicit-configuration-review-is-required).

## Step 9 — I-test ang buong system, isang flow kada record

Gumamit ng hiwalay na browser profile/incognito/session para sa bawat role, o mag-logout bago magpalit. Huwag i-assume na ibang account ang bagong tab lang.

| Order | I-test | Dapat mangyari |
| --- | --- | --- |
| 1 | Landing page | Working links, readable services, mobile menu at guest/account actions. |
| 2 | Register/login/verify/reset/logout | Normal validation, correct account flow, walang public Admin option o verification bypass. |
| 3 | Profile/settings | Allowed edits lang; demographic validation, avatar upload/replace/remove at persistent light/dark mode. |
| 4 | Admin services/requirements/schedules | Create/edit/status controls, valid fees, required files, future times at capacity checks. Referenced history stays protected. |
| 5 | Resident reservation | Service -> information -> schedule -> confirmation/uploads. Check missing fields/files, bad file type/size, Back, refresh at repeated submit. |
| 6 | Duplicate/capacity | Same Resident/service/schedule with active request is blocked. Another Resident is independent, subject to capacity. Past/full/inactive slots are blocked. |
| 7 | My Reservations/tracking | Own records/files only; status and history readable. Cancel sariling Pending request only; hindi permanent delete. |
| 8 | Admin processing | Pending -> Under Review -> Approved -> Ready for Pickup -> Completed. Allowed rejection only; invalid/stale transitions fail safely. |
| 9 | Dynamic document | Review protected personalized Preview before Finalize. Check long text/page edges. Historical values stay historical; no second overwrite. |
| 10 | Printing | Service tools print blank master; reservation tools use saved personalized PDF only. Printing does not generate/change status/payment/arrival/QR usage. |
| 11 | QR | Issued at Approved. Valid ticket feedback -> correct details; invalid/used ticket rejected. Scan/verify alone does not consume QR or record arrival. |
| 12 | Arrival | Explicit Admin check-in at Ready for Pickup records one arrival; repeat does not duplicate or complete request. |
| 13 | Payment | At Ready for Pickup, record known positive historical fee once. Positive unpaid request cannot Complete. Free `0.00` can proceed without payment. Payment alone does not Complete. |
| 14 | Completion | Ready -> Completed records history and marks QR used. Used QR cannot be reused. |
| 15 | Notifications/logs | Correct recipients, useful messages/history, correct read/unread. No private Admin notes exposed to Residents. Ordinary verification is not an arrival. |
| 16 | Reports/CSV | After manifest approval: matching dates/counts/charts/table/export, pagination, empty state and invalid-range errors. Collections use paid_at/recorded amount. |
| 17 | Access/security | Resident B must not access A's details, attachments, QR or PDFs by copying URLs. Guest, unverified, inactive and wrong-role accounts remain blocked as appropriate. |
| 18 | UI on devices | 1440, 768, 390 and 320px; long names, keyboard navigation, visible focus, modals, errors and dark mode. No page-level horizontal overflow. |

Gumamit ng separate requests para sa cancel, reject, free completion at paid completion. Huwag i-reset ang official-style transaction records para ulitin ang test.

Para sa advanced cases tulad ng race conditions, corrupt payment records at legacy NULL fees, gamitin ang existing automated tests o humingi ng approved fixtures. Huwag gumawa ng corruption/manual database changes para lang ma-test.

Markahan bawat test: **Pass / Fail / Blocked / Not tested**. Camera/physical printing na hindi nagawa ay hindi dapat markahang Pass.

## Step 10 — I-report muna ang issue

Ito ang simple template na ipapadala kay Ronin:

```text
Issue: [short title]
Module / role:
Branch / commit:
Browser / screen size:
Steps:
1.
2.
3.
Expected:
Actual:
Screenshot or sanitized error:
Severity: High / Medium / Low
Suggested fix:
Status: Reported / Assigned / Awaiting review
```

Huwag isama ang passwords, .env, raw QR token, reset link, full private logs o real personal documents. I-blur ang sensitive information sa screenshot.

**Mag-edit lang ng issue na approved/assigned ni Ronin.** Huwag palawakin ang task dahil may ibang nakita si Copilot.

## Step 11 — Rules sa pag-edit at paggamit ng Copilot

- Laravel + Blade + Bootstrap + vanilla JS pa rin. Walang bagong frontend framework.
- Panatilihin ang existing routes, permissions at business rules.
- Walang bagong role, public Admin signup, QR bypass, blank-PDF fallback o automatic completion.
- Huwag baguhin ang historical snapshots, fee sources, private file serving o PDF geometry bilang UI shortcut.
- Huwag mag-add/run ng bagong migrations, dependencies o repair/cleanup jobs nang walang approval.
- Huwag tanggalin o pahinain ang tests para pumasa.
- Basahin ang actual diff; hindi automatic correct ang AI output.

Copy-paste prompt para kay Copilot:

```text
SmartBarangay ito: existing Laravel 12 / PHP 8.2 / Blade / Bootstrap / vanilla JS.
Read docs/dealyn-simple-guide-taglish.md and relevant module docs first.
Work only on branch qa/dealyn-whole-system; stop if another branch is active.

Assigned issue: [paste approved issue]
Allowed changes: [paste scope]

Inspect the code/tests and explain the root cause and smallest fix first.
Do not change business rules, authorization, snapshots, payment/QR lifecycle,
private files, PDF generation/geometry, dependencies or schema without approval.
Use synthetic local data and isolated SQLite for automated tests.
Do not commit, push, merge, deploy, reset data or edit secrets.
Report changed files, validation results and limitations.
Ronin + Codex review the work; Ronin is the final tester and integration owner.
```

## Step 12 — Mag-check bago commit

Para sa automated PHP tests, mag-open ng **dedicated new terminal** sa project. Test terminal lang ito; huwag dito i-start ang normal website:

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

Isolated SQLite memory database ang gagamitin. Huwag baguhin ang phpunit.xml para gumamit ng real MySQL. Run PHP suites one at a time. Isara ang test terminal pagkatapos para hindi madala ang testing environment sa website server.

Sa normal project terminal:

```powershell
node --test tests/Js/*.test.js tests/Js/*.test.mjs
npm run build
php artisan route:list --except-vendor
git diff --check
```

Kung may changed PHP files, run Pint check sa actual changed paths: `php vendor/bin/pint --test PATH_TO_CHANGED_PHP_FILE`. Palitan ang placeholder; huwag i-reformat ang unrelated files. Ihiwalay ang pre-existing failures sa bagong issue at ipaalam kay Ronin.

I-record ang actual tests/assertions at failures. Hindi sapat na sabihing “working sa akin.”

## Step 13 — Tamang araw-araw na Git workflow

### A. Bago mag-edit

```powershell
git branch --show-current
git status -sb
```

Dapat `qa/dealyn-whole-system`. Kung may ibang edits, huwag basta mag-pull/overwrite.

Kapag clean ang working tree:

```powershell
git fetch origin
git pull --ff-only origin qa/dealyn-whole-system
```

Kung may new updates sa `main`, sabihin kay Ronin para mapag-usapan ang synchronization. Huwag basta mag-merge/rebase ng main sa branch mo o mag-resolve ng conflicts gamit ang “accept all.”

### B. Pagkatapos ng assigned fix

I-review muna:

```powershell
git status --short
git diff --stat
git diff
git diff --check
```

Kapag lahat ng changes ay intended, puwedeng gamitin ang `git add .` **mula sa project root**:

```powershell
git add .
git diff --cached --stat
git diff --cached --check
git diff --cached
```

Ang **staged** ay changes na isasama sa next commit. Kung may unrelated/private file sa staged list, **huwag muna mag-commit**. Ipa-check kay Ronin. Huwag gumamit ng `git add -f` para isama ang ignored files.

Hindi dapat kasama: `.env`, database dumps, private uploads/PDFs, logs, local reporting manifest, `vendor`, `node_modules`, generated build files o local `AGENTS.md`.

### C. Commit at push sa branch mo lang

Palitan ang commit message ayon sa actual issue. Example:

```powershell
git commit -m "Fix reservation form spacing on mobile"
git push origin qa/dealyn-whole-system
git status -sb
git log -1 --oneline
```

**Never `git push origin main`.** Kung rejected ang push, may conflict, o wrong branch: stop at send ang sanitized error kay Ronin. Huwag mag-force push, `reset --hard`, `clean -fd` o forced pull.

## Step 14 — Gumawa ng Pull Request

Sa GitHub repository:

1. Open **Pull requests** -> **New pull request**.
2. Set **base: main**.
3. Set **compare: qa/dealyn-whole-system**.
4. I-review ang Files changed. Kung maraming unrelated changes, huwag muna ituloy; sabihin kay Ronin.
5. Gumawa ng **Draft Pull Request** at request review kay Ronin.
6. Kapag kumpleto na ang testing/report mo, puwedeng markahang Ready for review. Hindi pa ito final acceptance.
7. Huwag i-click ang Merge o Enable auto-merge.

PR description template:

```text
Issue fixed:
Root cause:
Files / behavior changed:
Tests run + results:
Browser / viewport checks:
Screenshots:
Not tested / known limitations:
Head commit:

Awaiting Ronin + Codex review and Ronin's final acceptance.
```

Kung may requested changes, ayusin sa **same branch**, run checks ulit, commit at push ulit. Automatically updated ang existing PR. Kailangan ulit ng review para sa bagong commits.

## Step 15 — Ano ang ipapadala kay Ronin

```text
Hi Ronin, ready for review na ang work ko.
Branch: qa/dealyn-whole-system
Commit: [git log -1 --oneline]
PR link:
Issues tested/fixed:
Test results:
Screenshots:
Blocked / not tested:

Hindi ko minerge o pinush sa main.
Waiting sa review ninyo ni Codex at final overall testing mo.
```

**Tapos na ang part mo sa submission; hindi pa automatically closed ang task.** Si Ronin ang magsasabi kung accepted na, may revisions, o puwede nang isama sa main.

## Kapag may problema sa setup

| Problem | Simple next step |
| --- | --- |
| Repository not found | Check invitation at sarili mong GitHub login. |
| PHP/extensions error | Check `where.exe php`, `php --ini`, `php -m` at platform requirements. |
| MySQL/database error | Check XAMPP, local port, credentials, database name at migration status. No reset shortcut. |
| No services | Fresh database pa; gumawa ng sample services sa Admin UI. |
| Hindi makapasok sa Admin | Check normal email verification at active status; huwag mag-bypass. |
| Invalid verification link | Same account/origin, correct APP_URL/clock, fresh link via resend. |
| Reports unavailable | Ask Ronin for local manifest review. Huwag alisin ang safety check. |
| Missing/unstyled assets | Run build o keep Vite running. Check stale `public/hot` only in your own clone; ask before changing if unsure. |
| PDF missing | Private PDFs are not in Git. Upload a harmless test master through the Admin UI. |
| Tests trying MySQL | Stop. Clear cached config and use the isolated test terminal above. |
| Git conflict/push rejected | Stop, preserve changes, send the error to Ronin. |

Para sa deeper technical details, basahin ang [detailed onboarding guide](dealyn-onboarding-and-testing.md) at module docs sa `docs/`. Ang mas bagong explicit instruction ni Ronin ang susundin kapag may lumang checkpoint/base sa older notes.

**Summary:** sariling laptop + sariling database + sariling branch. Test honestly, edit only assigned fixes, review before push, then PR. Ronin at Codex ang reviewers; Ronin lang ang final tester at mag-i-integrate sa main.
