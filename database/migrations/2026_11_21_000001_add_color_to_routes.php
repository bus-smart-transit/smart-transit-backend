<?php

use App\Support\RouteColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-21 (user): each route keeps its own colour (RouteColor picks it). Existing routes get theirs in route order,
 * so routes 1 to 8 keep the colours they already showed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('route_name');
        });

        $used = [];
        foreach (DB::table('routes')->orderBy('route_id')->pluck('route_id') as $routeId) {
            $color = RouteColor::nextFree($used);
            $used[] = $color;
            DB::table('routes')->where('route_id', $routeId)->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
