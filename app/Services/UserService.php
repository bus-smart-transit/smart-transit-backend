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
    public function __construct(private UserRepository $userRepository)
    {
    }

    /**
     * Step 1 of 2-step login.
     * Validates credentials, generates a 6-digit OTP, stores it in cache
     * (10-minute TTL) and emails it to the passenger.
     * Returns the masked email so the frontend can show "code sent to a*****@gmail.com".
     * Does NOT issue a Sanctum token yet.
     */
    public function initiateLoginOtp(array $payload): array
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (!$user || !Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        if ($user->role !== 'passenger') {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        // If the passenger has disabled 2FA, issue a Sanctum token immediately
        // without sending an OTP — respects their stored preference.
        if (!(bool) $user->two_factor_enabled) {
            return [
                'otp_required' => false,
                'token'        => $user->createToken('passenger-session-token')->plainTextToken,
            ];
        }

        $otp       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey  = 'passenger-otp:' . $user->user_id;

        // Store hashed OTP — prevents cache-leak from exposing the code directly.
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

        $name      = $user->username ?? explode('@', $user->email)[0];
        Mail::to($user->email)->send(new PassengerOtpMail($otp, $name));

        return [
            'otp_required'   => true,
            'user_id'        => $user->user_id,
            'email_masked'   => $this->maskEmail($user->email),
        ];
    }

    /**
     * Step 2 of 2-step login.
     * Validates the OTP and, if correct, deletes it and issues the Sanctum token.
     */
    public function verifyLoginOtp(int $userId, string $otpInput): array
    {
        $cacheKey = 'passenger-otp:' . $userId;
        $stored   = Cache::get($cacheKey);

        if (!$stored) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired or was never issued. Please log in again.'],
            ]);
        }

        if (!Hash::check($otpInput, $stored)) {
            throw ValidationException::withMessages([
                'otp' => ['Incorrect code. Please check your email and try again.'],
            ]);
        }

        // Single-use — delete immediately after successful check.
        Cache::forget($cacheKey);

        $user = $this->userRepository->findByField('user_id', $userId);

        if (!$user || $user->role !== 'passenger') {
            throw ValidationException::withMessages([
                'otp' => ['Account not found.'],
            ]);
        }

        return [
            'user'  => $user,
            'token' => $user->createToken('passenger-session-token')->plainTextToken,
        ];
    }

    // ── Staff authentication ───────────────────────────────────────────────
    // Operator and Admin roles require OTP regardless of their two_factor_enabled
    // flag — MFA is mandatory for those roles (ACCOUNT_SECURITY_AUDIT §1).
    // Driver and Conductor respect the per-account two_factor_enabled flag.

    private const MFA_MANDATORY_ROLES = ['operator', 'admin'];

    /**
     * Step 1 of staff login.
     * Validates credentials, then either issues a Sanctum token directly
     * (driver/conductor with two_factor_enabled = false) or sends an OTP
     * and returns the masked email (operator/admin and any staff with MFA on).
     */
    public function initiateStaffLoginOtp(array $credentials): array
    {
        $user = $this->userRepository->findByField('email', $credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        if (!in_array($user->role, ['operator', 'driver', 'conductor', 'admin'])) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        $requiresMfa = in_array($user->role, self::MFA_MANDATORY_ROLES)
            || (bool) $user->two_factor_enabled;

        if (!$requiresMfa) {
            return [
                'otp_required' => false,
                'user'         => $user,
                'token'        => $user->createToken('staff-session-token')->plainTextToken,
            ];
        }

        $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = 'staff-otp:' . $user->user_id;

        // Store hashed OTP to prevent cache-leak exposure.
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

        $name = $user->username ?? explode('@', $user->email)[0];
        Mail::to($user->email)->send(new PassengerOtpMail($otp, $name));

        return [
            'otp_required' => true,
            'user_id'      => $user->user_id,
            'email_masked' => $this->maskEmail($user->email),
        ];
    }

    /**
     * Step 2 of staff login — validates OTP and issues Sanctum token.
     */
    public function verifyStaffLoginOtp(int $userId, string $otpInput): array
    {
        $cacheKey = 'staff-otp:' . $userId;
        $stored   = Cache::get($cacheKey);

        if (!$stored) {
            throw ValidationException::withMessages([
                'otp' => ['OTP has expired or was never issued. Please log in again.'],
            ]);
        }

        if (!Hash::check($otpInput, $stored)) {
            throw ValidationException::withMessages([
                'otp' => ['Incorrect code. Please check your email and try again.'],
            ]);
        }

        // Single-use — delete immediately after a successful check.
        Cache::forget($cacheKey);

        $user = $this->userRepository->findByField('user_id', $userId);

        if (!$user || !in_array($user->role, ['operator', 'driver', 'conductor', 'admin'])) {
            throw ValidationException::withMessages([
                'otp' => ['Account not found.'],
            ]);
        }

        return [
            'user'  => $user,
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
        $masked  = str_repeat('*', max(0, strlen($local) - 2));
        return $visible . $masked . '@' . $domain;
    }
}
