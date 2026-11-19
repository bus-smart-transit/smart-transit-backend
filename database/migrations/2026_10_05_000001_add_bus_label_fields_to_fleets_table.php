<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 28 (item 2): the passenger-facing bus label is "{bus_company_name} - {body_no}".
     * Both are nullable on purpose: existing fleets carry no data for them, and the
     * passenger screens then say the label is unavailable instead of inventing one.
     * The plate stays on staff and operator screens.
     */
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->string('bus_company_name', 100)->nullable()->after('plate_number');
            $table->string('body_no', 30)->nullable()->after('bus_company_name');
        });
    }

    public function down(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->dropColumn(['bus_company_name', 'body_no']);
        });
    }
};
