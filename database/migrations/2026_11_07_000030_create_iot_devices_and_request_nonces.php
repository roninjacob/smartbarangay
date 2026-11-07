<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->text('credential');
            $table->unsignedInteger('credential_version')->default(1);
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('iot_request_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('device_id')->constrained('iot_devices')->cascadeOnDelete();
            $table->char('nonce_hash', 64);
            $table->dateTime('expires_at')->index();
            $table->unique(['device_id', 'nonce_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_request_nonces');
        Schema::dropIfExists('iot_devices');
    }
};
