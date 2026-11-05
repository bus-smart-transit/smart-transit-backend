<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 46 phase 0 (decided 2026-11-05, least privilege): a Driver or Chauffeur belongs to the Operator (company) that
 * created them. company_users.operator_company_user_id points at that Operator's own company_users row; it is empty for
 * Operators and Admins. Additive: existing rows read null (the local data is reseeded; production is cleaned only with
 * the user's OK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_company_user_id')->nullable();
            $table->foreign('operator_company_user_id')->references('company_user_id')->on('company_users')->nullOnDelete();
            $table->index('operator_company_user_id', 'company_users_operator_idx');
        });
    }

    public function down(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropForeign(['operator_company_user_id']);
            $table->dropIndex('company_users_operator_idx');
            $table->dropColumn('operator_company_user_id');
        });
    }
};
