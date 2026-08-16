<?php
namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\PassengerResource;
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

    public function __construct(private UserService $userService)
    {
    }

    /**
     * Step 1: validate credentials and send OTP.
     * Returns { otp_required: true, user_id, email_masked } — NO token yet.
     */
    public function login(LoginRequest $request)
    {
        // Bug 2 fix: only count FAILED attempts toward the rate limit.
        $key = 'passenger-login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Please wait {$seconds} seconds and try again."],
            ]);
        }

        try {
            $result = $this->userService->initiateLoginOtp($request->validated());
        } catch (ValidationException $e) {
            RateLimiter::hit($key, 900);
            throw $e;
        }

        RateLimiter::clear($key);
        return $this->success($result, 'OTP sent to your email address.');
    }

    /**
     * Step 2: verify OTP and issue Sanctum token.
     * POST /passengers/verify-otp  { user_id, otp }
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'otp'     => 'required|string|size:6',
        ]);

        // Separate rate limit for OTP guessing — max 5 wrong codes per 15 min.
        $otpKey = 'passenger-otp-verify:' . $request->ip() . ':' . $request->input('user_id');

        if (RateLimiter::tooManyAttempts($otpKey, 5)) {
            $seconds = RateLimiter::availableIn($otpKey);
            throw ValidationException::withMessages([
                'otp' => ["Too many incorrect attempts. Please wait {$seconds} seconds."],
            ]);
        }

        try {
            $result = $this->userService->verifyLoginOtp(
                (int) $request->input('user_id'),
                (string) $request->input('otp')
            );
        } catch (ValidationException $e) {
            RateLimiter::hit($otpKey, 900);
            throw $e;
        }

        RateLimiter::clear($otpKey);
        return $this->success(['token' => $result['token']], 'Logged in successfully.');
    }

    public function profile(Request $request)
    {
        $user             = $request->user();
        $passengerProfile = $this->userService->getPassengerProfile($user);

        if (!$passengerProfile) {
            // The Sanctum token is valid (middleware passed) but no passenger_users
            // row exists yet.  Return minimal user data so the frontend can stay
            // authenticated and prompt the user to complete their profile instead
            // of immediately logging them out.
            return $this->success([
                'user_id'          => $user->user_id,
                'email'            => $user->email,
                'username'         => $user->username,
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

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::broker('passengers')->sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success(null, 'Password reset link sent to your email.');
        }

        // Return a generic message to avoid email enumeration
        return $this->success(null, 'If that email is registered, a reset link has been sent.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
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
