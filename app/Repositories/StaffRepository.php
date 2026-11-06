<?php

namespace App\Repositories;

use App\Models\StaffUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
        // Batch 46: with the company, so every portal can show which company the person works for.
        return StaffUser::with(['user', 'company'])->where('user_id', $userId)->first();
    }

    /**
     * Staff of one role (drivers or conductors). Batch 46: with $companyId only that company's crew;
     * null lists everyone (the Admin).
     */
    public function listByRole(string $role, ?int $companyId = null): Collection
    {
        // Batch 46: a deactivated person is never offered for assignment (the Staff Directory lists them apart).
        return StaffUser::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', $role)->whereNull('deactivated_at'))
            ->when($companyId !== null, fn ($q) => $q->where('company_users.company_id', $companyId))
            ->get();
    }

    /**
     * Batch 46 Staff Directory: one page of a company's Drivers and Chauffeurs (active and deactivated), newest
     * first. $search matches username, email, name and the role words ("driver", "chauffeur").
     */
    public function pageOwnCrew(int $companyId, ?string $search, ?string $role, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->ownCrewQuery($companyId)
            ->when($role !== null, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', $role)))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $roleWords = array_keys(array_filter(
                    ['driver' => 'driver', 'conductor' => 'chauffeur'],
                    fn (string $word) => str_contains($word, mb_strtolower($search)),
                ));
                $q->where(function ($inner) use ($like, $roleWords) {
                    $inner->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereHas('user', function ($u) use ($like, $roleWords) {
                            $u->whereRaw('LOWER(username) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                                ->when($roleWords !== [], fn ($r) => $r->orWhereIn('role', $roleWords));
                        });
                });
            })
            ->orderByDesc('company_user_id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** How many Drivers and Chauffeurs a company has in all (the roster cards; never one page). */
    public function countOwnCrewByRole(int $companyId): array
    {
        $counts = $this->ownCrewQuery($companyId)
            ->join('users', 'users.user_id', '=', 'company_users.user_id')
            ->selectRaw('users.role as role, COUNT(*) as total')
            ->groupBy('users.role')
            ->pluck('total', 'role');

        return ['driver' => (int) ($counts['driver'] ?? 0), 'conductor' => (int) ($counts['conductor'] ?? 0)];
    }

    /** One of the company's Drivers or Chauffeurs, or null (another company's person reads as not found). */
    public function findOwnCrew(int $companyId, int $companyUserId): ?StaffUser
    {
        return $this->ownCrewQuery($companyId)->where('company_user_id', $companyUserId)->first();
    }

    private function ownCrewQuery(int $companyId): Builder
    {
        return StaffUser::with('user')
            ->where('company_users.company_id', $companyId)
            ->whereHas('user', fn ($q) => $q->whereIn('role', ['driver', 'conductor']));
    }

    /** Batch 46: the company a company_users row works for (null for the Admin or an unknown id). */
    public function companyIdOf(int $companyUserId): ?int
    {
        $companyId = StaffUser::whereKey($companyUserId)->value('company_id');

        return $companyId !== null ? (int) $companyId : null;
    }

    /** The role of the account behind a company_users row (null when unknown). */
    public function roleOf(int $companyUserId): ?string
    {
        return StaffUser::with('user')->find($companyUserId)?->user?->role;
    }
}
