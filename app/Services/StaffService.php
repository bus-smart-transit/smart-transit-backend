<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Repositories\StaffRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class StaffService
{
    private UserRepository $userRepository;
    private StaffRepository $staffRepository;
    private const STAFF_ROLES = ['operator', 'driver', 'conductor', 'admin'];

    private const OPERATOR_CREATABLE_ROLES = ['driver', 'conductor'];

    public function __construct(
        UserRepository $userRepository,
        StaffRepository $staffRepository,
    ) {
        $this->userRepository = $userRepository;
        $this->staffRepository = $staffRepository;
    }

    public function loginStaff(array $credentials): array
    {
        $user = $this->userRepository->findByField('email', $credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        if (!in_array($user->role, self::STAFF_ROLES)) {
            throw ValidationException::withMessages([
                'email' => ['Access unauthorized via staff terminal.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $user->createToken('staff-session-token', [$user->role])->plainTextToken,
        ];
    }

    public function getStaffProfile(object $user): ?object
    {
        return $this->staffRepository->findByUserId($user->user_id);
    }

    // ── Account creation chain ──────────────────────────────────────
    // Admin  → can create operator accounts
    // Operator → can create driver and conductor accounts only
    // Driver / Conductor → cannot create any accounts (blocked by route middleware)

    public function createStaffAccount(array $payload, string $createdByRole): array
    {
        $targetRole = $payload['role'];

        if ($createdByRole === 'operator') {
            if (!in_array($targetRole, self::OPERATOR_CREATABLE_ROLES)) {
                throw ValidationException::withMessages([
                    'role' => ['Operators can only create driver or conductor accounts.'],
                ]);
            }
        } elseif ($createdByRole === 'admin') {
            // Admin can create any staff role — no restriction
        } else {
            // Should never reach here since route middleware blocks other roles,
            // but kept as a safety net
            throw ValidationException::withMessages([
                'role' => ['You do not have permission to create staff accounts.'],
            ]);
        }

        if ($this->userRepository->findByField('email', $payload['email'])) {
            throw ValidationException::withMessages([
                'email' => ['This email is already in use.'],
            ]);
        }

        $user = $this->userRepository->create([
            'username' => $payload['username'] ?? explode('@', $payload['email'])[0],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role' => $targetRole,
        ]);

        $companyUser = $this->staffRepository->create([
            'company_user_uuid' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'name' => $payload['name'],
            'phone_num' => $payload['phone_num'],
            'address' => $payload['address'] ?? '',
        ]);

        return ['user' => $user, 'profile' => $companyUser];
    }

    // ── Staff listings (operator/admin views) ───────────────────────

    public function listDrivers(): object
    {
        return $this->staffRepository->listByRole('driver');
    }

    public function listConductors(): object
    {
        return $this->staffRepository->listByRole('conductor');
    }
}
