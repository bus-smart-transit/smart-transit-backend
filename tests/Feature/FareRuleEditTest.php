<?php

use App\Models\Company;
use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-06: an Operator copies a fare to a later date, ends or stops a fare in use (its prices stay), changes a fare
// that has not started, and edits their own buses. Another company's are not found.

function freOperator(string $companyName): User
{
    $company = Company::firstOrCreate(['company_name' => $companyName]);
    $user = User::create(['username' => 'fre'.Str::random(6), 'email' => 'fre.'.Str::random(6).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Fre Op', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id]);

    return $user->fresh();
}

function freFleet(User $owner, string $plate): Fleet
{
    return Fleet::create(['company_user_id' => $owner->companyProfile->company_user_id, 'company_id' => $owner->companyProfile->company_id, 'plate_number' => $plate, 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);
}

function freRule(Fleet $fleet, array $over = []): FareRule
{
    return FareRule::create(array_merge(['fleet_id' => $fleet->fleet_id, 'service_type' => 'regular', 'seat_type' => 'seated', 'base_fare' => 15, 'fare_per_km' => 2.25, 'base_distance_km' => 5, 'rounding_mode' => config('fares.default_rounding_mode'), 'status' => 'active'], $over));
}

/** Acts as the user with a fresh step-up token (the extra check fare changes need). */
function freAs($test, User $user)
{
    $raw = bin2hex(random_bytes(32));
    DB::table('step_up_tokens')->insert(['guard' => 'sanctum', 'user_id' => $user->user_id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);

    return $test->actingAs($user, 'sanctum')->withHeader('X-Step-Up-Token', $raw);
}

it('copies a fare to a later date with new prices and ends the original the day before', function () {
    $op = freOperator('Davao Metro Shuttle');
    $rule = freRule(freFleet($op, 'DMS-001'));
    $from = Carbon::now('Asia/Manila')->addDays(5)->toDateString();

    $copy = freAs($this, $op)->postJson("/api/operator/fare-rules/{$rule->fare_rule_id}/copy", ['effective_from' => $from, 'base_fare' => 16])
        ->assertSuccessful()->json('data');

    expect((float) $copy['base_fare'])->toBe(16.0)
        ->and((float) $copy['fare_per_km'])->toBe(2.25)
        ->and($copy['effective_from'])->toStartWith($from)
        ->and($rule->fresh()->effective_to->toDateString())->toBe(Carbon::parse($from)->subDay()->toDateString());
});

it('refuses a copy that starts today or on the original\'s start date', function () {
    $op = freOperator('Davao Metro Shuttle');
    $start = Carbon::now('Asia/Manila')->addDays(3)->toDateString();
    $rule = freRule(freFleet($op, 'DMS-001'), ['effective_from' => $start]);

    freAs($this, $op)->postJson("/api/operator/fare-rules/{$rule->fare_rule_id}/copy", ['effective_from' => Carbon::now('Asia/Manila')->toDateString()])
        ->assertUnprocessable()->assertJsonPath('errors.effective_from.0', 'Choose a start date from tomorrow on.');
    freAs($this, $op)->postJson("/api/operator/fare-rules/{$rule->fare_rule_id}/copy", ['effective_from' => $start])
        ->assertUnprocessable()->assertJsonPath('errors.effective_from.0', 'Choose a start date after the original fare starts.');

    expect(FareRule::count())->toBe(1);
});

it('lets a fare in use be ended or stopped, but keeps its prices; a fare not yet started may change', function () {
    $op = freOperator('Davao Metro Shuttle');
    $fleet = freFleet($op, 'DMS-001');
    $inUse = freRule($fleet);
    $later = freRule($fleet, ['seat_type' => 'standing', 'base_fare' => 13, 'effective_from' => Carbon::now('Asia/Manila')->addDays(4)->toDateString()]);
    $before = $inUse->updated_at;
    $this->travel(1)->minutes();

    freAs($this, $op)->patchJson("/api/operator/fare-rules/{$inUse->fare_rule_id}", ['base_fare' => 20])
        ->assertUnprocessable()->assertJsonValidationErrors(['base_fare']);
    freAs($this, $op)->patchJson("/api/operator/fare-rules/{$inUse->fare_rule_id}", ['status' => 'inactive', 'note' => 'No longer followed'])
        ->assertSuccessful()->assertJsonPath('data.status', 'inactive');
    freAs($this, $op)->patchJson("/api/operator/fare-rules/{$later->fare_rule_id}", ['base_fare' => 14])
        ->assertSuccessful();

    expect((float) $inUse->fresh()->base_fare)->toBe(15.0)
        ->and($inUse->fresh()->updated_at->gt($before))->toBeTrue()
        ->and((float) $later->fresh()->base_fare)->toBe(14.0)
        ->and(DB::table('audit_logs')->where('action', 'fare_rule.update')->count())->toBe(2);
});

it('answers not found for another company\'s fare, and needs the extra check', function () {
    $op = freOperator('Davao Metro Shuttle');
    $rival = freOperator('Rival Lines');
    $theirs = freRule(freFleet($rival, 'RIV-001'));

    freAs($this, $op)->patchJson("/api/operator/fare-rules/{$theirs->fare_rule_id}", ['note' => 'x'])->assertNotFound();
    freAs($this, $op)->postJson("/api/operator/fare-rules/{$theirs->fare_rule_id}/copy", ['effective_from' => now()->addDays(3)->toDateString()])->assertNotFound();
    $this->actingAs($rival, 'sanctum')->patchJson("/api/operator/fare-rules/{$theirs->fare_rule_id}", ['note' => 'x'])->assertForbidden();
});

it('lets an Operator edit their own bus but not drop its places below what is sold, and not another company\'s', function () {
    $op = freOperator('Davao Metro Shuttle');
    $rival = freOperator('Rival Lines');
    $fleet = freFleet($op, 'DMS-001');
    $theirs = freFleet($rival, 'RIV-001');
    $route = Route::create(['route_name' => 'Fre Route', 'origin' => 'A', 'destination' => 'B']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $op->companyProfile->company_user_id, 'trip_date' => now()->addDay()->toDateString(), 'status' => 'scheduled', 'current_seated_capacity' => 32, 'current_standing_capacity' => 0, 'total_occupancy' => 32]);

    $this->actingAs($op, 'sanctum');
    $this->patchJson("/api/operator/fleets/{$fleet->fleet_id}", ['seated_capacity' => 30])
        ->assertUnprocessable()->assertJsonPath('errors.seated_capacity.0', '32 seats are already sold on a trip of this bus that has not finished. Choose at least 32.');
    $this->patchJson("/api/operator/fleets/{$fleet->fleet_id}", ['body_no' => '07', 'seated_capacity' => 35, 'bus_company_name' => 'Renamed'])
        ->assertSuccessful();
    $this->patchJson("/api/operator/fleets/{$theirs->fleet_id}", ['body_no' => '99'])->assertNotFound();

    $fresh = $fleet->fresh();
    expect($fresh->body_no)->toBe('07')
        ->and((int) $fresh->capacity)->toBe(45)
        ->and($fresh->bus_company_name)->not->toBe('Renamed')
        ->and($theirs->fresh()->body_no)->toBeNull();
});
