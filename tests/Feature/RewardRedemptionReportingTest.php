<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\RewardTransaction;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\ReportingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch C: THESIS_OBJECTIVES_AUDIT.md item #8 — "Reward redemptions excluded
// from financial reporting." ReportingRepository::getPaymentChannelBreakdown()
// never queried reward_transactions, so a fleet's "multi-channel financial
// auditing" silently omitted the value given away via points redemption
// during checkout (PaymentService::checkoutOnline() calls
// RewardService::redeemPoints() with the payment_id when points are applied).
function makeRewardReportFixtures(): Fleet
{
    $operatorUser = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);
    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $operatorUser->user_id,
        'name' => 'Operator Reward Report',
        'phone_num' => '09170000014',
        'address' => 'Test Address',
    ]);
    $operatorCompanyUserId = $operatorUser->fresh()->companyProfile->company_user_id;

    $route = Route::create([
        'route_name' => 'Reward Report Route',
        'origin' => 'A',
        'destination' => 'B',
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'RWD-' . fake()->unique()->numerify('###'),
        'capacity' => 40,
        'seated_capacity' => 30,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    FareRule::create([
        'fleet_id' => $fleet->fleet_id,
        'base_fare' => 15,
        'fare_per_km' => 2,
        'status' => 'active',
        'seat_type' => 'seated',
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
        'status' => 'completed',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 1,
    ]);

    $passengerUser = User::create([
        'username' => 'passenger_' . uniqid(),
        'email' => 'passenger+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'passenger',
    ]);
    $passenger = PassengerUser::create([
        'passenger_uuid' => (string) Str::uuid(),
        'user_id' => $passengerUser->user_id,
        'name' => 'Reward Report Passenger',
        'phone_num' => '09170000015',
        'address' => 'Test Address',
        'birthdate' => '2000-01-01',
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
        'status' => 'alighted',
        'seat_type' => 'seated',
        'amount' => 80,
    ]);

    // Passenger redeemed 50 points (₱20/point → ₱1000 value) during this checkout.
    RewardTransaction::create([
        'passenger_id' => $passenger->passenger_id,
        'payment_id' => $payment->payment_id,
        'points' => -50,
        'type' => 'redeemed',
        'description' => 'Redeemed during checkout: ' . $payment->transaction_reference,
    ]);

    return $fleet;
}

test('payment channel breakdown includes reward redemption value', function () {
    $fleet = makeRewardReportFixtures();

    $result = app(ReportingRepository::class)->getPaymentChannelBreakdown(
        $fleet->fleet_id,
        now()->subDay()->toDateString(),
        now()->addDay()->toDateString(),
    );

    expect($result)->toHaveKey('reward_redemptions');
    expect($result['reward_redemptions']['redemption_count'])->toBe(1);
    expect($result['reward_redemptions']['total_points_redeemed'])->toBe(50);
    expect($result['reward_redemptions']['total_value_php'])->toBe(1000.0);
});
