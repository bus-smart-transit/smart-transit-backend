<?php

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
use App\Repositories\PaymentRepository;
use App\Services\DiscountClaimService;
use App\Services\PayMongoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 75 (panel and user, 2026-11-15): a Student, Senior citizen or PWD discount claimed online. The passenger pays
// the full fare; the Chauffeur checks the ID on the bus; on approval the difference goes back through PayMongo.
// Fare numbers are SAMPLE test values: B -> D is 20 km, 13 + 15 x 2.25 = 46.75, 47 at the whole peso; 20% off = 37.6,
// 38 at the whole peso, so PHP 9 comes back.

const DC_SECRET = 'whsk_dc_secret';

function dcStaff(string $role): User
{
    $user = User::create(['username' => 'dc'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Dc '.ucfirst($role), 'phone_num' => '09170000061', 'address' => 'x']);

    return $user->fresh();
}

function dcPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000062', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
}

function dcFixture(): array
{
    $operator = dcStaff('operator');
    $route = Route::create(['route_name' => 'Dc Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'stop'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'DC-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co D', 'body_no' => '9', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'stops', 'trip');
}

function dcCheckout($test, PassengerUser $passenger, array $f, ?string $claim = 'student', string $channel = 'gcash')
{
    return $test->actingAs($passenger->user)->postJson('/api/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => $channel,
        'items' => [array_filter(['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id, 'discount_claim_key' => $claim])],
    ]);
}

function dcWebhook($test, string $type, array $resource)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => $type, 'livemode' => false, 'data' => $resource, 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, DC_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

/** Checkout with a claim, PayMongo confirms it, and the ticket is issued. */
function dcTicket($test, PassengerUser $passenger, array $f, string $channel = 'gcash'): Ticket
{
    $payment = Payment::findOrFail(dcCheckout($test, $passenger, $f, 'student', $channel)->assertSuccessful()->json('data.payment.payment_id'));
    dcWebhook($test, 'checkout_session.payment.paid', ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []])->assertOk();
    $payment->forceFill(['gateway_payment_id' => 'pay_dc_test'])->save();

    return Ticket::where('payment_id', $payment->payment_id)->firstOrFail();
}

function dcBoarding(array $f): Trip
{
    $f['trip']->update(['status' => 'boarding']);

    return $f['trip']->fresh();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => DC_SECRET]);
    Http::fake([
        'api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_dc_1', 'attributes' => ['status' => 'pending']]]),
        'api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]]),
    ]);
});
afterEach(fn () => Carbon::setTestNow());

it('charges the full fare online and records the claim on the issued ticket', function () {
    $f = dcFixture();
    $ticket = dcTicket($this, dcPassenger(), $f);

    expect((float) $ticket->amount)->toBe(47.0)
        ->and($ticket->discount_type_key)->toBeNull();
    $claim = DiscountClaim::where('ticket_id', $ticket->ticket_id)->firstOrFail();
    expect($claim->status)->toBe('claimed')->and($claim->discount_label)->toBe('Student')->and((float) $claim->fare)->toBe(47.0);
    expect(app(DiscountClaimService::class)->present($claim)['message'])->toContain('Show your valid ID to the Chauffeur');
});

it('refuses a discount the bus does not offer', function () {
    $f = dcFixture();

    dcCheckout($this, dcPassenger(), $f, 'vip')->assertStatus(422);
    expect(Payment::count())->toBe(0);
});

it('lets the Chauffeur approve: the fare becomes the discounted fare and the difference goes back through PayMongo', function () {
    $f = dcFixture();
    $passenger = dcPassenger();
    $ticket = dcTicket($this, $passenger, $f);
    $pointsBefore = (float) $passenger->fresh()->reward_points;

    $claim = app(DiscountClaimService::class)->review($ticket, true, dcBoarding($f), dcStaff('conductor'));

    expect($claim->status)->toBe('approved')
        ->and((float) $claim->refund_amount)->toBe(9.0)
        ->and($claim->refund_status)->toBe('pending')
        ->and($claim->gateway_refund_id)->toBe('ref_dc_1');
    $ticket = $ticket->fresh();
    expect((float) $ticket->amount)->toBe(38.0)
        ->and((float) $ticket->original_amount)->toBe(47.0)
        ->and((float) $ticket->discount_amount)->toBe(9.0)
        ->and($ticket->discount_label)->toBe('Student')
        ->and($ticket->discount_id_checked_at)->not->toBeNull();
    $sent = Http::recorded(fn ($request) => str_contains($request->url(), '/v1/refunds'))->last()[0]->data()['data']['attributes'];
    expect($sent['amount'])->toBe(900)->and($sent['metadata']['discount_claim_id'])->toBe((string) $claim->id);
    // Points earned on PHP 47 (0.24) become those on PHP 38 (0.19): 0.05 comes back off (2026-11-20 rate: 0.1 per PHP 20).
    expect(round((float) $passenger->fresh()->reward_points, 2))->toBe(round($pointsBefore - 0.05, 2));

    // Only the signed refund event settles it; the history then shows a partial refund.
    dcWebhook($this, 'payment.refund.updated', ['id' => 'ref_dc_1', 'type' => 'refund', 'attributes' => ['status' => 'succeeded', 'payment_id' => 'pay_dc_test']])->assertOk();
    expect($claim->fresh()->refund_status)->toBe('succeeded');
    $row = collect(app(PaymentRepository::class)->findPassengerHistoryFromPayments((int) $passenger->passenger_id)->items())->first();
    expect($row['refund_status'])->toBe('partially_refunded')->and((float) $row['refunded_amount'])->toBe(9.0);
});

// 2026-11-19 (audit): a refund event that arrives before the refund id is stored still settles the claim, because the
// payment id is saved before PayMongo is asked; and refunds:reconcile settles a claim whose event never came.
it('settles a discount refund whose event arrives before the refund id is stored', function () {
    $f = dcFixture();
    $ticket = dcTicket($this, dcPassenger(), $f);
    $claim = app(DiscountClaimService::class)->review($ticket, true, dcBoarding($f), dcStaff('conductor'));
    expect($claim->gateway_payment_id)->toBe('pay_dc_test');
    $claim->forceFill(['gateway_refund_id' => null])->save(); // the state while PayMongo has not answered yet

    dcWebhook($this, 'payment.refund.updated', ['id' => 'ref_early', 'type' => 'refund', 'attributes' => [
        'status' => 'succeeded', 'payment_id' => 'pay_dc_test', 'metadata' => ['discount_claim_id' => (string) $claim->id],
    ]])->assertOk();

    expect($claim->fresh())->refund_status->toBe('succeeded')->gateway_refund_id->toBe('ref_early');
});

it('asks PayMongo about a discount refund still pending and settles it, and gives up after the limit', function () {
    $f = dcFixture();
    $claim = app(DiscountClaimService::class)->review(dcTicket($this, dcPassenger(), $f), true, dcBoarding($f), dcStaff('conductor'));
    $other = app(DiscountClaimService::class)->review(dcTicket($this, dcPassenger(), $f), true, $f['trip']->fresh(), dcStaff('conductor'));
    $other->forceFill(['gateway_refund_id' => 'ref_lost'])->save();
    $payMongo = Mockery::mock(PayMongoService::class)->makePartial();
    $payMongo->shouldReceive('retrieveRefund')->with('ref_dc_1')->andReturn(['id' => 'ref_dc_1', 'attributes' => ['status' => 'succeeded']]);
    $payMongo->shouldReceive('retrieveRefund')->with('ref_lost')->andReturn(null);
    app()->instance(PayMongoService::class, $payMongo);

    Carbon::setTestNow(now()->addMinutes(5));
    expect(app(DiscountClaimService::class)->reconcilePending())->toBe(['settled' => 0, 'failed' => 0]); // too soon to ask

    Carbon::setTestNow(now()->addMinutes(15));
    expect(app(DiscountClaimService::class)->reconcilePending())->toBe(['settled' => 1, 'failed' => 0]);
    expect($claim->fresh()->refund_status)->toBe('succeeded')->and($other->fresh()->refund_status)->toBe('pending');

    Carbon::setTestNow(now()->addHours(73));
    $this->artisan('refunds:reconcile')->expectsOutputToContain('Discount refunds: settled 0, failed 1')->assertSuccessful();
    expect($other->fresh())->refund_status->toBe('failed')->failure_reason->toContain('Check it in PayMongo');
});

it('lets the Chauffeur reject once, and keeps the full fare', function () {
    $f = dcFixture();
    $ticket = dcTicket($this, dcPassenger(), $f);
    $service = app(DiscountClaimService::class);

    expect($service->review($ticket, false, dcBoarding($f), dcStaff('conductor'))->status)->toBe('rejected')
        ->and((float) $ticket->fresh()->amount)->toBe(47.0);
    expect(fn () => $service->review($ticket->fresh(), true, $f['trip']->fresh(), dcStaff('conductor')))->toThrow(ValidationException::class, 'This discount was already reviewed.');
    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/v1/refunds')))->toBeEmpty();
});

it('reviews only after Start boarding and only on the Chauffeur\'s own trip', function () {
    $f = dcFixture();
    $ticket = dcTicket($this, dcPassenger(), $f);
    $service = app(DiscountClaimService::class);

    expect(fn () => $service->review($ticket, true, $f['trip']->fresh(), dcStaff('conductor')))->toThrow(ValidationException::class, 'Boarding has not started yet.');
    $other = $f['trip']->replicate();
    $other->status = 'boarding';
    $other->save();
    expect(fn () => $service->review($ticket, true, $other->fresh(), dcStaff('conductor')))->toThrow(ValidationException::class, 'This ticket is for a different trip.');
    expect(DiscountClaim::firstOrFail()->status)->toBe('claimed');
});

it('waits for the next midnight before a Maya partial refund, then sends it', function () {
    $f = dcFixture();
    $ticket = dcTicket($this, dcPassenger(), $f, 'maya');

    $claim = app(DiscountClaimService::class)->review($ticket, true, dcBoarding($f), dcStaff('conductor'));
    expect($claim->refund_status)->toBe('pending')->and($claim->gateway_refund_id)->toBeNull()->and($claim->not_before)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-10-05 00:01:00', 'Asia/Manila'));
    $this->artisan('refunds:release-deferred')->assertSuccessful();
    expect($claim->fresh()->gateway_refund_id)->toBe('ref_dc_1');
});

it('lapses a claim nobody reviewed when the trip ends', function () {
    $f = dcFixture();
    $ticket = dcTicket($this, dcPassenger(), $f);

    expect(app(DiscountClaimService::class)->lapseForTrip((int) $f['trip']->trip_id))->toBe(1);
    $claim = DiscountClaim::where('ticket_id', $ticket->ticket_id)->firstOrFail();
    expect($claim->status)->toBe('lapsed')
        ->and(app(DiscountClaimService::class)->present($claim)['message'])->toContain('The full fare stays');
});

it('guards the Chauffeur\'s review endpoint with pairing and Start boarding', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/conductor/tickets/{ticketId}/discount-claim' && in_array('POST', $r->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('boarding.open')->toContain('paired:conductor');
});

it('lists the discounts a passenger may claim online for a trip', function () {
    $f = dcFixture();

    $this->getJson('/api/booking/discount-types?trip_id='.$f['trip']->trip_id)->assertOk()
        ->assertJsonPath('data.0', ['key' => 'student', 'label' => 'Student', 'rate' => 0.2])
        ->assertJsonCount(3, 'data');
    $this->getJson('/api/booking/discount-types')->assertStatus(422);
});
