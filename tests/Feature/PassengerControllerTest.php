<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it can create a passenger user', function () {
    $response = $this->postJson('/api/passengers/register', [
        'email' => 'test+'.uniqid().'@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'name' => 'John Doe',
        'phone_num' => '1234567890',
        'address' => '123 Smart St',
        'birthdate' => '2000-01-01',
    ]);

    $response->assertStatus(201);
    // 2026-10-28: no token at sign-up; a code is emailed to verify the address first.
    $response->assertJsonStructure(['data' => ['passenger', 'verification_required', 'user_id', 'email_masked']]);
    $response->assertJsonMissingPath('data.token');
});

test('it validates request data when creating a passenger user', function () {
    $response = $this->postJson('/api/passengers/register', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name', 'email', 'password', 'phone_num', 'birthdate']);
});
