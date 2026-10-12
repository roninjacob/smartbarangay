<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_tickets', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qr_tickets', function (Blueprint $table) {
            $table->dropForeign(['used_by']);
            $table->dropColumn(['used_at', 'used_by']);
        });
    }
};
