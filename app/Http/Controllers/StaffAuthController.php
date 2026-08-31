<?php
namespace App\Http\Controllers;
use App\Services\StaffService;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\CreateStaffAccountRequest;
use App\Traits\ApiResponse;
use App\Mail\PassengerOtpMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    // ── Step-up re-authentication ─────────────────────────────────────────

    /**
     * POST /step-up/initiate
     * Send a fresh OTP to the authenticated user's email for step-up verification.
     */
    public function stepUpInitiate(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->error('Unauthenticated.', 401);

        $rateLimitKey = 'step-up-initiate:' . $user->user_id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return $this->error("Too many requests. Try again in {$seconds} seconds.", 429);
        }
        RateLimiter::hit($rateLimitKey, 300);

        $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = 'step-up-otp:' . $user->user_id;
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

        $name = $user->username ?? explode('@', $user->email)[0];
        Mail::to($user->email)->send(new PassengerOtpMail($otp, $name));

        return $this->success([
            'email_masked' => $this->maskEmailLocal($user->email),
        ], 'Step-up OTP sent to your email.');
    }

    /**
     * POST /step-up/verify
     * Validate the OTP and issue a 15-minute step-up token.
     */
    public function stepUpVerify(Request $request)
    {
        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        if (!$user) return $this->error('Unauthenticated.', 401);

        $rateLimitKey = 'step-up-verify:' . $user->user_id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return $this->error("Too many incorrect attempts. Wait {$seconds} seconds.", 429);
        }

        $cacheKey = 'step-up-otp:' . $user->user_id;
        $stored   = Cache::get($cacheKey);

        if (!$stored || !Hash::check($validated['otp'], $stored)) {
            RateLimiter::hit($rateLimitKey, 900);
            throw ValidationException::withMessages(['otp' => ['Incorrect or expired OTP.']]);
        }

        Cache::forget($cacheKey);
        RateLimiter::clear($rateLimitKey);

        // Purge any existing step-up tokens for this user before issuing a new one
        DB::table('step_up_tokens')
            ->where('user_id', $user->user_id)
            ->delete();

        $rawToken = bin2hex(random_bytes(32));
        DB::table('step_up_tokens')->insert([
            'user_id'    => $user->user_id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        return $this->success([
            'step_up_token' => $rawToken,
            'expires_in'    => 900, // seconds
        ], 'Step-up verified. Token valid for 15 minutes.');
    }

    private function maskEmailLocal(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));
        $masked  = str_repeat('*', max(0, strlen($local) - 2));
        return $visible . $masked . '@' . $domain;
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
