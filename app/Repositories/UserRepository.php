<?php
namespace App\Repositories;

use App\Models\User;
use App\Models\PassengerUser;

class UserRepository
{
    public function create(array $payload): User
    {
        $role = $payload['role'] ?? 'passenger';

        return User::create([
            'username' => $payload['username'] ?? explode('@', $payload['email'])[0],
            'email' => $payload['email'],
            'password' => $payload['password'],
            'role' => $role,
            // Operator and Admin always start with MFA enabled — matches the
            // mandatory-MFA policy in UserService::initiateStaffLoginOtp.
            'two_factor_enabled' => $payload['two_factor_enabled'] ?? in_array($role, ['passenger', 'operator', 'admin']),
        ]);
    }

    public function findByField(string $field, mixed $value): ?User
    {
        return User::where($field, $value)->first();
    }

    public function getPassengerProfile(int $userId): ?PassengerUser
    {
        return PassengerUser::with('user')->where('user_id', $userId)->first();
    }
}
