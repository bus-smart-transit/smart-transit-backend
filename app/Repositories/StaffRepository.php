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

    // List all staff of a specific role — e.g. all drivers or all conductors
    public function listByRole(string $role): Collection
    {
        return StaffUser::with('user')
            ->whereHas('user', fn($q) => $q->where('role', $role))
            ->get();
    }
}
