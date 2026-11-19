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
        Schema::create('trip_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips', 'trip_id')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->unsignedBigInteger('actor_company_user_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('actor_company_user_id')->references('company_user_id')->on('company_users')->nullOnDelete();
            $table->index(['type', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_notifications');
    }
};
