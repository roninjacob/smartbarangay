<?php

namespace App\Console\Commands;

use App\Services\IotAutomaticScanner;
use App\Services\IotTelemetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIotScannerState extends Command
{
    protected $signature = 'smartbarangay:prune-iot-scanner-state';

    protected $description = 'Remove expired private scanner handoff state';

    public function handle(): int
    {
        app(IotAutomaticScanner::class)->prune();
        app(IotTelemetry::class)->prune();
        DB::table('iot_scan_events')->where('expires_at', '<=', now('UTC'))
            ->where('captured_at_ms', '<=', (int) now()->getTimestampMs() - 5000)->delete();
        DB::table('iot_scanner_pairings')->where('expires_at', '<=', now('UTC'))->delete();
        $this->info('Expired scanner handoff state removed.');

        return self::SUCCESS;
    }
}
