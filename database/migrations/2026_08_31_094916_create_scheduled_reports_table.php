<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id('report_id');
            $table->unsignedBigInteger('fleet_id')->nullable(); // null = all fleets
            $table->string('report_type', 40);   // 'daily_summary'|'financial'|'occupancy_trends'|'revenue_by_route'
            $table->date('report_date');          // the calendar day this report covers
            $table->json('payload');              // full report data
            $table->string('status', 20)->default('generated'); // 'generated'|'failed'
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->useCurrent();

            $table->foreign('fleet_id')->references('fleet_id')->on('fleets')->onDelete('cascade');
            $table->index(['fleet_id', 'report_type', 'report_date']);
            $table->unique(['fleet_id', 'report_type', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
