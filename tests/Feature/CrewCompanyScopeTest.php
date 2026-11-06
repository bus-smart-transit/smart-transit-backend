<?php

use App\Models\Company;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use App\Services\FcmService;
use App\Services\ShiftBlockService;
use App\Services\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Batch 46 phase 0 (decided 2026-11-05, least privilege): a Driver or Chauffeur belongs to the Operator who added them.
// An Operator lists, and assigns, only their own crew; the Admin still sees everyone.

beforeEach(function () {
    $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn(true));
});

function ccsStaff(string $role, string $name, ?User $operator = null): User
{
    $user = User::create(['username' => 'ccs'.$role.Str::random(5), 'email' => 'ccs.'.Str::random(6).'@example.com', 'password' => 'right-password-1', 'role' => $role]);
    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => $name,
        'phone_num' => '0917'.random_int(1000000, 9999999),
        'address' => 'Tagum City',
        'company_id' => $role === 'operator' ? Company::create(['company_name' => 'Co '.Str::random(8)])->company_id : $operator?->companyProfile?->company_id,
        'is_company_admin' => $role === 'operator',
        'is_available' => in_array($role, ['driver', 'conductor'], true),
    ]);

    return $user->fresh();
}

function ccsId(User $user): int
{
    return (int) $user->companyProfile->company_user_id;
}

function ccsTrip(User $operator, ?User $driver = null, ?User $conductor = null): Trip
{
    $route = Route::create(['route_name' => 'Ccs '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create(['company_user_id' => ccsId($operator), 'plate_number' => 'CCS-'.random_int(100, 999), 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => ccsId($operator),
        'trip_date' => now()->addDays(3)->toDateString(),
        'status' => 'scheduled',
        'driver_id' => $driver ? ccsId($driver) : null,
        'conductor_id' => $conductor ? ccsId($conductor) : null,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

it('lists only the signed-in Operator\'s own Drivers and Chauffeurs', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $other = ccsStaff('operator', 'Other Operator');
    ccsStaff('driver', 'My Driver', $mine);
    ccsStaff('conductor', 'My Chauffeur', $mine);
    ccsStaff('driver', 'Their Driver', $other);
    ccsStaff('conductor', 'Their Chauffeur', $other);
    ccsStaff('driver', 'Nobody\'s Driver');

    $this->actingAs($mine, 'sanctum');
    $drivers = collect($this->getJson('/api/operator/drivers')->assertSuccessful()->json('data'))->pluck('name');
    $dated = collect($this->getJson('/api/operator/drivers?trip_date='.now()->addDays(3)->toDateString())->assertSuccessful()->json('data'))->pluck('name');
    $chauffeurs = collect($this->getJson('/api/operator/conductors')->assertSuccessful()->json('data'))->pluck('name');

    expect($drivers->all())->toBe(['My Driver'])
        ->and($dated->all())->toBe(['My Driver'])
        ->and($chauffeurs->all())->toBe(['My Chauffeur']);
});

it('still lets the Admin see every Driver and Chauffeur', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    ccsStaff('driver', 'My Driver', $mine);
    ccsStaff('driver', 'Nobody\'s Driver');

    $this->actingAs(ccsStaff('admin', 'The Admin'), 'sanctum');

    expect(collect($this->getJson('/api/admin/drivers')->assertSuccessful()->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['My Driver', 'Nobody\'s Driver']);
});

it('makes a Driver or Chauffeur added by an Operator belong to that Operator', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $this->actingAs($mine, 'sanctum');

    $this->postJson('/api/operator/accounts', [
        'role' => 'driver',
        'name' => 'New Driver',
        'email' => 'ccs.new.driver@example.com',
        'username' => 'ccsnewdriver',
        'password' => 'Strong-password-123',
        'password_confirmation' => 'Strong-password-123',
        'phone_num' => '09171234567',
        'address' => 'Tagum City',
        'birth_date' => '1990-05-06',
    ])->assertSuccessful();

    $profile = User::where('email', 'ccs.new.driver@example.com')->first()->companyProfile;
    expect((int) $profile->company_id)->toBe((int) $mine->companyProfile->company_id);
});

it('refuses to put another company\'s Driver or Chauffeur on a trip, without naming them', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $other = ccsStaff('operator', 'Other Operator');
    $theirDriver = ccsStaff('driver', 'Their Driver', $other);
    $theirChauffeur = ccsStaff('conductor', 'Their Chauffeur', $other);
    $trip = ccsTrip($mine);

    $this->actingAs($mine, 'sanctum');
    $foreign = $this->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => ccsId($theirDriver)])->assertUnprocessable();
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => 999999])->assertUnprocessable();
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/conductor", ['conductor_id' => ccsId($theirChauffeur)])->assertUnprocessable();

    expect($foreign->json('errors.driver_id.0'))->toBe('Choose a driver from your own staff.')
        ->and(json_encode($foreign->json()))->not->toContain('Their Driver')
        ->and($trip->fresh()->driver_id)->toBeNull()
        ->and($trip->fresh()->conductor_id)->toBeNull();
});

it('puts the Operator\'s own Driver and Chauffeur on a trip', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $driver = ccsStaff('driver', 'My Driver', $mine);
    $chauffeur = ccsStaff('conductor', 'My Chauffeur', $mine);
    $trip = ccsTrip($mine);

    $this->actingAs($mine, 'sanctum');
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => ccsId($driver)])->assertSuccessful();
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/conductor", ['conductor_id' => ccsId($chauffeur)])->assertSuccessful();

    expect((int) $trip->fresh()->driver_id)->toBe(ccsId($driver))
        ->and((int) $trip->fresh()->conductor_id)->toBe(ccsId($chauffeur));
});

it('refuses another Operator\'s trip outright (404, not a crew answer)', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $other = ccsStaff('operator', 'Other Operator');
    $theirDriver = ccsStaff('driver', 'Their Driver', $other);
    $theirTrip = ccsTrip($other);

    $this->actingAs($mine, 'sanctum');
    $status = $this->patchJson("/api/operator/trips/{$theirTrip->trip_id}/driver", ['driver_id' => ccsId($theirDriver)])->status();

    expect($status)->toBeIn([403, 404])
        ->and($theirTrip->fresh()->driver_id)->toBeNull();
});

it('refuses another company\'s crew on a shift block and on a range preview, before reading their schedule', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $other = ccsStaff('operator', 'Other Operator');
    $myDriver = ccsStaff('driver', 'My Driver', $mine);
    $theirChauffeur = ccsStaff('conductor', 'Their Chauffeur', $other);
    $blocks = app(ShiftBlockService::class);

    $payload = [
        'company_user_id' => ccsId($mine),
        // Batch 46 step 2: on one of the Operator's own buses, so the check reaches the crew.
        'fleet_route_id' => ccsTrip($mine)->fleet_route_id,
        'driver_id' => ccsId($myDriver),
        'conductor_id' => ccsId($theirChauffeur),
        'from_date' => now()->addDays(3)->toDateString(),
        'to_date' => now()->addDays(4)->toDateString(),
    ];

    expect(fn () => $blocks->previewRange($payload))->toThrow(ValidationException::class, 'Choose a Chauffeur from your own staff.');
});

it('lets an Admin-owned trip take any crew (the Admin portal is rewritten in Batch 48)', function () {
    $admin = ccsStaff('admin', 'The Admin');
    $someDriver = ccsStaff('driver', 'Any Driver', ccsStaff('operator', 'Some Operator'));

    $checked = app(StaffService::class)->assertCrewOf(ccsId($admin), ccsId($someDriver), 'driver', 'driver_id');

    expect((int) $checked->company_user_id)->toBe(ccsId($someDriver));
});

it('refuses a person with the wrong role even inside the company', function () {
    $mine = ccsStaff('operator', 'Mine Operator');
    $myChauffeur = ccsStaff('conductor', 'My Chauffeur', $mine);

    expect(fn () => app(StaffService::class)->assertCrewOf(ccsId($mine), ccsId($myChauffeur), 'driver', 'driver_id'))
        ->toThrow(ValidationException::class, 'Choose a driver from your own staff.');
});

it('lets every Operator of one company see and assign the same staff, and shows the company name (decided 2026-11-06)', function () {
    $first = ccsStaff('operator', 'First Dispatcher');
    $second = ccsStaff('operator', 'Second Dispatcher');
    $second->companyProfile->forceFill(['company_id' => $first->companyProfile->company_id])->save();
    $driver = ccsStaff('driver', 'Shared Driver', $first);
    $trip = ccsTrip($second->fresh());

    $this->actingAs($second->fresh(), 'sanctum');
    expect(collect($this->getJson('/api/operator/drivers')->json('data'))->pluck('name')->all())->toBe(['Shared Driver']);
    $this->patchJson("/api/operator/trips/{$trip->trip_id}/driver", ['driver_id' => ccsId($driver)])->assertSuccessful();

    $companyName = Company::find($first->companyProfile->company_id)->company_name;
    expect($this->getJson('/api/operator/staff')->json('data.company.company_name'))->toBe($companyName)
        ->and($this->getJson('/api/operator/profile')->json('data.company.company_name'))->toBe($companyName);
});

it('lets an Operator without a company see no staff and add none', function () {
    $loose = ccsStaff('operator', 'Loose Operator');
    $loose->companyProfile->forceFill(['company_id' => null])->save();
    ccsStaff('driver', 'Someone', ccsStaff('operator', 'Other'));

    $this->actingAs($loose->fresh(), 'sanctum');
    expect($this->getJson('/api/operator/drivers')->json('data'))->toBe([]);
    $this->postJson('/api/operator/accounts', [
        'role' => 'driver', 'name' => 'New Driver', 'email' => 'ccs.loose@example.com', 'password' => 'Strong-password-123',
        'phone_num' => '09171234567', 'address' => 'Tagum City', 'birth_date' => '1990-05-06',
    ])->assertUnprocessable()->assertJsonPath('errors.role.0', 'Your account is not linked to a company yet. Ask the Admin to link it first.');
});
