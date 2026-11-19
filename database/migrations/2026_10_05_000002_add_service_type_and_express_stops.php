<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 28 (item 3): service type regular | express is a property of the SCHEDULE
     * (the trip), not of the route.
     *
     * - trips.service_type: server-validated enum, default regular, so every existing trip stays regular.
     * - route_stop.served_by_express: which stops an express trip still stops at. Editable by the
     *   operator or admin. Backfilled conservatively: only terminals are marked true. Every
     *   other stop is false and needs a person to confirm it (reported, never guessed).
     * - route_stop.express_offset_min: the minutes from departure at which an EXPRESS trip reaches the
     *   stop. Null everywhere today, so express times show "ETA unavailable" until someone enters them.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('service_type', 16)->default('regular')->after('leg_direction');
        });

        Schema::table('route_stop', function (Blueprint $table) {
            $table->boolean('served_by_express')->default(false)->after('stop_type');
            $table->smallInteger('express_offset_min')->nullable()->after('scheduled_offset_min');
        });

        DB::table('route_stop')->where('stop_type', 'terminal')->update(['served_by_express' => true]);
    }

    public function down(): void
    {
        Schema::table('route_stop', function (Blueprint $table) {
            $table->dropColumn(['served_by_express', 'express_offset_min']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
