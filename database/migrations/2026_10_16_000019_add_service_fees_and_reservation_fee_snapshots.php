<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('fee_amount', 10, 2)->nullable();
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('fee_snapshot', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('fee_snapshot'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('fee_amount'));
    }
};
