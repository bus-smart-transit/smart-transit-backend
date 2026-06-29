<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id('ticket_id');
            $table->foreignId('fleet_route_id')->constrained('fleets_routes', 'fleet_route_id');
            $table->foreignId('trip_id')->constrained('trips', 'trip_id');
            $table->foreignId('fare_id')->constrained('fare_matrix', 'fare_id');       // per-ticket price reference
            $table->foreignId('payment_id')->constrained('payments', 'payment_id');   // ← new: which transaction paid for this ticket
            $table->foreignId('passenger_id')->nullable()->constrained('passenger_users', 'passenger_id');
            $table->string('ticket_uuid')->unique(); // already your per-ticket reference number
            $table->string('status');
            $table->decimal('amount'); // this ticket's individual frozen price
            $table->timestamp('boarded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
