<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePassengerUserForCheckoutTest(): User
{
    $user = User::create([
        'username' => 'passenger_'.uniqid(),
        'email' => 'passenger+'.uniqid().'@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'passenger',
    ]);

    PassengerUser::create([
        'passenger_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Passenger Checkout',
        'phone_num' => '09170000011',
        'address' => 'Test Address',
        'birthdate' => '2000-01-01',
    ]);

    return $user;
}

function makeOperatorForCheckoutTest(): User
{
    $user = User::create([
        'username' => 'operator_'.uniqid(),
        'email' => 'operator+'.uniqid().'@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Operator Checkout',
        'phone_num' => '09170000012',
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

function makeTripForCheckoutTest(int $operatorCompanyUserId): Trip
{
    $route = Route::create([
        'route_name' => 'Checkout Validation Route',
        'origin' => 'Stop A',
        'destination' => 'Stop B',
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'CHK-'.fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operatorCompanyUserId,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

test('checkout rejects booking_date that does not match trip scheduled date', function () {
    $passenger = makePassengerUserForCheckoutTest();
    $operator = makeOperatorForCheckoutTest();
    $trip = makeTripForCheckoutTest($operator->companyProfile->company_user_id);

    $bookingDate = now()->addDay()->toDateString();
    $expectedDateLabel = now()->format('m/d/Y');

    $response = $this->actingAs($passenger)->postJson('/api/passengers/checkout', [
        'booking_date' => $bookingDate,
        'payment_channel' => 'gcash',
        'items' => [[
            'trip_id' => $trip->trip_id,
            'seat_type' => 'seated',
            'origin_lat' => 7.1000,
            'origin_lng' => 125.6000,
            'destination_lat' => 7.2000,
            'destination_lng' => 125.7000,
        ]],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.trip_id'])
        ->assertJsonFragment(['This trip is scheduled for '.$expectedDateLabel.'.']);
});

test('online checkout refuses standing for a passenger and a guest', function () {
    $passenger = makePassengerUserForCheckoutTest();
    $operator = makeOperatorForCheckoutTest();
    $trip = makeTripForCheckoutTest($operator->companyProfile->company_user_id);
    $payload = [
        'payment_channel' => 'gcash',
        'guest_name' => 'Guest Standing',
        'guest_email' => 'guest-standing@smarttransit.test',
        'items' => [[
            'trip_id' => $trip->trip_id,
            'seat_type' => 'standing',
            'origin_lat' => 7.1000,
            'origin_lng' => 125.6000,
            'destination_lat' => 7.2000,
            'destination_lng' => 125.7000,
        ]],
    ];
    $message = 'Online tickets are for seats only. Standing places are sold on the bus.';

    $this->actingAs($passenger)->postJson('/api/passengers/checkout', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.seat_type'])
        ->assertJsonFragment([$message]);

    app('auth')->forgetGuards();
    $this->postJson('/api/checkout', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.seat_type'])
        ->assertJsonFragment([$message]);

    expect($trip->fresh()->current_standing_capacity)->toBe(0);
});

test('passenger trip search accepts only the seated seat type', function () {
    $this->getJson('/api/booking/trips?seat_type=standing')->assertStatus(422)->assertJsonValidationErrors(['seat_type']);
    $this->getJson('/api/booking/trips?seat_type=seated')->assertSuccessful();
});

// Batch 50 item 7 (user, 2026-11-08): a guest's ticket and tracking code are sent by email, so a guest must give one;
// a signed-in passenger's account email is used and none is asked for.
test('a guest checkout needs an email, a signed-in passenger does not', function () {
    $passenger = makePassengerUserForCheckoutTest();
    $operator = makeOperatorForCheckoutTest();
    $trip = makeTripForCheckoutTest($operator->companyProfile->company_user_id);
    $payload = ['payment_channel' => 'gcash', 'items' => [['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_lat' => 7.1, 'origin_lng' => 125.6, 'destination_lat' => 7.2, 'destination_lng' => 125.7]]];

    $this->postJson('/api/checkout', $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.guest_email.0', 'Enter your email. Your ticket and tracking code are sent there.');
    $this->postJson('/api/checkout', $payload + ['guest_email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['guest_email']);

    $response = $this->actingAs($passenger)->postJson('/api/passengers/checkout', $payload);
    expect($response->json('errors.guest_email'))->toBeNull();
});

// Batch 51 item 7 (user, 2026-11-09): booking for someone else needs their name and a valid email or mobile number.
test('asks for both the name and a valid contact when booking for someone else', function () {
    $passenger = makePassengerUserForCheckoutTest();
    $operator = makeOperatorForCheckoutTest();
    $trip = makeTripForCheckoutTest($operator->companyProfile->company_user_id);
    $payload = ['payment_channel' => 'gcash', 'items' => [['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_lat' => 7.1, 'origin_lng' => 125.6, 'destination_lat' => 7.2, 'destination_lng' => 125.7]]];

    $this->actingAs($passenger)->postJson('/api/passengers/checkout', $payload + ['traveller_name' => 'Lola Ana'])
        ->assertStatus(422)->assertJsonPath('errors.traveller_contact.0', 'Enter their email or mobile number.');
    $this->actingAs($passenger)->postJson('/api/passengers/checkout', $payload + ['traveller_name' => 'Lola Ana', 'traveller_contact' => '12345'])
        ->assertStatus(422)->assertJsonPath('errors.traveller_contact.0', 'Enter their email, or a mobile number like 09171234567.');
    $this->actingAs($passenger)->postJson('/api/passengers/checkout', $payload + ['traveller_contact' => '09171234567'])
        ->assertStatus(422)->assertJsonPath('errors.traveller_name.0', 'Enter the name of the person you are booking for.');
});
