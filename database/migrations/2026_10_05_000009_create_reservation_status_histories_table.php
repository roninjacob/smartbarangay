<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->enum('from_status', ['pending', 'under_review', 'approved', 'ready_for_pickup', 'completed', 'rejected'])->nullable();
            $table->enum('to_status', ['pending', 'under_review', 'approved', 'ready_for_pickup', 'completed', 'rejected']);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('changed_at');
            $table->index(['reservation_id', 'changed_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_histories');
    }
};
