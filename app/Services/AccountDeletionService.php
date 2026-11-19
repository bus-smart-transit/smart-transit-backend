<?php

namespace App\Services;

use App\Mail\AccountDeletionRequestedMail;
use App\Models\User;
use App\Repositories\AccountDeletionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * 2026-11-05: the one owner of passenger account deletion (Data Privacy Act of 2012, right to erasure; GDPR in spirit).
 *
 * - Least privilege: a passenger deletes only their OWN account (the signed-in user; the request carries no id), after
 *   re-entering their password. No staff role can delete a passenger or read their details through this.
 * - Refused while a ticket is still in use: valid or boarded on a trip that has not ended, or waiting for a
 *   cancelled-trip choice or a refund.
 * - On request: every session ends at once and one email says when the data will be erased. Signing in again within
 *   config('privacy.account_deletion_grace_days') (7) cancels it.
 * - After the grace days the nightly job erases the personal data for good (AccountDeletionRepository::anonymize).
 *   Tickets, payments and refunds stay, linked to "Deleted passenger", for the financial records; reward points are
 *   forfeited.
 */
class AccountDeletionService
{
    public function __construct(private AccountDeletionRepository $accounts) {}

    /**
     * @return array{deletes_on: string} the Manila date the data will be erased if the passenger does not sign in
     *
     * @throws ValidationException
     */
    public function request(User $user, string $password): array
    {
        if ($user->role !== 'passenger' || $user->anonymized_at !== null) {
            throw ValidationException::withMessages(['account' => ['Only a passenger can delete their own account here.']]);
        }
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['That password is not right.']]);
        }

        $passenger = $this->accounts->passengerOf($user);
        if ($passenger && $this->accounts->openTicketCount((int) $passenger->passenger_id) > 0) {
            throw ValidationException::withMessages(['account' => [
                'You still have a ticket for a trip that has not happened yet, or a refund that is not finished. You can delete your account after that.',
            ]]);
        }

        $now = Carbon::now('UTC');
        $this->accounts->markRequested($user, $now);
        $this->accounts->revokeSessions($user);
        AuditLogger::log('account.deletion_requested', 'User', (int) $user->user_id, ['grace_days' => $this->graceDays()]);

        $deletesOn = $this->deletesOn($now);
        $this->email($user, $passenger?->name, $deletesOn);

        return ['deletes_on' => $deletesOn];
    }

    /** A passenger who signs in during the grace days keeps their account. True when a deletion was cancelled. */
    public function cancelIfPending(User $user): bool
    {
        if ($user->deletion_requested_at === null || $user->anonymized_at !== null) {
            return false;
        }

        $this->accounts->clearRequest($user);
        $user->deletion_requested_at = null;
        AuditLogger::log('account.deletion_cancelled', 'User', (int) $user->user_id, ['by' => 'sign_in']);

        return true;
    }

    /** Erases every account whose grace days are over. Returns how many were erased. */
    public function anonymizeDue(): int
    {
        $now = Carbon::now('UTC');
        $count = 0;
        foreach ($this->accounts->dueForAnonymization($now->copy()->subDays($this->graceDays())) as $user) {
            $this->accounts->anonymize($user, $now);
            AuditLogger::log('account.anonymized', 'User', (int) $user->user_id, []);
            $count++;
        }

        return $count;
    }

    public function graceDays(): int
    {
        return max(1, (int) config('privacy.account_deletion_grace_days', 7));
    }

    /** The Manila date the data is erased (the nightly job after the grace days have passed). */
    private function deletesOn(Carbon $requestedAt): string
    {
        return $requestedAt->copy()->addDays($this->graceDays())->setTimezone('Asia/Manila')->toDateString();
    }

    private function email(User $user, ?string $name, string $deletesOn): void
    {
        try {
            $intercept = config('mail.intercept_email');
            Mail::to($intercept ?: $user->email)->queue(new AccountDeletionRequestedMail(
                name: $name ?: 'there',
                deletesOn: Carbon::parse($deletesOn, 'Asia/Manila')->format('F j, Y'),
                graceDays: $this->graceDays(),
            ));
        } catch (\Throwable $e) {
            Log::warning('Account deletion email could not be sent.', ['user_id' => $user->user_id, 'error' => $e->getMessage()]);
        }
    }
}
