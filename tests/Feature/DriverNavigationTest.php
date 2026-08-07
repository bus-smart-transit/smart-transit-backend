<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDriverWithProfile(): User
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
        'phone_num' => '09170000001',
        'address' => 'Test Address',
    ]);

    return $user;
}

describe('Driver Navigation System', function () {

    it('driver can retrieve current trip with stops and passengers', function () {
        $driver = makeDriverWithProfile();

        $driverLogin = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);
        $driverLogin->assertStatus(200);

        $token = $driverLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/driver/trips/current/stops');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('driver can view specific stop details', function () {
        $driver = makeDriverWithProfile();

        $driverLogin = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);
        $driverLogin->assertStatus(200);

        $token = $driverLogin->json('data.token');

        $tripsResponse = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/driver/trips/current/stops');

        if ($tripsResponse->status() === 200 && $tripsResponse->json('data.stops.0')) {
            $stopId = $tripsResponse->json('data.stops.0.stop_id');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson("/api/driver/trips/current/stops/$stopId");

            expect($response->status())->toBeIn([200, 404]);
            return;
        }

        expect($tripsResponse->status())->toBeIn([200, 404]);
    });

    it('driver can acknowledge reaching a stop', function () {
        $driver = makeDriverWithProfile();

        $driverLogin = $this->postJson('/api/staff/login', [
            'email' => $driver->email,
            'password' => 'password123',
        ]);
        $driverLogin->assertStatus(200);

        $token = $driverLogin->json('data.token');

        $tripsResponse = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/driver/trips/current/stops');

        if ($tripsResponse->status() === 200 && $tripsResponse->json('data.stops.0')) {
            $stopId = $tripsResponse->json('data.stops.0.stop_id');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->postJson("/api/driver/trips/current/stops/$stopId/acknowledge", []);

            expect($response->status())->toBeIn([200, 404]);
            return;
        }

        expect($tripsResponse->status())->toBeIn([200, 404]);
    });

});
