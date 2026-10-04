<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Batch 27, Task 2c: the exact server moment a cash ticket was issued, kept in
// UTC (the rest of the app stores Manila wall-clock times; this one value is
// explicit so it cannot be misread). Screens convert it to Manila time.
// "Who issued it" is already onsite_payment.conductor_id and the payment method
// is payments.payment_method = 'cash'. Existing rows keep NULL here.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('onsite_payment', 'issued_at')) {
            return;
        }

        Schema::table('onsite_payment', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('onsite_payment', 'issued_at')) {
            Schema::table('onsite_payment', function (Blueprint $table) {
                $table->dropColumn('issued_at');
            });
        }
    }
};
