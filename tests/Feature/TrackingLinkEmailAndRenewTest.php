<?php

use App\Mail\TicketTrackingMail;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// L-43: the buyer gets the tracking link of each ticket by email after paying, and a passenger can replace a link that
// got out (the old link stops working at once). Fare numbers are SAMPLE test values.

const TLK_SECRET = 'whsk_tl_secret';

function tlkStaff(string $role): User
{
    $user = User::create(['username' => 'tl'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Tl '.ucfirst($role), 'phone_num' => '09170000121', 'address' => 'x']);

    return $user->fresh();
}

function tlkPassenger(string $name = 'Rider One'): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => 'rider'.Str::random(5).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => $name, 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
}

function tlkFixture(): array
{
    $operator = tlkStaff('operator');
    $route = Route::create(['route_name' => 'Davao - Tagum', 'origin' => 'A', 'destination' => 'E']);
    $stops = [];
    foreach ([['A', 0, 'terminal'], ['B', 10, 'stop'], ['C', 20, 'pass_through'], ['D', 30, 'stop'], ['E', 40, 'terminal']] as $i => [$name, $km, $type]) {
        $stop = Stop::create(['stop_name' => "Stop $name", 'latitude' => 7.0 + $km / 100, 'longitude' => 125.0, 'municipality' => 'Mati City']);
        RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $km, 'stop_type' => $type, 'scheduled_offset_min' => $km]);
        $stops[$name] = $stop;
    }
    $fleet = Fleet::create(['company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'TL-'.fake()->unique()->numerify('####'), 'bus_company_name' => 'Co T', 'body_no' => '3', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);
    FareRule::create(['fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated', 'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'note' => 'SAMPLE']);

    return compact('trip', 'stops');
}

/** Checks out one seated ticket B -> D (as the passenger, or as a guest with an email) and pays it by the signed webhook. */
function tlkBuy($test, array $f, ?PassengerUser $pax = null, string $guestEmail = 'guest@example.com', int $quantity = 1, array $extra = []): Payment
{
    $item = ['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'origin_stop_id' => $f['stops']['B']->stop_id, 'destination_stop_id' => $f['stops']['D']->stop_id];
    $call = $pax ? $test->actingAs($pax->user) : $test;
    $paymentId = $call->postJson('/api/checkout', array_filter([
        'booking_date' => '2026-10-05', 'payment_channel' => 'gcash', 'guest_email' => $pax ? null : $guestEmail,
        'items' => array_fill(0, $quantity, $item),
    ]) + $extra)->assertSuccessful()->json('data.payment.payment_id');
    $payment = Payment::findOrFail($paymentId);

    tlkPay($test, $payment);

    return $payment->fresh();
}

function tlkPay($test, Payment $payment): void
{
    $payload = json_encode(['data' => ['id' => 'evt_'.Str::random(8), 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => ['id' => $payment->gateway_reference, 'type' => 'checkout_session', 'attributes' => []], 'created_at' => time(), 'updated_at' => time()]]]);
    $ts = time();
    $sig = hash_hmac('sha256', $ts.'.'.$payload, TLK_SECRET);
    $test->call('POST', '/api/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te={$sig},li="], $payload)->assertOk();
}

/** A request as this user, or as nobody. A test keeps one app, so the auth guards are reset first, as a new real request would find them. */
function tlkAs($test, ?User $user = null)
{
    app('auth')->forgetGuards();

    return $user ? $test->actingAs($user) : $test;
}

function tlkToken(Ticket $ticket): string
{
    return (string) $ticket->fresh()->tracking_token_enc;   // the model decrypts it
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 07:00:00', 'Asia/Manila'));
    Cache::flush();
    Mail::fake();
    config([
        'services.paymongo.secret_key' => 'sk_test_dummy', 'services.paymongo.webhook_secret' => TLK_SECRET,
        'app.frontend_url' => 'https://app.example.test', 'mail.intercept_email' => null,
    ]);
    Http::fake(['api.paymongo.com/*' => fn () => Http::response(['data' => ['id' => 'cs_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/x', 'payment_intent' => ['id' => 'pi_'.Str::random(8)]]]])]);
});
afterEach(fn () => Carbon::setTestNow());

// ------------------------------------------------------------ the email

it('emails a passenger the tracking link of the ticket they bought', function () {
    $f = tlkFixture();
    $pax = tlkPassenger();
    $payment = tlkBuy($this, $f, $pax);
    $ticket = Ticket::where('payment_id', $payment->payment_id)->firstOrFail();

    Mail::assertQueued(TicketTrackingMail::class, function (TicketTrackingMail $mail) use ($pax, $ticket) {
        return $mail->hasTo($pax->user->email)
            && $mail->name === 'Rider One'
            && count($mail->items) === 1
            && $mail->items[0]['link'] === 'https://app.example.test/track/'.tlkToken($ticket)
            && $mail->items[0]['route'] === 'Davao - Tagum'
            && $mail->items[0]['date'] === '2026-10-05'
            && $mail->items[0]['time'] === '08:00'
            && $mail->items[0]['from'] === 'Stop B'
            && $mail->items[0]['to'] === 'Stop D';
    });
    Mail::assertQueuedCount(1);
});

it('emails a guest at the address they paid with, with one link for the tickets on one bus (§4.10)', function () {
    $f = tlkFixture();
    $payment = tlkBuy($this, $f, null, 'walkin@example.com', 2);
    $first = Ticket::where('payment_id', $payment->payment_id)->orderBy('ticket_id')->firstOrFail();

    $html = null;
    Mail::assertQueued(TicketTrackingMail::class, function (TicketTrackingMail $mail) use ($first, &$html) {
        $html = $mail->render();

        return $mail->hasTo('walkin@example.com')
            && count($mail->items) === 1
            && $mail->items[0]['link'] === 'https://app.example.test/track/'.tlkToken($first)
            && $mail->items[0]['tickets'] === '2';
    });
    expect($html)->toContain('2 tickets, one bus')->and(substr_count($html, 'Track this bus'))->toBe(1);
});

it('sends to the redirect address in the demo setup and says which account it was for', function () {
    config(['mail.intercept_email' => 'owner@example.com']);
    $f = tlkFixture();
    $pax = tlkPassenger();
    tlkBuy($this, $f, $pax);

    Mail::assertQueued(TicketTrackingMail::class, fn (TicketTrackingMail $mail) => $mail->hasTo('owner@example.com') && $mail->redirected && $mail->accountEmail === $pax->user->email);
});

it('sends it once, however many times the paid webhook is delivered', function () {
    $f = tlkFixture();
    $payment = tlkBuy($this, $f, tlkPassenger());

    tlkPay($this, $payment);
    tlkPay($this, $payment);

    Mail::assertQueuedCount(1);
});

it('never lets a failed email undo or delay the payment', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('mail server down'));
    $f = tlkFixture();
    $payment = tlkBuy($this, $f, tlkPassenger());

    expect($payment->status)->toBe('paid');
    expect(Ticket::where('payment_id', $payment->payment_id)->count())->toBe(1);
});

// §4.10 (2026-10-26): the email carries the boarding QR as an image (drawn on the server), never the ticket id as text.
it('carries a single ticket boarding QR as an image, never the ticket id as text', function () {
    $f = tlkFixture();
    $payment = tlkBuy($this, $f, tlkPassenger());
    $ticket = Ticket::where('payment_id', $payment->payment_id)->firstOrFail();

    $html = null;
    $qrCodes = null;
    Mail::assertQueued(TicketTrackingMail::class, function (TicketTrackingMail $mail) use (&$html, &$qrCodes) {
        $html = $mail->render();
        $qrCodes = $mail->qrCodes;

        return true;
    });

    expect($qrCodes)->toHaveCount(1)->and($qrCodes[0]['label'])->toBe('Your ticket');
    expect(bin2hex(substr(base64_decode($qrCodes[0]['png']), 0, 8)))->toBe('89504e470d0a1a0a'); // a real PNG image
    expect($html)->toContain('Your boarding QR')->toContain('keep this email to yourself')->toContain('/track/'.tlkToken($ticket));
    expect($html)->not->toContain($ticket->ticket_uuid);
});

it('sends one group QR for a purchase of several tickets by default', function () {
    $f = tlkFixture();
    $payment = tlkBuy($this, $f, null, 'walkin@example.com', 3);

    expect($payment->email_group_qr)->toBeTrue();
    Mail::assertQueued(TicketTrackingMail::class, fn (TicketTrackingMail $mail) => count($mail->qrCodes) === 1
        && $mail->qrCodes[0]['label'] === 'Group QR: boards all 3 tickets in one scan'
        && str_contains($mail->render(), 'One scan boards everyone'));
});

it('sends one QR per ticket when the buyer unticks the group QR', function () {
    $f = tlkFixture();
    $payment = tlkBuy($this, $f, null, 'walkin@example.com', 2, ['email_group_qr' => false]);

    expect($payment->email_group_qr)->toBeFalse();
    Mail::assertQueued(TicketTrackingMail::class, fn (TicketTrackingMail $mail) => collect($mail->qrCodes)->pluck('label')->all() === ['Ticket 1 of 2', 'Ticket 2 of 2']
        && count($mail->items) === 1); // still one tracking link
});

it('sends nothing for a payment that could not become tickets', function () {
    $f = tlkFixture();
    $pax = tlkPassenger();
    $payment = Payment::create(['amount' => 47, 'payment_created' => now(), 'transaction_reference' => 'TXN-NONE', 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'needs_refund', 'is_valid' => false, 'payment_uuid' => (string) Str::uuid(), 'gateway_reference' => 'cs_none', 'items_payload' => [['trip_id' => $f['trip']->trip_id, 'seat_type' => 'seated', 'passenger_id' => $pax->passenger_id]]]);

    tlkPay($this, $payment);

    Mail::assertNothingQueued();
});

// ------------------------------------------------------------ replacing a leaked link

it('replaces the link: the new one works and the old one stops working at once', function () {
    $f = tlkFixture();
    $pax = tlkPassenger();
    $ticket = Ticket::where('payment_id', tlkBuy($this, $f, $pax)->payment_id)->firstOrFail();
    $old = tlkToken($ticket);
    $this->getJson("/api/track/$old")->assertOk();

    $new = $this->actingAs($pax->user)->postJson("/api/passengers/tickets/{$ticket->ticket_uuid}/tracking-link")->assertOk()->json('data.tracking_token');

    expect($new)->not->toBe($old)->and(strlen($new))->toBe(43);
    expect(tlkToken($ticket))->toBe($new);
    $this->getJson("/api/track/$old")->assertNotFound();
    $this->getJson("/api/track/$new")->assertOk();
    expect(DB::table('audit_logs')->where('action', 'ticket.tracking_link_renewed')->where('subject_id', $ticket->ticket_id)->exists())->toBeTrue();
});

it('lets only the owner replace a link, and never a guest ticket\'s', function () {
    $f = tlkFixture();
    $pax = tlkPassenger();
    $stranger = tlkPassenger('Someone Else');
    // The guest buys first: a test keeps the last signed-in user, so a guest purchase must come before any actingAs.
    $guest = Ticket::where('payment_id', tlkBuy($this, $f)->payment_id)->firstOrFail();
    $mine = Ticket::where('payment_id', tlkBuy($this, $f, $pax)->payment_id)->firstOrFail();
    $before = [tlkToken($mine), tlkToken($guest)];

    tlkAs($this, $stranger->user)->postJson("/api/passengers/tickets/{$mine->ticket_uuid}/tracking-link")->assertForbidden();
    tlkAs($this, $pax->user)->postJson("/api/passengers/tickets/{$guest->ticket_uuid}/tracking-link")->assertForbidden();
    tlkAs($this)->postJson("/api/passengers/tickets/{$mine->ticket_uuid}/tracking-link")->assertUnauthorized();

    expect([tlkToken($mine), tlkToken($guest)])->toBe($before);
});

it('does not replace the link of a ticket that can no longer be tracked', function () {
    $f = tlkFixture();
    $pax = tlkPassenger();
    $ticket = Ticket::where('payment_id', tlkBuy($this, $f, $pax)->payment_id)->firstOrFail();
    $ticket->update(['status' => 'cancelled']);
    $before = tlkToken($ticket);

    $this->actingAs($pax->user)->postJson("/api/passengers/tickets/{$ticket->ticket_uuid}/tracking-link")->assertStatus(422);

    expect(tlkToken($ticket))->toBe($before);
});
