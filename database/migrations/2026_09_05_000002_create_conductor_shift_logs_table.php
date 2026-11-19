<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductor_shift_logs', function (Blueprint $table) {
            $table->id('shift_log_id');
            $table->unsignedBigInteger('conductor_company_user_id');
            $table->unsignedBigInteger('trip_id');
            $table->unsignedBigInteger('fleet_id')->nullable();
            $table->date('shift_date');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->string('start_source', 40)->default('dashboard');
            $table->string('end_source', 40)->nullable();
            $table->timestamps();

            $table->foreign('conductor_company_user_id')->references('company_user_id')->on('company_users')->onDelete('cascade');
            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->foreign('fleet_id')->references('fleet_id')->on('fleets')->onDelete('set null');

            $table->index(['conductor_company_user_id', 'ended_at']);
            $table->index(['conductor_company_user_id', 'shift_date']);
            $table->index(['trip_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductor_shift_logs');
    }
};
