<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-06 (decided by the user): least privilege inside a company and between companies. Additive.
 * - company_users.is_company_admin: an Operator who manages the company's buses, fares, staff, routes and stops. Other
 *   Operators of the company dispatch (schedule trips, assign crew) and only view those. No new role (section 7).
 * - routes.company_id and stops.company_id: the company that owns them; another company's are not found. Existing rows
 *   get a company only when exactly one company uses them (through its buses or routes); the rest stay with the Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->boolean('is_company_admin')->default(false);
        });

        foreach (['routes', 'stops'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('company_id')->nullable();
                $table->foreign('company_id')->references('company_id')->on('companies')->restrictOnDelete();
                $table->index('company_id', "{$name}_company_idx");
            });
        }

        // Routes: the one company whose buses run them.
        $routeCompanies = DB::table('fleets_routes')
            ->join('fleets', 'fleets.fleet_id', '=', 'fleets_routes.fleet_id')
            ->whereNotNull('fleets.company_id')
            ->select('fleets_routes.route_id', 'fleets.company_id')
            ->distinct()
            ->get()
            ->groupBy('route_id');
        foreach ($routeCompanies as $routeId => $rows) {
            if ($rows->count() === 1) {
                DB::table('routes')->where('route_id', $routeId)->update(['company_id' => $rows->first()->company_id]);
            }
        }

        // Stops: the one company whose routes use them.
        $stopCompanies = DB::table('route_stop')
            ->join('routes', 'routes.route_id', '=', 'route_stop.route_id')
            ->whereNotNull('routes.company_id')
            ->select('route_stop.stop_id', 'routes.company_id')
            ->distinct()
            ->get()
            ->groupBy('stop_id');
        foreach ($stopCompanies as $stopId => $rows) {
            if ($rows->count() === 1) {
                DB::table('stops')->where('stop_id', $stopId)->update(['company_id' => $rows->first()->company_id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['routes', 'stops'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['company_id']);
                $table->dropIndex("{$name}_company_idx");
                $table->dropColumn('company_id');
            });
        }

        Schema::table('company_users', function (Blueprint $table) {
            $table->dropColumn('is_company_admin');
        });
    }
};
