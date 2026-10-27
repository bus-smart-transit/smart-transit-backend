<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-10-27: onsite (cash) discounts. Student, Senior citizen and PWD at 20%; the Chauffeur checks the ID; one
// discount per ticket; each Operator turns each type on or off. Online fares use reward points, never a discount.

function odcStaff(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => 'Discount '.ucfirst($role),
        'phone_num' => '09170000028',
        'address' => 'Test Address',
    ]);

    return $user->fresh();
}

/** Stops at 0, 5 and 10 km; fare 15 + 2.00 per km, so A to C is PHP 35 and a 20% discount makes it PHP 28. */
function odcFixture(): array
{
    $operator = odcStaff('operator');
    $driver = odcStaff('driver');
    $conductor = odcStaff('conductor');

    $route = Route::create(['route_name' => 'Discount Route', 'origin' => 'A', 'destination' => 'C']);
    $stops = [];
    foreach ([['Stop A', 0, 'terminal'], ['Stop B', 5, 'stop'], ['Stop C', 10, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => $name, 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0]);
        RouteStop::create([
            'route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1,
            'distance_from_origin_km' => $km, 'stop_type' => $type,
        ]);
        $stops[$name] = $stop;
    }

    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'DSC-'.fake()->unique()->numerify('###'),
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active',
    ]);
    FareRule::create(['fleet_id' => $fleet->fleet_id, 'base_fare' => 15, 'fare_per_km' => 2, 'status' => 'active', 'seat_type' => 'seated']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'driver_accepted_at' => now(), 'conductor_accepted_at' => now(),
        'trip_date' => now('Asia/Manila')->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'leg_direction' => 'outbound',
        'status' => 'boarding',
        'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now('Asia/Manila')->toDateString(),
        'driver_verified_at' => now(),
        'conductor_verified_at' => now(),
        'paired_at' => now(),
    ]);

    return [$operator, $conductor, $trip, $stops];
}

function odcItem(Trip $trip, array $stops, array $extra = []): array
{
    return array_merge([
        'trip_id' => $trip->trip_id,
        'seat_type' => 'seated',
        'origin_stop_id' => $stops['Stop A']->stop_id,
        'destination_stop_id' => $stops['Stop C']->stop_id,
    ], $extra);
}

it('lists the three discount types at 20%, all on until the Operator turns one off', function () {
    [$operator] = odcFixture();

    $types = $this->actingAs($operator)->getJson('/api/operator/discount-types')->assertSuccessful()->json('data');

    expect(array_column($types, 'key'))->toBe(['student', 'senior', 'pwd'])
        ->and(array_unique(array_column($types, 'rate')))->toBe([0.2])
        ->and(array_unique(array_column($types, 'active')))->toBe([true]);
});

it('quotes and sells a discounted cash ticket and keeps the discount on the ticket', function () {
    [, $conductor, $trip, $stops] = odcFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $discount = ['discount_type_key' => 'student', 'discount_id_checked' => true];

    $quote = $this->actingAs($conductor)->postJson('/api/conductor/checkout/quote', odcItem($trip, $stops, $discount))->assertSuccessful();
    expect((float) $quote->json('data.amount'))->toBe(28.0)
        ->and((float) $quote->json('data.original_amount'))->toBe(35.0)
        ->and((float) $quote->json('data.discount_amount'))->toBe(7.0);

    // The sale sends the quoted (discounted) fare; the fare check compares like with like.
    $sale = $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [odcItem($trip, $stops, $discount + ['quoted_amount' => 28])]])->assertSuccessful();

    expect((float) $sale->json('data.payment.amount'))->toBe(28.0);
    $ticket = Ticket::firstOrFail();
    expect((float) $ticket->amount)->toBe(28.0)
        ->and($ticket->original_amount)->toBe(35.0)
        ->and($ticket->discount_amount)->toBe(7.0)
        ->and($ticket->discount_type_key)->toBe('student')
        ->and($ticket->discount_label)->toBe('Student')
        ->and($ticket->discount_rate)->toBe(0.2)
        ->and($ticket->discount_id_checked_at)->not->toBeNull();
});

it('refuses a discount when the Chauffeur has not ticked that the ID was checked', function () {
    [, $conductor, $trip, $stops] = odcFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();

    $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [odcItem($trip, $stops, ['discount_type_key' => 'senior'])]])
        ->assertStatus(422);

    expect(Ticket::count())->toBe(0);
});

it('refuses a discount type the Operator turned off, and lists it as off', function () {
    [$operator, $conductor, $trip, $stops] = odcFixture();

    $this->actingAs($operator)->patchJson('/api/operator/discount-types/student/active', ['active' => false])
        ->assertSuccessful()
        ->assertJsonPath('data.active', false);

    app('auth')->forgetGuards();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $offered = $this->actingAs($conductor)->getJson('/api/conductor/discount-types')->assertSuccessful()->json('data');
    expect(array_column($offered, 'key'))->toBe(['senior', 'pwd']);

    $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [odcItem($trip, $stops, ['discount_type_key' => 'student', 'discount_id_checked' => true])]])
        ->assertStatus(422);
    expect(Ticket::count())->toBe(0);
});

it('keeps one Operator\'s choice from changing another Operator\'s buses', function () {
    [$operatorA] = odcFixture();
    [$operatorB] = odcFixture();

    $this->actingAs($operatorA)->patchJson('/api/operator/discount-types/pwd/active', ['active' => false])->assertSuccessful();
    app('auth')->forgetGuards();

    $types = collect($this->actingAs($operatorB)->getJson('/api/operator/discount-types')->json('data'))->keyBy('key');
    expect($types['pwd']['active'])->toBeTrue();
});

it('says no for an unknown discount type', function () {
    [$operator] = odcFixture();

    $this->actingAs($operator)->patchJson('/api/operator/discount-types/veteran/active', ['active' => false])->assertNotFound();
});

it('refuses a discount on an online checkout', function () {
    [, , $trip, $stops] = odcFixture();

    $this->postJson('/api/checkout', [
        'items' => [odcItem($trip, $stops, ['discount_type_key' => 'student'])],
        'email' => 'guest@example.test',
    ])->assertStatus(422)->assertJsonValidationErrors(['items.0.discount_type_key']);
});

it('shows the discounts in the Operator\'s financial summary and transaction log', function () {
    [$operator, $conductor, $trip, $stops] = odcFixture();
    $this->actingAs($conductor)->postJson('/api/conductor/shift/start')->assertSuccessful();
    $this->actingAs($conductor)->postJson('/api/conductor/checkout', ['items' => [
        odcItem($trip, $stops, ['discount_type_key' => 'pwd', 'discount_id_checked' => true]),
        odcItem($trip, $stops),
    ]])->assertSuccessful();
    app('auth')->forgetGuards();

    $today = now('Asia/Manila')->toDateString();
    $summary = $this->actingAs($operator)->getJson("/api/operator/financials?from={$today}&to={$today}")->assertSuccessful()->json('data');
    expect($summary['discounts']['total'])->toEqual(7)
        ->and($summary['discounts']['tickets'])->toBe(1)
        ->and($summary['discounts']['by_type'][0]['type_key'])->toBe('pwd')
        ->and($summary['kpis']['gross'])->toEqual(63);

    $rows = collect($this->actingAs($operator)->getJson("/api/operator/financials/transactions?from={$today}&to={$today}")->assertSuccessful()->json('data.data'));
    $discounted = $rows->firstWhere('discount_type_key', 'pwd');
    expect($discounted['discount_label'])->toBe('Person with disability')
        ->and($discounted['original_amount'])->toEqual(35)
        ->and($discounted['discount_amount'])->toEqual(7)
        ->and($discounted['amount'])->toEqual(28);
});
