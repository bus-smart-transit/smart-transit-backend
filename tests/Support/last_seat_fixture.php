<?php

// Shared by LastSeatRaceTest and LastSeatSequentialTest. Pest helper names are global, so they are defined once here.

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Str;

if (! function_exists('lsPassenger')) {
    function lsPassenger(string $name): PassengerUser
    {
        $user = User::create(['username' => 'ls'.Str::random(8), 'email' => 'ls'.Str::random(8).'@example.test', 'password' => bcrypt('password123'), 'role' => 'passenger']);

        return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => $name, 'phone_num' => '09170000003', 'address' => 'Test', 'birthdate' => '2000-01-01']);
    }
}

if (! function_exists('lsOneSeatTrip')) {
    /**
     * A bus with ONE seat and no standing places, on a trip tomorrow, and the checkout payload for that seat.
     *
     * @return array{0: Trip, 1: array<string, mixed>}
     */
    function lsOneSeatTrip(): array
    {
        $user = User::create(['username' => 'ls_op_'.Str::random(8), 'email' => 'ls_op_'.Str::random(8).'@example.test', 'password' => bcrypt('password123'), 'role' => 'operator']);
        StaffUser::create(['company_user_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Race Operator', 'phone_num' => '09170000004', 'address' => 'Test']);
        $operator = $user->fresh();

        $route = Route::create(['route_name' => 'Race Route '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
        $origin = Stop::create(['stop_name' => 'Race A '.Str::random(4), 'latitude' => 7.0, 'longitude' => 125.0]);
        $destination = Stop::create(['stop_name' => 'Race B '.Str::random(4), 'latitude' => 7.1, 'longitude' => 125.0]);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $origin->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'terminal']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $destination->stop_id, 'stop_order' => 2, 'distance_from_origin_km' => 10, 'stop_type' => 'terminal']);
        $fleet = Fleet::create([
            'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'RACE-'.Str::upper(Str::random(5)),
            'capacity' => 1, 'seated_capacity' => 1, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
        ]);
        $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
        $trip = Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id,
            'trip_date' => now('Asia/Manila')->addDay()->toDateString(), 'departure_time' => '08:00', 'trip_type' => 'one_way', 'status' => 'scheduled',
            'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
        ]);
        FareRule::firstOrCreate(
            ['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active'],
            ['base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5],
        );

        $payload = [
            'booking_date' => $trip->trip_date->toDateString(), 'payment_channel' => 'gcash',
            'items' => [['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $origin->stop_id, 'destination_stop_id' => $destination->stop_id]],
        ];

        return [$trip, $payload];
    }
}
