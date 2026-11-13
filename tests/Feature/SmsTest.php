<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\SmsLog;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Services\Sms\IprogSmsGateway;
use App\Services\Sms\SmsNotifier;
use App\Services\Sms\SmsService;
use App\Services\TripCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 64 (user, 2026-11-13): texts through IPROG SMS. Off unless switched on; one switch per kind of text; every
// text recorded; never to a number that is not a Philippine mobile; one credit (160 characters) at most.
// SAMPLE names, numbers and tokens; IPROG is faked (no text leaves the test).

beforeEach(function () {
    config(['sms.enabled' => true, 'sms.api_token' => 'tok_sample', 'sms.intercept_to' => null]);
    // What the fake IPROG answers; a test changes it with smsReply().
    $GLOBALS['smsReply'] = ['status' => 200, 'message' => 'SMS successfully queued for delivery.', 'message_id' => 'iSms-ABC123'];
    Http::fake(['www.iprogsms.com/*' => fn () => Http::response($GLOBALS['smsReply'])]);
});

function smsReply(array $body): void
{
    $GLOBALS['smsReply'] = $body;
}

function smsPassenger(string $phone = '+639171234567'): User
{
    $user = User::create(['username' => 'sms'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);
    PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Sam Sample', 'phone_num' => $phone, 'address' => 'x', 'birthdate' => '2000-01-01']);

    return $user->fresh();
}

/** A trip and a paid purchase of $count tickets on it. */
function smsPurchase(?User $passenger, array $payment = [], int $count = 1, string $ticketStatus = 'valid'): array
{
    $operator = User::create(['username' => 'smo'.Str::random(6), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => 'operator']);
    StaffUser::create(['user_id' => $operator->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone_num' => '09170000001', 'address' => 'x']);
    $route = Route::create(['route_name' => 'Sample Route', 'origin' => 'A', 'destination' => 'B']);
    $a = Stop::create(['stop_name' => 'Stop A', 'latitude' => 7.0, 'longitude' => 125.0]);
    $b = Stop::create(['stop_name' => 'Stop B', 'latitude' => 7.1, 'longitude' => 125.0]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $a->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'stop']);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $b->stop_id, 'stop_order' => 2, 'distance_from_origin_km' => 10, 'stop_type' => 'stop']);
    $fleet = Fleet::create(['company_user_id' => $operator->fresh()->companyProfile->company_user_id, 'plate_number' => 'SM-'.fake()->unique()->numerify('####'), 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fr = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fr->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-11-20', 'departure_time' => '08:30', 'trip_type' => 'one_way', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    $pay = Payment::create(array_merge(['amount' => 30 * $count, 'payment_created' => now(), 'transaction_reference' => 'TXN-SMS'.Str::upper(Str::random(6)), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []], $payment));
    $tickets = collect(range(1, $count))->map(fn () => Ticket::create([
        'fleet_route_id' => $fr->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $pay->payment_id, 'passenger_id' => $passenger?->passengerProfile?->passenger_id,
        'ticket_uuid' => (string) Str::uuid(), 'status' => $ticketStatus, 'amount' => 30, 'distance_km' => 10, 'seat_type' => 'seated', 'origin_stop_id' => $a->stop_id, 'destination_stop_id' => $b->stop_id,
    ]));

    return compact('trip', 'pay', 'tickets');
}

it('sends nothing while SMS is off, or while that kind of text is switched off', function () {
    config(['sms.enabled' => false]);
    expect(app(SmsService::class)->send('login_code', '09171234567', 'x'))->toBeNull();
    config(['sms.enabled' => true, 'sms.events.login_code' => false]);
    expect(app(SmsService::class)->send('login_code', '09171234567', 'x'))->toBeNull();

    expect(SmsLog::count())->toBe(0);
    Http::assertNothingSent();
});

it('sends a login code to the passenger\'s phone in IPROG\'s format and records it as sent', function () {
    $user = smsPassenger('+639171234567');
    app(SmsNotifier::class)->loginCode($user, '482913', 3);

    $log = SmsLog::sole();
    expect($log)->status->toBe('sent')->phone->toBe('+639171234567')->provider_message_id->toBe('iSms-ABC123');
    Http::assertSent(fn ($request) => $request['phone_number'] === '09171234567'
        && $request['api_token'] === 'tok_sample'
        && str_contains($request['message'], '482913')
        && str_contains($request->url(), '/api/v1/sms_messages'));
});

it('records a number it cannot text as skipped, and an IPROG refusal as failed with its answer', function () {
    expect(app(SmsService::class)->send('test', null, 'x')->status)->toBe('skipped');
    expect(app(SmsService::class)->send('test', '+12025550123', 'x')->status)->toBe('skipped'); // not a PH mobile

    smsReply(['status' => 500, 'message' => 'Invalid Token']);
    $log = app(SmsService::class)->send('test', '09171234567', 'x')->refresh();
    expect($log)->status->toBe('failed')->error->toBe('IPROG: Invalid Token');
});

it('sends every text to the test number when one is set, and never more than one credit', function () {
    config(['sms.intercept_to' => '09998887777']);
    $log = app(SmsService::class)->send('test', '09171234567', str_repeat('a', 300))->refresh();

    expect($log->phone)->toBe('+639998887777')
        ->and(mb_strlen($log->message))->toBe(160);
});

it('texts the buyer the Ticket IDs when an online payment is confirmed', function () {
    $p = smsPurchase(smsPassenger(), [], 2);
    app(SmsNotifier::class)->paymentConfirmed($p['pay']);

    $text = SmsLog::sole()->message;
    expect($text)->toContain('2 tickets Stop A to Stop B on Nov 20 08:30')
        ->toContain(strtoupper(substr($p['tickets'][0]->ticket_uuid, 0, 8)))
        ->and(mb_strlen($text))->toBeLessThanOrEqual(160);
});

it('texts a cash passenger who gave a mobile number, and nobody when none was given', function () {
    $given = smsPurchase(null, ['payment_method' => 'cash', 'payment_channel' => 'onsite', 'traveller_contact' => '+639175550000']);
    $none = smsPurchase(null, ['payment_method' => 'cash', 'payment_channel' => 'onsite']);
    app(SmsNotifier::class)->cashTicket($given['pay']);
    app(SmsNotifier::class)->cashTicket($none['pay']);

    expect(SmsLog::sole())->phone->toBe('+639175550000')->message->toContain('Ticket ID:');
});

it('tells a cancelled trip\'s buyers what happens next: online fares refunded, cash tickets choose', function () {
    $online = smsPurchase(smsPassenger('+639171110000'), [], 1, TripCancellationService::AWAITING_CHOICE);
    app(SmsNotifier::class)->tripCancelled($online['trip'], null, true);
    expect(SmsLog::sole()->message)->toContain('was cancelled')->toContain('refunded to your e-wallet');

    $cash = smsPurchase(smsPassenger('+639172220000'), ['payment_method' => 'cash', 'payment_channel' => 'onsite'], 1, TripCancellationService::AWAITING_CHOICE);
    app(SmsNotifier::class)->tripCancelled($cash['trip'], null, true);
    expect(SmsLog::latest('id')->first()->message)->toContain('rebook or get a refund');
});

it('texts the refund and the Operator\'s "Tell passengers" notice', function () {
    $p = smsPurchase(smsPassenger());
    app(SmsNotifier::class)->refundCompleted($p['pay'], 45.5);
    expect(SmsLog::sole()->message)->toContain('PHP 45.50 was refunded to your GCash');

    expect(app(SmsNotifier::class)->tellPassengers((int) $p['trip']->trip_id, 'Sample Route: your bus has a breakdown and will be late.'))->toBe(1);
    expect(SmsLog::latest('id')->first()->message)->toStartWith('SmartTransit: Sample Route: your bus has a breakdown');
});

it('reads the credits left and writes the local number IPROG documents', function () {
    smsReply(['status' => 200, 'load_balance' => 4]);
    expect(app(IprogSmsGateway::class)->credits())->toBe(4.0)
        ->and(IprogSmsGateway::localNumber('+639171234567'))->toBe('09171234567');
});
