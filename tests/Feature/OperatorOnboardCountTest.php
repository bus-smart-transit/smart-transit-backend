<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// §4.7: when the Operator tracks a bus, the trip says how many passengers are on board now (tickets whose status is
// `boarded`, the Chauffeur manifest's own definition), next to the tickets sold, which stays as it was.

function obStaff(string $role): User
{
    $user = User::create(['username' => 'ob'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Ob '.ucfirst($role), 'phone_num' => '09170000070', 'address' => 'x']);

    return $user->fresh();
}

it('counts the passengers on board, not the tickets sold, for each of the Operator\'s trips', function () {
    $operator = obStaff('operator');
    $route = Route::create(['route_name' => 'Onboard Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'OB-'.fake()->unique()->numerify('####'), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = fn (string $time) => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => now()->addDay()->toDateString(),
        'departure_time' => $time, 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled',
        'current_seated_capacity' => 4, 'current_standing_capacity' => 0, 'total_occupancy' => 4,
    ]);
    $running = $trip('08:00');
    $empty = $trip('12:00');
    $payment = Payment::create(['amount' => 0, 'payment_created' => now(), 'transaction_reference' => 'TXN-OB-'.Str::upper(Str::random(8)), 'payment_method' => 'cash', 'payment_channel' => 'onsite', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    foreach (['boarded', 'boarded', 'valid', 'alighted'] as $status) {
        Ticket::create(['payment_id' => $payment->payment_id, 'fleet_route_id' => $fleetRoute->fleet_route_id, 'trip_id' => $running->trip_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => $status, 'amount' => 20, 'seat_type' => 'seated']);
    }

    $trips = collect($this->actingAs($operator)->getJson('/api/operator/trips?status_filter=all')->assertSuccessful()->json('data'))->keyBy('trip_id');

    expect($trips[$running->trip_id]['onboard_count'])->toBe(2)               // the two scanned on, not the waiting or alighted ones
        ->and($trips[$running->trip_id]['current_seated_capacity'])->toBe(4)  // tickets sold is unchanged
        ->and($trips[$empty->trip_id]['onboard_count'])->toBe(0);
});
