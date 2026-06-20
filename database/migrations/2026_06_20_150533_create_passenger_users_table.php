<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('passenger_users', function (Blueprint $table) {
            $table->id('passenger_id'); // Auto-incrementing int8 Primary Key for easy readability
            $table->uuid('passenger_uuid')->unique(); // Secure, unique UUID identifier for APIs/Mobile
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('name');
            $table->string('phone_num');
            $table->string('address');
            $table->double('reward_points')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passenger_users');
    }
};
