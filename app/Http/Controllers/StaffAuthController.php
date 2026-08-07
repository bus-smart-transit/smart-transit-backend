<?php
namespace App\Http\Controllers;
use App\Services\StaffService;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\CreateStaffAccountRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
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

        return $this->success($response, 'Logged in successfully');
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
