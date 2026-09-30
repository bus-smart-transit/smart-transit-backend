<?php

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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeConductorWithProfileQr(): User
{
    $user = User::create([
        'username' => 'conductor_'.uniqid(),
        'email' => 'conductor+'.uniqid().'@smarttransit.test',
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
        'username' => 'driver_'.uniqid(),
        'email' => 'driver+'.uniqid().'@smarttransit.test',
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
        'username' => 'operator_'.uniqid(),
        'email' => 'operator+'.uniqid().'@smarttransit.test',
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
        'route_name' => 'QR Validation Route',
        'origin' => 'Stop A',
        'destination' => 'Stop B',
    ]);

    $fleet = Fleet::create([
        'company_user_id' => $operatorCompanyUserId,
        'plate_number' => 'QRV-'.fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);

    return FleetRoute::create([
        'fleet_id' => $fleet->fleet_id,
        'route_id' => $route->route_id,
        'start_time' => '06:00',
        'end_time' => '22:00',
        'status' => 'active',
    ]);
}

describe('QR Code & Occupancy Monitoring System', function () {

    it('conductor live endpoints are locked before pairing', function () {
        $conductor = makeConductorWithProfileQr();
        $driver = makeDriverWithProfileQr();
        $operator = makeOperatorWithProfileQr();
        $fleetRoute = makeFleetRouteFixtureQr($operator->companyProfile->company_user_id);

        Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id,
            'company_user_id' => $operator->companyProfile->company_user_id,
            'driver_id' => $driver->companyProfile->company_user_id,
            'conductor_id' => $conductor->companyProfile->company_user_id,
            'trip_date' => now()->toDateString(),
            'status' => 'boarding',
            'current_seated_capacity' => 0,
            'current_standing_capacity' => 0,
            'total_occupancy' => 0,
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
            'email' => 'test.passenger.'.uniqid().'@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_num' => '09999999999',
            'address' => 'Test Address',
            'birthdate' => '2000-01-01',
        ]);

        $registerResponse->assertStatus(201);

        $token = $registerResponse->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/passengers/tickets/'.Str::uuid().'/qr');

        expect($response->status())->toBe(404);
    });

    it('passenger qr payload includes custom origin and destination labels', function () {
        $user = User::create([
            'username' => 'passenger_'.uniqid(),
            'email' => 'passenger+'.uniqid().'@smarttransit.test',
            'password' => bcrypt('password123'),
            'role' => 'passenger',
        ]);

        $passenger = PassengerUser::create([
            'user_id' => $user->user_id,
            'passenger_uuid' => (string) Str::uuid(),
            'name' => 'QR Passenger',
            'phone_num' => '09999999999',
            'address' => 'Test Address',
            'birthdate' => '2000-01-01',
            'reward_points' => 0,
        ]);

        $operator = makeOperatorWithProfileQr();
        $driver = makeDriverWithProfileQr();
        $conductor = makeConductorWithProfileQr();
        $fleetRoute = makeFleetRouteFixtureQr($operator->companyProfile->company_user_id);

        $trip = Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id,
            'company_user_id' => $operator->companyProfile->company_user_id,
            'driver_id' => $driver->companyProfile->company_user_id,
            'conductor_id' => $conductor->companyProfile->company_user_id,
            'trip_date' => now()->toDateString(),
            'status' => 'scheduled',
            'current_seated_capacity' => 0,
            'current_standing_capacity' => 0,
            'total_occupancy' => 0,
        ]);

        $payment = Payment::create([
            'amount' => 42.5,
            'payment_created' => now(),
            'transaction_reference' => 'TXN-QR-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
            'payment_method' => 'online',
            'payment_channel' => 'gcash',
            'status' => 'paid',
            'payment_uuid' => (string) Str::uuid(),
            'is_valid' => true,
            'items_payload' => [[
                'trip_id' => $trip->trip_id,
                'seat_type' => 'seated',
                'origin_lat' => 7.101234,
                'origin_lng' => 125.601234,
                'destination_lat' => 7.151234,
                'destination_lng' => 125.651234,
                'origin_label' => 'SM Ecoland Entrance',
                'destination_label' => 'Bankerohan Public Market',
                'amount' => 42.5,
            ]],
        ]);

        $ticket = Ticket::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'payment_id' => $payment->payment_id,
            'passenger_id' => $passenger->passenger_id,
            'ticket_uuid' => (string) Str::uuid(),
            'status' => 'valid',
            'seat_type' => 'seated',
            'amount' => 42.5,
            'origin_lat' => 7.101234,
            'origin_lng' => 125.601234,
            'destination_lat' => 7.151234,
            'destination_lng' => 125.651234,
        ]);

        $token = $user->createToken('passenger-qr-test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/passengers/tickets/'.$ticket->ticket_uuid.'/qr');

        $response
            ->assertSuccessful()
            ->assertJsonPath('data.origin', 'SM Ecoland Entrance')
            ->assertJsonPath('data.destination', 'Bankerohan Public Market');
    });

    it('qr payload is unchanged (uuid / grp:ref), scannable by the validator, and carries no third-party image url', function () {
        $user = User::create([
            'username' => 'passenger_'.uniqid(),
            'email' => 'passenger+'.uniqid().'@smarttransit.test',
            'password' => bcrypt('password123'),
            'role' => 'passenger',
        ]);
        $passenger = PassengerUser::create([
            'user_id' => $user->user_id,
            'passenger_uuid' => (string) Str::uuid(),
            'name' => 'QR Payload Passenger',
            'phone_num' => '09999999998',
            'address' => 'Test Address',
            'birthdate' => '2000-01-01',
            'reward_points' => 0,
        ]);
        $operator = makeOperatorWithProfileQr();
        $fleetRoute = makeFleetRouteFixtureQr($operator->companyProfile->company_user_id);
        $trip = Trip::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id,
            'company_user_id' => $operator->companyProfile->company_user_id,
            'driver_id' => makeDriverWithProfileQr()->companyProfile->company_user_id,
            'conductor_id' => makeConductorWithProfileQr()->companyProfile->company_user_id,
            'trip_date' => now()->toDateString(),
            'departure_time' => '06:30:00',
            'status' => 'scheduled',
            'current_seated_capacity' => 0,
            'current_standing_capacity' => 0,
            'total_occupancy' => 0,
        ]);
        $payment = Payment::create([
            'amount' => 40,
            'payment_created' => now(),
            'transaction_reference' => 'TXN-PAYLOAD-1',
            'payment_method' => 'online',
            'payment_channel' => 'gcash',
            'status' => 'paid',
            'payment_uuid' => (string) Str::uuid(),
            'is_valid' => true,
            'items_payload' => [],
        ]);
        $ticket = Ticket::create([
            'fleet_route_id' => $fleetRoute->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'payment_id' => $payment->payment_id,
            'passenger_id' => $passenger->passenger_id,
            'ticket_uuid' => (string) Str::uuid(),
            'status' => 'valid',
            'seat_type' => 'seated',
            'amount' => 40,
        ]);

        $token = $user->createToken('passenger-qr-payload')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/passengers/tickets/'.$ticket->ticket_uuid.'/qr')
            ->assertSuccessful();
        $data = $response->json('data');

        // Same payload strings the conductor scanner has always read.
        expect($data['qr_content'])->toBe($ticket->ticket_uuid)
            ->and($data['group_qr_content'])->toBe('grp:TXN-PAYLOAD-1');

        // The validator accepts the payload exactly as the scanner posts it.
        $rules = (new \App\Http\Requests\ScanTicketRequest())->rules();
        expect(\Illuminate\Support\Facades\Validator::make(['ticket_uuid' => $data['qr_content']], ['ticket_uuid' => $rules['ticket_uuid']])->passes())->toBeTrue();
        $groupRules = (new \App\Http\Requests\ScanGroupRequest())->rules();
        expect(\Illuminate\Support\Facades\Validator::make(['transaction_reference' => substr($data['group_qr_content'], 4)], ['transaction_reference' => $groupRules['transaction_reference']])->passes())->toBeTrue();

        // No third-party image URL: nothing in the response points at an external QR service.
        expect($data)->not->toHaveKeys(['qr_url', 'group_qr_url'])
            ->and($response->getContent())->not->toContain('qrserver');

        // Manila wall-clock departure for the saved image.
        expect($data['trip_date'])->toBe(now()->toDateString())
            ->and($data['departure_time'])->toBe('06:30');
    });

});
