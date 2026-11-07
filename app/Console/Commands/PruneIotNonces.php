<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIotNonces extends Command
{
    protected $signature = 'smartbarangay:prune-iot-nonces';

    protected $description = 'Remove expired IoT replay protection records';

    public function handle(): int
    {
        try {
            $count = DB::table('iot_request_nonces')->where('expires_at', '<=', now('UTC'))->delete();
        } catch (\Throwable) {
            $this->error('IoT nonce cleanup unavailable. Check migration readiness.');

            return self::FAILURE;
        }
        $this->info($count.' expired IoT nonce records removed.');

        return self::SUCCESS;
    }
}
