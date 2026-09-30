<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Assignment-level requests (Batch 24, C6 / D6). Deliberately separate
        // from the trip lifecycle status: a staff member asking to be released
        // from an assignment ("For Approval") never changes the trip's state.
        Schema::create('trip_assignment_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id');
            $table->string('role', 20); // driver | conductor
            $table->unsignedBigInteger('staff_company_user_id');
            $table->string('reason_code', 30)->nullable();
            $table->string('reason_text', 300)->nullable();
            $table->string('status', 20)->default('for_approval'); // for_approval | approved | rejected
            $table->unsignedBigInteger('decided_by_company_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->index(['trip_id', 'role', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_assignment_requests');
    }
};
