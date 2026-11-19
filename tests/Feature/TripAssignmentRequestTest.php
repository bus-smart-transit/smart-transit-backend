<?php

use App\Models\Fleet;
use App\Models\FleetDailyPin;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rqUser(string $role): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => $role,
    ]);

    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ]);

    return $user->fresh();
}

function rqTrip(User $operator, User $driver, User $conductor, string $status = 'scheduled'): Trip
{
    $route = Route::create(['route_name' => 'Req '.Str::random(4), 'origin' => 'A', 'destination' => 'B']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'RQ-'.fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);
    $fleetRoute = FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);

    return Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now()->toDateString(),
        'status' => $status,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
}

function rqFakeFcm(array &$titles): void
{
    test()->mock(FcmService::class, function ($mock) use (&$titles) {
        $mock->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title) use (&$titles) {
            $titles[] = [$user->user_id, $title];

            return true;
        });
    });
}

test('"Others" requires a description and the text is stored as sanitized plain text', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'other'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason_text']);
    $this->assertDatabaseMissing('trip_assignment_requests', ['trip_id' => $trip->trip_id]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", [
        'reason_code' => 'other',
        'reason_text' => "  Family   <script>alert(1)</script>emergency \n today ",
    ])->assertSuccessful();

    $this->assertDatabaseHas('trip_assignment_requests', [
        'trip_id' => $trip->trip_id,
        'reason_text' => 'Family alert(1)emergency today',
    ]);
});

test('the free text is length limited', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", [
        'reason_code' => 'other',
        'reason_text' => str_repeat('a', 301),
    ])->assertStatus(422)->assertJsonValidationErrors(['reason_text']);
});

test('repeating the request is idempotent and does not create a second one', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();
    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();

    expect(TripAssignmentRequest::where('trip_id', $trip->trip_id)->count())->toBe(1);
});

test('a driver who already accepted the trip cannot decline it', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));
    $trip->forceFill(['driver_accepted_at' => now()])->save();

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])
        ->assertStatus(422);
    $this->assertDatabaseMissing('trip_assignment_requests', ['trip_id' => $trip->trip_id]);
});

test('approve releases the driver, drops the stale PIN, keeps the trip status and notifies the driver', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $conductor = rqUser('conductor');
    $trip = rqTrip($operator, $driver, $conductor);
    FleetDailyPin::create([
        'trip_id' => $trip->trip_id,
        'driver_id' => $driver->companyProfile->company_user_id,
        'conductor_id' => $conductor->companyProfile->company_user_id,
        'pin_code' => '123456',
        'pin_date' => now()->toDateString(),
    ]);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();
    $requestId = TripAssignmentRequest::where('trip_id', $trip->trip_id)->value('id');

    $titles = [];
    rqFakeFcm($titles);

    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'driver_id' => null, 'status' => 'scheduled']);
    $this->assertDatabaseMissing('fleet_daily_pins', ['trip_id' => $trip->trip_id]);
    $this->assertDatabaseHas('trip_notifications', ['trip_id' => $trip->trip_id, 'type' => 'trip_declined']);
    expect($titles)->toContain([$driver->user_id, 'Decline Request Approved']);
});

test('a driver with an approved release cannot be selected again on that date', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $conductor = rqUser('conductor');
    $declinedTrip = rqTrip($operator, $driver, $conductor);

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$declinedTrip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();
    $requestId = TripAssignmentRequest::where('trip_id', $declinedTrip->trip_id)->value('id');
    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/approve")->assertSuccessful();

    $anotherTrip = rqTrip($operator, rqUser('driver'), rqUser('conductor'));
    $this->actingAs($operator)->patchJson("/api/operator/trips/{$anotherTrip->trip_id}/driver", [
        'driver_id' => $driver->companyProfile->company_user_id,
    ])->assertStatus(422)->assertJsonValidationErrors(['driver_id']);

    $availableIds = collect($this->actingAs($operator)->getJson('/api/operator/drivers?trip_date='.now()->toDateString())->assertSuccessful()->json('data'))
        ->pluck('company_user_id')
        ->map(fn ($id) => (int) $id);
    expect($availableIds)->not->toContain((int) $driver->companyProfile->company_user_id);
});

test('reject keeps the staff member assigned, tells them to proceed and blocks a repeat request', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $conductor = rqUser('conductor');
    $trip = rqTrip($operator, $driver, $conductor);

    $this->actingAs($conductor)->patchJson("/api/conductor/trips/{$trip->trip_id}/decline", ['reason_code' => 'vehicle_issue'])->assertSuccessful();
    $requestId = TripAssignmentRequest::where('trip_id', $trip->trip_id)->value('id');

    $titles = [];
    rqFakeFcm($titles);

    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/reject")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'rejected');

    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'conductor_id' => $conductor->companyProfile->company_user_id]);
    expect($titles)->toContain([$conductor->user_id, 'Decline Request Rejected']);

    $this->actingAs($conductor)->patchJson("/api/conductor/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])
        ->assertStatus(422);
});

test('only the owning operator can decide, and only once', function () {
    $operator = rqUser('operator');
    $otherOperator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();
    $requestId = TripAssignmentRequest::where('trip_id', $trip->trip_id)->value('id');

    $this->actingAs($otherOperator)->patchJson("/api/operator/trip-requests/{$requestId}/approve")->assertForbidden();

    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/approve")->assertSuccessful();
    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/approve")->assertStatus(422);
    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/reject")->assertStatus(422);
});

test('a request cannot be approved once the trip has departed', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();
    $requestId = TripAssignmentRequest::where('trip_id', $trip->trip_id)->value('id');
    $trip->update(['status' => 'departed']);

    $this->actingAs($operator)->patchJson("/api/operator/trip-requests/{$requestId}/approve")->assertStatus(422);
    $this->assertDatabaseHas('trips', ['trip_id' => $trip->trip_id, 'driver_id' => $driver->companyProfile->company_user_id]);
});

test('the operator sees only their own pending requests with a count', function () {
    $operator = rqUser('operator');
    $otherOperator = rqUser('operator');
    $driver = rqUser('driver');
    $otherDriver = rqUser('driver');
    $mine = rqTrip($operator, $driver, rqUser('conductor'));
    $theirs = rqTrip($otherOperator, $otherDriver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$mine->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();
    $this->actingAs($otherDriver)->patchJson("/api/driver/trips/{$theirs->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();

    $this->actingAs($operator)->getJson('/api/operator/trip-requests')
        ->assertSuccessful()
        ->assertJsonPath('data.pending_count', 1)
        ->assertJsonPath('data.requests.0.trip_id', $mine->trip_id);
});

test('staff trip lists carry the For Approval status so the badge can show it', function () {
    $operator = rqUser('operator');
    $driver = rqUser('driver');
    $trip = rqTrip($operator, $driver, rqUser('conductor'));

    $this->actingAs($driver)->patchJson("/api/driver/trips/{$trip->trip_id}/decline", ['reason_code' => 'sick'])->assertSuccessful();

    $this->actingAs($driver)->getJson('/api/driver/trips')
        ->assertSuccessful()
        ->assertJsonPath('data.0.driver_request_status', 'for_approval');
});
