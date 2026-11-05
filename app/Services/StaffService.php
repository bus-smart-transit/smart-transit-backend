<?php

namespace App\Services;

use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;
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

    public function verifyStaffLoginOtp(string $accountUuid, string $otp): array
    {
        return $this->userService->verifyStaffLoginOtp($accountUuid, $otp);
    }

    public function getStaffProfile(object $user): ?object
    {
        return $this->staffRepository->findByUserId($user->user_id);
    }

    // ── Account creation chain ──────────────────────────────────────
    // Admin  → can create operator accounts
    // Operator → can create driver and conductor accounts only
    // Driver / Conductor → cannot create any accounts (blocked by route middleware)

    /**
     * @param  int|null  $creatorCompanyUserId  the creating Operator's company_users id: a Driver or Chauffeur made by an
     *                                          Operator belongs to that Operator (Batch 46).
     */
    public function createStaffAccount(array $payload, string $createdByRole, ?int $creatorCompanyUserId = null): array
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
            'operator_company_user_id' => $createdByRole === 'operator' && in_array($targetRole, self::OPERATOR_CREATABLE_ROLES, true)
                ? $creatorCompanyUserId
                : null,
        ]);

        return ['user' => $user, 'profile' => $companyUser];
    }

    // ── Staff listings (operator/admin views) ───────────────────────

    /**
     * 2026-11-04 (Todo 3 finding 2, Option A): staff are one shared pool, so an Operator's list carries only what
     * scheduling needs (id, name, username, gender, availability, joined date). Phone, address, birth date and email
     * stay with the Admin ($withContactDetails) and with the person's own profile.
     */
    public function listDrivers(?string $tripDate = null, bool $withContactDetails = false, ?int $operatorCompanyUserId = null): object
    {
        // Batch 46 (least privilege): an Operator lists only their own crew; null (the Admin) lists everyone.
        $drivers = $this->staffRepository->listByRole('driver', $operatorCompanyUserId);
        if ($tripDate === null) {
            return $this->presentStaff($drivers, $withContactDetails);
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

        return $this->presentStaff(
            $drivers->reject(fn ($driver) => $unavailableIds->contains((int) $driver->company_user_id))->values(),
            $withContactDetails,
        );
    }

    public function listConductors(bool $withContactDetails = false, ?int $operatorCompanyUserId = null): object
    {
        return $this->presentStaff($this->staffRepository->listByRole('conductor', $operatorCompanyUserId), $withContactDetails);
    }

    /**
     * Batch 46 (decided 2026-11-05, least privilege): the one rule for putting a crew member on a trip or shift block.
     * The person must have the right role AND belong to the Operator who owns the trip or block. Another company's crew
     * gets the same answer as a person with the wrong role, so nothing about other companies is revealed. An owner that is an Admin (the
     * Admin portal is rewritten in Batch 48) is not limited.
     *
     * @return StaffUser the checked crew member
     *
     * @throws ValidationException
     */
    public function assertCrewOf(int $ownerCompanyUserId, mixed $staffCompanyUserId, string $role, string $field): object
    {
        $staff = $staffCompanyUserId ? $this->staffRepository->findById((int) $staffCompanyUserId) : null;
        $label = $role === 'driver' ? 'driver' : 'Chauffeur';
        $refuse = fn () => throw ValidationException::withMessages([$field => ["Choose a {$label} from your own staff."]]);

        if (! $staff || $staff->user?->role !== $role) {
            $refuse();
        }
        if ($this->staffRepository->roleOf($ownerCompanyUserId) !== 'admin'
            && (int) $staff->operator_company_user_id !== $ownerCompanyUserId) {
            $refuse();
        }

        return $staff;
    }

    private function presentStaff(Collection $staff, bool $withContactDetails): Collection
    {
        if ($withContactDetails) {
            return $staff;
        }

        return $staff->map(fn ($member) => [
            'company_user_id' => $member->company_user_id,
            'company_user_uuid' => $member->company_user_uuid,
            'user_id' => $member->user_id,
            'name' => $member->name,
            'gender' => $member->gender,
            'is_available' => $member->is_available,
            'available_since' => $member->available_since,
            'created_at' => $member->created_at,
            'user' => $member->user ? [
                'user_id' => $member->user->user_id,
                'username' => $member->user->username,
                'role' => $member->user->role,
                'created_at' => $member->user->created_at,
            ] : null,
        ])->values();
    }
}
