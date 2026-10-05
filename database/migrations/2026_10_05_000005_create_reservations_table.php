<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $table->text('purpose')->nullable();
            $table->enum('status', ['pending', 'under_review', 'approved', 'ready_for_pickup', 'completed', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->index(['user_id', 'status']);
            $table->index(['schedule_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
