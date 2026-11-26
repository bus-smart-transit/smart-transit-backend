<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-26 (user): how many pieces of baggage a passenger brings for the compartment (the passenger enters it when
 * booking online; "2x baggage" on the ticket and in the Chauffeur's scan pop-up). The fee is still set per piece by
 * size and paid in cash on board (baggage_charges). Existing tickets with baggage count as one piece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('baggage_count')->default(0)->after('has_baggage');
        });

        DB::table('tickets')->where('has_baggage', true)->update(['baggage_count' => 1]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('baggage_count');
        });
    }
};
