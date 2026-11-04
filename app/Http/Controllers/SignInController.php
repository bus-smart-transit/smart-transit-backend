<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\StaffService;
use App\Services\UserService;
use App\Support\RateLimiting\SignInThrottle;
use App\Traits\ApiResponse;
use Illuminate\Validation\ValidationException;

/**
 * 2026-10-30: step 1 of signing in, for passengers and staff alike: POST /auth/login { email, password, portal }.
 * `portal` is the page the person signs in on (passenger or staff). It only picks the flow: each flow still checks
 * the account's own role and answers a wrong one exactly like a wrong password. Step 2 (the emailed code) stays on
 * passengers/verify-otp and staff/verify-otp. Only FAILED attempts count toward the limit (SignInThrottle: 5 per email + IP,
 * then 5 minutes; a wider per-IP cap).
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
        $portal = $request->validated('portal');
        $email = (string) $request->validated('email');
        $ip = $request->ip();

        $seconds = SignInThrottle::secondsLocked($portal, $email, $ip);
        if ($seconds !== null) {
            throw ValidationException::withMessages([
                'email' => ['Too many failed login attempts. Please wait '.SignInThrottle::waitText($seconds).' and try again.'],
            ]);
        }

        $credentials = $request->safe()->only(['email', 'password']);
        try {
            $result = $portal === 'staff'
                ? $this->staffService->loginStaff($credentials)
                : $this->userService->initiateLoginOtp($credentials);
        } catch (ValidationException $e) {
            SignInThrottle::recordFailure($portal, $email, $ip);
            throw $e;
        }

        SignInThrottle::recordSuccess($portal, $email, $ip);

        $message = match (true) {
            (bool) ($result['otp_required'] ?? false) => 'OTP sent to your email address.',
            (bool) ($result['deletion_cancelled'] ?? false) => 'Logged in. Your account will not be deleted.',
            default => 'Logged in successfully.',
        };

        return $this->success($result, $message);
    }
}
