<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Canonical route geometry (Batch 24, 1.4). PostGIS geography(LineString)
        // with a GiST index on Postgres; GeoJSON text on the sqlite test DB.
        Schema::table('routes', function (Blueprint $table) use ($driver) {
            if ($driver !== 'pgsql') {
                $table->text('route_geometry')->nullable();
            }
            $table->boolean('geometry_stale')->default(false);
            $table->timestamp('geometry_generated_at')->nullable();
            $table->text('geometry_error')->nullable();
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE routes ADD COLUMN route_geometry geography(LineString, 4326)');
            DB::statement('CREATE INDEX routes_route_geometry_gix ON routes USING GIST (route_geometry)');
        }

        Schema::table('route_stop', function (Blueprint $table) {
            $table->string('stop_type', 20)->default('stop');
            $table->decimal('snapped_latitude', 10, 7)->nullable();
            $table->decimal('snapped_longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_from_origin_m')->nullable();
            $table->unsignedSmallInteger('scheduled_offset_min')->nullable();
        });

        // Conservative backfill (reviewable): first/last stop of each route is a
        // terminal, everything else a regular stop. Nothing is set to pass_through.
        DB::table('route_stop')->update([
            'distance_from_origin_m' => DB::raw('ROUND(distance_from_origin_km * 1000)'),
        ]);

        $routeIds = DB::table('route_stop')->distinct()->pluck('route_id');
        foreach ($routeIds as $routeId) {
            $first = DB::table('route_stop')->where('route_id', $routeId)->orderBy('stop_order')->value('id');
            $last = DB::table('route_stop')->where('route_id', $routeId)->orderByDesc('stop_order')->value('id');
            DB::table('route_stop')->whereIn('id', array_filter([$first, $last]))->update(['stop_type' => 'terminal']);
        }

        // GPS accuracy radius (metres) reported by the device, latest position only.
        Schema::table('fleet_locations', function (Blueprint $table) {
            $table->decimal('accuracy_m', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_locations', function (Blueprint $table) {
            $table->dropColumn('accuracy_m');
        });

        Schema::table('route_stop', function (Blueprint $table) {
            $table->dropColumn(['stop_type', 'snapped_latitude', 'snapped_longitude', 'distance_from_origin_m', 'scheduled_offset_min']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS routes_route_geometry_gix');
        }

        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['route_geometry', 'geometry_stale', 'geometry_generated_at', 'geometry_error']);
        });
    }
};
