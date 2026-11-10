<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iot_device_states', function (Blueprint $table) {
            $table->json('telemetry')->nullable();
            $table->dateTime('heartbeat_at', 3)->nullable();
            $table->dateTime('telemetry_at', 3)->nullable();
            $table->dateTime('counts_since', 3)->nullable();
            $table->unsignedBigInteger('scan_count')->default(0);
            $table->unsignedBigInteger('valid_count')->default(0);
            $table->unsignedBigInteger('invalid_count')->default(0);
            $table->dateTime('offline_reported_at', 3)->nullable();
            $table->unsignedInteger('last_handoff_age_ms')->nullable();
            $table->unsignedBigInteger('frame_captured_ms')->nullable();
        });
        Schema::create('iot_device_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('device_id')->constrained('iot_devices')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->json('details')->nullable();
            $table->dateTime('recorded_at', 3);
            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_device_diagnostics');
        Schema::table('iot_device_states', fn (Blueprint $table) => $table->dropColumn([
            'telemetry', 'heartbeat_at', 'telemetry_at', 'counts_since', 'scan_count', 'valid_count',
            'invalid_count', 'offline_reported_at', 'last_handoff_age_ms', 'frame_captured_ms',
        ]));
    }
};
