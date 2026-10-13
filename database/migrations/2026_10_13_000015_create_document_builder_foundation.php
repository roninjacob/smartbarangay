<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('service_document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_revision', 64);
            $table->string('source_filename');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('document_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->string('field_name', 64);
            $table->string('label', 150);
            $table->enum('source', ['system', 'custom']);
            $table->enum('field_type', ['text', 'number', 'date', 'dropdown']);
            $table->boolean('is_required')->default(false);
            $table->json('validation_rules')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['document_template_id', 'field_name']);
        });
        Schema::create('document_field_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_field_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            foreach (['x_position', 'y_position', 'width', 'height'] as $column) {
                $table->decimal($column, 10, 3);
            }
            $table->decimal('font_size', 5, 2)->default(12);
            $table->enum('alignment', ['left', 'center', 'right'])->default('left');
            $table->enum('text_behavior', ['auto_resize', 'wrap'])->default('auto_resize');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_field_positions');
        Schema::dropIfExists('document_fields');
        Schema::dropIfExists('document_templates');
    }
};
