<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkin_logs', function (Blueprint $table) {
            // Legacy verification attempts remain unrestricted; only explicit arrival events are unique.
            $table->unsignedBigInteger('checked_in_reservation_id')->nullable()
                ->virtualAs("CASE WHEN verification_result = 'checked_in' THEN reservation_id ELSE NULL END");
            $table->unique('checked_in_reservation_id', 'checkin_logs_one_arrival_unique');
        });
    }

    public function down(): void
    {
        Schema::table('checkin_logs', function (Blueprint $table) {
            $table->dropUnique('checkin_logs_one_arrival_unique');
            $table->dropColumn('checked_in_reservation_id');
        });
    }
};
