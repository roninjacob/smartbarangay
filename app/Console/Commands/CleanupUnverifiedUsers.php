<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupUnverifiedUsers extends Command
{
    protected $signature = 'smartbarangay:cleanup-unverified-users {--dry-run : Report eligible accounts without changing records}';

    protected $description = 'Remove abandoned, unverified Residents after 7 days, preserving business records';

    public function handle(): int
    {
        $cutoff = now()->subDays(User::UNVERIFIED_RETENTION_DAYS);
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['candidates' => 0, 'eligible' => 0, 'removed' => 0, 'skipped' => 0];

        User::abandonedResidents($cutoff)->select('id')->chunkById(200, function ($users) use ($cutoff, $dryRun, &$counts) {
            foreach ($users as $candidate) {
                $counts['candidates']++;
                try {
                    $result = DB::transaction(function () use ($candidate, $cutoff, $dryRun) {
                        // Recheck under a row lock so verification cannot race a stale candidate list.
                        $query = User::abandonedResidents($cutoff)->whereKey($candidate->id);
                        $user = ($dryRun ? $query : $query->lockForUpdate())->first();
                        if (! $user) {
                            return 'no_longer_eligible';
                        }
                        if ($user->reservations()->exists() || $user->checkinLogs()->exists() || $user->statusHistories()->exists()) {
                            return 'business_records';
                        }
                        if ($dryRun) {
                            return 'eligible';
                        }
                        // Remove only authentication artifacts, never transaction or history rows.
                        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
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
}
