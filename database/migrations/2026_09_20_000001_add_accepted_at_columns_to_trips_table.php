<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Batch 15, Item 1: explicit Accept counterpart to the existing Decline
     * flow (S2, Batch 12). Modeled as nullable timestamps — consistent with
     * this table's existing acceptance-style columns (dispatch_decided_at)
     * and fleet_daily_pins' driver_verified_at/conductor_verified_at — so
     * "accepted" has its own auditable moment-in-time, independent of the
     * `status` lifecycle column (which represents the trip's overall state,
     * not each staff member's personal acknowledgement of their assignment).
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('driver_accepted_at')->nullable()->after('driver_id');
            $table->timestamp('conductor_accepted_at')->nullable()->after('conductor_id');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['driver_accepted_at', 'conductor_accepted_at']);
        });
    }
};
