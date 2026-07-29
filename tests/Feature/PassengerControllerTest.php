<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it can create a passenger user', function () {
    $response = $this->postJson('/api/passengers/register', [
        'email' => 'test+' . uniqid() . '@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'name' => 'John Doe',
        'phone_num' => '1234567890',
        'address' => '123 Smart St',
        'birthdate' => '2000-01-01',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['data' => ['passenger', 'token']]);
});

test('it validates request data when creating a passenger user', function () {
    $response = $this->postJson('/api/passengers/register', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name', 'email', 'password', 'phone_num', 'birthdate']);
});
