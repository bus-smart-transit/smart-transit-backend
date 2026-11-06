<?php

use App\Models\Company;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-06 (user's permission table): a Company Admin adds Operators to their own company and can turn them off and
// on again (someone resigned or retired). They cannot make anyone a Company Admin, cannot change another Company
// Admin, and cannot turn off their own account. A plain Operator cannot add or change staff.

function caoOperator(Company $company, bool $admin, string $name = 'Op'): User
{
    $user = User::create(['username' => 'cao'.Str::random(6), 'email' => 'cao.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => $name, 'phone_num' => '+639171234567', 'address' => 'Sample', 'company_id' => $company->company_id, 'is_company_admin' => $admin]);

    return $user->fresh();
}

function caoNewOperator(array $extra = []): array
{
    return array_merge([
        'name' => 'New Dispatcher',
        'email' => 'dispatcher.'.Str::lower(Str::random(5)).'@example.com',
        'password' => 'Right-password-1',
        'phone_num' => '09171234568',
        'address' => 'Sample City',
        'role' => 'operator',
    ], $extra);
}

it('lets a Company Admin add an Operator to their own company, never as a Company Admin', function () {
    $company = Company::create(['company_name' => 'Cao Bus Co']);
    $admin = caoOperator($company, true, 'Boss');
    $this->actingAs($admin, 'sanctum');

    $created = $this->postJson('/api/operator/accounts', caoNewOperator(['is_company_admin' => true, 'company_name' => 'Other Co']))
        ->assertUnprocessable()->assertJsonValidationErrors(['company_name']);
    $created = $this->postJson('/api/operator/accounts', caoNewOperator(['is_company_admin' => true]))->assertSuccessful()->json('data');

    $profile = StaffUser::find($created['profile']['company_user_id']);
    expect($profile->company_id)->toBe($company->company_id)
        ->and((bool) $profile->is_company_admin)->toBeFalse()
        ->and($profile->user->role)->toBe('operator')
        ->and(DB::table('audit_logs')->where('action', 'staff.create')->value('context'))->toContain('operator');

    $list = $this->getJson('/api/operator/staff?role=operator')->assertSuccessful()->json('data');
    expect(collect($list['staff'])->pluck('role_label')->sort()->values()->all())->toBe(['Company Admin', 'Operator'])
        ->and($list['totals']['operator'])->toBe(2)
        ->and(collect($list['staff'])->firstWhere('is_you', true)['manageable'])->toBeFalse()
        ->and(collect($list['staff'])->firstWhere('role_label', 'Operator')['manageable'])->toBeTrue();
});

it('lets a Company Admin turn an Operator off and on, but not themselves or another Company Admin', function () {
    $company = Company::create(['company_name' => 'Cao Bus Co']);
    $admin = caoOperator($company, true, 'Boss');
    $otherAdmin = caoOperator($company, true, 'Co-owner');
    $dispatcher = caoOperator($company, false, 'Dispatcher');
    $dispatcher->createToken('phone');
    $this->actingAs($admin, 'sanctum');

    $id = fn (User $user) => $user->companyProfile->company_user_id;
    $this->patchJson("/api/operator/staff/{$id($dispatcher)}/deactivate")->assertSuccessful()->assertJsonPath('data.deactivated', true);
    expect($dispatcher->fresh()->deactivated_at)->not->toBeNull()
        ->and($dispatcher->tokens()->count())->toBe(0);
    $this->patchJson("/api/operator/staff/{$id($dispatcher)}/reactivate")->assertSuccessful()->assertJsonPath('data.deactivated', false);
    $this->patchJson("/api/operator/staff/{$id($dispatcher)}", ['phone_num' => '09171234569'])->assertSuccessful();

    $this->patchJson("/api/operator/staff/{$id($admin)}/deactivate")->assertForbidden()->assertJsonPath('message', 'You cannot change your own account here. Use My Account instead.');
    $this->patchJson("/api/operator/staff/{$id($otherAdmin)}/deactivate")->assertForbidden()->assertJsonPath('message', 'Only the Super Admin can change a Company Admin account.');
    $this->patchJson("/api/operator/staff/{$id($otherAdmin)}", ['phone_num' => '09171234569'])->assertForbidden();
    expect($otherAdmin->fresh()->deactivated_at)->toBeNull();
});

it('keeps another company\'s Operators out of reach, and a plain Operator cannot add or change staff', function () {
    $company = Company::create(['company_name' => 'Cao Bus Co']);
    $elsewhere = caoOperator(Company::create(['company_name' => 'Other Bus Co']), false, 'Stranger');
    $admin = caoOperator($company, true, 'Boss');
    $dispatcher = caoOperator($company, false, 'Dispatcher');

    $this->actingAs($admin, 'sanctum');
    $this->patchJson("/api/operator/staff/{$elsewhere->companyProfile->company_user_id}/deactivate")->assertNotFound();
    expect(collect($this->getJson('/api/operator/staff')->json('data.staff'))->pluck('name')->all())->not->toContain('Stranger');

    app('auth')->forgetGuards();
    $this->actingAs($dispatcher, 'sanctum');
    $this->postJson('/api/operator/accounts', caoNewOperator())->assertForbidden();
    $this->patchJson("/api/operator/staff/{$admin->companyProfile->company_user_id}/deactivate")->assertForbidden();
    $this->getJson('/api/operator/staff')->assertSuccessful();
});
