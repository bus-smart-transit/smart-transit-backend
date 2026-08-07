<?php

namespace Database\Seeders;

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sample dataset for the Wateringen (Netherlands) area.
 *
 * Creates:
 *   - 1 operator account  (operator@smarttransit.nl / password123)
 *   - 1 driver account    (driver@smarttransit.nl / password123)
 *   - 1 conductor account (conductor@smarttransit.nl / password123)
 *   - 1 passenger account (passenger@smarttransit.nl / password123)
 *   - 2 routes with real stops (coordinates from the Den Haag – Wateringen corridor)
 *   - 2 fleets with fare rules
 *   - Fleet ↔ route assignments
 *   - 1 scheduled trip (today) per fleet so the dashboard shows data immediately
 *
 * Run with:
 *   docker compose exec -T backend php artisan db:seed --class=WateringenSeeder
 */
class WateringenSeeder extends Seeder
{
    public function run(): void
    {
        // ── Accounts ──────────────────────────────────────────────────────────

        $operatorUser = User::updateOrCreate(
            ['email' => 'operator@smarttransit.nl'],
            [
                'username'  => 'operator_nl',
                'password'  => 'password123',
                'role'      => 'operator',
            ]
        );

        $operator = StaffUser::firstOrCreate(
            ['user_id' => $operatorUser->user_id],
            [
                'company_user_uuid' => (string) Str::uuid(),
                'name'    => 'SmartTransit NL Operator',
                'phone_num' => '+31 6 12345678',
                'address' => 'Wateringen, South Holland',
            ]
        );

        $driverUser = User::updateOrCreate(
            ['email' => 'driver@smarttransit.nl'],
            [
                'username' => 'driver_nl',
                'password' => 'password123',
                'role'     => 'driver',
            ]
        );

        $driver = StaffUser::firstOrCreate(
            ['user_id' => $driverUser->user_id],
            [
                'company_user_uuid' => (string) Str::uuid(),
                'name'    => 'Jan de Vries',
                'phone_num' => '+31 6 23456789',
                'address' => 'Den Haag, South Holland',
            ]
        );

        $conductorUser = User::updateOrCreate(
            ['email' => 'conductor@smarttransit.nl'],
            [
                'username' => 'conductor_nl',
                'password' => 'password123',
                'role'     => 'conductor',
            ]
        );

        $conductor = StaffUser::firstOrCreate(
            ['user_id' => $conductorUser->user_id],
            [
                'company_user_uuid' => (string) Str::uuid(),
                'name'    => 'Fatima El-Amine',
                'phone_num' => '+31 6 34567890',
                'address' => 'Naaldwijk, South Holland',
            ]
        );

        $passengerUser = User::updateOrCreate(
            ['email' => 'passenger@smarttransit.nl'],
            [
                'username' => 'passenger_nl',
                'password' => 'password123',
                'role'     => 'passenger',
            ]
        );

        PassengerUser::firstOrCreate(
            ['user_id' => $passengerUser->user_id],
            [
                'passenger_uuid' => (string) Str::uuid(),
                'name'      => 'Sophie van der Berg',
                'phone_num' => '+31 6 45678901',
                'address'   => 'Wateringen, South Holland',
                'birth_date' => '1995-04-12',
            ]
        );

        // ── Stops — real locations along the Den Haag / Wateringen corridor ──
        // Coordinates verified against OpenStreetMap (WGS-84).

        $stopData = [
            // Route 1: Wateringen → Den Haag Centraal
            ['Wateringen Dorpsstraat',        52.0248,  4.2796],
            ['Wateringen Hoogeland',          52.0296,  4.2841],
            ['Honselersdijk De Lier Kruising',52.0391,  4.2903],
            ['Naaldwijk Centrum',             52.0021,  4.2156],
            ['Monster Station',               52.0262,  4.1594],
            ['s-Gravenzande Centrum',         51.9987,  4.1683],
            ['Kwintsheul Brug',               52.0175,  4.2378],
            ['Den Haag Ypenburg P+R',         52.0541,  4.3718],
            ['Den Haag Laan van NOI',         52.0663,  4.3361],
            ['Den Haag Centraal',             52.0801,  4.3249],

            // Route 2: Wateringen → Delft Centrum
            ['Wateringen Stationsweg',        52.0251,  4.2812],
            ['Wateringen Pijletuinen',        52.0314,  4.2869],
            ['Rijswijk De Stede',             52.0458,  4.3205],
            ['Rijswijk Centrum',              52.0396,  4.3271],
            ['Delft TU Wijk',                 51.9987,  4.3731],
            ['Delft Reinier de Graaf Hospital',51.9982, 4.3656],
            ['Delft Station',                 52.0063,  4.3565],
            ['Delft Centrum Markt',           52.0116,  4.3573],
        ];

        $stops = [];
        foreach ($stopData as [$name, $lat, $lng]) {
            $stops[$name] = Stop::firstOrCreate(
                ['stop_name' => $name],
                ['latitude' => $lat, 'longitude' => $lng]
            );
        }

        // ── Route 1: Wateringen → Den Haag Centraal ───────────────────────────

        $route1 = Route::firstOrCreate(
            ['route_name' => 'Route 361 – Wateringen → Den Haag Centraal'],
            [
                'origin'      => 'Wateringen Dorpsstraat',
                'destination' => 'Den Haag Centraal',
            ]
        );

        // Stops in order with cumulative road-distance estimates (km)
        $route1Stops = [
            ['Wateringen Dorpsstraat',         0,   0.00],
            ['Wateringen Hoogeland',           1,   0.62],
            ['Kwintsheul Brug',                2,   2.40],
            ['Den Haag Ypenburg P+R',          3,   7.80],
            ['Den Haag Laan van NOI',          4,  11.20],
            ['Den Haag Centraal',              5,  13.50],
        ];

        foreach ($route1Stops as [$name, $order, $dist]) {
            RouteStop::firstOrCreate(
                ['route_id' => $route1->route_id, 'stop_id' => $stops[$name]->stop_id],
                ['stop_order' => $order, 'distance_from_origin_km' => $dist]
            );
        }

        // ── Route 2: Wateringen → Naaldwijk – Monster Coastal ─────────────────

        $route2 = Route::firstOrCreate(
            ['route_name' => 'Route 320 – Wateringen → Naaldwijk – Monster'],
            [
                'origin'      => 'Wateringen Dorpsstraat',
                'destination' => 'Monster Station',
            ]
        );

        $route2Stops = [
            ['Wateringen Dorpsstraat',          0,   0.00],
            ['Honselersdijk De Lier Kruising',  1,   2.10],
            ['Naaldwijk Centrum',               2,   5.30],
            ['s-Gravenzande Centrum',           3,   9.80],
            ['Monster Station',                 4,  12.70],
        ];

        foreach ($route2Stops as [$name, $order, $dist]) {
            RouteStop::firstOrCreate(
                ['route_id' => $route2->route_id, 'stop_id' => $stops[$name]->stop_id],
                ['stop_order' => $order, 'distance_from_origin_km' => $dist]
            );
        }

        // ── Route 3: Wateringen → Delft Centrum ───────────────────────────────

        $route3 = Route::firstOrCreate(
            ['route_name' => 'Route 385 – Wateringen → Delft Centrum'],
            [
                'origin'      => 'Wateringen Stationsweg',
                'destination' => 'Delft Centrum Markt',
            ]
        );

        $route3Stops = [
            ['Wateringen Stationsweg',              0,   0.00],
            ['Wateringen Pijletuinen',               1,   0.80],
            ['Rijswijk De Stede',                    2,   5.30],
            ['Rijswijk Centrum',                     3,   6.10],
            ['Delft TU Wijk',                        4,  12.50],
            ['Delft Reinier de Graaf Hospital',      5,  13.20],
            ['Delft Station',                        6,  14.40],
            ['Delft Centrum Markt',                  7,  15.10],
        ];

        foreach ($route3Stops as [$name, $order, $dist]) {
            RouteStop::firstOrCreate(
                ['route_id' => $route3->route_id, 'stop_id' => $stops[$name]->stop_id],
                ['stop_order' => $order, 'distance_from_origin_km' => $dist]
            );
        }

        // ── Fleets ────────────────────────────────────────────────────────────

        $fleet1 = Fleet::firstOrCreate(
            ['plate_number' => 'NL-361-AAA'],
            [
                'company_user_id'  => $operator->company_user_id,
                'capacity'         => 60,
                'seated_capacity'  => 40,
                'standing_capacity'=> 20,
                'status'           => 'active',
                'fleet_type'       => 'public',
            ]
        );

        $fleet2 = Fleet::firstOrCreate(
            ['plate_number' => 'NL-320-BBB'],
            [
                'company_user_id'  => $operator->company_user_id,
                'capacity'         => 50,
                'seated_capacity'  => 35,
                'standing_capacity'=> 15,
                'status'           => 'active',
                'fleet_type'       => 'public',
            ]
        );

        $fleet3 = Fleet::firstOrCreate(
            ['plate_number' => 'NL-385-CCC'],
            [
                'company_user_id'  => $operator->company_user_id,
                'capacity'         => 45,
                'seated_capacity'  => 30,
                'standing_capacity'=> 15,
                'status'           => 'active',
                'fleet_type'       => 'public',
            ]
        );

        // ── Fare Rules ─────────────────────────────────────────────────────────
        // Dutch OV pricing approximation: €1.10 base + €0.19 / km (seated)
        //                                  €0.90 base + €0.15 / km (standing)

        foreach ([[$fleet1, 1], [$fleet2, 2], [$fleet3, 3]] as [$fleet, $n]) {
            FareRule::firstOrCreate(
                ['fleet_id' => $fleet->fleet_id, 'seat_type' => 'seated'],
                ['base_fare' => 1.10, 'fare_per_km' => 0.19, 'status' => 'active']
            );
            FareRule::firstOrCreate(
                ['fleet_id' => $fleet->fleet_id, 'seat_type' => 'standing'],
                ['base_fare' => 0.90, 'fare_per_km' => 0.15, 'status' => 'active']
            );
            unset($n);
        }

        // ── Fleet ↔ Route assignments ─────────────────────────────────────────

        $fr1 = FleetRoute::firstOrCreate(
            ['fleet_id' => $fleet1->fleet_id, 'route_id' => $route1->route_id],
            ['start_time' => '07:30:00', 'end_time' => '20:00:00', 'status' => 'active']
        );

        $fr2 = FleetRoute::firstOrCreate(
            ['fleet_id' => $fleet2->fleet_id, 'route_id' => $route2->route_id],
            ['start_time' => '08:00:00', 'end_time' => '19:00:00', 'status' => 'active']
        );

        $fr3 = FleetRoute::firstOrCreate(
            ['fleet_id' => $fleet3->fleet_id, 'route_id' => $route3->route_id],
            ['start_time' => '09:00:00', 'end_time' => '21:00:00', 'status' => 'active']
        );

        // ── Scheduled trips (today, boarding status so they appear on dashboards)

        $today = now()->toDateString();

        Trip::firstOrCreate(
            ['fleet_route_id' => $fr1->fleet_route_id, 'trip_date' => $today . ' 08:00:00'],
            [
                'company_user_id'          => $operator->company_user_id,
                'driver_id'                => $driver->company_user_id,
                'conductor_id'             => $conductor->company_user_id,
                'status'                   => 'boarding',
                'current_seated_capacity'  => $fleet1->seated_capacity,
                'current_standing_capacity'=> $fleet1->standing_capacity,
                'total_occupancy'          => 0,
            ]
        );

        Trip::firstOrCreate(
            ['fleet_route_id' => $fr2->fleet_route_id, 'trip_date' => $today . ' 09:00:00'],
            [
                'company_user_id'          => $operator->company_user_id,
                'driver_id'                => $driver->company_user_id,
                'conductor_id'             => $conductor->company_user_id,
                'status'                   => 'scheduled',
                'current_seated_capacity'  => $fleet2->seated_capacity,
                'current_standing_capacity'=> $fleet2->standing_capacity,
                'total_occupancy'          => 0,
            ]
        );

        Trip::firstOrCreate(
            ['fleet_route_id' => $fr3->fleet_route_id, 'trip_date' => $today . ' 10:30:00'],
            [
                'company_user_id'          => $operator->company_user_id,
                'driver_id'                => $driver->company_user_id,
                'conductor_id'             => $conductor->company_user_id,
                'status'                   => 'scheduled',
                'current_seated_capacity'  => $fleet3->seated_capacity,
                'current_standing_capacity'=> $fleet3->standing_capacity,
                'total_occupancy'          => 0,
            ]
        );

        $this->command->info('✓ Wateringen sample data seeded.');

        // ── Daily PINs — created so pairing works on first use without requiring
        //   staff to visit the /pin page first. Uses a simple 6-digit code so
        //   testers can pair immediately.
        foreach (Trip::where('trip_date', '>=', Carbon::today()->toDateString())->get() as $trip) {
            if (!$trip->driver_id || !$trip->conductor_id) continue;
            FleetDailyPin::firstOrCreate(
                ['trip_id' => $trip->trip_id, 'pin_date' => Carbon::today()],
                [
                    'driver_id'    => $trip->driver_id,
                    'conductor_id' => $trip->conductor_id,
                    'pin_code'     => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                ]
            );
        }
        $this->command->table(
            ['Account', 'Email', 'Password'],
            [
                ['Operator',   'operator@smarttransit.nl',  'password123'],
                ['Driver',     'driver@smarttransit.nl',    'password123'],
                ['Conductor',  'conductor@smarttransit.nl', 'password123'],
                ['Passenger',  'passenger@smarttransit.nl', 'password123'],
            ]
        );
        $this->command->table(
            ['Route', 'Stops', 'Total km'],
            [
                ['Route 361 – Wateringen → Den Haag Centraal',  '6', '13.5 km'],
                ['Route 320 – Wateringen → Naaldwijk – Monster','5', '12.7 km'],
                ['Route 385 – Wateringen → Delft Centrum',      '8', '15.1 km'],
            ]
        );
    }
}
