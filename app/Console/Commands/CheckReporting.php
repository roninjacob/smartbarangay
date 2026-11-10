<?php

namespace App\Console\Commands;

use App\Exceptions\ReportingConfigurationException;
use App\Services\CollectionReports;
use App\Services\RecordedEventReports;
use App\Services\ReportingExclusions;
use App\Services\ReservationReports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class CheckReporting extends Command
{
    protected $signature = 'smartbarangay:reporting-check
        {--start= : First Philippine calendar date (YYYY-MM-DD)}
        {--end= : Last Philippine calendar date, inclusive (YYYY-MM-DD)}';

    protected $description = 'Read-only reporting configuration, schema and reconciliation diagnostics (no private data output)';

    public function handle(): int
    {
        $dates = ['start' => $this->option('start'), 'end' => $this->option('end')];
        $custom = $dates['start'] !== null || $dates['end'] !== null;
        if ($custom && Validator::make($dates, [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ])->fails()) {
            $this->error('invalid_period: supply both --start and --end as ordered YYYY-MM-DD dates.');

            return self::FAILURE;
        }

        $filters = $custom ? ['period' => 'custom', ...$dates] : ['period' => 'month'];
        $period = app(ReservationReports::class)->period($filters);
        $this->line('Read-only checks; no manifest approval or data repair is performed.');
        $this->line('Configuration cache: '.(app()->configurationIsCached() ? 'cached' : 'not cached'));
        $this->line('Period (Asia/Manila): '.$period['start']->toDateString().' through '.$period['end']->toDateString());

        try {
            // These are the columns read by the existing report queries, not a migration runner.
            $required = [
                'reservations' => ['id', 'user_id', 'service_id', 'schedule_id', 'created_at', 'status', 'fee_snapshot'],
                'users' => ['id', 'name'], 'services' => ['id', 'name'], 'schedules' => ['id'],
                'reservation_payments' => ['id', 'reservation_id', 'amount', 'paid_at', 'recorded_by'],
                'checkin_logs' => ['id', 'reservation_id', 'verification_result', 'verified_at', 'verified_by'],
                'reservation_documents' => ['id', 'reservation_id', 'generated_at', 'generated_by', 'mime_type',
                    'stored_path', 'file_size', 'template_version', 'content_hash', 'template_revision',
                    'template_filename', 'original_filename'],
            ];
            foreach ($required as $table => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                    $this->error('schema_missing: '.$table.' (review migrate:status; do not migrate blindly).');

                    return self::FAILURE;
                }
            }
            $this->info('Schema: PASS');

            return DB::transaction(function () use ($filters, $period) {
                app(ReportingExclusions::class)->validatedIds();
                $this->info('Reviewed manifest and all fixture identities: PASS');
                $failed = false;
                foreach (['reservations', 'collections', 'checkins', 'documents'] as $kind) {
                    try {
                        $data = match ($kind) {
                            'reservations' => app(ReservationReports::class)->data($filters, $period),
                            'collections' => app(CollectionReports::class)->data($filters, $period),
                            default => app(RecordedEventReports::class)->data($kind, $filters, $period),
                        };
                        // Exercise the integrity table partition as well as its financial reconciliation.
                        if ($kind === 'collections') {
                            app(CollectionReports::class)->data([...$filters, 'view' => 'integrity'], $period);
                        }
                        $this->info($kind.': PASS');
                        if (($data['integrity_count'] ?? $data['issues'] ?? 0) > 0) {
                            $this->warn($kind.': period-bound data-quality issues exist; excluded from valid totals.');
                        }
                    } catch (ReportingConfigurationException $exception) {
                        throw $exception;
                    } catch (RuntimeException $exception) {
                        $reason = $exception->getMessage() === ReportingExclusions::UNAVAILABLE
                            ? 'reconciliation_failed' : 'query_failed';
                        $this->error($kind.': '.$reason.' (no data changed).');
                        $failed = true;
                    }
                }

                return $failed ? self::FAILURE : self::SUCCESS;
            });
        } catch (ReportingConfigurationException $exception) {
            $this->error('Configuration: '.$exception->reason);
        } catch (Throwable) {
            // Never print SQL, connection details, row values, or exception messages.
            $this->error('database_or_schema_check_failed: verify database connectivity and schema privately.');
        }

        return self::FAILURE;
    }
}
