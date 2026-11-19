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
        Schema::table('trips', function (Blueprint $table) {
            $table->string('dispatch_decision')->nullable()->after('status');
            $table->string('dispatch_route')->nullable()->after('dispatch_decision');
            $table->text('dispatch_reason')->nullable()->after('dispatch_route');
            $table->timestamp('dispatch_decided_at')->nullable()->after('dispatch_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn([
                'dispatch_decision',
                'dispatch_route',
                'dispatch_reason',
                'dispatch_decided_at',
            ]);
        });
    }
};
