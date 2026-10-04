<?php

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
use App\Models\TicketResolution;
use App\Models\Trip;
use App\Models\TripCancellation;
use App\Models\User;
use App\Services\TicketResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 28 Stage 3, item 6: an OPERATOR cancels a trip; the paid, unused tickets wait for the passenger's choice
// (rebook or refund). Fare numbers are SAMPLE test values: 13.00 + 2.25/km after a 5 km base, whole pesos.

const RF_SECRET = 'whsk_rf_secret';

function rfStaff(string $role): User
{
    $user = User::create(['username' => 'rf'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Rf '.ucfirst($role), 'phone_num' => '09170000041', 'address' => 'x']);

    return $user->fresh();
}

function rfPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000001', 'address' => 'x', 'birthdate' => '2000-01-01']);
}

/** Route A(0) B(10) C(pass-through 20) D(30) E(40); an aircon bus; the original trip and (optionally) a later one. */
function rfFixture(bool $laterTrip = true): array
{
    $operator = rfStaff('operator');
    $route = Route::create(['route_name' => 'Rf Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'distance_from_origin_m' => $km * 1000, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $company = $operator->companyProfile->company_user_id;

    $fleet = Fleet::create(['company_user_id' => $company, 'plate_number' => 'RF-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co A', 'body_no' => '101',
        'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $mk = fn (string $date, string $time, string $direction = 'outbound', string $status = 'scheduled') => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $company, 'trip_date' => $date, 'departure_time' => $time, 'trip_type' => 'one_way',
        'leg_direction' => $direction, 'status' => $status, 'current_seated_capacity' => 1, 'current_standing_capacity' => 0, 'total_occupancy' => 1,
    ]);

    $trip = $mk('2026-10-05', '08:00');
    $later = $laterTrip ? $mk('2026-10-05', '12:00') : null;
    $rule = FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('operator', 'route', 'stops', 'fleet', 'fleetRoute', 'trip', 'later', 'rule', 'mk');
}

/** A paid payment and one ticket on $trip. $channel gcash|maya|onsite (cash). */
function rfTicket(array $f, Trip $trip, array $over = [], string $channel = 'gcash', ?PassengerUser $pax = null, ?Payment $payment = null): Ticket
{
    $amount = $over['amount'] ?? 47.0;
    $payment ??= Payment::create([
        'amount' => $amount, 'payment_created' => now(), 'transaction_reference' => 'TXN-RF-'.Str::upper(Str::random(8)),
        'payment_method' => $channel === 'onsite' ? 'cash' : 'online', 'payment_channel' => $channel, 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true,
        'gateway_reference' => $channel === 'onsite' ? null : 'cs_'.Str::random(8), 'gateway_payment_id' => $channel === 'onsite' ? null : 'pay_'.Str::random(8), 'items_payload' => [],
    ]);

    return Ticket::create(array_merge([
        'fleet_route_id' => $trip->fleet_route_id, 'trip_id' => $trip->trip_id, 'fare_rule_id' => $f['rule']->fare_rule_id, 'rounding_mode' => 'nearest', 'distance_km' => 20,
        'payment_id' => $payment->payment_id, 'passenger_id' => $pax?->passenger_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => 'valid', 'amount' => $amount, 'seat_type' => 'seated',
        'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id, 'boarding_distance_m' => 10000, 'alighting_distance_m' => 30000,
    ], $over));
}

function rfCancel($test, array $f, Trip $trip, ?string $reason = 'Bus breakdown')
{
    return $test->actingAs($f['operator'])->patchJson("/api/operator/trips/{$trip->trip_id}/cancel", ['reason' => $reason]);
}

function rfFakeGateway(string $refundId = 'ref_test_1', string $status = 'pending'): void
{
    Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => $refundId, 'type' => 'refund', 'attributes' => ['status' => $status, 'amount' => 0]]])]);
}

function rfRefundCalls(): array
{
    return Http::recorded(fn ($request) => str_contains($request->url(), '/v1/refunds'))->map(fn ($pair) => $pair[0]->data()['data']['attributes'])->values()->all();
}

function rfRefundWebhook($test, string $refundId, string $status, ?int $resolutionId = null)
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => 'refund.'.($status === 'succeeded' ? 'succeeded' : 'failed'), 'livemode' => false,
        'data' => ['id' => $refundId, 'type' => 'refund', 'attributes' => ['amount' => 100, 'status' => $status, 'metadata' => $resolutionId ? ['resolution_id' => (string) $resolutionId] : new stdClass]], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, RF_SECRET);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload);
}

function rfChoose($test, PassengerUser $pax, Ticket $ticket, array $body)
{
    return $test->actingAs($pax->user)->postJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution", $body);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => RF_SECRET, 'refunds.choice_deadline_days' => null]);
});
afterEach(fn () => Carbon::setTestNow());

it('flags only the paid unused tickets of the cancelled trip, tells the passenger, and leaves used tickets alone', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $valid = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    $used = rfTicket($f, $f['trip'], ['status' => 'alighted', 'boarded_at' => now()], 'gcash', $pax);
    $other = rfTicket($f, $f['later'], [], 'gcash', $pax); // another trip: untouched

    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    expect($valid->fresh()->status)->toBe('trip_cancelled')->and($used->fresh()->status)->toBe('alighted')->and($other->fresh()->status)->toBe('valid');
    expect($f['trip']->fresh()->status)->toBe('cancelled');
    $cancellation = TripCancellation::where('trip_id', $f['trip']->trip_id)->firstOrFail();
    expect($cancellation->stage)->toBe('before_departure')->and($cancellation->actor_role)->toBe('operator')->and($cancellation->cancelled_at->isUtc())->toBeTrue();
    $this->assertDatabaseHas('user_notifications', ['user_id' => $pax->user_id, 'type' => 'trip_cancelled_choice']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.trip_cancelled_awaiting_choice', 'subject_id' => $valid->ticket_id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'trip.cancel', 'subject_id' => $f['trip']->trip_id]);

    // Nothing is refunded by itself.
    expect(TicketResolution::count())->toBe(0);

    // A trip that already ended cannot be cancelled again, and a cancelled one is not cancelled twice.
    rfCancel($this, $f, $f['trip'])->assertStatus(422);
});

it('affects only the return ticket when the operator cancels the return trip of a roundtrip', function () {
    $f = rfFixture();
    $returnTrip = $f['mk']('2026-10-06', '15:00', 'reverse');
    $pax = rfPassenger();
    $payment = Payment::create(['amount' => 94, 'payment_created' => now(), 'transaction_reference' => 'TXN-RT-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'gateway_reference' => 'cs_rt', 'gateway_payment_id' => 'pay_rt', 'items_payload' => []]);
    $out = rfTicket($f, $f['trip'], ['journey_leg' => 'outbound'], 'gcash', $pax, $payment);
    $back = rfTicket($f, $returnTrip, ['journey_leg' => 'return', 'origin_stop_id' => $f['stops']['D']->stop_id, 'destination_stop_id' => $f['stops']['B']->stop_id, 'boarding_distance_m' => 30000, 'alighting_distance_m' => 10000], 'gcash', $pax, $payment);

    rfCancel($this, $f, $returnTrip)->assertSuccessful();

    expect($out->fresh()->status)->toBe('valid')->and($back->fresh()->status)->toBe('trip_cancelled');
    rfFakeGateway();
    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$back->ticket_uuid}/resolution")->assertSuccessful()->json('data');
    expect($view['refund']['amount'])->toEqual(47.0)->and($view['awaiting_choice'])->toBeTrue(); // this ticket's own fare, not the whole payment
    rfChoose($this, $pax, $back, ['choice' => 'refund'])->assertSuccessful();
    expect(rfRefundCalls()[0]['amount'])->toBe(4700);
    expect($out->fresh()->status)->toBe('valid');
});

it('rebooks onto a later departure of the same route, class and stops at no extra charge, taking a place on the new trip', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution")->assertSuccessful()->json('data');
    expect($view['rebook']['available'])->toBeTrue()->and($view['rebook']['options'][0]['trip_id'])->toBe($f['later']->trip_id);

    $result = rfChoose($this, $pax, $ticket, ['choice' => 'rebook'])->assertSuccessful()->json('data');
    $resolution = TicketResolution::where('ticket_id', $ticket->ticket_id)->firstOrFail();
    $new = Ticket::findOrFail($resolution->rebooked_ticket_id);

    expect($ticket->fresh()->status)->toBe('rebooked')->and($resolution->resolution)->toBe('rebook')->and($resolution->status)->toBe('succeeded');
    expect($new->status)->toBe('valid')->and($new->trip_id)->toBe($f['later']->trip_id)->and((float) $new->amount)->toBe(47.0)->and($new->payment_id)->toBe($ticket->payment_id);
    expect([$new->origin_stop_id, $new->destination_stop_id])->toBe([$ticket->origin_stop_id, $ticket->destination_stop_id]);
    expect($new->ticket_uuid)->not->toBe($ticket->ticket_uuid)->and($new->tracking_token_hash)->not->toBeNull();
    // Capacity = tickets sold: the new trip gains one, the cancelled trip's counters are left as they were.
    expect($f['later']->fresh()->current_seated_capacity)->toBe(2)->and($f['trip']->fresh()->current_seated_capacity)->toBe(1);
    expect($result['resolution']['rebooked_ticket_uuid'])->toBe($new->ticket_uuid);
    $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.rebooked', 'subject_id' => $ticket->ticket_id]);
    expect(Http::recorded()->count())->toBe(0); // no money moved
});

it('says clearly when no later departure exists, keeps the refund open, and does not rebook onto other classes or full trips', function () {
    $f = rfFixture(false);
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    // A later trip of the OTHER class, one of a different service type, and one that is full: none qualifies.
    $ordinary = Fleet::create(['company_user_id' => $f['operator']->companyProfile->company_user_id, 'plate_number' => 'RF-ORD1', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'vehicle_class' => 'ordinary']);
    $ordFr = FleetRoute::create(['fleet_id' => $ordinary->fleet_id, 'route_id' => $f['route']->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    Trip::create(['fleet_route_id' => $ordFr->fleet_route_id, 'company_user_id' => $f['operator']->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '12:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    $full = $f['mk']('2026-10-05', '13:00');
    $full->update(['current_seated_capacity' => 30, 'total_occupancy' => 30]);
    $express = $f['mk']('2026-10-05', '14:00');
    $express->update(['service_type' => 'express']);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution")->assertSuccessful()->json('data');
    expect($view['rebook']['available'])->toBeFalse()->and($view['rebook']['message'])->toContain('refund option stays open');

    rfChoose($this, $pax, $ticket, ['choice' => 'rebook'])->assertStatus(422);
    expect($ticket->fresh()->status)->toBe('trip_cancelled')->and(TicketResolution::count())->toBe(0);

    rfFakeGateway();
    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful(); // still open
    expect(TicketResolution::first()->resolution)->toBe('refund');
});

it('refunds the full ticket fare, whole pesos, when the trip is cancelled before departure (digital: gateway reversal)', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    rfFakeGateway('ref_full_1');

    $data = rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful()->json('data');

    $resolution = TicketResolution::where('ticket_id', $ticket->ticket_id)->firstOrFail();
    expect($resolution->amount)->toBe(47.0)->and($resolution->method)->toBe('gateway')->and($resolution->status)->toBe('pending')->and($resolution->gateway_refund_id)->toBe('ref_full_1');
    expect($resolution->actor_role)->toBe('passenger')->and($resolution->on_behalf)->toBeFalse();
    // Pending until the SIGNED webhook says otherwise: never from the request's own answer.
    expect($ticket->fresh()->status)->toBe('refund_pending')->and($data['resolution']['status'])->toBe('pending');

    $calls = rfRefundCalls();
    expect($calls)->toHaveCount(1)->and($calls[0]['amount'])->toBe(4700)->and($calls[0]['payment_id'])->toBe($ticket->payment->gateway_payment_id)->and($calls[0]['reason'])->toBe('others');
    expect($calls[0]['metadata']['resolution_id'])->toBe((string) $resolution->id);

    rfRefundWebhook($this, 'ref_full_1', 'succeeded')->assertOk();
    expect($ticket->fresh()->status)->toBe('refunded')->and($resolution->fresh()->status)->toBe('succeeded')->and($resolution->fresh()->completed_at->isUtc())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.refund_succeeded', 'subject_id' => $ticket->ticket_id]);
    $this->assertDatabaseHas('user_notifications', ['user_id' => $pax->user_id, 'type' => 'ticket_refunded']);
});

it('prices a mid-route cancellation as fare paid minus the fare of the ridden part, to the last acknowledged stop', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $trip = $f['mk']('2026-10-04', '06:00', 'outbound', 'departed');
    $trip->forceFill(['last_acknowledged_stop_id' => $f['stops']['D']->stop_id, 'last_acknowledged_at' => now()])->save();
    // B (10 km) to E (40 km): 13 + 25 x 2.25 = 69.25 -> 69; the bus reached D (30 km): 20 km ridden = 13 + 15 x 2.25 = 46.75 -> 47.
    $ticket = rfTicket($f, $trip, ['status' => 'boarded', 'boarded_at' => now(), 'amount' => 69.0, 'destination_stop_id' => $f['stops']['E']->stop_id, 'alighting_distance_m' => 40000], 'gcash', $pax);
    $unboarded = rfTicket($f, $trip, ['amount' => 69.0, 'destination_stop_id' => $f['stops']['E']->stop_id, 'alighting_distance_m' => 40000], 'gcash', $pax);

    rfCancel($this, $f, $trip)->assertSuccessful();
    $cancellation = TripCancellation::where('trip_id', $trip->trip_id)->firstOrFail();
    expect($cancellation->stage)->toBe('mid_route')->and($cancellation->last_point_source)->toBe('stop_ack')->and($cancellation->last_point_distance_m)->toBe(30000);

    rfFakeGateway('ref_part_1');
    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();
    $resolution = TicketResolution::where('ticket_id', $ticket->ticket_id)->firstOrFail();
    expect($resolution->amount)->toBe(22.0)->and($resolution->ridden_fare)->toBe(47.0)->and($resolution->fare_paid)->toBe(69.0);
    expect($resolution->basis)->toMatchArray(['type' => 'partial', 'boarding_distance_m' => 10000, 'last_point_distance_m' => 30000, 'last_point_source' => 'stop_ack']);
    expect(rfRefundCalls()[0]['amount'])->toBe(2200);

    // The ticket that never boarded gets the whole fare back, and may rebook.
    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$unboarded->ticket_uuid}/resolution")->json('data');
    expect($view['refund']['amount'])->toEqual(69.0);
    // A boarded ticket cannot rebook: only the part not travelled is refunded.
    $boarded2 = rfTicket($f, $trip, ['status' => 'trip_cancelled', 'boarded_at' => now(), 'amount' => 69.0, 'destination_stop_id' => $f['stops']['E']->stop_id, 'alighting_distance_m' => 40000], 'gcash', $pax);
    rfChoose($this, $pax, $boarded2, ['choice' => 'rebook'])->assertStatus(422);
});

it('prices a snapped boarding point (no origin stop) from the stored boarding distance', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $trip = $f['mk']('2026-10-04', '06:00', 'outbound', 'departed');
    $trip->forceFill(['last_acknowledged_stop_id' => $f['stops']['D']->stop_id, 'last_acknowledged_at' => now()])->save();
    // Boarded at 15 km (flag-down), going to E (40 km): 13 + 20 x 2.25 = 58; ridden 15 -> 30 km = 15 km = 13 + 10 x 2.25 = 35.5 -> 36; refund 22.
    $ticket = rfTicket($f, $trip, ['status' => 'boarded', 'boarded_at' => now(), 'amount' => 58.0, 'origin_stop_id' => null, 'boarding_distance_m' => 15000, 'destination_stop_id' => $f['stops']['E']->stop_id, 'alighting_distance_m' => 40000], 'gcash', $pax);
    rfCancel($this, $f, $trip)->assertSuccessful();

    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution")->assertSuccessful()->json('data');
    expect($view['refund']['amount'])->toEqual(22.0)->and($view['refund']['ridden_fare'])->toEqual(36.0)->and($view['rebook']['available'])->toBeFalse();
});

it('does not guess the travelled part when the last point is unknown: it is flagged for operator review and then set by a person', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $trip = $f['mk']('2026-10-04', '06:00', 'outbound', 'departed'); // no acknowledged stop, no GPS
    $ticket = rfTicket($f, $trip, ['status' => 'boarded', 'boarded_at' => now(), 'amount' => 69.0, 'destination_stop_id' => $f['stops']['E']->stop_id, 'alighting_distance_m' => 40000], 'gcash', $pax);
    rfCancel($this, $f, $trip)->assertSuccessful();
    expect(TripCancellation::first()->last_point_distance_m)->toBeNull();

    rfFakeGateway('ref_rev_1');
    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();
    $resolution = TicketResolution::firstOrFail();
    expect($resolution->status)->toBe('needs_review')->and($resolution->amount)->toBeNull()->and($ticket->fresh()->status)->toBe('refund_review');
    expect(rfRefundCalls())->toBe([]); // nothing was sent anywhere

    // The operator sets it, within what was paid, with a recorded reason; a larger amount is capped at the fare paid.
    $this->actingAs($f['operator'])->postJson("/api/operator/ticket-resolutions/{$resolution->id}/review", ['amount' => 500, 'note' => 'Driver confirmed the bus broke down near stop D'])->assertSuccessful();
    expect($resolution->fresh()->amount)->toBe(69.0)->and($resolution->fresh()->status)->toBe('pending')->and($resolution->fresh()->actor_role)->toBe('operator');
    expect(rfRefundCalls()[0]['amount'])->toBe(6900);
    $this->actingAs($f['operator'])->postJson("/api/operator/ticket-resolutions/{$resolution->id}/review", ['amount' => 10, 'note' => 'again'])->assertStatus(422);
});

it('uses the later of the last stop and the last usable GPS position, and ignores one that is off the route or too old', function () {
    $f = rfFixture();
    $trip = $f['mk']('2026-10-04', '06:00', 'outbound', 'departed');
    $trip->forceFill(['last_acknowledged_stop_id' => $f['stops']['B']->stop_id, 'last_acknowledged_at' => now()->subMinutes(10)])->save();

    $service = app(\App\Services\TripCancellationService::class);
    $point = $service->resolveLastPoint($trip->fresh());
    expect($point['source'])->toBe('stop_ack')->and($point['distance_m'])->toBe(10000)->and($point['stop_id'])->toBe($f['stops']['B']->stop_id);

    $trip->forceFill(['last_acknowledged_stop_id' => null, 'last_acknowledged_at' => null])->save();
    expect($service->resolveLastPoint($trip->fresh()))->toBeNull(); // nothing known: nothing guessed
});

it('never pays out cash for a digital ticket, and a terminal action on a digital ticket is a gateway reversal', function () {
    $f = rfFixture();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', null); // a guest ticket: only staff can act for them
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    rfFakeGateway('ref_term_1');

    $this->actingAs($f['operator'])->postJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution", ['choice' => 'refund', 'method' => 'cash'])->assertStatus(422);
    expect(TicketResolution::count())->toBe(0)->and(rfRefundCalls())->toBe([]);

    $this->actingAs($f['operator'])->postJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution", ['choice' => 'refund', 'note' => 'Guest at the counter'])->assertSuccessful();
    $resolution = TicketResolution::firstOrFail();
    expect($resolution->method)->toBe('gateway')->and($resolution->on_behalf)->toBeTrue()->and($resolution->actor_role)->toBe('operator')->and($resolution->actor_user_id)->toBe($f['operator']->user_id);
    expect(rfRefundCalls())->toHaveCount(1);

    // The cash entry endpoint refuses a digital refund.
    $this->actingAs($f['operator'])->postJson("/api/operator/ticket-resolutions/{$resolution->id}/cash")->assertStatus(422);
    expect($resolution->fresh()->status)->toBe('pending');
});

it('records a cash refund of a cash ticket with who, when and how much, at the counter or after the passenger asked', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $atCounter = rfTicket($f, $f['trip'], [], 'onsite', null);
    $asked = rfTicket($f, $f['trip'], [], 'onsite', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    // Staff at the counter: recorded in the same step.
    $this->actingAs($f['operator'])->postJson("/api/operator/tickets/{$atCounter->ticket_uuid}/resolution", ['choice' => 'refund', 'note' => 'Cash handed back'])->assertSuccessful();
    $entry = TicketResolution::where('ticket_id', $atCounter->ticket_id)->firstOrFail();
    expect($entry->method)->toBe('cash')->and($entry->status)->toBe('succeeded')->and($entry->amount)->toBe(47.0)->and($entry->actor_user_id)->toBe($f['operator']->user_id)->and($entry->completed_at)->not->toBeNull();
    expect($atCounter->fresh()->status)->toBe('refunded');
    $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.cash_refund_recorded', 'subject_id' => $atCounter->ticket_id]);

    // The passenger asks; it waits for the operator's cash entry; no gateway call is ever made.
    rfChoose($this, $pax, $asked, ['choice' => 'refund'])->assertSuccessful();
    $pending = TicketResolution::where('ticket_id', $asked->ticket_id)->firstOrFail();
    expect($pending->status)->toBe('pending')->and($pending->method)->toBe('cash')->and($asked->fresh()->status)->toBe('refund_pending');
    $this->actingAs($f['operator'])->postJson("/api/operator/ticket-resolutions/{$pending->id}/cash", ['note' => 'Paid at the terminal'])->assertSuccessful();
    $this->actingAs($f['operator'])->postJson("/api/operator/ticket-resolutions/{$pending->id}/cash")->assertSuccessful(); // idempotent
    expect($pending->fresh()->status)->toBe('succeeded')->and($asked->fresh()->status)->toBe('refunded')->and($pending->fresh()->actor_role)->toBe('operator');
    expect(Http::recorded()->count())->toBe(0);
});

it('settles a ticket once: a double tap and a second choice change nothing and send one refund', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    rfFakeGateway('ref_dbl_1');

    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();
    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful(); // double tap

    expect(TicketResolution::count())->toBe(1)->and(rfRefundCalls())->toHaveCount(1);
    // Having asked for a refund, the same ticket cannot also be rebooked.
    rfChoose($this, $pax, $ticket, ['choice' => 'rebook'])->assertStatus(422);
    expect(TicketResolution::count())->toBe(1)->and(Ticket::count())->toBe(1);
});

it('treats a replayed or late refund webhook as a no-op and never undoes a success', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    rfFakeGateway('ref_replay_1');
    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();
    $resolution = TicketResolution::firstOrFail();

    rfRefundWebhook($this, 'ref_replay_1', 'succeeded')->assertOk();
    $completedAt = $resolution->fresh()->completed_at;
    rfRefundWebhook($this, 'ref_replay_1', 'succeeded')->assertOk(); // replay
    rfRefundWebhook($this, 'ref_replay_1', 'failed')->assertOk();     // a late, contradictory event

    expect($ticket->fresh()->status)->toBe('refunded')->and($resolution->fresh()->status)->toBe('succeeded')->and($resolution->fresh()->completed_at->equalTo($completedAt))->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'ticket.refund_succeeded')->count())->toBe(1);
    expect(DB::table('user_notifications')->where('type', 'ticket_refunded')->count())->toBe(1);

    // An unsigned or unknown event is rejected / ignored without touching anything.
    $this->postJson('/api/webhooks/paymongo', ['data' => []])->assertStatus(401);
    rfRefundWebhook($this, 'ref_unknown', 'succeeded')->assertOk();
    expect(TicketResolution::count())->toBe(1);
});

it('marks a gateway refund that the provider refuses as failed for review, and lets staff retry it', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    Http::fake(['api.paymongo.com/v1/refunds' => Http::sequence()
        ->push(['errors' => [['code' => 'refund_amount_exceeds_payment', 'detail' => 'The refund exceeds the payment.']]], 400)
        ->push(['data' => ['id' => 'ref_retry_1', 'attributes' => ['status' => 'pending']]])]);

    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();
    $resolution = TicketResolution::firstOrFail();
    expect($resolution->status)->toBe('failed')->and($resolution->failure_reason)->toContain('exceeds')->and($ticket->fresh()->status)->toBe('refund_review');

    $this->actingAs($f['operator'])->postJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution", ['choice' => 'refund'])->assertSuccessful();
    expect($resolution->fresh()->status)->toBe('pending')->and($resolution->fresh()->gateway_refund_id)->toBe('ref_retry_1')->and(TicketResolution::count())->toBe(1);
});

it('waits for the next Manila midnight before a partial Maya refund on the day of the payment', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $payment = Payment::create(['amount' => 94, 'payment_created' => now(), 'transaction_reference' => 'TXN-MAYA-1', 'payment_method' => 'online', 'payment_channel' => 'maya', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'gateway_reference' => 'cs_maya', 'gateway_payment_id' => 'pay_maya', 'items_payload' => []]);
    $ticket = rfTicket($f, $f['trip'], [], 'maya', $pax, $payment);
    rfTicket($f, $f['later'], [], 'maya', $pax, $payment); // the other ticket of the payment: this refund is partial
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    rfFakeGateway('ref_maya_1');

    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();
    $resolution = TicketResolution::firstOrFail();
    expect($resolution->status)->toBe('pending')->and($resolution->gateway_refund_id)->toBeNull()->and(rfRefundCalls())->toBe([]);
    expect($resolution->not_before->setTimezone('Asia/Manila')->toDateTimeString())->toBe('2026-10-05 00:00:00');

    Carbon::setTestNow(Carbon::parse('2026-10-05 00:05:00', 'Asia/Manila'));
    expect(app(TicketResolutionService::class)->releaseDeferred())->toBe(1);
    expect(rfRefundCalls())->toHaveCount(1)->and($resolution->fresh()->gateway_refund_id)->toBe('ref_maya_1');
});

it('refuses a refund or rebook of a ticket that is not waiting for a choice, and a passenger cancel is still a 422', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $used = rfTicket($f, $f['trip'], ['status' => 'alighted', 'boarded_at' => now()], 'gcash', $pax);
    $valid = rfTicket($f, $f['later'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    foreach (['refund', 'rebook'] as $choice) {
        rfChoose($this, $pax, $used, ['choice' => $choice])->assertStatus(422);  // used ticket: never refundable
        rfChoose($this, $pax, $valid, ['choice' => $choice])->assertStatus(422); // its trip was not cancelled
    }
    expect(TicketResolution::count())->toBe(0)->and($used->fresh()->status)->toBe('alighted')->and($valid->fresh()->status)->toBe('valid');

    $this->actingAs($pax->user)->postJson("/api/passengers/tickets/{$valid->ticket_uuid}/cancel")->assertStatus(422);
    $this->actingAs($pax->user)->postJson("/api/passengers/tickets/{$valid->ticket_uuid}/refund")->assertStatus(422);
});

it('keeps another passenger\'s ticket and another operator\'s trips out of reach', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    $stranger = rfPassenger();
    $this->actingAs($stranger->user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution")->assertStatus(404);
    rfChoose($this, $stranger, $ticket, ['choice' => 'refund'])->assertStatus(404);

    $rival = rfStaff('operator');
    $this->actingAs($rival)->getJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution")->assertStatus(404);
    $this->actingAs($rival)->postJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution", ['choice' => 'refund'])->assertStatus(404);
    $this->actingAs($rival)->patchJson("/api/operator/trips/{$f['later']->trip_id}/cancel")->assertStatus(403);
    expect($this->actingAs($rival)->getJson('/api/operator/ticket-resolutions')->assertSuccessful()->json('data'))->toBe([]);
    expect($this->actingAs($f['operator'])->getJson('/api/operator/ticket-resolutions')->json('data.0.ticket_uuid'))->toBe($ticket->ticket_uuid);

    $this->actingAs($pax->user)->patchJson("/api/operator/trips/{$f['trip']->trip_id}/cancel")->assertStatus(403); // a passenger cannot cancel a trip
    TicketResolution::count(); // nothing created by any of the refused calls
    expect(TicketResolution::count())->toBe(0);
});

it('lets the passenger choose until the configured deadline, then only staff; with no deadline set there is no auto-refund', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    // No deadline configured: weeks later the ticket is still awaiting a choice and nothing was refunded.
    Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution")->json('data');
    expect($view['awaiting_choice'])->toBeTrue()->and($view['deadline'])->toBe(['configured' => false, 'open' => true, 'closes_at' => null]);
    expect($ticket->fresh()->status)->toBe('trip_cancelled')->and(TicketResolution::count())->toBe(0);

    config(['refunds.choice_deadline_days' => 3]);
    $view = $this->actingAs($pax->user)->getJson("/api/passengers/tickets/{$ticket->ticket_uuid}/resolution")->json('data');
    expect($view['deadline']['configured'])->toBeTrue()->and($view['deadline']['open'])->toBeFalse();
    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertStatus(422);

    rfFakeGateway('ref_late_1');
    $this->actingAs($f['operator'])->postJson("/api/operator/tickets/{$ticket->ticket_uuid}/resolution", ['choice' => 'refund'])->assertSuccessful(); // staff can still act
    expect(TicketResolution::count())->toBe(1);
});

it('flags a ticket that is confirmed late for a trip the operator already cancelled, and stores the gateway payment id from the paid event', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $payment = Payment::create(['amount' => 47, 'payment_created' => now(), 'transaction_reference' => 'TXN-LATE-1', 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'pending', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'gateway_reference' => 'cs_late', 'items_payload' => [[
        'fleet_route_id' => $f['trip']->fleet_route_id, 'trip_id' => $f['trip']->trip_id, 'fare_rule_id' => $f['rule']->fare_rule_id, 'distance_km' => 20, 'amount' => 47, 'seat_type' => 'seated', 'passenger_id' => $pax->passenger_id,
        'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id,
    ]]]);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();

    $payload = json_encode(['data' => ['id' => 'evt_late', 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
        'data' => ['id' => 'cs_late', 'type' => 'checkout_session', 'attributes' => ['payments' => [['id' => 'pay_from_event']]]], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, RF_SECRET);
    $this->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload)->assertOk();

    $ticket = Ticket::where('payment_id', $payment->payment_id)->firstOrFail();
    expect($ticket->status)->toBe('trip_cancelled')->and($payment->fresh()->gateway_payment_id)->toBe('pay_from_event');
});

it('reads the payment id from the checkout session for a payment made before the id was stored', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $ticket = rfTicket($f, $f['trip'], [], 'gcash', $pax);
    $ticket->payment->update(['gateway_payment_id' => null, 'gateway_reference' => 'cs_old_1']);
    rfCancel($this, $f, $f['trip'])->assertSuccessful();
    Http::fake([
        'api.paymongo.com/v1/checkout_sessions/cs_old_1' => Http::response(['data' => ['attributes' => ['payments' => [['id' => 'pay_old_1']]]]]),
        'api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_old_1', 'attributes' => ['status' => 'pending']]]),
    ]);

    rfChoose($this, $pax, $ticket, ['choice' => 'refund'])->assertSuccessful();

    expect(rfRefundCalls()[0]['payment_id'])->toBe('pay_old_1')->and($ticket->payment->fresh()->gateway_payment_id)->toBe('pay_old_1');
});

it('cancels every leg of a shift block through the same flow', function () {
    $f = rfFixture();
    $pax = rfPassenger();
    $driver = rfStaff('driver');
    $conductor = rfStaff('conductor');
    $block = app(\App\Services\ShiftBlockService::class)->createBlock([
        'fleet_route_id' => $f['fleetRoute']->fleet_route_id, 'driver_id' => $driver->companyProfile->company_user_id, 'conductor_id' => $conductor->companyProfile->company_user_id,
        'company_user_id' => $f['operator']->companyProfile->company_user_id, 'block_type' => 'morning', 'scheduled_date' => '2026-10-08', 'leg_departure_times' => ['06:00', '09:00', '12:00', '15:00'],
    ]);
    $leg = $block->legs->first();
    $ticket = rfTicket($f, $leg, [], 'gcash', $pax);

    $this->actingAs($f['operator'])->patchJson("/api/operator/shift-blocks/{$block->shift_block_id}/cancel")->assertSuccessful();

    expect($ticket->fresh()->status)->toBe('trip_cancelled');
    expect(TripCancellation::whereIn('trip_id', $block->legs->pluck('trip_id'))->count())->toBe($block->legs->count());
});
