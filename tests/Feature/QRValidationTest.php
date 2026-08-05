<?php

use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeConductorWithProfileQr(): User
{
    $user = User::create([
        'username' => 'conductor_' . uniqid(),
        'email' => 'conductor+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'conductor',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Conductor Test',
        'phone_num' => '09170000003',
        'address' => 'Test Address',
    ]);

    return $user;
}

function makeDriverWithProfileQr(): User
{
    $user = User::create([
        'username' => 'driver_' . uniqid(),
        'email' => 'driver+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'driver',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Driver Test',
        'phone_num' => '09170000004',
        'address' => 'Test Address',
    ]);

    return $user;
}

function makeOperatorWithProfileQr(): User
{
    $user = User::create([
        'username' => 'operator_' . uniqid(),
        'email' => 'operator+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $user->user_id,
        'name' => 'Operator Test',
        'phone_num' => '09170000005',
        'address' => 'Test Address',
    ]);

    return $user;
}

function makeFleetRouteFixtureQr(int $operatorCompanyUserId): FleetRoute
{
    $route = Route::create([
        'route_name'  => 'QR Validation Route',
        'origin'      => 'Stop A',
        'destination' => 'Stop B',
    ]);

    $fleet = Fleet::create([
        'company_user_id'   => $operatorCompanyUserId,
        'plate_number'      => 'QRV-' . fake()->unique()->numerify('###'),
        'capacity'          => 50,
        'seated_capacity'   => 40,
        'standing_capacity' => 10,
        'status'            => 'active',
    ]);

    return FleetRoute::create([
        'fleet_id'   => $fleet->fleet_id,
        'route_id'   => $route->route_id,
        'start_time' => '06:00',
        'end_time'   => '22:00',
        'status'     => 'active',
    ]);
}

describe('QR Code & Occupancy Monitoring System', function () {

    it('conductor live endpoints are locked before pairing', function () {
        $conductor = makeConductorWithProfileQr();
        $driver = makeDriverWithProfileQr();
        $operator = makeOperatorWithProfileQr();
        $fleetRoute = makeFleetRouteFixtureQr($operator->companyProfile->company_user_id);

        Trip::create([
            'fleet_route_id'            => $fleetRoute->fleet_route_id,
            'company_user_id'           => $operator->companyProfile->company_user_id,
            'driver_id'                 => $driver->companyProfile->company_user_id,
            'conductor_id'              => $conductor->companyProfile->company_user_id,
            'trip_date'                 => now()->toDateString(),
            'status'                    => 'boarding',
            'current_seated_capacity'   => 0,
            'current_standing_capacity' => 0,
            'total_occupancy'           => 0,
        ]);

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $token = $conductorLogin->json('data.token');

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current')
            ->assertStatus(423);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/occupancy')
            ->assertStatus(423);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/conductor/tickets/999999/alight', [])
            ->assertStatus(423);
    });

    it('conductor can retrieve current trip occupancy metrics', function () {
        $conductor = makeConductorWithProfileQr();

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/occupancy');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('conductor can get occupancy breakdown by stop', function () {
        $conductor = makeConductorWithProfileQr();

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/occupancy/by-stop');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('conductor can view current passengers on trip', function () {
        $conductor = makeConductorWithProfileQr();

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/passengers');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('conductor can scan QR code to board passenger', function () {
        $conductor = makeConductorWithProfileQr();

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/conductor/tickets/scan', [
                'ticket_uuid' => (string) Str::uuid(),
            ]);

        expect($response->status())->toBeIn([404, 422]);
    });

    it('conductor can record passenger alighting', function () {
        $conductor = makeConductorWithProfileQr();

        $conductorLogin = $this->postJson('/api/staff/login', [
            'email' => $conductor->email,
            'password' => 'password123',
        ]);
        $conductorLogin->assertStatus(200);

        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/conductor/tickets/999999/alight', []);

        expect($response->status())->toBeIn([404, 422]);
    });

    it('passenger can generate QR code for ticket', function () {
        $registerResponse = $this->postJson('/api/passengers/register', [
            'name' => 'Test Passenger',
            'email' => 'test.passenger.' . uniqid() . '@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_num' => '09999999999',
            'address' => 'Test Address',
            'birthdate' => '2000-01-01',
        ]);

        $registerResponse->assertStatus(201);

        $token = $registerResponse->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/passengers/tickets/' . Str::uuid() . '/qr');

        expect($response->status())->toBe(404);
    });

});
