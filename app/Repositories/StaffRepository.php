<?php

namespace App\Repositories;

use App\Models\StaffUser;
use Illuminate\Support\Collection;

class StaffRepository
{
    public function create(array $payload): StaffUser
    {
        return StaffUser::create($payload);
    }

    public function findById(int $companyUserId): ?StaffUser
    {
        return StaffUser::with('user')->find($companyUserId);
    }

    public function findByUserId(int $userId): ?StaffUser
    {
        return StaffUser::with('user')->where('user_id', $userId)->first();
    }

    /**
     * Staff of one role (drivers or conductors). Batch 46: with $operatorCompanyUserId only that Operator's own crew;
     * null lists everyone (the Admin).
     */
    public function listByRole(string $role, ?int $operatorCompanyUserId = null): Collection
    {
        return StaffUser::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', $role))
            ->when($operatorCompanyUserId !== null, fn ($q) => $q->where('operator_company_user_id', $operatorCompanyUserId))
            ->get();
    }

    /** The role of the account behind a company_users row (null when unknown). */
    public function roleOf(int $companyUserId): ?string
    {
        return StaffUser::with('user')->find($companyUserId)?->user?->role;
    }
}
