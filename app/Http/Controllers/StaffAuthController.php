<?php
namespace App\Http\Controllers;
use App\Services\StaffService;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\CreateStaffAccountRequest;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StaffAuthController extends Controller
{
    use ApiResponse;
    public function __construct(private StaffService $staffService)
    {
    }

    public function login(LoginRequest $request)
    {
        // Bug 2 fix: only count FAILED attempts toward the rate limit.
        $key = 'staff-login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Please wait {$seconds} seconds and try again."],
            ]);
        }

        try {
            $response = $this->staffService->loginStaff($request->validated());
        } catch (ValidationException $e) {
            RateLimiter::hit($key, 900); // decay: 15 minutes
            throw $e;
        }

        RateLimiter::clear($key);

        $message = ($response['otp_required'] ?? false)
            ? 'OTP sent to your email address.'
            : 'Logged in successfully';

        return $this->success($response, $message);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'otp'     => 'required|string|size:6',
        ]);

        $otpKey = 'staff-otp-verify:' . $request->ip() . ':' . $request->input('user_id');

        if (RateLimiter::tooManyAttempts($otpKey, 5)) {
            $seconds = RateLimiter::availableIn($otpKey);
            throw ValidationException::withMessages([
                'otp' => ["Too many incorrect attempts. Please wait {$seconds} seconds."],
            ]);
        }

        try {
            $result = $this->staffService->verifyStaffLoginOtp(
                (int) $request->input('user_id'),
                (string) $request->input('otp')
            );
        } catch (ValidationException $e) {
            RateLimiter::hit($otpKey, 900);
            throw $e;
        }

        RateLimiter::clear($otpKey);

        return $this->success([
            'token' => $result['token'],
            'user'  => $result['user'],
        ], 'Logged in successfully.');
    }

    public function profile(Request $request)
    {
        $profile = $this->staffService->getStaffProfile($request->user());
        return $this->success($profile, 'Profile retrieved successfully');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Logged out successfully');
    }

    public function updateTwoFactorPreference(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        // Operator and Admin MFA is mandatory — cannot be disabled.
        if (!$validated['enabled'] && in_array($request->user()?->role, ['operator', 'admin'])) {
            return $this->error('Two-factor authentication cannot be disabled for operator and admin accounts.', 403);
        }

        $request->user()->forceFill([
            'two_factor_enabled' => (bool) $validated['enabled'],
        ])->save();

        return $this->success([
            'two_factor_enabled' => (bool) $request->user()->two_factor_enabled,
        ], '2FA preference updated successfully.');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Always return a generic message to prevent email enumeration.
        Password::sendResetLink($request->only('email'));

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

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                // Invalidate all active sessions on password reset.
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(null, 'Password reset successfully. Please log in again.');
        }

        return $this->error('Invalid or expired password reset token. Please request a new one.', 422);
    }

    // Admin hits POST /admin/accounts   → creates operator
    // Operator hits POST /operator/accounts → creates driver or conductor
    // StaffService enforces which roles each creator is allowed to assign
    public function createAccount(CreateStaffAccountRequest $request)
    {
        $result = $this->staffService->createStaffAccount(
            $request->validated(),
            $request->user()->role
        );
        return $this->success($result, 'Account created successfully');
    }

    public function listDrivers()
    {
        return $this->success($this->staffService->listDrivers(), 'Drivers retrieved successfully');
    }

    public function listConductors()
    {
        return $this->success($this->staffService->listConductors(), 'Conductors retrieved successfully');
    }
}
