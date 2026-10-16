<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->usesAutomaticTimestamps()) {
            // An explicit insert default without ON UPDATE disables MariaDB's implicit first-TIMESTAMP behavior.
            DB::statement('ALTER TABLE `qr_tickets` MODIFY `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
    }

    public function down(): void
    {
        if ($this->usesAutomaticTimestamps()) {
            // Schema reversibility only: rollback reintroduces the known issuance-history defect.
            DB::statement('ALTER TABLE `qr_tickets` MODIFY `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        }
    }

    private function usesAutomaticTimestamps(): bool
    {
        return match (DB::getDriverName()) {
            'mysql', 'mariadb' => true,
            // SQLite has no automatic ON UPDATE clause to remove; lifecycle tests still run there.
            'sqlite' => false,
            default => throw new RuntimeException('QR timestamp repair supports MariaDB/MySQL and isolated SQLite only.'),
        };
    }
};
