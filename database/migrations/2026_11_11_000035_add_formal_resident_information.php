<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 80)->nullable();
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('suffix', 20)->nullable();
        });
        Schema::table('resident_profiles', function (Blueprint $table) {
            $table->string('house_unit_number', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resident_profiles', fn (Blueprint $table) => $table->dropColumn('house_unit_number'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name', 'middle_name', 'last_name', 'suffix']));
    }
};
