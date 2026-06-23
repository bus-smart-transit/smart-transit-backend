<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserRepository
{
    public function create(array $payload): User
    {
        return User::create([
            'username' => $payload['username'] ?? explode('@', $payload['email'])[0],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role' => $payload['role'] ?? 'passenger',
        ]);
    }

    public function findByField(string $field, $value): ?User
    {
        return User::where($field, $value)->first();
    }
}