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
        Schema::create('payments', function (Blueprint $table) {
            $table->id('payment_id');
            $table->double('amount');
            $table->timestamp('payment_created');
            $table->string('transaction_reference');
            $table->string('payment_method');
            $table->foreignId('fare_id')->constrained('fare_matrix', 'fare_id');
            $table->foreignId('ticket_id')->constrained('tickets', 'ticket_id');
            $table->string('payment_channel');
            $table->string('status');
            $table->string('payment_uuid')->unique();
            $table->string('is_valid');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
