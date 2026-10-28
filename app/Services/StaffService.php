<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffService
{
    private UserRepository $userRepository;

    private StaffRepository $staffRepository;

    private const STAFF_ROLES = ['operator', 'driver', 'conductor', 'admin'];

    private const OPERATOR_CREATABLE_ROLES = ['driver', 'conductor'];

    public function __construct(
        UserRepository $userRepository,
        StaffRepository $staffRepository,
        private UserService $userService,
    ) {
        $this->userRepository = $userRepository;
        $this->staffRepository = $staffRepository;
    }

    public function loginStaff(array $credentials): array
    {
        return $this->userService->initiateStaffLoginOtp($credentials);
    }

    public function verifyStaffLoginOtp(int $userId, string $otp): array
    {
        return $this->userService->verifyStaffLoginOtp($userId, $otp);
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
            if (! in_array($targetRole, self::OPERATOR_CREATABLE_ROLES)) {
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
            'username' => $payload['username'] ?? null,
            'email' => $payload['email'],
            'password' => $payload['password'],
            'role' => $targetRole,
        ]);

        $companyUser = $this->staffRepository->create([
            'company_user_uuid' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'name' => $payload['name'],
            'phone_num' => $payload['phone_num'],
            'address' => $payload['address'] ?? '',
            'birth_date' => $payload['birth_date'] ?? null,
            'gender' => $payload['gender'] ?? null,
        ]);

        return ['user' => $user, 'profile' => $companyUser];
    }

    // ── Staff listings (operator/admin views) ───────────────────────

    public function listDrivers(?string $tripDate = null): object
    {
        $drivers = $this->staffRepository->listByRole('driver');
        if ($tripDate === null) {
            return $drivers;
        }

        $assignedIds = Trip::query()
            ->whereDate('trip_date', $tripDate)
            ->whereNotNull('driver_id')
            ->where('status', '!=', 'cancelled')
            ->pluck('driver_id');
        $releasedIds = TripAssignmentRequest::query()
            ->where('role', 'driver')
            ->where('status', TripAssignmentRequest::APPROVED)
            ->whereHas('trip', fn ($query) => $query->whereDate('trip_date', $tripDate))
            ->pluck('staff_company_user_id');
        $unavailableIds = $assignedIds->merge($releasedIds)->map(fn ($id) => (int) $id)->unique();

        return $drivers->reject(fn ($driver) => $unavailableIds->contains((int) $driver->company_user_id))->values();
    }

    public function listConductors(): object
    {
        return $this->staffRepository->listByRole('conductor');
    }
}
