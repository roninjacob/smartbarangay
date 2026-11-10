<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iot_scanner_pairings', function (Blueprint $table) {
            $table->boolean('automatic')->default(false);
            $table->unsignedBigInteger('monitor_started_ms')->default(0);
            $table->dateTime('preview_until', 3)->nullable();
        });
        Schema::create('iot_device_states', function (Blueprint $table) {
            $table->foreignUuid('device_id')->primary()->constrained('iot_devices')->cascadeOnDelete();
            $table->char('boot_id', 32);
            $table->dateTime('last_seen_at', 3);
            $table->string('camera_state', 24)->default('ready');
            $table->unsignedInteger('last_session')->default(0);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('last_capture')->default(0);
            $table->unsignedBigInteger('last_status_capture')->default(0);
            $table->char('last_payload_hash', 64)->nullable();
            $table->dateTime('last_scan_at', 3)->nullable();
            $table->string('last_result', 16)->nullable();
            $table->uuid('frame_lease')->nullable();
            $table->unsignedInteger('frame_sequence')->default(0);
            $table->dateTime('frame_at', 3)->nullable();
        });
        // Device events are independent of browser leases. Legacy paired events stay intact.
        Schema::create('iot_device_scan_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('iot_devices')->cascadeOnDelete();
            $table->char('boot_id', 32);
            $table->unsignedInteger('scanner_session');
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('captured_at_ms');
            $table->dateTime('expires_at', 3)->index();
            $table->text('payload')->nullable();
            $table->dateTime('consumed_at', 3)->nullable();
            $table->unique(['device_id', 'boot_id', 'scanner_session', 'sequence'], 'iot_auto_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_device_scan_events');
        Schema::dropIfExists('iot_device_states');
        Schema::table('iot_scanner_pairings', fn (Blueprint $table) => $table->dropColumn(['automatic', 'monitor_started_ms', 'preview_until']));
    }
};
