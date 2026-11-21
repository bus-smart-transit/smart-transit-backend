<?php

use App\Models\Company;
use App\Models\Route;
use App\Models\StaffUser;
use App\Models\User;
use App\Support\RouteColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-21 (user): every route gets its own colour, never one already in use. SAMPLE places.

it('picks the first palette colour not in use', function () {
    expect(RouteColor::nextFree([]))->toBe('#16a34a')
        ->and(RouteColor::nextFree(['#16A34A', '#9333ea']))->toBe('#2563eb');
});

it('makes new colours once the palette is used up, never repeating one', function () {
    $used = RouteColor::PALETTE;
    for ($i = 0; $i < 40; $i++) {
        $next = RouteColor::nextFree($used);
        expect($next)->toMatch('/^#[0-9a-f]{6}$/')->and(in_array($next, $used, true))->toBeFalse();
        $used[] = $next;
    }
    expect(count(array_unique($used)))->toBe(count(RouteColor::PALETTE) + 40);
});

it('gives each route the Company Admin creates a colour no other route has', function () {
    $company = Company::create(['company_name' => 'Rc Co']);
    $user = User::create(['username' => 'rc'.Str::random(6), 'email' => 'rc.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Rc Op', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => true]);
    Route::create(['route_name' => 'Existing', 'origin' => 'A', 'destination' => 'B', 'color' => '#16a34a']);

    $this->actingAs($user->fresh(), 'sanctum');
    $first = $this->postJson('/api/operator/routes', ['route_name' => 'One', 'origin' => 'A', 'destination' => 'C'])->assertOk()->json('data.color');
    $second = $this->postJson('/api/operator/routes', ['route_name' => 'Two', 'origin' => 'A', 'destination' => 'D'])->assertOk()->json('data.color');

    expect($first)->toBe('#2563eb')->and($second)->toBe('#9333ea');
});
