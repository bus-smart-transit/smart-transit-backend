<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Trip;
use App\Models\FleetRoute;
use App\Models\StaffUser;
$operator = StaffUser::first();
$fleetRoute = FleetRoute::first();
$now = now();
$pastTrip = Trip::create([
    'fleet_route_id' => $fleetRoute->fleet_route_id,
    'company_user_id' => $operator->company_user_id,
    'trip_date' => $now->toDateString(),
    'departure_time' => $now->copy()->subMinutes(5)->format('H:i:s'),
    'status' => 'scheduled',
    'current_seated_capacity' => 0,
    'current_standing_capacity' => 0,
    'total_occupancy' => 0,
]);
$futureTrip = Trip::create([
    'fleet_route_id' => $fleetRoute->fleet_route_id,
    'company_user_id' => $operator->company_user_id,
    'trip_date' => $now->toDateString(),
    'departure_time' => $now->copy()->addMinutes(30)->format('H:i:s'),
    'status' => 'scheduled',
    'current_seated_capacity' => 0,
    'current_standing_capacity' => 0,
    'total_occupancy' => 0,
]);
$timezone = config('app.timezone', 'Asia/Manila');
foreach (Trip::where('company_user_id', $operator->company_user_id)->get() as $trip) {
    $dateString = $trip->trip_date instanceof Illuminate\Support\Carbon
        ? $trip->trip_date->format('Y-m-d')
        : Illuminate\Support\Carbon::parse((string) $trip->trip_date)->format('Y-m-d');
    $parsed = Illuminate\Support\Carbon::createFromFormat('!Y-m-d H:i:s', $dateString.' '.$trip->departure_time, $timezone);
    echo "trip={$trip->trip_id} departure={$trip->departure_time} parsed={$parsed->toDateTimeString()} now=" . Illuminate\Support\Carbon::now($timezone)->toDateTimeString() . " compare=" . ($parsed->greaterThanOrEqualTo(Illuminate\Support\Carbon::now($timezone)) ? 'future' : 'past') . PHP_EOL;
}
