<?php

use App\Models\BaggageCharge;
use App\Models\DiscountClaim;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\DiscountClaimService;
use App\Services\FleetDailyPinService;
use App\Services\OccupancyService;
use App\Services\PaymentService;
use App\Services\TicketExpiryService;
use App\Services\TicketResolutionService;
use App\Services\TicketService;
use App\Services\TripService;
use App\Support\BaggageFee;
use App\Support\PriorityBoarding;
use App\Support\TripPassengerCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 76 (user, 2026-11-17): a priority seat per ticket (senior, PWD, pregnant), baggage in the compartment, and
// "missed" tickets (the bus left the boarding stop before the ticket was scanned).
// Fare numbers are SAMPLE test values: B -> D is 20 km, 13 + 15 x 2.25 = 46.75, 47 at the whole peso.
// 2026-11-25 (user): baggage is only declared online and paid by size in cash on board (BaggageOnBoardTest); the
// ticket's amount is the fare only.

const B76_SECRET = 'whsk_b76_secret';

function b76Staff(string $role): User
{
    $user = User::create(['username' => 'b76'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'B76 '.ucfirst($role), 'phone_num' => '09170000076', 'address' => 'x']);

    return $user->fresh();
}

function b76Passenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000077', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
}

function b76Fixture(string $status = 'scheduled'): array
{
    $operator = b76Staff('operator');
    $route = Route::create(['route_name' => 'B76 Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'stop'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Sample City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'B76-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co B', 'body_no' => '76', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $status, 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'stops', 'trip', 'fleetRoute');
}

/** @param array<string, mixed> $extra per-item fields (discount_claim_key, priority_type, baggage) */
function b76Checkout($test, PassengerUser $passenger, array $f, array $extra = [], int $count = 1)
{
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id] + $extra;

    return $test->actingAs($passenger->user)->postJson('/api/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash', 'items' => array_fill(0, $count, $item),
    ]);
}

function b76Paid($test, PassengerUser $passenger, array $f, array $extra = []): Ticket
{
    $payment = Payment::findOrFail(b76Checkout($test, $passenger, $f, $extra)->assertSuccessful()->json('data.payment.payment_id'));
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te=".hash_hmac('sha256', $ts.'.'.$payload, B76_SECRET).',li='], $payload)->assertOk();
    $payment->forceFill(['gateway_payment_id' => 'pay_b76_test'])->save();

    return Ticket::where('payment_id', $payment->payment_id)->firstOrFail();
}

/** An unscanned paid ticket on the trip, holding its seat (the trip counter counts it). */
function b76Unscanned(array $f, string $from, string $to): Ticket
{
    $payment = Payment::create(['amount' => 30, 'payment_created' => now(), 'transaction_reference' => 'TXN-B76-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    $f['trip']->increment('current_seated_capacity');

    return Ticket::create([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'trip_id' => $f['trip']->trip_id, 'payment_id' => $payment->payment_id, 'ticket_uuid' => (string) Str::uuid(),
        'status' => 'valid', 'amount' => 30, 'distance_km' => 10, 'seat_type' => 'seated',
        'origin_stop_id' => $f['stops'][$from]->stop_id, 'destination_stop_id' => $f['stops'][$to]->stop_id,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => B76_SECRET]);
    Http::fake([
        'api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_b76_1', 'attributes' => ['status' => 'pending']]]),
        'api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]]),
    ]);
});
afterEach(fn () => Carbon::setTestNow());

it('prices baggage by size from the default list', function () {
    expect(BaggageFee::priceFor(null, 'small'))->toBe(20.0)
        ->and(BaggageFee::priceFor(null, 'medium'))->toBe(50.0)
        ->and(BaggageFee::priceFor(null, 'large'))->toBe(100.0)
        ->and(BaggageFee::isSize('huge'))->toBeFalse();
});

it('only declares baggage online: the payment is the fare, with no baggage line on PayMongo', function () {
    $f = b76Fixture();
    $response = b76Checkout($this, b76Passenger(), $f, ['baggage' => true])->assertSuccessful();
    $payment = Payment::findOrFail($response->json('data.payment.payment_id'));

    expect((float) $payment->amount)->toBe(47.0)
        ->and((float) $payment->fee_amount)->toBe(round(47 * 0.024976, 2));

    Http::assertSent(function ($request) {
        $lines = collect($request['data']['attributes']['line_items'] ?? []);

        return $lines->firstWhere('name', 'Baggage compartment') === null
            && $lines->firstWhere('name', 'Smart Transit Ticket Purchase')['amount'] === 4700;
    });
});

it('marks a declared online ticket as bringing baggage, and a discount is only on the fare', function () {
    $f = b76Fixture();
    $ticket = b76Paid($this, b76Passenger(), $f, ['discount_claim_key' => 'student', 'baggage' => true]);
    expect((float) $ticket->amount)->toBe(47.0)->and($ticket->has_baggage)->toBeTrue()
        ->and((float) DiscountClaim::where('ticket_id', $ticket->ticket_id)->value('fare'))->toBe(47.0);

    $f['trip']->update(['status' => 'boarding']);
    app(DiscountClaimService::class)->review($ticket->fresh(), true, $f['trip']->fresh(), b76Staff('conductor'));

    $ticket = $ticket->fresh();
    // 20% of the PHP 47 fare is about PHP 9: 47 - 9 = 38.
    expect((float) $ticket->amount)->toBe(38.0)
        ->and((float) $ticket->original_amount)->toBe(47.0)
        ->and((float) DiscountClaim::where('ticket_id', $ticket->ticket_id)->value('refund_amount'))->toBe(9.0);
});

it('refunds only the fare of a ticket that declared baggage', function () {
    $f = b76Fixture();
    $ticket = b76Paid($this, b76Passenger(), $f, ['baggage' => true]);

    expect(app(TicketResolutionService::class)->farePaid($ticket->fresh()))->toBe(47.0);
});

it('asks for a priority seat when chosen, or when a senior or PWD discount is claimed', function () {
    $f = b76Fixture();
    $chosen = b76Paid($this, b76Passenger(), $f, ['priority_type' => 'pregnant']);
    $senior = b76Paid($this, b76Passenger(), $f, ['discount_claim_key' => 'senior']);
    $student = b76Paid($this, b76Passenger(), $f, ['discount_claim_key' => 'student']);

    expect($chosen->priority_type)->toBe('pregnant')
        ->and($senior->priority_type)->toBe('senior')
        ->and($student->priority_type)->toBeNull()
        ->and(PriorityBoarding::label('pwd'))->toBe('Person with disability');
});

it('sells at most the priority seats a bus has, and says how many are left', function () {
    config(['booking.priority.seats_per_bus' => 2]);
    $f = b76Fixture();
    b76Paid($this, b76Passenger(), $f, ['priority_type' => 'senior']);

    b76Checkout($this, b76Passenger(), $f, ['priority_type' => 'pwd'], 2)
        ->assertStatus(422)
        ->assertJsonFragment(['Only 1 priority seat is left on this bus. Turn off Priority on the other tickets.']);
    b76Checkout($this, b76Passenger(), $f, ['priority_type' => 'pwd'])->assertSuccessful();
});

it('refuses an unknown priority type', function () {
    b76Checkout($this, b76Passenger(), b76Fixture(), ['priority_type' => 'vip'])->assertStatus(422);
});

it('records baggage on a cash sale as its own payment, apart from the fare', function () {
    $f = b76Fixture('boarding');
    $chauffeur = b76Staff('conductor');
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id, 'baggage_size' => 'medium', 'baggage_stub' => 'BG-12', 'priority_type' => 'pwd'];

    $result = app(PaymentService::class)->checkoutOnsite((int) $chauffeur->companyProfile->company_user_id, ['items' => [$item]]);
    $charge = BaggageCharge::where('ticket_id', $result['tickets'][0]->ticket_id)->firstOrFail();

    expect((float) $result['payment']->amount)->toBe(47.0)
        ->and((float) $result['tickets'][0]->amount)->toBe(47.0)
        ->and((float) $charge->amount)->toBe(50.0)
        ->and($charge->size)->toBe('medium')
        ->and($charge->stub_number)->toBe('BG-12')
        ->and($result['tickets'][0]->has_baggage)->toBeTrue()
        ->and($result['tickets'][0]->priority_type)->toBe('pwd');
});

it('marks missed every unscanned ticket from the first stop once the bus departs, and gives the seats back', function () {
    $f = b76Fixture('departed');
    $atA = b76Unscanned($f, 'A', 'C');
    $atB = b76Unscanned($f, 'B', 'D');
    $claim = DiscountClaim::create(['ticket_id' => $atA->ticket_id, 'discount_type_key' => 'student', 'discount_label' => 'Student', 'discount_rate' => 0.2, 'status' => DiscountClaim::CLAIMED, 'fare' => 30]);

    expect(app(TicketExpiryService::class)->markMissedForTrip($f['trip']->fresh()))->toBe(1);

    expect($atA->fresh()->status)->toBe('missed')
        ->and($atA->fresh()->missed_at)->not->toBeNull()
        ->and($atB->fresh()->status)->toBe('valid')
        ->and((int) $f['trip']->fresh()->current_seated_capacity)->toBe(1)
        ->and($claim->fresh()->status)->toBe(DiscountClaim::LAPSED);
});

it('marks a stop\'s unscanned tickets missed only once the Driver taps a stop after it', function () {
    $f = b76Fixture('departed');
    $atB = b76Unscanned($f, 'B', 'D');

    $f['trip']->forceFill(['last_acknowledged_stop_id' => $f['stops']['B']->stop_id])->save();
    expect(app(TicketExpiryService::class)->markMissedForTrip($f['trip']->fresh()))->toBe(0); // the bus is AT B: people are boarding

    $f['trip']->forceFill(['last_acknowledged_stop_id' => $f['stops']['C']->stop_id])->save();
    expect(app(TicketExpiryService::class)->markMissedForTrip($f['trip']->fresh()))->toBe(1)
        ->and($atB->fresh()->status)->toBe('missed');
});

it('never marks anything missed before the bus departs', function () {
    $f = b76Fixture('boarding');
    $atA = b76Unscanned($f, 'A', 'C');

    expect(app(TicketExpiryService::class)->markMissedForTrip($f['trip']->fresh()))->toBe(0)
        ->and($atA->fresh()->status)->toBe('valid');
});

it('refuses to scan a missed ticket and tells the Chauffeur a new one is needed', function () {
    $f = b76Fixture('departed');
    $atA = b76Unscanned($f, 'A', 'C');
    $this->mock(TripService::class, fn ($mock) => $mock->shouldReceive('getCurrentTripForConductor')->andReturn($f['trip']->fresh()));

    expect(fn () => app(TicketService::class)->validateScan(['ticket_uuid' => $atA->ticket_uuid], 999))
        ->toThrow(ValidationException::class, TicketExpiryService::MISSED_MESSAGE);
    expect($atA->fresh()->status)->toBe('missed');
});

it('marks missed when the Driver departs, through the trip service', function () {
    $f = b76Fixture('boarding');
    $atA = b76Unscanned($f, 'A', 'C');
    $this->mock(FleetDailyPinService::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('isBothVerified')->andReturn(true);
    });

    app(TripService::class)->departTrip((int) $f['trip']->trip_id);

    expect($atA->fresh()->status)->toBe('missed');
});

it('counts the passengers of a leg for an inspector: sold, scanned in, on board, off, not scanned, missed', function () {
    expect(TripPassengerCount::from(['boarded' => 12, 'alighted' => 3, 'valid' => 2, 'missed' => 1, 'expired' => 1, 'rebooked' => 4]))->toBe([
        'tickets_sold' => 19, 'scanned_in' => 15, 'on_board' => 12, 'got_off' => 3, 'not_scanned' => 2, 'missed' => 1, 'expired' => 1,
    ]);
});

it('gives the Chauffeur and the Operator the same count, from paid tickets only', function () {
    $f = b76Fixture('departed');
    b76Unscanned($f, 'B', 'D');
    b76Unscanned($f, 'C', 'D')->update(['status' => 'boarded']);
    $unpaid = b76Unscanned($f, 'C', 'E');
    $unpaid->payment->update(['status' => 'pending']);

    $count = app(OccupancyService::class)->getOccupancyByStop((int) $f['trip']->trip_id)['passenger_count'];
    expect($count)->toMatchArray(['tickets_sold' => 2, 'scanned_in' => 1, 'on_board' => 1, 'not_scanned' => 1]);

    $rows = $this->actingAs($f['operator'])->getJson('/api/operator/trips?status_filter=all')->json('data') ?? [];
    $mine = collect($rows)->firstWhere('trip_id', $f['trip']->trip_id);
    if ($mine !== null) {
        expect($mine['passenger_count'])->toBe($count);
    }
});

it('gives the plain missed message when an already missed ticket is scanned again', function () {
    $f = b76Fixture('departed');
    $atA = b76Unscanned($f, 'A', 'C');
    app(TicketExpiryService::class)->markMissedForTrip($f['trip']->fresh());
    $this->mock(TripService::class, fn ($mock) => $mock->shouldReceive('getCurrentTripForConductor')->andReturn($f['trip']->fresh()));

    expect(fn () => app(TicketService::class)->validateScan(['ticket_uuid' => $atA->ticket_uuid], 999))
        ->toThrow(ValidationException::class, TicketExpiryService::MISSED_MESSAGE);
});
