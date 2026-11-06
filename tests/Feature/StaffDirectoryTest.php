<?php

use App\Models\Company;
use App\Models\ConductorShiftLog;
use App\Models\DriverShiftLog;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Services\StaffService;
use App\Support\PhoneNumber;
use App\Support\TripCrew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 46 phases 1 to 6 (2026-11-05): Staff Directory search and pages, Edit, Deactivate / Reactivate, availability
// rule, password change, the time in / out range, and trips that wait for their crew.

beforeEach(function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
});

function sdtStaff(string $role, string $name, ?User $operator = null, array $over = []): User
{
    $user = User::create(['username' => $over['username'] ?? 'sdt'.$role.Str::random(5), 'email' => $over['email'] ?? 'sdt.'.Str::random(6).'@example.com', 'password' => 'Right-password-1', 'role' => $role]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => $name,
        'phone_num' => '+639171234567',
        'address' => 'Tagum City',
        'company_id' => $role === 'operator' ? Company::create(['company_name' => 'Co '.Str::random(8)])->company_id : $operator?->companyProfile?->company_id,
        'is_available' => $over['is_available'] ?? true,
    ]);

    return $user->fresh();
}

function sdtId(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

function sdtFleetRoute(User $operator): FleetRoute
{
    $route = Route::create(['route_name' => 'Sdt '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => sdtId($operator), 'plate_number' => 'SDT-'.random_int(100, 999), 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);

    return FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
}

function sdtTrip(User $operator, ?User $driver, ?User $conductor, string $date, string $status = 'scheduled'): Trip
{
    return Trip::create([
        'fleet_route_id' => sdtFleetRoute($operator)->fleet_route_id,
        'company_user_id' => sdtId($operator),
        'trip_date' => $date,
        'departure_time' => '08:00',
        'status' => $status,
        'driver_id' => $driver ? sdtId($driver) : null,
        'conductor_id' => $conductor ? sdtId($conductor) : null,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

it('stores a typed Philippine mobile number in one form and refuses a wrong one', function () {
    expect(PhoneNumber::normalize('0917 123 4567'))->toBe('+639171234567')
        ->and(PhoneNumber::normalize('9171234567'))->toBe('+639171234567')
        ->and(PhoneNumber::normalize('+63 917 123 4567'))->toBe('+639171234567')
        ->and(PhoneNumber::normalize('+630917-123-4567'))->toBe('+639171234567')
        ->and(PhoneNumber::normalize('+1 415 555 0100'))->toBe('+14155550100')
        ->and(PhoneNumber::normalize('0817 123 4567'))->toBeNull()
        ->and(PhoneNumber::normalize('call me'))->toBeNull()
        ->and(PhoneNumber::mask('+639171234567'))->toBe('+63917*****67');
});

it('pages, searches and counts only the Operator\'s own crew', function () {
    $op = sdtStaff('operator', 'Op');
    $other = sdtStaff('operator', 'Other');
    foreach (range(1, 12) as $i) {
        sdtStaff('driver', "Driver {$i}", $op);
    }
    sdtStaff('conductor', 'Maria Chauffeur', $op, ['username' => 'maria.c']);
    sdtStaff('driver', 'Their Driver', $other);

    $this->actingAs($op, 'sanctum');
    $first = $this->getJson('/api/operator/staff')->assertSuccessful()->json('data');
    expect($first['staff'])->toHaveCount(10)
        ->and($first['pagination'])->toMatchArray(['page' => 1, 'per_page' => 10, 'total' => 13, 'last_page' => 2])
        ->and($first['totals'])->toBe(['driver' => 12, 'conductor' => 1])
        ->and(json_encode($first))->not->toContain('Their Driver');

    $byName = $this->getJson('/api/operator/staff?search=maria')->json('data');
    $byRoleWord = $this->getJson('/api/operator/staff?search=chauffeur')->json('data');
    $withFilter = $this->getJson('/api/operator/staff?search=maria&role=driver')->json('data');

    expect(collect($byName['staff'])->pluck('username')->all())->toBe(['maria.c'])
        ->and($byName['totals'])->toBe(['driver' => 12, 'conductor' => 1])
        ->and($byRoleWord['staff'][0]['role_label'])->toBe('Chauffeur')
        ->and($withFilter['staff'])->toBe([]);
});

it('lets the Operator edit only username, phone and email of their own crew; a new email ends the sessions', function () {
    $op = sdtStaff('operator', 'Op');
    $driver = sdtStaff('driver', 'Juan', $op);
    sdtStaff('driver', 'Taken', $op, ['email' => 'taken@example.com', 'username' => 'taken.name']);
    $driver->createToken('phone');

    $this->actingAs($op, 'sanctum');
    $id = sdtId($driver);
    $this->patchJson("/api/operator/staff/{$id}", ['email' => 'taken@example.com'])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This email cannot be used. Try another one.');
    $this->patchJson("/api/operator/staff/{$id}", ['username' => 'TAKEN.name'])->assertUnprocessable();
    $this->patchJson("/api/operator/staff/{$id}", ['phone_num' => '12'])->assertUnprocessable();

    $saved = $this->patchJson("/api/operator/staff/{$id}", ['username' => 'juan.new', 'phone_num' => '0918 765 4321', 'email' => 'Juan.New@Example.com', 'name' => 'Ignored Name'])
        ->assertSuccessful()->json('data');

    expect($saved)->toMatchArray(['username' => 'juan.new', 'phone_num' => '+639187654321', 'email' => 'juan.new@example.com', 'name' => 'Juan'])
        ->and($driver->fresh()->tokens()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'staff.update')->exists())->toBeTrue()
        ->and((string) DB::table('audit_logs')->where('action', 'staff.update')->value('context'))->not->toContain('9187654321');
});

it('answers 404 for another Operator\'s staff on every directory action', function () {
    $op = sdtStaff('operator', 'Op');
    $theirs = sdtStaff('driver', 'Theirs', sdtStaff('operator', 'Other'));
    $myself = sdtId($op);

    $this->actingAs($op, 'sanctum');
    foreach ([sdtId($theirs), $myself] as $id) {
        $this->patchJson("/api/operator/staff/{$id}", ['username' => 'stolen'])->assertNotFound();
        $this->patchJson("/api/operator/staff/{$id}/deactivate")->assertNotFound();
        $this->patchJson("/api/operator/staff/{$id}/reactivate")->assertNotFound();
    }

    expect($theirs->fresh()->username)->not->toBe('stolen')->and($theirs->fresh()->deactivated_at)->toBeNull();
});

it('refuses to turn off someone with trips still to work, and lists them', function () {
    $op = sdtStaff('operator', 'Op');
    $driver = sdtStaff('driver', 'Juan', $op);
    sdtTrip($op, $driver, null, now()->addDays(2)->toDateString());

    $this->actingAs($op, 'sanctum');
    $response = $this->patchJson('/api/operator/staff/'.sdtId($driver).'/deactivate')->assertUnprocessable();

    expect($response->json('errors.staff.0'))->toContain('Replace this person on their trips first')
        ->and($response->json('errors.trips'))->toHaveCount(1)
        ->and($driver->fresh()->deactivated_at)->toBeNull();
});

it('turns an account off: no sign-in, sessions ended, out of the pickers, history kept; and on again', function () {
    $op = sdtStaff('operator', 'Op');
    $driver = sdtStaff('driver', 'Juan', $op, ['email' => 'juan.off@example.com']);
    $past = sdtTrip($op, $driver, null, now()->subDays(3)->toDateString(), 'completed');
    $driver->createToken('phone');

    $this->actingAs($op, 'sanctum');
    $this->patchJson('/api/operator/staff/'.sdtId($driver).'/deactivate')->assertSuccessful()->assertJsonPath('data.deactivated', true);
    expect($driver->fresh()->tokens()->count())->toBe(0)
        ->and(collect($this->getJson('/api/operator/drivers')->json('data'))->pluck('name')->all())->not->toContain('Juan')
        ->and((int) $past->fresh()->driver_id)->toBe(sdtId($driver));

    $trip = sdtTrip($op, null, null, now()->addDays(4)->toDateString());
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => sdtId($driver)])->assertUnprocessable()
        ->assertJsonPath('errors.driver_id.0', "Juan's account is turned off. Choose someone else.");
    app('auth')->forgetGuards();

    $this->postJson('/api/auth/login', ['email' => 'juan.off@example.com', 'password' => 'Right-password-1', 'portal' => 'staff'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'This account is turned off. Ask your Operator to turn it on again.');

    $this->actingAs($op, 'sanctum');
    $this->patchJson('/api/operator/staff/'.sdtId($driver).'/reactivate')->assertSuccessful()->assertJsonPath('data.deactivated', false);
    expect(DB::table('audit_logs')->whereIn('action', ['staff.deactivate', 'staff.reactivate'])->count())->toBe(2);
});

it('does not assign a crew member who is Not Available, and shows the reason', function () {
    $op = sdtStaff('operator', 'Op');
    $resting = sdtStaff('conductor', 'Ana', $op, ['is_available' => false]);
    $trip = sdtTrip($op, null, null, now()->addDays(2)->toDateString());

    $this->actingAs($op, 'sanctum');
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/conductor", ['conductor_id' => sdtId($resting)])->assertUnprocessable()
        ->assertJsonPath('errors.conductor_id.0', 'Ana is Not Available right now. Choose someone else, or ask them to switch to Available.');

    expect($trip->fresh()->conductor_id)->toBeNull();
});

it('lets a new crew member start Available, owned by the Operator, with a required birth date and phone', function () {
    $op = sdtStaff('operator', 'Op');
    $this->actingAs($op, 'sanctum');
    $body = ['role' => 'conductor', 'name' => 'Ana Reyes', 'email' => 'ana.reyes@example.com', 'username' => 'ana.reyes', 'password' => 'Strong-pass-1', 'phone_num' => '0917 000 1111', 'address' => 'Panabo'];

    $this->postJson('/api/operator/accounts', $body)->assertUnprocessable()->assertJsonValidationErrors(['birth_date']);
    $this->postJson('/api/operator/accounts', $body + ['birth_date' => '1995-02-03'])->assertSuccessful();

    $profile = User::where('email', 'ana.reyes@example.com')->first()->companyProfile;
    expect($profile->is_available)->toBeTrue()
        ->and($profile->phone_num)->toBe('+639170001111')
        ->and((int) $profile->company_id)->toBe((int) $op->companyProfile->company_id);
});

it('offers a new crew member one password change, which they may skip or take', function () {
    $op = sdtStaff('operator', 'Op');
    $driver = sdtStaff('driver', 'Juan', $op);
    $chauffeur = sdtStaff('conductor', 'Ana', $op);

    $this->actingAs($driver, 'sanctum');
    $this->getJson('/api/driver/profile')->assertJsonPath('data.offer_password_change', true);
    $this->postJson('/api/staff/password/skip')->assertSuccessful();
    $this->getJson('/api/driver/profile')->assertJsonPath('data.offer_password_change', false);
    app('auth')->forgetGuards();

    $this->actingAs($chauffeur, 'sanctum');
    $this->postJson('/api/staff/password', ['current_password' => 'wrong', 'password' => 'New-password-2', 'password_confirmation' => 'New-password-2'])
        ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'Your current password is not right.');
    $this->postJson('/api/staff/password', ['current_password' => 'Right-password-1', 'password' => 'weakpass', 'password_confirmation' => 'weakpass'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->postJson('/api/staff/password', ['current_password' => 'Right-password-1', 'password' => 'New-password-2', 'password_confirmation' => 'New-password-2'])
        ->assertSuccessful();

    expect(password_verify('New-password-2', $chauffeur->fresh()->password))->toBeTrue()
        ->and(StaffService::offersPasswordChange($chauffeur->fresh()))->toBeFalse()
        ->and(StaffService::offersPasswordChange($op->fresh()))->toBeFalse();
});

it('shows time in and out over a range with shift labels, hours and totals per person', function () {
    $op = sdtStaff('operator', 'Op');
    $driver = sdtStaff('driver', 'Juan', $op);
    $chauffeur = sdtStaff('conductor', 'Ana', $op);
    $trip = sdtTrip($op, $driver, $chauffeur, '2026-11-02', 'completed');
    $fleetId = $trip->fleetRoute->fleet_id;

    DriverShiftLog::create(['driver_company_user_id' => sdtId($driver), 'trip_id' => $trip->trip_id, 'fleet_id' => $fleetId, 'shift_date' => '2026-11-02', 'started_at' => '2026-11-02 06:00:00', 'ended_at' => '2026-11-02 13:30:00']);
    DriverShiftLog::create(['driver_company_user_id' => sdtId($driver), 'trip_id' => $trip->trip_id, 'fleet_id' => $fleetId, 'shift_date' => '2026-11-03', 'started_at' => '2026-11-03 20:00:00', 'ended_at' => '2026-11-04 01:00:00']);
    ConductorShiftLog::create(['conductor_company_user_id' => sdtId($chauffeur), 'trip_id' => $trip->trip_id, 'fleet_id' => $fleetId, 'shift_date' => '2026-11-03', 'started_at' => '2026-11-03 07:00:00']);

    $this->actingAs($op, 'sanctum');
    $data = $this->getJson('/api/operator/crew-shifts?from=2026-11-01&to=2026-11-05')->assertSuccessful()->json('data');

    $juan = collect($data['totals'])->firstWhere('name', 'Juan');
    $ana = collect($data['totals'])->firstWhere('name', 'Ana');
    $overnight = collect($data['shifts'])->firstWhere('time_in', '20:00');

    expect($data['shifts'])->toHaveCount(3)
        ->and($data['shifts'][0])->toMatchArray(['shift' => 'Single trip', 'minutes' => 450, 'role' => 'Driver'])
        ->and($overnight['ended_on'])->toBe('2026-11-04')
        ->and($juan)->toMatchArray(['shifts' => 2, 'minutes' => 750])
        ->and($ana)->toMatchArray(['minutes' => 0, 'still_open' => true])
        ->and(collect($data['shifts'])->firstWhere('role', 'Chauffeur')['minutes'])->toBeNull();

    $this->getJson('/api/operator/crew-shifts?from=2026-11-01&to=2026-12-15')->assertUnprocessable();
    expect($this->getJson('/api/operator/crew-shifts?from=2026-11-01&to=2026-11-05&staff_id='.sdtId($chauffeur))->json('data.shifts'))->toHaveCount(1);
});

it('schedules a trip without a crew; passengers may book it, but it cannot start boarding until both are assigned', function () {
    $op = sdtStaff('operator', 'Op');
    $driver = sdtStaff('driver', 'Juan', $op);
    $chauffeur = sdtStaff('conductor', 'Ana', $op);
    $fleetRoute = sdtFleetRoute($op);
    $date = now()->addDays(2)->toDateString();

    $this->actingAs($op, 'sanctum');
    $tripId = $this->postJson('/api/operator/trips', ['fleet_route_id' => $fleetRoute->fleet_route_id, 'trip_date' => $date, 'departure_time' => '09:00', 'trip_type' => 'one_way'])
        ->assertSuccessful()->json('data.trip_id');
    $trip = Trip::find($tripId);
    expect($trip->driver_id)->toBeNull()->and($trip->conductor_id)->toBeNull();

    $this->patchJson("/api/operator/trips/{$tripId}/boarding")->assertUnprocessable()->assertJsonPath('message', 'Assign a Driver and a Chauffeur to this trip first.');
    $this->patchJson("/api/operator/trips/{$tripId}/depart")->assertUnprocessable()->assertJsonPath('message', 'Assign a Driver and a Chauffeur to this trip first.');
    expect(collect($this->getJson("/api/trips?trip_date={$date}")->json('data'))->pluck('trip_id')->all())->toContain($tripId);

    $this->patchJson("/api/operator/trips/{$tripId}/driver", ['driver_id' => sdtId($driver)])->assertSuccessful();
    $this->patchJson("/api/operator/trips/{$tripId}/boarding")->assertUnprocessable()->assertJsonPath('message', 'Assign a Driver and a Chauffeur to this trip first.');
    $this->patchJson("/api/operator/trips/{$tripId}/conductor", ['conductor_id' => sdtId($chauffeur)])->assertSuccessful();

    expect(TripCrew::isComplete(Trip::find($tripId)))->toBeTrue();
});
