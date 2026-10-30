<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\StaffService;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * 2026-10-30: step 1 of signing in, for passengers and staff alike: POST /auth/login { email, password, portal }.
 * `portal` is the page the person signs in on (passenger or staff). It only picks the flow: each flow still checks
 * the account's own role and answers a wrong one exactly like a wrong password. Step 2 (the emailed code) stays on
 * passengers/verify-otp and staff/verify-otp. Only FAILED attempts count toward the limit (5, then 15 minutes).
 */
class SignInController extends Controller
{
    use ApiResponse;

    public function __construct(
        private UserService $userService,
        private StaffService $staffService,
    ) {}

    public function login(LoginRequest $request)
    {
        $staff = $request->validated('portal') === 'staff';
        $key = ($staff ? 'staff-login:' : 'passenger-login:').$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Please wait {$seconds} seconds and try again."],
            ]);
        }

        $credentials = $request->safe()->only(['email', 'password']);
        try {
            $result = $staff
                ? $this->staffService->loginStaff($credentials)
                : $this->userService->initiateLoginOtp($credentials);
        } catch (ValidationException $e) {
            RateLimiter::hit($key, 900);
            throw $e;
        }

        RateLimiter::clear($key);

        return $this->success($result, ($result['otp_required'] ?? false) ? 'OTP sent to your email address.' : 'Logged in successfully.');
    }
}
