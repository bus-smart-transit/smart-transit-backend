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

    public function login(LoginRequest $request)
    {
        // Bug 2 fix: only count FAILED attempts toward the rate limit.
        // The route-level throttle (throttle:5,15,passenger-login) increments
        // on every call regardless of outcome, so successful logins consumed
        // the counter and eventually triggered the lockout message.
        $key = 'passenger-login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Please wait {$seconds} seconds and try again."],
            ]);
        }

        try {
            $response = $this->userService->loginUser($request->validated());
        } catch (ValidationException $e) {
            // Only FAILED logins count toward the rate limit.
            RateLimiter::hit($key, 900); // decay: 15 minutes
            throw $e;
        }

        // Successful login — reset the counter so the user is not penalised
        // for previous mistakes once they authenticate correctly.
        RateLimiter::clear($key);

        return $this->success($response, 'Logged in successfully');
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
