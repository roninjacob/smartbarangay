<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_documents', function (Blueprint $table) {
            $table->unsignedInteger('template_version')->nullable();
            $table->string('content_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_documents', fn (Blueprint $table) => $table->dropColumn(['template_version', 'content_hash']));
    }
};
