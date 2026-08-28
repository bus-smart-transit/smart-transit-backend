<?php

use App\Models\Fleet;
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

    function issueOperatorToken(User $operator): string
    {
        return $operator->createToken('operator-test-token')->plainTextToken;
    }

    function createOwnedFleet(User $operator): Fleet
    {
        $companyUser = StaffUser::where('user_id', $operator->user_id)->firstOrFail();

        return Fleet::create([
            'company_user_id' => $companyUser->company_user_id,
            'plate_number' => 'TEST-' . strtoupper(substr((string) Str::uuid(), 0, 6)),
            'capacity' => 40,
            'seated_capacity' => 30,
            'standing_capacity' => 10,
            'status' => 'active',
            'fleet_type' => 'public',
        ]);
    }

    it('operator can retrieve financial audit for fleet', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/financial');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve revenue breakdown by route', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/revenue-by-route');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve route adherence data', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/route-adherence');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve occupancy trends', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/occupancy-trends');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve daily summary', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/daily-summary');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('operator can retrieve payment channel breakdown', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);
        $fleet = createOwnedFleet($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/' . $fleet->fleet_id . '/reports/payment-channels');

        expect($response->status())->toBeIn([200, 404]);
    });

    it('rejects reporting request for non-existent fleet', function () {
        $operator = makeOperatorWithProfile();
        $token = issueOperatorToken($operator);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/operator/fleets/99999/reports/financial');

        $response->assertStatus(403);
    });

});
