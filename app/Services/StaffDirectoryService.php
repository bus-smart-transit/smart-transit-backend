<?php

namespace App\Services;

use App\Models\StaffUser;
use App\Repositories\CompanyRepository;
use App\Repositories\StaffRepository;
use App\Repositories\TripRepository;
use App\Repositories\UserRepository;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Batch 46 (decided 2026-11-05/06, least privilege): the Operator's Staff Directory. Every call takes the signed-in
 * Operator's company id and touches only that company's Drivers and Chauffeurs (shared by all its Operators); another
 * company's person reads as not found (404). Edit changes username, phone and email only. Deactivate is soft: sign-in refused, sessions
 * ended, left out of assignment lists, history kept; refused while the person still has unfinished trips.
 */
class StaffDirectoryService
{
    public const PER_PAGE = 10;

    public function __construct(
        private StaffRepository $staff,
        private UserRepository $users,
        private TripRepository $trips,
        private CompanyRepository $companies,
    ) {}

    /** One page of the Operator's own crew plus the whole-roster counts (the cards never count one page). */
    public function page(int $companyId, ?string $search, ?string $role, int $page): array
    {
        $paginator = $this->staff->pageOwnCrew($companyId, $search !== null ? trim($search) : null, $role, max(1, $page), self::PER_PAGE);

        return [
            'staff' => collect($paginator->items())->map(fn (StaffUser $member) => $this->present($member))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'totals' => $this->staff->countOwnCrewByRole($companyId),
            // Batch 46: shown on the page, so it is plain that these people belong to this company only.
            'company' => ($company = $this->companies->find($companyId)) ? ['company_id' => (int) $company->company_id, 'company_name' => $company->company_name] : null,
        ];
    }

    /**
     * Username, phone and email only (decided 2026-11-05). A taken username or email gets the same plain answer as
     * any other refusal. A new email ends the person's sessions, so they sign in again with it.
     *
     * @param  array{username?: ?string, phone_num?: ?string, email?: ?string}  $changes  already validated
     * @return array{member: array, changed: list<string>}
     */
    public function update(int $companyId, int $companyUserId, array $changes): array
    {
        $member = $this->ownOrFail($companyId, $companyUserId);
        $user = $member->user;
        $changed = [];

        if (array_key_exists('username', $changes) && $changes['username'] !== $user->username) {
            if ($this->users->usernameTakenByOther($changes['username'], (int) $user->user_id)) {
                throw ValidationException::withMessages(['username' => ['This username cannot be used. Try another one.']]);
            }
            $changed[] = 'username';
        }
        if (array_key_exists('email', $changes) && $changes['email'] !== $user->email) {
            if ($this->users->emailTakenByOther($changes['email'], (int) $user->user_id)) {
                throw ValidationException::withMessages(['email' => ['This email cannot be used. Try another one.']]);
            }
            $changed[] = 'email';
        }
        if (array_key_exists('phone_num', $changes) && $changes['phone_num'] !== $member->phone_num) {
            $changed[] = 'phone_num';
        }

        if ($changed !== []) {
            DB::transaction(function () use ($user, $member, $changes, $changed) {
                $userFields = array_intersect_key($changes, array_flip(array_intersect($changed, ['username', 'email'])));
                if ($userFields !== []) {
                    $user->forceFill($userFields)->save();
                }
                if (in_array('phone_num', $changed, true)) {
                    $member->forceFill(['phone_num' => $changes['phone_num']])->save();
                }
                if (in_array('email', $changed, true)) {
                    $user->tokens()->delete();
                }
            });
        }

        return ['member' => $this->present($this->ownOrFail($companyId, $companyUserId)), 'changed' => $changed];
    }

    /**
     * Refused (with the list of trips) while the person still has a trip or block leg to work: the Operator replaces
     * them first (decided 2026-11-05). Then sign-in is refused and every session ends.
     */
    public function deactivate(int $companyId, int $companyUserId): array
    {
        $member = $this->ownOrFail($companyId, $companyUserId);
        if ($member->user->deactivated_at !== null) {
            return $this->present($member);
        }

        $today = Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString();
        $pending = $this->trips->unfinishedForCrew($companyUserId, $today);
        if ($pending->isNotEmpty()) {
            $lines = $pending->take(10)->map(fn ($trip) => trim(sprintf(
                '%s %s, %s, bus %s',
                Carbon::parse($trip->trip_date)->format('M j'),
                $trip->departure_time ? Carbon::parse($trip->departure_time)->format('g:i A') : '',
                $trip->fleetRoute?->route?->route_name ?? 'Not provided',
                $trip->fleetRoute?->fleet?->plate_number ?? 'Not provided',
            )))->values()->all();

            throw ValidationException::withMessages([
                'staff' => ['Replace this person on their trips first. They still have '.$pending->count().' trip'.($pending->count() === 1 ? '' : 's').' to work.'],
                'trips' => $lines,
            ]);
        }

        DB::transaction(function () use ($member) {
            $member->user->forceFill(['deactivated_at' => now()])->save();
            $member->user->tokens()->delete();
        });

        return $this->present($this->ownOrFail($companyId, $companyUserId));
    }

    public function reactivate(int $companyId, int $companyUserId): array
    {
        $member = $this->ownOrFail($companyId, $companyUserId);
        $member->user->forceFill(['deactivated_at' => null])->save();

        return $this->present($this->ownOrFail($companyId, $companyUserId));
    }

    /** For the audit log: what changed, with the phone masked. */
    public static function auditContext(array $member, array $changed): array
    {
        return [
            'changed' => $changed,
            'phone' => in_array('phone_num', $changed, true) ? PhoneNumber::mask($member['phone_num'] ?? null) : null,
        ];
    }

    private function ownOrFail(int $companyId, int $companyUserId): StaffUser
    {
        $member = $this->staff->findOwnCrew($companyId, $companyUserId);
        if (! $member || ! $member->user) {
            throw new NotFoundHttpException('Staff member not found.');
        }

        return $member;
    }

    private function present(StaffUser $member): array
    {
        $role = (string) $member->user?->role;

        return [
            'company_user_id' => (int) $member->company_user_id,
            'name' => $member->name,
            'username' => $member->user?->username,
            'email' => $member->user?->email,
            'phone_num' => $member->phone_num,
            'gender' => $member->gender,
            'role' => $role,
            'role_label' => $role === 'conductor' ? 'Chauffeur' : 'Driver',
            'is_available' => (bool) $member->is_available,
            'available_since' => $member->available_since,
            'deactivated' => $member->user?->deactivated_at !== null,
            'deactivated_at' => $member->user?->deactivated_at,
            'joined_at' => $member->created_at,
        ];
    }
}
