<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-27: the discount a cash ticket was sold with, copied onto the ticket at the sale (a later change of the type's
 * label or rate never changes an issued ticket). `amount` stays what the passenger paid; `original_amount` is the fare
 * before the discount. Null on every ticket sold without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('discount_type_key', 32)->nullable();
            $table->string('discount_label', 64)->nullable();
            $table->decimal('discount_rate', 4, 3)->nullable();
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->timestamp('discount_id_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['discount_type_key', 'discount_label', 'discount_rate', 'original_amount', 'discount_amount', 'discount_id_checked_at']);
        });
    }
};
