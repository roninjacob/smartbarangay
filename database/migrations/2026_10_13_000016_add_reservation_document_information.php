<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->json('resident_snapshot')->nullable();
            $table->json('document_template_snapshot')->nullable();
        });
        Schema::create('reservation_document_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_field_id')->nullable()->constrained()->nullOnDelete();
            $table->json('field_snapshot');
            $table->text('answer')->nullable();
            $table->timestamps();
            $table->unique(['reservation_id', 'document_field_id'], 'reservation_document_answer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_document_answers');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['resident_snapshot', 'document_template_snapshot']);
        });
    }
};
