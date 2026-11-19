<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;

$user = User::create([
    'username' => 'debugop_'.uniqid(),
    'email' => 'debugop_'.uniqid().'@example.com',
    'password' => bcrypt('password'),
    'role' => 'operator',
]);
$profile = StaffUser::create([
    'user_id' => $user->user_id,
    'company_user_uuid' => (string) Illuminate\Support\Str::uuid(),
    'name' => 'Debug Operator',
    'phone_num' => '09123456789',
    'address' => 'x',
]);
$route = Route::create(['route_name' => 'Test Route', 'origin' => 'Stop A', 'destination' => 'Stop B']);
$fleet = Fleet::create(['company_user_id' => $profile->company_user_id, 'plate_number' => 'ABC-123', 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);
$fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
$past = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $profile->company_user_id, 'trip_date' => now()->toDateString(), 'departure_time' => now()->subMinutes(5)->format('H:i:s'), 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
$future = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $profile->company_user_id, 'trip_date' => now()->toDateString(), 'departure_time' => now()->addMinutes(30)->format('H:i:s'), 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
$repo = app(\App\Repositories\TripRepository::class);
$up = $repo->listUpcomingByOperator($profile->company_user_id, 'upcoming');
$all = $repo->listUpcomingByOperator($profile->company_user_id, 'all');
echo "past={$past->trip_id}\n";
echo "future={$future->trip_id}\n";
echo "upcoming=" . $up->pluck('trip_id')->implode(',') . "\n";
echo "all=" . $all->pluck('trip_id')->implode(',') . "\n";
