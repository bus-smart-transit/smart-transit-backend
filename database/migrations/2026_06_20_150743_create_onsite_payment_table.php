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
        Schema::create('onsite_payment', function (Blueprint $table) {
            $table->id('onsite_pay_id');
            $table->foreignId('payment_id')->constrained('payments', 'payment_id')->onDelete('cascade');
            $table->foreignId('conductor_id')->constrained('company_users', 'company_user_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onsite_payment');
    }
};
