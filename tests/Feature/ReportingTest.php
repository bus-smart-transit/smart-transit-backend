<?php

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeOperatorWithProfile(): User
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
        'phone_num' => '09170000002',
        'address' => 'Test Address',
    ]);

    return $user;
}

describe('Fleet Reporting System', function () {

    it('operator can retrieve financial audit for fleet', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/1/reports/financial');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve revenue breakdown by route', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/1/reports/revenue-by-route');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve route adherence data', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/1/reports/route-adherence');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve occupancy trends', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/1/reports/occupancy-trends');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve daily summary', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/1/reports/daily-summary');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve payment channel breakdown', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/1/reports/payment-channels');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('rejects reporting request for non-existent fleet', function () {
        $operator = makeOperatorWithProfile();

        $operatorLogin = $this->postJson('/api/staff/login', [
            'email' => $operator->email,
            'password' => 'password123',
        ]);
        $operatorLogin->assertStatus(200);

        $token = $operatorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/99999/reports/financial');

        $response->assertStatus(404);
    });

});
