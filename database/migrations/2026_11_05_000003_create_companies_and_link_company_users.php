<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 46 phase 0 (decided 2026-11-05/06, least privilege): staff belong to a company, not to one Operator login.
 * - companies: the bus company (name set by the Admin). A company may have several Operator accounts; all of them see
 *   the same staff.
 * - company_users.company_id: the company an Operator, Driver or Chauffeur works for (null for the Admin). Additive.
 * Replaces the unreleased company_users.operator_company_user_id of 2026-11-05 (never pushed or deployed).
 * Next step (own commit): fleets, trips, blocks and fares owned by company_id too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id('company_id');
            $table->string('company_name', 120)->unique();
            $table->timestamps();
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('company_id')->on('companies')->restrictOnDelete();
            $table->index('company_id', 'company_users_company_idx');
        });
    }

    public function down(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('company_users_company_idx');
            $table->dropColumn('company_id');
        });

        Schema::dropIfExists('companies');
    }
};
