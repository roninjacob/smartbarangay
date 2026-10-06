<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL = ['pending', 'under_review', 'approved', 'ready_for_pickup', 'completed', 'rejected'];

    public function up(): void
    {
        $this->changeStatuses([...self::ORIGINAL, 'cancelled']);
    }

    public function down(): void
    {
        if (DB::table('reservations')->where('status', 'cancelled')->exists()
            || DB::table('reservation_status_histories')->where('from_status', 'cancelled')->orWhere('to_status', 'cancelled')->exists()) {
            throw new RuntimeException('Cannot remove Cancelled status while cancellation records exist. Preserve reservation and audit data.');
        }
        $this->changeStatuses(self::ORIGINAL);
    }

    private function changeStatuses(array $statuses): void
    {
        Schema::table('reservations', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('pending')->change();
        });
        Schema::table('reservation_status_histories', function (Blueprint $table) use ($statuses) {
            $table->enum('from_status', $statuses)->nullable()->change();
            $table->enum('to_status', $statuses)->change();
        });
    }
};
