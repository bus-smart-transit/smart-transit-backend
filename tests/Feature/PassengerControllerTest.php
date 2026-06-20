<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it can create a passenger user', function () {
    $user = User::create([
        'username' => 'testpassenger',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'role' => 'passenger',
    ]);

    $response = $this->postJson('/api/passengers', [
        'user_id' => $user->user_id,
        'name' => 'John Doe',
        'phone_num' => '1234567890',
        'address' => '123 Smart St',
    ]);

    $response->assertStatus(201);
});

test('it validates request data when creating a passenger user', function () {
    $response = $this->postJson('/api/passengers', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['user_id', 'name', 'phone_num', 'address']);
});
