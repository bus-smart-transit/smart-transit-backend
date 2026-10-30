<?php

namespace App\Http\Controllers;

use App\Http\Resources\PassengerResource;
use App\Services\FcmService;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private UserService $userService) {}

    /**
     * Step 2: verify OTP and issue Sanctum token.
     * POST /passengers/verify-otp  { account_uuid, otp } (2026-10-29: the uuid from step 1, never the numeric user id)
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'account_uuid' => 'required|uuid',
            'otp' => 'required|string|size:6',
        ]);

        // Separate rate limit for OTP guessing — max 5 wrong codes per 15 min.
        $otpKey = 'passenger-otp-verify:'.$request->ip().':'.$request->input('account_uuid');

        if (RateLimiter::tooManyAttempts($otpKey, 5)) {
            $seconds = RateLimiter::availableIn($otpKey);
            throw ValidationException::withMessages([
                'otp' => ["Too many incorrect attempts. Please wait {$seconds} seconds."],
            ]);
        }

        try {
            $result = $this->userService->verifyLoginOtp(
                (string) $request->input('account_uuid'),
                (string) $request->input('otp')
            );
        } catch (ValidationException $e) {
            RateLimiter::hit($otpKey, 900);
            throw $e;
        }

        RateLimiter::clear($otpKey);

        return $this->success(['token' => $result['token']], 'Logged in successfully.');
    }

    /** 2026-10-28: POST passengers/verify-email/resend { account_uuid }. A new code for an account not verified yet. */
    public function resendVerification(Request $request)
    {
        $data = $request->validate(['account_uuid' => 'required|uuid']);
        $this->userService->resendVerificationCode((string) $data['account_uuid']);

        return $this->success(null, 'If this account still needs verifying, a new code was sent.');
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $passengerProfile = $this->userService->getPassengerProfile($user);

        if (! $passengerProfile) {
            // The Sanctum token is valid (middleware passed) but no passenger_users
            // row exists yet.  Return minimal user data so the frontend can stay
            // authenticated and prompt the user to complete their profile instead
            // of immediately logging them out.
            return $this->success([
                'user_id' => $user->user_id,
                'email' => $user->email,
                'username' => $user->username,
                'profile_complete' => false,
            ], 'Profile incomplete — please complete your profile');
        }

        return $this->success(
            new PassengerResource($passengerProfile),
            'Profile retrieved successfully'
        );
    }

    public function logout(Request $request)
    {
        $this->userService->logoutUser($request->user());

        return $this->success(null, 'Logged out successfully');
    }

    /**
     * Passenger: toggle two-factor authentication on/off.
     * PATCH /passengers/two-factor
     */
    public function updateTwoFactor(Request $request)
    {
        $request->validate(['enabled' => 'required|boolean']);

        $request->user()->forceFill([
            'two_factor_enabled' => (bool) $request->input('enabled'),
        ])->save();

        return $this->success(
            ['two_factor_enabled' => (bool) $request->user()->two_factor_enabled],
            'Extra login security updated.'
        );
    }

    // Passenger: register/overwrite this user's FCM device token, so trip
    // cancellation / payment failure / auto-delay pushes can reach them.
    public function registerFcmToken(Request $request, FcmService $fcmService)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:4096',
        ]);

        $fcmService->registerToken($request->user(), $validated['token']);

        return $this->success(null, 'Push notification token registered.');
    }

    public function forgotPassword(Request $request)
    {
        // Accounts are stored and logged in with a lower-case email (LoginRequest), so a reset is looked up the same way.
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $request->validate(['email' => 'required|email']);

        // Always the same answer, whether or not the email is registered or the mail could be sent (no account probing).
        Password::broker('passengers')->sendResetLink(
            $request->only('email')
        );

        return $this->success(null, 'If that email is registered, a reset link has been sent.');
    }

    public function resetPassword(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $status = Password::broker('passengers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                // Invalidate all active sessions on password reset
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(null, 'Password reset successfully. Please log in with your new password.');
        }

        return $this->error('Invalid or expired password reset token. Please request a new one.', 422);
    }
}
