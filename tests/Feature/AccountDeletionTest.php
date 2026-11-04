<?php

use App\Mail\AccountDeletionRequestedMail;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-05: a passenger deletes only their own account; signing in within 7 days cancels it; after that the
// personal data is erased and the money records stay without a name (AccountDeletionService).

beforeEach(function () {
    Mail::fake();
    config(['mail.intercept_email' => null, 'app.rate_limiting_enabled' => false]);
    Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'Asia/Manila'));
});
afterEach(fn () => Carbon::setTestNow());

function adlPassenger(string $email = 'adl.pax@example.com', int $points = 25): PassengerUser
{
    $user = User::create(['username' => Str::before($email, '@'), 'email' => $email, 'password' => 'right-password-1', 'role' => 'passenger']);
    $user->forceFill(['email_verified_at' => now()])->save(); // verified, extra login code off: sign-in gives a token at once

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Ada Lim', 'phone_num' => '09171234567', 'address' => 'Panabo City', 'birthdate' => '1999-05-05', 'gender' => 'female', 'reward_points' => $points]);
}

function adlDelete($test, PassengerUser $pax, string $password = 'right-password-1')
{
    app('auth')->forgetGuards();

    return $test->actingAs($pax->user, 'sanctum')->deleteJson('/api/passengers/account', ['password' => $password, 'confirm' => true]);
}

/** A paid ticket for this passenger on a trip with the given status. */
function adlTicket(PassengerUser $pax, string $ticketStatus, string $tripStatus): Ticket
{
    $operator = User::create(['username' => 'adlop'.Str::random(4), 'email' => 'adl.op.'.Str::random(4).'@example.com', 'password' => 'x-password-1', 'role' => 'operator']);
    $company = StaffUser::create(['user_id' => $operator->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone_num' => '0917', 'address' => 'x']);
    $route = Route::create(['route_name' => 'A - B', 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => $company->company_user_id, 'plate_number' => 'ADL-'.Str::random(3), 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'bus_company_name' => 'Adl Lines', 'body_no' => 'B-'.Str::random(3)]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $company->company_user_id, 'trip_date' => '2026-11-06', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => $tripStatus, 'current_seated_capacity' => 1, 'current_standing_capacity' => 0, 'total_occupancy' => 1]);
    $fleetRouteId = $fleetRoute->fleet_route_id;
    $tripId = $trip->trip_id;
    $payment = Payment::create(['amount' => 50, 'payment_created' => now(), 'transaction_reference' => 'TXN-ADL-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);

    return Ticket::create(['fleet_route_id' => $fleetRouteId, 'trip_id' => $tripId, 'payment_id' => $payment->payment_id, 'passenger_id' => $pax->passenger_id, 'ticket_uuid' => (string) Str::uuid(), 'status' => $ticketStatus, 'amount' => 50, 'seat_type' => 'seated', 'distance_km' => 10, 'rounding_mode' => 'nearest']);
}

function adlSignIn($test)
{
    app('auth')->forgetGuards();

    return $test->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => 'adl.pax@example.com', 'password' => 'right-password-1']);
}

it('deletes only the signed-in passenger, ends every session and emails the date', function () {
    $pax = adlPassenger();
    $other = adlPassenger('adl.other@example.com');
    $pax->user->createToken('phone');

    $response = adlDelete($this, $pax)->assertSuccessful();

    expect($response->json('data.deletes_on'))->toBe('2026-11-12')
        ->and($pax->user->fresh()->deletion_requested_at)->not->toBeNull()
        ->and($pax->user->tokens()->count())->toBe(0)
        ->and($other->user->fresh()->deletion_requested_at)->toBeNull();
    Mail::assertQueued(AccountDeletionRequestedMail::class, fn ($mail) => $mail->hasTo('adl.pax@example.com'));
});

it('asks for the right password and a ticked box', function () {
    $pax = adlPassenger();

    adlDelete($this, $pax, 'wrong-password')->assertStatus(422)->assertJsonValidationErrors(['password']);
    app('auth')->forgetGuards();
    $this->actingAs($pax->user, 'sanctum')->deleteJson('/api/passengers/account', ['password' => 'right-password-1'])
        ->assertStatus(422)->assertJsonValidationErrors(['confirm']);

    expect($pax->user->fresh()->deletion_requested_at)->toBeNull();
});

it('has no way to name another account, and staff cannot use it', function () {
    $operator = User::create(['username' => 'adlstaff', 'email' => 'adl.staff@example.com', 'password' => 'right-password-1', 'role' => 'operator']);

    $this->actingAs($operator, 'sanctum')->deleteJson('/api/passengers/account', ['password' => 'right-password-1', 'confirm' => true])->assertStatus(403);
    app('auth')->forgetGuards();
    $this->deleteJson('/api/passengers/'.Str::uuid())->assertStatus(404);
});

it('refuses while a ticket is still in use, and allows it once the trip is over', function (string $ticketStatus, string $tripStatus, bool $blocked) {
    $pax = adlPassenger();
    adlTicket($pax, $ticketStatus, $tripStatus);

    $response = adlDelete($this, $pax);

    $blocked
        ? $response->assertStatus(422)->assertJsonValidationErrors(['account'])
        : $response->assertSuccessful();
})->with([
    'valid ticket, upcoming trip' => ['valid', 'scheduled', true],
    'boarded, trip under way' => ['boarded', 'in-progress', true],
    'waiting for a cancelled-trip choice' => ['trip_cancelled', 'cancelled', true],
    'refund in progress' => ['refund_pending', 'cancelled', true],
    'ridden and finished' => ['alighted', 'completed', false],
    'valid ticket, trip completed' => ['valid', 'completed', false],
]);

it('cancels the deletion when the passenger signs in again within the grace days', function () {
    $pax = adlPassenger();
    adlDelete($this, $pax)->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Manila'));
    adlSignIn($this)->assertSuccessful()->assertJsonPath('data.deletion_cancelled', true);

    Carbon::setTestNow(Carbon::parse('2026-11-20 03:00:00', 'Asia/Manila'));
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();

    expect($pax->user->fresh()->deletion_requested_at)->toBeNull()
        ->and($pax->fresh()->name)->toBe('Ada Lim');
});

it('erases the personal data after the grace days and keeps the money records without a name', function () {
    $pax = adlPassenger();
    $ticket = adlTicket($pax, 'alighted', 'completed');
    adlDelete($this, $pax)->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-11-11 02:00:00', 'Asia/Manila')); // 6 days later: too early
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();
    expect($pax->fresh()->name)->toBe('Ada Lim');

    Carbon::setTestNow(Carbon::parse('2026-11-12 10:30:00', 'Asia/Manila'));
    $this->artisan('accounts:anonymize-deleted')->assertSuccessful();

    $user = $pax->user->fresh();
    $profile = $pax->fresh();
    expect($user->email)->toBe('deleted-'.$user->user_id.'@deleted.invalid')
        ->and($user->anonymized_at)->not->toBeNull()
        ->and($profile->name)->toBe('Deleted passenger')
        ->and($profile->phone_num)->toBeNull()
        ->and($profile->address)->toBeNull()
        ->and($profile->getRawOriginal('birth_date'))->toBeNull()
        ->and($profile->gender)->toBeNull()
        ->and((float) $profile->reward_points)->toBe(0.0)
        ->and(Ticket::find($ticket->ticket_id)->passenger_id)->toBe($pax->passenger_id);

    adlSignIn($this)->assertStatus(422);
});
