<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_scanner_pairings', function (Blueprint $table) {
            $table->foreignUuid('device_id')->primary()->constrained('iot_devices')->cascadeOnDelete();
            $table->uuid('id')->unique();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->text('session_id');
            $table->char('session_hash', 64)->unique();
            $table->char('binding_hash', 64);
            $table->char('auth_hash', 64);
            $table->unsignedInteger('credential_version');
            $table->char('challenge_hash', 64)->nullable();
            $table->dateTime('challenge_expires_at');
            $table->dateTime('paired_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_browser_seen_at');
            $table->char('boot_id', 32)->nullable();
            $table->unsignedInteger('last_scan_session')->default(0);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('last_captured_at_ms')->default(0);
        });
        Schema::create('iot_scan_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pairing_id')->references('id')->on('iot_scanner_pairings')->cascadeOnDelete();
            $table->char('boot_id', 32);
            $table->unsignedInteger('scanner_session');
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('captured_at_ms');
            $table->dateTime('expires_at', 3)->index();
            $table->text('payload')->nullable();
            $table->dateTime('consumed_at', 3)->nullable();
            $table->unique(['pairing_id', 'boot_id', 'scanner_session', 'sequence'], 'iot_event_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_scan_events');
        Schema::dropIfExists('iot_scanner_pairings');
    }
};
