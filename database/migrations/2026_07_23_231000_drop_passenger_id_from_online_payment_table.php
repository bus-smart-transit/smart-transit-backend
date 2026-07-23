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
        Schema::table('online_payment', function (Blueprint $table) {
            if (Schema::hasColumn('online_payment', 'passenger_id')) {
                try {
                    $table->dropForeign(['passenger_id']);
                } catch (\Throwable $e) {
                    // Ignore if foreign key is already absent.
                }

                $table->dropColumn('passenger_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('online_payment', function (Blueprint $table) {
            if (!Schema::hasColumn('online_payment', 'passenger_id')) {
                $table->unsignedBigInteger('passenger_id')->nullable()->after('online_payment_id');
                $table->foreign('passenger_id')
                    ->references('passenger_id')
                    ->on('passenger_users')
                    ->nullOnDelete();
            }
        });
    }
};
