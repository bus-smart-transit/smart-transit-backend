<?php

use App\Models\Company;
use App\Models\Fleet;
use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Batch 48 Phase 3: the Super Admin adds and renames companies, adds a company's Company Admin, lists every account
// and turns accounts off and on (never deleted). Not their own account, not the last Super Admin.

function saaStaff(string $role, ?Company $company = null, bool $companyAdmin = false, string $name = 'Saa'): User
{
    $user = User::create(['username' => 'saa'.Str::random(8), 'email' => 'saa.'.Str::lower(Str::random(8)).'@example.com', 'password' => 'Right-password-1', 'role' => $role]);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '+639171234567', 'address' => 'Sample', 'company_id' => $company?->company_id, 'is_company_admin' => $companyAdmin]);

    return $user->fresh();
}

function saaPassenger(string $name = 'Pia Passenger'): User
{
    $user = User::create(['username' => 'saap'.Str::random(6), 'email' => 'saap.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'passenger', 'email_verified_at' => now()]);
    PassengerUser::create(['user_id' => $user->user_id, 'passenger_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '+639171234567']);

    return $user->fresh();
}

it('adds and renames a company (the buses take the new name) and adds its Company Admin', function () {
    $this->actingAs(saaStaff('admin'), 'sanctum');

    $id = $this->postJson('/api/admin/companies', ['company_name' => '  Davao  Express '])->assertSuccessful()->json('data.company_id');
    $this->postJson('/api/admin/companies', ['company_name' => 'davao express'])->assertUnprocessable()->assertJsonValidationErrors(['company_name']);

    Fleet::create(['company_user_id' => saaStaff('operator', Company::find($id))->companyProfile->company_user_id, 'company_id' => $id, 'plate_number' => 'SAA-1', 'capacity' => 40, 'seated_capacity' => 30, 'standing_capacity' => 10, 'status' => 'active', 'bus_company_name' => 'Davao Express']);
    $this->patchJson("/api/admin/companies/{$id}", ['company_name' => 'Davao Express Lines'])->assertSuccessful();
    expect(Fleet::where('plate_number', 'SAA-1')->value('bus_company_name'))->toBe('Davao Express Lines');

    $made = $this->postJson("/api/admin/companies/{$id}/company-admins", [
        'name' => 'Owner One', 'email' => 'Owner.One@Example.com', 'password' => 'Right-password-1', 'phone_num' => '09171234567', 'address' => 'Sample City',
    ])->assertSuccessful()->json('data');

    $profile = StaffUser::find($made['company_user_id']);
    expect($profile->company_id)->toBe($id)
        ->and((bool) $profile->is_company_admin)->toBeTrue()
        ->and($profile->user->role)->toBe('operator')
        ->and($profile->user->email)->toBe('owner.one@example.com');

    $row = collect($this->getJson('/api/admin/companies')->assertOk()->json('data'))->firstWhere('company_id', $id);
    expect($row)->toMatchArray(['company_name' => 'Davao Express Lines', 'operators' => 2, 'buses' => 1])
        ->and($row['company_admins'][0]['name'])->toBe('Owner One')
        ->and(DB::table('audit_logs')->whereIn('action', ['company.create', 'company.rename', 'company.admin_added'])->count())->toBe(3);
});

it('lists every account with search and role, and turns a passenger off so they cannot sign in, then on again', function () {
    $admin = saaStaff('admin');
    $company = Company::create(['company_name' => 'Saa Bus Co']);
    saaStaff('operator', $company, true, 'Owner');
    $passenger = saaPassenger('Pia Passenger');
    $passenger->createToken('phone');
    $this->actingAs($admin, 'sanctum');

    $all = $this->getJson('/api/admin/users')->assertOk()->json('data');
    expect($all['pagination']['total'])->toBe(3)
        ->and(collect($all['users'])->firstWhere('name', 'Owner'))->toMatchArray(['role_label' => 'Company Admin', 'company_name' => 'Saa Bus Co'])
        ->and(collect($all['users'])->firstWhere('is_you', true)['role_label'])->toBe('Super Admin');
    expect(collect($this->getJson('/api/admin/users?search=pia&role=passenger')->json('data.users'))->pluck('name')->all())->toBe(['Pia Passenger']);

    $this->patchJson("/api/admin/users/{$passenger->user_id}/deactivate")->assertOk()->assertJsonPath('data.deactivated', true);
    expect($passenger->tokens()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->postJson('/api/auth/login', ['portal' => 'passenger', 'email' => $passenger->email, 'password' => 'Right-password-1'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'This account is turned off. Contact SmartTransit to turn it on again.');

    $this->actingAs($admin, 'sanctum');
    $this->patchJson("/api/admin/users/{$passenger->user_id}/reactivate")->assertOk()->assertJsonPath('data.deactivated', false);
    expect(DB::table('audit_logs')->where('action', 'user.deactivate')->exists())->toBeTrue();
});

it('never turns off your own account or the last Super Admin, and gives or takes Company Admin only for a company Operator', function () {
    $admin = saaStaff('admin');
    $company = Company::create(['company_name' => 'Saa Bus Co']);
    $operator = saaStaff('operator', $company, false, 'Dispatcher');
    $passenger = saaPassenger();
    $this->actingAs($admin, 'sanctum');

    $this->patchJson("/api/admin/users/{$admin->user_id}/deactivate")->assertForbidden();

    $second = saaStaff('admin');
    $this->patchJson("/api/admin/users/{$second->user_id}/deactivate")->assertOk();
    $second->fresh()->forceFill(['deactivated_at' => null])->save();
    $admin->forceFill(['deactivated_at' => now()])->save(); // now $second is the only one on
    $this->patchJson("/api/admin/users/{$second->user_id}/deactivate")->assertUnprocessable()->assertJsonPath('errors.user.0', 'This is the last Super Admin account that is on. It cannot be turned off.');
    $admin->forceFill(['deactivated_at' => null])->save();

    $this->patchJson("/api/admin/users/{$operator->user_id}/company-admin", ['is_company_admin' => true])->assertOk()->assertJsonPath('data.role_label', 'Company Admin');
    $this->patchJson("/api/admin/users/{$operator->user_id}/company-admin", ['is_company_admin' => false])->assertOk()->assertJsonPath('data.role_label', 'Operator');
    $this->patchJson("/api/admin/users/{$passenger->user_id}/company-admin", ['is_company_admin' => true])->assertUnprocessable();
    $this->patchJson('/api/admin/users/999999/deactivate')->assertNotFound();
});

it('pages the audit log newest first with who did it, filtered by action, role and date', function () {
    $admin = saaStaff('admin', null, false, 'Ada Admin');
    $operator = saaStaff('operator', Company::create(['company_name' => 'Saa Bus Co']), true, 'Owen Operator');
    DB::table('audit_logs')->insert([
        ['actor_type' => 'staff', 'actor_id' => $operator->user_id, 'action' => 'fare.update', 'subject_type' => 'FareRule', 'subject_id' => 4, 'context' => json_encode(['note' => 'x']), 'created_at' => '2026-11-01 09:00:00'],
        ['actor_type' => 'system', 'actor_id' => null, 'action' => 'refund.reconcile', 'subject_type' => null, 'subject_id' => null, 'context' => null, 'created_at' => '2026-11-03 09:00:00'],
        ['actor_type' => 'staff', 'actor_id' => $admin->user_id, 'action' => 'company.create', 'subject_type' => 'Company', 'subject_id' => 1, 'context' => null, 'created_at' => '2026-11-05 09:00:00'],
    ]);
    $this->actingAs($admin, 'sanctum');

    $all = $this->getJson('/api/admin/audit-log')->assertOk()->json('data');
    expect(collect($all['entries'])->pluck('action')->all())->toBe(['company.create', 'refund.reconcile', 'fare.update'])
        ->and($all['entries'][2]['who'])->toBe(['name' => 'Owen Operator', 'username' => $operator->username, 'role_label' => 'Operator'])
        ->and($all['entries'][2]['subject'])->toBe('FareRule 4')
        ->and($all['entries'][1]['who']['name'])->toBe('System');

    expect(collect($this->getJson('/api/admin/audit-log?action=FARE')->json('data.entries'))->pluck('action')->all())->toBe(['fare.update'])
        ->and(collect($this->getJson('/api/admin/audit-log?role=system')->json('data.entries'))->pluck('action')->all())->toBe(['refund.reconcile'])
        ->and(collect($this->getJson('/api/admin/audit-log?from=2026-11-02&to=2026-11-04')->json('data.entries'))->pluck('action')->all())->toBe(['refund.reconcile']);
    $this->getJson('/api/admin/audit-log?from=2026-11-05&to=2026-11-01')->assertUnprocessable();
});
