<?php

use App\Models\Fleet;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Regression tests for GET /api/admin/audit-logs and GET /api/admin/reports/stored.
// These previously lived as raw DB::table() closures directly in routes/api.php
// (a Controller -> Service -> Repository layering violation) and were moved to
// ReportingController -> ReportingService -> ReportingRepository.

function makeAdminUser(): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'role' => 'admin',
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

test('admin can list audit logs, newest first, with an action filter', function () {
    $admin = makeAdminUser();

    DB::table('audit_logs')->insert([
        [
            'actor_type' => 'staff',
            'actor_id' => $admin->user_id,
            'action' => 'trip.status_override',
            'subject_type' => 'Trip',
            'subject_id' => 1,
            'context' => null,
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subMinute(),
        ],
        [
            'actor_type' => 'staff',
            'actor_id' => $admin->user_id,
            'action' => 'trip.decline',
            'subject_type' => 'Trip',
            'subject_id' => 2,
            'context' => null,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ],
    ]);

    $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs?action=trip.decline');

    $response->assertSuccessful()->assertJsonPath('status', 'success');
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['action'])->toBe('trip.decline');
});

test('a non-admin cannot access the audit log viewer', function () {
    $operator = StaffUser::create([
        'user_id' => User::create([
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role' => 'operator',
        ])->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ])->user;

    $this->actingAs($operator)->getJson('/api/admin/audit-logs')->assertStatus(403);
});

test('admin can list stored reports filtered by fleet and type', function () {
    $admin = makeAdminUser();
    $operator = makeAdminUser();
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'ZZZ-' . fake()->unique()->numerify('###'),
        'capacity' => 50,
        'seated_capacity' => 40,
        'standing_capacity' => 10,
        'status' => 'active',
    ]);
    $fleet->refresh();

    DB::table('scheduled_reports')->insert([
        [
            'fleet_id' => $fleet->fleet_id,
            'report_type' => 'daily_summary',
            'report_date' => now()->toDateString(),
            'payload' => json_encode(['total_trips' => 3]),
            'status' => 'generated',
            'generated_at' => now(),
        ],
        [
            'fleet_id' => $fleet->fleet_id,
            'report_type' => 'financial',
            'report_date' => now()->toDateString(),
            'payload' => json_encode(['total_revenue' => 500]),
            'status' => 'generated',
            'generated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($admin)->getJson(
        "/api/admin/reports/stored?fleet_id={$fleet->fleet_id}&report_type=daily_summary"
    );

    $response->assertSuccessful()->assertJsonPath('status', 'success');
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['report_type'])->toBe('daily_summary');
    expect($data[0]['payload'])->toBe(['total_trips' => 3]);
});
