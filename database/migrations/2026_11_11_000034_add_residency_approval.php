<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('residency_status', 20)->default('pending')->index();
            $table->unsignedInteger('residency_revision')->default(0);
            $table->dateTime('residency_reviewed_at')->nullable();
        });
        if (DB::getDriverName() === 'sqlite') {
            // Native ADD COLUMN preserves the existing role CHECK constraint.
            // SQLite's table rebuild for a separate FK would discard that CHECK.
            DB::statement('ALTER TABLE users ADD COLUMN residency_reviewed_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('residency_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            });
        }

        Schema::create('residency_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('resident_message')->nullable();
            $table->dateTime('reviewed_at');
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residency_reviews');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE users DROP COLUMN residency_reviewed_by');
        } else {
            Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('residency_reviewed_by'));
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['residency_status']);
            $table->dropColumn(['residency_status', 'residency_revision', 'residency_reviewed_at']);
        });
    }
};
