<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 46 step 2 (decided 2026-11-06): a bus belongs to a company. fleets.company_id is filled from the company of
 * the Operator who registered the bus (fleets.company_user_id, which stays as "registered by"). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('company_id')->on('companies')->restrictOnDelete();
            $table->index('company_id', 'fleets_company_idx');
        });

        DB::table('fleets')->whereNull('company_id')->orderBy('fleet_id')->each(function ($fleet) {
            $companyId = DB::table('company_users')->where('company_user_id', $fleet->company_user_id)->value('company_id');
            if ($companyId !== null) {
                DB::table('fleets')->where('fleet_id', $fleet->fleet_id)->update(['company_id' => $companyId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('fleets_company_idx');
            $table->dropColumn('company_id');
        });
    }
};
