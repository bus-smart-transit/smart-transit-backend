<?php

use App\Support\OperatingWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-21 (user): a bus has one operating window (set when it is added), and the route is chosen when a trip is
 * scheduled. Each existing bus gets the smallest window that covers the hours of its active bus-and-route rows, so no
 * departure that was allowed before is refused now. A bus with no route rows keeps no window (nothing is limited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->time('operating_start_time')->nullable();
            $table->time('operating_end_time')->nullable();
        });

        $rows = DB::table('fleets_routes')->where('status', 'active')->get(['fleet_id', 'start_time', 'end_time'])->groupBy('fleet_id');
        foreach ($rows as $fleetId => $links) {
            $window = OperatingWindow::cover($links
                ->filter(fn ($link) => $link->start_time && $link->end_time)
                ->map(fn ($link) => [(string) $link->start_time, (string) $link->end_time])
                ->values()->all());
            if ($window !== null) {
                DB::table('fleets')->where('fleet_id', $fleetId)->update(['operating_start_time' => $window[0], 'operating_end_time' => $window[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->dropColumn(['operating_start_time', 'operating_end_time']);
        });
    }
};
