<?php

namespace App\Console\Commands;

use App\Enums\ResidencyStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupUnverifiedUsers extends Command
{
    protected $signature = 'smartbarangay:cleanup-unverified-users
        {--dry-run : Report eligible accounts without changing records (default)}
        {--execute : Delete eligible accounts after owner approval}';

    protected $description = 'Remove abandoned, unverified Residents after 7 days, preserving business records';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Choose --dry-run or --execute, never both.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays(User::UNVERIFIED_RETENTION_DAYS);
        $dryRun = ! $this->option('execute');
        $counts = ['candidates' => 0, 'eligible' => 0, 'removed' => 0, 'skipped' => 0];

        User::abandonedResidents($cutoff)->select('id')->chunkById(200, function ($users) use ($cutoff, $dryRun, &$counts) {
            foreach ($users as $candidate) {
                $counts['candidates']++;
                try {
                    $result = DB::transaction(function () use ($candidate, $cutoff, $dryRun) {
                        // Recheck under a row lock so verification cannot race a stale candidate list.
                        $user = User::abandonedResidents($cutoff)->whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $user) {
                            return 'no_longer_eligible';
                        }
                        if ($this->hasProtectedHistory($user)) {
                            return 'business_records';
                        }
                        if ($dryRun) {
                            return 'eligible';
                        }
                        // A registration profile has no independent retention once its unverified
                        // parent is eligible. Its RESTRICT foreign key requires explicit deletion.
                        $user->profile()->delete();
                        // Resolve email in SQL so a database error cannot expose it in bindings.
                        DB::table('password_reset_tokens')->whereIn('email', User::whereKey($user->id)->select('email'))->delete();
                        DB::table('sessions')->where('user_id', $user->id)->delete();
                        $user->delete();

                        return 'removed';
                    });
                } catch (QueryException $exception) {
                    if (! in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                        throw $exception;
                    }
                    // Preserve the entire transaction if a foreign key blocks deletion.
                    $result = 'database_constraint';
                }

                if (in_array($result, ['eligible', 'removed'], true)) {
                    $counts['eligible']++;
                    $counts['removed'] += $result === 'removed' ? 1 : 0;
                } else {
                    $counts['skipped']++;
                }
                Log::info('Unverified Resident cleanup account result.', [
                    'user_id' => $candidate->id, 'result' => $result, 'dry_run' => $dryRun,
                ]);
            }
        });

        if ($dryRun) {
            $this->info('Dry run: no database records were modified.');
        }
        $this->info("Candidates: {$counts['candidates']}; eligible: {$counts['eligible']}; removed: {$counts['removed']}; skipped: {$counts['skipped']}.");
        Log::info('Unverified Resident cleanup completed.', [...$counts, 'dry_run' => $dryRun]);

        return self::SUCCESS;
    }

    private function hasProtectedHistory(User $user): bool
    {
        if (! $user->is_active || $user->residency_status !== ResidencyStatus::Pending || $user->residency_revision !== 0
            || $user->residency_reviewed_by !== null || $user->residency_reviewed_at !== null
            || $user->profile_picture !== null || $user->notifications()->exists()) {
            return true;
        }

        // Cover owner records and historical actor references, including NULL-on-delete
        // and cascading relationships that would otherwise silently lose attribution.
        $references = [
            'reservations' => ['user_id'],
            'checkin_logs' => ['verified_by'],
            'reservation_status_histories' => ['changed_by'],
            'reservation_payments' => ['recorded_by'],
            'reservation_documents' => ['generated_by'],
            'qr_tickets' => ['used_by'],
            'service_document_templates' => ['uploaded_by'],
            'document_templates' => ['created_by'],
            'audit_logs' => ['user_id'],
            'residency_reviews' => ['user_id', 'reviewed_by'],
            'users' => ['residency_reviewed_by'],
            'reservation_cancellation_requests' => ['requested_by', 'reviewed_by'],
            'reservation_attendance_reviews' => ['recorded_by'],
            'iot_scanner_pairings' => ['admin_id'],
        ];
        foreach ($references as $table => $columns) {
            $query = DB::table($table)->where(function ($query) use ($columns, $user) {
                foreach ($columns as $column) {
                    $query->orWhere($column, $user->id);
                }
            });
            if ($query->exists()) {
                return true;
            }
        }

        // Protect material-profile audit history even if its actor is a different account.
        return DB::table('audit_logs')->whereIn('action', ['residency_profile_changed', 'updated_resident_account'])
            ->where('target_id', $user->id)->exists();
    }
}
