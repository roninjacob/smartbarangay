<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->date('birthdate')->nullable();
            $table->string('civil_status', 80)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('address_street', 255)->nullable();
            $table->string('address_zone_purok', 120)->nullable();
            $table->string('emergency_contact_name', 255)->nullable();
            $table->string('emergency_contact_number', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_profiles');
    }
};
