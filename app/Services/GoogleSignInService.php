<?php

namespace App\Services;

use App\Repositories\PassengerRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 2026-11-07 (user approved): passengers may sign in with Google. Rules:
 * - Passengers only. A staff account with the same email is refused; staff keep password plus login code.
 * - An account already linked to this Google account signs in. Otherwise a passenger with the same email is linked
 *   (Google has confirmed the email). Otherwise a new passenger account is made from the Google name and email.
 * - No emailed login code: Google confirmed who it is. The email counts as verified.
 * - A new account has no phone yet; the website asks for it before booking (needs_phone).
 * - A turned-off or erased account cannot sign in this way either.
 */
class GoogleSignInService
{
    public function __construct(
        private GoogleIdTokenVerifier $verifier,
        private UserRepository $users,
        private PassengerRepository $passengers,
        private AccountDeletionService $accountDeletion,
    ) {}

    /** @return array{token: string, is_new: bool, needs_phone: bool, deletion_cancelled: bool, user_id: int} */
    public function signIn(string $idToken): array
    {
        $google = $this->verifier->verify($idToken);
        if (! $google['email_verified']) {
            throw ValidationException::withMessages(['id_token' => ['Your Google account email is not verified yet. Verify it with Google, or sign up with email.']]);
        }

        $user = $this->users->findByField('google_uid', $google['uid']) ?? $this->users->findByField('email', $google['email']);
        $isNew = false;

        if ($user) {
            if ($user->role !== 'passenger') {
                throw ValidationException::withMessages(['id_token' => ['This email belongs to a staff account. Staff sign in on the staff login page.']]);
            }
            if ($user->anonymized_at !== null) {
                throw ValidationException::withMessages(['id_token' => ['This account was deleted. Sign up again to use SmartTransit.']]);
            }
            if ($user->deactivated_at !== null) {
                throw ValidationException::withMessages(['id_token' => ['This account is turned off. Contact SmartTransit to turn it on again.']]);
            }
            $user->forceFill(array_filter([
                'google_uid' => $user->google_uid ?: $google['uid'],
                'email_verified_at' => $user->email_verified_at ?: now(),
            ]))->save();
        } else {
            $user = DB::transaction(function () use ($google) {
                $user = $this->users->create([
                    'username' => $this->freeUsername($google['email']),
                    'email' => $google['email'],
                    // No password: the passenger signs in with Google, or sets one with Forgot password.
                    'password' => Str::random(64),
                    'role' => 'passenger',
                    'two_factor_enabled' => false,
                ]);
                $user->forceFill(['google_uid' => $google['uid'], 'email_verified_at' => now()])->save();
                $this->passengers->create([
                    'user_id' => $user->user_id,
                    'name' => $google['name'] ?: Str::before($google['email'], '@'),
                    'phone_num' => null,
                    'address' => null,
                    'birth_date' => null,
                ]);

                return $user;
            });
            $isNew = true;
        }

        $phone = $this->users->getPassengerProfile((int) $user->user_id)?->phone_num;

        return [
            'token' => $user->createToken('passenger-session-token')->plainTextToken,
            'is_new' => $isNew,
            'needs_phone' => blank($phone),
            'deletion_cancelled' => $this->accountDeletion->cancelIfPending($user),
            'user_id' => (int) $user->user_id,
        ];
    }

    /** The email's first part, with digits added when taken (usernames are unique). */
    private function freeUsername(string $email): string
    {
        $base = Str::limit(preg_replace('/[^a-z0-9._-]/', '', strtolower(Str::before($email, '@'))) ?: 'passenger', 40, '');
        $candidate = $base;
        while ($this->users->findByField('username', $candidate)) {
            $candidate = $base.random_int(1000, 99999);
        }

        return $candidate;
    }
}
