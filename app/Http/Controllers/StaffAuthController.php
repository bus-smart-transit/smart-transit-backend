<?php

namespace App\Http\Controllers;

use App\Services\StaffService;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class StaffAuthController extends Controller
{
    use ApiResponse;
    private StaffService $staffService;

    public function __construct(StaffService $staffService)
    {
        $this->staffService = $staffService;
    }

    // ── Auth ────────────────────────────────────────────────────────

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

    // ── Account creation ────────────────────────────────────────────
    // Admin hits POST /admin/accounts    → can create operators
    // Operator hits POST /operator/accounts → can create drivers/conductors
    // Both use this same method; StaffService enforces what each role can create

    public function createAccount(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'phone_num' => 'required|string',
            'address' => 'nullable|string',
            'username' => 'nullable|string',
            'role' => 'required|string|in:operator,driver,conductor',
        ]);

        $result = $this->staffService->createStaffAccount(
            $validated,
            $request->user()->role  // who is making the request — enforced in service
        );

        return $this->success($result, 'Account created successfully');
    }

    // ── Staff listings ───────────────────────────────────────────────
    // Operator/Admin can see all drivers and conductors to assign to trips

    public function listDrivers()
    {
        return $this->success(
            $this->staffService->listDrivers(),
            'Drivers retrieved successfully'
        );
    }

    public function listConductors()
    {
        return $this->success(
            $this->staffService->listConductors(),
            'Conductors retrieved successfully'
        );
    }
}
