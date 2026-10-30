<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// 2026-10-30: staff:create makes the first real account on a fresh server, with the password typed at a hidden prompt.

it('creates a staff account that can sign in, with the email saved lowercase', function () {
    $this->artisan('staff:create', ['role' => 'operator', 'email' => 'Ana.Reyes@Example.com', 'name' => 'Ana Reyes', 'phone' => '0917 123 4567', 'address' => 'Tagum City'])
        ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-9')
        ->expectsQuestion('Type the password again', 'correct-horse-9')
        ->assertSuccessful();

    $user = User::where('email', 'ana.reyes@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe('operator')
        ->and($user->username)->toBe('ana.reyes')
        ->and(Hash::check('correct-horse-9', $user->password))->toBeTrue()
        ->and($user->companyProfile?->name)->toBe('Ana Reyes')
        ->and($user->companyProfile?->company_user_uuid)->not->toBeEmpty();

    $this->postJson('/api/staff/login', ['email' => 'ana.reyes@example.com', 'password' => 'correct-horse-9', 'role' => 'operator'])
        ->assertSuccessful();
});

it('refuses a short or mistyped password and creates nothing', function () {
    $args = ['role' => 'operator', 'email' => 'ops@example.com', 'name' => 'Ops Person', 'phone' => '09171234567', 'address' => 'Tagum City'];

    $this->artisan('staff:create', $args)
        ->expectsQuestion('Password (at least 8 characters)', 'short')
        ->assertFailed();
    $this->artisan('staff:create', $args)
        ->expectsQuestion('Password (at least 8 characters)', 'long-enough-1')
        ->expectsQuestion('Type the password again', 'long-enough-2')
        ->assertFailed();

    expect(User::where('email', 'ops@example.com')->exists())->toBeFalse();
});

it('refuses an unknown role, a taken email and a bad phone before asking for a password', function () {
    User::create(['username' => 'taken', 'email' => 'taken@example.com', 'password' => 'whatever-123', 'role' => 'driver']);

    $this->artisan('staff:create', ['role' => 'passenger', 'email' => 'new@example.com', 'name' => 'New', 'phone' => '09171234567', 'address' => 'Tagum City'])->assertFailed();
    $this->artisan('staff:create', ['role' => 'driver', 'email' => 'taken@example.com', 'name' => 'New', 'phone' => '09171234567', 'address' => 'Tagum City'])->assertFailed();
    $this->artisan('staff:create', ['role' => 'driver', 'email' => 'other@example.com', 'name' => 'New', 'phone' => 'call me', 'address' => 'Tagum City'])->assertFailed();
});
