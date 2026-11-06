<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\Fleet;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch 48 Phase 3: the Super Admin's Companies and Users pages, across every company.
 */
class AdminAccountsRepository
{
    /** Every company with its staff and bus counts and the names of its Company Admins. */
    public function companiesWithCounts(): Collection
    {
        $staff = DB::table('company_users')
            ->join('users', 'users.user_id', '=', 'company_users.user_id')
            ->whereNotNull('company_users.company_id')
            ->whereNull('users.deactivated_at')
            ->selectRaw('company_users.company_id, users.role, COUNT(*) as total')
            ->groupBy('company_users.company_id', 'users.role')
            ->get()
            ->groupBy('company_id');
        $admins = StaffUser::with('user')
            ->whereNotNull('company_id')
            ->where('is_company_admin', true)
            ->whereHas('user', fn ($q) => $q->where('role', 'operator'))
            ->get()
            ->groupBy('company_id');
        $buses = Fleet::whereNotNull('company_id')->selectRaw('company_id, COUNT(*) as total')->groupBy('company_id')->pluck('total', 'company_id');

        return Company::orderBy('company_name')->get()->map(function (Company $company) use ($staff, $admins, $buses) {
            $counts = collect($staff->get($company->company_id, []))->pluck('total', 'role');

            return [
                'company_id' => (int) $company->company_id,
                'company_name' => $company->company_name,
                'operators' => (int) ($counts['operator'] ?? 0),
                'drivers' => (int) ($counts['driver'] ?? 0),
                'chauffeurs' => (int) ($counts['conductor'] ?? 0),
                'buses' => (int) ($buses[$company->company_id] ?? 0),
                'company_admins' => collect($admins->get($company->company_id, []))
                    ->map(fn (StaffUser $admin) => ['name' => $admin->name, 'email' => $admin->user?->email, 'deactivated' => $admin->user?->deactivated_at !== null])
                    ->values()->all(),
                'created_at' => $company->created_at,
            ];
        });
    }

    public function findCompany(int $companyId): ?Company
    {
        return Company::find($companyId);
    }

    public function companyNameTaken(string $name, ?int $exceptId = null): bool
    {
        return Company::whereRaw('LOWER(company_name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId !== null, fn ($q) => $q->where('company_id', '!=', $exceptId))
            ->exists();
    }

    public function createCompany(string $name): Company
    {
        return Company::create(['company_name' => $name]);
    }

    /** A new name also becomes the bus company label on that company's buses (what passengers see). */
    public function renameCompany(Company $company, string $name): void
    {
        DB::transaction(function () use ($company, $name) {
            $company->forceFill(['company_name' => $name])->save();
            Fleet::where('company_id', $company->company_id)->update(['bus_company_name' => $name]);
        });
    }

    /** Accounts of every role, newest first. $search matches username, email or name. */
    public function usersPage(?string $search, ?string $role, int $page, int $perPage): LengthAwarePaginator
    {
        return User::with(['passengerProfile', 'companyProfile.company'])
            ->when($role !== null, fn ($q) => $q->where('role', $role))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(function ($inner) use ($like) {
                    $inner->whereRaw('LOWER(username) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                        ->orWhereHas('passengerProfile', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('companyProfile', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            })
            ->orderByDesc('user_id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findUser(int $userId): ?User
    {
        return User::with(['passengerProfile', 'companyProfile.company'])->find($userId);
    }

    public function activeAdminCount(): int
    {
        return User::where('role', 'admin')->whereNull('deactivated_at')->count();
    }
}
