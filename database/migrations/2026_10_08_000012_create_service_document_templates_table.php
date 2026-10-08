<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->restrictOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_document_templates');
    }
};
