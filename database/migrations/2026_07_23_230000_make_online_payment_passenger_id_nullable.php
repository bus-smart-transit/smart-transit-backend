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
        Schema::table('online_payment', function (Blueprint $table) {
            $table->dropForeign(['passenger_id']);
            $table->unsignedBigInteger('passenger_id')->nullable()->change();
            $table->foreign('passenger_id')
                ->references('passenger_id')
                ->on('passenger_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('online_payment', function (Blueprint $table) {
            $table->dropForeign(['passenger_id']);
            $table->unsignedBigInteger('passenger_id')->nullable(false)->change();
            $table->foreign('passenger_id')
                ->references('passenger_id')
                ->on('passenger_users');
        });
    }
};
