<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Services\PaymentService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Notifications audit follow-up: passenger-side FCM registration was added
// (AuthController::registerFcmToken, POST /passengers/fcm-token) so trip
// cancellation and payment failure can reach the passenger who's actually
// affected, not just staff/operator roles.
function makePassengerForNotificationTest(): PassengerUser
{
    $user = User::create([
        'username' => 'passenger_' . uniqid(),
        'email' => 'passenger+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'passenger',
    ]);

    return PassengerUser::create([
        'passenger_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Notification Test Passenger',
        'phone_num' => '09170000026',
        'address' => 'Test Address',
        'birthdate' => '2000-01-01',
    ]);
}

function makeOperatorForPassengerNotificationTest(): StaffUser
{
    $user = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);

    return StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Operator Notification Test',
        'phone_num' => '09170000027',
        'address' => 'Test Address',
    ]);
}

function makeTripWithTicketForNotificationTest(int $operatorCompanyUserId, PassengerUser $passenger, string $ticketStatus = 'valid'): Trip
{
    $route = Route::create(['route_name' => 'Passenger Notification Route', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'PSGR-' . fake()->unique()->numerify('###'),
        'capacity' => 40,
        'seated_capacity' => 30,
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
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operatorCompanyUserId,
        'trip_date' => now()->toDateString(),
        'departure_time' => '08:30',
        'trip_type' => 'one_way',
        'status' => 'departed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);

    $payment = Payment::create([
        'amount' => 80,
        'payment_created' => now(),
        'transaction_reference' => 'txn-' . uniqid(),
        'payment_method' => 'online',
        'payment_channel' => 'gcash',
        'status' => 'paid',
        'payment_uuid' => (string) Str::uuid(),
    ]);

    Ticket::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'trip_id' => $trip->trip_id,
        'payment_id' => $payment->payment_id,
        'passenger_id' => $passenger->passenger_id,
        'ticket_uuid' => (string) Str::uuid(),
        'status' => $ticketStatus,
        'seat_type' => 'seated',
        'amount' => 80,
    ]);

    return $trip;
}

test('cancelling a trip notifies the affected passenger', function () {
    $operator = makeOperatorForPassengerNotificationTest();
    $passenger = makePassengerForNotificationTest();
    $trip = makeTripWithTicketForNotificationTest($operator->company_user_id, $passenger);

    $this->mock(FcmService::class, function ($mock) use ($passenger) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $passenger->user_id && $title === 'Trip Cancelled')
            ->andReturn(true);
    });

    app(TripService::class)->overrideStatus($trip->trip_id, 'cancelled', 'admin');
});

test('cancelling a trip does not notify a passenger whose ticket already expired', function () {
    $operator = makeOperatorForPassengerNotificationTest();
    $passenger = makePassengerForNotificationTest();
    $trip = makeTripWithTicketForNotificationTest($operator->company_user_id, $passenger, 'expired');

    $this->mock(FcmService::class, function ($mock) {
        $mock->shouldNotReceive('sendToUser');
    });

    app(TripService::class)->overrideStatus($trip->trip_id, 'cancelled', 'admin');
});

test('a failed online payment notifies the passenger who attempted it', function () {
    $passenger = makePassengerForNotificationTest();
    $operator = makeOperatorForPassengerNotificationTest();
    $trip = makeTripWithTicketForNotificationTest($operator->company_user_id, $passenger);

    $payment = Payment::create([
        'amount' => 80,
        'payment_created' => now(),
        'transaction_reference' => 'txn-fail-' . uniqid(),
        'payment_method' => 'online',
        'payment_channel' => 'gcash',
        'status' => 'pending',
        'payment_uuid' => (string) Str::uuid(),
        'items_payload' => [[
            'trip_id' => $trip->trip_id,
            'passenger_id' => $passenger->passenger_id,
            'seat_type' => 'seated',
            'fare_rule_id' => null,
            'distance_km' => 5,
            'amount' => 80,
        ]],
    ]);

    $this->mock(FcmService::class, function ($mock) use ($passenger) {
        $mock->shouldReceive('sendToUser')
            ->once()
            ->withArgs(fn ($user, $title) => $user->user_id === $passenger->user_id && $title === 'Payment Failed')
            ->andReturn(true);
    });

    app(PaymentService::class)->failOnlinePaymentWithReleases($payment);
});
