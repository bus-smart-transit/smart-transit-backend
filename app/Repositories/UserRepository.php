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

    /** Batch 67: "Send me text messages" on or off. */
    public function setSmsOptIn(User $user, bool $enabled): void
    {
        $user->forceFill(['sms_opt_in' => $enabled])->save();
    }

    public function findByField(string $field, mixed $value): ?User
    {
        return User::where($field, $value)->first();
    }

    /** 2026-10-29: the passenger account behind a passenger_uuid (sign-in sends the uuid, never the numeric id). */
    /**
     * 2026-11-05: a Driver or Chauffeur signing in with their username (any letter case). Other roles are never found
     * this way: Operators and Admins sign in with their email only.
     */
    public function findCrewByUsername(string $username): ?User
    {
        return User::whereRaw('LOWER(username) = ?', [mb_strtolower(trim($username))])
            ->whereIn('role', ['driver', 'conductor'])
            ->first();
    }

    /** Batch 46: is this username (any letter case, since crews sign in with it) used by another account? */
    public function usernameTakenByOther(string $username, int $exceptUserId): bool
    {
        return User::whereRaw('LOWER(username) = ?', [mb_strtolower(trim($username))])->where('user_id', '!=', $exceptUserId)->exists();
    }

    /** Batch 46: is this email used by another account? */
    public function emailTakenByOther(string $email, int $exceptUserId): bool
    {
        return User::where('email', mb_strtolower(trim($email)))->where('user_id', '!=', $exceptUserId)->exists();
    }

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
