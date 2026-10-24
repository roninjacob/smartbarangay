<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->date('appointment_date')->nullable();
            $table->time('appointment_start_time')->nullable();
            $table->time('appointment_end_time')->nullable();
            $table->timestamp('requirements_snapshotted_at')->nullable();
        });
        Schema::create('reservation_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_required');
            $table->timestamps();
            $table->unique(['reservation_id', 'service_requirement_id'], 'reservation_requirement_source_unique');
        });
        Schema::table('reservation_attachments', function (Blueprint $table) {
            $table->foreignId('reservation_requirement_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_requirement_id');
        });
        Schema::dropIfExists('reservation_requirements');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['appointment_date', 'appointment_start_time', 'appointment_end_time', 'requirements_snapshotted_at']);
        });
    }
};
