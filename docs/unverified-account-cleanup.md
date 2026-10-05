# Unverified Resident account maintenance

Only Residents whose `email_verified_at` is null and whose original `created_at` is at least **7 days (168 hours)** old qualify. Admins, verified Residents, and newer accounts are excluded. A candidate with any reservation, check-in verifier activity, or status-history actor activity is retained and logged as `business_records`. QR tickets and attachments are protected through their owning reservation. Future business relationships must be added to this safety check before enabling their modules.

The command scans in batches of 200, rechecks each candidate inside a transaction with a user-row lock before deletion, and retains accounts blocked by a database constraint. Verification and email correction use the same row lock. Successful verification excludes the account immediately; changing email preserves the original registration age. Logs include internal user IDs, reasons, and aggregate counts, without names, email addresses, passwords, or verification links.

Deletion also removes that user's password-reset tokens and database sessions. No business records are cascaded or deleted. Dry-run does not modify database records; it writes diagnostic application logs.

Preview before running a manual cleanup:

```powershell
php artisan smartbarangay:cleanup-unverified-users --dry-run
```

To actually delete eligible abandoned accounts:

```powershell
php artisan smartbarangay:cleanup-unverified-users
```

The output separates candidate, eligible, removed, and skipped counts. Automated tests use SQLite in memory and fake notifications; they never clean the development MySQL database. Run them with:

```powershell
php artisan test
```

## Scheduler

`routes/console.php` schedules the command daily at **02:00 Asia/Manila**, with `withoutOverlapping()`. Scheduling is not automatic during local development. In a separate terminal, keep this running from the project directory:

```powershell
php artisan schedule:work
```

Inspect scheduled tasks without executing them:

```powershell
php artisan schedule:list
```

In production, run the scheduler once per minute on one designated scheduler host. For Linux hosting, use a cron entry (replace the paths with the deployed application and PHP binary):

```cron
* * * * * cd /path/to/smartbarangay && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

For Windows hosting, configure Task Scheduler to repeat every minute, with `C:\xampp\php\php.exe` as the program, `artisan schedule:run` as the arguments, and the deployed project folder as **Start in**. Keep application logs available for skip/failure review. No operating-system scheduled task is installed by this change, and no scheduler process is started automatically.

This policy uses existing tables and fields; no migration, cleanup table, or expiration column is required. It does not expire verified accounts or change Admin access rules.
