<?php

namespace Database\Seeders;

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEV / DEMO DATA ONLY. A clean start after a full database reset: the demo accounts, three routes in the Davao
 * Region that are outside Davao City, one bus per route, and the general fare rules. Every row is demo data: the
 * accounts use the shared dev password, the bus company is "SAMPLE Transit Co.", and the per-km rates are the SAMPLE
 * values of SampleFareKeySeeder (not real fares). Only the base fares (13 and 14 pesos for the first 5 km) follow
 * today's minimum fares.
 *
 * Nothing is guessed about distance: the stop coordinates are approximate town-centre points, and the kilometres
 * between stops, the stop times and the route line all come from the routing engine (OpenRouteService), as in
 * RouteStopScheduleSeeder. A route the engine cannot route is reported and left without distances, never filled in.
 *
 * Idempotent: existing rows are reused. No trips or shift blocks are created; an Operator schedules them.
 *
 * Run (after php artisan migrate:fresh --seed): php artisan db:seed --class=DavaoRegionDemoSeeder
 */
class DavaoRegionDemoSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    /** [username, email, role, name, phone]. The passenger account comes from DefaultAccountsSeeder. */
    private const STAFF = [
        ['admin', 'admin@smarttransit.com', 'admin', 'Demo Admin', '09000000001'],
        ['operator', 'operator@smarttransit.com', 'operator', 'Demo Operator', '09000000002'],
        ['driver', 'driver@smarttransit.com', 'driver', 'Demo Driver One', '09000000003'],
        ['driver2', 'driver2@smarttransit.com', 'driver', 'Demo Driver Two', '09000000004'],
        ['conductor', 'conductor@smarttransit.com', 'conductor', 'Demo Chauffeur One', '09000000005'],
        ['conductor2', 'conductor2@smarttransit.com', 'conductor', 'Demo Chauffeur Two', '09000000006'],
    ];

    /**
     * name => [origin, destination, stops[[name, lat, lng, type, municipality]], bus[plate, body no, class, capacity, seated, standing]]
     * All municipalities are in the Davao Region and none is Davao City.
     */
    private const ROUTES = [
        'Tagum - Panabo' => [
            'Tagum', 'Panabo',
            [
                ['Tagum City Terminal', 7.4478, 125.8078, 'terminal', 'Tagum City'],
                ['Carmen Public Market', 7.3600, 125.7080, 'stop', 'Carmen'],
                ['Panabo City Public Market', 7.3080, 125.6840, 'terminal', 'Panabo City'],
            ],
            ['DEMO-101', 'SAMPLE-101', 'ordinary', 50, 40, 10],
        ],
        'Bansalan - Sta. Cruz' => [
            'Bansalan', 'Sta. Cruz',
            [
                ['Bansalan Public Market', 6.7833, 125.2167, 'terminal', 'Bansalan'],
                ['Digos City Public Market', 6.7496, 125.3572, 'stop', 'Digos City'],
                ['Sta. Cruz Public Market', 6.8333, 125.4167, 'terminal', 'Sta. Cruz'],
            ],
            ['DEMO-102', 'SAMPLE-102', 'aircon', 40, 30, 10],
        ],
        'Tagum - Nabunturan' => [
            'Tagum', 'Nabunturan',
            [
                ['Tagum City Terminal', 7.4478, 125.8078, 'terminal', 'Tagum City'],
                ['Mawab Public Market', 7.498701, 125.968082, 'stop', 'Mawab'], // the road point nearest the town centre (the centre was 210 m off it)
                ['Nabunturan Public Market', 7.6000, 125.9667, 'terminal', 'Nabunturan'],
            ],
            ['DEMO-103', 'SAMPLE-103', 'ordinary', 45, 35, 10],
        ],
    ];

    /** class => base fare for the first 5 km, and the SAMPLE per-km rate after it. */
    private const FARES = [
        'ordinary' => [13.00, 1.90],
        'aircon' => [14.00, 2.25],
    ];

    private const BASE_DISTANCE_KM = 5;

    public function run(): void
    {
        DB::transaction(function () {
            $staff = $this->accounts();
            $operator = $staff['operator'];
            foreach (self::ROUTES as $name => [$origin, $destination, $stops, $bus]) {
                $route = Route::firstOrCreate(['route_name' => $name], ['origin' => $origin, 'destination' => $destination]);
                $this->stops($route, $stops);
                $this->bus($route, $operator, $bus);
            }
            $this->fares();
        });

        // Kilometres, stop times and the route line come from the routing engine, never from typed-in numbers.
        $this->call(RouteStopScheduleSeeder::class);
        $this->kilometresFromRouting();
        if ($this->command) {
            $this->command->call('routes:generate-geometry');
        }
    }

    /** @return array<string, StaffUser> */
    private function accounts(): array
    {
        $made = [];
        foreach (self::STAFF as [$username, $email, $role, $name, $phone]) {
            $user = User::updateOrCreate(['email' => $email], ['username' => $username, 'password' => self::PASSWORD, 'role' => $role]);
            $made[$username] = StaffUser::firstOrCreate(
                ['user_id' => $user->user_id],
                ['phone_num' => $phone, 'name' => $name, 'address' => 'Demo data', 'birth_date' => now()->subYears(30)]
            );
        }

        return $made;
    }

    private function stops(Route $route, array $stops): void
    {
        RouteStop::where('route_id', $route->route_id)->delete();
        foreach ($stops as $i => [$name, $lat, $lng, $type, $municipality]) {
            $stop = Stop::firstOrCreate(['stop_name' => $name], ['latitude' => $lat, 'longitude' => $lng]);
            $stop->update(['municipality' => $municipality]);
            RouteStop::create([
                'route_id' => $route->route_id,
                'stop_id' => $stop->stop_id,
                'stop_order' => $i + 1,
                'distance_from_origin_km' => 0, // replaced from the routing engine below
                'stop_type' => $type,
            ]);
        }
    }

    private function bus(Route $route, StaffUser $operator, array $bus): void
    {
        [$plate, $body, $class, $capacity, $seated, $standing] = $bus;
        $fleet = Fleet::firstOrCreate(['plate_number' => $plate], [
            'company_user_id' => $operator->company_user_id,
            'bus_company_name' => 'SAMPLE Transit Co.',
            'body_no' => $body,
            'capacity' => $capacity,
            'seated_capacity' => $seated,
            'standing_capacity' => $standing,
            'status' => 'active',
            'fleet_type' => 'public',
            'vehicle_class' => $class,
        ]);
        FleetRoute::firstOrCreate(
            ['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id],
            ['start_time' => '05:00', 'end_time' => '21:00', 'status' => 'active']
        );
    }

    private function fares(): void
    {
        foreach (self::FARES as $class => [$base, $perKm]) {
            foreach (['seated', 'standing'] as $seat) {
                FareRule::firstOrCreate(
                    ['fleet_id' => null, 'route_id' => null, 'vehicle_class' => $class, 'service_type' => 'regular', 'seat_type' => $seat, 'status' => 'active'],
                    ['base_fare' => $base, 'fare_per_km' => $perKm, 'base_distance_km' => self::BASE_DISTANCE_KM, 'rounding_mode' => 'nearest', 'note' => 'SAMPLE - demo data, per-km rate is not a real fare'],
                );
            }
        }
    }

    /** The fare distance of each stop is the routing engine's driving distance, to 0.1 km. */
    private function kilometresFromRouting(): void
    {
        foreach (Route::whereIn('route_name', array_keys(self::ROUTES))->get() as $route) {
            $rows = RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->get();
            if ($rows->contains(fn ($row) => $row->distance_from_origin_m === null)) {
                $this->say("{$route->route_name}: the routing engine gave no distances; the fare distances stay 0 and must be fixed.", true);

                continue;
            }
            foreach ($rows as $row) {
                $row->update(['distance_from_origin_km' => round($row->distance_from_origin_m / 1000, 1)]);
            }
            $this->say("{$route->route_name}: ".$rows->last()->distance_from_origin_km.' km end to end.');
        }
    }

    private function say(string $message, bool $warn = false): void
    {
        if ($this->command) {
            $warn ? $this->command->warn($message) : $this->command->info($message);
        }
    }
}
