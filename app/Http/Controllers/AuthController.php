<?php
namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\PassengerResource;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
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
        $passengerProfile = $this->userService->getPassengerProfile($request->user());

        if (!$passengerProfile) {
            return $this->error('Passenger profile not found.', 404);
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
}
