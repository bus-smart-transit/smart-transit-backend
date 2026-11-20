<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-20 (user): a general fare rule (no fleet) belongs to a company. The Company Admin sets their company's fare
 * per vehicle class and service type; it prices only that company's buses, and a bus's own rule still wins.
 * fare_rules.company_id is null for a per-bus rule (the bus says the company) and for any older general rule. Additive:
 * production has no general rule yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fare_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('company_id')->on('companies')->restrictOnDelete();
            $table->index(['company_id', 'vehicle_class', 'service_type'], 'fare_rules_company_class_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fare_rules', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('fare_rules_company_class_idx');
            $table->dropColumn('company_id');
        });
    }
};
