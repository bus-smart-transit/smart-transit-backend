<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Models\PassengerUser;

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
    public function getPassengerProfile(int $userId): ?PassengerUser
    {
        return PassengerUser::with('user')->where('user_id', $userId)->first();
    }

    public function findByField(string $field, $value): ?User
    {
        return User::where($field, $value)->first();
    }
}