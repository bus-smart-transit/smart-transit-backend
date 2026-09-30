<?php

namespace Database\Seeders;

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Services\ShiftBlockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Dev/mockup operations for the Davao - Tagum corridor (Batch 25), so the route can be
 * booked end to end: a fleet on the route, fare rules, and upcoming shift blocks.
 *
 * Shift blocks are NOT created automatically when a fleet is assigned to a route: an
 * Operator creates each block (crew, date, four leg departure times). This seeder does
 * that through ShiftBlockService, so every rule (one block per staff per day, rest rule,
 * driver/conductor roles) still applies; a day with no crew that qualifies is skipped.
 *
 * Idempotent: an existing fleet route / fare rule is reused, and a date that already has
 * trips on the route is left alone. Fares are mock rates (same as the other dev fleet).
 * Leg departure times follow the route's own end-to-end duration plus the configured
 * turnaround buffer (no typed-in schedule).
 *
 * Run: php artisan db:seed --class=DavaoTagumOperationsSeeder
 */
class DavaoTagumOperationsSeeder extends Seeder
{
    private const ROUTE_NAME = 'Davao - Tagum';

    /** First leg leaves at this time; later legs follow from the route duration. */
    private const FIRST_LEG = '06:00';

    /** Shift blocks are created for today and this many days in total. */
    private const DAYS_AHEAD = 5;

    /** Mock per-seat-type rates (base fare, fare per km). */
    private const MOCK_FARES = [
        'seated' => [13.00, 2.25],
        'standing' => [11.00, 1.75],
    ];

    public function run(): void
    {
        $route = Route::where('route_name', self::ROUTE_NAME)->first();
        if (! $route) {
            $this->say('Route "'.self::ROUTE_NAME.'" not found. Run DavaoTagumRouteSeeder first.', true);

            return;
        }

        $fleet = $this->fleetFor($route);
        if (! $fleet) {
            $this->say('No active fleet is free to put on this route. Create a fleet first.', true);

            return;
        }

        $fleetRoute = FleetRoute::firstOrCreate(
            ['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id],
            ['start_time' => '05:00', 'end_time' => '21:00', 'status' => 'active']
        );
        if ($fleetRoute->status !== 'active') {
            $fleetRoute->update(['status' => 'active']);
        }

        foreach (self::MOCK_FARES as $seatType => [$base, $perKm]) {
            FareRule::firstOrCreate(
                ['fleet_id' => $fleet->fleet_id, 'seat_type' => $seatType],
                ['base_fare' => $base, 'fare_per_km' => $perKm, 'status' => 'active']
            );
        }

        $legTimes = $this->legTimes($route);
        $days = self::DAYS_AHEAD;
        $today = Carbon::now(config('app.timezone', 'Asia/Manila'))->startOfDay();
        $created = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i)->toDateString();
            if (Trip::where('fleet_route_id', $fleetRoute->fleet_route_id)->whereDate('trip_date', $date)->exists()) {
                continue;
            }
            if ($this->createBlockFor($fleetRoute, (int) $fleet->company_user_id, $date, $legTimes)) {
                $created++;
            } else {
                $this->say("No driver/conductor pair is free on $date; skipped.", true);
            }
        }

        $this->say("Davao - Tagum: fleet {$fleet->plate_number} on the route, fare rules set, $created new shift block(s).");
    }

    /** The fleet already on the route, else the first active fleet that has no route yet. */
    private function fleetFor(Route $route): ?Fleet
    {
        $assigned = FleetRoute::where('route_id', $route->route_id)->value('fleet_id');
        if ($assigned) {
            return Fleet::find($assigned);
        }

        $busy = FleetRoute::pluck('fleet_id');

        return Fleet::where('status', 'active')->whereNotIn('fleet_id', $busy)->orderBy('fleet_id')->first();
    }

    /**
     * Four departure times: each leg leaves one end-to-end duration plus the
     * turnaround buffer after the previous one, rounded up to 5 minutes.
     *
     * @return array<int, string>
     */
    private function legTimes(Route $route): array
    {
        $duration = (float) (RouteStop::where('route_id', $route->route_id)->max('scheduled_offset_min') ?? 0);
        $step = $duration > 0 ? $duration : 120.0;
        $step = (int) (ceil(($step + (int) config('shift_blocks.terminal_turnaround_buffer_minutes')) / 5) * 5);

        $start = Carbon::createFromFormat('H:i', self::FIRST_LEG);
        $times = [];
        for ($leg = 0; $leg < (int) config('shift_blocks.legs_per_block'); $leg++) {
            $times[] = $start->copy()->addMinutes($leg * $step)->format('H:i');
        }

        return $times;
    }

    /** Tries each driver/conductor pair until the block rules accept one. */
    private function createBlockFor(FleetRoute $fleetRoute, int $operatorId, string $date, array $legTimes): bool
    {
        $drivers = $this->staffWithRole('driver');
        $conductors = $this->staffWithRole('conductor');
        $service = app(ShiftBlockService::class);

        foreach ($drivers as $driver) {
            foreach ($conductors as $conductor) {
                try {
                    $service->createBlock([
                        'fleet_route_id' => $fleetRoute->fleet_route_id,
                        'driver_id' => $driver->company_user_id,
                        'conductor_id' => $conductor->company_user_id,
                        'company_user_id' => $operatorId,
                        'block_type' => config('shift_blocks.block_types')[0],
                        'scheduled_date' => $date,
                        'leg_departure_times' => $legTimes,
                    ]);

                    return true;
                } catch (ValidationException) {
                    continue;
                }
            }
        }

        return false;
    }

    /** @return \Illuminate\Support\Collection<int, StaffUser> */
    private function staffWithRole(string $role)
    {
        return StaffUser::whereHas('user', fn ($q) => $q->where('role', $role))->orderBy('company_user_id')->get();
    }

    private function say(string $message, bool $warn = false): void
    {
        if ($this->command) {
            $warn ? $this->command->warn($message) : $this->command->info($message);
        }
    }
}
