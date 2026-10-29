<?php

namespace App\Repositories;

use App\Models\PassengerUser;
use App\Models\StaffUser;
use App\Models\User;

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

    /** 2026-10-29: the passenger account behind a passenger_uuid (sign-in sends the uuid, never the numeric id). */
    public function findPassengerUserByUuid(string $passengerUuid): ?User
    {
        return PassengerUser::with('user')->where('passenger_uuid', $passengerUuid)->first()?->user;
    }

    /** 2026-10-29: the staff account behind a company_user_uuid (sign-in sends the uuid, never the numeric id). */
    public function findStaffUserByUuid(string $companyUserUuid): ?User
    {
        return StaffUser::with('user')->where('company_user_uuid', $companyUserUuid)->first()?->user;
    }

    public function getPassengerProfile(int $userId): ?PassengerUser
    {
        return PassengerUser::with('user')->where('user_id', $userId)->first();
    }
}
