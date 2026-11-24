# SmartBarangay deployment (Hostinger, Git-based)

Production: `https://smartbarangay.site`. The layout follows
`docs/archive/prehosting-security-review.md` ("Precise fixed-public-root Hostinger layout").

```text
~/domains/smartbarangay.site/
  smartbarangay/      private Laravel application: .env, vendor/, storage/ (never public)
  public_html/        document root: index.php adapter, .htaccess, build/, images/, favicon, robots
~/smartbarangay-repo/ Git clone of main, used only as the deployment source
~/.smartbarangay-deploy/
  current             deployed commit
  backups/<time>-<commit>/   files replaced by each deploy (for rollback)
```

The deployment script is [`deploy/hostinger-deploy.sh`](../deploy/hostinger-deploy.sh).
It runs on the server, never locally.

## What a deploy does

1. Fetches `origin/main` into `~/smartbarangay-repo` (a clean clone; never edit it).
2. Stops if database migrations are pending, unless `--with-migrations` is given.
3. Puts the site in maintenance mode.
4. Copies application code to the private folder. Every replaced file is backed up first.
   Never copied: `.git`, `firmware/`, `tests/`, `docs/`, `artifacts/`, `deploy/`, `node_modules/`.
   Never touched: `.env`, `vendor/`, `storage/`, `bootstrap/cache/`.
5. Runs `composer install --no-dev --optimize-autoloader` only when `composer.lock` changed.
6. Publishes `public/build` to `public_html/build` and other public files (images,
   favicon, robots). `public_html/index.php` (the adapter) and `public_html/.htaccess`
   are never replaced; `check` warns when `.htaccess` differs from Git.
7. Rebuilds caches (`optimize:clear`, then `optimize`: config, events, routes, views).
8. Leaves maintenance mode, records the commit and smoke-tests `/`, `/login` and the CSS.

Node.js is not needed on the server: `public/build` is built locally and committed.

## 1. Before every deploy (laptop)

```powershell
npm run build
php artisan test
git add -f public/build/manifest.json public/build/assets
git add -u public/build
git status --short
```

Commit, merge into `main` and push. `public/build` is in `.gitignore`, so new build
files need `git add -f`; `git add -u public/build` stages deleted old assets.

## 2. First time only (server SSH)

```bash
git clone --branch main https://github.com/roninjacob/smartbarangay.git ~/smartbarangay-repo
```

## 3. Every deploy (server SSH)

Preview, changes nothing:

```bash
bash ~/smartbarangay-repo/deploy/hostinger-deploy.sh check
```

Read the lists. On the first Git deployment, files under "Application files that
will be added or replaced" that you did not expect may be server-side edits that
are not in Git; copy them into the repository first or they will be replaced
(the backup keeps them either way).

Deploy:

```bash
bash ~/smartbarangay-repo/deploy/hostinger-deploy.sh deploy
```

If it stops with pending migrations: export a database backup from hPanel
(phpMyAdmin), then:

```bash
bash ~/smartbarangay-repo/deploy/hostinger-deploy.sh deploy --with-migrations
```

## Automatic deploy from GitHub

[`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) runs on every push
to `main`: the PHP and JavaScript test suites first, then (only if they pass) it
connects to Hostinger over SSH and runs `hostinger-deploy.sh deploy` for exactly
that pushed commit. The deploy job stays off until it is enabled (step 5), and it
still stops on pending migrations; run those by hand with `--with-migrations`.

One-time setup:

1. Do the first `check` and `deploy` by hand (sections 2 and 3) and confirm the site works.
2. Create a key pair used only for deploys, on the laptop. Press Enter twice when
   asked for a passphrase (GitHub Actions cannot type one):

   ```powershell
   ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\smartbarangay_deploy" -C "github-actions-deploy"
   ```

3. hPanel > Advanced > SSH Access: enable SSH, note the IP, port (usually 65002)
   and username, and add the contents of `smartbarangay_deploy.pub` under SSH keys.
   Record the server's host key:

   ```powershell
   ssh-keyscan -p 65002 <SSH-IP>
   ```

4. GitHub > repository > Settings > Secrets and variables > Actions > New repository secret:

   | Secret | Value |
   |---|---|
   | `HOSTINGER_HOST` | SSH IP from hPanel |
   | `HOSTINGER_PORT` | SSH port from hPanel |
   | `HOSTINGER_USER` | SSH username from hPanel |
   | `HOSTINGER_SSH_KEY` | full contents of the private key file `smartbarangay_deploy` |
   | `HOSTINGER_KNOWN_HOSTS` | the full `ssh-keyscan` output |

5. Same page, Variables tab: add `HOSTINGER_DEPLOY` = `enabled`.
6. Optional: Settings > Environments > `production` > Required reviewers, to approve
   each deploy with one click before it runs.

Follow a deploy under the repository's Actions tab. A red test job means nothing
was deployed. Never commit the private key; delete it from the laptop after
step 4 if nobody else needs it. To stop automatic deploys, set `HOSTINGER_DEPLOY`
to anything else.

## Rollback

```bash
bash ~/smartbarangay-repo/deploy/hostinger-deploy.sh rollback
```

Restores the files replaced by the last deploy (or pass a folder from
`~/.smartbarangay-deploy/backups/`). Files that the deploy added, Composer packages
and database migrations are not reverted.

## Scheduler (one-time hPanel setting)

hPanel > Advanced > Cron Jobs, every minute:

```text
cd /home/<account>/domains/smartbarangay.site/smartbarangay && php artisan schedule:run >> /dev/null 2>&1
```

`check` warns when no `schedule:run` cron exists. It drives IoT nonce/scanner
cleanup, preview cleanup and password-reset cleanup.

## Not part of a web deploy

- ESP32 firmware is flashed over USB from `firmware/` (see `firmware/README.md`);
  it is never uploaded to the server.
- `.env`, the APP_KEY, database credentials and IoT device credentials stay private
  and are edited by hand on the server only.
- Use the settings override variables at the top of the script (`SB_PHP`,
  `SB_APP_DIR`, ...) if the account layout differs.
