<?php
namespace App\Services;

use App\Mail\PassengerOtpMail;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class UserService
{
    private const PASSENGER_ROLES = ['passenger'];
    private const STAFF_ROLES = ['driver', 'conductor', 'operator', 'admin'];
    private const OTP_TTL_MINUTES = 3;

    public function __construct(private UserRepository $userRepository)
    {
    }

    /**
     * Step 1 of 2-step login.
     * Validates credentials, and if 2FA is enabled, sends a 6-digit OTP.
     * If 2FA is disabled, this issues a token immediately.
     * Returns the masked email so the frontend can show "code sent to a*****@gmail.com".
     */
    public function initiateLoginOtp(array $payload): array
    {
        return $this->initiateRoleAwareLoginOtp(
            $payload,
            self::PASSENGER_ROLES,
            'passenger-login-otp',
            'passenger-session-token',
            []
        );
    }

    public function initiateStaffLoginOtp(array $payload): array
    {
        return $this->initiateRoleAwareLoginOtp(
            $payload,
            self::STAFF_ROLES,
            'staff-login-otp',
            'staff-session-token',
            null
        );
    }

    /**
     * Step 2 of 2-step login.
     * Validates the OTP and, if correct, deletes it and issues the Sanctum token.
     */
    public function verifyLoginOtp(int $userId, string $otpInput): array
    {
        return $this->verifyRoleAwareLoginOtp(
            $userId,
            $otpInput,
            self::PASSENGER_ROLES,
            'passenger-login-otp',
            'passenger-session-token',
            []
        );
    }

    public function verifyStaffLoginOtp(int $userId, string $otpInput): array
    {
        return $this->verifyRoleAwareLoginOtp(
            $userId,
            $otpInput,
            self::STAFF_ROLES,
            'staff-login-otp',
            'staff-session-token',
            null
        );
    }

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

    private function initiateRoleAwareLoginOtp(
        array $payload,
        array $allowedRoles,
        string $cachePrefix,
        string $tokenName,
        ?array $abilities
    ): array {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (!$user || !Hash::check($payload['password'], $user->password) || !in_array($user->role, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        if (!$user->two_factor_enabled) {
            $token = $abilities === null
                ? $user->createToken($tokenName, [$user->role])->plainTextToken
                : $user->createToken($tokenName, $abilities)->plainTextToken;

            return [
                'otp_required' => false,
                'token' => $token,
                'user' => $user,
            ];
        }

        $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = $cachePrefix . ':' . $user->user_id;

        // Store hashed OTP — prevents cache-leak from exposing the code directly.
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(self::OTP_TTL_MINUTES));

        $name = $user->username ?? explode('@', $user->email)[0];

        // Always log OTP first so it is visible even if mail delivery fails.
        Log::info('[DEV] Login OTP issued', [
            'user_id' => $user->user_id,
            'email' => $user->email,
            'otp' => $otp,
            'ttl_minutes' => self::OTP_TTL_MINUTES,
        ]);

        $mailDeliveryFailed = false;
        $mailDeliveryError = null;

        try {
            Mail::to($user->email)->send(new PassengerOtpMail($otp, $name));
        } catch (\Throwable $e) {
            // Mail is non-critical during development (e.g. missing RESEND_API_KEY).
            // OTP is already persisted in cache and visible in the log above.
            $mailDeliveryFailed = true;
            $mailDeliveryError = $e->getMessage();
            Log::warning('[DEV] OTP mail failed — check RESEND_API_KEY or mail config', [
                'error' => $e->getMessage(),
            ]);
        }

        $response = [
            'otp_required' => true,
            'user_id' => $user->user_id,
            'email_masked' => $this->maskEmail($user->email),
            'otp_expires_in_seconds' => self::OTP_TTL_MINUTES * 60,
        ];

        if ($mailDeliveryFailed) {
            $response['otp_delivery_failed'] = true;
            $response['otp_delivery_message'] = $this->humanizeMailDeliveryError($mailDeliveryError);
        }

        return $response;
    }

    private function verifyRoleAwareLoginOtp(
        int $userId,
        string $otpInput,
        array $allowedRoles,
        string $cachePrefix,
        string $tokenName,
        ?array $abilities
    ): array {
        $cacheKey = $cachePrefix . ':' . $userId;
        $stored = Cache::get($cacheKey);

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

        if (!$user || !in_array($user->role, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'otp' => ['Account not found.'],
            ]);
        }

        $token = $abilities === null
            ? $user->createToken($tokenName, [$user->role])->plainTextToken
            : $user->createToken($tokenName, $abilities)->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    private function humanizeMailDeliveryError(?string $rawError): string
    {
        $raw = (string) $rawError;

        if (str_contains($raw, 'You can only send testing emails to your own email address')) {
            return 'Resend test mode only sends to your account email. Verify a domain in Resend and use a sender from that domain to deliver OTPs to other recipients.';
        }

        if (str_contains($raw, 'invalid_api_key') || str_contains($raw, 'Unauthorized')) {
            return 'Resend rejected the API key. Please check RESEND_API_KEY.';
        }

        return 'OTP email delivery failed. Please check Resend configuration.';
    }
}
