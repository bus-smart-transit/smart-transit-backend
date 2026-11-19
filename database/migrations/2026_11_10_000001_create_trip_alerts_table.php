<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 58 (user, 2026-11-10, approved): the crew's "Alert operator" button. One row per alert a Driver or Chauffeur
 * raises on their trip (breakdown, accident, road blocked; the types are in config/alerts.php), with where the bus was,
 * and what the Operator did: handled it, and whether passengers were told. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_alerts', function (Blueprint $table) {
            $table->id('alert_id');
            $table->foreignId('trip_id')->constrained('trips', 'trip_id')->cascadeOnDelete();
            $table->foreignId('raised_by_company_user_id')->constrained('company_users', 'company_user_id');
            $table->string('raised_role', 16); // driver | conductor
            $table->string('type', 32);
            $table->string('note', 255)->nullable();
            // Where the bus was: the phone's position when sent, else the bus's last GPS report; null when neither.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_source', 16)->nullable(); // phone | gps
            $table->string('status', 16)->default('open'); // open | handled | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('handled_by_company_user_id')->nullable()->constrained('company_users', 'company_user_id');
            $table->timestamp('handled_at')->nullable();
            $table->string('handled_note', 255)->nullable();
            $table->foreignId('passengers_told_by_company_user_id')->nullable()->constrained('company_users', 'company_user_id');
            $table->timestamp('passengers_told_at')->nullable();
            $table->unsignedInteger('passengers_told_count')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_alerts');
    }
};
