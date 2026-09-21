<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\StaffUser;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Regression tests for the auto-delay → passenger notification flow.
// TripRepository::markOverdueScheduledAsDelayed() flips trips to 'delayed'
// from 10+ different read-time call sites with no single authoritative
// caller — so notifications are driven separately by
// TripService::notifyDelayedTrips() (invoked by the trips:notify-delayed
// scheduled command), gated by the delay_notified_at idempotency column.

function makeOperatorForDelayNotificationTest(): StaffUser
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'role' => 'operator',
    ]);

    return StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ])->fresh();
}

function makePassengerForDelayNotificationTest(): PassengerUser
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'role' => 'passenger',
        'fcm_token' => 'test-fcm-token',
    ]);

    return PassengerUser::create([
        'user_id' => $user->user_id,
        'passenger_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
        'birthdate' => '2000-01-01',
    ])->fresh();
}

function makeDelayedTripWithTicket(int $operatorCompanyUserId, PassengerUser $passenger): Trip
{
    $route = Route::create([
        'route_name' => 'Delay Test Route',
        'origin' => 'Stop A',
        'destination' => 'Stop B',
    ]);
    $route->refresh();

    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'DLY-' . fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);
    $fleet->refresh();

    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
    $fleetRoute->refresh();

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operatorCompanyUserId,
        'trip_date' => now()->toDateString(),
        'status' => 'delayed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $payment = Payment::create([
        'amount' => 50,
        'payment_created' => now(),
        'transaction_reference' => 'txn-delay-' . uniqid(),
        'payment_method' => 'online',
        'payment_channel' => 'gcash',
        'status' => 'paid',
        'payment_uuid' => (string) Str::uuid(),
        'items_payload' => [],
    ]);

    Ticket::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_id' => $trip->trip_id,
        'passenger_id' => $passenger->passenger_id,
        'payment_id' => $payment->payment_id,
        'seat_type' => 'seated',
        'status' => 'valid',
        'ticket_uuid' => (string) Str::uuid(),
        'amount' => 50,
    ]);

    return $trip->fresh();
}

test('notifyDelayedTrips notifies passengers of a delayed trip and marks it claimed', function () {
    $operator = makeOperatorForDelayNotificationTest();
    $passenger = makePassengerForDelayNotificationTest();
    $trip = makeDelayedTripWithTicket($operator->company_user_id, $passenger);

    $this->mock(FcmService::class, function ($mock) use ($passenger) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $passenger->user_id && $title === 'Trip Delayed')
            ->andReturn(true);
    });

    $notified = app(TripService::class)->notifyDelayedTrips();

    expect($notified)->toBe(1);
    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id]);
    expect(Trip::find($trip->trip_id)->delay_notified_at)->not->toBeNull();
});

test('notifyDelayedTrips does not double-notify a trip on a second run', function () {
    $operator = makeOperatorForDelayNotificationTest();
    $passenger = makePassengerForDelayNotificationTest();
    makeDelayedTripWithTicket($operator->company_user_id, $passenger);

    $this->mock(FcmService::class, function ($mock) use ($passenger) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $passenger->user_id && $title === 'Trip Delayed')
            ->andReturn(true);
    });

    $tripService = app(TripService::class);

    $first = $tripService->notifyDelayedTrips();
    $second = $tripService->notifyDelayedTrips();

    expect($first)->toBe(1);
    expect($second)->toBe(0);
});

test('notifyDelayedTrips ignores trips that are not delayed', function () {
    $operator = makeOperatorForDelayNotificationTest();
    $passenger = makePassengerForDelayNotificationTest();
    $trip = makeDelayedTripWithTicket($operator->company_user_id, $passenger);
    $trip->update(['status' => 'scheduled']);

    $this->mock(FcmService::class, function ($mock) {
        $mock->shouldNotReceive('sendToUser');
    });

    $notified = app(TripService::class)->notifyDelayedTrips();

    expect($notified)->toBe(0);
});
