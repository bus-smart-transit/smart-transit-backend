<?php

namespace App\Repositories;

use App\Models\PassengerUser;
use App\Models\User;
use App\Services\TicketResolutionService;
use App\Services\TripCancellationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Every query of passenger account deletion (App\Services\AccountDeletionService owns the rules).
 */
class AccountDeletionRepository
{
    public function passengerOf(User $user): ?PassengerUser
    {
        return PassengerUser::where('user_id', $user->user_id)->first();
    }

    /**
     * Tickets that are still in use: valid or boarded on a trip that has not ended or been cancelled, or waiting for
     * a cancelled-trip choice or a refund.
     */
    public function openTicketCount(int $passengerId): int
    {
        return DB::table('tickets')
            ->join('trips', 'trips.trip_id', '=', 'tickets.trip_id')
            ->where('tickets.passenger_id', $passengerId)
            ->where(function ($query) {
                $query->where(function ($inUse) {
                    $inUse->whereIn('tickets.status', ['valid', 'boarded'])
                        ->whereNotIn('trips.status', ['completed', 'cancelled']);
                })->orWhereIn('tickets.status', [
                    TripCancellationService::AWAITING_CHOICE,
                    TicketResolutionService::PENDING,
                    TicketResolutionService::REVIEW,
                ]);
            })
            ->count();
    }

    public function markRequested(User $user, Carbon $at): void
    {
        User::whereKey($user->user_id)->update(['deletion_requested_at' => $at]);
    }

    public function clearRequest(User $user): void
    {
        User::whereKey($user->user_id)->update(['deletion_requested_at' => null]);
    }

    public function revokeSessions(User $user): void
    {
        $user->tokens()->delete();
        User::whereKey($user->user_id)->update(['fcm_token' => null]);
    }

    /** @return Collection<int, User> passengers whose grace period ended before $cutoff and who are not erased yet */
    public function dueForAnonymization(Carbon $cutoff): Collection
    {
        return User::where('role', 'passenger')
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', $cutoff)
            ->whereNull('anonymized_at')
            ->get();
    }

    /**
     * Erases the personal data of one passenger. The rows stay (tickets, payments, refunds and reward history point to
     * them), but nothing in them identifies the person any more, and the login can never be used again.
     */
    public function anonymize(User $user, Carbon $at): void
    {
        DB::transaction(function () use ($user, $at) {
            $oldEmail = $user->email;

            User::whereKey($user->user_id)->update([
                'email' => 'deleted-'.$user->user_id.'@deleted.invalid',
                'username' => 'deleted_'.$user->user_id,
                'password' => Hash::make(Str::random(64)),
                'fcm_token' => null,
                'two_factor_enabled' => false,
                'anonymized_at' => $at,
            ]);

            PassengerUser::where('user_id', $user->user_id)->update([
                'name' => 'Deleted passenger',
                'phone_num' => null,
                'address' => null,
                'birth_date' => null,
                'gender' => null,
                'reward_points' => 0,
            ]);

            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();
            DB::table('user_notifications')->where('user_id', $user->user_id)->delete();
        });
    }
}
