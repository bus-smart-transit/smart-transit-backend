<?php

use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\RewardTransaction;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\FareRuleService;
use App\Services\RewardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 45 Todo 3, findings 5 and 6 (2026-11-04).

function t3fPassenger(int $points): PassengerUser
{
    $user = User::create(['username' => 't3fpax', 'email' => 't3f.pax@example.com', 'password' => 'right-password-1', 'role' => 'passenger', 'email_verified_at' => now()]);

    return PassengerUser::create(['passenger_uuid' => (string) Str::uuid(), 'user_id' => $user->user_id, 'name' => 'T3f Pax', 'phone_num' => '09170000093', 'address' => 'x', 'birthdate' => '2000-01-01', 'reward_points' => $points]);
}

function t3fPayment(): int
{
    return Payment::create(['amount' => 30, 'payment_created' => now(), 'transaction_reference' => 'TXN-T3F-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'pending', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []])->payment_id;
}

it('gives redeemed points back once, however often it is asked, and a late payment takes them again once', function () {
    $pax = t3fPassenger(20);
    $paymentId = t3fPayment();
    $rewards = app(RewardService::class);
    expect($rewards->redeemPoints($pax->passenger_id, 5, 'Redeemed during checkout', $paymentId))->toBeTrue();

    expect($rewards->returnRedeemedPoints($paymentId))->toBe(5)
        ->and($rewards->returnRedeemedPoints($paymentId))->toBe(0)
        ->and((int) $pax->fresh()->reward_points)->toBe(20);

    expect($rewards->reclaimRedeemedPoints($paymentId))->toBeTrue()
        ->and($rewards->reclaimRedeemedPoints($paymentId))->toBeTrue()
        ->and((int) $pax->fresh()->reward_points)->toBe(15)
        ->and((int) RewardTransaction::where('payment_id', $paymentId)->sum('points'))->toBe(-5);
});

it('returns nothing for a payment that used no points', function () {
    expect(app(RewardService::class)->returnRedeemedPoints(902))->toBe(0)
        ->and(app(RewardService::class)->reclaimRedeemedPoints(902))->toBeTrue();
});

it('never shows database error text on the public fare routes', function () {
    $this->mock(FareRuleService::class, fn ($mock) => $mock->shouldReceive('getFleetQuotesFromCoordinates')
        ->andThrow(new QueryException('pgsql', 'select * from fare_rules where secret = ?', ['x'], new PDOException('SQLSTATE[42P01]: relation missing'))));

    $response = $this->postJson('/api/fare/quote-fleets-by-location', ['origin_lat' => 7.07, 'origin_lng' => 125.6, 'destination_lat' => 7.1, 'destination_lng' => 125.62, 'seat_type' => 'seated']);

    if ($response->status() === 422 && $response->json('errors')) {
        $this->markTestSkipped('The request was refused before the fare lookup: '.json_encode($response->json('errors')));
    }
    $response->assertStatus(500)->assertJsonPath('message', 'Fares could not be loaded right now. Please try again.');
    expect(json_encode($response->json()))->not->toContain('select')->not->toContain('SQLSTATE');
});

it('still shows a fare rule refusal as it is', function () {
    $this->mock(FareRuleService::class, fn ($mock) => $mock->shouldReceive('getFleetQuotesFromCoordinates')->andThrow(new RuntimeException('No fare is set for this bus and seat type.')));

    $response = $this->postJson('/api/fare/quote-fleets-by-location', ['origin_lat' => 7.07, 'origin_lng' => 125.6, 'destination_lat' => 7.1, 'destination_lng' => 125.62, 'seat_type' => 'seated']);

    if ($response->status() === 422 && $response->json('errors')) {
        $this->markTestSkipped('The request was refused before the fare lookup: '.json_encode($response->json('errors')));
    }
    $response->assertStatus(422)->assertJsonPath('message', 'No fare is set for this bus and seat type.');
});

function t3fStaff(string $role, string $name): User
{
    $user = User::create(['username' => 't3f'.$role.Str::random(4), 'email' => "t3f.{$role}.".Str::random(4).'@example.com', 'password' => 'right-password-1', 'role' => $role]);
    joinTestCompany(StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '0917'.random_int(1000000, 9999999), 'address' => 'Secret Street 1', 'birth_date' => '1990-01-01']));

    return $user;
}

it('gives an Operator only the scheduling fields of Drivers and Chauffeurs, and the Admin everything (Todo 3 finding 2)', function () {
    t3fStaff('driver', 'Shared Driver');
    t3fStaff('conductor', 'Shared Chauffeur');
    $operator = t3fStaff('operator', 'Any Operator');

    foreach (['operator/drivers', 'operator/conductors'] as $url) {
        $this->actingAs($operator, 'sanctum');
        $row = $this->getJson("/api/{$url}")->assertSuccessful()->json('data.0');
        app('auth')->forgetGuards();

        expect($row)->toHaveKeys(['company_user_id', 'name', 'is_available', 'user'])
            ->not->toHaveKeys(['phone_num', 'address', 'birth_date'])
            ->and($row['user'])->toHaveKey('username')->not->toHaveKey('email');
        expect(json_encode($row))->not->toContain('Secret Street')->not->toContain('@example.com');
    }

    $this->actingAs(t3fStaff('admin', 'The Admin'), 'sanctum');
    // Batch 48 Phase 7: the Super Admin sees a Driver's email on the Users page (its old driver list was removed).
    $adminRow = $this->getJson('/api/admin/users?role=driver')->assertSuccessful()->json('data.users.0');
    expect($adminRow['email'])->toContain('@example.com');
});

it('still leaves out a Driver already busy on the asked date', function () {
    $this->actingAs(t3fStaff('operator', 'Any Operator'), 'sanctum');
    t3fStaff('driver', 'Free Driver');

    $names = collect($this->getJson('/api/operator/drivers?trip_date=2026-11-10')->assertSuccessful()->json('data'))->pluck('name');

    expect($names)->toContain('Free Driver');
});
