<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reward_transactions', function (Blueprint $table) {
            $table->id('reward_transaction_id');
            $table->foreignId('passenger_id')->constrained('passenger_users', 'passenger_id')->cascadeOnDelete();
            // Nullable: redemptions aren't tied to a purchase, and this also
            // keeps the row insertable if a payment is ever hard-deleted.
            $table->foreignId('payment_id')->nullable()->constrained('payments', 'payment_id')->nullOnDelete();
            // Signed: positive for 'earned', negative for 'redeemed' — makes
            // SUM(points) a trivial correctness check against reward_points.
            $table->integer('points');
            $table->enum('type', ['earned', 'redeemed']);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['passenger_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_transactions');
    }
};