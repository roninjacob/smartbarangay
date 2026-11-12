# Unverified Resident account maintenance

The retention period remains **7 days (168 hours)** from the original `created_at`. Only active, unverified Residents with pending residency, revision zero, no reviewer metadata and no protected history are eligible. Admins, verified or newer accounts, inactive accounts and every approved, rejected or needs-correction account are preserved.

Formal registration immediately creates a demographic profile. A profile by itself, including its registration/demographic values, does not establish protected operational history. An eligible abandoned account's profile is explicitly removed in the same transaction as its parent. Profiles belonging to protected accounts remain intact. No migration or historical backfill is required.

Protected history includes reservations in every status, residency decisions, material-profile/account-status audits, database notifications, profile-picture metadata and historical actor references for check-ins, status changes, payments, finalized documents, QR usage, official templates, document configurations, cancellation/attendance reviews and scanner leases. Owning reservations protect their QR tickets, payments, documents, attachments, requirements and answers. Future business relationships must be added to the safety check, particularly relationships that cascade or null actor attribution on deletion.

The command scans in batches of 200. Both modes recheck each candidate under a user-row lock inside a transaction. Registration submission, email verification/correction, material profile edits and Admin residency review coordinate through this parent lock. The locked recheck sees a winning verification/review and preserves the account. If cleanup wins first, later requests find the deleted account and cannot restore or inherit its identity. A restricting foreign key rolls back profile, reset-token, session and parent deletion together. SQLite tests demonstrate controlled interleavings and rollback. MariaDB lock contention requires a separate isolated concurrency run.

Logs contain internal user IDs, fixed reason categories, mode and aggregate counts. Names, email addresses, demographic values, passwords and verification tokens are excluded. Dry-run writes diagnostic logs but leaves all database records unchanged. Its counts represent the state at each locked check; the execute pass always re-evaluates current eligibility.

## Safe default and owner approval

The command defaults to dry-run, including when called without options. The daily scheduled task explicitly passes `--dry-run`. Deploying this code therefore does not activate the expanded deletion policy. No real cleanup was executed during implementation.

```powershell
php artisan smartbarangay:cleanup-unverified-users --dry-run
```

Before any real execution, the owner must approve the retention/deletion procedure, review the private dry-run results, confirm the intended database and verify a database/private-file backup and recovery procedure. After that approval, the explicit deletion command is

```powershell
php artisan smartbarangay:cleanup-unverified-users --execute
```

`--execute` explicitly selects deletion mode. Owner consent is handled through the reviewed operating procedure. Passing it together with `--dry-run` fails before querying/deleting candidates. The output separates candidate, eligible, removed and skipped counts. A repeated approved execution safely finds already-deleted accounts absent.

Deletion also removes only that account's password-reset tokens and database sessions. Protected business records are preserved. Existing registration-only files are not removed by this command; accounts with profile-picture metadata remain protected.

## Email squatting and recovery

After eligible, approved cleanup, the released email can be registered again through normal registration. The new account receives a new identity, pending residency and a fresh verification requirement. Old signed verification links remain bound to the deleted user ID and cannot verify its replacement. Existing reservations and documents never transfer to the new registration.

Password recovery remains restricted to verified email accounts and keeps the same generic public response for eligible, unverified and unknown addresses. No public reclaim/delete endpoint or automatic verification is introduced. Until owner-approved cleanup occurs, an occupied unverified email remains unavailable for re-registration. Accounts with protected history require separate manual review. The existing unique-email registration validation remains unchanged.

Automated tests use SQLite in memory and synthetic accounts. They never clean the development MySQL database. Run them with

```powershell
php artisan test
```

## Scheduler

`routes/console.php` schedules a dry-run daily at **02:00 Asia/Manila**, with `withoutOverlapping()`. Scheduling is not automatic during local development. Any future automated deletion requires a separate owner-approved scheduler change. In a separate terminal, an approved scheduler can be run using

```powershell
php artisan schedule:work
```

Inspect scheduled tasks without executing them using

```powershell
php artisan schedule:list
```

An existing production scheduler will run this cleanup in dry-run mode after deployment. Do not install or change production cron as part of this remediation. For reference, the normal Linux scheduler entry is

```cron
* * * * * cd /path/to/smartbarangay && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

For Windows hosting, configure Task Scheduler to repeat every minute, with `C:\xampp\php\php.exe` as the program, `artisan schedule:run` as the arguments, and the deployed project folder as **Start in**. Keep application logs available for skip/failure review. No operating-system scheduled task is installed by this change, and no scheduler process is started automatically.

This policy uses existing tables and fields; no migration, cleanup table, or expiration column is required. It does not expire verified accounts or change Admin access rules.
