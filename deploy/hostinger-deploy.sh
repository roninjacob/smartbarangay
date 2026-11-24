#!/usr/bin/env bash
# SmartBarangay - Git-based deployment for the Hostinger layout.
#
# Runs ON THE SERVER over SSH. The private Laravel application and the public
# document root are separate folders (see docs/DEPLOYMENT.md):
#
#   ~/domains/smartbarangay.site/smartbarangay/app   private application (.env, vendor, storage)
#   ~/domains/smartbarangay.site/public_html     public adapter (index.php, .htaccess, build/)
#   ~/smartbarangay-repo                        Git clone used only as the deployment source
#
# Commands:
#   check                     update the clone and show what a deploy would change (no changes)
#   deploy                    deploy origin/main; stops if database migrations are pending
#   deploy --with-migrations  same, and runs `php artisan migrate --force` (back up the DB first)
#   rollback [BACKUP_DIR]     restore the files replaced by a deploy (default: the last one)
#   status                    show the deployed commit and the last backup
#
# Never touched: .env, vendor/ (except `composer install` when composer.lock
# changes), storage/, bootstrap/cache/, public_html/index.php, public_html/.htaccess.
# Never copied to the server: .git, firmware, tests, docs, artifacts, deploy.

set -Eeuo pipefail

# Run from a private copy: `update_repo` resets the clone, which may replace this
# very file while Bash is still reading it.
if [ -z "${SB_DEPLOY_SELF_COPY:-}" ]; then
    self_copy="$(mktemp "${TMPDIR:-/tmp}/sb-deploy.XXXXXX")"
    cp "$0" "$self_copy"
    SB_DEPLOY_SELF_COPY="$self_copy" exec bash "$self_copy" "$@"
fi
rm -f "$SB_DEPLOY_SELF_COPY"

SITE_ROOT="${SB_SITE_ROOT:-$HOME/domains/smartbarangay.site}"
# public_html/index.php requires smartbarangay/app/public/index.php, so the
# application (artisan, .env, vendor, storage) lives in smartbarangay/app.
APP="${SB_APP_DIR:-$SITE_ROOT/smartbarangay/app}"
PUB="${SB_PUBLIC_DIR:-$SITE_ROOT/public_html}"
REPO="${SB_REPO_DIR:-$HOME/smartbarangay-repo}"
BRANCH="${SB_BRANCH:-main}"
REMOTE_URL="${SB_REMOTE_URL:-https://github.com/roninjacob/smartbarangay.git}"
SITE_URL="${SB_SITE_URL:-https://smartbarangay.site}"
PHP="${SB_PHP:-php}"
COMPOSER="${SB_COMPOSER:-composer}"
STATE="${SB_STATE_DIR:-$HOME/.smartbarangay-deploy}"

EXCLUDES=(
    --exclude=/.git/ --exclude=/.github/ --exclude=/.claude/ --exclude=/.vscode/
    --exclude=/firmware/ --exclude=/tests/ --exclude=/docs/ --exclude=/artifacts/ --exclude=/deploy/
    --exclude=/node_modules/ --exclude=/vendor/ --exclude=/storage/ --exclude=/bootstrap/cache/
    --exclude=/.env --exclude='/.env.*' --exclude=/public/hot
)
PUBLIC_EXCLUDES=(--exclude=/index.php --exclude=/.htaccess --exclude=/build/ --exclude=/hot)

say() { printf '\n== %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf '\nSTOP: %s\n' "$*" >&2; exit 1; }

preflight() {
    command -v git >/dev/null || die "git is not available on this server"
    command -v rsync >/dev/null || die "rsync is not available on this server"
    [ -f "$APP/artisan" ] || die "no Laravel application at $APP"
    [ -f "$APP/.env" ] || die "$APP/.env is missing (create it privately first)"
    [ -f "$PUB/index.php" ] || die "no $PUB/index.php"
    grep -q "smartbarangay" "$PUB/index.php" \
        || die "$PUB/index.php does not load the private smartbarangay application"
    "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
        || die "PHP 8.2+ required; set SB_PHP to the right binary"
    mkdir -p "$STATE/backups"
    chmod 700 "$STATE"
}

update_repo() {
    if [ ! -d "$REPO/.git" ]; then
        say "Cloning $REMOTE_URL ($BRANCH) into $REPO"
        git clone --quiet --branch "$BRANCH" "$REMOTE_URL" "$REPO"
    fi
    git -C "$REPO" fetch --quiet origin "$BRANCH"
    git -C "$REPO" checkout --quiet "$BRANCH"
    # The clone is a deployment source only; never edit files inside it.
    # SB_REF pins an exact tested commit (GitHub Actions passes the pushed SHA);
    # it must already be part of origin/$BRANCH.
    local target="origin/$BRANCH"
    if [ -n "${SB_REF:-}" ]; then
        git -C "$REPO" merge-base --is-ancestor "$SB_REF" "origin/$BRANCH" 2>/dev/null \
            || die "SB_REF $SB_REF is not on origin/$BRANCH"
        target="$SB_REF"
    fi
    git -C "$REPO" reset --quiet --hard "$target"
    COMMIT="$(git -C "$REPO" rev-parse --short HEAD)"
    [ -f "$REPO/public/build/manifest.json" ] \
        || die "commit $COMMIT has no public/build/manifest.json (run npm run build and commit it)"
}

deployed_commit() { cat "$STATE/current" 2>/dev/null || true; }

pending_migrations() {
    local status name
    status="$(cd "$APP" && "$PHP" artisan migrate:status --no-ansi 2>&1)" \
        || die "cannot read migration status: check .env database settings"
    for file in "$REPO"/database/migrations/*.php; do
        name="$(basename "$file" .php)"
        # No `grep -q` here: an early pipe close plus pipefail would misreport.
        grep -F "$name" <<<"$status" | grep "Ran" >/dev/null || echo "$name"
    done
}

show_changes() {
    say "Application files that will be added or replaced"
    rsync -rlcn --itemize-changes "${EXCLUDES[@]}" "$REPO/" "$APP/" | grep -v '/$' || echo "(none)"
    say "Public files that will be added or replaced"
    rsync -rlcn --itemize-changes "${PUBLIC_EXCLUDES[@]}" "$REPO/public/" "$PUB/" | grep -v '/$' || echo "(none)"
    rsync -rlcn --itemize-changes --delete "$REPO/public/build/" "$PUB/build/" | grep -v '/$' | sed 's#^#build/ #' || true
    cmp -s "$REPO/public/.htaccess" "$PUB/.htaccess" \
        || warn "public_html/.htaccess differs from public/.htaccess in Git. It is NOT replaced automatically; review it by hand."
    say "Application files on the server that are no longer in Git (left in place)"
    rsync -rlcn --delete --itemize-changes "${EXCLUDES[@]}" "$REPO/" "$APP/" | grep '^\*deleting' | grep -v '/$' || echo "(none)"
    if cmp -s "$REPO/composer.lock" "$APP/composer.lock"; then
        echo "composer.lock unchanged: dependencies stay as installed."
    else
        echo "composer.lock changed: deploy will run composer install --no-dev."
    fi
}

check() {
    preflight
    update_repo
    local current
    current="$(deployed_commit)"
    say "Deployed: ${current:-unknown (first Git deployment)}   ->   new: $COMMIT"
    if [ -n "$current" ] && git -C "$REPO" cat-file -e "$current" 2>/dev/null; then
        git -C "$REPO" log --oneline "$current..HEAD"
    else
        git -C "$REPO" log --oneline -5
    fi
    show_changes
    say "Pending database migrations"
    pending_migrations | sed 's/^/  /' | grep . || echo "(none)"
    say "Scheduler"
    if crontab -l 2>/dev/null | grep -q "schedule:run"; then
        echo "cron with schedule:run found."
    else
        warn "no cron running 'artisan schedule:run' found (see docs/DEPLOYMENT.md)."
    fi
    say "Check finished. Nothing was changed. Run: bash $REPO/deploy/hostinger-deploy.sh deploy"
}

smoke_test() {
    command -v curl >/dev/null || { warn "curl not available; open $SITE_URL manually"; return; }
    say "Smoke test"
    local css code failed=0
    css="$(grep -o 'assets/app-[A-Za-z0-9_-]*\.css' "$PUB/build/manifest.json" | head -1)"
    for path in / /login "/build/$css"; do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL$path" || echo 000)"
        echo "  $code  $SITE_URL$path"
        case "$code" in 2??|3??) ;; *) failed=1 ;; esac
    done
    [ "$failed" = 0 ] || warn "a page did not answer 2xx/3xx; check storage/logs/laravel.log or run rollback."
}

deploy() {
    local with_migrations="${1:-}"
    preflight
    update_repo
    local pending
    pending="$(pending_migrations)"
    if [ -n "$pending" ] && [ "$with_migrations" != "--with-migrations" ]; then
        printf '%s\n' "$pending" | sed 's/^/  /'
        die "pending migrations above. Back up the database, then run: deploy --with-migrations"
    fi
    local lock_changed=0
    cmp -s "$REPO/composer.lock" "$APP/composer.lock" || lock_changed=1

    BK="$STATE/backups/$(date +%Y%m%d-%H%M%S)-$COMMIT"
    mkdir -p "$BK"
    deployed_commit > "$BK/previous-commit"
    say "Backup of replaced files: $BK"

    (cd "$APP" && "$PHP" artisan down --retry=15) || warn "maintenance mode not enabled"
    trap '(cd "$APP" && "$PHP" artisan up >/dev/null 2>&1) || true' EXIT

    say "Syncing application code ($COMMIT)"
    rsync -rlc --backup --backup-dir="$BK/app" "${EXCLUDES[@]}" "$REPO/" "$APP/"

    if [ "$lock_changed" = 1 ]; then
        say "composer.lock changed: installing locked dependencies"
        (cd "$APP" && "$COMPOSER" install --no-dev --prefer-dist --optimize-autoloader --no-interaction)
    fi

    if [ -n "$pending" ]; then
        say "Running migrations"
        (cd "$APP" && "$PHP" artisan migrate --force)
    fi

    say "Publishing public assets"
    rsync -rlc --backup --backup-dir="$BK/public_html" "${PUBLIC_EXCLUDES[@]}" "$REPO/public/" "$PUB/"
    [ -d "$PUB/build" ] && cp -a "$PUB/build" "$BK/public_html-build"
    mkdir -p "$PUB/build"
    rsync -rlc --delete "$REPO/public/build/" "$PUB/build/"

    say "Rebuilding caches"
    (cd "$APP" && "$PHP" artisan optimize:clear && "$PHP" artisan optimize)

    (cd "$APP" && "$PHP" artisan up)
    trap - EXIT
    echo "$COMMIT" > "$STATE/current"
    echo "$BK" > "$STATE/last-backup"
    smoke_test
    say "Deployed $COMMIT. Rollback if needed: bash $REPO/deploy/hostinger-deploy.sh rollback"
}

rollback() {
    preflight
    local bk="${1:-$(cat "$STATE/last-backup" 2>/dev/null || true)}"
    [ -n "$bk" ] && [ -d "$bk" ] || die "no backup folder found (pass one from $STATE/backups)"
    say "Restoring files from $bk"
    (cd "$APP" && "$PHP" artisan down --retry=15) || true
    trap '(cd "$APP" && "$PHP" artisan up >/dev/null 2>&1) || true' EXIT
    [ -d "$bk/app" ] && rsync -rlc "$bk/app/" "$APP/"
    [ -d "$bk/public_html" ] && rsync -rlc "$bk/public_html/" "$PUB/"
    [ -d "$bk/public_html-build" ] && rsync -rlc --delete "$bk/public_html-build/" "$PUB/build/"
    (cd "$APP" && "$PHP" artisan optimize:clear && "$PHP" artisan optimize)
    (cd "$APP" && "$PHP" artisan up)
    trap - EXIT
    cp "$bk/previous-commit" "$STATE/current" 2>/dev/null || true
    warn "files ADDED by that deploy, composer packages and database migrations are not reverted."
    smoke_test
}

status() {
    echo "Deployed commit: $(deployed_commit || true)"
    echo "Last backup:     $(cat "$STATE/last-backup" 2>/dev/null || echo none)"
    [ -d "$REPO/.git" ] && echo "Clone:           $(git -C "$REPO" log --oneline -1)"
}

case "${1:-}" in
    check) check ;;
    deploy) deploy "${2:-}" ;;
    rollback) rollback "${2:-}" ;;
    status) status ;;
    *) echo "Usage: bash hostinger-deploy.sh check | deploy [--with-migrations] | rollback [BACKUP_DIR] | status"; exit 1 ;;
esac
