<?php

namespace App\Services;

use App\Mail\PassengerOtpMail;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private UserRepository $userRepository) {}

    /**
     * Step 1 of 2-step login.
     * Validates credentials, generates a 6-digit OTP, stores it in cache
     * (10-minute TTL) and emails it to the passenger.
     *
     * Returns the masked email so the frontend can show "code sent to a*****@gmail.com".
     * Does NOT issue a Sanctum token yet.
     */
    public function initiateLoginOtp(array $payload): array
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        if ($user->role !== 'passenger') {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        // 2026-10-28: a passenger whose email is not verified yet always gets a code, whatever their login-code
        // setting: entering it verifies the email (verifyLoginOtp) and signs them in.
        $unverified = $user->email_verified_at === null;

        // If the passenger has disabled 2FA, issue a Sanctum token immediately
        // without sending an OTP — respects their stored preference.
        if (! $unverified && ! (bool) $user->two_factor_enabled) {
            return [
                'otp_required' => false,
                'token' => $user->createToken('passenger-session-token')->plainTextToken,
            ];
        }

        return $this->sendPassengerCode($user, $unverified);
    }

    /**
     * 2026-10-28: emails a passenger a 6-digit code (hashed in the cache for 10 minutes, single use). The same code
     * signs them in and, while their email is not verified, verifies it. $verify only changes the email's wording.
     *
     * @return array{otp_required: true, verification_required: bool, user_id: int, email_masked: string}
     */
    public function sendPassengerCode(object $user, bool $verify = false): array
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = 'passenger-otp:'.$user->user_id;

        // Store hashed OTP — prevents cache-leak from exposing the code directly.
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

        $name = $user->username ?? explode('@', $user->email)[0];
        $recipientEmail = env('MAIL_INTERCEPT_EMAIL') ?: $user->email;

        try {
            Mail::to($recipientEmail)->send(new PassengerOtpMail($otp, $name, $user->email, $verify));
        } catch (\Exception $e) {
            // In development/testing with Resend's free tier, sending to non-verified
            // recipients fails. Log the error but continue — OTP is still cached and usable.
            \Log::warning("OTP email send failed for {$recipientEmail}: {$e->getMessage()}");
        }

        return [
            'otp_required' => true,
            'verification_required' => $verify,
            // 2026-10-29: the account's uuid, not the numeric user id; step 2 sends it back.
            'account_uuid' => (string) $user->passengerProfile?->passenger_uuid,
            'email_masked' => $this->maskEmail($user->email),
        ];
    }

    /**
     * 2026-10-28: a new code for a passenger whose email is not verified yet (the "Resend code" right after sign-up,
     * when the password is not at hand). An unknown or already verified account gets the same quiet answer.
     */
    public function resendVerificationCode(string $passengerUuid): void
    {
        $user = $this->userRepository->findPassengerUserByUuid($passengerUuid);
        if ($user && $user->role === 'passenger' && $user->email_verified_at === null) {
            $this->sendPassengerCode($user, true);
        }
    }

    /**
     * Step 2 of 2-step login.
     * Validates the OTP and, if correct, deletes it and issues the Sanctum token.
     */
    public function verifyLoginOtp(string $accountUuid, string $otpInput): array
    {
        // 2026-10-29: the uuid from step 1 names the account; an unknown one reads like an expired code.
        $userId = (int) ($this->userRepository->findPassengerUserByUuid($accountUuid)?->user_id ?? 0);
        $cacheKey = 'passenger-otp:'.$userId;
        $stored = Cache::get($cacheKey);

        if (! $stored) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired or was never issued. Please log in again.'],
            ]);
        }

        if (! Hash::check($otpInput, $stored)) {
            throw ValidationException::withMessages([
                'otp' => ['Incorrect code. Please check your email and try again.'],
            ]);
        }

        // Single-use — delete immediately after successful check.
        Cache::forget($cacheKey);

        $user = $this->userRepository->findByField('user_id', $userId);

        if (! $user || $user->role !== 'passenger') {
            throw ValidationException::withMessages([
                'otp' => ['Account not found.'],
            ]);
        }

        // 2026-10-28: the first correct code proves the passenger reads this inbox: the email is verified.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return [
            'user' => $user,
            'token' => $user->createToken('passenger-session-token')->plainTextToken,
        ];
    }

    // ── Staff authentication ───────────────────────────────────────────────
    // Operator and Admin roles require OTP regardless of their two_factor_enabled
    // flag — MFA is mandatory for those roles (ACCOUNT_SECURITY_AUDIT §1).
    // Driver and Conductor respect the per-account two_factor_enabled flag.

    private const MFA_MANDATORY_ROLES = ['operator', 'admin'];

    /**
     * TEMPORARY dev-phase convenience flag — see config('app.disable_staff_otp').
     * Double-gated: the flag alone is not enough, APP_ENV must also be
     * "local", so this can never silently activate in staging/production
     * (AppServiceProvider also refuses to boot if the flag is set outside
     * local as a fail-fast backstop). When active, ALL staff logins
     * (including operator/admin, which are otherwise MFA-mandatory) skip
     * the OTP step entirely.
     */
    private function staffOtpBypassActive(): bool
    {
        return config('app.disable_staff_otp') && app()->environment('local');
    }

    /**
     * Step 1 of staff login.
     * Validates credentials, then either issues a Sanctum token directly
     * (driver/conductor with two_factor_enabled = false) or sends an OTP
     * and returns the masked email (operator/admin and any staff with MFA on).
     */
    public function initiateStaffLoginOtp(array $credentials): array
    {
        try {
            $user = $this->userRepository->findByField('email', $credentials['email']);

            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password credentials provided.'],
                ]);
            }

            if (! in_array($user->role, ['operator', 'driver', 'conductor', 'admin'])) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password credentials provided.'],
                ]);
            }

            $requiresMfa = ! $this->staffOtpBypassActive() && (
                in_array($user->role, self::MFA_MANDATORY_ROLES)
                || (bool) $user->two_factor_enabled
            );

            if (! $requiresMfa) {
                return [
                    'otp_required' => false,
                    'user' => $user,
                    'token' => $user->createToken('staff-session-token')->plainTextToken,
                ];
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $cacheKey = 'staff-otp:'.$user->user_id;

            // Store hashed OTP to prevent cache-leak exposure.
            Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

            $name = $user->username ?? explode('@', $user->email)[0];
            $recipientEmail = env('MAIL_INTERCEPT_EMAIL') ?: $user->email;

            try {
                Mail::to($recipientEmail)->send(new PassengerOtpMail($otp, $name, $user->email));
            } catch (\Exception $e) {
                // In development/testing with Resend's free tier, sending to non-verified
                // recipients fails. Log the error but continue — OTP is still cached and usable.
                \Log::warning("OTP email send failed for {$recipientEmail}: {$e->getMessage()}");
            }

            return [
                'otp_required' => true,
                // 2026-10-29: the account's uuid, not the numeric user id; step 2 sends it back.
                'account_uuid' => (string) $user->companyProfile?->company_user_uuid,
                'email_masked' => $this->maskEmail($user->email),
            ];
        } catch (\Exception $e) {
            \Log::error('initiateStaffLoginOtp error: '.$e->getMessage().' | '.$e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Step 2 of staff login — validates OTP and issues Sanctum token.
     */
    public function verifyStaffLoginOtp(string $accountUuid, string $otpInput): array
    {
        // 2026-10-29: the uuid from step 1 names the account; an unknown one reads like an expired code.
        $userId = (int) ($this->userRepository->findStaffUserByUuid($accountUuid)?->user_id ?? 0);
        $cacheKey = 'staff-otp:'.$userId;
        $stored = Cache::get($cacheKey);

        if (! $stored) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired or was never issued. Please log in again.'],
            ]);
        }

        if (! Hash::check($otpInput, $stored)) {
            throw ValidationException::withMessages([
                'otp' => ['Incorrect code. Please check your email and try again.'],
            ]);
        }

        // Single-use — delete immediately after a successful check.
        Cache::forget($cacheKey);

        $user = $this->userRepository->findByField('user_id', $userId);

        if (! $user || ! in_array($user->role, ['operator', 'driver', 'conductor', 'admin'])) {
            throw ValidationException::withMessages([
                'otp' => ['Account not found.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $user->createToken('staff-session-token')->plainTextToken,
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    public function getPassengerProfile(object $user): ?object
    {
        return $this->userRepository->getPassengerProfile($user->user_id);
    }

    public function logoutUser(object $user): void
    {
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));
        $masked = str_repeat('*', max(0, strlen($local) - 2));

        return $visible.$masked.'@'.$domain;
    }
}
