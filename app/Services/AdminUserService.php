<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AdminAccountsRepository;
use App\Repositories\TripRepository;
use App\Support\FinancialClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Batch 48 Phase 3: the Super Admin's Users page. Every account, any role. Turning an account off only blocks sign-in
 * and ends its sessions; it can be undone and nothing is deleted (a passenger's own account deletion is separate and
 * only the passenger starts it). The Super Admin cannot turn off their own account or the last Super Admin, and a
 * Driver or Chauffeur with trips still to work is replaced first (same rule as the Company Admin's Staff page).
 */
class AdminUserService
{
    public const PER_PAGE = 15;

    private const ROLE_LABELS = ['passenger' => 'Passenger', 'driver' => 'Driver', 'conductor' => 'Chauffeur', 'operator' => 'Operator', 'admin' => 'Super Admin'];

    public function __construct(
        private AdminAccountsRepository $accounts,
        private TripRepository $trips,
    ) {}

    public function page(?string $search, ?string $role, int $page, int $actorUserId): array
    {
        $paginator = $this->accounts->usersPage($search !== null ? trim($search) : null, $role, max(1, $page), self::PER_PAGE);

        return [
            'users' => collect($paginator->items())->map(fn (User $user) => $this->present($user, $actorUserId))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function deactivate(int $userId, int $actorUserId): array
    {
        $user = $this->findOrFail($userId);
        if ((int) $user->user_id === $actorUserId) {
            throw new AccessDeniedHttpException('You cannot turn off your own account.');
        }
        if ($user->deactivated_at !== null) {
            return $this->present($user, $actorUserId);
        }
        if ($user->role === 'admin' && $this->accounts->activeAdminCount() <= 1) {
            throw ValidationException::withMessages(['user' => ['This is the last Super Admin account that is on. It cannot be turned off.']]);
        }
        if (in_array($user->role, ['driver', 'conductor'], true) && $user->companyProfile) {
            $pending = $this->trips->unfinishedForCrew((int) $user->companyProfile->company_user_id, FinancialClock::today());
            if ($pending->isNotEmpty()) {
                throw ValidationException::withMessages(['user' => ['Their company must replace this person on their trips first. They still have '.$pending->count().' trip'.($pending->count() === 1 ? '' : 's').' to work.']]);
            }
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['deactivated_at' => now()])->save();
            $user->tokens()->delete();
        });

        return $this->present($this->findOrFail($userId), $actorUserId);
    }

    public function reactivate(int $userId, int $actorUserId): array
    {
        $user = $this->findOrFail($userId);
        $user->forceFill(['deactivated_at' => null])->save();

        return $this->present($this->findOrFail($userId), $actorUserId);
    }

    /** Only an Operator who works for a company can be (or stop being) its Company Admin. */
    public function setCompanyAdmin(int $userId, bool $on, int $actorUserId): array
    {
        $user = $this->findOrFail($userId);
        $profile = $user->companyProfile;
        if ($user->role !== 'operator' || ! $profile || $profile->company_id === null) {
            throw ValidationException::withMessages(['user' => ['Only an Operator of a company can be its Company Admin.']]);
        }
        $profile->forceFill(['is_company_admin' => $on])->save();

        return $this->present($this->findOrFail($userId), $actorUserId);
    }

    private function findOrFail(int $userId): User
    {
        return $this->accounts->findUser($userId) ?? throw new NotFoundHttpException('Account not found.');
    }

    private function present(User $user, int $actorUserId): array
    {
        $staff = $user->companyProfile;
        $isCompanyAdmin = $user->role === 'operator' && (bool) $staff?->is_company_admin;

        return [
            'user_id' => (int) $user->user_id,
            'name' => $user->passengerProfile?->name ?? $staff?->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $isCompanyAdmin ? 'Company Admin' : (self::ROLE_LABELS[$user->role] ?? 'Not provided'),
            'company_name' => $staff?->company?->company_name,
            'is_company_admin' => $isCompanyAdmin,
            'deactivated' => $user->deactivated_at !== null,
            // A passenger who asked to delete their account (erased after the grace days), or already erased.
            'deletion' => $user->anonymized_at !== null ? 'erased' : ($user->deletion_requested_at !== null ? 'requested' : null),
            'is_you' => (int) $user->user_id === $actorUserId,
            'joined_at' => $user->created_at,
        ];
    }
}
