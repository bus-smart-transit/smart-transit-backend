<?php
namespace App\Http\Controllers;
use App\Services\StaffService;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\CreateStaffAccountRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class StaffAuthController extends Controller
{
    use ApiResponse;
    public function __construct(private StaffService $staffService)
    {
    }

    public function login(LoginRequest $request)
    {
        $response = $this->staffService->loginStaff($request->validated());
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
