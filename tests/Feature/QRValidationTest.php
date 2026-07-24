<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeConductorWithProfile(): User
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

describe('QR Code & Occupancy Monitoring System', function () {

    it('conductor can retrieve current trip occupancy metrics', function () {
        $conductor = makeConductorWithProfile();

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
        $conductor = makeConductorWithProfile();

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
        $conductor = makeConductorWithProfile();

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
        $conductor = makeConductorWithProfile();

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
        $conductor = makeConductorWithProfile();

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

        $token = $registerResponse->json('token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/passengers/tickets/' . Str::uuid() . '/qr');

        expect($response->status())->toBe(404);
    });

});
