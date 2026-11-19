<?php

use App\Http\Requests\ScanTicketRequest;
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
use App\Repositories\TicketRepository;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Scanning (Priority 1, items 6-7):
//  - boarding is claimed in one statement, so two scans of the same ticket at the same instant cannot both succeed;
//  - the 8-character Ticket ID printed on the ticket can be typed by hand, and is looked up only within the running trip.
// The suite runs on SQLite in memory, so the race itself cannot be run here: the tests prove the claim is conditional
// (it changes a row only while the ticket is 'valid') and that a lost claim is refused by both scan paths.
// Fare numbers are SAMPLE test values.

const SCN_SECRET = 'whsk_sc_secret';

function scnStaff(string $role): User
{
    $user = User::create(['username' => 'sc'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sc '.ucfirst($role), 'phone_num' => '09170000061', 'address' => 'x']);

    return $user->fresh();
}

function scnFixture(): array
{
    $operator = scnStaff('operator');
    $route = Route::create(['route_name' => 'Sc Route', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'SC-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co S', 'body_no' => '9', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $makeTrip = fn () => Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return ['trip' => $makeTrip(), 'otherTrip' => $makeTrip(), 'stops' => $stops];
}

/** A paid online ticket for the trip, bought the way a passenger buys it (checkout, then the signed paid webhook). */
function scnTicket($test, array $f, Trip $trip): Ticket
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    $pax = PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Pax', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);

    $paymentId = $test->actingAs($pax->user)->postJson('/api/checkout', [
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash',
        'items' => [['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id]],
    ])->assertSuccessful()->json('data.payment.payment_id');
    $payment = Payment::findOrFail($paymentId);

    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, SCN_SECRET);
    $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload)->assertOk();

    return Ticket::where('payment_id', $paymentId)->firstOrFail();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    config(['services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => SCN_SECRET]);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
});
afterEach(fn () => Carbon::setTestNow());

// ------------------------------------------------------------ one boarding per ticket

it('boards a ticket once, and refuses the second scan', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:30:00', 'Asia/Manila'));
    $service = app(TicketService::class);

    $first = $service->validateScan(['ticket_uuid' => $ticket->ticket_uuid]);
    expect($first->status)->toBe('boarded');

    expect(fn () => $service->validateScan(['ticket_uuid' => $ticket->ticket_uuid]))
        ->toThrow(ValidationException::class, 'Ticket is already boarded.');
});

it('the boarding claim changes a row only while the ticket is still valid', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    $repository = app(TicketRepository::class);

    expect($repository->markBoarded($ticket->ticket_id))->toBeTrue();
    $boardedAt = $ticket->fresh()->boarded_at;
    expect($repository->markBoarded($ticket->ticket_id))->toBeFalse();   // a second claim finds nothing to change
    expect($ticket->fresh()->boarded_at->equalTo($boardedAt))->toBeTrue();   // and does not rewrite the first boarding time
});

it('refuses a scan that lost the claim to another scan, even though it read the ticket as valid', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:30:00', 'Asia/Manila'));

    // The other phone's claim lands between this scan's read and its own claim: simulated by a claim that fails.
    $repository = Mockery::mock(TicketRepository::class)->makePartial();
    $repository->shouldReceive('markBoarded')->once()->andReturn(false);
    app()->instance(TicketRepository::class, $repository);
    app()->forgetInstance(TicketService::class);

    expect(fn () => app(TicketService::class)->validateScan(['ticket_uuid' => $ticket->ticket_uuid]))
        ->toThrow(ValidationException::class, 'Ticket is already boarded.');
});

it('a group scan skips a ticket whose claim was lost, and does not count it as boarded', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    $reference = $ticket->payment->transaction_reference;
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:30:00', 'Asia/Manila'));

    $repository = Mockery::mock(TicketRepository::class)->makePartial();
    $repository->shouldReceive('markBoarded')->once()->andReturn(false);
    app()->instance(TicketRepository::class, $repository);
    app()->forgetInstance(TicketService::class);

    $result = app(TicketService::class)->scanGroup(['transaction_reference' => $reference]);

    expect($result['boarded_count'])->toBe(0);
    expect($result['tickets'][0]['skipped'])->toBeTrue();
});

// ------------------------------------------------------------ the typed Ticket ID

it('finds a ticket by the 8-character Ticket ID printed on it, in either case', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    $short = substr($ticket->ticket_uuid, 0, 8);
    $service = app(TicketService::class);

    expect($service->resolveShortCode($short, $f['trip']->trip_id))->toBe($ticket->ticket_uuid);
    expect($service->resolveShortCode(strtoupper($short), $f['trip']->trip_id))->toBe($ticket->ticket_uuid);
});

it('looks the Ticket ID up only within the given trip', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    $short = substr($ticket->ticket_uuid, 0, 8);

    expect(fn () => app(TicketService::class)->resolveShortCode($short, $f['otherTrip']->trip_id))
        ->toThrow(ValidationException::class, 'Ticket not found on this trip.');
});

it('refuses a Ticket ID that matches more than one ticket on the trip, instead of guessing', function () {
    $f = scnFixture();
    $one = scnTicket($this, $f, $f['trip']);
    $two = scnTicket($this, $f, $f['trip']);
    $one->update(['ticket_uuid' => 'abcdef12-0000-4000-8000-000000000001']);
    $two->update(['ticket_uuid' => 'abcdef12-0000-4000-8000-000000000002']);

    expect(fn () => app(TicketService::class)->resolveShortCode('ABCDEF12', $f['trip']->trip_id))
        ->toThrow(ValidationException::class, 'More than one ticket matches');
});

it('a full ticket code passes through untouched, and a Ticket ID needs a running trip to be looked up', function () {
    $service = app(TicketService::class);
    $uuid = (string) Str::uuid();

    expect($service->resolveScanCode($uuid, 123))->toBe($uuid);
    expect(fn () => $service->resolveScanCode('ABCDEF12', null))
        ->toThrow(ValidationException::class, 'No active trip found');
});

it('the scan request accepts the QR code or the 8-character Ticket ID, and nothing else', function () {
    $f = scnFixture();
    $ticket = scnTicket($this, $f, $f['trip']);
    $rules = (new ScanTicketRequest())->rules();
    $valid = fn (string $value) => ! Validator::make(['ticket_uuid' => $value], $rules)->fails();

    expect($valid($ticket->ticket_uuid))->toBeTrue();
    expect($valid(substr($ticket->ticket_uuid, 0, 8)))->toBeTrue();
    expect($valid('  '.strtoupper(substr($ticket->ticket_uuid, 0, 8)).'  '))->toBeTrue();

    expect($valid((string) Str::uuid()))->toBeFalse();     // a well-formed code that is not a ticket
    expect($valid('hello'))->toBeFalse();
    expect($valid('abcdef1'))->toBeFalse();                // 7 characters
    expect($valid('abcdef123'))->toBeFalse();              // 9 characters
    expect($valid('ABCDEFGH'))->toBeFalse();               // not hexadecimal
});
