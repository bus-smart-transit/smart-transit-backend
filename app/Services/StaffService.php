<?php

namespace App\Services;

use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Repositories\CompanyRepository;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
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
        private CompanyRepository $companies,
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
     * @param  int|null  $creatorCompanyId  the creating Operator's company: a Driver or Chauffeur made by an Operator
     *                                      works for that company (Batch 46). The Admin names the company of a new
     *                                      Operator in $payload['company_id'].
     */
    public function createStaffAccount(array $payload, string $createdByRole, ?int $creatorCompanyId = null): array
    {
        $targetRole = $payload['role'];
        $companyId = null;

        if ($createdByRole === 'operator') {
            if (! in_array($targetRole, self::OPERATOR_CREATABLE_ROLES)) {
                throw ValidationException::withMessages([
                    'role' => ['Operators can only create driver or conductor accounts.'],
                ]);
            }
            if ($creatorCompanyId === null) {
                throw ValidationException::withMessages([
                    'role' => ['Your account is not linked to a company yet. Ask the Admin to link it first.'],
                ]);
            }
            $companyId = $creatorCompanyId;
        } elseif ($createdByRole === 'admin') {
            // Admin can create any staff role. Batch 46: they name the company (found by name, else created); a new
            // Admin has none.
            if ($targetRole !== 'admin' && ! empty($payload['company_name'])) {
                $companyId = (int) ($this->companies->findByName($payload['company_name'])
                    ?? $this->companies->create($payload['company_name']))->company_id;
            }
        } else {
            // Should never reach here since route middleware blocks other roles,
            // but kept as a safety net
            throw ValidationException::withMessages([
                'role' => ['You do not have permission to create staff accounts.'],
            ]);
        }

        if ($this->userRepository->findByField('email', $payload['email'])) {
            throw ValidationException::withMessages([
                'email' => ['This email cannot be used. Try another one.'],
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
            'company_id' => $targetRole === 'admin' ? null : $companyId,
        ]);

        // Batch 46: a new Driver or Chauffeur starts Available, so the Operator can schedule them at once; they switch
        // to Not Available themselves on their profile.
        if (in_array($targetRole, self::OPERATOR_CREATABLE_ROLES, true)) {
            $companyUser->forceFill(['is_available' => true, 'available_since' => now()])->save();
        }

        return ['user' => $user, 'profile' => $companyUser];
    }

    // ── Staff listings (operator/admin views) ───────────────────────

    /**
     * 2026-11-04 (Todo 3 finding 2, Option A): staff are one shared pool, so an Operator's list carries only what
     * scheduling needs (id, name, username, gender, availability, joined date). Phone, address, birth date and email
     * stay with the Admin ($withContactDetails) and with the person's own profile.
     */
    public function listDrivers(?string $tripDate = null, bool $withContactDetails = false, ?int $companyId = null): object
    {
        // Batch 46 (least privilege): an Operator lists only their company's crew; null (the Admin) lists everyone.
        $drivers = $this->staffRepository->listByRole('driver', $companyId);
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

    public function listConductors(bool $withContactDetails = false, ?int $companyId = null): object
    {
        return $this->presentStaff($this->staffRepository->listByRole('conductor', $companyId), $withContactDetails);
    }

    /**
     * Batch 46 (decided 2026-11-05/06, least privilege): the one rule for putting a crew member on a trip or shift
     * block. The person must have the right role AND work for the company of the Operator who owns the trip or block
     * (any Operator of that company). Another company's crew gets the same answer as a person with the wrong role, so
     * nothing about other companies is revealed. An owner that is an Admin (the Admin portal is rewritten in Batch 48)
     * is not limited.
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
        if ($this->staffRepository->roleOf($ownerCompanyUserId) !== 'admin') {
            $ownerCompanyId = $this->staffRepository->companyIdOf($ownerCompanyUserId);
            if ($ownerCompanyId === null || (int) $staff->company_id !== $ownerCompanyId) {
                $refuse();
            }
        }

        // Batch 46 (decided 2026-11-05): a deactivated person, or one who set Not Available, is not assigned. A shift
        // already running is never touched by this: the check runs only when someone is put on a trip or block.
        $name = $staff->name ?: ($staff->user?->username ?? 'This person');
        if ($staff->user?->deactivated_at !== null) {
            throw ValidationException::withMessages([$field => ["{$name}'s account is turned off. Choose someone else."]]);
        }
        if (! $staff->is_available) {
            throw ValidationException::withMessages([$field => ["{$name} is Not Available right now. Choose someone else, or ask them to switch to Available."]]);
        }

        return $staff;
    }

    /**
     * Batch 46 (decided 2026-11-05): a staff member changes their own password with the current one. Other sessions
     * end; the one in use stays signed in. Also answers the first-sign-in offer.
     *
     * @throws ValidationException
     */
    public function changePassword(object $user, string $currentPassword, string $newPassword, ?int $keepTokenId): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is not right.']]);
        }
        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages(['password' => ['Choose a password that is different from the current one.']]);
        }

        $user->forceFill(['password' => $newPassword, 'password_prompted_at' => now()])->save();
        $user->tokens()->when($keepTokenId !== null, fn ($q) => $q->where('id', '!=', $keepTokenId))->delete();
    }

    /** Batch 46: the crew member skipped the first-sign-in offer; it is not shown again. */
    public function skipPasswordPrompt(object $user): void
    {
        if ($user->password_prompted_at === null) {
            $user->forceFill(['password_prompted_at' => now()])->save();
        }
    }

    /** Batch 46: show the one-time offer to change the password the Operator typed (Drivers and Chauffeurs only). */
    public static function offersPasswordChange(object $user): bool
    {
        return in_array($user->role, ['driver', 'conductor'], true) && $user->password_prompted_at === null;
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
