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
        Schema::create('payments', function (Blueprint $table) {
            $table->id('payment_id');
            $table->double('amount'); // total for the whole transaction
            $table->timestamp('payment_created');
            $table->string('transaction_reference'); // one per checkout
            $table->string('payment_method');
            $table->string('payment_channel');
            $table->string('status');
            $table->string('payment_uuid')->unique();
            $table->boolean('is_valid')->default(true);
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
