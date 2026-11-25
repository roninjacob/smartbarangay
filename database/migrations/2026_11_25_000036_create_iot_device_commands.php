<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable desired state and command log for software scanner control (ON/OFF, restart).
     * Additive only: no existing table or data changes.
     */
    public function up(): void
    {
        Schema::create('iot_device_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('iot_devices')->cascadeOnDelete();
            // One monotonic sequence per device across command types; the highest revision wins.
            $table->unsignedInteger('revision');
            $table->string('type', 16);
            $table->boolean('enabled')->nullable();
            $table->string('status', 16);
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            // Boot identifier when a restart was acknowledged; a different boot later proves the restart.
            $table->char('boot_id', 32)->nullable();
            $table->string('result', 48)->nullable();
            $table->dateTime('delivered_at', 3)->nullable();
            $table->dateTime('acknowledged_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('expires_at', 3)->nullable();
            $table->timestamps(3);
            $table->unique(['device_id', 'revision']);
            $table->index(['device_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_device_commands');
    }
};
