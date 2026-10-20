<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The Operator's list of payments that took money but could not become tickets (`needs_refund`), and the record that the
// Operator refunded them. An Operator sees only payments for trips of their own.

function nrStaff(string $role): User
{
    $user = User::create(['username' => 'nr'.Str::random(8), 'email' => Str::random(8).'@example.com', 'password' => bcrypt('password123'), 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Nr '.ucfirst($role), 'phone_num' => '09170000081', 'address' => 'x']);

    return $user->fresh();
}

/** An Operator with one trip on one route. */
function nrOperator(string $routeName = 'Davao - Tagum'): array
{
    $operator = nrStaff('operator');
    $route = Route::create(['route_name' => $routeName, 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id, 'plate_number' => 'NR-'.fake()->unique()->numerify('####'),
        'bus_company_name' => 'Co N', 'body_no' => '1', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $operator->companyProfile->company_user_id, 'trip_date' => '2026-10-05', 'departure_time' => '08:00',
        'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0,
    ]);

    return compact('operator', 'trip');
}

function nrPassenger(): PassengerUser
{
    $user = User::create(['username' => 'pax'.Str::random(6), 'email' => 'rider'.Str::random(5).'@example.com', 'password' => bcrypt('password123'), 'role' => 'passenger']);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'Rider One', 'phone_num' => '09170000002', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => 0]);
}

function nrPayment(Trip $trip, string $status = 'needs_refund', array $overrides = []): Payment
{
    $pax = $overrides['_passenger'] ?? null;
    unset($overrides['_passenger']);

    return Payment::create(array_merge([
        'amount' => 47, 'payment_created' => now(), 'transaction_reference' => 'TXN-'.strtoupper(Str::random(10)),
        'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => $status, 'is_valid' => false,
        'payment_uuid' => (string) Str::uuid(), 'gateway_reference' => 'cs_'.Str::random(10), 'gateway_payment_id' => 'pay_'.Str::random(10),
        'guest_email' => $pax ? null : 'guest@example.com',
        'items_payload' => [['trip_id' => $trip->trip_id, 'seat_type' => 'seated', 'amount' => 47, 'passenger_id' => $pax?->passenger_id]],
    ], $overrides));
}

it('lists the payments that need a refund with who paid, for which trip, and the PayMongo references', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    $pax = nrPassenger();
    $mine = nrPayment($trip, 'needs_refund', ['_passenger' => $pax]);

    $response = $this->actingAs($operator)->getJson('/api/operator/payments/needs-refund')->assertOk();
    $row = $response->json('data.data.0');

    expect($response->json('data.data'))->toHaveCount(1);
    expect($row['payment_id'])->toBe($mine->payment_id);
    expect($row['transaction_reference'])->toBe($mine->transaction_reference);
    expect($row['amount'])->toEqual(47);
    expect($row['status'])->toBe('needs_refund');
    expect($row['payer'])->toMatchArray(['is_guest' => false, 'name' => 'Rider One', 'email' => $pax->user->email]);
    expect($row['trips'][0])->toMatchArray(['trip_date' => '2026-10-05', 'route_name' => 'Davao - Tagum', 'tickets' => 1]);
    expect($row['gateway_payment_id'])->toBe($mine->gateway_payment_id);
    expect($row['reason'])->toContain('no ticket could be issued');
    expect($response->json('data.meta.counts'))->toMatchArray(['needs_refund' => 1, 'refunded' => 0]);
});

it('shows a guest payment by the email it was made with', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    nrPayment($trip);

    $row = $this->actingAs($operator)->getJson('/api/operator/payments/needs-refund')->assertOk()->json('data.data.0');

    expect($row['payer'])->toMatchArray(['is_guest' => true, 'email' => 'guest@example.com']);
});

it('loads the Operator payment queue when PayMongo is not configured', function () {
    ['operator' => $operator] = nrOperator();
    config(['services.paymongo.secret_key' => null]);

    $this->actingAs($operator)
        ->getJson('/api/operator/payments/needs-refund')
        ->assertOk()
        ->assertJsonPath('data.data', []);
});

it('shows only needs-refund payments by default: not paid ones, not refunded ones, not another Operator\'s', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    ['trip' => $otherTrip] = nrOperator('Other route');
    $mine = nrPayment($trip);
    nrPayment($trip, 'paid', ['is_valid' => true]);
    nrPayment($trip, 'expired');
    nrPayment($trip, 'refunded');
    nrPayment($otherTrip);

    $ids = collect($this->actingAs($operator)->getJson('/api/operator/payments/needs-refund')->assertOk()->json('data.data'))->pluck('payment_id')->all();

    expect($ids)->toBe([$mine->payment_id]);
});

it('filters by refunded and all, and counts both', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    $waiting = nrPayment($trip);
    $done = nrPayment($trip, 'refunded', ['refund_note' => 'rf_abc123', 'refunded_at' => now()]);

    $refunded = $this->actingAs($operator)->getJson('/api/operator/payments/needs-refund?status=refunded')->assertOk();
    $all = $this->actingAs($operator)->getJson('/api/operator/payments/needs-refund?status=all')->assertOk();

    expect(collect($refunded->json('data.data'))->pluck('payment_id')->all())->toBe([$done->payment_id]);
    expect($refunded->json('data.data.0.refund_note'))->toBe('rf_abc123');
    expect($all->json('data.data'))->toHaveCount(2);
    expect($all->json('data.meta.counts'))->toMatchArray(['needs_refund' => 1, 'refunded' => 1]);
    expect($waiting->payment_id)->not->toBe($done->payment_id);
});

it('lists unknown checkout outcomes only for the Operator who owns their trip', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    ['trip' => $otherTrip] = nrOperator('Other route');
    $unknown = nrPayment($trip, 'pending', ['idempotency_state' => 'unknown', 'hold_expires_at' => now()->subMinute()]);
    nrPayment($otherTrip, 'pending', ['idempotency_state' => 'unknown', 'hold_expires_at' => now()->subMinute()]);

    $response = $this->actingAs($operator)->getJson('/api/operator/payments/needs-refund?status=outcome_unknown')->assertOk();

    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0'))->toMatchArray([
        'payment_id' => $unknown->payment_id,
        'status' => 'pending',
        'reconciliation_status' => 'outcome_unknown',
    ]);
    expect($response->json('data.meta.counts.outcome_unknown'))->toBe(1);
});

it('releases an unknown checkout only after an Operator records that PayMongo shows it was not charged', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    $trip->update(['current_seated_capacity' => 1, 'total_occupancy' => 1]);
    $payment = nrPayment($trip, 'pending', [
        'idempotency_state' => 'unknown',
        'hold_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/checkout-reconciled-not-paid", [
        'note' => 'Verified no charge in PayMongo dashboard',
    ])->assertOk()->assertJsonPath('data.status', 'failed');

    expect($payment->fresh()->idempotency_state)->toBe('failed');
    expect((int) $trip->fresh()->current_seated_capacity)->toBe(0);
    expect((int) $trip->fresh()->total_occupancy)->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'payment.checkout_reconciled_not_charged')
        ->where('subject_id', $payment->payment_id)->exists())->toBeTrue();

    $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/checkout-reconciled-not-paid", [
        'note' => 'Verified no charge in PayMongo dashboard',
    ])->assertStatus(422);
    expect((int) $trip->fresh()->current_seated_capacity)->toBe(0);
});

it('records a refund: the payment becomes refunded with who, when and the note, and it is audit logged', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    $payment = nrPayment($trip);

    $row = $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", ['note' => 'PayMongo refund rf_123abc'])
        ->assertOk()->json('data');

    expect($row['status'])->toBe('refunded');
    expect($row['refund_note'])->toBe('PayMongo refund rf_123abc');
    expect($row['refunded_by_name'])->toBe('Nr Operator');
    expect($row['refunded_at'])->not->toBeNull();

    $fresh = $payment->fresh();
    expect($fresh->status)->toBe('refunded')->and((int) $fresh->refunded_by_user_id)->toBe((int) $operator->user_id);
    expect(DB::table('audit_logs')->where('action', 'payment.refund_recorded')->where('subject_id', $payment->payment_id)->exists())->toBeTrue();
});

it('records a refund only once, and only for a payment that is waiting for one', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    $payment = nrPayment($trip);
    $paid = nrPayment($trip, 'paid', ['is_valid' => true]);

    $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", ['note' => 'rf_first'])->assertOk();
    $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", ['note' => 'rf_second'])->assertStatus(422);
    $this->actingAs($operator)->postJson("/api/operator/payments/{$paid->payment_id}/refunded", ['note' => 'rf_paid'])->assertStatus(422);

    expect($payment->fresh()->refund_note)->toBe('rf_first');
    expect($paid->fresh()->status)->toBe('paid');
});

it('needs a note', function () {
    ['operator' => $operator, 'trip' => $trip] = nrOperator();
    $payment = nrPayment($trip);

    $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", [])->assertStatus(422)->assertJsonValidationErrors('note');
    $this->actingAs($operator)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", ['note' => 'x'])->assertStatus(422);

    expect($payment->fresh()->status)->toBe('needs_refund');
});

it('treats another Operator\'s payment as not found', function () {
    ['trip' => $trip] = nrOperator();
    ['operator' => $stranger] = nrOperator('Other route');
    $payment = nrPayment($trip);

    $this->actingAs($stranger)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", ['note' => 'rf_nope'])->assertNotFound();

    expect($payment->fresh()->status)->toBe('needs_refund');
});

it('is for Operators only', function () {
    ['trip' => $trip] = nrOperator();
    $payment = nrPayment($trip);

    $this->getJson('/api/operator/payments/needs-refund')->assertUnauthorized();
    foreach (['driver', 'conductor', 'passenger'] as $role) {
        $user = $role === 'passenger' ? nrPassenger()->user : nrStaff($role);
        $this->actingAs($user)->getJson('/api/operator/payments/needs-refund')->assertForbidden();
        $this->actingAs($user)->postJson("/api/operator/payments/{$payment->payment_id}/refunded", ['note' => 'rf_x'])->assertForbidden();
    }

    expect($payment->fresh()->status)->toBe('needs_refund');
});
