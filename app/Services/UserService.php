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

        $otp       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey  = 'passenger-otp:' . $user->user_id;

        // Store hashed OTP — prevents cache-leak from exposing the code directly.
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

        $name = $user->username ?? explode('@', $user->email)[0];

        // Always log OTP first so it is visible even if mail delivery fails.
        Log::info('[DEV] Passenger OTP', [
            'user_id' => $user->user_id,
            'email'   => $user->email,
            'otp'     => $otp,
        ]);

        try {
            Mail::to($user->email)->send(new PassengerOtpMail($otp, $name));
        } catch (\Throwable $e) {
            // Mail is non-critical during development (e.g. no RESEND_KEY set yet).
            // OTP is already persisted in cache and visible in the log above.
            Log::warning('[DEV] OTP mail failed — check RESEND_KEY or mail config', [
                'error' => $e->getMessage(),
            ]);
        }

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
